<?php
/**
 * api/studenti.php
 * Restituisce la lista studenti al Raspberry per sincronizzare le foto
 */

header('Content-Type: application/json');

require_once '../includes/config.php';
define('API_KEY', SCHOOLFACEID_API_KEY);

// Verifica API key
if (($_GET['api_key'] ?? '') !== API_KEY) {
    http_response_code(401);
    echo json_encode(['errore' => 'API key non valida']);
    exit;
}

require_once '../includes/db.php';

$stmt = $pdo->query("
    SELECT id, nome, cognome, foto_path
    FROM utenti
    WHERE ruolo = 'studente' AND attivo = 1
    ORDER BY cognome, nome
");

echo json_encode($stmt->fetchAll());
