<?php
/**
 * api/eventi.php
 * Server-Sent Events — manda aggiornamenti in tempo reale alla dashboard.
 * Richiede sessione autenticata (docente o studente).
 */

session_start();
if (!isset($_SESSION['utente_id']) && !isset($_SESSION['studente_id'])) {
    http_response_code(401);
    exit;
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$cache_file = __DIR__ . '/../cache/ultimo_evento.json';
$ultimo_ts  = isset($_GET['since']) ? (int)$_GET['since'] : 0;

// Tieni la connessione aperta max 30 secondi
$timeout = time() + 30;

while (time() < $timeout) {
    if (file_exists($cache_file)) {
        $data = json_decode(file_get_contents($cache_file), true);

        // Manda evento solo se è nuovo rispetto all'ultimo che il client conosce
        if (isset($data['timestamp']) && $data['timestamp'] > $ultimo_ts) {
            $ultimo_ts = $data['timestamp'];
            echo "data: " . json_encode($data) . "\n\n";
            ob_flush();
            flush();
        }
    }

    // Heartbeat ogni 5 secondi per tenere viva la connessione
    echo ": heartbeat\n\n";
    ob_flush();
    flush();

    sleep(2); // controlla ogni 2 secondi
}

// Chiudi — il client si riconnetterà automaticamente
echo "data: {\"tipo\":\"reconnect\"}\n\n";
ob_flush();
flush();
