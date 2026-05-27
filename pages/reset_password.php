<?php
session_start();
if (isset($_SESSION['utente_id'])) { header('Location: dashboard.php'); exit; }

require_once '../includes/db.php';
require_once '../includes/mailer.php';

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$token   = trim($_GET['token'] ?? '');
$record  = $token ? valida_token_reset($pdo, $token) : null;
$errore  = '';
$success = false;

if (!$token || !$record) {
    $errore = 'Link non valido o scaduto. Richiedi un nuovo link.';
}

if ($record && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errore = 'Richiesta non valida. Ricarica la pagina.';
    } else {
        $nuova      = $_POST['password']    ?? '';
        $conferma   = $_POST['conferma']    ?? '';

        if (strlen($nuova) < 8) {
            $errore = 'La password deve essere di almeno 8 caratteri.';
        } elseif ($nuova !== $conferma) {
            $errore = 'Le password non coincidono.';
        } else {
            consuma_token_reset($pdo, $token, $nuova);
            $success = true;
        }
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    // Ri-valida il token (potrebbe essere stato consumato)
    if (!$success) $record = valida_token_reset($pdo, $token);
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>SchoolFaceID — Nuova Password</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
    :root {
      --bg-deep:#070d1a; --bg-card:#0e1829; --bg-input:#0a1322;
      --border:rgba(255,255,255,0.07); --border-focus:rgba(59,130,246,0.6);
      --blue:#3b82f6; --blue-hover:#2563eb; --green:#22c55e;
      --text-white:#f0f6ff; --text-muted:#6b7fa3; --text-dim:#3d5070;
    }
    html,body { height:100%; font-family:'Sora',sans-serif; background:var(--bg-deep); color:var(--text-white); }
    body::before { content:''; position:fixed; inset:0; background:radial-gradient(ellipse 80% 60% at 20% 50%,rgba(30,58,138,0.25) 0%,transparent 70%); pointer-events:none; z-index:0; }
    body::after { content:''; position:fixed; inset:0; background-image:radial-gradient(rgba(59,130,246,0.06) 1px,transparent 1px); background-size:32px 32px; pointer-events:none; z-index:0; }
    .page { position:relative; z-index:1; display:flex; align-items:center; justify-content:center; min-height:100vh; padding:24px; }
    .card { background:var(--bg-card); border:1px solid var(--border); border-radius:24px; padding:48px 44px; width:100%; max-width:460px; box-shadow:0 40px 80px rgba(0,0,0,0.5); animation:fadeUp 0.5s ease both; }
    @keyframes fadeUp { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }
    .brand { display:flex; align-items:center; gap:12px; margin-bottom:32px; }
    .brand-icon { width:48px; height:48px; display:flex; align-items:center; justify-content:center; filter:drop-shadow(0 4px 16px rgba(59,130,246,0.4)); }
    .brand-icon img { width:100%; height:100%; object-fit:contain; }
    .brand-name { font-size:16px; font-weight:700; }
    .brand-sub  { font-size:12px; color:var(--text-muted); }
    h1 { font-size:26px; font-weight:700; letter-spacing:-0.03em; margin-bottom:6px; }
    .subtitle { font-size:14px; color:var(--text-muted); margin-bottom:28px; line-height:1.5; }
    .form-group { display:flex; flex-direction:column; gap:8px; margin-bottom:16px; }
    label { font-size:13px; font-weight:500; }
    input[type="password"] { width:100%; background:var(--bg-input); border:1px solid var(--border); border-radius:10px; padding:13px 16px; font-size:14px; font-family:'Sora',sans-serif; color:var(--text-white); outline:none; transition:border-color 0.2s,box-shadow 0.2s; }
    input::placeholder { color:var(--text-dim); }
    input:focus { border-color:var(--border-focus); box-shadow:0 0 0 3px rgba(59,130,246,0.1); }
    .strength-bar { height:4px; border-radius:4px; background:rgba(255,255,255,0.07); margin-top:6px; overflow:hidden; }
    .strength-fill { height:100%; border-radius:4px; transition:width 0.3s,background 0.3s; width:0%; }
    .hint { font-size:11px; color:var(--text-dim); margin-top:4px; font-family:'JetBrains Mono',monospace; }
    .btn { width:100%; padding:14px; background:var(--blue); color:#fff; font-size:15px; font-weight:600; font-family:'Sora',sans-serif; border:none; border-radius:12px; cursor:pointer; transition:background 0.2s; box-shadow:0 4px 20px rgba(59,130,246,0.3); margin-top:8px; }
    .btn:hover { background:var(--blue-hover); }
    .back-link { text-align:center; font-size:13px; color:var(--text-muted); margin-top:20px; }
    .back-link a { color:var(--blue); text-decoration:none; }
    .msg-ok  { background:rgba(34,197,94,0.1);  border:1px solid rgba(34,197,94,0.3);  border-radius:10px; padding:14px 16px; font-size:13px; color:#86efac; margin-bottom:20px; }
    .msg-err { background:rgba(239,68,68,0.1);  border:1px solid rgba(239,68,68,0.3);  border-radius:10px; padding:14px 16px; font-size:13px; color:#fca5a5; margin-bottom:20px; }
    .expiry  { display:flex; align-items:center; gap:8px; background:rgba(59,130,246,0.07); border:1px solid rgba(59,130,246,0.2); border-radius:10px; padding:10px 14px; font-size:12px; color:var(--text-muted); margin-bottom:24px; font-family:'JetBrains Mono',monospace; }
  </style>
</head>
<body>
<div class="page">
  <div class="card">

    <div class="brand">
      <div class="brand-icon"><img src="../assets/icon.svg" alt="SchoolFaceID"></div>
      <div>
        <div class="brand-name">SchoolFaceID</div>
        <div class="brand-sub">Reimposta password</div>
      </div>
    </div>

    <?php if ($success): ?>
      <div class="msg-ok">✅ Password aggiornata con successo!</div>
      <p style="font-size:14px;color:var(--text-muted);margin-bottom:24px;">Ora puoi accedere con la tua nuova password.</p>
      <a href="login.php" style="display:block;text-align:center;" class="btn">Vai al login</a>

    <?php elseif ($errore && !$record): ?>
      <div class="msg-err">❌ <?= htmlspecialchars($errore) ?></div>
      <div class="back-link"><a href="recupera_password.php">Richiedi un nuovo link</a></div>

    <?php else: ?>
      <h1>Nuova password</h1>
      <p class="subtitle">Ciao <strong><?= htmlspecialchars($record['nome']) ?></strong>, scegli una nuova password sicura.</p>

      <?php if ($errore): ?>
        <div class="msg-err">❌ <?= htmlspecialchars($errore) ?></div>
      <?php endif; ?>

      <div class="expiry">
        ⏱ Link valido fino alle <?= date('H:i', strtotime($record['scadenza'])) ?>
      </div>

      <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <div class="form-group">
          <label for="password">Nuova password</label>
          <input type="password" id="password" name="password" placeholder="Minimo 8 caratteri" required oninput="valutaForza(this.value)"/>
          <div class="strength-bar"><div class="strength-fill" id="strength-fill"></div></div>
          <div class="hint" id="strength-hint">Inserisci la password</div>
        </div>
        <div class="form-group">
          <label for="conferma">Conferma password</label>
          <input type="password" id="conferma" name="conferma" placeholder="Ripeti la password" required/>
        </div>
        <button type="submit" class="btn">Salva nuova password</button>
      </form>

      <div class="back-link"><a href="recupera_password.php">Richiedi un nuovo link</a></div>
    <?php endif; ?>

  </div>
</div>
<script>
function valutaForza(v) {
  const fill = document.getElementById('strength-fill');
  const hint = document.getElementById('strength-hint');
  let score = 0;
  if (v.length >= 8)  score++;
  if (v.length >= 12) score++;
  if (/[A-Z]/.test(v)) score++;
  if (/[0-9]/.test(v)) score++;
  if (/[^A-Za-z0-9]/.test(v)) score++;
  const livelli = [
    { w:'0%',   c:'transparent', t:'Inserisci la password' },
    { w:'25%',  c:'#ef4444',     t:'Troppo corta' },
    { w:'50%',  c:'#f97316',     t:'Debole' },
    { w:'75%',  c:'#eab308',     t:'Discreta' },
    { w:'90%',  c:'#22c55e',     t:'Buona' },
    { w:'100%', c:'#22c55e',     t:'Ottima' },
  ];
  const l = livelli[Math.min(score, 5)];
  fill.style.width = l.w;
  fill.style.background = l.c;
  hint.textContent = l.t;
  hint.style.color = l.c || 'var(--text-dim)';
}
</script>
</body>
</html>
