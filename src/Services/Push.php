<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Clock;
use Axi\Core\Database;

/**
 * Alertes sur le téléphone (bannière du système, même application fermée) par le service d'Expo.
 * Mises en file (Jobs) : la requête répond sans attendre Expo, le worker envoie et réessaie si Expo ne répond pas.
 * Seuls les téléphones encore connectés (session active) au moment de l'envoi reçoivent l'alerte ;
 * un téléphone désinstallé est oublié.
 */
final class Push
{
    private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    /** @param array $data ex. ['url' => '/messages/conv_3'] : écran ouvert quand on touche l'alerte */
    public static function toUser(int $userId, string $title, string $body, array $data = []): void
    {
        Jobs::push($userId, $title, $body, $data);
    }

    /** Envoi immédiat (worker). false = Expo injoignable : la tâche sera réessayée */
    public static function sendNow(int $userId, string $title, string $body, array $data = []): bool
    {
        $tokens = Database::all(
            'SELECT p.token FROM push_tokens p JOIN auth_tokens a ON a.id = p.auth_token_id
             WHERE p.user_id = ? AND a.revoked_at IS NULL AND a.expires_at > ?',
            [$userId, Clock::now()],
        );
        $messages = array_map(static fn (array $t): array => [
            'to' => $t['token'],
            'title' => mb_substr($title, 0, 120),
            'body' => mb_substr($body, 0, 240),
            'data' => (object) $data,
            'sound' => 'default',
            'priority' => 'high',
            'channelId' => 'default',
        ], $tokens);
        // 100 messages par appel au plus (limite d'Expo)
        foreach (array_chunk($messages, 100) as $chunk) {
            $res = HttpClient::json('POST', self::ENDPOINT, ['Accept-Encoding' => 'identity'], $chunk, 10);
            if ($res['status'] === 0 || $res['status'] >= 500 || $res['status'] === 429) {
                return false;
            }
            foreach ($res['body']['data'] ?? [] as $i => $ticket) {
                // Application désinstallée ou notifications coupées : jeton oublié
                if (($ticket['details']['error'] ?? '') === 'DeviceNotRegistered' && isset($chunk[$i]['to'])) {
                    Database::run('DELETE FROM push_tokens WHERE token = ?', [$chunk[$i]['to']]);
                }
            }
        }
        return true;
    }
}
