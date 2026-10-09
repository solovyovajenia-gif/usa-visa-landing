<?php
// Настройки приёма заявок. Файл закрыт от веба через .htaccess.
return [
  'mail_to'   => 'info@worldpass.ae',
  'mail_from' => 'leads@usatravel.ae',

  // Kommo напрямую (без посредников). Заполнить, когда будет токен:
  // Kommo → Настройки → Интеграции → Создать интеграцию → Долгосрочный токен.
  'kommo' => [
    'subdomain'   => '',   // например 'worldpass' из https://worldpass.kommo.com
    'token'       => '',   // долгосрочный токен
    'pipeline_id' => 0,    // можно оставить 0 — уйдёт в основную воронку
    'status_id'   => 0,
    'tags'        => ['usatravel.ae', 'USA B1/B2'],
  ],
];
