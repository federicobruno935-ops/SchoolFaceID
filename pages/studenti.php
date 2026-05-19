<?php
session_start();
if (!isset($_SESSION['utente_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../includes/db.php';

$successo = '';
$errore   = '';

// AGGIUNTA STUDENTE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['azione']) && $_POST['azione'] === 'aggiungi') {
    $nome      = trim($_POST['nome'] ?? '');
    $cognome   = trim($_POST['cognome'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $classe_id = $_POST['classe_id'] ?? null;
    $foto_path = null;

    if ($nome && $cognome) {
        // Validazione email
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errore = 'Indirizzo email non valido.';
        }

        // Upload foto
        if (!$errore && !empty($_FILES['foto']['name'])) {
            $upload_dir = __DIR__ . '/../uploads/studenti/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $ext      = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
            $permessi = ['jpg','jpeg','png'];

            if (in_array($ext, $permessi) && $_FILES['foto']['size'] < 5 * 1024 * 1024) {
                $filename = uniqid('stu_') . '.' . $ext;
                $dest     = $upload_dir . $filename;
                if (move_uploaded_file($_FILES['foto']['tmp_name'], $dest)) {
                    $foto_path = 'uploads/studenti/' . $filename;
                }
            } else {
                $errore = 'Foto non valida. Usa JPG o PNG, max 5MB.';
            }
        }

        if (!$errore) {
            // Password default: Nome1234 — lo studente può cambiarla con "password dimenticata"
            $password_default = ucfirst(strtolower($nome)) . '1234';
            $password_hash    = password_hash($password_default, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("INSERT INTO utenti (nome, cognome, email, password_hash, ruolo, classe_id, foto_path, attivo) VALUES (?, ?, ?, ?, 'studente', ?, ?, 1)");
            $stmt->execute([$nome, $cognome, $email ?: null, $password_hash, $classe_id ?: null, $foto_path]);
            $successo = "Studente $nome $cognome aggiunto. Password temporanea: <strong>{$password_default}</strong>";
        }
    } else {
        $errore = 'Nome e cognome sono obbligatori.';
    }
}


// Lista studenti
$cerca      = $_GET['cerca'] ?? '';
$classe_sel = $_GET['classe'] ?? '';
$classi     = $pdo->query("SELECT * FROM classi ORDER BY nome")->fetchAll();

$where  = ["u.ruolo = 'studente'", "u.attivo = 1"];
$params = [];

if ($cerca) {
    $where[]          = "(u.nome LIKE :cerca OR u.cognome LIKE :cerca)";
    $params[':cerca'] = "%$cerca%";
}
if ($classe_sel) {
    $where[]              = "u.classe_id = :classe_id";
    $params[':classe_id'] = $classe_sel;
}

$where_sql = implode(' AND ', $where);
$stmt      = $pdo->prepare("
    SELECT u.*, c.nome AS classe_nome,
           (SELECT COUNT(*) FROM presenze p WHERE p.studente_id = u.id AND p.stato = 'presente') AS tot_presenze
    FROM utenti u
    LEFT JOIN classi c ON c.id = u.classe_id
    WHERE $where_sql
    ORDER BY u.cognome, u.nome
");
$stmt->execute($params);
$studenti = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>SchoolFaceID — Studenti</title>
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
      width: 36px; height: 36px;
      background: linear-gradient(135deg, #1e40af, #3b82f6);
      border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: 16px; box-shadow: 0 0 20px rgba(59,130,246,0.35);
    }
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
      transition: border-color 0.2s, transform 0.2s;
      position: relative;
    }
    .studente-card:hover {
      border-color: rgba(255,255,255,0.12);
      transform: translateY(-2px);
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
    <div class="nav-logo">🎓</div>
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
  <div>
    <a href="logout.php" class="btn-logout">Esci</a>
  </div>
</nav>

<main class="main">

  <div class="page-header">
    <div>
      <h1>Gestione studenti</h1>
      <p class="page-subtitle"><?= count($studenti) ?> studenti registrati nel sistema</p>
    </div>
    <button class="btn-nuovo" onclick="document.getElementById('modalAggiungi').classList.add('open')">
      + Nuovo studente
    </button>
  </div>

  <?php if ($successo): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successo) ?></div>
  <?php endif; ?>
  <?php if ($errore): ?>
    <div class="alert alert-error"><?= htmlspecialchars($errore) ?></div>
  <?php endif; ?>

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
      <button type="submit" class="btn-filter">Filtra</button>
    </div>
  </form>

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
      ?>
      <div class="studente-card">
        <?php if (!$s['foto_path']): ?>
          <span class="no-foto-badge">no foto</span>
        <?php endif; ?>

        <div class="avatar">
          <?php if ($s['foto_path']): ?>
            <img src="../<?= htmlspecialchars(foto_path_sicuro($s['foto_path'])) ?>?v=<?= filemtime(dirname(__DIR__).'/'.foto_path_sicuro($s['foto_path'])) ?>" alt="">
          <?php else: ?>
            <?= $iniziali ?>
          <?php endif; ?>
        </div>

        <div class="studente-nome"><?= htmlspecialchars($s['cognome'] . ' ' . $s['nome']) ?></div>
        <div class="studente-classe"><?= htmlspecialchars($s['classe_nome'] ?? 'Nessuna classe') ?></div>
        <div class="presenze-count">
          Presenze: <strong><?= $s['tot_presenze'] ?></strong>
        </div>

      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</main>

<!-- MODAL AGGIUNGI STUDENTE -->
<div class="modal-overlay" id="modalAggiungi">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Nuovo studente</span>
      <button class="modal-close" onclick="document.getElementById('modalAggiungi').classList.remove('open')">✕</button>
    </div>

    <form method="POST" action="" enctype="multipart/form-data">
      <input type="hidden" name="azione" value="aggiungi"/>
      <div class="form-grid">
        <div class="form-group">
          <label>Nome *</label>
          <input type="text" name="nome" placeholder="Mario" required/>
        </div>
        <div class="form-group">
          <label>Cognome *</label>
          <input type="text" name="cognome" placeholder="Rossi" required/>
        </div>
        <div class="form-group">
          <label>Email</label>
          <input type="email" name="email" placeholder="mario@scuola.it"/>
        </div>
        <div class="form-group">
          <label>Classe</label>
          <select name="classe_id">
            <option value="">Nessuna</option>
            <?php foreach ($classi as $c): ?>
              <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group form-full">
          <label>Foto (per riconoscimento facciale)</label>
          <div class="upload-area" id="uploadArea">
            <input type="file" name="foto" accept="image/jpeg,image/png" onchange="aggiornaFoto(this)"/>
            <div class="upload-icon" id="uploadIcon">📷</div>
            <div class="upload-text" id="uploadText"><strong>Clicca</strong> per caricare una foto</div>
            <div class="upload-hint">JPG o PNG · max 5MB · 1 volto visibile</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="document.getElementById('modalAggiungi').classList.remove('open')">
          Annulla
        </button>
        <button type="submit" class="btn-submit">Aggiungi studente</button>
      </div>
    </form>
  </div>
</div>

<script>
  function aggiornaFoto(input) {
    if (input.files && input.files[0]) {
      const nome = input.files[0].name;
      document.getElementById('uploadIcon').textContent = '✅';
      document.getElementById('uploadText').innerHTML = '<strong>' + nome + '</strong>';
    }
  }

  // Chiudi modal cliccando fuori
  document.getElementById('modalAggiungi').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
  });

  <?php if ($errore && isset($_POST['azione'])): ?>
    document.getElementById('modalAggiungi').classList.add('open');
  <?php endif; ?>
</script>

</body>
</html>
