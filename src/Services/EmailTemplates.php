<?php
declare(strict_types=1);

namespace Axi\Services;

/** Emails en français aux couleurs d'Axi : orange terre cuite #C2410C, nom écrit en minuscules. */
final class EmailTemplates
{
    /** @return array{0: string, 1: string, 2: string} [objet, html, texte] */
    public static function code(string $purpose, string $firstName, string $code, int $minutes): array
    {
        [$subject, $intro] = match ($purpose) {
            'verify_email' => ['Votre code de confirmation Axi', 'Voici le code pour confirmer votre adresse email et activer votre compte Axi :'],
            'reset_password' => ['Réinitialisation de votre mot de passe Axi', 'Vous avez demandé à changer votre mot de passe. Voici votre code :'],
        };
        $hello = $firstName !== '' ? 'Bonjour ' . $firstName . ',' : 'Bonjour,';
        $footer = 'Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message : votre compte reste protégé.';

        $text = "$hello\n\n$intro\n\n    $code\n\nCe code est valable $minutes minutes. Ne le communiquez à personne, "
            . "même à un agent Axi.\n\n$footer\n\naxi · tout le marché sans se déplacer\n";

        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $html = <<<HTML
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>{$e($subject)}</title></head>
<body style="margin:0;padding:0;background:#FAF7F2;font-family:Arial,Helvetica,sans-serif;color:#1F2937">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FAF7F2;padding:24px 12px">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#FFFFFF;border-radius:12px;overflow:hidden">
        <tr><td style="background:#C2410C;padding:20px 24px">
          <span style="font-size:30px;font-weight:bold;color:#FFFFFF;letter-spacing:1px">axi</span>
        </td></tr>
        <tr><td style="padding:28px 24px 8px;font-size:16px;line-height:1.5">
          <p style="margin:0 0 12px">{$e($hello)}</p>
          <p style="margin:0 0 20px">{$e($intro)}</p>
          <p style="margin:0 0 20px;text-align:center">
            <span style="display:inline-block;font-size:32px;font-weight:bold;letter-spacing:8px;color:#C2410C;background:#FFF7ED;border:1px solid #FED7AA;border-radius:8px;padding:12px 20px">{$e($code)}</span>
          </p>
          <p style="margin:0 0 12px">Ce code est valable <strong>{$minutes} minutes</strong>. Ne le communiquez à personne, même à un agent Axi.</p>
          <p style="margin:0 0 20px;color:#6B7280;font-size:14px">{$e($footer)}</p>
        </td></tr>
        <tr><td style="padding:16px 24px;border-top:1px solid #F3F4F6;color:#9CA3AF;font-size:12px">
          <strong style="color:#C2410C">axi</strong> · tout le marché sans se déplacer
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;
        return [$subject, $html, $text];
    }
}
