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
$TG        = $cfg['telegram']  ?? (file_exists(__DIR__ . '/telegram.php') ? include __DIR__ . '/telegram.php' : null);
// повторная отправка из очереди (retry.php по cron): письмо не дублируем, в очередь не кладём
$isRetry   = !empty($_POST['_retry']) && !empty($TG['retry_key']) && hash_equals((string)$TG['retry_key'], (string)($_POST['_retry_key'] ?? ''));

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
$mailed = $isRetry ? null : @mail($MAIL_TO, $subject, $body, $headers);

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

/** Уведомление в Telegram (копия каждой заявки + статус Kommo) */
function tg_send(?array $tg, string $html): void {
  if (!$tg || empty($tg['token']) || empty($tg['chat_id'])) return;
  $ch = curl_init("https://api.telegram.org/bot{$tg['token']}/sendMessage");
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
    CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $tg['chat_id'], 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true])]);
  $r = curl_exec($ch); curl_close($ch);
  if (!$r || strpos((string)$r, '"ok":true') === false) @file_put_contents(__DIR__ . '/leads.log', "TELEGRAM ERROR: " . substr((string)$r, 0, 200) . "\n", FILE_APPEND | LOCK_EX);
}

/** Поле контакта «Country of Residence» (multiselect): id поля и enum_id значения. Кэш в .kommo_fields.json на сутки. */
function kommo_residence_enum(array $k, string $residence): ?array {
  $cache = __DIR__ . '/.kommo_fields.json';
  $f = null;
  if (file_exists($cache) && time() - filemtime($cache) < 86400) { $c = json_decode((string)file_get_contents($cache), true); if (!empty($c['contact_residence'])) $f = $c['contact_residence']; }
  if (!$f) {
    $names = array_map('mb_strtolower', $k['residence_field_names'] ?? ['Country of Residence', 'Country of residence', 'Residence', 'Страна проживания']);
    for ($page = 1; $page <= 5 && !$f; $page++) {
      [$code, $data] = kommo_call($k, 'GET', "contacts/custom_fields?limit=250&page=$page");
      $fields = $data['_embedded']['custom_fields'] ?? [];
      if ($code !== 200 || !$fields) break;
      foreach ($fields as $fld) if (in_array(mb_strtolower(trim($fld['name'] ?? '')), $names, true)) {
        $f = ['id' => (int)$fld['id'], 'type' => $fld['type'] ?? '', 'enums' => []];
        foreach ($fld['enums'] ?? [] as $e) $f['enums'][mb_strtolower(trim($e['value']))] = (int)$e['id'];
        break;
      }
    }
    if ($f) @file_put_contents($cache, json_encode(['contact_residence' => $f]));
  }
  if (!$f) return null;
  if (empty($f['enums'])) return ['field_id' => $f['id'], 'value' => $residence]; // текстовое поле
  // RU → EN для списка стран с сайта
  static $ru = ['оаэ'=>'united arab emirates','россия'=>'russia','казахстан'=>'kazakhstan','узбекистан'=>'uzbekistan','армения'=>'armenia','грузия'=>'georgia','азербайджан'=>'azerbaijan','кыргызстан'=>'kyrgyzstan','беларусь'=>'belarus','украина'=>'ukraine','турция'=>'turkey','сербия'=>'serbia','кипр'=>'cyprus','черногория'=>'montenegro','таиланд'=>'thailand','индонезия (бали)'=>'indonesia','индия'=>'india','пакистан'=>'pakistan','египет'=>'egypt','саудовская аравия'=>'saudi arabia','катар'=>'qatar','оман'=>'oman','кувейт'=>'kuwait','бахрейн'=>'bahrain','иордания'=>'jordan','ливан'=>'lebanon','марокко'=>'morocco','нигерия'=>'nigeria','кения'=>'kenya','юар'=>'south africa','великобритания'=>'united kingdom','германия'=>'germany','франция'=>'france','испания'=>'spain','италия'=>'italy','португалия'=>'portugal','нидерланды'=>'netherlands','польша'=>'poland','чехия'=>'czech republic','израиль'=>'israel','канада'=>'canada','мексика'=>'mexico','бразилия'=>'brazil','аргентина'=>'argentina','китай'=>'china','филиппины'=>'philippines','вьетнам'=>'vietnam','малайзия'=>'malaysia','сингапур'=>'singapore','таджикистан'=>'tajikistan','uae'=>'united arab emirates','indonesia (bali)'=>'indonesia','другая страна'=>'other'];
  $key = mb_strtolower(trim($residence));
  if (isset($ru[$key])) $key = $ru[$key];
  if (isset($f['enums'][$key])) return ['field_id' => $f['id'], 'enum_id' => $f['enums'][$key]];
  if (isset($f['enums']['other'])) return ['field_id' => $f['id'], 'enum_id' => $f['enums']['other']];
  return null;
}

$kommo_ok = null; $kommo_note = ''; $kommo_lead_id = null; $kommo_err = '';
if ($KOMMO && !empty($KOMMO['token']) && !empty($KOMMO['subdomain'])) {
  $res = ($residence !== '') ? kommo_residence_enum($KOMMO, $residence) : null;
  if ($residence !== '' && !$res) $kommo_note = 'residence field not found';

  $lead = [ 'name' => "$service — $name" ];
  // поле сделки «Source» → «USA website» (иначе автоматизация Kommo ставит «Agent»)
  $srcField = (int)($KOMMO['source_field_id'] ?? 432700); $srcEnum = (int)($KOMMO['source_enum_id'] ?? 454630);
  $lf = [];
  if ($srcField && $srcEnum) $lf[] = [ 'field_id' => $srcField, 'values' => [['enum_id' => $srcEnum]] ];
  // UTM → поля сделки (tracking data), чтобы работала аналитика/атрибуция
  $UTM_FIELDS = ['utm_source'=>325784,'utm_medium'=>325780,'utm_campaign'=>325782,'utm_term'=>325786,'utm_content'=>325778];
  foreach ($UTM_FIELDS as $k => $fid) if (!empty($utm[$k])) $lf[] = [ 'field_id' => $fid, 'values' => [['value' => $utm[$k]]] ];
  if ($lf) $lead['custom_fields_values'] = $lf;
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
        'custom_fields_values' => array_values(array_filter([
          [ 'field_code' => 'PHONE', 'values' => [['value' => $phone, 'enum_code' => 'WORK']] ],
          $res ? [ 'field_id' => $res['field_id'], 'values' => [ isset($res['enum_id']) ? ['enum_id' => $res['enum_id']] : ['value' => $res['value']] ] ] : null,
        ])),
      ]],
    ],
  ]];
  $payload[0] = array_filter($payload[0], fn($v) => $v !== null);

  [$code, $data, $raw] = kommo_call($KOMMO, 'POST', 'leads/unsorted/forms', $payload);
  // если Kommo отклонил кастомное поле (например, его удалили) — повторяем без него; страна и так есть в примечании
  if ($code === 400 && strpos($raw, 'custom_fields_values') !== false) {
    $payload[0]['_embedded']['contacts'][0]['custom_fields_values'] = [[ 'field_code' => 'PHONE', 'values' => [['value' => $phone, 'enum_code' => 'WORK']] ]];
    unset($payload[0]['_embedded']['leads'][0]['custom_fields_values']);
    @file_put_contents(__DIR__ . '/leads.log', "KOMMO retry without custom fields (400): " . substr($raw, 0, 200) . "\n", FILE_APPEND | LOCK_EX);
    [$code, $data, $raw] = kommo_call($KOMMO, 'POST', 'leads/unsorted/forms', $payload);
  }
  $kommo_ok = ($code >= 200 && $code < 300);
  $kommo_err = $kommo_ok ? '' : "HTTP $code: " . substr(preg_replace('/\s+/', ' ', strip_tags((string)$raw)), 0, 160);
  if ($kommo_ok) {
    $lead_id = $data['_embedded']['unsorted'][0]['_embedded']['leads'][0]['id'] ?? null;
    $kommo_lead_id = $lead_id;
    if ($lead_id) kommo_call($KOMMO, 'POST', "leads/$lead_id/notes", [[ 'note_type' => 'common', 'params' => ['text' => $body] ]]);
  } else {
    @file_put_contents(__DIR__ . '/leads.log', "KOMMO ERROR $code: " . substr($raw, 0, 300) . "\n", FILE_APPEND | LOCK_EX);
    if (!$isRetry) { // в очередь на один повтор через 10 минут (retry.php)
      $q = $_POST; unset($q['website'], $q['_retry'], $q['_retry_key']);
      @file_put_contents(__DIR__ . '/pending.jsonl', json_encode(['ts' => time(), 'post' => $q], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }
  }
}

// ---------- Telegram: копия заявки ----------
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$kline = $kommo_ok === null ? '⚪️ Kommo: не настроен'
       : ($kommo_ok ? '✅ Kommo: сделка #' . ($kommo_lead_id ?? '?') . ' создана' . ($isRetry ? ' (повтор)' : '')
                    : '❌ Kommo: НЕ создана — ' . $h($kommo_err ?? '') . ($isRetry ? "\n⚠️ Повтор не удался — завести вручную" : "\n🔁 Повтор через 10 минут"));
$utmline = $utm ? ($h($utm['utm_source'] ?? '—') . ' / ' . $h($utm['utm_campaign'] ?? '—')) : 'прямой заход';
tg_send($TG,
  ($isRetry ? "♻️ <b>usatravel.ae — повтор заявки</b>\n" : "🇺🇸 <b>usatravel.ae — новая заявка</b>\n") .
  "<b>Имя:</b> " . $h($name) . "\n" .
  "<b>Телефон:</b> " . $h($phone) . "\n" .
  ($residence !== '' ? "<b>Страна проживания:</b> " . $h($residence) . "\n" : '') .
  "<b>Форма:</b> " . $h($form_name ?? $f('form') ?: '—') . "\n" .
  "<b>Источник:</b> " . $utmline . "\n" .
  ($mailed === null ? '' : ($mailed ? "📧 Письмо отправлено\n" : "⚠️ Письмо НЕ отправлено\n")) .
  $kline);

echo json_encode(['ok' => true, 'mail' => (bool)$mailed, 'kommo' => $kommo_ok, 'note' => $kommo_note]);
