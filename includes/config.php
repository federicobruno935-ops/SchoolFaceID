<?php
// Carica variabili da .env (gitignored). In ambienti dove putenv() è disabilitato
// (es. Altervista) leggiamo direttamente da $_ENV via la helper _env().
$envFile = __DIR__ . '/../.env';
if (is_readable($envFile)) {
    $loaded = @parse_ini_file($envFile, false, INI_SCANNER_RAW);
    if (is_array($loaded)) {
        foreach ($loaded as $k => $v) {
            $_ENV[$k] = $v;
            @putenv("$k=$v");
        }
    }
}

function _env(string $key, string $default = ''): string {
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string)$_ENV[$key];
    $v = getenv($key);
    return ($v !== false && $v !== '') ? (string)$v : $default;
}

define('SCHOOLFACEID_API_KEY', _env('API_KEY'));
define('BASE_URL',             _env('BASE_URL', 'http://localhost/registro'));
define('MAIL_FROM',            _env('SMTP_USER'));
define('MAIL_FROM_NAME', 'SchoolFaceID');
define('SMTP_HOST',            _env('SMTP_HOST', 'smtp.gmail.com'));
define('SMTP_PORT', 587);
define('SMTP_USER',            _env('SMTP_USER'));
define('SMTP_PASS',            _env('SMTP_PASS'));
