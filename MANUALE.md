# SchoolFaceID — Manuale Tecnico Completo

> Documentazione tecnica esaustiva del progetto SchoolFaceID — sistema di rilevazione presenze scolastiche con riconoscimento facciale.
> Versione: 1.0 — aggiornato al 21 maggio 2026

---

## Indice

1. [Introduzione e obiettivo](#1-introduzione-e-obiettivo)
2. [Architettura del sistema](#2-architettura-del-sistema)
3. [Stack tecnologico](#3-stack-tecnologico)
4. [Database](#4-database)
5. [Configurazione (includes/)](#5-configurazione-includes)
6. [API REST (api/)](#6-api-rest-api)
7. [Area docenti (pages/)](#7-area-docenti-pages)
8. [Area studenti (studenti_area/)](#8-area-studenti-studenti_area)
9. [Lato Raspberry Pi](#9-lato-raspberry-pi)
10. [Sicurezza](#10-sicurezza)
11. [Deploy e operazioni](#11-deploy-e-operazioni)
12. [Workflow comuni](#12-workflow-comuni)
13. [Troubleshooting](#13-troubleshooting)

---

## 1. Introduzione e obiettivo

SchoolFaceID automatizza la rilevazione delle presenze a scuola tramite riconoscimento facciale. Un Raspberry Pi 4 con webcam USB osserva l'ingresso, riconosce gli studenti già registrati nel sistema e comunica al server PHP/MySQL le entrate, le uscite e i ritardi in tempo reale.

**Tre attori principali**:

- **Raspberry Pi** — riconosce i volti, comunica al server
- **Server PHP/MySQL** — registra presenze, espone dashboard
- **Browser** (docenti + studenti) — visualizza presenze in tempo reale via SSE

**Tre interfacce**:

- **Area docenti** (`/pages/`) — login, dashboard real-time, registro, gestione studenti
- **Area studenti** (`/studenti_area/`) — login separato, dashboard personale
- **API REST** (`/api/`) — endpoint per Raspberry e refresh frontend

---

## 2. Architettura del sistema

```
┌─────────────────────┐
│   Webcam USB        │
└──────┬──────────────┘
       │
       ↓ frame video
┌─────────────────────────────────────────────┐
│ Raspberry Pi 4 (hostname: macarena)         │
│ • Python 3.13 + face_recognition + OpenCV   │
│ • Venv: ~/registro_env                      │
│ • systemd: schoolfaceid.service             │
│ • Script: ~/registro_facce/riconoscimento.py│
└──────┬──────────────────────────────────────┘
       │ HTTPS POST JSON
       │ + Bearer-like API key
       ↓
┌──────────────────────────────────────────────┐
│ Altervista (macarena.altervista.org)         │
│ • PHP 8.2 + MySQL 8                          │
│ • DB: my_macarena (user: macarena)           │
│ • Files: /membri/macarena/registro/          │
└──────┬──────────┬────────────────────────────┘
       │          │
       │ SSE      │ HTTP request
       ↓          ↓
┌──────────────┐ ┌──────────────────┐
│ Dashboard    │ │ Browser studente │
│ docenti      │ │ (area dedicata)  │
│ real-time    │ │                  │
└──────────────┘ └──────────────────┘
```

### Flusso di una rilevazione

1. Raspberry rileva un volto noto via OpenCV
2. POST a `https://macarena.altervista.org/registro/api/presenza.php` con `studente_id`, `confidenza`, `timestamp`, `api_key`
3. PHP verifica l'API key, valida l'utente, applica la logica entrata/uscita/ritardo dentro una transazione `BEGIN; SELECT ... FOR UPDATE; INSERT/UPDATE; COMMIT;`
4. PHP scrive `cache/ultimo_evento.json` con l'evento
5. I browser docenti aperti sulla dashboard ricevono l'evento via SSE (`api/eventi.php`) e aggiornano l'UI senza reload

---

## 3. Stack tecnologico

| Layer | Tecnologia | Note |
|-------|-----------|------|
| Hardware | Raspberry Pi 4 + Webcam USB | A scuola in produzione |
| Riconoscimento | `face_recognition` + OpenCV | encoding 128-dim per volto |
| Python | 3.13 nel venv `~/registro_env` | librerie: face_recognition, opencv-python, requests, numpy, pillow |
| Servizio | systemd unit `schoolfaceid.service` | autostart al boot, restart on failure |
| Backend | PHP 8.2 | nessun framework |
| Database | MySQL 8 (Altervista) / MariaDB (XAMPP locale) | utf8mb4 |
| ORM | PDO con prepared statements | `PDO::ERRMODE_EXCEPTION` + `EMULATE_PREPARES=false` |
| Frontend | PHP puro + HTML/CSS vanilla | nessun React/Vue |
| Charts | Chart.js 4.4 via CDN | solo in `pages/studenti.php` |
| Email | `mail()` PHP nativa | usa MTA Altervista, niente SMTP esterno |
| Real-time | Server-Sent Events (SSE) | un endpoint long-poll su `api/eventi.php` |
| Hosting prod | Altervista (gratuito, Italian) | account `macarena` |
| Deploy | Bash + curl FTP | script `deploy.sh` |

---

## 4. Database

### Schema completo

```sql
-- Anagrafica unica per studenti, professori, admin
CREATE TABLE utenti (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(100),
  cognome VARCHAR(100),
  email VARCHAR(150) UNIQUE,
  password_hash VARCHAR(255),
  ruolo ENUM('studente','professore','admin'),
  classe_id INT NULL,
  foto_path VARCHAR(255),
  encoding LONGTEXT,
  attivo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (classe_id) REFERENCES classi(id)
);

-- Una riga per studente per giornata (di solito)
CREATE TABLE presenze (
  id INT AUTO_INCREMENT PRIMARY KEY,
  studente_id INT,
  orario_id INT NULL,
  data DATE,
  ora_entrata TIME,
  ora_uscita TIME,
  stato ENUM('presente','assente','ritardo','uscita_anticipata'),
  rilevato_da ENUM('facciale','manuale') DEFAULT 'facciale',
  note TEXT,
  FOREIGN KEY (studente_id) REFERENCES utenti(id)
);

-- Log di ogni rilevamento webcam (anche sconosciuti)
CREATE TABLE log_riconoscimenti (
  id INT AUTO_INCREMENT PRIMARY KEY,
  utente_id INT NULL,
  timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  confidenza FLOAT,
  esito ENUM('riconosciuto','sconosciuto'),
  FOREIGN KEY (utente_id) REFERENCES utenti(id)
);

-- Token per il recupero password
CREATE TABLE password_reset_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  utente_id INT,
  token VARCHAR(64),
  scadenza DATETIME,
  usato TINYINT(1) DEFAULT 0
);

-- Anagrafica classi
CREATE TABLE classi (id INT PK, nome VARCHAR(50), anno_scolastico VARCHAR(20));

-- (Opzionali, struttura presente ma poco usata)
CREATE TABLE materie (id INT PK, nome VARCHAR(100), colore VARCHAR(7));
CREATE TABLE orario (id INT PK, classe_id, materia_id, professore_id, giorno ENUM, ora_inizio TIME, ora_fine TIME);
```

### Vincoli logici sugli orari per stato

Questi vincoli NON sono enforced a livello DB (perché Altervista non permette TRIGGER) ma sono applicati lato PHP nel form di modifica manuale:

| Stato | ora_entrata | ora_uscita |
|-------|-------------|------------|
| **presente** | obbligatoria | **deve essere NULL** |
| **assente** | **deve essere NULL** | **deve essere NULL** |
| **ritardo** | obbligatoria (>08:15) | **deve essere NULL** |
| **uscita_anticipata** | obbligatoria | **obbligatoria** |

---

## 5. Configurazione (includes/)

### `includes/config.php`

Centralizza tutte le credenziali del progetto. Ogni costante legge prima da variabile d'ambiente (utile per cambiare config senza modificare il codice), con fallback hardcoded.

```php
<?php
define('SCHOOLFACEID_API_KEY', getenv('API_KEY')  ?: 'REDACTED_API_KEY');
define('BASE_URL',             getenv('BASE_URL') ?: 'https://macarena.altervista.org/registro');
define('MAIL_FROM',            getenv('SMTP_USER')?: 'federicobruno935@gmail.com');
define('MAIL_FROM_NAME', 'SchoolFaceID');
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', getenv('SMTP_USER') ?: 'federicobruno935@gmail.com');
define('SMTP_PASS', getenv('SMTP_PASS') ?: 'REDACTED_SMTP_PASS');
```

**Nota**: le costanti SMTP_* sono residuo storico. Adesso `mailer.php` usa `mail()` nativa.

### `includes/db.php`

Crea l'istanza `$pdo` usata da tutto il backend.

```php
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'my_macarena');
define('DB_USER', getenv('DB_USER') ?: 'macarena');
define('DB_PASS', getenv('DB_PASS') ?: '');

try {
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['errore' => 'Connessione DB fallita: '.$e->getMessage()]));
}

// Helper: blocca i path con ".." per impedire directory traversal
function foto_path_sicuro(?string $path): string {
    if (!$path) return '';
    $path = str_replace('\\', '/', $path);
    if (!preg_match('/^uploads\/studenti\/[\w\-]+\/[\w\-\.]+$/', $path)) return '';
    return $path;
}
```

**Punto cruciale**: `EMULATE_PREPARES => false` significa che MySQL prepara la query lato server. Conseguenza: **non puoi usare lo stesso `:placeholder` due volte** nella stessa query. Usa nomi diversi o positional `?`.

### `includes/mailer.php`

Invia email di recupero password usando `mail()` nativa (Altervista blocca SMTP outbound).

```php
function invia_reset_password(string $email, string $nome, string $link): bool {
    $subject = '=?UTF-8?B?'.base64_encode('SchoolFaceID — Reset Password').'?=';

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: SchoolFaceID <noreply@macarena.altervista.org>\r\n";
    $headers .= "Reply-To: ".MAIL_FROM."\r\n";
    $headers .= "X-Mailer: PHP/".phpversion();

    $body = "...HTML con bottone link reset...";

    $inviato = @mail($email, $subject, $body, $headers);

    // Log di fallback — il link è sempre disponibile qui
    $riga = date('Y-m-d H:i:s') . " | TO: $email | SENT: " . ($inviato ? 'yes' : 'no') . " | LINK: $link\n";
    @file_put_contents(__DIR__.'/../cache/reset_log.txt', $riga, FILE_APPEND);

    return (bool) $inviato;
}

function genera_token_reset(PDO $pdo, int $utente_id): string {
    // Invalida i token esistenti per questo utente
    $pdo->prepare("UPDATE password_reset_tokens SET usato=1 WHERE utente_id=? AND usato=0")
        ->execute([$utente_id]);

    $token    = bin2hex(random_bytes(32));      // 64 char esadecimali
    $scadenza = date('Y-m-d H:i:s', strtotime('+30 minutes'));

    $pdo->prepare("INSERT INTO password_reset_tokens (utente_id, token, scadenza) VALUES (?, ?, ?)")
        ->execute([$utente_id, $token, $scadenza]);

    return $token;
}

function valida_token_reset(PDO $pdo, string $token): ?array {
    $stmt = $pdo->prepare("
        SELECT t.*, u.nome, u.cognome, u.email, u.ruolo
        FROM password_reset_tokens t
        JOIN utenti u ON u.id = t.utente_id
        WHERE t.token = ? AND t.usato = 0 AND t.scadenza > NOW()
    ");
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

function consuma_token_reset(PDO $pdo, string $token, string $nuova_password): void {
    $pdo->prepare("UPDATE password_reset_tokens SET usato=1 WHERE token=?")
        ->execute([$token]);
    $pdo->prepare("
        UPDATE utenti SET password_hash=?
        WHERE id=(SELECT utente_id FROM password_reset_tokens WHERE token=?)
    ")->execute([password_hash($nuova_password, PASSWORD_DEFAULT), $token]);
}
```

**Perché `noreply@macarena.altervista.org`**: usando un From su dominio Altervista, l'SPF check passa e Gmail non marca come spam.

---

## 6. API REST (api/)

### `api/presenza.php` — endpoint principale chiamato dal Raspberry

```php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../includes/config.php';
define('API_KEY', SCHOOLFACEID_API_KEY);

// Solo POST con body JSON
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); ...exit; }
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) { http_response_code(400); ...exit; }

// Auth via API key
if (($data['api_key'] ?? '') !== API_KEY) { http_response_code(401); ...exit; }

$studente_id = (int)$data['studente_id'];
$confidenza  = (float)$data['confidenza'];
$timestamp   = $data['timestamp'] ?? date('Y-m-d H:i:s');

require_once '../includes/db.php';

// 1. Log riconoscimento sempre, anche se sconosciuto
$pdo->prepare("INSERT INTO log_riconoscimenti (utente_id, timestamp, confidenza, esito) VALUES (?,?,?,?)")
    ->execute([$studente_id, $timestamp, $confidenza, $esito]);

// 2. Verifica esistenza studente
$stmt = $pdo->prepare("SELECT * FROM utenti WHERE id=? AND ruolo='studente' AND attivo=1");
$stmt->execute([$studente_id]);
$studente = $stmt->fetch();
if (!$studente) { http_response_code(404); ...exit; }

// 3. Calcola stato (presente vs ritardo)
$ora = date('H:i:s', strtotime($timestamp));
$stato = ($ora > '08:15:00') ? 'ritardo' : 'presente';

// 4. Logica entrata/uscita in transazione
$pdo->beginTransaction();
try {
    // Lock pessimistico per evitare race condition
    $stmt = $pdo->prepare("SELECT * FROM presenze WHERE studente_id=? AND data=? ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $stmt->execute([$studente_id, $oggi]);
    $presenza_esistente = $stmt->fetch();

    if (!$presenza_esistente) {
        // Prima rilevazione = entrata
        $pdo->prepare("INSERT INTO presenze (studente_id,data,ora_entrata,stato,rilevato_da) VALUES (?,?,?,?,'facciale')")
            ->execute([$studente_id, $oggi, $ora, $stato]);
        $azione = 'entrata_registrata';

    } elseif ($presenza_esistente['ora_uscita'] === null && $ora > $presenza_esistente['ora_entrata']) {
        $diff_minuti = (strtotime($ora) - strtotime($presenza_esistente['ora_entrata'])) / 60;
        if ($diff_minuti >= 30) {
            // Seconda rilevazione dopo 30 min = uscita
            $pdo->prepare("UPDATE presenze SET ora_uscita=? WHERE id=?")
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
    ...
}

// 5. Scrivi evento per SSE
file_put_contents(__DIR__.'/../cache/ultimo_evento.json', json_encode([
    'studente_id' => $studente_id,
    'studente'    => $studente['nome'].' '.$studente['cognome'],
    'azione'      => $azione,
    'ora'         => $ora,
    'stato'       => $stato,
    'timestamp'   => time()
]));

// 6. Risposta JSON
echo json_encode([...]);
```

**Punti chiave**:

- **API key in body** (non header) per semplicità lato Raspberry
- **Transazione + SELECT FOR UPDATE**: due rilevazioni simultanee dello stesso studente non creano due righe
- **Soglia 30 min** tra entrata e uscita: evita doppi conteggi per fluttuazioni di rilevamento
- **08:15** è la soglia ritardo (hardcoded in `$ora_inizio_lezioni + $minuti_ritardo`)

### `api/eventi.php` — Server-Sent Events

```php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // disabilita buffering nginx-like

$cache_file = __DIR__.'/../cache/ultimo_evento.json';
$ultimo_ts  = (int)($_GET['since'] ?? 0);

// Connessione aperta max 30s, poi il client riconnette automaticamente
$timeout = time() + 30;
while (time() < $timeout) {
    if (file_exists($cache_file)) {
        $data = json_decode(file_get_contents($cache_file), true);
        if (isset($data['timestamp']) && $data['timestamp'] > $ultimo_ts) {
            $ultimo_ts = $data['timestamp'];
            echo "data: ".json_encode($data)."\n\n";
            ob_flush(); flush();
        }
    }
    sleep(1);
}
// Manda al client istruzione "riconnetti"
echo 'data: '.json_encode(['tipo'=>'reconnect'])."\n\n";
```

Il client JS è in dashboard.php:

```javascript
function connetti() {
    const source = new EventSource('../api/eventi.php?since=' + ultimoTs);
    source.onmessage = (e) => {
        const data = JSON.parse(e.data);
        if (data.tipo === 'reconnect') { source.close(); setTimeout(connetti, 1000); return; }
        ultimoTs = data.timestamp;
        mostraNotifica(data);
        aggiornaDashboard();
    };
    source.onerror = () => { source.close(); setTimeout(connetti, 3000); };
}
connetti();
```

### `api/stats.php` — refresh dashboard via fetch()

Restituisce JSON con contatori e lista studenti per il refresh AJAX ogni 15 secondi. Richiede sessione valida (no API key qui).

```php
session_start();
if (!isset($_SESSION['utente_id'])) { http_response_code(401); ...exit; }
header('Content-Type: application/json');
require_once '../includes/db.php';

$oggi       = date('Y-m-d');
$classe_sel = (int)($_GET['classe'] ?? 0) ?: null;
$filtro_sql = $classe_sel ? "AND u.classe_id = :cid" : '';

// Totale studenti
$stmt = $pdo->prepare("SELECT COUNT(*) FROM utenti u WHERE u.ruolo='studente' AND u.attivo=1 ".($classe_sel?"AND u.classe_id=:cid":''));
if ($classe_sel) $stmt->bindValue(':cid', $classe_sel, PDO::PARAM_INT);
$stmt->execute();
$totale = (int)$stmt->fetchColumn();

// Conteggi per stato
$stmt = $pdo->prepare("
    SELECT SUM(p.stato='presente') AS presenti, SUM(p.stato='ritardo') AS ritardi,
           SUM(p.stato='uscita_anticipata') AS uscite
    FROM presenze p
    INNER JOIN utenti u ON u.id=p.studente_id AND u.ruolo='studente' AND u.attivo=1
    WHERE p.data=:data $filtro_sql
");
$stmt->bindValue(':data', $oggi);
if ($classe_sel) $stmt->bindValue(':cid', $classe_sel, PDO::PARAM_INT);
$stmt->execute();
$counts = $stmt->fetch();
$assenti = max(0, $totale - $counts['presenti'] - $counts['ritardi'] - $counts['uscite']);

echo json_encode([
    'totale'=>$totale, 'presenti'=>(int)$counts['presenti'], 'assenti'=>$assenti,
    'ritardi'=>(int)$counts['ritardi'], 'uscite'=>(int)$counts['uscite'],
    'studenti'=>$studenti, 'riconoscimenti'=>$riconoscimenti,
]);
```

**Formula chiave**: `assenti = totale - presenti - ritardi - uscite`. Così la somma chiude sempre, anche se ci sono record espliciti di stato `'assente'`.

### `api/studenti.php` — sync Raspberry

Lista studenti attivi, usata da `carica_studenti.py` sul Raspberry per scaricare le foto.

```php
if (($_GET['api_key'] ?? '') !== SCHOOLFACEID_API_KEY) { http_response_code(401); exit; }
header('Content-Type: application/json');

$studenti = $pdo->query("SELECT id, nome, cognome, foto_path FROM utenti WHERE ruolo='studente' AND attivo=1")->fetchAll();
echo json_encode($studenti);
```

---

## 7. Area docenti (pages/)

### `pages/login.php`

Login con CSRF, bcrypt, session regeneration.

```php
session_start();
if (isset($_SESSION['utente_id'])) { header('Location: dashboard.php'); exit; }

// CSRF token: generato alla prima richiesta, rigenerato dopo POST
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errore = 'Richiesta non valida. Ricarica la pagina.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM utenti WHERE email=? AND ruolo IN ('professore','admin') AND attivo=1");
        $stmt->execute([$email]);
        $utente = $stmt->fetch();
        if ($utente && password_verify($password, $utente['password_hash'])) {
            session_regenerate_id(true);                       // prevenzione session fixation
            $_SESSION['utente_id']   = $utente['id'];
            $_SESSION['utente_nome'] = $utente['nome'].' '.$utente['cognome'];
            $_SESSION['ruolo']       = $utente['ruolo'];
            header('Location: dashboard.php'); exit;
        } else {
            $errore = 'Credenziali non valide. Riprova.';
        }
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));      // ruota token
}
```

### `pages/dashboard.php` — pagina 1

Mostra le presenze di oggi in tempo reale. Componenti:

- **Stat cards**: 4 contatori (Presenti, Assenti, Ritardi, Uscite anticipate) — filtrabili per classe
- **Filtro classe**: dropdown che ricarica i contatori e la lista
- **Grid studenti**: ogni card mostra stato corrente, click → profilo
- **SSE**: connessione persistente per aggiornamenti real-time
- **Polling AJAX**: refresh stats ogni 15 secondi (fallback se SSE fallisce)
- **Notifiche flottanti**: appaiono in alto a destra a ogni nuovo evento

```php
// Calcoli backend
$totale = (int)$pdo->query(...)->fetchColumn();
$stmt = $pdo->prepare("
    SELECT SUM(p.stato='presente') AS presenti, SUM(p.stato='ritardo') AS ritardi,
           SUM(p.stato='uscita_anticipata') AS uscite
    FROM presenze p INNER JOIN utenti u ON u.id=p.studente_id
    WHERE p.data=:data $filtro_sql
");
$stmt->execute(...);
$assenti = max(0, $totale - $presenti - $ritardi - $uscite);

// Card studenti con stato corrente
$stmt = $pdo->prepare("
    SELECT u.id, u.nome, u.cognome, u.foto_path, COALESCE(p.stato,'assente') AS stato
    FROM utenti u
    LEFT JOIN presenze p ON p.studente_id=u.id AND p.data=:data
    WHERE u.ruolo='studente' AND u.attivo=1 $filtro_sql
    ORDER BY u.cognome, u.nome
");
```

**HTML card studente**:

```html
<a class="studente-card stato-<?= $stato ?>" href="studente_profilo.php?id=<?= $s['id'] ?>">
    <div class="avatar">...</div>
    <div class="studente-nome"><?= htmlspecialchars($s['nome'].' '.$s['cognome']) ?></div>
    <span class="badge badge-<?= $stato ?>"><?= str_replace('_',' ',$stato) ?></span>
</a>
```

**JavaScript real-time**:

```javascript
const classeFiltro = new URLSearchParams(window.location.search).get('classe') || '';

function aggiornaDashboard() {
    fetch('../api/stats.php' + (classeFiltro ? '?classe=' + encodeURIComponent(classeFiltro) : ''))
        .then(r => r.json())
        .then(data => {
            aggiornaValore('stat-presenti', data.presenti);
            data.studenti.forEach(s => {
                const card = document.getElementById('studente-' + s.id);
                const badge = card?.querySelector('.badge');
                if (badge && !badge.classList.contains('badge-'+s.stato)) {
                    // ...aggiorna badge classe + animazione
                }
            });
        });
}
setInterval(aggiornaDashboard, 15000);
```

### `pages/registro.php` — pagina 2

Mostra il registro giornaliero per una data specifica. Filtri: data, classe, stato, nome/cognome.

```php
$data_sel = $_GET['data'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_sel)) $data_sel = date('Y-m-d');

$where  = ["p.data = :data"];
$params = [':data' => $data_sel];

if ($classe_sel) { $where[] = "u.classe_id=:classe_id"; $params[':classe_id'] = $classe_sel; }
if ($stato_sel === 'assente') $where[] = "(p.stato='assente' OR p.id IS NULL)";
elseif ($stato_sel)            { $where[] = "p.stato=:stato"; $params[':stato'] = $stato_sel; }
if ($cerca_sel) {
    $where[] = "(LOWER(u.nome) LIKE :cerca_n OR LOWER(u.cognome) LIKE :cerca_c)";
    $cerca_lower = '%'.mb_strtolower($cerca_sel,'UTF-8').'%';
    $params[':cerca_n'] = $cerca_lower;
    $params[':cerca_c'] = $cerca_lower;
}

// LEFT JOIN per includere studenti senza record (= assenti)
$stmt = $pdo->prepare("
    SELECT u.id, u.nome, u.cognome, u.foto_path, c.nome AS classe,
           p.stato, p.ora_entrata, p.ora_uscita, p.rilevato_da, m.nome AS materia
    FROM utenti u
    LEFT JOIN presenze p ON p.studente_id=u.id AND p.data=:data2
    LEFT JOIN classi c   ON c.id=u.classe_id
    LEFT JOIN orario o   ON o.id=p.orario_id
    LEFT JOIN materie m  ON m.id=o.materia_id
    WHERE u.ruolo='studente' AND u.attivo=1 AND ".implode(' AND ', $where)."
    ORDER BY c.nome, u.cognome, u.nome
");
$params[':data2'] = $data_sel;
$stmt->execute($params);
```

**Bug PDO da ricordare**: `:data` e `:data2` sono due placeholder distinti perché PDO emulate=false richiede chiavi uniche.

Bottone "Modifica" per ogni riga → `modifica_presenza.php?id=X&data=YYYY-MM-DD`.

### `pages/studenti.php` — pagina 3

Dashboard statistica con grafici. Filtri: cerca, classe, periodo (7/30/90/365 giorni / tutti).

```php
$periodi = [
    '7'   => ['Ultimi 7 giorni',   7],
    '30'  => ['Ultimi 30 giorni',  30],
    '90'  => ['Ultimi 3 mesi',     90],
    '365' => ['Anno scolastico',   365],
    'all' => ['Tutti i giorni',    9999],
];
$periodo_giorni = $periodi[$periodo_sel][1];
$data_inizio    = date('Y-m-d', strtotime("-{$periodo_giorni} days"));

// Query aggregata per studente nel periodo
// Trucco: filtro data nel JOIN (placeholder usato una sola volta)
$stmt = $pdo->prepare("
    SELECT u.*, c.nome AS classe_nome,
           COALESCE(SUM(p.stato='presente'),          0) AS p_presente,
           COALESCE(SUM(p.stato='assente'),           0) AS p_assente,
           COALESCE(SUM(p.stato='ritardo'),           0) AS p_ritardo,
           COALESCE(SUM(p.stato='uscita_anticipata'), 0) AS p_uscita
    FROM utenti u
    LEFT JOIN classi c   ON c.id=u.classe_id
    LEFT JOIN presenze p ON p.studente_id=u.id AND p.data >= :d_inizio
    WHERE $where_sql
    GROUP BY u.id
    ORDER BY u.cognome, u.nome
");

// Trend giornaliero per il grafico
$stmt = $pdo->prepare("
    SELECT data, stato, COUNT(*) AS tot
    FROM presenze
    WHERE studente_id IN ($placeholders) AND data >= ?
    GROUP BY data, stato
    ORDER BY data ASC
");
```

**Componenti UI**:

- **5 stat card** aggregate (% media + 4 stati)
- **Doughnut chart**: distribuzione stati
- **Line chart**: trend % presenze (solo giorni di scuola, no buchi)
- **Top 5 assenze + Top 5 ritardi**: link diretti al profilo
- **Grid studenti**: ognuna mostra % presenze del periodo con barra colorata

**Chart.js setup**:

```javascript
Chart.defaults.color = '#6b7fa3';
Chart.defaults.borderColor = 'rgba(255,255,255,0.06)';
Chart.defaults.font.family = 'Sora, sans-serif';

// Doughnut
new Chart(document.getElementById('chart-stati'), {
    type: 'doughnut',
    data: { labels: ['Presenze','Assenze','Ritardi','Uscite anticipate'],
            datasets: [{ data: [<?=$tot_presenti?>, ...], backgroundColor: ['#22c55e','#ef4444','#f97316','#eab308'] }] },
    options: { cutout:'65%', plugins:{legend:{position:'bottom'}} }
});

// Line trend (solo giorni con dati)
new Chart(document.getElementById('chart-trend'), {
    type: 'line',
    data: { labels: <?= json_encode($serie_label) ?>,
            datasets: [{ label:'% Presenze', data: <?= json_encode($serie_perc) ?>, fill:true, tension:0.35 }] },
    options: { scales: { y: { min:0, max:100, ticks:{callback:v=>v+'%'} } } }
});
```

### `pages/studente_profilo.php`

Profilo di un singolo studente con statistiche complete. Accessibile sia da dashboard che da studenti.

Componenti:

- **Hero**: avatar + nome + classe + email + stato di oggi
- **4 stat card**: % presenze, presenze, assenze, ritardi
- **Settimana ultimi 7 giorni**: pallini colorati per stato
- **Filtri storico**: Tutti / Presenze / Assenze / Ritardi / Uscite anticipate (con conteggio)
- **Tabella storico**: filtrabile, tutti i giorni del periodo

```php
$stati_validi = ['tutti', 'presente', 'assente', 'ritardo', 'uscita_anticipata'];
$filtro = in_array($_GET['filtro'] ?? 'tutti', $stati_validi) ? $_GET['filtro'] : 'tutti';

if ($filtro === 'tutti') {
    $stmt = $pdo->prepare("SELECT * FROM presenze WHERE studente_id=? ORDER BY data DESC, id DESC");
    $stmt->execute([$sid]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM presenze WHERE studente_id=? AND stato=? ORDER BY data DESC, id DESC");
    $stmt->execute([$sid, $filtro]);
}
```

### `pages/modifica_presenza.php`

Form di modifica manuale con regole sui campi.

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stato = in_array($_POST['stato'], $stati_validi) ? $_POST['stato'] : 'assente';
    $ora_entrata = $_POST['ora_entrata'] ?: null;
    $ora_uscita  = $_POST['ora_uscita']  ?: null;

    // Validazione server-side delle regole
    switch ($stato) {
        case 'presente':           $ora_uscita = null; break;
        case 'assente':            $ora_entrata = null; $ora_uscita = null; break;
        case 'ritardo':            $ora_uscita = null; break;
        case 'uscita_anticipata':
            if (!$ora_uscita) $errore = 'Per "uscita anticipata" è obbligatorio inserire l\'ora di uscita.';
            break;
    }

    if (!$errore) {
        if ($presenza) {
            // UPDATE
            $pdo->prepare("UPDATE presenze SET stato=?,ora_entrata=?,ora_uscita=?,note=?,rilevato_da='manuale' WHERE id=?")
                ->execute([$stato, $ora_entrata, $ora_uscita, $note, $presenza['id']]);
        } else {
            // INSERT nuovo record
            $pdo->prepare("INSERT INTO presenze (studente_id,data,ora_entrata,ora_uscita,stato,note,rilevato_da) VALUES (?,?,?,?,?,?, 'manuale')")
                ->execute([$studente_id, $data_sel, $ora_entrata, $ora_uscita, $stato, $note]);
        }
    }
}
```

**JS per le regole UI**:

```javascript
function applicaRegole() {
    const stato = document.querySelector('input[name="stato"]:checked')?.value;
    entrataIn.disabled = false; uscitaIn.disabled = false; uscitaIn.required = false;

    switch (stato) {
        case 'presente':         uscitaIn.disabled = true;  uscitaIn.value = ''; break;
        case 'assente':          entrataIn.disabled = true; entrataIn.value = ''; uscitaIn.disabled = true; uscitaIn.value = ''; break;
        case 'ritardo':          uscitaIn.disabled = true;  uscitaIn.value = ''; break;
        case 'uscita_anticipata': uscitaIn.required = true; gruppoUscita.classList.add('obbligatorio'); break;
    }
}
document.querySelectorAll('input[name="stato"]').forEach(el => el.addEventListener('change', applicaRegole));
applicaRegole(); // alla pagina aperta
```

### `pages/recupera_password.php` e `reset_password.php`

Flusso unificato per docenti + studenti + admin.

**Recupera**: l'utente inserisce email → se esiste in `utenti` con `attivo=1`, viene generato un token e inviata l'email. **Risposta sempre generica** ("Se l'email è registrata riceverai il link…") per non rivelare chi è registrato.

**Reset**: link contiene il token, validato via `valida_token_reset`. La nuova password sostituisce `password_hash`, e il token viene marcato `usato=1`.

---

## 8. Area studenti (studenti_area/)

Cartella separata per **isolare la sessione studente** da quella docente. Le chiavi di sessione sono diverse:

- Area docenti: `$_SESSION['utente_id']`, `$_SESSION['utente_nome']`, `$_SESSION['ruolo']`
- Area studenti: `$_SESSION['studente_id']`, `$_SESSION['studente_nome']`

### `studenti_area/dashboard.php`

Mostra le stesse statistiche di `pages/studente_profilo.php` ma **dello studente loggato**, **senza permessi di modifica**.

```php
session_start();
if (!isset($_SESSION['studente_id'])) { header('Location: login.php'); exit; }

$sid = $_SESSION['studente_id'];  // sicuro: viene da una sessione autenticata

// Stesse query di studente_profilo.php ma con $_SESSION['studente_id'] al posto di $_GET['id']
```

**SSE personalizzato**: lo studente riceve solo le sue notifiche.

```javascript
const MIO_ID = <?= (int)$sid ?>;
source.onmessage = (e) => {
    const d = JSON.parse(e.data);
    if (d.studente_id !== MIO_ID) return;  // ignora eventi di altri studenti
    if (d.azione !== 'entrata_registrata' && d.azione !== 'uscita_registrata') return;
    mostraNotifica(d);
    aggiornaUI(d);
};
```

---

## 9. Lato Raspberry Pi

### `~/registro_facce/riconoscimento.py` (struttura semplificata)

```python
import face_recognition, cv2, requests, time, os, json
from datetime import datetime

SERVER_URL     = "https://macarena.altervista.org/registro/api/presenza.php"
API_KEY        = "REDACTED_API_KEY"
SOGLIA_SECONDI = 30
STUDENTI_DIR   = os.path.expanduser("~/registro_facce/studenti/")

# 1. Carica encoding facciali al boot
encodings_studenti = {}     # { id: [encoding_array, ...] }
for cartella in os.listdir(STUDENTI_DIR):
    if not cartella[0].isdigit(): continue
    studente_id = int(cartella.split('_')[0])
    for foto in os.listdir(os.path.join(STUDENTI_DIR, cartella)):
        img = face_recognition.load_image_file(os.path.join(STUDENTI_DIR, cartella, foto))
        encs = face_recognition.face_encodings(img)
        if encs:
            encodings_studenti.setdefault(studente_id, []).append(encs[0])

# 2. Apri webcam
cap = cv2.VideoCapture(0)
ultime_rilevazioni = {}  # { id: timestamp_ultima_chiamata }

while True:
    ret, frame = cap.read()
    if not ret: continue

    # Riduci risoluzione per velocizzare
    small_frame = cv2.resize(frame, (0,0), fx=0.5, fy=0.5)
    rgb = cv2.cvtColor(small_frame, cv2.COLOR_BGR2RGB)

    # 3. Trova volti e calcola encoding
    locations = face_recognition.face_locations(rgb)
    encodings = face_recognition.face_encodings(rgb, locations)

    for face_enc in encodings:
        # 4. Confronta con tutti gli encoding conosciuti
        miglior_match = None
        miglior_dist = 1.0
        for sid, sids_encs in encodings_studenti.items():
            distances = face_recognition.face_distance(sids_encs, face_enc)
            min_dist = min(distances)
            if min_dist < miglior_dist:
                miglior_dist = min_dist
                miglior_match = sid

        if miglior_match and miglior_dist < 0.6:
            # 5. Soglia anti-doppione (30 secondi)
            now = time.time()
            if now - ultime_rilevazioni.get(miglior_match, 0) < SOGLIA_SECONDI:
                continue
            ultime_rilevazioni[miglior_match] = now

            # 6. POST al server
            try:
                requests.post(SERVER_URL, json={
                    "studente_id": miglior_match,
                    "confidenza":  1 - miglior_dist,
                    "esito":       "riconosciuto",
                    "timestamp":   datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
                    "api_key":     API_KEY,
                }, timeout=5)
            except Exception as e:
                print("Errore POST:", e)
```

### systemd unit

```ini
# /etc/systemd/system/schoolfaceid.service
[Unit]
Description=SchoolFaceID Recognition
After=network.target

[Service]
Type=simple
User=macarena
WorkingDirectory=/home/macarena/registro_facce
ExecStart=/home/macarena/registro_env/bin/python3 riconoscimento.py
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

### `carica_studenti.py` — sincronizzazione foto

```python
import requests, os, shutil
API_URL = "https://macarena.altervista.org/registro/api/studenti.php?api_key=REDACTED_API_KEY"
STUDENTI_DIR = os.path.expanduser("~/registro_facce/studenti/")

studenti = requests.get(API_URL).json()
for s in studenti:
    cartella = f"{s['id']}_{s['nome'].lower()}_{s['cognome'].lower()}"
    dest = os.path.join(STUDENTI_DIR, cartella)
    os.makedirs(dest, exist_ok=True)

    url_foto = f"https://macarena.altervista.org/registro/{s['foto_path']}"
    r = requests.get(url_foto)
    if r.status_code == 200:
        with open(os.path.join(dest, '1.png'), 'wb') as f:
            f.write(r.content)
```

---

## 10. Sicurezza

### Cosa è protetto

| Controllo | Dove |
|-----------|------|
| **Bcrypt password** | `password_hash` / `password_verify` in login.php (entrambi) |
| **CSRF** | login docenti + login studenti + recupero password |
| **Session fixation** | `session_regenerate_id(true)` dopo login |
| **SQL injection** | Solo prepared statements, niente concatenazione di variabili |
| **XSS** | `htmlspecialchars()` su tutto l'output user-controlled |
| **Directory traversal foto** | `foto_path_sicuro()` con regex restrittiva |
| **Validazione date** | `preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_sel)` |
| **API key Raspberry** | Confronto con `SCHOOLFACEID_API_KEY` |
| **scripts/ inaccessibile** | `.htaccess: Require all denied` |
| **Token reset password** | 32 byte random + scadenza 30 min + flag `usato` |
| **Sessioni separate** | Chiavi diverse per docenti/studenti |
| **Row-level security** | Studenti vedono solo i propri dati (`WHERE studente_id = $_SESSION['studente_id']`) |

### Limiti noti

- Nessun CSRF in `modifica_presenza.php` (form interno docenti, basso rischio)
- Password hash visibili nei dump SQL (rischio basso, sono bcrypt)
- API key in chiaro in `config.php` (compensato da .gitignore + permessi server)

---

## 11. Deploy e operazioni

### Script `deploy.sh`

```bash
#!/bin/bash
FTP_HOST="macarena.altervista.org"
FTP_USER="macarena"
FTP_PASS="..."
REMOTE_BASE="registro"

upload() {
    local file="$1"
    local remote_path="${REMOTE_BASE}/$(dirname "$file")"
    curl -s --ftp-create-dirs -T "${LOCAL_BASE}/${file}" \
        "ftp://${FTP_HOST}/${remote_path}/" --user "${FTP_USER}:${FTP_PASS}"
}

if [ $# -eq 0 ]; then
    # Senza argomenti: carica file modificati rispetto all'ultimo commit git
    FILES=$(git diff --name-only HEAD)
    for f in $FILES; do upload "$f"; done
else
    for f in "$@"; do upload "$f"; done
fi
```

**Uso**:

```bash
./deploy.sh                                  # tutti i modificati da git
./deploy.sh pages/dashboard.php api/stats.php # file specifici
```

### Eliminare file remoti via FTP

```bash
curl --user "macarena:PASS" "ftp://macarena.altervista.org/" -Q "DELE registro/path/file.php"
```

### Backup database

Da phpMyAdmin → Esporta → SQL → Tutte le tabelle → Scarica.

### Permessi cartelle

| Cartella | Permessi |
|----------|----------|
| `cache/` | 777 (PHP deve scrivere `ultimo_evento.json` e `reset_log.txt`) |
| `uploads/` | 755 |
| `scripts/` | 755 + `.htaccess deny` |
| Tutto il resto | 644 file, 755 dir |

---

## 12. Workflow comuni

### A. Aggiungere uno studente

```sql
INSERT INTO utenti (nome, cognome, email, password_hash, ruolo, classe_id, foto_path, attivo)
VALUES ('Mario', 'Rossi', 'mario.rossi@studenti.iispascal.it',
        '$2y$10$...',  -- hash di password_hash('PasswordIniziale')
        'studente', 1, 'uploads/studenti/N_mario_rossi/1.png', 1);
```

1. INSERT in `utenti` (recupera l'ID generato)
2. Crea cartella `uploads/studenti/{id}_{nome}_{cognome}/` e carica `1.png`
3. SSH Raspberry → `python3 carica_studenti.py && sudo systemctl restart schoolfaceid`

### B. Modificare manualmente una presenza

1. Login docente → Registro → seleziona data
2. Click "Modifica" sulla riga dello studente
3. Cambia stato (i campi orari si abilitano/disabilitano in base alla regola)
4. Salva — il rilevato_da diventa `'manuale'`

### C. Reset password utente

**Via form**: la persona va su `recupera_password.php`, inserisce email, riceve link via mail.

**Manualmente** (admin via phpMyAdmin):

```sql
UPDATE utenti SET password_hash = '$2y$10$NUOVO_HASH' WHERE email = 'utente@scuola.it';
```

Per generare il hash:

```php
<?php echo password_hash('NuovaPassword', PASSWORD_DEFAULT); ?>
```

### D. Disattivare uno studente (rimosso o trasferito)

```sql
UPDATE utenti SET attivo = 0 WHERE id = N;
```

Non viene cancellato (mantiene lo storico presenze), ma sparisce da dashboard, registro, Raspberry sync.

### E. Cambiare la soglia di ritardo

Modifica `api/presenza.php`:

```php
$ora_inizio_lezioni = '08:00:00';   // ora di inizio
$minuti_ritardo     = 15;            // tolleranza
```

---

## 13. Troubleshooting

### HTTP 500 generico

1. Apri `cache/reset_log.txt` o aggiungi `error_reporting(E_ALL); ini_set('display_errors',1);` temporaneamente
2. Controlla che `vendor/autoload.php` non sia richiesto da nessuna parte (è stato rimosso)
3. Cause comuni: placeholder PDO ripetuti, query con tabelle mancanti, sintassi `===` con cast errato

### Mail di reset non arriva

1. Controlla cartella spam Gmail
2. Verifica che `$inviato` sia `yes` in `cache/reset_log.txt`
3. Il From è `noreply@macarena.altervista.org` (non Gmail), altrimenti SPF fail

### Raspberry non comunica col server

```bash
# Sul Raspberry
sudo systemctl status schoolfaceid.service
sudo journalctl -u schoolfaceid.service -f
```

Cause comuni:
- API key non aggiornata in `riconoscimento.py`
- URL server vecchio (verifica `SERVER_URL` punti ad Altervista)
- Cartelle studenti mancanti o nominate male (formato `{id}_{nome}_{cognome}/`)

### Dashboard non aggiorna in tempo reale

1. Controlla console browser per errori SSE
2. Verifica permessi `cache/` (deve essere 777)
3. Verifica che `cache/ultimo_evento.json` venga aggiornato a ogni POST

### Conteggi non tornano

`assenti = totale - presenti - ritardi - uscite_anticipate` — se ti sembra mancare qualcuno, controlla:
- Studenti con `attivo=0` (esclusi)
- Filtro classe attivo
- Records con stato fuori dall'enum (improbabile ma controllabile con `SELECT DISTINCT stato FROM presenze`)

---

## Appendice — File index

```
htdocs/registro/
├── MANUALE.md                      — questo file
├── deploy.sh                       — script FTP (gitignored)
├── index.php                       — redirect a login
├── composer.json
│
├── pages/                          # Area docenti
│   ├── login.php                   — login con CSRF/bcrypt
│   ├── logout.php
│   ├── dashboard.php               — pagina 1, real-time
│   ├── registro.php                — pagina 2, registro giornaliero
│   ├── studenti.php                — pagina 3, statistiche aggregate
│   ├── studente_profilo.php        — profilo singolo studente
│   ├── modifica_presenza.php       — modifica manuale con regole
│   ├── recupera_password.php
│   └── reset_password.php
│
├── studenti_area/                  # Area studenti (sessione separata)
│   ├── login.php
│   ├── logout.php
│   ├── dashboard.php               — vista personale sola lettura
│   ├── recupera_password.php
│   └── reset_password.php
│
├── api/
│   ├── presenza.php                — POST dal Raspberry
│   ├── studenti.php                — sync foto Raspberry
│   ├── stats.php                   — refresh dashboard JSON
│   └── eventi.php                  — stream SSE
│
├── includes/
│   ├── config.php                  — costanti progetto
│   ├── db.php                      — PDO + helper
│   └── mailer.php                  — mail() nativa
│
├── assets/
│   ├── logo.svg                    — logo completo
│   └── icon.svg                    — icona compatta navbar
│
├── uploads/studenti/{id}_{...}/1.png
├── cache/ultimo_evento.json
└── scripts/                        — .htaccess: deny all
    ├── hash.php
    └── database_export.sql
```

---

*Fine manuale.*
