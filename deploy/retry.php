<?php
/**
 * Повтор заявок, которые не дошли до Kommo. Запускается по cron раз в 10 минут:
 *   php /home/visaproa/usatravel.ae/retry.php
 * Берёт из pending.jsonl записи старше 10 минут, один раз отправляет их в send.php
 * с флагом _retry (письмо не дублируется), результат уходит в Telegram из send.php.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dir = __DIR__; $q = "$dir/pending.jsonl"; $tg = file_exists("$dir/telegram.php") ? include "$dir/telegram.php" : [];
$SITE = 'https://usatravel.ae/send.php';
if (!file_exists($q) || !filesize($q)) exit;
$fh = fopen($q, 'c+'); if (!flock($fh, LOCK_EX)) exit;
$lines = array_filter(array_map('trim', file($q)));
$keep = []; $now = time();
foreach ($lines as $line) {
  $e = json_decode($line, true);
  if (!$e || empty($e['post'])) continue;
  if ($now - (int)$e['ts'] < 600) { $keep[] = $line; continue; } // ещё не прошло 10 минут
  $post = $e['post']; $post['_retry'] = 1; $post['_retry_key'] = $tg['retry_key'] ?? '';
  $ch = curl_init($SITE);
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_POSTFIELDS => http_build_query($post)]);
  $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  @file_put_contents("$dir/leads.log", "RETRY " . ($post['fname'] ?? '') . " -> HTTP $code " . substr((string)$r, 0, 120) . "\n", FILE_APPEND | LOCK_EX);
  // повтор делаем один раз: из очереди убираем в любом случае
}
ftruncate($fh, 0); rewind($fh); if ($keep) fwrite($fh, implode("\n", $keep) . "\n"); flock($fh, LOCK_UN); fclose($fh);
