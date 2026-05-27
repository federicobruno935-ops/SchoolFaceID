<?php
// Carica variabili da .env (gitignored) — usato in dev locale. In produzione
// le variabili devono essere già esportate dall'host o via .htaccess SetEnv.
$envFile = __DIR__ . '/../.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $v = trim($v, "\"'");
        if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
    }
}

define('SCHOOLFACEID_API_KEY', getenv('API_KEY')   ?: '');
define('BASE_URL',             getenv('BASE_URL')  ?: 'http://localhost/registro');
define('MAIL_FROM',            getenv('SMTP_USER') ?: '');
define('MAIL_FROM_NAME', 'SchoolFaceID');
define('SMTP_HOST',            getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER',            getenv('SMTP_USER') ?: '');
define('SMTP_PASS',            getenv('SMTP_PASS') ?: '');
