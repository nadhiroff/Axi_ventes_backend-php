<?php
declare(strict_types=1);

namespace Axi\Services;

/** Emails en français aux couleurs d'Axi : orange terre cuite #C2410C, vert #166534, nom écrit en minuscules. */
final class EmailTemplates
{
    /** @return array{0: string, 1: string, 2: string} [objet, html, texte] */
    public static function code(string $purpose, string $firstName, string $code, int $minutes): array
    {
        [$subject, $intro] = match ($purpose) {
            'verify_email' => ['Votre code de confirmation Axi', 'Voici le code pour confirmer votre adresse email et activer votre compte Axi :'],
            'reset_password' => ['Réinitialisation de votre mot de passe Axi', 'Vous avez demandé à changer votre mot de passe. Voici votre code :'],
        };
        return self::message($subject, $firstName, [$intro], [
            'code' => $code,
            'after' => "Ce code est valable $minutes minutes. Ne le communiquez à personne, même à un agent Axi.",
            'footer' => 'Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message : votre compte reste protégé.',
        ]);
    }

    /**
     * Email générique : un titre, des paragraphes, et en option un code, un tableau de lignes ou un bouton.
     * @param array{code?: string, rows?: array<int, array{0: string, 1: string}>, button?: array{0: string, 1: string}, after?: string, footer?: string} $extra
     * @return array{0: string, 1: string, 2: string}
     */
    public static function message(string $subject, string $firstName, array $paragraphs, array $extra = []): array
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $hello = $firstName !== '' ? 'Bonjour ' . $firstName . ',' : 'Bonjour,';

        $text = $hello . "\n\n" . implode("\n\n", $paragraphs) . "\n";
        $html = '<p style="margin:0 0 12px">' . $e($hello) . '</p>';
        foreach ($paragraphs as $p) {
            $html .= '<p style="margin:0 0 14px">' . nl2br($e($p)) . '</p>';
        }
        if (isset($extra['code'])) {
            $text .= "\n    " . $extra['code'] . "\n";
            $html .= '<p style="margin:0 0 20px;text-align:center"><span style="display:inline-block;font-size:32px;font-weight:bold;letter-spacing:8px;color:#C2410C;background:#FFF7ED;border:1px solid #FED7AA;border-radius:8px;padding:12px 20px">' . $e($extra['code']) . '</span></p>';
        }
        if (!empty($extra['rows'])) {
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;border-collapse:collapse">';
            foreach ($extra['rows'] as [$label, $value]) {
                $text .= "\n$label : $value";
                $html .= '<tr><td style="padding:8px 0;border-bottom:1px solid #F3F4F6;color:#6B7280">' . $e($label) . '</td><td style="padding:8px 0;border-bottom:1px solid #F3F4F6;text-align:right;font-weight:bold">' . $e($value) . '</td></tr>';
            }
            $html .= '</table>';
            $text .= "\n";
        }
        if (isset($extra['button'])) {
            [$label, $url] = $extra['button'];
            $text .= "\n$label : $url\n";
            $html .= '<p style="margin:0 0 20px"><a href="' . $e($url) . '" style="display:inline-block;background:#C2410C;color:#FFFFFF;text-decoration:none;font-weight:bold;padding:12px 20px;border-radius:8px">' . $e($label) . '</a></p>';
        }
        if (isset($extra['after'])) {
            $text .= "\n" . $extra['after'] . "\n";
            $html .= '<p style="margin:0 0 12px">' . $e($extra['after']) . '</p>';
        }
        if (isset($extra['footer'])) {
            $text .= "\n" . $extra['footer'] . "\n";
            $html .= '<p style="margin:0 0 12px;color:#6B7280;font-size:14px">' . $e($extra['footer']) . '</p>';
        }
        $text .= "\naxi · tout le marché sans se déplacer\nPour ne plus recevoir ces emails : Paramètres > Notifications, dans l'application.\n";

        $page = <<<HTML
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>{$e($subject)}</title></head>
<body style="margin:0;padding:0;background:#FAF7F2;font-family:Arial,Helvetica,sans-serif;color:#1F2937">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FAF7F2;padding:24px 12px">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#FFFFFF;border-radius:12px;overflow:hidden">
        <tr><td style="background:#C2410C;padding:20px 24px">
          <span style="font-size:30px;font-weight:bold;color:#FFFFFF;letter-spacing:1px">axi</span>
        </td></tr>
        <tr><td style="padding:28px 24px 8px;font-size:16px;line-height:1.5">$html</td></tr>
        <tr><td style="padding:16px 24px;border-top:1px solid #F3F4F6;color:#9CA3AF;font-size:12px">
          <strong style="color:#C2410C">axi</strong> · tout le marché sans se déplacer<br>
          Pour ne plus recevoir ces emails : Paramètres &gt; Notifications, dans l'application.
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;
        return [$subject, $page, $text];
    }
}
