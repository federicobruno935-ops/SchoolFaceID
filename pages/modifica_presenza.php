<?php
session_start();
if (!isset($_SESSION['utente_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../includes/db.php';

$successo = '';
$errore   = '';

$studente_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$data_sel    = $_GET['data'] ?? date('Y-m-d');
// Valida formato data
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_sel)) {
    $data_sel = date('Y-m-d');
}

if (!$studente_id) {
    header('Location: registro.php');
    exit;
}

// Dati studente — verifica che sia effettivamente uno studente
$stmt = $pdo->prepare("SELECT u.*, c.nome AS classe_nome FROM utenti u LEFT JOIN classi c ON c.id = u.classe_id WHERE u.id = ? AND u.ruolo = 'studente' AND u.attivo = 1");
$stmt->execute([$studente_id]);
$studente = $stmt->fetch();

if (!$studente) {
    header('Location: registro.php');
    exit;
}

// Presenza esistente per la data
$stmt = $pdo->prepare("SELECT * FROM presenze WHERE studente_id = ? AND data = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$studente_id, $data_sel]);
$presenza = $stmt->fetch();

// SALVA MODIFICHE
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stati_validi = ['presente', 'assente', 'ritardo', 'uscita_anticipata'];
    $stato        = in_array($_POST['stato'] ?? '', $stati_validi) ? $_POST['stato'] : 'assente';
    $ora_entrata  = $_POST['ora_entrata']  ?: null;
    $ora_uscita   = $_POST['ora_uscita']   ?: null;
    $note         = substr($_POST['note'] ?? '', 0, 500);

    if ($presenza) {
        // Aggiorna presenza esistente
        $stmt = $pdo->prepare("
            UPDATE presenze 
            SET stato = ?, ora_entrata = ?, ora_uscita = ?, note = ?, rilevato_da = 'manuale'
            WHERE id = ?
        ");
        $stmt->execute([$stato, $ora_entrata, $ora_uscita, $note, $presenza['id']]);
    } else {
        // Crea nuova presenza manuale
        $stmt = $pdo->prepare("
            INSERT INTO presenze (studente_id, data, ora_entrata, ora_uscita, stato, note, rilevato_da)
            VALUES (?, ?, ?, ?, ?, ?, 'manuale')
        ");
        $stmt->execute([$studente_id, $data_sel, $ora_entrata, $ora_uscita, $stato, $note]);
    }

    $successo = 'Presenza aggiornata con successo.';

    // Ricarica presenza aggiornata
    $stmt = $pdo->prepare("SELECT * FROM presenze WHERE studente_id = ? AND data = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$studente_id, $data_sel]);
    $presenza = $stmt->fetch();
}

$iniziali = strtoupper(substr($studente['nome'],0,1) . substr($studente['cognome'],0,1));
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>SchoolFaceID — Modifica Presenza</title>
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

    .nav-links { display: flex; gap: 4px; }
    .nav-link {
      padding: 7px 16px; border-radius: 8px;
      font-size: 13px; font-weight: 500;
      color: var(--text-muted); text-decoration: none; transition: all 0.2s;
    }
    .nav-link:hover  { background: rgba(255,255,255,0.05); color: var(--text-white); }
    .nav-link.active { background: rgba(59,130,246,0.15); color: var(--blue); }

    .btn-logout {
      padding: 7px 18px; background: transparent;
      border: 1px solid var(--border); border-radius: 8px;
      color: var(--text-muted); font-family: 'Sora', sans-serif;
      font-size: 13px; cursor: pointer; text-decoration: none; transition: all 0.2s;
    }
    .btn-logout:hover { border-color: rgba(255,255,255,0.15); color: var(--text-white); }

    /* MAIN */
    .main {
      position: relative; z-index: 1;
      max-width: 700px; margin: 0 auto;
      padding: 40px 40px 60px;
    }

    @keyframes fadeUp {
      from { opacity:0; transform:translateY(14px); }
      to   { opacity:1; transform:translateY(0); }
    }

    /* BREADCRUMB */
    .breadcrumb {
      display: flex; align-items: center; gap: 8px;
      font-size: 13px; color: var(--text-muted);
      margin-bottom: 28px;
      animation: fadeUp 0.4s ease both;
    }
    .breadcrumb a { color: var(--text-muted); text-decoration: none; transition: color 0.2s; }
    .breadcrumb a:hover { color: var(--blue); }
    .breadcrumb span { color: var(--text-dim); }

    /* CARD STUDENTE */
    .studente-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 16px; padding: 24px;
      display: flex; align-items: center; gap: 20px;
      margin-bottom: 24px;
      animation: fadeUp 0.4s ease 0.05s both;
    }

    .avatar {
      width: 64px; height: 64px;
      background: linear-gradient(135deg, #1d3a6e, #2563eb);
      border-radius: 50%; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      font-size: 22px; font-weight: 600; color: #fff;
      overflow: hidden; border: 2px solid var(--border);
    }
    .avatar img { width: 100%; height: 100%; object-fit: cover; }

    .studente-info strong {
      display: block; font-size: 20px; font-weight: 700;
      letter-spacing: -0.02em; margin-bottom: 4px;
    }
    .studente-info span { font-size: 13px; color: var(--text-muted); }

    .data-badge {
      margin-left: auto;
      background: var(--bg-card2); border: 1px solid var(--border);
      border-radius: 10px; padding: 10px 16px; text-align: right;
    }
    .data-badge .label { font-size: 11px; color: var(--text-dim); font-family: 'JetBrains Mono', monospace; text-transform: uppercase; margin-bottom: 4px; }
    .data-badge .value { font-size: 15px; font-weight: 600; color: var(--text-white); }

    /* ALERT */
    .alert {
      padding: 13px 18px; border-radius: 10px;
      font-size: 13px; margin-bottom: 24px;
      animation: fadeUp 0.3s ease both;
    }
    .alert-success { background: rgba(34,197,94,0.1);  border: 1px solid rgba(34,197,94,0.25);  color: #86efac; }
    .alert-error   { background: rgba(239,68,68,0.1);  border: 1px solid rgba(239,68,68,0.25);  color: #fca5a5; }

    /* FORM */
    .form-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 16px; padding: 32px;
      animation: fadeUp 0.4s ease 0.1s both;
    }

    .form-card-title {
      font-size: 18px; font-weight: 600;
      letter-spacing: -0.02em; margin-bottom: 24px;
    }

    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
    .form-full { grid-column: 1 / -1; }

    .form-group { display: flex; flex-direction: column; gap: 7px; }
    .form-group label { font-size: 12px; font-weight: 500; color: var(--text-white); }

    .form-group input,
    .form-group select,
    .form-group textarea {
      background: var(--bg-input); border: 1px solid var(--border);
      border-radius: 9px; padding: 11px 14px;
      font-family: 'Sora', sans-serif; font-size: 13px;
      color: var(--text-white); outline: none;
      transition: border-color 0.2s;
    }
    .form-group input[type="time"] { color-scheme: dark; }
    .form-group input::placeholder,
    .form-group textarea::placeholder { color: var(--text-dim); }
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
      border-color: rgba(59,130,246,0.5);
      box-shadow: 0 0 0 3px rgba(59,130,246,0.08);
    }
    .form-group select option { background: #0e1829; }
    .form-group textarea { resize: vertical; min-height: 80px; }

    /* Stato selector */
    .stato-selector {
      display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px;
    }

    .stato-option { display: none; }
    .stato-label {
      display: flex; flex-direction: column; align-items: center; gap: 6px;
      padding: 14px 8px; border-radius: 10px;
      border: 1px solid var(--border);
      cursor: pointer; transition: all 0.2s;
      font-size: 12px; font-weight: 500;
      color: var(--text-muted); text-align: center;
    }
    .stato-label:hover { border-color: rgba(255,255,255,0.15); color: var(--text-white); }

    .stato-option:checked + .stato-label { color: var(--text-white); }

    #stato_presente:checked + .stato-label  { background: rgba(34,197,94,0.1);  border-color: var(--green);  color: var(--green); }
    #stato_assente:checked + .stato-label   { background: rgba(239,68,68,0.1);  border-color: var(--red);    color: var(--red); }
    #stato_ritardo:checked + .stato-label   { background: rgba(249,115,22,0.1); border-color: var(--orange); color: var(--orange); }
    #stato_uscita:checked + .stato-label    { background: rgba(234,179,8,0.1);  border-color: var(--yellow); color: var(--yellow); }

    .stato-icon { font-size: 20px; }

    /* Footer form */
    .form-footer {
      display: flex; gap: 12px; margin-top: 28px;
      padding-top: 24px; border-top: 1px solid var(--border);
    }

    .btn-submit {
      flex: 1; padding: 13px;
      background: var(--blue); color: #fff;
      font-family: 'Sora', sans-serif; font-size: 14px; font-weight: 600;
      border: none; border-radius: 10px; cursor: pointer;
      transition: background 0.2s; box-shadow: 0 4px 16px rgba(59,130,246,0.25);
    }
    .btn-submit:hover { background: #2563eb; }

    .btn-cancel {
      padding: 13px 24px;
      background: transparent; border: 1px solid var(--border);
      border-radius: 10px; color: var(--text-muted);
      font-family: 'Sora', sans-serif; font-size: 14px; cursor: pointer;
      text-decoration: none; transition: all 0.2s;
    }
    .btn-cancel:hover { border-color: rgba(255,255,255,0.15); color: var(--text-white); }

    /* Info rilevamento */
    .rilevamento-info {
      display: flex; align-items: center; gap: 8px;
      font-size: 12px; color: var(--text-muted);
      margin-top: 12px;
    }
    .source-badge {
      font-size: 11px; font-family: 'JetBrains Mono', monospace;
      padding: 3px 8px; border-radius: 6px;
    }
    .source-facciale { background: rgba(59,130,246,0.1); color: var(--blue); }
    .source-manuale  { background: rgba(100,116,139,0.1); color: #64748b; }
  </style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
  <div class="nav-brand">
    <div class="nav-icon">🎓</div>
    <div class="nav-info">
      <strong>SchoolFaceID</strong>
      <span>Dashboard docente &bull; <?= htmlspecialchars($_SESSION['utente_nome']) ?></span>
    </div>
  </div>
  <div class="nav-links">
    <a href="dashboard.php" class="nav-link">Dashboard</a>
    <a href="registro.php"  class="nav-link active">Registro</a>
    <a href="studenti.php"  class="nav-link">Studenti</a>
  </div>
  <div>
    <a href="logout.php" class="btn-logout">Logout</a>
  </div>
</nav>

<main class="main">

  <!-- BREADCRUMB -->
  <div class="breadcrumb">
    <a href="registro.php">Registro</a>
    <span>›</span>
    <a href="registro.php?data=<?= $data_sel ?>">
      <?= date('d/m/Y', strtotime($data_sel)) ?>
    </a>
    <span>›</span>
    <span style="color:var(--text-white)">Modifica presenza</span>
  </div>

  <!-- CARD STUDENTE -->
  <div class="studente-card">
    <div class="avatar">
      <?php if ($studente['foto_path']): ?>
        <img src="../<?= htmlspecialchars(foto_path_sicuro($studente['foto_path'])) ?>" alt="">
      <?php else: ?>
        <?= $iniziali ?>
      <?php endif; ?>
    </div>
    <div class="studente-info">
      <strong><?= htmlspecialchars($studente['nome'] . ' ' . $studente['cognome']) ?></strong>
      <span><?= htmlspecialchars($studente['classe_nome'] ?? 'Nessuna classe') ?></span>
    </div>
    <div class="data-badge">
      <div class="label">Data</div>
      <div class="value"><?= date('d/m/Y', strtotime($data_sel)) ?></div>
    </div>
  </div>

  <?php if ($successo): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successo) ?></div>
  <?php endif; ?>

  <!-- FORM -->
  <div class="form-card">
    <div class="form-card-title">
      <?= $presenza ? 'Modifica presenza' : 'Inserisci presenza manuale' ?>
    </div>

    <form method="POST" action="">
      <div class="form-grid">

        <!-- STATO -->
        <div class="form-group form-full">
          <label>Stato</label>
          <div class="stato-selector">
            <?php
              $stato_corrente = $presenza['stato'] ?? 'assente';
            ?>
            <input type="radio" name="stato" id="stato_presente" value="presente" class="stato-option"
                   <?= $stato_corrente === 'presente' ? 'checked' : '' ?>>
            <label for="stato_presente" class="stato-label">
              <span class="stato-icon">✅</span> Presente
            </label>

            <input type="radio" name="stato" id="stato_assente" value="assente" class="stato-option"
                   <?= $stato_corrente === 'assente' ? 'checked' : '' ?>>
            <label for="stato_assente" class="stato-label">
              <span class="stato-icon">❌</span> Assente
            </label>

            <input type="radio" name="stato" id="stato_ritardo" value="ritardo" class="stato-option"
                   <?= $stato_corrente === 'ritardo' ? 'checked' : '' ?>>
            <label for="stato_ritardo" class="stato-label">
              <span class="stato-icon">⏰</span> Ritardo
            </label>

            <input type="radio" name="stato" id="stato_uscita" value="uscita_anticipata" class="stato-option"
                   <?= $stato_corrente === 'uscita_anticipata' ? 'checked' : '' ?>>
            <label for="stato_uscita" class="stato-label">
              <span class="stato-icon">🚪</span> Uscita anticipata
            </label>
          </div>
        </div>

        <!-- ORA ENTRATA -->
        <div class="form-group">
          <label>Ora entrata</label>
          <input type="time" name="ora_entrata"
                 value="<?= $presenza['ora_entrata'] ? substr($presenza['ora_entrata'], 0, 5) : '' ?>"/>
        </div>

        <!-- ORA USCITA -->
        <div class="form-group">
          <label>Ora uscita</label>
          <input type="time" name="ora_uscita"
                 value="<?= $presenza['ora_uscita'] ? substr($presenza['ora_uscita'], 0, 5) : '' ?>"/>
        </div>

        <!-- NOTE -->
        <div class="form-group form-full">
          <label>Note (opzionale)</label>
          <textarea name="note" placeholder="Es. Certificato medico, permesso..."><?= htmlspecialchars($presenza['note'] ?? '') ?></textarea>
        </div>

      </div>

      <?php if ($presenza && $presenza['rilevato_da']): ?>
        <div class="rilevamento-info">
          Rilevato da:
          <span class="source-badge source-<?= $presenza['rilevato_da'] ?>">
            <?= $presenza['rilevato_da'] === 'facciale' ? '📷 facciale' : '✏️ manuale' ?>
          </span>
          <?php if ($presenza['ora_entrata']): ?>
            alle <?= date('H:i', strtotime($presenza['ora_entrata'])) ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="form-footer">
        <a href="registro.php?data=<?= $data_sel ?>" class="btn-cancel">Annulla</a>
        <button type="submit" class="btn-submit">Salva modifiche</button>
      </div>
    </form>
  </div>

</main>
</body>
</html>
