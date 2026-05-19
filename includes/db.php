<?php
// includes/db.php
// Connessione al database MySQL di XAMPP

define('DB_HOST', 'localhost');
define('DB_NAME', 'registro_facciale');
define('DB_USER', 'root');
define('DB_PASS', '');   // lascia vuoto se non hai impostato password su XAMPP

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
    die(json_encode(['errore' => 'Connessione DB fallita: ' . $e->getMessage()]));
}

// Valida foto_path contro directory traversal — accetta solo percorsi interni a uploads/studenti/
function foto_path_sicuro(?string $path): string {
    if (!$path) return '';
    $path = str_replace('\\', '/', $path);
    if (!preg_match('/^uploads\/studenti\/[\w\-]+\/[\w\-\.]+$/', $path)) return '';
    return $path;
}
