<?php
session_start();
if (!isset($_SESSION['studente_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../includes/db.php';

$sid  = $_SESSION['studente_id'];
$oggi = date('Y-m-d');

// Dati studente
$stmt = $pdo->prepare("SELECT u.*, c.nome AS classe_nome FROM utenti u LEFT JOIN classi c ON c.id = u.classe_id WHERE u.id = ?");
$stmt->execute([$sid]);
$studente = $stmt->fetch();

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

// Storico ultime 30 presenze
$stmt = $pdo->prepare("SELECT * FROM presenze WHERE studente_id = ? ORDER BY data DESC, id DESC LIMIT 30");
$stmt->execute([$sid]);
$storico = $stmt->fetchAll();

// Ultimi 7 giorni (una sola query)
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
  <title>SchoolFaceID — La mia area</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg-deep:   #070d1a;
      --bg-card:   #0e1829;
      --bg-card2:  #111f33;
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

    .navbar {
      position: relative; z-index: 10;
      display: flex; align-items: center; justify-content: space-between;
      padding: 0 40px; height: 64px;
      background: rgba(7,13,26,0.85);
      backdrop-filter: blur(16px);
      border-bottom: 1px solid var(--border);
    }

    .nav-brand { display: flex; align-items: center; gap: 12px; }
    .nav-icon {
      width: 40px; height: 40px;
      background: linear-gradient(135deg, #1d3a6e, #2563eb);
      border-radius: 11px;
      display: flex; align-items: center; justify-content: center;
      font-size: 18px; box-shadow: 0 4px 16px rgba(37,99,235,0.3);
    }
    .nav-info strong { display: block; font-size: 16px; font-weight: 700; letter-spacing: -0.02em; }
    .nav-info span   { font-size: 12px; color: var(--text-muted); }

    .nav-right { display: flex; align-items: center; gap: 12px; }
    .btn-logout {
      padding: 7px 18px; background: transparent;
      border: 1px solid var(--border); border-radius: 8px;
      color: var(--text-muted); font-family: 'Sora', sans-serif;
      font-size: 13px; cursor: pointer; text-decoration: none; transition: all 0.2s;
    }
    .btn-logout:hover { border-color: rgba(255,255,255,0.15); color: var(--text-white); }

    .main {
      position: relative; z-index: 1;
      max-width: 1100px; margin: 0 auto;
      padding: 40px 40px 60px;
    }

    @keyframes fadeUp {
      from { opacity:0; transform:translateY(14px); }
      to   { opacity:1; transform:translateY(0); }
    }

    @keyframes slideIn {
      from { opacity:0; transform:translateX(20px); }
      to   { opacity:1; transform:translateX(0); }
    }

    /* HERO */
    .hero {
      display: flex; align-items: center; gap: 24px;
      background: var(--bg-card); border: 1px solid var(--border);
      border-radius: 20px; padding: 28px 32px;
      margin-bottom: 28px;
      animation: fadeUp 0.5s ease both;
    }

    .avatar-lg {
      width: 80px; height: 80px;
      background: linear-gradient(135deg, #1d3a6e, #2563eb);
      border-radius: 50%; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      font-size: 28px; font-weight: 700; color: #fff;
      overflow: hidden; border: 3px solid rgba(59,130,246,0.3);
    }
    .avatar-lg img { width: 100%; height: 100%; object-fit: cover; }

    .hero-info { flex: 1; }
    .hero-info h1 { font-size: 28px; font-weight: 700; letter-spacing: -0.03em; margin-bottom: 4px; }
    .hero-info .classe { font-size: 14px; color: var(--text-muted); margin-bottom: 10px; }

    .stato-oggi {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 6px 14px; border-radius: 20px;
      font-size: 13px; font-weight: 500;
    }
    .stato-oggi.presente  { background: rgba(34,197,94,0.1);  border: 1px solid rgba(34,197,94,0.25);  color: var(--green); }
    .stato-oggi.assente   { background: rgba(239,68,68,0.1);  border: 1px solid rgba(239,68,68,0.25);  color: var(--red); }
    .stato-oggi.ritardo   { background: rgba(249,115,22,0.1); border: 1px solid rgba(249,115,22,0.25); color: var(--orange); }
    .stato-oggi.nessuno   { background: rgba(100,116,139,0.1);border: 1px solid rgba(100,116,139,0.25);color: #64748b; }

    .hero-right { text-align: right; }
    .data-oggi { font-family: 'JetBrains Mono', monospace; font-size: 13px; color: var(--text-muted); }

    /* STATS */
    .stats {
      display: grid; grid-template-columns: repeat(4, 1fr);
      gap: 14px; margin-bottom: 28px;
      animation: fadeUp 0.5s ease 0.05s both;
    }

    .stat-card {
      background: var(--bg-card); border: 1px solid var(--border);
      border-radius: 14px; padding: 20px 22px;
      position: relative; overflow: hidden;
    }
    .stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; }
    .stat-card.blue::before   { background: var(--blue); }
    .stat-card.green::before  { background: var(--green); }
    .stat-card.red::before    { background: var(--red); }
    .stat-card.orange::before { background: var(--orange); }

    .stat-label {
      font-size: 11px; font-family: 'JetBrains Mono', monospace;
      letter-spacing: 0.1em; text-transform: uppercase; margin-bottom: 8px;
    }
    .stat-card.blue .stat-label   { color: var(--blue); }
    .stat-card.green .stat-label  { color: var(--green); }
    .stat-card.red .stat-label    { color: var(--red); }
    .stat-card.orange .stat-label { color: var(--orange); }

    .stat-value { font-size: 34px; font-weight: 700; letter-spacing: -0.04em; }
    .stat-sub   { font-size: 11px; color: var(--text-dim); margin-top: 4px; font-family: 'JetBrains Mono', monospace; }

    /* GRIGLIA */
    .grid-main {
      display: grid; grid-template-columns: 1fr 340px;
      gap: 20px;
      animation: fadeUp 0.5s ease 0.1s both;
    }

    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; padding: 28px; }
    .card-title { font-size: 16px; font-weight: 600; margin-bottom: 20px; letter-spacing: -0.02em; }

    /* Barra % */
    .perc-bar-wrap { margin-bottom: 24px; }
    .perc-label { display: flex; justify-content: space-between; font-size: 13px; color: var(--text-muted); margin-bottom: 8px; }
    .perc-label strong { color: var(--text-white); }
    .perc-bar { height: 10px; background: var(--bg-card2); border-radius: 10px; overflow: hidden; }
    .perc-fill { height: 100%; border-radius: 10px; transition: width 1s ease; }
    .perc-fill.alta   { background: var(--green); }
    .perc-fill.media  { background: var(--orange); }
    .perc-fill.bassa  { background: var(--red); }

    /* Ultimi 7 giorni */
    .settimana { display: grid; grid-template-columns: repeat(7, 1fr); gap: 8px; margin-bottom: 24px; }
    .giorno-cell { text-align: center; }
    .giorno-nome { font-size: 10px; font-family: 'JetBrains Mono', monospace; color: var(--text-dim); text-transform: uppercase; margin-bottom: 6px; }
    .giorno-num {
      width: 36px; height: 36px; margin: 0 auto;
      border-radius: 50%; display: flex; align-items: center; justify-content: center;
      font-size: 13px; font-weight: 600; border: 2px solid transparent;
    }
    .giorno-num.oggi     { border-color: var(--blue); color: var(--blue); }
    .giorno-num.presente { background: rgba(34,197,94,0.2);  color: var(--green); }
    .giorno-num.assente  { background: rgba(239,68,68,0.2);  color: var(--red); }
    .giorno-num.ritardo  { background: rgba(249,115,22,0.2); color: var(--orange); }
    .giorno-num.nessuno  { background: var(--bg-card2); color: var(--text-dim); }

    /* Tabella */
    table { width: 100%; border-collapse: collapse; }
    thead th {
      padding: 10px 14px; text-align: left;
      font-size: 10px; font-family: 'JetBrains Mono', monospace;
      letter-spacing: 0.1em; text-transform: uppercase;
      color: var(--text-dim); border-bottom: 1px solid var(--border);
    }
    tbody tr { border-bottom: 1px solid var(--border); transition: background 0.15s; }
    tbody tr:last-child { border-bottom: none; }
    tbody tr:hover { background: rgba(255,255,255,0.02); }
    tbody td { padding: 11px 14px; font-size: 13px; color: var(--text-muted); }

    .badge {
      display: inline-flex; align-items: center; gap: 5px;
      font-size: 11px; font-weight: 500; padding: 3px 10px; border-radius: 20px;
    }
    .badge::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; }
    .badge-presente          { background: rgba(34,197,94,0.12);  color: var(--green); }
    .badge-assente           { background: rgba(239,68,68,0.12);  color: var(--red); }
    .badge-ritardo           { background: rgba(249,115,22,0.12); color: var(--orange); }
    .badge-uscita_anticipata { background: rgba(234,179,8,0.12);  color: var(--yellow); }

    .time-cell { font-family: 'JetBrains Mono', monospace; font-size: 12px; color: var(--text-white); }

    /* Mini stats */
    .mini-stats { display: flex; flex-direction: column; gap: 12px; margin-bottom: 20px; }
    .mini-stat {
      display: flex; align-items: center; justify-content: space-between;
      background: var(--bg-card2); border: 1px solid var(--border);
      border-radius: 10px; padding: 12px 16px;
    }
    .mini-stat-label { font-size: 13px; color: var(--text-muted); }
    .mini-stat-val   { font-size: 16px; font-weight: 700; }
    .mini-stat-val.green  { color: var(--green); }
    .mini-stat-val.red    { color: var(--red); }
    .mini-stat-val.orange { color: var(--orange); }
    .mini-stat-val.blue   { color: var(--blue); }

    /* Avviso */
    .avviso { border-radius: 12px; padding: 14px 16px; font-size: 13px; line-height: 1.5; }
    .avviso.ok      { background: rgba(34,197,94,0.07);  border: 1px solid rgba(34,197,94,0.2);  color: #86efac; }
    .avviso.warning { background: rgba(249,115,22,0.07); border: 1px solid rgba(249,115,22,0.2); color: #fdba74; }
    .avviso.danger  { background: rgba(239,68,68,0.07);  border: 1px solid rgba(239,68,68,0.2);  color: #fca5a5; }
    .avviso strong  { display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.05em; }
  </style>
</head>
<body>

<nav class="navbar">
  <div class="nav-brand">
    <div class="nav-icon">🎓</div>
    <div class="nav-info">
      <strong>SchoolFaceID</strong>
      <span>Area studente — <?= htmlspecialchars($_SESSION['studente_nome']) ?></span>
    </div>
  </div>
  <div class="nav-right">
    <a href="logout.php" class="btn-logout">Logout</a>
  </div>
</nav>

<main class="main">

  <!-- HERO -->
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
      <?php
        $css_stato  = $stato_oggi ?? 'nessuno';
        $icone      = ['presente' => '✅', 'assente' => '❌', 'ritardo' => '⏰'];
        $icona      = $icone[$stato_oggi] ?? '—';
        $testo      = $stato_oggi ? ucfirst($stato_oggi) : 'Non ancora rilevato';
      ?>
      <div id="stato-oggi" class="stato-oggi <?= $css_stato ?>">
        <?= $icona ?> Oggi: <?= $testo ?>
        <?php if ($presenza_oggi && $presenza_oggi['ora_entrata']): ?>
          — entrata alle <?= date('H:i', strtotime($presenza_oggi['ora_entrata'])) ?>
        <?php endif; ?>
      </div>
    </div>
    <div class="hero-right">
      <div class="data-oggi"><?= date('d/m/Y') ?></div>
    </div>
  </div>

  <!-- STATS -->
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

  <!-- GRIGLIA -->
  <div class="grid-main">

    <div class="card">
      <div class="card-title">Storico presenze</div>

      <!-- Ultimi 7 giorni -->
      <div class="settimana">
        <?php foreach ($ultimi7 as $g):
          $css = $g['oggi'] ? 'oggi' : ($g['stato'] ?? 'nessuno');
        ?>
          <div class="giorno-cell">
            <div class="giorno-nome"><?= substr($g['giorno'], 0, 3) ?></div>
            <div <?= $g['oggi'] ? 'id="giorno-oggi"' : '' ?> class="giorno-num <?= $css ?>"><?= $g['num'] ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Barra % -->
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

      <!-- Tabella -->
      <?php if (empty($storico)): ?>
        <div style="color:var(--text-muted); font-size:13px; text-align:center; padding:20px 0;">
          Nessuna presenza registrata.
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

    <!-- Colonna destra -->
    <div style="display:flex; flex-direction:column; gap:20px;">
      <div class="card">
        <div class="card-title">La tua situazione</div>
        <div class="mini-stats">
          <div class="mini-stat">
            <span class="mini-stat-label">Giorni totali</span>
            <span class="mini-stat-val blue"><?= $totale_giorni ?></span>
          </div>
          <div class="mini-stat">
            <span class="mini-stat-label">Presenze</span>
            <span class="mini-stat-val green"><?= $stats['presente'] ?></span>
          </div>
          <div class="mini-stat">
            <span class="mini-stat-label">Assenze</span>
            <span class="mini-stat-val red"><?= $stats['assente'] ?></span>
          </div>
          <div class="mini-stat">
            <span class="mini-stat-label">Ritardi</span>
            <span class="mini-stat-val orange"><?= $stats['ritardo'] ?></span>
          </div>
          <div class="mini-stat">
            <span class="mini-stat-label">Uscite anticipate</span>
            <span class="mini-stat-val orange"><?= $stats['uscita_anticipata'] ?></span>
          </div>
        </div>

        <?php if ($perc_presenze >= 75): ?>
          <div class="avviso ok">
            <strong>✅ Situazione regolare</strong>
            Hai una percentuale di presenze superiore al 75%. Continua così!
          </div>
        <?php elseif ($perc_presenze >= 60): ?>
          <div class="avviso warning">
            <strong>⚠️ Attenzione</strong>
            Sei al <?= $perc_presenze ?>% di presenze. Fai attenzione a non scendere sotto il 75%.
          </div>
        <?php else: ?>
          <div class="avviso danger">
            <strong>🚨 Situazione critica</strong>
            Sei sotto il 75% di presenze. Parla con il tuo docente il prima possibile.
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

</main>

<script>
  const MIO_ID  = <?= (int)$sid ?>;
  const ICONE   = { presente: '✅', ritardo: '⏰', uscita_anticipata: '⚠️', assente: '❌' };
  let   sseTs   = Math.floor(Date.now() / 1000);

  function collegaSSE() {
    const es = new EventSource('../api/eventi.php?since=' + sseTs);

    es.onmessage = function(e) {
      let d;
      try { d = JSON.parse(e.data); } catch { return; }

      if (d.tipo === 'reconnect') { es.close(); setTimeout(collegaSSE, 1000); return; }
      if (d.studente_id !== MIO_ID) return;
      if (d.azione !== 'entrata_registrata' && d.azione !== 'uscita_registrata') return;

      if (d.timestamp) sseTs = d.timestamp;
      mostraNotifica(d);
      aggiornaUI(d);
    };

    es.onerror = function() { es.close(); setTimeout(collegaSSE, 3000); };
  }

  function mostraNotifica(d) {
    const colori = {
      'entrata_registrata': '#22c55e',
      'uscita_registrata':  '#3b82f6',
    };
    const etichette = {
      'entrata_registrata': '✅ Entrata registrata',
      'uscita_registrata':  '🚪 Uscita registrata',
    };

    const colore    = colori[d.azione]    || '#6b7fa3';
    const etichetta = etichette[d.azione] || d.azione.replace(/_/g, ' ');
    const ora       = d.ora ? d.ora.substring(0, 5) : '--:--';

    const box = document.createElement('div');
    box.style.cssText = `
      position:fixed; top:80px; right:24px; z-index:9999;
      background:#0e1829; border:1px solid ${colore};
      border-radius:12px; padding:14px 18px;
      font-family:'Sora',sans-serif; font-size:14px;
      color:#f0f6ff; box-shadow:0 8px 32px rgba(0,0,0,0.4);
      animation:slideIn 0.3s ease; max-width:300px;
    `;
    box.innerHTML = `
      <div style="font-size:11px;color:${colore};font-weight:600;margin-bottom:4px;text-transform:uppercase;letter-spacing:0.05em">${etichetta}</div>
      <div style="font-weight:600">Sei stato riconosciuto!</div>
      <div style="font-size:12px;color:#6b7fa3;margin-top:2px">alle ${ora}</div>
    `;
    document.body.appendChild(box);
    setTimeout(() => {
      box.style.opacity = '0';
      box.style.transition = 'opacity 0.3s';
      setTimeout(() => box.remove(), 300);
    }, 4000);
  }

  function aggiornaUI(d) {
    const stato = d.stato || 'presente';
    const ora   = d.ora ? d.ora.substring(0, 5) : '';
    const icona = ICONE[stato] || '✅';
    const testo = stato.charAt(0).toUpperCase() + stato.slice(1);

    // Badge hero
    const badge = document.getElementById('stato-oggi');
    if (badge) {
      badge.className = 'stato-oggi ' + stato;
      badge.innerHTML = icona + ' Oggi: ' + testo + (ora ? ' — entrata alle ' + ora : '');
    }

    // Cella oggi nella settimana
    const oggiCell = document.getElementById('giorno-oggi');
    if (oggiCell) {
      oggiCell.className = 'giorno-num ' + stato;
    }
  }

  collegaSSE();
</script>

</body>
</html>
