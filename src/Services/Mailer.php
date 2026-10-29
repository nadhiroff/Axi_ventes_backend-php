<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Config;
use Axi\Core\Logger;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

/** Envoi SMTP (Gmail) avec PHPMailer. Chaque email a une version HTML et une version texte. */
final class Mailer
{
    /** Domaines réservés aux tests (RFC 2606) : jamais envoyés, seulement journalisés */
    private const TEST_DOMAINS = '/@(example\.(com|net|org)|[^@]+\.(test|invalid|localhost|example))$/i';

    /** Renvoie true si l'email est parti (ou volontairement journalisé seulement). */
    public static function send(string $to, string $subject, string $html, string $text): bool
    {
        if (preg_match(self::TEST_DOMAINS, $to)) {
            Logger::write('mail', "[non envoyé, domaine de test] à $to : $subject");
            return true;
        }

        $config = Config::get('mail');
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $config['host'];
            $mail->Port = $config['port'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['username'];
            $mail->Password = $config['password'];
            $mail->SMTPSecure = $config['encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Timeout = 15;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom($config['username'], $config['from_name']);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $html;
            $mail->AltBody = $text;
            $mail->send();
            Logger::write('mail', "envoyé à $to : $subject");
            return true;
        } catch (MailException $e) {
            Logger::write('mail', "ÉCHEC vers $to : $subject : " . $mail->ErrorInfo);
            return false;
        }
    }
}
