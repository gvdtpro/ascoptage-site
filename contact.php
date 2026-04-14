<?php
header('Content-Type: application/json; charset=utf-8');

// ─── CONFIGURATION ───────────────────────────────────────────
$SMTP_HOST = 'smtp.office365.com';
$SMTP_PORT = 587;
$SMTP_USER = 'gael.vadot@ascoptage.fr';
$SMTP_PASS = 'Liloudu01';
$SMTP_FROM = 'gael.vadot@ascoptage.fr';     // doit correspondre à $SMTP_USER (Microsoft refuse sinon)
$SMTP_TO   = 'gael.vadot@ascoptage.fr';
// ─────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée.']);
    exit;
}

$nom    = trim($_POST['nom']     ?? '');
$prenom = trim($_POST['prenom']  ?? '');
$email  = trim($_POST['email']   ?? '');
$tel    = trim($_POST['tel']     ?? '');
$objet  = trim($_POST['objet']   ?? '');
$msg    = trim($_POST['message'] ?? '');

if (!$nom || !$prenom || !$email || !$objet || !$msg) {
    echo json_encode(['success' => false, 'message' => 'Veuillez remplir tous les champs obligatoires.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Adresse e-mail invalide.']);
    exit;
}

function smtp_read($sock) {
    $out = '';
    while ($line = fgets($sock, 512)) {
        $out .= $line;
        if (isset($line[3]) && $line[3] === ' ') break;
    }
    return $out;
}

function smtp_cmd($sock, $c) {
    fwrite($sock, $c . "\r\n");
    return smtp_read($sock);
}

function smtp_send($host, $port, $user, $pass, $from, $to, $replyto, $subject, $body) {
    $sock = @fsockopen($host, $port, $errno, $errstr, 15);
    if (!$sock) return "Connexion SMTP échouée: $errstr ($errno)";

    smtp_read($sock);
    smtp_cmd($sock, 'EHLO ' . $host);
    $tls_resp = smtp_cmd($sock, 'STARTTLS');
    if (strpos($tls_resp, '220') === false) {
        fclose($sock);
        return "STARTTLS refusé: $tls_resp";
    }
    if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        fclose($sock);
        return "Activation TLS échouée";
    }
    smtp_cmd($sock, 'EHLO ' . $host);
    smtp_cmd($sock, 'AUTH LOGIN');
    smtp_cmd($sock, base64_encode($user));
    $auth_resp = smtp_cmd($sock, base64_encode($pass));

    if (strpos($auth_resp, '235') === false) {
        fclose($sock);
        return "Authentification SMTP échouée: $auth_resp";
    }

    smtp_cmd($sock, 'MAIL FROM:<' . $from . '>');
    smtp_cmd($sock, 'RCPT TO:<' . $to . '>');
    smtp_cmd($sock, 'DATA');

    $enc_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $enc_body    = chunk_split(base64_encode($body));

    $message  = "From: Ascoptage <{$from}>\r\n";
    $message .= "To: {$to}\r\n";
    $message .= "Reply-To: {$replyto}\r\n";
    $message .= "Subject: {$enc_subject}\r\n";
    $message .= "MIME-Version: 1.0\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n";
    $message .= "\r\n";
    $message .= $enc_body;
    $message .= "\r\n.";

    fwrite($sock, $message . "\r\n");
    smtp_read($sock);
    smtp_cmd($sock, 'QUIT');
    fclose($sock);
    return true;
}

// ─── NOTIFICATION INTERNE ────────────────────────────────────
$subject = 'Demande de contact — ' . $prenom . ' ' . $nom . ' — ' . $objet;
$body  = "Nouvelle demande de contact reçue depuis ascoptage.fr\n\n";
$body .= "──────────────────────────────\n";
$body .= "Nom       : $nom\n";
$body .= "Prénom    : $prenom\n";
$body .= "E-mail    : $email\n";
$body .= "Téléphone : " . ($tel ?: 'Non renseigné') . "\n";
$body .= "Objet     : $objet\n";
$body .= "──────────────────────────────\n\n";
$body .= "Message :\n$msg\n";

$sent = smtp_send($SMTP_HOST, $SMTP_PORT, $SMTP_USER, $SMTP_PASS, $SMTP_FROM, $SMTP_TO, $email, $subject, $body);

if ($sent === true) {
    // ─── CONFIRMATION AU VISITEUR ────────────────────────────
    $confirm_subject = 'Ascoptage — Nous avons bien reçu votre message';
    $confirm_body  = "Bonjour $prenom,\n\n";
    $confirm_body .= "Nous avons bien reçu votre demande et nous vous en remercions.\n\n";
    $confirm_body .= "Notre équipe prendra connaissance de votre message dans les plus brefs délais et reviendra vers vous rapidement.\n\n";
    $confirm_body .= "──────────────────────────────\n";
    $confirm_body .= "Récapitulatif de votre demande :\n\n";
    $confirm_body .= "Objet : $objet\n\n";
    $confirm_body .= "$msg\n";
    $confirm_body .= "──────────────────────────────\n\n";
    $confirm_body .= "Pour toute question urgente, vous pouvez nous joindre au :\n";
    $confirm_body .= "06 82 96 97 56\n\n";
    $confirm_body .= "Cordialement,\n";
    $confirm_body .= "L'équipe Ascoptage\n";
    $confirm_body .= "4 rue du Berry, 44000 Nantes\n";
    $confirm_body .= "contact@ascoptage.fr\n";

    smtp_send($SMTP_HOST, $SMTP_PORT, $SMTP_USER, $SMTP_PASS, $SMTP_FROM, $email, $SMTP_FROM, $confirm_subject, $confirm_body);

    echo json_encode(['success' => true, 'message' => 'Votre message a bien été envoyé. Nous vous répondrons rapidement.']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => "Erreur lors de l'envoi: $sent"]);
}
?>
