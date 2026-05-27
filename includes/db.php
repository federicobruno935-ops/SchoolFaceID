<?php
// includes/db.php — connessione PDO MySQL.
// Le credenziali vengono lette da .env via la helper _env() definita in config.php.

require_once __DIR__ . '/config.php';

define('DB_HOST', _env('DB_HOST', 'localhost'));
define('DB_NAME', _env('DB_NAME', 'registro_facciale'));
define('DB_USER', _env('DB_USER', 'root'));
define('DB_PASS', _env('DB_PASS', ''));

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['errore' => 'Connessione DB fallita']));
}

// Valida foto_path contro directory traversal — accetta solo percorsi interni a uploads/studenti/
function foto_path_sicuro(?string $path): string {
    if (!$path) return '';
    $path = str_replace('\\', '/', $path);
    if (!preg_match('/^uploads\/studenti\/[\w\-]+\/[\w\-\.]+$/', $path)) return '';
    return $path;
}
