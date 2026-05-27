<?php
session_start();
if (!isset($_SESSION['utente_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../includes/db.php';

// Filtri
$data_sel   = $_GET['data']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_sel)) $data_sel = date('Y-m-d');
$classe_sel = $_GET['classe'] ?? '';
$stato_sel  = $_GET['stato']  ?? '';
$cerca_sel  = trim($_GET['cerca'] ?? '');

// Classi per il filtro
$classi = $pdo->query("SELECT * FROM classi ORDER BY nome")->fetchAll();

// Query presenze con filtri — TUTTI gli studenti, anche senza record (= assenti)
$where  = ["u.ruolo = 'studente'", "u.attivo = 1"];
$params = [':data' => $data_sel];

if ($classe_sel) {
    $where[]              = "u.classe_id = :classe_id";
    $params[':classe_id'] = $classe_sel;
}
// Filtro stato: NULL = nessun record = assente
if ($stato_sel === 'assente') {
    $where[] = "(p.stato = 'assente' OR p.stato IS NULL)";
} elseif ($stato_sel) {
    $where[]          = "p.stato = :stato";
    $params[':stato'] = $stato_sel;
}
if ($cerca_sel) {
    $where[]            = "(LOWER(u.nome) LIKE :cerca_n OR LOWER(u.cognome) LIKE :cerca_c)";
    $cerca_lower        = '%' . mb_strtolower($cerca_sel, 'UTF-8') . '%';
    $params[':cerca_n'] = $cerca_lower;
    $params[':cerca_c'] = $cerca_lower;
}

$where_sql = implode(' AND ', $where);

// Filtro data SOLO nel LEFT JOIN — così gli studenti senza record vengono inclusi con p.* NULL
$presenze = $pdo->prepare("
    SELECT
        u.id, u.nome, u.cognome, u.foto_path,
        c.nome AS classe,
        p.stato, p.ora_entrata, p.ora_uscita,
        p.rilevato_da, p.note
    FROM utenti u
    LEFT JOIN presenze p ON p.studente_id = u.id AND p.data = :data
    LEFT JOIN classi c   ON c.id = u.classe_id
    WHERE $where_sql
    ORDER BY c.nome, u.cognome, u.nome
");
$presenze->execute($params);
$rows = $presenze->fetchAll();

// Contatori — NULL = nessuna presenza registrata, trattato come assente
$tot_presenti = count(array_filter($rows, fn($r) => $r['stato'] === 'presente'));
$tot_ritardi  = count(array_filter($rows, fn($r) => $r['stato'] === 'ritardo'));
$tot_uscite   = count(array_filter($rows, fn($r) => $r['stato'] === 'uscita_anticipata'));
$tot_assenti  = count($rows) - $tot_presenti - $tot_ritardi - $tot_uscite;

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
  <title>SchoolFaceID — Registro Presenze</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
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
      --yellow:    #eab308;
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
      content: '';
      position: fixed; inset: 0;
      background:
        radial-gradient(ellipse 70% 50% at 10% 20%, rgba(30,58,138,0.2) 0%, transparent 60%),
        radial-gradient(ellipse 50% 70% at 90% 80%, rgba(15,40,100,0.15) 0%, transparent 60%);
      pointer-events: none; z-index: 0;
    }

    body::after {
      content: '';
      position: fixed; inset: 0;
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
    .nav-link:hover { background: rgba(255,255,255,0.05); color: var(--text-white); }
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
      max-width: 1200px;
      margin: 0 auto;
      padding: 40px 40px 60px;
    }

    .page-header {
      display: flex; align-items: flex-start; justify-content: space-between;
      margin-bottom: 32px;
      animation: fadeUp 0.5s ease both;
    }

    @keyframes fadeUp {
      from { opacity:0; transform:translateY(14px); }
      to   { opacity:1; transform:translateY(0); }
    }

    h1 {
      font-size: 32px; font-weight: 700;
      letter-spacing: -0.03em; margin-bottom: 6px;
    }

    .page-date {
      font-family: 'JetBrains Mono', monospace;
      font-size: 13px; color: var(--text-muted);
    }


    /* STATS */
    .stats {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 14px; margin-bottom: 28px;
      animation: fadeUp 0.5s ease 0.05s both;
    }
    @media (max-width: 900px) { .stats { grid-template-columns: repeat(2, 1fr); } }

    .stat-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 20px 22px;
      position: relative; overflow: hidden;
    }

    .stat-card::before {
      content: ''; position: absolute;
      top: 0; left: 0; right: 0; height: 2px;
    }

    .stat-card.grey::before   { background: rgba(255,255,255,0.08); }
    .stat-card.green::before  { background: var(--green); }
    .stat-card.red::before    { background: var(--red); }
    .stat-card.orange::before { background: var(--orange); }
    .stat-card.yellow::before { background: var(--yellow); }

    .stat-label {
      font-size: 11px; font-family: 'JetBrains Mono', monospace;
      letter-spacing: 0.1em; text-transform: uppercase;
      margin-bottom: 8px;
    }

    .stat-card.grey .stat-label   { color: var(--text-dim); }
    .stat-card.green .stat-label  { color: var(--green); }
    .stat-card.red .stat-label    { color: var(--red); }
    .stat-card.orange .stat-label { color: var(--orange); }
    .stat-card.yellow .stat-label { color: var(--yellow); }

    .stat-value { font-size: 32px; font-weight: 700; letter-spacing: -0.04em; }

    /* FILTRI */
    .filters {
      display: flex; gap: 12px; align-items: flex-end;
      margin-bottom: 24px;
      animation: fadeUp 0.5s ease 0.1s both;
    }

    .filter-group { display: flex; flex-direction: column; gap: 6px; }
    .filter-group label { font-size: 11px; color: var(--text-dim); font-family: 'JetBrains Mono', monospace; letter-spacing: 0.08em; text-transform: uppercase; }

    .filter-group input,
    .filter-group select {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 9px;
      padding: 9px 14px;
      font-family: 'Sora', sans-serif;
      font-size: 13px;
      color: var(--text-white);
      outline: none;
      transition: border-color 0.2s;
      cursor: pointer;
    }

    .filter-group input[type="date"] { color-scheme: dark; }

    .filter-group input:focus,
    .filter-group select:focus {
      border-color: rgba(59,130,246,0.5);
      box-shadow: 0 0 0 3px rgba(59,130,246,0.08);
    }

    .filter-group select option { background: #0e1829; }

    .btn-filter {
      padding: 9px 20px;
      background: var(--blue);
      border: none; border-radius: 9px;
      color: #fff;
      font-family: 'Sora', sans-serif;
      font-size: 13px; font-weight: 600;
      cursor: pointer;
      transition: background 0.2s;
      box-shadow: 0 4px 14px rgba(59,130,246,0.25);
    }
    .btn-filter:hover { background: #2563eb; }

    /* TABELLA */
    .table-wrap {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 16px;
      overflow: hidden;
      animation: fadeUp 0.5s ease 0.15s both;
    }

    .table-header {
      display: flex; align-items: center; justify-content: space-between;
      padding: 20px 24px;
      border-bottom: 1px solid var(--border);
    }

    .table-title { font-size: 16px; font-weight: 600; }
    .table-count {
      font-size: 12px; font-family: 'JetBrains Mono', monospace;
      color: var(--text-muted);
    }

    table {
      width: 100%; border-collapse: collapse;
    }

    thead th {
      padding: 12px 20px;
      text-align: left;
      font-size: 11px;
      font-family: 'JetBrains Mono', monospace;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: var(--text-dim);
      border-bottom: 1px solid var(--border);
      background: rgba(0,0,0,0.15);
    }

    tbody tr {
      border-bottom: 1px solid var(--border);
      transition: background 0.15s;
    }
    tbody tr:last-child { border-bottom: none; }
    tbody tr:hover { background: rgba(255,255,255,0.02); }

    tbody td {
      padding: 14px 20px;
      font-size: 13px;
      color: var(--text-muted);
      vertical-align: middle;
    }

    .student-cell {
      display: flex; align-items: center; gap: 12px;
    }

    .avatar-sm {
      width: 36px; height: 36px;
      background: linear-gradient(135deg, #1d3a6e, #2563eb);
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 13px; font-weight: 600; color: #fff;
      flex-shrink: 0; overflow: hidden;
    }

    .avatar-sm img { width: 100%; height: 100%; object-fit: cover; }

    .student-name  { font-size: 14px; font-weight: 500; color: var(--text-white); }
    .student-class { font-size: 11px; color: var(--text-dim); margin-top: 2px; }

    .badge {
      display: inline-flex; align-items: center; gap: 5px;
      font-size: 11px; font-weight: 500;
      padding: 4px 10px; border-radius: 20px;
    }

    .badge::before {
      content: ''; width: 6px; height: 6px;
      border-radius: 50%; background: currentColor;
    }

    .badge-presente          { background: rgba(34,197,94,0.12);  color: var(--green); }
    .badge-assente           { background: rgba(239,68,68,0.12);  color: var(--red); }
    .badge-ritardo           { background: rgba(249,115,22,0.12); color: var(--orange); }
    .badge-uscita_anticipata { background: rgba(234,179,8,0.12);  color: var(--yellow); }
    .badge-null              { background: rgba(100,116,139,0.12);color: #64748b; }

    .time-cell {
      font-family: 'JetBrains Mono', monospace;
      font-size: 12px; color: var(--text-white);
    }
    .time-cell span { color: var(--text-dim); }

    .source-badge {
      font-size: 11px;
      font-family: 'JetBrains Mono', monospace;
      padding: 3px 8px; border-radius: 6px;
    }
    .source-facciale { background: rgba(59,130,246,0.1); color: var(--blue); }
    .source-manuale  { background: rgba(100,116,139,0.1); color: #64748b; }

    .btn-edit {
      padding: 6px 14px;
      background: transparent;
      border: 1px solid var(--border);
      border-radius: 7px;
      color: var(--text-muted);
      font-family: 'Sora', sans-serif;
      font-size: 12px; cursor: pointer;
      transition: all 0.2s; text-decoration: none;
    }
    .btn-edit:hover { border-color: rgba(255,255,255,0.15); color: var(--text-white); }

    .empty-state {
      padding: 60px 20px;
      text-align: center;
      color: var(--text-dim);
    }
    .empty-state p { font-size: 14px; margin-top: 8px; }
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
    <a href="registro.php"  class="nav-link active">Registro</a>
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

  <!-- HEADER -->
  <div class="page-header">
    <div>
      <h1>Registro presenze</h1>
      <div class="page-date">
        <?= date('l d F Y', strtotime($data_sel)) ?>
      </div>
    </div>
  </div>

  <!-- STATS -->
  <div class="stats">
    <div class="stat-card green">
      <div class="stat-label">Presenti</div>
      <div class="stat-value"><?= $tot_presenti ?></div>
    </div>
    <div class="stat-card red">
      <div class="stat-label">Assenti</div>
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

  <!-- FILTRI -->
  <form method="GET" action="">
    <div class="filters">
      <div class="filter-group">
        <label>Data</label>
        <input type="date" name="data" value="<?= $data_sel ?>"/>
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
        <label>Stato</label>
        <select name="stato">
          <option value="">Tutti</option>
          <option value="presente"          <?= $stato_sel === 'presente'          ? 'selected' : '' ?>>Presente</option>
          <option value="assente"           <?= $stato_sel === 'assente'           ? 'selected' : '' ?>>Assente</option>
          <option value="ritardo"           <?= $stato_sel === 'ritardo'           ? 'selected' : '' ?>>Ritardo</option>
          <option value="uscita_anticipata" <?= $stato_sel === 'uscita_anticipata' ? 'selected' : '' ?>>Uscita anticipata</option>
        </select>
      </div>
      <div class="filter-group">
        <label>Cerca</label>
        <input type="text" name="cerca" placeholder="Nome o cognome..." value="<?= htmlspecialchars($cerca_sel) ?>"/>
      </div>
      <button type="submit" class="btn-filter">Filtra</button>
    </div>
  </form>

  <!-- TABELLA -->
  <div class="table-wrap">
    <div class="table-header">
      <span class="table-title">Elenco studenti</span>
      <span class="table-count"><?= count($rows) ?> studenti</span>
    </div>

    <?php if (empty($rows)): ?>
      <div class="empty-state">
        <div style="font-size:32px">📋</div>
        <p>Nessuna presenza trovata per i filtri selezionati.</p>
      </div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Studente</th>
          <th>Stato</th>
          <th>Entrata</th>
          <th>Uscita</th>
          <th>Rilevato da</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row):
          $iniziali = strtoupper(substr($row['nome'],0,1) . substr($row['cognome'],0,1));
          $stato    = $row['stato'] ?? 'assente';
        ?>
        <tr>
          <td>
            <div class="student-cell">
              <div class="avatar-sm">
                <?php if ($row['foto_path']): ?>
                  <img src="../<?= htmlspecialchars(foto_path_sicuro($row['foto_path'])) ?>" alt="">
                <?php else: ?>
                  <?= $iniziali ?>
                <?php endif; ?>
              </div>
              <div>
                <div class="student-name"><?= htmlspecialchars($row['nome'] . ' ' . $row['cognome']) ?></div>
                <div class="student-class"><?= htmlspecialchars($row['classe'] ?? '—') ?></div>
              </div>
            </div>
          </td>
          <td>
            <span class="badge badge-<?= $stato ?>">
              <?= ucfirst(str_replace('_', ' ', $stato)) ?>
            </span>
          </td>
          <td class="time-cell">
            <?= $row['ora_entrata'] ? date('H:i', strtotime($row['ora_entrata'])) : '<span>—</span>' ?>
          </td>
          <td class="time-cell">
            <?= $row['ora_uscita'] ? date('H:i', strtotime($row['ora_uscita'])) : '<span>—</span>' ?>
          </td>
          <td>
            <?php if ($row['rilevato_da']): ?>
              <span class="source-badge source-<?= $row['rilevato_da'] ?>">
                <?= $row['rilevato_da'] === 'facciale' ? '📷 facciale' : '✏️ manuale' ?>
              </span>
            <?php else: ?>
              <span style="color:var(--text-dim)">—</span>
            <?php endif; ?>
          </td>
          <td>
            <a href="modifica_presenza.php?id=<?= $row['id'] ?>&data=<?= $data_sel ?>" class="btn-edit">
              Modifica
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</main>

<script>
  // SSE: aggiorna lo status "Sistema attivo" appena il Raspberry registra qualcuno
  let regSseTs = Math.floor(Date.now() / 1000);
  function regConnetti() {
    const src = new EventSource('../api/eventi.php?since=' + regSseTs);
    src.onmessage = function(e) {
      try {
        const d = JSON.parse(e.data);
        if (d.tipo === 'reconnect') { src.close(); setTimeout(regConnetti, 1000); return; }
        if (d.timestamp) regSseTs = d.timestamp;
        if (d.studente_id) {
          const ns = document.getElementById('nav-status');
          const nt = document.getElementById('nav-status-text');
          if (ns && nt) { ns.classList.remove('offline'); ns.classList.add('online'); nt.textContent = 'Sistema attivo'; }
        }
      } catch {}
    };
    src.onerror = function() { src.close(); setTimeout(regConnetti, 3000); };
  }
  regConnetti();
</script>
</body>
</html>
