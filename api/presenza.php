<?php

/**
 * api/presenza.php
 * Endpoint che riceve i dati dal Raspberry Pi
 * 
 * Accetta POST con JSON:
 * {
 *   "studente_id": 3,
 *   "nome": "Mario Rossi",       (opzionale, per log)
 *   "confidenza": 0.92,
 *   "timestamp": "2026-04-21 08:45:00",
 *   "api_key": "chiave_segreta"
 * }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');
// L'autenticazione avviene tramite API key; non blocchiamo per IP (il Raspberry si collega da internet).
header('Access-Control-Allow-Origin: *');

require_once '../includes/config.php';
define('API_KEY', SCHOOLFACEID_API_KEY);

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['errore' => 'Metodo non consentito']);
    exit;
}

// Leggi il body JSON
$body = file_get_contents('php://input');
$data = json_decode($body, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['errore' => 'JSON non valido']);
    exit;
}

// Verifica API key (fail fast se non configurata lato server)
if (API_KEY === '' || ($data['api_key'] ?? '') !== API_KEY) {
    http_response_code(401);
    echo json_encode(['errore' => 'API key non valida']);
    exit;
}

// Valida campi obbligatori
$studente_id = isset($data['studente_id']) ? (int)$data['studente_id'] : null;
$confidenza  = isset($data['confidenza'])  ? (float)$data['confidenza'] : null;
$esito       = $data['esito'] ?? 'riconosciuto'; // 'riconosciuto' o 'sconosciuto'
$timestamp   = $data['timestamp'] ?? date('Y-m-d H:i:s');

require_once '../includes/db.php';

// ---- SALVA NEL LOG RICONOSCIMENTI ----
$stmt = $pdo->prepare("
    INSERT INTO log_riconoscimenti (utente_id, timestamp, confidenza, esito)
    VALUES (?, ?, ?, ?)
");
$stmt->execute([$studente_id, $timestamp, $confidenza, $esito]);

// Se sconosciuto, ci fermiamo qui
if ($esito === 'sconosciuto' || !$studente_id) {
    echo json_encode([
        'stato'   => 'ok',
        'azione'  => 'log_sconosciuto',
        'message' => 'Volto sconosciuto registrato nel log'
    ]);
    exit;
}

// ---- VERIFICA CHE LO STUDENTE ESISTA ----
$stmt    = $pdo->prepare("SELECT * FROM utenti WHERE id = ? AND ruolo = 'studente' AND attivo = 1");
$stmt->execute([$studente_id]);
$studente = $stmt->fetch();

if (!$studente) {
    http_response_code(404);
    echo json_encode(['errore' => 'Studente non trovato']);
    exit;
}

// ---- LOGICA PRESENZA ----
$oggi      = date('Y-m-d', strtotime($timestamp));
$ora       = date('H:i:s', strtotime($timestamp));

// Orario scolastico — letto dalle impostazioni configurabili dal docente
require_once '../includes/impostazioni.php';
$imp                = get_impostazioni();
$ora_inizio_lezioni = $imp['ora_inizio_lezioni'];
$minuti_ritardo     = (int)$imp['minuti_ritardo'];

// Calcola stato
$stato = 'presente';
if ($ora > date('H:i:s', strtotime($ora_inizio_lezioni . ' +' . $minuti_ritardo . ' minutes'))) {
    $stato = 'ritardo';
}

$azione = '';

$pdo->beginTransaction();
try {
    // SELECT FOR UPDATE — blocca la riga durante la transazione, previene race condition
    $stmt = $pdo->prepare("
        SELECT * FROM presenze
        WHERE studente_id = ? AND data = ?
        ORDER BY id DESC LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$studente_id, $oggi]);
    $presenza_esistente = $stmt->fetch();

    if (!$presenza_esistente) {
        $pdo->prepare("
            INSERT INTO presenze (studente_id, data, ora_entrata, stato, rilevato_da)
            VALUES (?, ?, ?, ?, 'facciale')
        ")->execute([$studente_id, $oggi, $ora, $stato]);
        $azione = 'entrata_registrata';

    } elseif ($presenza_esistente['ora_uscita'] === null && $ora > $presenza_esistente['ora_entrata']) {
        $diff_minuti = (strtotime($ora) - strtotime($presenza_esistente['ora_entrata'])) / 60;
        if ($diff_minuti >= 30) {
            $pdo->prepare("UPDATE presenze SET ora_uscita = ? WHERE id = ?")
                ->execute([$ora, $presenza_esistente['id']]);
            $azione = 'uscita_registrata';
        } else {
            $azione = 'ignorato_troppo_presto';
        }
    } else {
        $azione = 'presenza_gia_registrata';
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['errore' => 'Errore interno, riprova.']);
    exit;
}


// Salva ultimo evento per SSE
$evento = [
    'studente_id' => $studente_id,
    'studente'    => $studente['nome'] . ' ' . $studente['cognome'],
    'azione'      => $azione,
    'ora'         => $ora,
    'stato'       => $stato,
    'timestamp'   => time()
];
file_put_contents(__DIR__ . '/../cache/ultimo_evento.json', json_encode($evento));

// ---- RISPOSTA ----
echo json_encode([
    'stato'      => 'ok',
    'azione'     => $azione,
    'studente'   => $studente['nome'] . ' ' . $studente['cognome'],
    'data'       => $oggi,
    'ora'        => $ora,
    'stato_pres' => $stato,
    'message'    => match($azione) {
        'entrata_registrata'      => "Entrata di {$studente['nome']} registrata ($stato)",
        'uscita_registrata'       => "Uscita di {$studente['nome']} registrata",
        'ignorato_troppo_presto'  => "Rilevamento ignorato (troppo vicino all'entrata)",
        'presenza_gia_registrata' => "Presenza già registrata per oggi",
        default                   => $azione
    }
]);
