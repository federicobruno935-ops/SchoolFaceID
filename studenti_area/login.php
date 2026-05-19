<?php
session_start();

if (isset($_SESSION['studente_id'])) {
    header('Location: dashboard.php');
    exit;
}

$errore = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errore = 'Richiesta non valida. Ricarica la pagina.';
    } else {
        require_once '../includes/db.php';

        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email && $password) {
            $stmt = $pdo->prepare("SELECT * FROM utenti WHERE email = ? AND ruolo = 'studente' AND attivo = 1");
            $stmt->execute([$email]);
            $studente = $stmt->fetch();

            if ($studente && password_verify($password, $studente['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['studente_id']   = $studente['id'];
                $_SESSION['studente_nome'] = $studente['nome'] . ' ' . $studente['cognome'];
                header('Location: dashboard.php');
                exit;
            } else {
                $errore = 'Credenziali non valide. Riprova.';
            }
        } else {
            $errore = 'Inserisci email e password.';
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
  <title>SchoolFaceID — Accesso Studenti</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg-deep:     #070d1a;
      --bg-card:     #0e1829;
      --bg-card2:    #111f33;
      --bg-input:    #0a1322;
      --border:      rgba(255,255,255,0.07);
      --border-focus:rgba(59,130,246,0.6);
      --blue:        #3b82f6;
      --blue-hover:  #2563eb;
      --green:       #22c55e;
      --red:         #ef4444;
      --text-white:  #f0f6ff;
      --text-muted:  #6b7fa3;
      --text-dim:    #3d5070;
    }

    html, body {
      height: 100%;
      font-family: 'Sora', sans-serif;
      background: var(--bg-deep);
      color: var(--text-white);
      overflow: hidden;
    }

    body::before {
      content: ''; position: fixed; inset: 0;
      background:
        radial-gradient(ellipse 80% 60% at 20% 50%, rgba(30,58,138,0.25) 0%, transparent 70%),
        radial-gradient(ellipse 60% 80% at 80% 30%, rgba(15,40,100,0.2) 0%, transparent 70%);
      pointer-events: none; z-index: 0;
    }

    body::after {
      content: ''; position: fixed; inset: 0;
      background-image: radial-gradient(rgba(59,130,246,0.08) 1px, transparent 1px);
      background-size: 32px 32px;
      pointer-events: none; z-index: 0;
    }

    .page {
      position: relative; z-index: 1;
      display: flex; align-items: center; justify-content: center;
      height: 100vh; padding: 24px;
    }

    .container {
      display: grid; grid-template-columns: 1fr 1fr;
      max-width: 980px; width: 100%;
      border-radius: 24px; overflow: hidden;
      border: 1px solid var(--border);
      box-shadow: 0 40px 80px rgba(0,0,0,0.5);
      animation: fadeUp 0.6s ease both;
    }

    @keyframes fadeUp {
      from { opacity:0; transform:translateY(24px); }
      to   { opacity:1; transform:translateY(0); }
    }

    .panel-left {
      background: var(--bg-card); padding: 48px 44px;
      display: flex; flex-direction: column; gap: 28px;
      border-right: 1px solid var(--border);
    }

    .brand { display: flex; align-items: center; gap: 14px; }
    .brand-icon {
      width: 48px; height: 48px;
      background: linear-gradient(135deg, #1d3a6e, #2563eb);
      border-radius: 14px;
      display: flex; align-items: center; justify-content: center;
      font-size: 22px; box-shadow: 0 4px 20px rgba(37,99,235,0.3);
    }
    .brand-label { font-size: 10px; font-family: 'JetBrains Mono', monospace; letter-spacing: 0.15em; color: var(--blue); text-transform: uppercase; }
    .brand-name  { font-size: 22px; font-weight: 700; letter-spacing: -0.02em; }

    .tagline { font-size: 15px; color: var(--text-muted); line-height: 1.7; }

    .features { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .feature-card {
      background: var(--bg-card2); border: 1px solid var(--border);
      border-radius: 12px; padding: 16px;
    }
    .feature-icon  { font-size: 22px; margin-bottom: 8px; }
    .feature-title { font-size: 13px; font-weight: 600; color: var(--text-white); margin-bottom: 4px; }
    .feature-desc  { font-size: 11px; color: var(--text-muted); line-height: 1.4; }

    .info-card {
      border-radius: 12px; padding: 16px;
      background: rgba(34,197,94,0.07); border: 1px solid rgba(34,197,94,0.2);
      margin-top: auto;
    }
    .info-card-title { font-size: 12px; font-weight: 600; color: var(--green); margin-bottom: 6px; }
    .info-card p { font-size: 12px; color: var(--text-muted); line-height: 1.5; }

    .panel-right {
      background: #0b1628; padding: 48px 44px;
      display: flex; flex-direction: column; gap: 28px;
    }

    .login-badge {
      display: inline-flex; align-items: center; gap: 8px;
      background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.25);
      border-radius: 20px; padding: 6px 14px; width: fit-content;
    }
    .login-badge span { width: 8px; height: 8px; background: var(--green); border-radius: 50%; animation: pulse 2s infinite; }
    @keyframes pulse { 0%,100% { opacity:1; } 50% { opacity:0.4; } }
    .login-badge p { font-size: 13px; color: var(--green); font-weight: 500; }

    .login-title    { font-size: 30px; font-weight: 700; letter-spacing: -0.03em; }
    .login-subtitle { font-size: 14px; color: var(--text-muted); margin-top: 4px; line-height: 1.5; }

    .form-group { display: flex; flex-direction: column; gap: 8px; }
    label { font-size: 13px; font-weight: 500; color: var(--text-white); }

    input[type="email"], input[type="password"] {
      width: 100%; background: var(--bg-input);
      border: 1px solid var(--border); border-radius: 10px;
      padding: 13px 16px; font-size: 14px; font-family: 'Sora', sans-serif;
      color: var(--text-white); outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
    }
    input::placeholder { color: var(--text-dim); }
    input:focus {
      border-color: var(--border-focus);
      box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
    }

    .btn-login {
      width: 100%; padding: 14px;
      background: var(--blue); color: #fff;
      font-size: 15px; font-weight: 600; font-family: 'Sora', sans-serif;
      border: none; border-radius: 12px; cursor: pointer;
      transition: background 0.2s, box-shadow 0.2s;
      box-shadow: 0 4px 20px rgba(59,130,246,0.3);
    }
    .btn-login:hover  { background: var(--blue-hover); }
    .btn-login:active { transform: scale(0.98); }

    .link-docente {
      text-align: center; font-size: 13px; color: var(--text-muted);
      margin-top: auto;
    }
    .link-docente a { color: var(--blue); text-decoration: none; }
    .link-docente a:hover { opacity: 0.7; }

    .error-msg {
      background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3);
      border-radius: 10px; padding: 12px 16px; font-size: 13px; color: #fca5a5;
    }
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

      <p class="tagline">
        Accedi per consultare le tue presenze, visualizzare le statistiche
        e tenere traccia della tua situazione scolastica.
      </p>

      <div class="features">
        <div class="feature-card">
          <div class="feature-icon">📊</div>
          <div class="feature-title">Le tue statistiche</div>
          <div class="feature-desc">Percentuale presenze, ritardi e assenze personali</div>
        </div>
        <div class="feature-card">
          <div class="feature-icon">📅</div>
          <div class="feature-title">Storico presenze</div>
          <div class="feature-desc">Visualizza tutte le tue giornate nel dettaglio</div>
        </div>
        <div class="feature-card">
          <div class="feature-icon">⚡</div>
          <div class="feature-title">Aggiornamento live</div>
          <div class="feature-desc">Dati aggiornati in tempo reale dal sistema</div>
        </div>
        <div class="feature-card">
          <div class="feature-icon">🔒</div>
          <div class="feature-title">Accesso privato</div>
          <div class="feature-desc">Vedi solo i tuoi dati, nessun altro</div>
        </div>
      </div>

      <div class="info-card">
        <div class="info-card-title">Prima volta?</div>
        <p>Chiedi al tuo docente di attivare il tuo account con la tua email scolastica.</p>
      </div>
    </div>

    <div class="panel-right">
      <div class="login-badge">
        <span></span>
        <p>Accesso studente</p>
      </div>

      <div>
        <div class="login-title">Ciao!</div>
        <div class="login-subtitle">Inserisci le tue credenziali per accedere alla tua area personale.</div>
      </div>

      <?php if ($errore): ?>
        <div class="error-msg"><?= htmlspecialchars($errore) ?></div>
      <?php endif; ?>

      <form method="POST" action="">
        <div style="display:flex; flex-direction:column; gap:18px;">
          <div class="form-group">
            <label for="email">Email scolastica</label>
            <input type="email" id="email" name="email"
                   placeholder="nome.cognome@scuola.it"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required/>
          </div>
          <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" placeholder="••••••••" required/>
          </div>
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
          <button type="submit" class="btn-login">Accedi alla tua area</button>
        </div>
      </form>

      <div class="link-docente" style="display:flex;flex-direction:column;gap:8px;">
        <a href="recupera_password.php" style="color:var(--blue);text-decoration:none;font-size:13px;text-align:center;">Password dimenticata?</a>
        Sei un docente? <a href="../pages/login.php">Accedi qui</a>
      </div>
    </div>

  </div>
</div>
</body>
</html>
