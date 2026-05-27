<?php
session_start();
if (!isset($_SESSION['utente_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../includes/db.php';

// Filtri
$cerca       = $_GET['cerca']    ?? '';
$classe_sel  = $_GET['classe']   ?? '';
$periodo_sel = $_GET['periodo']  ?? '30';   // default ultimi 30 giorni
$classi      = $pdo->query("SELECT * FROM classi ORDER BY nome")->fetchAll();

// Periodo → intervallo date
$periodi = [
    '7'    => ['Ultimi 7 giorni',     7],
    '30'   => ['Ultimi 30 giorni',    30],
    '90'   => ['Ultimi 3 mesi',       90],
    '365'  => ['Anno scolastico',     365],
    'all'  => ['Tutti i giorni',      9999],
];
if (!isset($periodi[$periodo_sel])) $periodo_sel = '30';
$periodo_giorni = $periodi[$periodo_sel][1];
$data_inizio    = date('Y-m-d', strtotime("-{$periodo_giorni} days"));

// WHERE studenti
$where  = ["u.ruolo = 'studente'", "u.attivo = 1"];
$params = [];

if ($cerca) {
    $where[]            = "(LOWER(u.nome) LIKE :cerca_n OR LOWER(u.cognome) LIKE :cerca_c)";
    $cerca_lower        = '%' . mb_strtolower($cerca, 'UTF-8') . '%';
    $params[':cerca_n'] = $cerca_lower;
    $params[':cerca_c'] = $cerca_lower;
}
if ($classe_sel) {
    $where[]              = "u.classe_id = :classe_id";
    $params[':classe_id'] = $classe_sel;
}
$where_sql = implode(' AND ', $where);

// Lista studenti con stats per il periodo
// Filtro data direttamente nel JOIN per usare il placeholder solo una volta
$stmt = $pdo->prepare("
    SELECT u.*, c.nome AS classe_nome,
           COALESCE(SUM(p.stato='presente'),          0) AS p_presente,
           COALESCE(SUM(p.stato='assente'),           0) AS p_assente,
           COALESCE(SUM(p.stato='ritardo'),           0) AS p_ritardo,
           COALESCE(SUM(p.stato='uscita_anticipata'), 0) AS p_uscita
    FROM utenti u
    LEFT JOIN classi c   ON c.id = u.classe_id
    LEFT JOIN presenze p ON p.studente_id = u.id AND p.data >= :d_inizio
    WHERE $where_sql
    GROUP BY u.id
    ORDER BY u.cognome, u.nome
");
$stmt->bindValue(':d_inizio', $data_inizio);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$studenti = $stmt->fetchAll();

// Stats aggregate
$tot_presenti = 0; $tot_assenti = 0; $tot_ritardi = 0; $tot_uscite = 0;
foreach ($studenti as $s) {
    $tot_presenti += (int)$s['p_presente'];
    $tot_assenti  += (int)$s['p_assente'];
    $tot_ritardi  += (int)$s['p_ritardo'];
    $tot_uscite   += (int)$s['p_uscita'];
}
$tot_rilevazioni = $tot_presenti + $tot_assenti + $tot_ritardi + $tot_uscite;
$perc_media = $tot_rilevazioni > 0 ? round($tot_presenti * 100 / $tot_rilevazioni) : 0;

// Trend giornaliero per il line chart
$ids = array_column($studenti, 'id');
$trend = [];
if (!empty($ids)) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT data, stato, COUNT(*) AS tot
        FROM presenze
        WHERE studente_id IN ($placeholders) AND data >= ?
        GROUP BY data, stato
        ORDER BY data ASC
    ");
    $stmt->execute([...$ids, $data_inizio]);
    foreach ($stmt->fetchAll() as $r) {
        $trend[$r['data']][$r['stato']] = (int)$r['tot'];
    }
}

// Distribuzione per classe
$studenti_per_classe = [];
foreach ($studenti as $s) {
    $cn = $s['classe_nome'] ?? 'Senza classe';
    $studenti_per_classe[$cn] = ($studenti_per_classe[$cn] ?? 0) + 1;
}

// Top assenze
$top_assenze = $studenti;
usort($top_assenze, fn($a, $b) => (int)$b['p_assente'] - (int)$a['p_assente']);
$top_assenze = array_slice(array_filter($top_assenze, fn($s) => $s['p_assente'] > 0), 0, 5);

// Top ritardi
$top_ritardi = $studenti;
usort($top_ritardi, fn($a, $b) => (int)$b['p_ritardo'] - (int)$a['p_ritardo']);
$top_ritardi = array_slice(array_filter($top_ritardi, fn($s) => $s['p_ritardo'] > 0), 0, 5);

// Stato Raspberry + data formattata
$cache_file = __DIR__ . '/../cache/ultimo_evento.json';
$raspberry_online = false;
if (file_exists($cache_file)) {
    $raw = file_get_contents($cache_file);
    $ev  = $raw ? json_decode($raw, true) : null;
    $raspberry_online = is_array($ev) && isset($ev['timestamp']) && (time() - $ev['timestamp']) < 7200;
}
$giorni_it = ['Dom','Lun','Mar','Mer','Gio','Ven','Sab'];
$mesi_it   = ['','Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
$data_fmt  = $giorni_it[date('w')] . ' ' . date('j') . ' ' . $mesi_it[(int)date('n')];
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>SchoolFaceID — Studenti</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg-deep:   #070d1a;
      --bg-card:   #0e1829;
      --bg-card2:  #111f33;
      --bg-input:  #0a1322;
      --border:    rgba(255,255,255,0.07);
      --blue:      #3b82f6;
      --green:     #22c55e;
      --red:       #ef4444;
      --orange:    #f97316;
      --text-white:#f0f6ff;
      --text-muted:#6b7fa3;
      --text-dim:  #3d5070;
    }

    html, body {
      min-height: 100%;
      font-family: 'Sora', sans-serif;
      background: var(--bg-deep);
      color: var(--text-white);
    }

    body::before {
      content: ''; position: fixed; inset: 0;
      background:
        radial-gradient(ellipse 70% 50% at 10% 20%, rgba(30,58,138,0.2) 0%, transparent 60%),
        radial-gradient(ellipse 50% 70% at 90% 80%, rgba(15,40,100,0.15) 0%, transparent 60%);
      pointer-events: none; z-index: 0;
    }

    body::after {
      content: ''; position: fixed; inset: 0;
      background-image: radial-gradient(rgba(59,130,246,0.06) 1px, transparent 1px);
      background-size: 32px 32px;
      pointer-events: none; z-index: 0;
    }

    /* NAVBAR */
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
    .nav-link:hover  { background: rgba(255,255,255,0.05); color: var(--text-white); }
    .nav-link.active {
      background: rgba(59,130,246,0.12); color: var(--blue);
      box-shadow: inset 0 0 0 1px rgba(59,130,246,0.2);
    }
    .nav-right { display: flex; align-items: center; gap: 14px; }
    .nav-date { font-size: 12px; color: var(--text-muted); font-family: 'JetBrains Mono', monospace; }
    .nav-status { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 500; padding: 5px 12px; border-radius: 20px; }
    .nav-status.online  { background: rgba(34,197,94,0.12); color: var(--green); }
    .nav-status.offline { background: rgba(239,68,68,0.12); color: var(--red); }
    .nav-status-dot { width: 7px; height: 7px; border-radius: 50%; }
    .online  .nav-status-dot { background: var(--green); animation: pulse 2s infinite; }
    .offline .nav-status-dot { background: var(--red); }
    @keyframes pulse { 0%,100%{opacity:1;} 50%{opacity:.3;} }
    .btn-logout {
      padding: 6px 16px; background: transparent;
      border: 1px solid var(--border); border-radius: 8px;
      color: var(--text-muted); font-family: 'Sora', sans-serif;
      font-size: 12px; cursor: pointer; text-decoration: none; transition: all 0.18s;
    }
    .btn-logout:hover { border-color: rgba(255,255,255,0.15); color: var(--text-white); }

    /* MAIN */
    .main {
      position: relative; z-index: 1;
      max-width: 1200px; margin: 0 auto;
      padding: 40px 40px 60px;
    }

    @keyframes fadeUp {
      from { opacity:0; transform:translateY(14px); }
      to   { opacity:1; transform:translateY(0); }
    }

    /* HEADER */
    .page-header {
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: 32px;
      animation: fadeUp 0.5s ease both;
    }

    h1 { font-size: 32px; font-weight: 700; letter-spacing: -0.03em; margin-bottom: 6px; }
    .page-subtitle { font-size: 14px; color: var(--text-muted); }

    .btn-nuovo {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 11px 22px;
      background: var(--blue); color: #fff;
      font-family: 'Sora', sans-serif; font-size: 14px; font-weight: 600;
      border: none; border-radius: 10px; cursor: pointer;
      text-decoration: none;
      box-shadow: 0 4px 16px rgba(59,130,246,0.3);
      transition: background 0.2s, box-shadow 0.2s;
    }
    .btn-nuovo:hover { background: #2563eb; box-shadow: 0 6px 24px rgba(59,130,246,0.4); }

    /* ALERT */
    .alert {
      padding: 13px 18px; border-radius: 10px;
      font-size: 13px; margin-bottom: 24px;
      animation: fadeUp 0.3s ease both;
    }
    .alert-success { background: rgba(34,197,94,0.1);  border: 1px solid rgba(34,197,94,0.25);  color: #86efac; }
    .alert-error   { background: rgba(239,68,68,0.1);  border: 1px solid rgba(239,68,68,0.25);  color: #fca5a5; }

    /* FILTRI */
    .filters {
      display: flex; gap: 12px; align-items: flex-end;
      margin-bottom: 24px;
      animation: fadeUp 0.5s ease 0.05s both;
    }

    .filter-group { display: flex; flex-direction: column; gap: 6px; }
    .filter-group label {
      font-size: 11px; color: var(--text-dim);
      font-family: 'JetBrains Mono', monospace;
      letter-spacing: 0.08em; text-transform: uppercase;
    }

    .filter-group input,
    .filter-group select {
      background: var(--bg-card); border: 1px solid var(--border);
      border-radius: 9px; padding: 9px 14px;
      font-family: 'Sora', sans-serif; font-size: 13px;
      color: var(--text-white); outline: none;
      transition: border-color 0.2s; min-width: 180px;
    }
    .filter-group input:focus,
    .filter-group select:focus {
      border-color: rgba(59,130,246,0.5);
      box-shadow: 0 0 0 3px rgba(59,130,246,0.08);
    }
    .filter-group select option { background: #0e1829; }

    .btn-filter {
      padding: 9px 20px; background: var(--blue);
      border: none; border-radius: 9px; color: #fff;
      font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 600;
      cursor: pointer; transition: background 0.2s;
      box-shadow: 0 4px 14px rgba(59,130,246,0.25);
    }
    .btn-filter:hover { background: #2563eb; }

    /* GRIGLIA STUDENTI */
    .studenti-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 16px;
      animation: fadeUp 0.5s ease 0.1s both;
    }

    .studente-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 16px; padding: 24px 20px;
      text-align: center;
      transition: border-color 0.2s, transform 0.2s, box-shadow 0.2s;
      position: relative;
      text-decoration: none;
      color: inherit;
      display: block;
      cursor: pointer;
    }
    .studente-card:hover {
      border-color: rgba(59,130,246,0.4);
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(59,130,246,0.08);
    }

    .avatar {
      width: 72px; height: 72px;
      background: linear-gradient(135deg, #1d3a6e, #2563eb);
      border-radius: 50%; margin: 0 auto 14px;
      display: flex; align-items: center; justify-content: center;
      font-size: 24px; font-weight: 600; color: #fff;
      overflow: hidden; border: 2px solid var(--border);
    }
    .avatar img { width: 100%; height: 100%; object-fit: cover; }

    .studente-nome {
      font-size: 15px; font-weight: 600;
      color: var(--text-white); margin-bottom: 4px;
    }
    .studente-classe {
      font-size: 12px; color: var(--text-muted); margin-bottom: 12px;
    }

    .presenze-count {
      font-family: 'JetBrains Mono', monospace;
      font-size: 11px; color: var(--text-dim);
      margin-bottom: 16px;
    }
    .presenze-count strong { color: var(--green); }


    .no-foto-badge {
      position: absolute; top: 12px; right: 12px;
      background: rgba(249,115,22,0.15);
      border: 1px solid rgba(249,115,22,0.3);
      border-radius: 6px; padding: 2px 8px;
      font-size: 10px; color: var(--orange);
      font-family: 'JetBrains Mono', monospace;
    }

    .empty-state {
      grid-column: 1/-1; text-align: center;
      padding: 60px 20px; color: var(--text-dim);
    }
    .empty-state p { font-size: 14px; margin-top: 8px; }

    /* STATS */
    .stats-grid {
      display: grid; grid-template-columns: repeat(5, 1fr);
      gap: 12px; margin-bottom: 24px;
      animation: fadeUp 0.5s ease 0.07s both;
    }
    @media (max-width: 1000px) { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
    .stat-card {
      background: var(--bg-card); border: 1px solid var(--border);
      border-radius: 14px; padding: 18px 20px;
      position: relative; overflow: hidden;
    }
    .stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; }
    .stat-card.blue::before { background: var(--blue); }
    .stat-card.green::before { background: var(--green); }
    .stat-card.red::before { background: var(--red); }
    .stat-card.orange::before { background: var(--orange); }
    .stat-card.yellow::before { background: #eab308; }
    .stat-label {
      font-size: 10px; font-family: 'JetBrains Mono', monospace;
      letter-spacing: 0.1em; text-transform: uppercase; margin-bottom: 8px;
    }
    .stat-card.blue .stat-label { color: var(--blue); }
    .stat-card.green .stat-label { color: var(--green); }
    .stat-card.red .stat-label { color: var(--red); }
    .stat-card.orange .stat-label { color: var(--orange); }
    .stat-card.yellow .stat-label { color: #eab308; }
    .stat-value { font-size: 28px; font-weight: 700; letter-spacing: -0.04em; }

    /* CHARTS */
    .charts-row {
      display: grid; grid-template-columns: 1fr 1fr;
      gap: 16px; margin-bottom: 24px;
      animation: fadeUp 0.5s ease 0.1s both;
    }
    @media (max-width: 900px) { .charts-row { grid-template-columns: 1fr; } }
    .chart-card {
      background: var(--bg-card); border: 1px solid var(--border);
      border-radius: 16px; padding: 22px;
    }
    .chart-title {
      font-size: 14px; font-weight: 600; margin-bottom: 16px;
      letter-spacing: -0.01em;
    }
    .chart-wrap { position: relative; height: 240px; }

    /* TOP */
    .top-row {
      display: grid; grid-template-columns: 1fr 1fr;
      gap: 16px; margin-bottom: 24px;
      animation: fadeUp 0.5s ease 0.12s both;
    }
    @media (max-width: 900px) { .top-row { grid-template-columns: 1fr; } }
    .top-card {
      background: var(--bg-card); border: 1px solid var(--border);
      border-radius: 16px; padding: 22px;
    }
    .top-list { display: flex; flex-direction: column; gap: 8px; }
    .top-item {
      display: flex; align-items: center; gap: 10px;
      background: var(--bg-card2); border: 1px solid var(--border);
      border-radius: 10px; padding: 10px 14px;
      text-decoration: none; color: inherit;
      transition: border-color 0.2s;
    }
    .top-item:hover { border-color: rgba(255,255,255,0.15); }
    .top-num {
      width: 26px; height: 26px; border-radius: 50%;
      background: var(--bg-deep); display: flex; align-items: center; justify-content: center;
      font-size: 11px; font-weight: 700; font-family: 'JetBrains Mono', monospace;
      color: var(--text-muted); flex-shrink: 0;
    }
    .top-nome { flex: 1; font-size: 13px; font-weight: 500; }
    .top-classe { font-size: 11px; color: var(--text-dim); font-family: 'JetBrains Mono', monospace; }
    .top-count {
      font-family: 'JetBrains Mono', monospace; font-size: 14px; font-weight: 700;
    }
    .top-count.red { color: var(--red); }
    .top-count.orange { color: var(--orange); }
    .top-empty { color: var(--text-dim); font-size: 13px; padding: 16px 0; text-align: center; }

    /* mini progress bar nelle card studente */
    .perc-bar { height: 4px; background: var(--bg-card2); border-radius: 4px; overflow: hidden; margin-top: 8px; }
    .perc-fill { height: 100%; transition: width 0.6s; }
    .perc-fill.alta { background: var(--green); }
    .perc-fill.media { background: var(--orange); }
    .perc-fill.bassa { background: var(--red); }

    /* MODAL AGGIUNGI */
    .modal-overlay {
      position: fixed; inset: 0;
      background: rgba(0,0,0,0.7);
      backdrop-filter: blur(8px);
      z-index: 100;
      display: none; align-items: center; justify-content: center;
    }
    .modal-overlay.open { display: flex; }

    .modal {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 20px; padding: 36px;
      width: 100%; max-width: 480px;
      animation: fadeUp 0.3s ease both;
      box-shadow: 0 40px 80px rgba(0,0,0,0.5);
    }

    .modal-header {
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: 28px;
    }
    .modal-title { font-size: 20px; font-weight: 700; letter-spacing: -0.02em; }
    .modal-close {
      width: 32px; height: 32px;
      background: var(--bg-card2); border: 1px solid var(--border);
      border-radius: 8px; cursor: pointer; color: var(--text-muted);
      font-size: 18px; display: flex; align-items: center; justify-content: center;
      transition: all 0.2s;
    }
    .modal-close:hover { color: var(--text-white); border-color: rgba(255,255,255,0.15); }

    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .form-full  { grid-column: 1 / -1; }

    .form-group { display: flex; flex-direction: column; gap: 7px; }
    .form-group label {
      font-size: 12px; font-weight: 500; color: var(--text-white);
    }

    .form-group input,
    .form-group select {
      background: var(--bg-input); border: 1px solid var(--border);
      border-radius: 9px; padding: 11px 14px;
      font-family: 'Sora', sans-serif; font-size: 13px;
      color: var(--text-white); outline: none;
      transition: border-color 0.2s;
    }
    .form-group input::placeholder { color: var(--text-dim); }
    .form-group input:focus,
    .form-group select:focus {
      border-color: rgba(59,130,246,0.5);
      box-shadow: 0 0 0 3px rgba(59,130,246,0.08);
    }
    .form-group select option { background: #0e1829; }

    /* Upload foto */
    .upload-area {
      border: 2px dashed var(--border); border-radius: 12px;
      padding: 24px; text-align: center; cursor: pointer;
      transition: border-color 0.2s, background 0.2s;
      position: relative;
    }
    .upload-area:hover {
      border-color: rgba(59,130,246,0.4);
      background: rgba(59,130,246,0.04);
    }
    .upload-area input[type="file"] {
      position: absolute; inset: 0; opacity: 0; cursor: pointer;
      width: 100%; height: 100%;
    }
    .upload-icon { font-size: 28px; margin-bottom: 8px; }
    .upload-text { font-size: 13px; color: var(--text-muted); }
    .upload-text strong { color: var(--blue); }
    .upload-hint { font-size: 11px; color: var(--text-dim); margin-top: 4px; font-family: 'JetBrains Mono', monospace; }

    .modal-footer { margin-top: 24px; display: flex; gap: 10px; }
    .btn-submit {
      flex: 1; padding: 12px;
      background: var(--blue); color: #fff;
      font-family: 'Sora', sans-serif; font-size: 14px; font-weight: 600;
      border: none; border-radius: 10px; cursor: pointer;
      transition: background 0.2s; box-shadow: 0 4px 16px rgba(59,130,246,0.25);
    }
    .btn-submit:hover { background: #2563eb; }
    .btn-cancel {
      padding: 12px 20px;
      background: transparent; border: 1px solid var(--border);
      border-radius: 10px; color: var(--text-muted);
      font-family: 'Sora', sans-serif; font-size: 14px; cursor: pointer;
      transition: all 0.2s;
    }
    .btn-cancel:hover { border-color: rgba(255,255,255,0.15); color: var(--text-white); }
  </style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
  <a class="nav-brand" href="dashboard.php">
    <div class="nav-logo"><img src="../assets/icon.svg" alt="SchoolFaceID"></div>
    <div>
      <div class="nav-title">SchoolFaceID</div>
      <div class="nav-sub"><?= htmlspecialchars($_SESSION['utente_nome']) ?></div>
    </div>
  </a>
  <div class="nav-links">
    <a href="dashboard.php" class="nav-link">Dashboard</a>
    <a href="registro.php"  class="nav-link">Registro</a>
    <a href="studenti.php"  class="nav-link active">Studenti</a>
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
    <div>
      <h1>Gestione studenti</h1>
      <p class="page-subtitle"><?= count($studenti) ?> studenti registrati nel sistema</p>
    </div>
  </div>

  <!-- FILTRI -->
  <form method="GET" action="">
    <div class="filters">
      <div class="filter-group">
        <label>Cerca</label>
        <input type="text" name="cerca" placeholder="Nome o cognome..." value="<?= htmlspecialchars($cerca) ?>"/>
      </div>
      <div class="filter-group">
        <label>Classe</label>
        <select name="classe">
          <option value="">Tutte le classi</option>
          <?php foreach ($classi as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $classe_sel == $c['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['nome']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group">
        <label>Periodo</label>
        <select name="periodo">
          <?php foreach ($periodi as $key => [$label, $g]): ?>
            <option value="<?= $key ?>" <?= (string)$periodo_sel === (string)$key ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn-filter">Filtra</button>
    </div>
  </form>

  <!-- STATS AGGREGATE -->
  <div class="stats-grid">
    <div class="stat-card blue">
      <div class="stat-label">% Presenze media</div>
      <div class="stat-value"><?= $perc_media ?>%</div>
    </div>
    <div class="stat-card green">
      <div class="stat-label">Presenze</div>
      <div class="stat-value"><?= $tot_presenti ?></div>
    </div>
    <div class="stat-card red">
      <div class="stat-label">Assenze</div>
      <div class="stat-value"><?= $tot_assenti ?></div>
    </div>
    <div class="stat-card orange">
      <div class="stat-label">Ritardi</div>
      <div class="stat-value"><?= $tot_ritardi ?></div>
    </div>
    <div class="stat-card yellow">
      <div class="stat-label">Uscite anticipate</div>
      <div class="stat-value"><?= $tot_uscite ?></div>
    </div>
  </div>

  <!-- CHARTS -->
  <div class="charts-row">
    <div class="chart-card">
      <div class="chart-title">Distribuzione stati (<?= htmlspecialchars($periodi[$periodo_sel][0]) ?>)</div>
      <div class="chart-wrap"><canvas id="chart-stati"></canvas></div>
    </div>
    <div class="chart-card">
      <div class="chart-title">Andamento % presenze (giorni di scuola)</div>
      <div class="chart-wrap"><canvas id="chart-trend"></canvas></div>
    </div>
  </div>

  <!-- TOP -->
  <div class="top-row">
    <div class="top-card">
      <div class="chart-title">Top 5 assenze</div>
      <div class="top-list">
        <?php if (empty($top_assenze)): ?>
          <div class="top-empty">Nessuna assenza nel periodo.</div>
        <?php else: ?>
          <?php foreach ($top_assenze as $i => $s): ?>
            <a href="studente_profilo.php?id=<?= $s['id'] ?>" class="top-item">
              <div class="top-num"><?= $i + 1 ?></div>
              <div style="flex:1;">
                <div class="top-nome"><?= htmlspecialchars($s['cognome'] . ' ' . $s['nome']) ?></div>
                <div class="top-classe"><?= htmlspecialchars($s['classe_nome'] ?? '—') ?></div>
              </div>
              <div class="top-count red"><?= $s['p_assente'] ?></div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <div class="top-card">
      <div class="chart-title">Top 5 ritardi</div>
      <div class="top-list">
        <?php if (empty($top_ritardi)): ?>
          <div class="top-empty">Nessun ritardo nel periodo.</div>
        <?php else: ?>
          <?php foreach ($top_ritardi as $i => $s): ?>
            <a href="studente_profilo.php?id=<?= $s['id'] ?>" class="top-item">
              <div class="top-num"><?= $i + 1 ?></div>
              <div style="flex:1;">
                <div class="top-nome"><?= htmlspecialchars($s['cognome'] . ' ' . $s['nome']) ?></div>
                <div class="top-classe"><?= htmlspecialchars($s['classe_nome'] ?? '—') ?></div>
              </div>
              <div class="top-count orange"><?= $s['p_ritardo'] ?></div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- GRIGLIA -->
  <div class="studenti-grid">
    <?php if (empty($studenti)): ?>
      <div class="empty-state">
        <div style="font-size:36px">👤</div>
        <p>Nessuno studente trovato.</p>
      </div>
    <?php else: ?>
      <?php foreach ($studenti as $s):
        $iniziali = strtoupper(substr($s['nome'],0,1) . substr($s['cognome'],0,1));
        $foto     = foto_path_sicuro($s['foto_path']);
        $foto_v   = ($foto && file_exists(dirname(__DIR__).'/'.$foto)) ? filemtime(dirname(__DIR__).'/'.$foto) : '';
      ?>
      <a class="studente-card" href="studente_profilo.php?id=<?= $s['id'] ?>">
        <?php if (!$s['foto_path']): ?>
          <span class="no-foto-badge">no foto</span>
        <?php endif; ?>

        <div class="avatar">
          <?php if ($foto): ?>
            <img src="../<?= htmlspecialchars($foto) ?><?= $foto_v ? '?v=' . $foto_v : '' ?>" alt="">
          <?php else: ?>
            <?= $iniziali ?>
          <?php endif; ?>
        </div>

        <div class="studente-nome"><?= htmlspecialchars($s['cognome'] . ' ' . $s['nome']) ?></div>
        <div class="studente-classe"><?= htmlspecialchars($s['classe_nome'] ?? 'Nessuna classe') ?></div>
        <?php
          $s_tot = (int)$s['p_presente'] + (int)$s['p_assente'] + (int)$s['p_ritardo'] + (int)$s['p_uscita'];
          $s_perc = $s_tot > 0 ? round((int)$s['p_presente'] * 100 / $s_tot) : 0;
          $cls = $s_perc >= 75 ? 'alta' : ($s_perc >= 50 ? 'media' : 'bassa');
        ?>
        <div class="presenze-count">
          <strong style="color:var(--text-white);"><?= $s_perc ?>%</strong> presenze
          <span style="color:var(--text-dim);">· <?= $s['p_presente'] ?>/<?= $s_tot ?></span>
        </div>
        <div class="perc-bar"><div class="perc-fill <?= $cls ?>" style="width:<?= $s_perc ?>%"></div></div>

      </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</main>

<script>
  Chart.defaults.color = '#6b7fa3';
  Chart.defaults.borderColor = 'rgba(255,255,255,0.06)';
  Chart.defaults.font.family = 'Sora, sans-serif';

  // Distribuzione stati (doughnut)
  new Chart(document.getElementById('chart-stati'), {
    type: 'doughnut',
    data: {
      labels: ['Presenze', 'Assenze', 'Ritardi', 'Uscite anticipate'],
      datasets: [{
        data: [<?= $tot_presenti ?>, <?= $tot_assenti ?>, <?= $tot_ritardi ?>, <?= $tot_uscite ?>],
        backgroundColor: ['#22c55e', '#ef4444', '#f97316', '#eab308'],
        borderColor: '#0e1829',
        borderWidth: 2,
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      cutout: '65%',
      plugins: {
        legend: { position: 'bottom', labels: { padding: 12, boxWidth: 12, font: { size: 11 } } }
      }
    }
  });

  // Trend giornaliero — % presenze per giorno (solo giorni con dati)
  <?php
    // Solo giorni con almeno una rilevazione
    $giorni_scuola = array_keys($trend);
    sort($giorni_scuola);

    $serie_label = [];
    $serie_perc  = [];
    foreach ($giorni_scuola as $d) {
        $p = $trend[$d]['presente']          ?? 0;
        $a = $trend[$d]['assente']           ?? 0;
        $r = $trend[$d]['ritardo']           ?? 0;
        $u = $trend[$d]['uscita_anticipata'] ?? 0;
        $tot = $p + $a + $r + $u;
        $serie_label[] = date('d/m', strtotime($d));
        $serie_perc[]  = $tot > 0 ? round($p * 100 / $tot, 1) : 0;
    }
  ?>
  new Chart(document.getElementById('chart-trend'), {
    type: 'line',
    data: {
      labels: <?= json_encode($serie_label) ?>,
      datasets: [{
        label: '% Presenze',
        data: <?= json_encode($serie_perc) ?>,
        borderColor: '#3b82f6',
        backgroundColor: 'rgba(59,130,246,0.15)',
        tension: 0.35,
        borderWidth: 2.5,
        pointRadius: 3,
        pointBackgroundColor: '#3b82f6',
        pointHoverRadius: 5,
        fill: true,
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: ctx => '% Presenze: ' + ctx.parsed.y + '%'
          }
        }
      },
      scales: {
        x: { ticks: { maxTicksLimit: 10, font: { size: 10 } }, grid: { display: false } },
        y: {
          min: 0, max: 100,
          ticks: { font: { size: 10 }, callback: v => v + '%' },
          grid: { color: 'rgba(255,255,255,0.04)' }
        }
      }
    }
  });

  // SSE: aggiorna lo status "Sistema attivo" appena il Raspberry registra qualcuno
  let stuSseTs = Math.floor(Date.now() / 1000);
  function stuConnetti() {
    const src = new EventSource('../api/eventi.php?since=' + stuSseTs);
    src.onmessage = function(e) {
      try {
        const d = JSON.parse(e.data);
        if (d.tipo === 'reconnect') { src.close(); setTimeout(stuConnetti, 1000); return; }
        if (d.timestamp) stuSseTs = d.timestamp;
        if (d.studente_id) {
          const ns = document.getElementById('nav-status');
          const nt = document.getElementById('nav-status-text');
          if (ns && nt) { ns.classList.remove('offline'); ns.classList.add('online'); nt.textContent = 'Sistema attivo'; }
        }
      } catch {}
    };
    src.onerror = function() { src.close(); setTimeout(stuConnetti, 3000); };
  }
  stuConnetti();
</script>

</body>
</html>
