<?php
declare(strict_types=1);

// Envoie un vrai email de test (gabarit du code de confirmation) pour vérifier le SMTP.
// Usage : php tests/send_test_mail.php adresse@exemple.bj
//         php tests/send_test_mail.php --check   (connexion et authentification SMTP seulement, sans envoi)

use Axi\Core\Config;
use Axi\Services\EmailTemplates;
use Axi\Services\Mailer;
use PHPMailer\PHPMailer\PHPMailer;

require dirname(__DIR__) . '/src/bootstrap.php';

$to = $argv[1] ?? '';
if ($to === '--check') {
    $c = Config::get('mail');
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $c['host'];
    $mail->Port = $c['port'];
    $mail->SMTPAuth = true;
    $mail->Username = $c['username'];
    $mail->Password = $c['password'];
    $mail->SMTPSecure = $c['encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Timeout = 15;
    try {
        $mail->smtpConnect();
        $mail->smtpClose();
        exit("OK : connexion et authentification SMTP réussies ({$c['host']}:{$c['port']}, {$c['username']}).\n");
    } catch (Throwable $e) {
        fwrite(STDERR, 'ÉCHEC : ' . $mail->ErrorInfo . "\n");
        exit(1);
    }
}
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage : php tests/send_test_mail.php adresse@exemple.bj | --check\n");
    exit(2);
}
[$subject, $html, $text] = EmailTemplates::code('verify_email', 'Test', '123456', 15);
$ok = Mailer::send($to, '[Test] ' . $subject, $html, $text);
echo $ok ? "OK : email envoyé à $to (vérifiez aussi les spams).\n" : "ÉCHEC : voir storage/logs/mail.log\n";
exit($ok ? 0 : 1);
