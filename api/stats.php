<?php
session_start();
if (!isset($_SESSION['utente_id'])) {
    http_response_code(401);
    echo json_encode(['errore' => 'Non autorizzato']);
    exit;
}

header('Content-Type: application/json');
require_once '../includes/db.php';

$oggi = date('Y-m-d');

// Contatori
$totale = $pdo->query("SELECT COUNT(*) FROM utenti WHERE ruolo='studente' AND attivo=1")->fetchColumn();

$stmt = $pdo->prepare("
    SELECT SUM(p.stato='presente') as presenti, SUM(p.stato='ritardo') as ritardi,
           COUNT(DISTINCT p.studente_id) as con_presenza
    FROM presenze p
    INNER JOIN utenti u ON u.id = p.studente_id AND u.ruolo='studente' AND u.attivo=1
    WHERE p.data=?
");
$stmt->execute([$oggi]);
$counts       = $stmt->fetch();
$presenti     = (int)$counts['presenti'];
$ritardi      = (int)$counts['ritardi'];
$assenti      = max(0, (int)$totale - (int)$counts['con_presenza']);

// Studenti con stato
$stmt = $pdo->prepare("
    SELECT u.id, u.nome, u.cognome, u.foto_path,
           COALESCE(p.stato, 'assente') as stato
    FROM utenti u
    LEFT JOIN presenze p ON p.studente_id = u.id AND p.data = ?
    WHERE u.ruolo = 'studente' AND u.attivo = 1
    ORDER BY u.cognome, u.nome
");
$stmt->execute([$oggi]);
$studenti = $stmt->fetchAll();

// Riconoscimenti recenti
$stmt2 = $pdo->query("
    SELECT u.nome, u.cognome, l.timestamp
    FROM log_riconoscimenti l
    JOIN utenti u ON u.id = l.utente_id
    WHERE l.esito = 'riconosciuto'
    ORDER BY l.timestamp DESC
    LIMIT 8
");
$riconoscimenti = $stmt2->fetchAll();

echo json_encode([
    'totale'          => (int)$totale,
    'presenti'        => (int)$presenti,
    'assenti'         => (int)$assenti,
    'ritardi'         => (int)$ritardi,
    'studenti'        => $studenti,
    'riconoscimenti'  => $riconoscimenti,
]);
