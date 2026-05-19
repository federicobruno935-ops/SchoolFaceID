<?php
session_start();
if (isset($_SESSION['studente_id'])) { header('Location: dashboard.php'); exit; }

require_once '../includes/db.php';
require_once '../includes/mailer.php';

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$messaggio = '';
$errore    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errore = 'Richiesta non valida. Ricarica la pagina.';
    } else {
        $email = trim($_POST['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errore = 'Inserisci un indirizzo email valido.';
        } else {
            $stmt = $pdo->prepare("SELECT id, nome, cognome FROM utenti WHERE email=? AND ruolo='studente' AND attivo=1");
            $stmt->execute([$email]);
            $studente = $stmt->fetch();

            if ($studente) {
                $token = genera_token_reset($pdo, $studente['id']);
                $link  = BASE_URL . '/studenti_area/reset_password.php?token=' . $token;
                invia_reset_password($email, $studente['nome'] . ' ' . $studente['cognome'], $link);
            }
            $messaggio = 'Se l\'email è registrata riceverai il link entro pochi minuti.';
        }
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>SchoolFaceID — Recupera Password</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg-deep:#070d1a; --bg-card:#0e1829; --bg-card2:#111f33; --bg-input:#0a1322;
      --border:rgba(255,255,255,0.07); --border-focus:rgba(59,130,246,0.6);
      --blue:#3b82f6; --blue-hover:#2563eb; --green:#22c55e; --red:#ef4444;
      --text-white:#f0f6ff; --text-muted:#6b7fa3; --text-dim:#3d5070;
    }
    html,body { height:100%; font-family:'Sora',sans-serif; background:var(--bg-deep); color:var(--text-white); overflow:hidden; }
    body::before { content:''; position:fixed; inset:0; background:radial-gradient(ellipse 80% 60% at 20% 50%,rgba(30,58,138,0.25) 0%,transparent 70%),radial-gradient(ellipse 60% 80% at 80% 30%,rgba(15,40,100,0.2) 0%,transparent 70%); pointer-events:none; z-index:0; }
    body::after { content:''; position:fixed; inset:0; background-image:radial-gradient(rgba(59,130,246,0.06) 1px,transparent 1px); background-size:32px 32px; pointer-events:none; z-index:0; }
    .page { position:relative; z-index:1; display:flex; align-items:center; justify-content:center; height:100vh; padding:24px; }
    .container { display:grid; grid-template-columns:1fr 1fr; max-width:900px; width:100%; border-radius:24px; overflow:hidden; border:1px solid var(--border); box-shadow:0 40px 80px rgba(0,0,0,0.5); animation:fadeUp 0.6s ease both; }
    @keyframes fadeUp { from{opacity:0;transform:translateY(24px)} to{opacity:1;transform:translateY(0)} }
    .panel-left { background:var(--bg-card); padding:48px 44px; display:flex; flex-direction:column; gap:28px; border-right:1px solid var(--border); }
    .brand { display:flex; align-items:center; gap:14px; }
    .brand-icon { width:48px; height:48px; background:linear-gradient(135deg,#1d3a6e,#2563eb); border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:22px; box-shadow:0 4px 20px rgba(37,99,235,0.3); }
    .brand-label { font-size:10px; font-family:'JetBrains Mono',monospace; letter-spacing:0.15em; color:var(--blue); text-transform:uppercase; }
    .brand-name  { font-size:22px; font-weight:700; letter-spacing:-0.02em; }
    .tagline { font-size:15px; color:var(--text-muted); line-height:1.7; }
    .steps { display:flex; flex-direction:column; gap:14px; }
    .step { display:flex; align-items:flex-start; gap:14px; }
    .step-num { width:28px; height:28px; min-width:28px; background:rgba(59,130,246,0.15); border:1px solid rgba(59,130,246,0.3); border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; color:var(--blue); font-family:'JetBrains Mono',monospace; }
    .step-text strong { display:block; font-size:13px; font-weight:600; color:var(--text-white); margin-bottom:2px; }
    .step-text span { font-size:12px; color:var(--text-muted); }
    .panel-right { background:#0b1628; padding:48px 44px; display:flex; flex-direction:column; gap:24px; }
    .login-title { font-size:28px; font-weight:700; letter-spacing:-0.03em; }
    .login-subtitle { font-size:14px; color:var(--text-muted); margin-top:6px; line-height:1.5; }
    .form-group { display:flex; flex-direction:column; gap:8px; }
    label { font-size:13px; font-weight:500; color:var(--text-white); }
    input[type="email"] { width:100%; background:var(--bg-input); border:1px solid var(--border); border-radius:10px; padding:13px 16px; font-size:14px; font-family:'Sora',sans-serif; color:var(--text-white); outline:none; transition:border-color 0.2s,box-shadow 0.2s; }
    input::placeholder { color:var(--text-dim); }
    input:focus { border-color:var(--border-focus); box-shadow:0 0 0 3px rgba(59,130,246,0.1); }
    .btn { width:100%; padding:14px; background:var(--blue); color:#fff; font-size:15px; font-weight:600; font-family:'Sora',sans-serif; border:none; border-radius:12px; cursor:pointer; transition:background 0.2s; box-shadow:0 4px 20px rgba(59,130,246,0.3); }
    .btn:hover { background:var(--blue-hover); }
    .back-link { text-align:center; font-size:13px; color:var(--text-muted); }
    .back-link a { color:var(--blue); text-decoration:none; }
    .back-link a:hover { opacity:0.7; }
    .msg-ok  { background:rgba(34,197,94,0.1);  border:1px solid rgba(34,197,94,0.3);  border-radius:10px; padding:14px 16px; font-size:13px; color:#86efac; }
    .msg-err { background:rgba(239,68,68,0.1);  border:1px solid rgba(239,68,68,0.3);  border-radius:10px; padding:14px 16px; font-size:13px; color:#fca5a5; }
  </style>
</head>
<body>
<div class="page">
  <div class="container">

    <div class="panel-left">
      <div class="brand">
        <div class="brand-icon">🎓</div>
        <div>
          <div class="brand-label">SchoolFaceID</div>
          <div class="brand-name">Area Studenti</div>
        </div>
      </div>
      <p class="tagline">Inserisci la tua email scolastica e ti invieremo un link per reimpostare la password.</p>
      <div class="steps">
        <div class="step">
          <div class="step-num">1</div>
          <div class="step-text">
            <strong>Inserisci la tua email</strong>
            <span>Quella con cui accedi normalmente</span>
          </div>
        </div>
        <div class="step">
          <div class="step-num">2</div>
          <div class="step-text">
            <strong>Controlla la casella email</strong>
            <span>Riceverai un link univoco entro pochi secondi</span>
          </div>
        </div>
        <div class="step">
          <div class="step-num">3</div>
          <div class="step-text">
            <strong>Scegli una nuova password</strong>
            <span>Il link è valido per 30 minuti</span>
          </div>
        </div>
      </div>
    </div>

    <div class="panel-right">
      <div>
        <div class="login-title">Password dimenticata?</div>
        <div class="login-subtitle">Ti mandiamo un link per reimpostarla in pochi secondi.</div>
      </div>

      <?php if ($messaggio): ?>
        <div class="msg-ok">✅ <?= htmlspecialchars($messaggio) ?></div>
      <?php elseif ($errore): ?>
        <div class="msg-err">❌ <?= htmlspecialchars($errore) ?></div>
      <?php endif; ?>

      <?php if (!$messaggio): ?>
      <form method="POST" action="">
        <div style="display:flex;flex-direction:column;gap:20px;">
          <div class="form-group">
            <label for="email">Email scolastica</label>
            <input type="email" id="email" name="email" placeholder="nome.cognome@scuola.it"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required/>
          </div>
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
          <button type="submit" class="btn">Invia link di recupero</button>
        </div>
      </form>
      <?php endif; ?>

      <div class="back-link">
        Ricordi la password? <a href="login.php">Torna al login</a>
      </div>
    </div>

  </div>
</div>
</body>
</html>
