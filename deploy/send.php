<?php
/**
 * Приём заявок с usatravel.ae.
 * 1) Письмо на почту менеджера (всегда).
 * 2) Сделка в Kommo через API напрямую (если задан токен в config.php):
 *    контакт с телефоном, поле «Country of Residence» в сделке, примечание с деталями.
 * 3) Лог в leads.log рядом (на случай сбоев почты/Kommo).
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }

$cfg = file_exists(__DIR__ . '/config.php') ? include __DIR__ . '/config.php' : [];
$MAIL_TO   = $cfg['mail_to']   ?? 'info@worldpass.ae';
$MAIL_FROM = $cfg['mail_from'] ?? 'leads@usatravel.ae';
$KOMMO     = $cfg['kommo']     ?? null;

$f = fn($k) => trim(mb_substr((string)($_POST[$k] ?? ''), 0, 500));
$name      = $f('fname');
$phone     = preg_replace('/[^\d+ ]/', '', $f('phone'));
$residence = $f('residence');
$msg       = $f('msg');
$service   = $f('service') ?: 'USA Visa B1/B2';
$url       = $f('url');
$utm       = [];
foreach (['utm_source','utm_campaign','utm_medium','utm_term','utm_content'] as $u) if ($f($u) !== '') $utm[$u] = $f($u);

// защита от ботов: пустое имя/телефон или honeypot
if ($name === '' || strlen(preg_replace('/\D/', '', $phone)) < 7 || !empty($_POST['website'])) {
  http_response_code(400); echo '{"ok":false,"error":"invalid"}'; exit;
}

$when = date('Y-m-d H:i:s');
$ip   = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$lines = [
  "Новая заявка — $service",
  "Имя: $name",
  "Телефон: $phone",
  "Страна проживания: $residence",
  "Комментарий: $msg",
  "Страница: $url",
  $utm ? 'UTM: ' . http_build_query($utm, '', ', ') : 'UTM: —',
  "Время: $when (сервер), IP: $ip",
];
$body = implode("\n", $lines);

// 3) лог
@file_put_contents(__DIR__ . '/leads.log', str_replace("\n", ' | ', $body) . "\n", FILE_APPEND | LOCK_EX);

// 1) письмо
$subject = '=?UTF-8?B?' . base64_encode("Заявка usatravel.ae: $name, $phone") . '?=';
$headers = "From: World Pass Leads <$MAIL_FROM>\r\nReply-To: $MAIL_FROM\r\nContent-Type: text/plain; charset=UTF-8\r\n";
$mailed = @mail($MAIL_TO, $subject, $body, $headers);

// ---------- Kommo ----------
function kommo_call(array $k, string $method, string $path, $payload = null) {
  $ch = curl_init("https://{$k['subdomain']}.kommo.com/api/v4/$path");
  $opt = [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $k['token']],
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
  ];
  if ($payload !== null) $opt[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
  curl_setopt_array($ch, $opt);
  $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  return [$code, json_decode((string)$resp, true), (string)$resp];
}

/** id поля сделки «Country of Residence»: из config, иначе ищем по имени и кэшируем в .kommo_fields.json */
function kommo_field_exists(array $k, int $id): bool {
  [$code] = kommo_call($k, 'GET', "leads/custom_fields/$id");
  return $code === 200;
}
function kommo_residence_field_id(array $k): ?int {
  // id из config или кэша используем только если поле всё ещё существует в Kommo (его могли удалить)
  if (!empty($k['residence_field_id'])) { $id = (int)$k['residence_field_id']; if (kommo_field_exists($k, $id)) return $id; }
  $cache = __DIR__ . '/.kommo_fields.json';
  if (file_exists($cache)) { $c = json_decode((string)file_get_contents($cache), true); if (!empty($c['residence'])) { $id = (int)$c['residence']; if (kommo_field_exists($k, $id)) return $id; @unlink($cache); } }
  $names = array_map('mb_strtolower', $k['residence_field_names'] ?? ['Country of Residence', 'Country of residence', 'Residence', 'Страна проживания']);
  $page = 1; $found = null;
  while ($page <= 5 && !$found) {
    [$code, $data] = kommo_call($k, 'GET', "leads/custom_fields?limit=250&page=$page");
    $fields = $data['_embedded']['custom_fields'] ?? [];
    if ($code !== 200 || !$fields) break;
    foreach ($fields as $fld) if (in_array(mb_strtolower(trim($fld['name'] ?? '')), $names, true)) { $found = (int)$fld['id']; break; }
    $page++;
  }
  if ($found) @file_put_contents($cache, json_encode(['residence' => $found]));
  return $found;
}

$kommo_ok = null; $kommo_note = '';
if ($KOMMO && !empty($KOMMO['token']) && !empty($KOMMO['subdomain'])) {
  $fid = ($residence !== '') ? kommo_residence_field_id($KOMMO) : null;
  if ($residence !== '' && !$fid) $kommo_note = 'residence field not found';

  $lead = [ 'name' => "$service — $name" ];
  if ($fid) $lead['custom_fields_values'] = [[ 'field_id' => $fid, 'values' => [['value' => $residence]] ]];
  if (!empty($KOMMO['tags'])) $lead['_embedded'] = ['tags' => array_map(fn($t) => ['name' => $t], $KOMMO['tags'])];

  $form_name = $f('form') ?: 'usatravel.ae — Case Assessment';
  // «Неразобранное» (Incoming leads): сделка без ответственного, менеджер принимает её сам
  $payload = [[
    'source_uid'  => 'usatravel.ae-' . bin2hex(random_bytes(8)),
    'source_name' => 'usatravel.ae',
    'pipeline_id' => (int)($KOMMO['pipeline_id'] ?? 0) ?: null,
    'created_at'  => time(),
    'metadata' => [
      'form_id'      => 'usatravel_ae',
      'form_name'    => $form_name,
      'form_page'    => $url ?: 'https://usatravel.ae/',
      'ip'           => $ip ?: '0.0.0.0',
      'form_sent_at' => time(),
      'referer'      => $url ?: 'https://usatravel.ae/',
    ],
    '_embedded' => [
      'leads'    => [$lead],
      'contacts' => [[
        'name' => $name,
        'custom_fields_values' => [[ 'field_code' => 'PHONE', 'values' => [['value' => $phone, 'enum_code' => 'WORK']] ]],
      ]],
    ],
  ]];
  $payload[0] = array_filter($payload[0], fn($v) => $v !== null);

  [$code, $data, $raw] = kommo_call($KOMMO, 'POST', 'leads/unsorted/forms', $payload);
  // если Kommo отклонил кастомное поле (например, его удалили) — повторяем без него; страна и так есть в примечании
  if ($code === 400 && $fid && strpos($raw, 'custom_fields_values') !== false) {
    unset($payload[0]['_embedded']['leads'][0]['custom_fields_values']);
    @file_put_contents(__DIR__ . '/leads.log', "KOMMO retry without residence field (400): " . substr($raw, 0, 200) . "\n", FILE_APPEND | LOCK_EX);
    [$code, $data, $raw] = kommo_call($KOMMO, 'POST', 'leads/unsorted/forms', $payload);
  }
  $kommo_ok = ($code >= 200 && $code < 300);
  if ($kommo_ok) {
    $lead_id = $data['_embedded']['unsorted'][0]['_embedded']['leads'][0]['id'] ?? null;
    if ($lead_id) kommo_call($KOMMO, 'POST', "leads/$lead_id/notes", [[ 'note_type' => 'common', 'params' => ['text' => $body] ]]);
  } else {
    @file_put_contents(__DIR__ . '/leads.log', "KOMMO ERROR $code: " . substr($raw, 0, 300) . "\n", FILE_APPEND | LOCK_EX);
  }
}

echo json_encode(['ok' => true, 'mail' => (bool)$mailed, 'kommo' => $kommo_ok, 'note' => $kommo_note]);
