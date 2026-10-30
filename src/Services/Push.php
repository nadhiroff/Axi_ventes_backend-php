<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Clock;
use Axi\Core\Database;

/**
 * Alertes sur le téléphone (bannière du système, même application fermée) par le service d'Expo.
 * Envoyées APRÈS la réponse à l'application (rien n'est ralenti) ; un téléphone désinstallé est oublié.
 * Seuls les téléphones encore connectés (session active) reçoivent l'alerte.
 */
final class Push
{
    private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    /** @var list<array> */
    private static array $queue = [];
    private static bool $scheduled = false;

    /** @param array $data ex. ['url' => '/messages/conv_3'] : écran ouvert quand on touche l'alerte */
    public static function toUser(int $userId, string $title, string $body, array $data = []): void
    {
        $tokens = Database::all(
            'SELECT p.token FROM push_tokens p JOIN auth_tokens a ON a.id = p.auth_token_id
             WHERE p.user_id = ? AND a.revoked_at IS NULL AND a.expires_at > ?',
            [$userId, Clock::now()],
        );
        foreach ($tokens as $t) {
            self::$queue[] = [
                'to' => $t['token'],
                'title' => mb_substr($title, 0, 120),
                'body' => mb_substr($body, 0, 240),
                'data' => (object) $data,
                'sound' => 'default',
                'priority' => 'high',
                'channelId' => 'default',
            ];
        }
        if ($tokens !== [] && !self::$scheduled) {
            self::$scheduled = true;
            register_shutdown_function([self::class, 'flush']);
        }
    }

    /** Après la réponse : envoi groupé (100 par appel, limite d'Expo) */
    public static function flush(): void
    {
        if (self::$queue === []) {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        $messages = self::$queue;
        self::$queue = [];
        foreach (array_chunk($messages, 100) as $chunk) {
            $res = HttpClient::json('POST', self::ENDPOINT, ['Accept-Encoding' => 'identity'], $chunk, 10);
            foreach ($res['body']['data'] ?? [] as $i => $ticket) {
                // Application désinstallée ou notifications coupées : jeton oublié
                if (($ticket['details']['error'] ?? '') === 'DeviceNotRegistered' && isset($chunk[$i]['to'])) {
                    Database::run('DELETE FROM push_tokens WHERE token = ?', [$chunk[$i]['to']]);
                }
            }
        }
    }
}
