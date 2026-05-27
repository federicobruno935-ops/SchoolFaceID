<?php
session_start();
if (!isset($_SESSION['utente_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../includes/db.php';

$sid  = (int)($_GET['id'] ?? 0);
$oggi = date('Y-m-d');

// Dati studente
$stmt = $pdo->prepare("
    SELECT u.*, c.nome AS classe_nome
    FROM utenti u LEFT JOIN classi c ON c.id = u.classe_id
    WHERE u.id = ? AND u.ruolo = 'studente' AND u.attivo = 1
");
$stmt->execute([$sid]);
$studente = $stmt->fetch();

if (!$studente) {
    header('Location: studenti.php');
    exit;
}

// Presenza oggi
$stmt = $pdo->prepare("SELECT * FROM presenze WHERE studente_id = ? AND data = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$sid, $oggi]);
$presenza_oggi = $stmt->fetch();

// Statistiche totali
$stmt = $pdo->prepare("SELECT stato, COUNT(*) as tot FROM presenze WHERE studente_id = ? GROUP BY stato");
$stmt->execute([$sid]);
$stats = ['presente' => 0, 'assente' => 0, 'ritardo' => 0, 'uscita_anticipata' => 0];
foreach ($stmt->fetchAll() as $r) $stats[$r['stato']] = (int)$r['tot'];
$totale_giorni = array_sum($stats);
$perc_presenze = $totale_giorni > 0 ? round(($stats['presente'] / $totale_giorni) * 100) : 0;

// Storico con filtro
$stati_validi = ['tutti', 'presente', 'assente', 'ritardo', 'uscita_anticipata'];
$filtro = in_array($_GET['filtro'] ?? 'tutti', $stati_validi) ? $_GET['filtro'] : 'tutti';

if ($filtro === 'tutti') {
    $stmt = $pdo->prepare("SELECT * FROM presenze WHERE studente_id = ? ORDER BY data DESC, id DESC");
    $stmt->execute([$sid]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM presenze WHERE studente_id = ? AND stato = ? ORDER BY data DESC, id DESC");
    $stmt->execute([$sid, $filtro]);
}
$storico = $stmt->fetchAll();

// Ultimi 7 giorni
$stmt = $pdo->prepare("
    SELECT data, stato FROM presenze
    WHERE studente_id = ? AND data >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
");
$stmt->execute([$sid]);
$presenze7 = [];
foreach ($stmt->fetchAll() as $r) {
    $presenze7[$r['data']] = $r['stato'];
}

$ultimi7 = [];
for ($i = 6; $i >= 0; $i--) {
    $data = date('Y-m-d', strtotime("-$i days"));
    $ultimi7[] = [
        'data'   => $data,
        'giorno' => date('D', strtotime($data)),
        'num'    => date('d', strtotime($data)),
        'stato'  => $presenze7[$data] ?? null,
        'oggi'   => $data === $oggi,
    ];
}

$iniziali  = strtoupper(substr($studente['nome'],0,1) . substr($studente['cognome'],0,1));
$stato_oggi = $presenza_oggi['stato'] ?? null;
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>SchoolFaceID — <?= htmlspecialchars($studente['nome'] . ' ' . $studente['cognome']) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg-deep:#070d1a; --bg-card:#0e1829; --bg-card2:#111f33;
      --border:rgba(255,255,255,0.07);
      --blue:#3b82f6; --green:#22c55e; --red:#ef4444; --orange:#f97316; --yellow:#eab308;
      --text-white:#f0f6ff; --text-muted:#6b7fa3; --text-dim:#3d5070;
    }
    html, body { min-height:100%; font-family:'Sora',sans-serif; background:var(--bg-deep); color:var(--text-white); }
    body::before { content:''; position:fixed; inset:0; background: radial-gradient(ellipse 70% 50% at 10% 20%, rgba(30,58,138,0.2) 0%, transparent 60%), radial-gradient(ellipse 50% 70% at 90% 80%, rgba(15,40,100,0.15) 0%, transparent 60%); pointer-events:none; z-index:0; }
    body::after { content:''; position:fixed; inset:0; background-image: radial-gradient(rgba(59,130,246,0.06) 1px, transparent 1px); background-size:32px 32px; pointer-events:none; z-index:0; }

    .navbar { position:sticky; top:0; z-index:100; display:flex; align-items:center; justify-content:space-between; padding:0 40px; height:62px; background:rgba(6,12,24,0.85); backdrop-filter:blur(20px) saturate(180%); border-bottom:1px solid var(--border); }
    .nav-brand { display:flex; align-items:center; gap:12px; text-decoration:none; }
    .nav-logo { width:38px; height:38px; display:flex; align-items:center; justify-content:center; filter:drop-shadow(0 0 12px rgba(59,130,246,0.4)); }
    .nav-logo img { width:100%; height:100%; object-fit:contain; }
    .nav-title { font-size:15px; font-weight:700; letter-spacing:-0.02em; color:var(--text-white); }
    .nav-sub { font-size:11px; color:var(--text-muted); margin-top:1px; }
    .nav-links { display:flex; gap:2px; }
    .nav-link { padding:6px 14px; border-radius:8px; font-size:13px; font-weight:500; color:var(--text-muted); text-decoration:none; transition:all 0.18s; }
    .nav-link:hover { background:rgba(255,255,255,0.05); color:var(--text-white); }
    .nav-link.active { background:rgba(59,130,246,0.12); color:var(--blue); box-shadow:inset 0 0 0 1px rgba(59,130,246,0.2); }
    .btn-logout { padding:6px 16px; background:transparent; border:1px solid var(--border); border-radius:8px; color:var(--text-muted); font-family:'Sora',sans-serif; font-size:12px; cursor:pointer; text-decoration:none; transition:all 0.18s; }
    .btn-logout:hover { border-color:rgba(255,255,255,0.15); color:var(--text-white); }

    .main { position:relative; z-index:1; max-width:1100px; margin:0 auto; padding:32px 40px 60px; }
    @keyframes fadeUp { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }

    .back-link { display:inline-flex; align-items:center; gap:8px; color:var(--text-muted); font-size:13px; text-decoration:none; margin-bottom:20px; transition:color 0.2s; }
    .back-link:hover { color:var(--text-white); }

    .hero { display:flex; align-items:center; gap:24px; background:var(--bg-card); border:1px solid var(--border); border-radius:20px; padding:28px 32px; margin-bottom:28px; animation:fadeUp 0.5s ease both; }
    .avatar-lg { width:80px; height:80px; background:linear-gradient(135deg,#1d3a6e,#2563eb); border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:28px; font-weight:700; color:#fff; overflow:hidden; border:3px solid rgba(59,130,246,0.3); }
    .avatar-lg img { width:100%; height:100%; object-fit:cover; }
    .hero-info { flex:1; }
    .hero-info h1 { font-size:28px; font-weight:700; letter-spacing:-0.03em; margin-bottom:4px; }
    .hero-info .classe { font-size:14px; color:var(--text-muted); margin-bottom:6px; }
    .hero-info .email { font-size:12px; color:var(--text-dim); font-family:'JetBrains Mono',monospace; margin-bottom:10px; }
    .stato-oggi { display:inline-flex; align-items:center; gap:8px; padding:6px 14px; border-radius:20px; font-size:13px; font-weight:500; }
    .stato-oggi.presente { background:rgba(34,197,94,0.1); border:1px solid rgba(34,197,94,0.25); color:var(--green); }
    .stato-oggi.assente { background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.25); color:var(--red); }
    .stato-oggi.ritardo { background:rgba(249,115,22,0.1); border:1px solid rgba(249,115,22,0.25); color:var(--orange); }
    .stato-oggi.uscita_anticipata { background:rgba(234,179,8,0.1); border:1px solid rgba(234,179,8,0.25); color:var(--yellow); }
    .stato-oggi.nessuno { background:rgba(100,116,139,0.1); border:1px solid rgba(100,116,139,0.25); color:#64748b; }

    .stats { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:28px; animation:fadeUp 0.5s ease 0.05s both; }
    .stat-card { background:var(--bg-card); border:1px solid var(--border); border-radius:14px; padding:20px 22px; position:relative; overflow:hidden; }
    .stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; }
    .stat-card.blue::before { background:var(--blue); }
    .stat-card.green::before { background:var(--green); }
    .stat-card.red::before { background:var(--red); }
    .stat-card.orange::before { background:var(--orange); }
    .stat-label { font-size:11px; font-family:'JetBrains Mono',monospace; letter-spacing:0.1em; text-transform:uppercase; margin-bottom:8px; }
    .stat-card.blue .stat-label { color:var(--blue); }
    .stat-card.green .stat-label { color:var(--green); }
    .stat-card.red .stat-label { color:var(--red); }
    .stat-card.orange .stat-label { color:var(--orange); }
    .stat-value { font-size:34px; font-weight:700; letter-spacing:-0.04em; }
    .stat-sub { font-size:11px; color:var(--text-dim); margin-top:4px; font-family:'JetBrains Mono',monospace; }

    .grid-main { display:grid; grid-template-columns:1fr 340px; gap:20px; animation:fadeUp 0.5s ease 0.1s both; }
    .card { background:var(--bg-card); border:1px solid var(--border); border-radius:16px; padding:28px; }
    .card-title { font-size:16px; font-weight:600; margin-bottom:20px; letter-spacing:-0.02em; }

    .filtri-storico { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px; }
    .filtro-btn {
      padding:6px 14px; border-radius:20px;
      font-family:'Sora',sans-serif; font-size:12px; font-weight:500;
      background:var(--bg-card2); border:1px solid var(--border);
      color:var(--text-muted); text-decoration:none;
      transition:all 0.2s; cursor:pointer;
    }
    .filtro-btn:hover { border-color:rgba(255,255,255,0.15); color:var(--text-white); }
    .filtro-btn.attivo { background:var(--blue); border-color:var(--blue); color:#fff; box-shadow:0 4px 12px rgba(59,130,246,0.25); }
    .filtro-btn.attivo.presente { background:var(--green); border-color:var(--green); box-shadow:0 4px 12px rgba(34,197,94,0.25); }
    .filtro-btn.attivo.assente { background:var(--red); border-color:var(--red); box-shadow:0 4px 12px rgba(239,68,68,0.25); }
    .filtro-btn.attivo.ritardo { background:var(--orange); border-color:var(--orange); box-shadow:0 4px 12px rgba(249,115,22,0.25); }
    .filtro-btn.attivo.uscita_anticipata { background:var(--yellow); border-color:var(--yellow); color:#0e1829; box-shadow:0 4px 12px rgba(234,179,8,0.25); }
    .filtro-count { font-family:'JetBrains Mono',monospace; opacity:0.7; margin-left:4px; }

    .perc-bar-wrap { margin-bottom:24px; }
    .perc-label { display:flex; justify-content:space-between; font-size:13px; color:var(--text-muted); margin-bottom:8px; }
    .perc-label strong { color:var(--text-white); }
    .perc-bar { height:10px; background:var(--bg-card2); border-radius:10px; overflow:hidden; }
    .perc-fill { height:100%; border-radius:10px; transition:width 1s ease; }
    .perc-fill.alta { background:var(--green); }
    .perc-fill.media { background:var(--orange); }
    .perc-fill.bassa { background:var(--red); }

    .settimana { display:grid; grid-template-columns:repeat(7,1fr); gap:8px; margin-bottom:24px; }
    .giorno-cell { text-align:center; }
    .giorno-nome { font-size:10px; font-family:'JetBrains Mono',monospace; color:var(--text-dim); text-transform:uppercase; margin-bottom:6px; }
    .giorno-num { width:36px; height:36px; margin:0 auto; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:600; border:2px solid transparent; }
    .giorno-num.oggi { border-color:var(--blue); color:var(--blue); }
    .giorno-num.presente { background:rgba(34,197,94,0.2); color:var(--green); }
    .giorno-num.assente { background:rgba(239,68,68,0.2); color:var(--red); }
    .giorno-num.ritardo { background:rgba(249,115,22,0.2); color:var(--orange); }
    .giorno-num.nessuno { background:var(--bg-card2); color:var(--text-dim); }

    table { width:100%; border-collapse:collapse; }
    thead th { padding:10px 14px; text-align:left; font-size:10px; font-family:'JetBrains Mono',monospace; letter-spacing:0.1em; text-transform:uppercase; color:var(--text-dim); border-bottom:1px solid var(--border); }
    tbody tr { border-bottom:1px solid var(--border); transition:background 0.15s; }
    tbody tr:last-child { border-bottom:none; }
    tbody tr:hover { background:rgba(255,255,255,0.02); }
    tbody td { padding:11px 14px; font-size:13px; color:var(--text-muted); }

    .badge { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:500; padding:3px 10px; border-radius:20px; }
    .badge::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; }
    .badge-presente { background:rgba(34,197,94,0.12); color:var(--green); }
    .badge-assente { background:rgba(239,68,68,0.12); color:var(--red); }
    .badge-ritardo { background:rgba(249,115,22,0.12); color:var(--orange); }
    .badge-uscita_anticipata { background:rgba(234,179,8,0.12); color:var(--yellow); }
    .time-cell { font-family:'JetBrains Mono',monospace; font-size:12px; color:var(--text-white); }

    .mini-stats { display:flex; flex-direction:column; gap:12px; margin-bottom:20px; }
    .mini-stat { display:flex; align-items:center; justify-content:space-between; background:var(--bg-card2); border:1px solid var(--border); border-radius:10px; padding:12px 16px; }
    .mini-stat-label { font-size:13px; color:var(--text-muted); }
    .mini-stat-val { font-size:16px; font-weight:700; }
    .mini-stat-val.green { color:var(--green); }
    .mini-stat-val.red { color:var(--red); }
    .mini-stat-val.orange { color:var(--orange); }
    .mini-stat-val.blue { color:var(--blue); }

    .avviso { border-radius:12px; padding:14px 16px; font-size:13px; line-height:1.5; }
    .avviso.ok { background:rgba(34,197,94,0.07); border:1px solid rgba(34,197,94,0.2); color:#86efac; }
    .avviso.warning { background:rgba(249,115,22,0.07); border:1px solid rgba(249,115,22,0.2); color:#fdba74; }
    .avviso.danger { background:rgba(239,68,68,0.07); border:1px solid rgba(239,68,68,0.2); color:#fca5a5; }
    .avviso strong { display:block; font-size:12px; font-weight:600; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em; }
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
    <a href="dashboard.php" class="nav-link">Dashboard</a>
    <a href="registro.php" class="nav-link">Registro</a>
    <a href="studenti.php" class="nav-link active">Studenti</a>
  </div>
  <div>
    <a href="logout.php" class="btn-logout">Logout</a>
  </div>
</nav>

<main class="main">

  <a href="studenti.php" class="back-link">← Torna agli studenti</a>

  <div class="hero">
    <div class="avatar-lg">
      <?php if ($studente['foto_path']): ?>
        <img src="../<?= htmlspecialchars(foto_path_sicuro($studente['foto_path'])) ?>" alt="">
      <?php else: ?>
        <?= $iniziali ?>
      <?php endif; ?>
    </div>
    <div class="hero-info">
      <h1><?= htmlspecialchars($studente['nome'] . ' ' . $studente['cognome']) ?></h1>
      <div class="classe"><?= htmlspecialchars($studente['classe_nome'] ?? 'Nessuna classe') ?></div>
      <div class="email"><?= htmlspecialchars($studente['email']) ?></div>
      <?php
        $css_stato  = $stato_oggi ?? 'nessuno';
        $icone      = ['presente' => '✅', 'assente' => '❌', 'ritardo' => '⏰', 'uscita_anticipata' => '⚠️'];
        $icona      = $icone[$stato_oggi] ?? '—';
        $testo      = $stato_oggi ? ucfirst(str_replace('_', ' ', $stato_oggi)) : 'Non ancora rilevato';
      ?>
      <div class="stato-oggi <?= $css_stato ?>">
        <?= $icona ?> Oggi: <?= $testo ?>
        <?php if ($presenza_oggi && $presenza_oggi['ora_entrata']): ?>
          — entrata alle <?= date('H:i', strtotime($presenza_oggi['ora_entrata'])) ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="stats">
    <div class="stat-card blue">
      <div class="stat-label">% Presenze</div>
      <div class="stat-value"><?= $perc_presenze ?>%</div>
      <div class="stat-sub">su <?= $totale_giorni ?> giorni</div>
    </div>
    <div class="stat-card green">
      <div class="stat-label">Presenze</div>
      <div class="stat-value"><?= $stats['presente'] ?></div>
      <div class="stat-sub">giorni presenti</div>
    </div>
    <div class="stat-card red">
      <div class="stat-label">Assenze</div>
      <div class="stat-value"><?= $stats['assente'] ?></div>
      <div class="stat-sub">giorni assente</div>
    </div>
    <div class="stat-card orange">
      <div class="stat-label">Ritardi</div>
      <div class="stat-value"><?= $stats['ritardo'] ?></div>
      <div class="stat-sub">ingressi in ritardo</div>
    </div>
  </div>

  <div class="grid-main">

    <div class="card">
      <div class="card-title">Ultimi 7 giorni</div>

      <div class="settimana">
        <?php foreach ($ultimi7 as $g):
          $css = $g['oggi'] ? 'oggi' : ($g['stato'] ?? 'nessuno');
        ?>
          <div class="giorno-cell">
            <div class="giorno-nome"><?= substr($g['giorno'], 0, 3) ?></div>
            <div class="giorno-num <?= $css ?>"><?= $g['num'] ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="perc-bar-wrap">
        <div class="perc-label">
          <span>Percentuale presenze</span>
          <strong><?= $perc_presenze ?>%</strong>
        </div>
        <div class="perc-bar">
          <?php $cls = $perc_presenze >= 75 ? 'alta' : ($perc_presenze >= 50 ? 'media' : 'bassa'); ?>
          <div class="perc-fill <?= $cls ?>" style="width:<?= $perc_presenze ?>%"></div>
        </div>
      </div>

      <div class="filtri-storico">
        <?php
          $filtri = [
            'tutti'             => ['Tutti',             $totale_giorni],
            'presente'          => ['Presenze',          $stats['presente']],
            'assente'           => ['Assenze',           $stats['assente']],
            'ritardo'           => ['Ritardi',           $stats['ritardo']],
            'uscita_anticipata' => ['Uscite anticipate', $stats['uscita_anticipata']],
          ];
          foreach ($filtri as $key => [$label, $count]):
            $attivo = $filtro === $key ? 'attivo ' . $key : '';
        ?>
          <a href="?id=<?= $sid ?>&filtro=<?= $key ?>" class="filtro-btn <?= $attivo ?>">
            <?= $label ?><span class="filtro-count"><?= $count ?></span>
          </a>
        <?php endforeach; ?>
      </div>

      <?php if (empty($storico)): ?>
        <div style="color:var(--text-muted); font-size:13px; text-align:center; padding:20px 0;">
          Nessuna registrazione per questo filtro.
        </div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Data</th>
              <th>Stato</th>
              <th>Entrata</th>
              <th>Uscita</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($storico as $p): ?>
              <tr>
                <td><?= date('d/m/Y', strtotime($p['data'])) ?></td>
                <td><span class="badge badge-<?= $p['stato'] ?>"><?= ucfirst(str_replace('_', ' ', $p['stato'])) ?></span></td>
                <td class="time-cell"><?= $p['ora_entrata'] ? date('H:i', strtotime($p['ora_entrata'])) : '—' ?></td>
                <td class="time-cell"><?= $p['ora_uscita']  ? date('H:i', strtotime($p['ora_uscita']))  : '—' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div style="display:flex; flex-direction:column; gap:20px;">
      <div class="card">
        <div class="card-title">Situazione</div>
        <div class="mini-stats">
          <div class="mini-stat"><span class="mini-stat-label">Giorni totali</span><span class="mini-stat-val blue"><?= $totale_giorni ?></span></div>
          <div class="mini-stat"><span class="mini-stat-label">Presenze</span><span class="mini-stat-val green"><?= $stats['presente'] ?></span></div>
          <div class="mini-stat"><span class="mini-stat-label">Assenze</span><span class="mini-stat-val red"><?= $stats['assente'] ?></span></div>
          <div class="mini-stat"><span class="mini-stat-label">Ritardi</span><span class="mini-stat-val orange"><?= $stats['ritardo'] ?></span></div>
          <div class="mini-stat"><span class="mini-stat-label">Uscite anticipate</span><span class="mini-stat-val orange"><?= $stats['uscita_anticipata'] ?></span></div>
        </div>

        <?php if ($perc_presenze >= 75): ?>
          <div class="avviso ok"><strong>✅ Situazione regolare</strong>Lo studente ha una percentuale di presenze superiore al 75%.</div>
        <?php elseif ($perc_presenze >= 60): ?>
          <div class="avviso warning"><strong>⚠️ Attenzione</strong>Lo studente è al <?= $perc_presenze ?>% di presenze.</div>
        <?php else: ?>
          <div class="avviso danger"><strong>🚨 Situazione critica</strong>Lo studente è sotto il 75% di presenze.</div>
        <?php endif; ?>
      </div>
    </div>

  </div>

</main>

</body>
</html>
