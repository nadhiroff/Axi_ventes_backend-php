<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Clock;
use Axi\Core\Database;

/**
 * Notifications : dans l'application (table notifications) et par email, selon les préférences
 * de l'utilisateur (Paramètres > Notifications). Aucun SMS.
 */
final class Notifier
{
    public const TYPES = ['order', 'payment', 'reminder', 'promotion', 'arrival', 'account'];

    /** Préférences par défaut : tout dans l'app ; email pour commandes, paiements, relances, compte */
    public static function defaults(): array
    {
        return [
            'order' => ['app' => true, 'email' => true],
            'payment' => ['app' => true, 'email' => true],
            'reminder' => ['app' => true, 'email' => true],
            'promotion' => ['app' => true, 'email' => false],
            'arrival' => ['app' => true, 'email' => false],
            'account' => ['app' => true, 'email' => true],
        ];
    }

    public static function preferences(int $userId): array
    {
        $raw = Database::value('SELECT notifications FROM user_settings WHERE user_id = ?', [$userId]);
        $saved = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        return array_replace_recursive(self::defaults(), array_intersect_key($saved, self::defaults()));
    }

    /**
     * @param array $email [paragraphes, extra] pour EmailTemplates::message, ou [] pour le texte seul
     * @param bool $forceEmail email envoyé même si l'utilisateur l'a désactivé (sécurité du compte)
     * @return array{app: bool, email: bool} ce qui a réellement été envoyé
     */
    public static function send(int $userId, string $type, string $title, string $body, array $data = [], array $email = [], bool $forceEmail = false): array
    {
        $user = Database::one('SELECT id, first_name, email, status FROM users WHERE id = ?', [$userId]);
        if ($user === null || $user['status'] === 'deleted') {
            return ['app' => false, 'email' => false];
        }
        $prefs = self::preferences($userId)[$type];
        $sent = ['app' => false, 'email' => false];
        if ($prefs['app']) {
            Database::run(
                'INSERT INTO notifications (user_id, type, title, body, data, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$userId, $type, mb_substr($title, 0, 160), mb_substr($body, 0, 600), $data === [] ? null : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), Clock::now()],
            );
            $sent['app'] = true;
        }
        if ($prefs['email'] || $forceEmail) {
            [$subject, $html, $text] = EmailTemplates::message($title, (string) $user['first_name'], $email[0] ?? [$body], $email[1] ?? []);
            $sent['email'] = Mailer::send((string) $user['email'], $subject, $html, $text);
        }
        return $sent;
    }

    /** Email à une adresse sans compte Axi (client ajouté à la main par un commerçant) */
    public static function emailOnly(string $email, string $name, string $title, array $paragraphs, array $extra = []): bool
    {
        [$subject, $html, $text] = EmailTemplates::message($title, $name, $paragraphs, $extra);
        return Mailer::send($email, $subject, $html, $text);
    }
}
