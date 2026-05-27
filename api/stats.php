<?php
session_start();
if (!isset($_SESSION['utente_id'])) {
    http_response_code(401);
    echo json_encode(['errore' => 'Non autorizzato']);
    exit;
}

header('Content-Type: application/json');
require_once '../includes/db.php';

$oggi       = date('Y-m-d');
$classe_sel = isset($_GET['classe']) && $_GET['classe'] !== '' ? (int)$_GET['classe'] : null;
$filtro_sql = $classe_sel ? "AND u.classe_id = :cid" : '';

// Totale
$stmt = $pdo->prepare("SELECT COUNT(*) FROM utenti u WHERE u.ruolo='studente' AND u.attivo=1 " . ($classe_sel ? "AND u.classe_id = :cid" : ''));
if ($classe_sel) $stmt->bindValue(':cid', $classe_sel, PDO::PARAM_INT);
$stmt->execute();
$totale = (int)$stmt->fetchColumn();

// Counters
$stmt = $pdo->prepare("
    SELECT
        SUM(p.stato='presente')          AS presenti,
        SUM(p.stato='ritardo')           AS ritardi,
        SUM(p.stato='uscita_anticipata') AS uscite
    FROM presenze p
    INNER JOIN utenti u ON u.id = p.studente_id AND u.ruolo='studente' AND u.attivo=1
    WHERE p.data = :data $filtro_sql
");
$stmt->bindValue(':data', $oggi);
if ($classe_sel) $stmt->bindValue(':cid', $classe_sel, PDO::PARAM_INT);
$stmt->execute();
$counts       = $stmt->fetch();
$presenti     = (int)$counts['presenti'];
$ritardi      = (int)$counts['ritardi'];
$uscite       = (int)$counts['uscite'];
$assenti      = max(0, $totale - $presenti - $ritardi - $uscite);

// Studenti con stato
$stmt = $pdo->prepare("
    SELECT u.id, u.nome, u.cognome, u.foto_path,
           COALESCE(p.stato, 'assente') as stato
    FROM utenti u
    LEFT JOIN presenze p ON p.studente_id = u.id AND p.data = :data
    WHERE u.ruolo = 'studente' AND u.attivo = 1 $filtro_sql
    ORDER BY u.cognome, u.nome
");
$stmt->bindValue(':data', $oggi);
if ($classe_sel) $stmt->bindValue(':cid', $classe_sel, PDO::PARAM_INT);
$stmt->execute();
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
    'totale'          => $totale,
    'presenti'        => $presenti,
    'assenti'         => $assenti,
    'ritardi'         => $ritardi,
    'uscite'          => $uscite,
    'studenti'        => $studenti,
    'riconoscimenti'  => $riconoscimenti,
]);
