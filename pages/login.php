<?php
session_start();

// Se già loggato, vai alla dashboard
if (isset($_SESSION['utente_id'])) {
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
            $stmt = $pdo->prepare("SELECT * FROM utenti WHERE email = ? AND ruolo IN ('professore','admin') AND attivo = 1");
            $stmt->execute([$email]);
            $utente = $stmt->fetch();

            if ($utente && password_verify($password, $utente['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['utente_id']   = $utente['id'];
                $_SESSION['utente_nome'] = $utente['nome'] . ' ' . $utente['cognome'];
                $_SESSION['ruolo']       = $utente['ruolo'];
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
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>SchoolFaceID — Accesso Docenti</title>
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

    /* Sfondo animato */
    body::before {
      content: '';
      position: fixed;
      inset: 0;
      background:
        radial-gradient(ellipse 80% 60% at 20% 50%, rgba(30,58,138,0.25) 0%, transparent 70%),
        radial-gradient(ellipse 60% 80% at 80% 30%, rgba(15,40,100,0.2) 0%, transparent 70%);
      pointer-events: none;
      z-index: 0;
    }

    /* Grid dots */
    body::after {
      content: '';
      position: fixed;
      inset: 0;
      background-image: radial-gradient(rgba(59,130,246,0.08) 1px, transparent 1px);
      background-size: 32px 32px;
      pointer-events: none;
      z-index: 0;
    }

    .page {
      position: relative;
      z-index: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      height: 100vh;
      padding: 24px;
    }

    .container {
      display: grid;
      grid-template-columns: 1fr 1fr;
      max-width: 980px;
      width: 100%;
      gap: 0;
      border-radius: 24px;
      overflow: hidden;
      border: 1px solid var(--border);
      box-shadow: 0 40px 80px rgba(0,0,0,0.5), 0 0 0 1px rgba(255,255,255,0.03);
      animation: fadeUp 0.6s ease both;
    }

    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(24px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    /* ---- PANNELLO SINISTRO ---- */
    .panel-left {
      background: var(--bg-card);
      padding: 48px 44px;
      display: flex;
      flex-direction: column;
      gap: 32px;
      border-right: 1px solid var(--border);
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .brand-icon {
      width: 48px; height: 48px;
      background: linear-gradient(135deg, #1d3a6e, #2563eb);
      border-radius: 14px;
      display: flex; align-items: center; justify-content: center;
      font-size: 22px;
      box-shadow: 0 4px 20px rgba(37,99,235,0.3);
    }

    .brand-text {
      display: flex; flex-direction: column;
    }

    .brand-label {
      font-size: 10px;
      font-family: 'JetBrains Mono', monospace;
      letter-spacing: 0.15em;
      color: var(--blue);
      text-transform: uppercase;
    }

    .brand-name {
      font-size: 22px;
      font-weight: 700;
      color: var(--text-white);
      letter-spacing: -0.02em;
    }

    .tagline {
      font-size: 15px;
      color: var(--text-muted);
      line-height: 1.7;
    }

    .features {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 10px;
    }

    .feature-card {
      background: var(--bg-card2);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 14px 12px;
    }

    .feature-label {
      font-size: 9px;
      font-family: 'JetBrains Mono', monospace;
      letter-spacing: 0.1em;
      color: var(--text-dim);
      text-transform: uppercase;
      margin-bottom: 6px;
    }

    .feature-value {
      font-size: 12px;
      font-weight: 600;
      color: var(--text-white);
      line-height: 1.4;
    }

    .info-cards {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      margin-top: auto;
    }

    .info-card {
      border-radius: 12px;
      padding: 16px;
      border: 1px solid;
    }

    .info-card.green {
      background: rgba(34,197,94,0.07);
      border-color: rgba(34,197,94,0.2);
    }

    .info-card.blue {
      background: rgba(59,130,246,0.07);
      border-color: rgba(59,130,246,0.2);
    }

    .info-card-title {
      font-size: 12px;
      font-weight: 600;
      margin-bottom: 6px;
    }

    .info-card.green .info-card-title { color: var(--green); }
    .info-card.blue  .info-card-title { color: var(--blue); }

    .info-card p {
      font-size: 12px;
      color: var(--text-muted);
      line-height: 1.5;
    }

    /* ---- PANNELLO DESTRO ---- */
    .panel-right {
      background: #0b1628;
      padding: 48px 44px;
      display: flex;
      flex-direction: column;
      gap: 28px;
    }

    .login-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(34,197,94,0.1);
      border: 1px solid rgba(34,197,94,0.25);
      border-radius: 20px;
      padding: 6px 14px;
      width: fit-content;
    }

    .login-badge span {
      width: 8px; height: 8px;
      background: var(--green);
      border-radius: 50%;
      animation: pulse 2s infinite;
    }

    @keyframes pulse {
      0%, 100% { opacity: 1; }
      50%       { opacity: 0.4; }
    }

    .login-badge p {
      font-size: 13px;
      color: var(--green);
      font-weight: 500;
    }

    .login-title {
      font-size: 30px;
      font-weight: 700;
      letter-spacing: -0.03em;
      color: var(--text-white);
    }

    .login-subtitle {
      font-size: 14px;
      color: var(--text-muted);
      margin-top: 4px;
      line-height: 1.5;
    }

    .form-group {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .form-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    label {
      font-size: 13px;
      font-weight: 500;
      color: var(--text-white);
    }

    .forgot {
      font-size: 13px;
      color: var(--blue);
      text-decoration: none;
      transition: opacity 0.2s;
    }
    .forgot:hover { opacity: 0.7; }

    input[type="email"],
    input[type="password"] {
      width: 100%;
      background: var(--bg-input);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 13px 16px;
      font-size: 14px;
      font-family: 'Sora', sans-serif;
      color: var(--text-white);
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
    }

    input::placeholder { color: var(--text-dim); }

    input:focus {
      border-color: var(--border-focus);
      box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
    }

    .remember-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .remember {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 13px;
      color: var(--text-muted);
      cursor: pointer;
    }

    .remember input[type="checkbox"] {
      width: 16px; height: 16px;
      accent-color: var(--blue);
      cursor: pointer;
    }

    .only-auth {
      font-size: 12px;
      color: var(--text-dim);
      font-family: 'JetBrains Mono', monospace;
    }

    .btn-login {
      width: 100%;
      padding: 14px;
      background: var(--blue);
      color: #fff;
      font-size: 15px;
      font-weight: 600;
      font-family: 'Sora', sans-serif;
      border: none;
      border-radius: 12px;
      cursor: pointer;
      transition: background 0.2s, transform 0.1s, box-shadow 0.2s;
      box-shadow: 0 4px 20px rgba(59,130,246,0.3);
      letter-spacing: -0.01em;
    }

    .btn-login:hover {
      background: var(--blue-hover);
      box-shadow: 0 6px 28px rgba(59,130,246,0.45);
    }

    .btn-login:active { transform: scale(0.98); }

    .protected-box {
      background: var(--bg-card2);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 18px;
    }

    .link-studente {
      text-align: center;
      font-size: 13px;
      color: var(--text-muted);
      margin-top: auto;
    }
    .link-studente a { color: var(--blue); text-decoration: none; }
    .link-studente a:hover { opacity: 0.7; }

    .protected-box strong {
      font-size: 13px;
      color: var(--text-white);
      display: block;
      margin-bottom: 6px;
    }

    .protected-box p {
      font-size: 12px;
      color: var(--text-muted);
      line-height: 1.6;
    }

    .error-msg {
      background: rgba(239,68,68,0.1);
      border: 1px solid rgba(239,68,68,0.3);
      border-radius: 10px;
      padding: 12px 16px;
      font-size: 13px;
      color: #fca5a5;
    }
  </style>
</head>
<body>
<div class="page">
  <div class="container">

    <!-- PANNELLO SINISTRO -->
    <div class="panel-left">
      <div class="brand">
        <div class="brand-icon">🎓</div>
        <div class="brand-text">
          <span class="brand-label">SchoolFaceID</span>
          <span class="brand-name">Accesso Docenti</span>
        </div>
      </div>

      <p class="tagline">
        Accedi alla piattaforma per consultare il registro presenze,
        gestire gli studenti registrati e monitorare i dati in tempo reale.
      </p>

      <div class="features">
        <div class="feature-card">
          <div class="feature-label">Presenze live</div>
          <div class="feature-value">Real-time</div>
        </div>
        <div class="feature-card">
          <div class="feature-label">Volti registrati</div>
          <div class="feature-value">Database sicuro</div>
        </div>
        <div class="feature-card">
          <div class="feature-label">Accesso docente</div>
          <div class="feature-value">Autenticazione protetta</div>
        </div>
      </div>

      <div class="info-cards">
        <div class="info-card green">
          <div class="info-card-title">Sicurezza</div>
          <p>Password protette con hash e accesso dedicato ai soli insegnanti autorizzati.</p>
        </div>
        <div class="info-card blue">
          <div class="info-card-title">Interfaccia coerente</div>
          <p>Design allineato alla dashboard, con card scure, bordi soft e accenti blu.</p>
        </div>
      </div>
    </div>

    <!-- PANNELLO DESTRO -->
    <div class="panel-right">
      <div class="login-badge">
        <span></span>
        <p>Login sicuro</p>
      </div>

      <div>
        <div class="login-title">Bentornato</div>
        <div class="login-subtitle">Inserisci le tue credenziali per entrare nella dashboard SchoolFaceID.</div>
      </div>

      <?php if ($errore): ?>
        <div class="error-msg"><?= htmlspecialchars($errore) ?></div>
      <?php endif; ?>

      <form method="POST" action="">
        <div style="display:flex; flex-direction:column; gap:18px;">
          <div class="form-group">
            <label for="email">Email docente</label>
            <input type="email" id="email" name="email" placeholder="docente@scuola.it"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required />
          </div>

          <div class="form-group">
            <div class="form-row">
              <label for="password">Password</label>
              <a href="recupera_password.php" class="forgot">Password dimenticata?</a>
            </div>
            <input type="password" id="password" name="password" placeholder="••••••••" required />
          </div>

          <div class="remember-row">
            <label class="remember">
              <input type="checkbox" name="ricordami" /> Ricordami
            </label>
            <span class="only-auth">Solo accesso autorizzato</span>
          </div>

            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
          <button type="submit" class="btn-login">Accedi alla dashboard</button>
        </div>
      </form>

      <div class="protected-box">
        <strong>Ambiente protetto</strong>
        <p>L'accesso è riservato ai docenti registrati nel sistema. Le credenziali vengono verificate dal backend PHP collegato al database locale.</p>
      </div>

      <div class="link-studente">
        Sei uno studente? <a href="../studenti_area/login.php">Accedi qui</a>
      </div>
    </div>

  </div>
</div>
</body>
</html>
