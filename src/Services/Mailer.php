<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Config;
use Axi\Core\Logger;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

/** Envoi SMTP avec PHPMailer. Chaque email a une version HTML et une version texte. */
final class Mailer
{
    /** Domaines réservés aux tests (RFC 2606) : jamais envoyés, seulement journalisés */
    private const TEST_DOMAINS = '/@(example\.(com|net|org)|[^@]+\.(test|invalid|localhost|example))$/i';

    /** Renvoie true si l'email est parti (ou volontairement journalisé seulement). */
    /** $replyTo : adresse à laquelle répond le destinataire (ex. le client qui écrit à un commerçant) */
    public static function send(string $to, string $subject, string $html, string $text, ?string $replyTo = null, ?string $replyName = null): bool
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
            $from = $config['from_address'] ?: $config['username'];
            // Moins de risques d'atterrir en spam : expéditeur, Return-Path et identifiant du message
            // sur le même domaine que l'adresse d'envoi (ex. @rgz.bj, couvert par son SPF et sa signature DKIM)
            $mail->setFrom($from, $config['from_name']);
            $mail->Sender = $from;
            $domain = substr((string) strrchr($from, '@'), 1);
            if ($domain !== '') {
                $mail->Hostname = $domain;
                $mail->MessageID = sprintf('<%s@%s>', bin2hex(random_bytes(16)), $domain);
            }
            // Pas d'en-tête « X-Mailer: PHPMailer », souvent pénalisé par les filtres
            $mail->XMailer = ' ';
            $mail->addAddress($to);
            if ($replyTo !== null) {
                $mail->addReplyTo($replyTo, $replyName ?? '');
            }
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
