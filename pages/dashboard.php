<?php
session_start();
if (!isset($_SESSION['utente_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../includes/db.php';
require_once '../includes/impostazioni.php';

// Salvataggio impostazioni orario
$msg_orario = '';
$err_orario = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salva_orario'])) {
    $nuovo_orario  = $_POST['ora_inizio_lezioni'] ?? '';
    $nuovo_ritardo = (int)($_POST['minuti_ritardo'] ?? 0);
    if (preg_match('/^\d{2}:\d{2}$/', $nuovo_orario) && $nuovo_ritardo >= 0 && $nuovo_ritardo <= 120) {
        set_impostazioni([
            'ora_inizio_lezioni' => $nuovo_orario . ':00',
            'minuti_ritardo'     => $nuovo_ritardo,
        ]);
        $msg_orario = 'Orario aggiornato.';
    } else {
        $err_orario = 'Valori non validi.';
    }
}
$imp = get_impostazioni();

$oggi = date('Y-m-d');

// Lista classi per filtro
$classi = $pdo->query("SELECT * FROM classi ORDER BY nome")->fetchAll();

// Filtro classe
$classe_sel = isset($_GET['classe']) && $_GET['classe'] !== '' ? (int)$_GET['classe'] : null;
$filtro_sql = $classe_sel ? "AND u.classe_id = :cid" : '';

// Totale studenti (filtrato per classe se selezionata)
$qt = "SELECT COUNT(*) FROM utenti u WHERE u.ruolo='studente' AND u.attivo=1 " . ($classe_sel ? "AND u.classe_id = :cid" : '');
$stmt = $pdo->prepare($qt);
if ($classe_sel) $stmt->bindValue(':cid', $classe_sel, PDO::PARAM_INT);
$stmt->execute();
$totale = (int)$stmt->fetchColumn();

// Counter giornalieri (filtrati per classe se selezionata)
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
$counts   = $stmt->fetch();
$presenti = (int)$counts['presenti'];
$ritardi  = (int)$counts['ritardi'];
$uscite   = (int)$counts['uscite'];
$assenti  = max(0, $totale - $presenti - $ritardi - $uscite);

// Lista studenti (filtrata per classe se selezionata)
$stmt = $pdo->prepare("
    SELECT u.id, u.nome, u.cognome, u.foto_path, c.nome as classe_nome,
           COALESCE(p.stato, 'assente') as stato
    FROM utenti u
    LEFT JOIN presenze p ON p.studente_id = u.id AND p.data = :data
    LEFT JOIN classi c ON c.id = u.classe_id
    WHERE u.ruolo = 'studente' AND u.attivo = 1 $filtro_sql
    ORDER BY u.cognome, u.nome
");
$stmt->bindValue(':data', $oggi);
if ($classe_sel) $stmt->bindValue(':cid', $classe_sel, PDO::PARAM_INT);
$stmt->execute();
$studenti = $stmt->fetchAll();

$cache_file = __DIR__ . '/../cache/ultimo_evento.json';
$raspberry_online = false;
if (file_exists($cache_file)) {
    $raw = file_get_contents($cache_file);
    $ev  = $raw ? json_decode($raw, true) : null;
    $raspberry_online = is_array($ev) && isset($ev['timestamp']) && (time() - $ev['timestamp']) < 7200;
}

$riconoscimenti = $pdo->query("
    SELECT u.nome, u.cognome, u.foto_path, l.timestamp
    FROM log_riconoscimenti l
    JOIN utenti u ON u.id = l.utente_id
    WHERE l.esito = 'riconosciuto'
    ORDER BY l.timestamp DESC
    LIMIT 8
")->fetchAll();

$perc_presenti = $totale > 0 ? round($presenti / $totale * 100) : 0;
$giorni_it = ['Dom','Lun','Mar','Mer','Gio','Ven','Sab'];
$mesi_it   = ['','Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
$data_fmt  = $giorni_it[date('w')] . ' ' . date('j') . ' ' . $mesi_it[(int)date('n')];
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>SchoolFaceID — Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg-deep:   #060c18;
      --bg-card:   #0d1726;
      --bg-card2:  #101e30;
      --bg-input:  #091220;
      --border:    rgba(255,255,255,0.06);
      --border-md: rgba(255,255,255,0.1);
      --blue:      #3b82f6;
      --blue-dim:  rgba(59,130,246,0.12);
      --green:     #22c55e;
      --green-dim: rgba(34,197,94,0.12);
      --red:       #ef4444;
      --red-dim:   rgba(239,68,68,0.12);
      --orange:    #f97316;
      --orange-dim:rgba(249,115,22,0.12);
      --purple:    #a855f7;
      --text-white:#eef4ff;
      --text-muted:#5d7a9e;
      --text-dim:  #2e4060;
      --radius:    18px;
      --radius-sm: 12px;
    }

    html, body { min-height: 100%; font-family: 'Sora', sans-serif; background: var(--bg-deep); color: var(--text-white); }

    body::before {
      content: ''; position: fixed; inset: 0; pointer-events: none; z-index: 0;
      background:
        radial-gradient(ellipse 80% 60% at 0% 0%,   rgba(37,99,235,0.13) 0%, transparent 55%),
        radial-gradient(ellipse 60% 80% at 100% 100%, rgba(139,92,246,0.07) 0%, transparent 55%);
    }
    body::after {
      content: ''; position: fixed; inset: 0; pointer-events: none; z-index: 0;
      background-image: radial-gradient(rgba(59,130,246,0.05) 1px, transparent 1px);
      background-size: 28px 28px;
    }

    /* ── NAVBAR ─────────────────────────────── */
    .navbar {
      position: sticky; top: 0; z-index: 100;
      display: flex; align-items: center; justify-content: space-between;
      padding: 0 40px; height: 62px;
      background: rgba(6,12,24,0.85);
      backdrop-filter: blur(20px) saturate(180%);
      border-bottom: 1px solid var(--border);
    }

    .nav-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }
    .nav-logo {
      width: 38px; height: 38px;
      display: flex; align-items: center; justify-content: center;
      filter: drop-shadow(0 0 12px rgba(59,130,246,0.4));
    }
    .nav-logo img { width: 100%; height: 100%; object-fit: contain; }
    .nav-title { font-size: 15px; font-weight: 700; letter-spacing: -0.02em; color: var(--text-white); }
    .nav-sub   { font-size: 11px; color: var(--text-muted); margin-top: 1px; }

    .nav-links { display: flex; gap: 2px; }
    .nav-link {
      padding: 6px 14px; border-radius: 8px;
      font-size: 13px; font-weight: 500;
      color: var(--text-muted); text-decoration: none; transition: all 0.18s;
    }
    .nav-link:hover { background: rgba(255,255,255,0.05); color: var(--text-white); }
    .nav-link.active {
      background: var(--blue-dim); color: var(--blue);
      box-shadow: inset 0 0 0 1px rgba(59,130,246,0.2);
    }

    .nav-right { display: flex; align-items: center; gap: 14px; }
    .nav-date {
      font-size: 12px; color: var(--text-muted);
      font-family: 'JetBrains Mono', monospace;
    }
    .nav-status {
      display: flex; align-items: center; gap: 6px;
      font-size: 12px; font-weight: 500;
      padding: 5px 12px; border-radius: 20px;
    }
    .nav-status.online  { background: var(--green-dim); color: var(--green); }
    .nav-status.offline { background: var(--red-dim);   color: var(--red); }
    .nav-status-dot { width: 7px; height: 7px; border-radius: 50%; }
    .online  .nav-status-dot { background: var(--green); animation: pulse 2s infinite; }
    .offline .nav-status-dot { background: var(--red); }
    @keyframes pulse { 0%,100%{opacity:1;} 50%{opacity:.3;} }

    .btn-logout {
      padding: 6px 16px;
      background: transparent; border: 1px solid var(--border);
      border-radius: 8px; color: var(--text-muted);
      font-family: 'Sora', sans-serif; font-size: 12px;
      cursor: pointer; text-decoration: none; transition: all 0.18s;
    }
    .btn-logout:hover { border-color: var(--border-md); color: var(--text-white); }

    /* ── MAIN ───────────────────────────────── */
    .main {
      position: relative; z-index: 1;
      max-width: 1240px; margin: 0 auto;
      padding: 36px 40px 60px;
    }

    @keyframes fadeUp {
      from { opacity:0; transform:translateY(14px); }
      to   { opacity:1; transform:translateY(0); }
    }

    /* ── PAGE HEADER ───────────────────────── */
    .page-header { margin-bottom: 32px; animation: fadeUp .45s ease both; }
    .page-header h1 {
      font-size: 30px; font-weight: 700;
      letter-spacing: -0.035em; margin-bottom: 6px;
    }
    .page-header p { font-size: 13px; color: var(--text-muted); line-height: 1.6; }

    /* ── STAT CARDS ─────────────────────────── */
    .stats {
      display: grid; grid-template-columns: repeat(4,1fr);
      gap: 14px; margin-bottom: 24px;
      animation: fadeUp .45s ease .05s both;
    }
    @media (max-width: 900px) { .stats { grid-template-columns: repeat(2,1fr); } }

    .stat-card {
      background: var(--bg-card); border: 1px solid var(--border);
      border-radius: var(--radius); padding: 22px 22px 18px;
      position: relative; overflow: hidden; transition: transform .2s;
    }
    .stat-card:hover { transform: translateY(-2px); }

    .stat-card.updated { animation: flash .4s ease; }
    @keyframes flash { 0%,100%{transform:scale(1);} 50%{transform:scale(1.03);} }

    .stat-icon {
      width: 36px; height: 36px; border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: 17px; margin-bottom: 14px;
    }
    .stat-card.blue   .stat-icon { background: var(--blue-dim); }
    .stat-card.green  .stat-icon { background: var(--green-dim); }
    .stat-card.red    .stat-icon { background: var(--red-dim); }
    .stat-card.orange .stat-icon { background: var(--orange-dim); }
    .stat-card.yellow .stat-icon { background: rgba(234,179,8,0.12); }

    .stat-label {
      font-size: 11px; text-transform: uppercase; letter-spacing: .08em;
      font-family: 'JetBrains Mono', monospace; margin-bottom: 6px;
    }
    .stat-card.blue   .stat-label { color: var(--blue); }
    .stat-card.green  .stat-label { color: var(--green); }
    .stat-card.red    .stat-label { color: var(--red); }
    .stat-card.orange .stat-label { color: var(--orange); }
    .stat-card.yellow .stat-label { color: #eab308; }

    .stat-value {
      font-size: 40px; font-weight: 700;
      letter-spacing: -0.04em; line-height: 1;
    }

    .stat-bar {
      height: 3px; border-radius: 2px;
      background: var(--border); margin-top: 14px; overflow: hidden;
    }
    .stat-bar-fill {
      height: 100%; border-radius: 2px;
      background: var(--green); transition: width 1s ease;
    }

    /* ── GRID MAIN ──────────────────────────── */
    .grid-main {
      display: grid; grid-template-columns: 1fr 360px;
      gap: 16px; animation: fadeUp .5s ease .1s both;
    }

    /* ── CARD ───────────────────────────────── */
    .card {
      background: var(--bg-card); border: 1px solid var(--border);
      border-radius: var(--radius); padding: 24px;
    }
    .card-header {
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: 20px;
    }
    .card-title { font-size: 16px; font-weight: 600; letter-spacing: -0.02em; }
    .card-badge {
      font-size: 11px; font-family: 'JetBrains Mono', monospace;
      color: var(--text-muted); background: var(--bg-card2);
      border: 1px solid var(--border); border-radius: 6px; padding: 3px 8px;
    }

    /* ── STUDENTI GRID ──────────────────────── */
    .studenti-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 12px; }

    .studente-card {
      background: var(--bg-card2); border: 1px solid var(--border);
      border-radius: var(--radius-sm); padding: 16px 12px 14px;
      text-align: center; position: relative; overflow: hidden;
      transition: border-color .25s, transform .2s, box-shadow .2s;
      cursor: pointer;
      text-decoration: none;
      color: inherit;
      display: block;
    }
    .studente-card:hover {
      border-color: rgba(59,130,246,0.4);
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(59,130,246,0.1);
    }

    .studente-card::after {
      content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
      border-radius: var(--radius-sm) var(--radius-sm) 0 0;
      transition: background .3s;
    }
    .stato-presente      .studente-card::after,
    .studente-card.stato-presente::after { background: var(--green); }
    .stato-assente       .studente-card::after,
    .studente-card.stato-assente::after  { background: var(--red); }
    .stato-ritardo       .studente-card::after,
    .studente-card.stato-ritardo::after  { background: var(--orange); }
    .stato-uscita_anticipata .studente-card::after,
    .studente-card.stato-uscita_anticipata::after { background: var(--blue); }

    .studente-card.aggiornato {
      border-color: var(--blue);
      box-shadow: 0 0 0 1px rgba(59,130,246,.2), 0 4px 20px rgba(59,130,246,.15);
      animation: cardPulse 1.2s ease forwards;
    }
    @keyframes cardPulse {
      0%   { box-shadow: 0 0 0 1px rgba(59,130,246,.3), 0 4px 20px rgba(59,130,246,.2); }
      100% { box-shadow: none; border-color: var(--border); }
    }

    .avatar {
      width: 52px; height: 52px;
      background: linear-gradient(135deg, #1e3a6e, #2563eb);
      border-radius: 50%; margin: 6px auto 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: 17px; font-weight: 700; color: #fff;
      overflow: hidden; border: 2px solid rgba(255,255,255,0.06);
    }
    .avatar img { width: 100%; height: 100%; object-fit: cover; }

    .studente-nome {
      font-size: 12px; font-weight: 600; color: var(--text-white);
      margin-bottom: 6px; line-height: 1.3;
    }

    .badge {
      display: inline-block; font-size: 10px; font-weight: 600;
      padding: 3px 9px; border-radius: 20px;
      font-family: 'JetBrains Mono', monospace; letter-spacing: .03em;
      transition: all .3s;
    }
    .badge-presente         { background: var(--green-dim);  color: var(--green); }
    .badge-assente          { background: var(--red-dim);    color: var(--red); }
    .badge-ritardo          { background: var(--orange-dim); color: var(--orange); }
    .badge-uscita_anticipata{ background: var(--blue-dim);   color: var(--blue); }

    /* ── RIGHT COL ──────────────────────────── */
    .right-col { display: flex; flex-direction: column; gap: 16px; }

    /* Riconoscimenti */
    .ricon-list { display: flex; flex-direction: column; gap: 6px; }
    .ricon-item {
      display: flex; align-items: center; gap: 12px;
      background: var(--bg-card2); border: 1px solid var(--border);
      border-radius: var(--radius-sm); padding: 10px 14px;
      transition: border-color .2s;
    }
    .ricon-item:hover { border-color: var(--border-md); }
    .ricon-item.nuovo { animation: nuovoRicon .8s ease forwards; }
    @keyframes nuovoRicon {
      from { border-color: var(--green); background: rgba(34,197,94,.06); }
      to   { border-color: var(--border); background: var(--bg-card2); }
    }
    .ricon-avatar {
      width: 32px; height: 32px; flex-shrink: 0;
      background: linear-gradient(135deg, #1e3a6e, #2563eb);
      border-radius: 50%; overflow: hidden;
      display: flex; align-items: center; justify-content: center;
      font-size: 11px; font-weight: 700; color: #fff;
    }
    .ricon-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .ricon-info { flex: 1; min-width: 0; }
    .ricon-nome { font-size: 12px; font-weight: 600; color: var(--text-white); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ricon-time {
      font-family: 'JetBrains Mono', monospace;
      font-size: 11px; color: var(--text-muted); flex-shrink: 0;
    }

    /* Azioni rapide */
    .azioni { display: flex; flex-direction: column; gap: 8px; }
    .btn-action {
      display: flex; align-items: center; gap: 12px;
      padding: 13px 16px; border-radius: var(--radius-sm);
      text-decoration: none; font-size: 13px; font-weight: 600;
      transition: all .18s; border: 1px solid transparent;
    }
    .btn-action-icon {
      width: 32px; height: 32px; border-radius: 9px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center; font-size: 15px;
    }
    .btn-action.primary {
      background: var(--blue); color: #fff;
      box-shadow: 0 4px 16px rgba(59,130,246,.25);
    }
    .btn-action.primary:hover { background: #2563eb; box-shadow: 0 6px 24px rgba(59,130,246,.4); }
    .btn-action.primary .btn-action-icon { background: rgba(255,255,255,.15); }
    .btn-action.secondary {
      background: var(--bg-card2); color: var(--text-muted);
      border-color: var(--border);
    }
    .btn-action.secondary:hover { border-color: var(--border-md); color: var(--text-white); }
    .btn-action.secondary .btn-action-icon { background: var(--blue-dim); }

    @keyframes slideIn {
      from { opacity:0; transform:translateX(16px); }
      to   { opacity:1; transform:translateX(0); }
    }

    /* Form orario */
    .orario-form { display: flex; flex-direction: column; gap: 14px; }
    .orario-field { display: flex; flex-direction: column; gap: 6px; }
    .orario-field label {
      font-size: 11px; font-family: 'JetBrains Mono', monospace;
      letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-dim);
    }
    .orario-field input {
      background: var(--bg-card2); border: 1px solid var(--border);
      border-radius: 9px; padding: 10px 14px;
      font-family: 'JetBrains Mono', monospace; font-size: 14px;
      color: var(--text-white); outline: none;
      transition: border-color 0.2s; color-scheme: dark;
    }
    .orario-field input:focus { border-color: rgba(59,130,246,0.5); box-shadow: 0 0 0 3px rgba(59,130,246,0.08); }
    .orario-info { font-size: 11px; color: var(--text-dim); font-family: 'JetBrains Mono', monospace; }
    .orario-submit {
      padding: 10px 14px; background: var(--blue); color: #fff;
      border: none; border-radius: 9px; cursor: pointer;
      font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 600;
      transition: background 0.2s; box-shadow: 0 4px 14px rgba(59,130,246,0.25);
    }
    .orario-submit:hover { background: #2563eb; }
    .orario-msg.ok { color: var(--green); font-size: 12px; }
    .orario-msg.err { color: var(--red); font-size: 12px; }
  </style>
</head>
<body>

<nav class="navbar">
  <a class="nav-brand" href="dashboard.php">
    <div class="nav-logo"><img src="../assets/icon.svg" alt="SchoolFaceID"></div>
    <div>
      <div class="nav-title">SchoolFaceID</div>
      <div class="nav-sub"><?= htmlspecialchars($_SESSION['utente_nome']) ?></div>
    </div>
  </a>

  <div class="nav-links">
    <a href="dashboard.php" class="nav-link active">Dashboard</a>
    <a href="registro.php"  class="nav-link">Registro</a>
    <a href="studenti.php"  class="nav-link">Studenti</a>
  </div>

  <div class="nav-right">
    <span class="nav-date"><?= $data_fmt ?></span>
    <div id="nav-status" class="nav-status <?= $raspberry_online ? 'online' : 'offline' ?>">
      <div class="nav-status-dot"></div>
      <span id="nav-status-text"><?= $raspberry_online ? 'Sistema attivo' : 'Offline' ?></span>
    </div>
    <a href="logout.php" class="btn-logout">Logout</a>
  </div>
</nav>

<main class="main">

  <div class="page-header">
    <h1>Presenze di oggi</h1>
    <p>Monitoraggio in tempo reale</p>
  </div>

  <div class="stats">
    <div class="stat-card green">
      <div class="stat-icon">✓</div>
      <div class="stat-label">Presenti</div>
      <div class="stat-value" id="stat-presenti"><?= $presenti ?></div>
      <div class="stat-bar"><div class="stat-bar-fill" id="stat-bar-presenti" style="width:<?= $perc_presenti ?>%"></div></div>
    </div>
    <div class="stat-card red">
      <div class="stat-icon">✗</div>
      <div class="stat-label">Assenti</div>
      <div class="stat-value" id="stat-assenti"><?= $assenti ?></div>
    </div>
    <div class="stat-card orange">
      <div class="stat-icon">⏱</div>
      <div class="stat-label">In ritardo</div>
      <div class="stat-value" id="stat-ritardi"><?= $ritardi ?></div>
    </div>
    <div class="stat-card yellow">
      <div class="stat-icon">🚪</div>
      <div class="stat-label">Uscite anticipate</div>
      <div class="stat-value" id="stat-uscite"><?= $uscite ?></div>
    </div>
  </div>

  <div class="grid-main">

    <div class="card">
      <div class="card-header">
        <div class="card-title">Studenti registrati</div>
        <div style="display:flex; align-items:center; gap:12px;">
          <form method="GET" action="" style="display:flex; align-items:center; gap:8px;">
            <label for="classe-select" style="font-size:11px; font-family:'JetBrains Mono',monospace; letter-spacing:0.08em; text-transform:uppercase; color:var(--text-dim);">Classe:</label>
            <select name="classe" id="classe-select" onchange="this.form.submit()"
                    style="background:var(--bg-card2); border:1px solid var(--border); border-radius:8px; padding:6px 12px; font-family:'Sora',sans-serif; font-size:13px; color:var(--text-white); outline:none; cursor:pointer;">
              <option value="">Tutte</option>
              <?php foreach ($classi as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $classe_sel == $c['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($c['nome']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </form>
          <div class="card-badge"><?= count($studenti) ?> totale</div>
        </div>
      </div>
      <div class="studenti-grid" id="studenti-grid">
        <?php foreach ($studenti as $s):
          $iniziali = strtoupper(substr($s['nome'],0,1).substr($s['cognome'],0,1));
          $stato    = $s['stato'];
          $fp       = foto_path_sicuro($s['foto_path']);
          $ft       = ($fp && file_exists(dirname(__DIR__).'/'.$fp)) ? filemtime(dirname(__DIR__).'/'.$fp) : 0;
        ?>
          <a class="studente-card stato-<?= $stato ?>" id="studente-<?= $s['id'] ?>"
             href="studente_profilo.php?id=<?= $s['id'] ?>"
             title="Vedi profilo di <?= htmlspecialchars($s['nome'].' '.$s['cognome']) ?>">
            <div class="avatar">
              <?php if ($fp): ?>
                <img src="../<?= htmlspecialchars($fp) ?><?= $ft ? '?v='.$ft : '' ?>" alt="">
              <?php else: ?>
                <?= $iniziali ?>
              <?php endif; ?>
            </div>
            <div class="studente-nome"><?= htmlspecialchars($s['nome'].' '.$s['cognome']) ?></div>
            <span class="badge badge-<?= $stato ?>"><?= str_replace('_',' ',$stato) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="right-col">

      <div class="card">
        <div class="card-header">
          <div class="card-title">Riconoscimenti recenti</div>
          <div class="card-badge">oggi</div>
        </div>
        <div class="ricon-list" id="ricon-list">
          <?php if (empty($riconoscimenti)): ?>
            <div style="color:var(--text-muted);font-size:13px;padding:8px 0;">Nessun riconoscimento.</div>
          <?php else: ?>
            <?php foreach ($riconoscimenti as $r):
              $fp2 = foto_path_sicuro($r['foto_path']);
              $ini = strtoupper(substr($r['nome'],0,1).substr($r['cognome'],0,1));
            ?>
              <div class="ricon-item">
                <div class="ricon-avatar">
                  <?php if ($fp2): ?>
                    <img src="../<?= htmlspecialchars($fp2) ?>" alt="">
                  <?php else: ?><?= $ini ?><?php endif; ?>
                </div>
                <div class="ricon-info">
                  <div class="ricon-nome"><?= htmlspecialchars($r['nome'].' '.$r['cognome']) ?></div>
                </div>
                <div class="ricon-time"><?= date('H:i', strtotime($r['timestamp'])) ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><div class="card-title">Azioni rapide</div></div>
        <div class="azioni">
          <a href="registro.php" class="btn-action primary">
            <div class="btn-action-icon">📋</div>
            Apri registro
          </a>
          <a href="studenti.php" class="btn-action secondary">
            <div class="btn-action-icon">👤</div>
            Gestisci studenti
          </a>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title">Orario lezioni</div>
        </div>
        <form method="POST" action="" class="orario-form">
          <div class="orario-field">
            <label for="ora_inizio_lezioni">Inizio lezioni</label>
            <input type="time" id="ora_inizio_lezioni" name="ora_inizio_lezioni"
                   value="<?= htmlspecialchars(substr($imp['ora_inizio_lezioni'], 0, 5)) ?>" required>
          </div>
          <div class="orario-field">
            <label for="minuti_ritardo">Tolleranza ritardo (minuti)</label>
            <input type="number" id="minuti_ritardo" name="minuti_ritardo"
                   value="<?= (int)$imp['minuti_ritardo'] ?>" min="0" max="120" required>
          </div>
          <div class="orario-info">
            Soglia ritardo attuale: <strong style="color:var(--text-white)"><?= date('H:i', strtotime($imp['ora_inizio_lezioni']) + (int)$imp['minuti_ritardo'] * 60) ?></strong>
          </div>
          <button type="submit" name="salva_orario" value="1" class="orario-submit">Salva orario</button>
          <?php if ($msg_orario): ?><div class="orario-msg ok">✓ <?= htmlspecialchars($msg_orario) ?></div><?php endif; ?>
          <?php if ($err_orario): ?><div class="orario-msg err">✗ <?= htmlspecialchars($err_orario) ?></div><?php endif; ?>
        </form>
      </div>

    </div>
  </div>

</main>

<script>
  // Inizializza a "adesso" così le notifiche appaiono solo per eventi DOPO il page load,
  // non per quello in cache che è già stato visto (es. al refresh della pagina).
  let ultimoTs = Math.floor(Date.now() / 1000);

  const classeFiltro = new URLSearchParams(window.location.search).get('classe') || '';

  function aggiornaDashboard() {
    fetch('../api/stats.php' + (classeFiltro ? '?classe=' + encodeURIComponent(classeFiltro) : ''))
      .then(r => r.json())
      .then(data => {
        aggiornaValore('stat-presenti', data.presenti);
        aggiornaValore('stat-assenti',  data.assenti);
        aggiornaValore('stat-ritardi',  data.ritardi);
        aggiornaValore('stat-uscite',   data.uscite);

        const bar = document.getElementById('stat-bar-presenti');
        if (bar && data.totale > 0) bar.style.width = Math.round(data.presenti / data.totale * 100) + '%';

        data.studenti.forEach(s => {
          const card  = document.getElementById('studente-' + s.id);
          if (!card) return;
          const badge = card.querySelector('.badge');
          if (!badge) return;
          const vecchio = [...badge.classList].find(c => c.startsWith('badge-') && c !== 'badge');
          const nuovo   = 'badge-' + s.stato;
          if (vecchio !== nuovo) {
            badge.classList.remove(vecchio);
            badge.classList.add(nuovo);
            badge.textContent = s.stato.replace('_',' ');
            card.className = card.className.replace(/stato-\S+/, '') + ' stato-' + s.stato;
            card.classList.add('aggiornato');
            setTimeout(() => card.classList.remove('aggiornato'), 1200);
          }
        });

        if (data.riconoscimenti && data.riconoscimenti.length > 0) {
          const lista = document.getElementById('ricon-list');
          lista.innerHTML = data.riconoscimenti.map((r, i) => `
            <div class="ricon-item ${i === 0 ? 'nuovo' : ''}">
              <div class="ricon-avatar">${r.nome[0]}${r.cognome[0]}</div>
              <div class="ricon-info"><div class="ricon-nome">${r.nome} ${r.cognome}</div></div>
              <div class="ricon-time">${r.timestamp.substring(11,16)}</div>
            </div>
          `).join('');
        }
      })
      .catch(() => {});
  }

  function aggiornaValore(id, nuovoVal) {
    const el = document.getElementById(id);
    if (!el || parseInt(el.textContent) === nuovoVal) return;
    el.textContent = nuovoVal;
    const card = el.closest('.stat-card');
    if (card) { card.classList.add('updated'); setTimeout(() => card.classList.remove('updated'), 400); }
  }

  function aggiornaStatusOnline() {
    const ns = document.getElementById('nav-status');
    const nt = document.getElementById('nav-status-text');
    if (ns && nt) {
      ns.classList.remove('offline');
      ns.classList.add('online');
      nt.textContent = 'Sistema attivo';
    }
  }

  function connetti() {
    const source = new EventSource('../api/eventi.php?since=' + ultimoTs);
    source.onmessage = function(e) {
      const data = JSON.parse(e.data);
      if (data.tipo === 'reconnect') { source.close(); setTimeout(connetti, 1000); return; }
      if (!data.studente) return;
      ultimoTs = data.timestamp;
      aggiornaStatusOnline();        // ← Raspberry attivo, aggiorna badge
      mostraNotifica(data);
      aggiornaDashboard();
    };
    source.onerror = function() { source.close(); setTimeout(connetti, 3000); };
  }

  function mostraNotifica(data) {
    const cfg = {
      'entrata_registrata':      { c:'#22c55e', t:'Entrata registrata' },
      'uscita_registrata':       { c:'#3b82f6', t:'Uscita registrata' },
      'presenza_gia_registrata': { c:'#5d7a9e', t:'Già presente oggi' },
      'ignorato_troppo_presto':  { c:'#5d7a9e', t:'Rilevato di recente' },
    };
    const { c, t } = cfg[data.azione] || { c:'#5d7a9e', t: data.azione };
    const box = document.createElement('div');
    box.style.cssText = `
      position:fixed;top:74px;right:20px;z-index:9999;
      background:#0d1726;border:1px solid ${c}40;border-left:3px solid ${c};
      border-radius:12px;padding:14px 18px;width:260px;
      font-family:'Sora',sans-serif;font-size:13px;color:#eef4ff;
      box-shadow:0 12px 40px rgba(0,0,0,.5);animation:slideIn .25s ease;
    `;
    box.innerHTML = `
      <div style="font-size:10px;color:${c};font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin-bottom:5px">${t}</div>
      <div style="font-weight:600;font-size:14px">${data.studente}</div>
      <div style="font-size:11px;color:#5d7a9e;margin-top:3px;font-family:'JetBrains Mono',monospace">${data.ora ? data.ora.substring(0,5) : ''}</div>
    `;
    document.body.appendChild(box);
    setTimeout(() => { box.style.transition='opacity .3s'; box.style.opacity='0'; setTimeout(()=>box.remove(),300); }, 4000);
  }

  connetti();
  setInterval(aggiornaDashboard, 15000);
</script>
</body>
</html>
