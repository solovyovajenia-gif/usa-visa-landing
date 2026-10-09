<?php
/**
 * Приём заявок с usatravel.ae.
 * 1) Письмо на почту менеджера (всегда).
 * 2) Сделка в Kommo через API напрямую (если задан токен в config.php).
 * 3) Лог в leads.log рядом (на случай сбоев почты/Kommo).
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }

$cfg = file_exists(__DIR__ . '/config.php') ? include __DIR__ . '/config.php' : [];
$MAIL_TO   = $cfg['mail_to']   ?? 'info@worldpass.ae';
$MAIL_FROM = $cfg['mail_from'] ?? 'leads@usatravel.ae';
$KOMMO     = $cfg['kommo']     ?? null; // ['subdomain'=>'xxx','token'=>'...','pipeline_id'=>0,'status_id'=>0]

$f = fn($k) => trim(mb_substr((string)($_POST[$k] ?? ''), 0, 500));
$name    = $f('fname');
$phone   = preg_replace('/[^\d+ ]/', '', $f('phone'));
$msg     = $f('msg');
$service = $f('service') ?: 'USA Visa B1/B2';
$url     = $f('url');
$utm     = [];
foreach (['utm_source','utm_campaign','utm_medium','utm_term','utm_content'] as $u) if ($f($u) !== '') $utm[$u] = $f($u);

// простая защита от ботов: пустое имя/телефон или honeypot
if ($name === '' || strlen(preg_replace('/\D/', '', $phone)) < 7 || !empty($_POST['website'])) {
  http_response_code(400); echo '{"ok":false,"error":"invalid"}'; exit;
}

$when = date('Y-m-d H:i:s');
$ip   = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$lines = [
  "Новая заявка — $service",
  "Имя: $name",
  "Телефон: $phone",
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

// 2) Kommo напрямую
$kommo_ok = null;
if ($KOMMO && !empty($KOMMO['token']) && !empty($KOMMO['subdomain'])) {
  $lead = [[
    'name' => "$service — $name",
    'pipeline_id' => (int)($KOMMO['pipeline_id'] ?? 0) ?: null,
    'status_id'   => (int)($KOMMO['status_id'] ?? 0) ?: null,
    '_embedded' => ['contacts' => [[
      'name' => $name,
      'custom_fields_values' => [[ 'field_code' => 'PHONE', 'values' => [['value' => $phone, 'enum_code' => 'WORK']] ]],
    ]]],
  ]];
  if (!empty($KOMMO['tags'])) $lead[0]['_embedded']['tags'] = array_map(fn($t) => ['name' => $t], $KOMMO['tags']);
  $lead[0] = array_filter($lead[0], fn($v) => $v !== null);
  $ch = curl_init("https://{$KOMMO['subdomain']}.kommo.com/api/v4/leads/complex");
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $KOMMO['token']],
    CURLOPT_POSTFIELDS => json_encode($lead, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
  ]);
  $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  $kommo_ok = ($code >= 200 && $code < 300);
  // примечание к сделке с деталями
  if ($kommo_ok) {
    $data = json_decode($resp, true);
    $lead_id = $data[0]['id'] ?? null;
    if ($lead_id) {
      $note = [[ 'note_type' => 'common', 'params' => ['text' => $body] ]];
      $ch = curl_init("https://{$KOMMO['subdomain']}.kommo.com/api/v4/leads/$lead_id/notes");
      curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $KOMMO['token']], CURLOPT_POSTFIELDS => json_encode($note, JSON_UNESCAPED_UNICODE), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
      curl_exec($ch); curl_close($ch);
    }
  } else {
    @file_put_contents(__DIR__ . '/leads.log', "KOMMO ERROR $code: " . substr((string)$resp, 0, 300) . "\n", FILE_APPEND | LOCK_EX);
  }
}

echo json_encode(['ok' => true, 'mail' => (bool)$mailed, 'kommo' => $kommo_ok]);
