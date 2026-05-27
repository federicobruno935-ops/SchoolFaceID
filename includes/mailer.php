<?php
require_once __DIR__ . '/config.php';

function invia_reset_password(string $email, string $nome, string $link): bool {
    $subject = '=?UTF-8?B?' . base64_encode('SchoolFaceID — Reset Password') . '?=';

    $headers  = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
    $headers .= 'From: SchoolFaceID <noreply@macarena.altervista.org>' . "\r\n";
    $headers .= 'Reply-To: ' . MAIL_FROM . "\r\n";
    $headers .= 'X-Mailer: PHP/' . phpversion();

    $body = "
        <div style='font-family:Arial,sans-serif;max-width:480px;margin:0 auto;background:#070d1a;color:#f0f6ff;border-radius:16px;overflow:hidden;'>
          <div style='background:linear-gradient(135deg,#1d3a6e,#2563eb);padding:28px 32px;'>
            <div style='font-size:22px;font-weight:700;letter-spacing:-0.02em;'>SchoolFaceID</div>
            <div style='font-size:13px;opacity:0.8;margin-top:4px;'>Sistema presenze scolastiche</div>
          </div>
          <div style='padding:32px;'>
            <h2 style='font-size:20px;margin:0 0 12px;'>Ciao $nome,</h2>
            <p style='color:#6b7fa3;line-height:1.6;margin-bottom:24px;'>
              Hai richiesto il reset della password per il tuo account SchoolFaceID.
              Clicca il bottone qui sotto entro <strong style='color:#f0f6ff;'>30 minuti</strong>.
            </p>
            <a href='$link' style='display:inline-block;background:#3b82f6;color:#fff;text-decoration:none;padding:14px 28px;border-radius:10px;font-weight:600;font-size:15px;'>
              Reimposta password
            </a>
            <p style='color:#3d5070;font-size:12px;margin-top:24px;line-height:1.5;'>
              Se non riesci a cliccare il bottone, copia questo link nel browser:<br>
              <span style='color:#6b7fa3;word-break:break-all;'>$link</span>
            </p>
            <hr style='border:none;border-top:1px solid rgba(255,255,255,0.07);margin:24px 0;'>
            <p style='color:#3d5070;font-size:11px;'>
              Se non hai richiesto il reset, ignora questa email. Il link scadrà automaticamente.
            </p>
          </div>
        </div>
    ";

    $inviato = @mail($email, $subject, $body, $headers);

    $riga = date('Y-m-d H:i:s') . " | TO: $email | SENT: " . ($inviato ? 'yes' : 'no') . " | LINK: $link\n";
    @file_put_contents(__DIR__ . '/../cache/reset_log.txt', $riga, FILE_APPEND);

    return (bool) $inviato;
}

function genera_token_reset(PDO $pdo, int $utente_id): string {
    $pdo->prepare("UPDATE password_reset_tokens SET usato=1 WHERE utente_id=? AND usato=0")
        ->execute([$utente_id]);

    $token    = bin2hex(random_bytes(32));
    $scadenza = date('Y-m-d H:i:s', strtotime('+30 minutes'));

    $pdo->prepare("INSERT INTO password_reset_tokens (utente_id, token, scadenza) VALUES (?, ?, ?)")
        ->execute([$utente_id, $token, $scadenza]);

    return $token;
}

function valida_token_reset(PDO $pdo, string $token): ?array {
    $stmt = $pdo->prepare("
        SELECT t.*, u.nome, u.cognome, u.email, u.ruolo
        FROM password_reset_tokens t
        JOIN utenti u ON u.id = t.utente_id
        WHERE t.token = ? AND t.usato = 0 AND t.scadenza > NOW()
    ");
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

function consuma_token_reset(PDO $pdo, string $token, string $nuova_password): void {
    $pdo->prepare("UPDATE password_reset_tokens SET usato=1 WHERE token=?")
        ->execute([$token]);
    $pdo->prepare("UPDATE utenti SET password_hash=? WHERE id=(SELECT utente_id FROM password_reset_tokens WHERE token=?)")
        ->execute([password_hash($nuova_password, PASSWORD_DEFAULT), $token]);
}
