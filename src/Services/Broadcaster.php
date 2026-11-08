<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\Ids;

/**
 * Messages du commerçant à ses clients (nouvelle adresse, arrivage, relance) : notification dans l'app
 * pour les clients qui ont un compte Axi, email pour les autres s'ils en ont un, et un lien WhatsApp
 * prêt à partager rendu au commerçant. Aucun SMS.
 */
final class Broadcaster
{
    /**
     * @param array<int, array> $clients lignes de merchant_clients
     * @return array{recipients: int, notified: int, emailed: int, message: string, whatsapp_url: ?string, share_text: string}
     */
    public static function send(array $stall, string $kind, ?string $segment, string $title, string $text, array $clients, ?string $audioPath = null): array
    {
        $type = match ($kind) {
            'arrival', 'segment' => 'arrival',
            default => 'reminder',
        };
        $notified = 0;
        $emailed = 0;
        $audioUrl = $audioPath === null ? null : SignedUrl::make('/v1/media/audio', ['p' => $audioPath], 30 * 86400);
        $data = ['stall_id' => Ids::encode('stl', $stall['id'])] + ($audioUrl !== null ? ['audio_url' => $audioUrl] : []);
        // Comptes bloqués écartés : ils ne reçoivent plus rien
        $blocked = array_flip(array_column(Database::all("SELECT id FROM users WHERE status <> 'active'"), 'id'));
        $clients = array_values(array_filter($clients, static fn (array $c): bool => $c['user_id'] === null || !isset($blocked[$c['user_id']])));
        foreach ($clients as $client) {
            if ($client['user_id'] !== null) {
                $sent = Notifier::send((int) $client['user_id'], $type, $title, $text, $data);
                $notified += (int) $sent['app'];
                $emailed += (int) $sent['email'];
            } elseif ($client['email'] !== null) {
                $emailed += (int) Notifier::emailOnly($client['email'], (string) $client['display_name'], $title, [$text]);
            }
        }
        Database::run(
            'INSERT INTO broadcasts (stall_id, kind, segment, message, audio_path, recipients, emailed, notified, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$stall['id'], $kind, $segment, mb_substr($text, 0, 500), $audioPath, count($clients), $emailed, $notified, Clock::now()],
        );
        $share = $title . "\n" . $text;
        $single = count($clients) === 1 ? preg_replace('/\D/', '', (string) ($clients[0]['phone'] ?? '')) : '';
        return [
            'recipients' => count($clients),
            'notified' => $notified,
            'emailed' => $emailed,
            'message' => self::summary(count($clients), $notified, $emailed),
            // Partage WhatsApp : vers le client s'il est seul et a un numéro, sinon choix des contacts dans WhatsApp
            'whatsapp_url' => 'https://wa.me/' . $single . '?text=' . rawurlencode($share),
            'share_text' => $share,
        ];
    }

    /**
     * Message groupé choisi par le commerçant (« Message à mes clients »).
     * - axi : le message arrive dans la discussion de chaque client qui a un compte Axi et qui suit l'étal ou
     *   a accepté d'être client (badge « Annonce »), avec une alerte sur son téléphone ; les clients sans compte
     *   reçoivent un email s'ils en ont un ; les autres sont rendus avec un lien WhatsApp.
     * - whatsapp : rien n'est envoyé par Axi (WhatsApp n'autorise pas l'envoi groupé automatique) ; un lien
     *   wa.me prêt à envoyer est rendu pour chaque client qui a un numéro.
     *
     * @param array<int, array> $clients lignes de merchant_clients
     */
    public static function announce(array $stall, int $merchantUserId, string $channel, ?string $segment, string $text, array $clients): array
    {
        $blocked = array_flip(array_column(Database::all("SELECT id FROM users WHERE status <> 'active'"), 'id'));
        $clients = array_values(array_filter($clients, static fn (array $c): bool => $c['user_id'] === null || !isset($blocked[$c['user_id']])));
        $inApp = 0;
        $emailed = 0;
        $others = [];
        foreach ($clients as $c) {
            $userId = $c['user_id'] === null ? null : (int) $c['user_id'];
            if ($channel === 'axi' && $userId !== null && Messaging::linked($userId, (int) $stall['id'])) {
                self::postAnnouncement($stall, $merchantUserId, $userId, $text);
                $inApp++;
                continue;
            }
            if ($channel === 'axi' && $userId === null && $c['email'] !== null
                && Notifier::emailOnly($c['email'], (string) $c['display_name'], 'Message de ' . $stall['name'], [$text])) {
                $emailed++;
                continue;
            }
            $others[] = $c;
        }
        Database::run(
            'INSERT INTO broadcasts (stall_id, kind, segment, channel, message, recipients, emailed, notified, created_at) VALUES (?, \'segment\', ?, ?, ?, ?, ?, ?, ?)',
            [$stall['id'], $segment ?? 'custom', $channel, mb_substr($text, 0, 500), count($clients), $emailed, $inApp, Clock::now()],
        );
        $share = 'Message de ' . $stall['name'] . "\n" . $text;
        $targets = [];
        $noPhone = 0;
        foreach ($others as $c) {
            $phone = preg_replace('/\D/', '', (string) ($c['phone'] ?? ''));
            if ($phone === '') {
                $noPhone++;
                continue;
            }
            $targets[] = ['client_id' => Ids::encode('cli', $c['id']), 'name' => $c['display_name'], 'phone' => $c['phone'], 'url' => 'https://wa.me/' . $phone . '?text=' . rawurlencode($share)];
        }
        $n = count($clients);
        $message = match (true) {
            $n === 0 => 'Aucun client sélectionné.',
            $channel === 'whatsapp' => 'Touchez chaque client pour ouvrir WhatsApp avec votre message prêt à envoyer.' . ($noPhone > 0 ? ' ' . self::plural($noPhone, 'client n\'a', 'clients n\'ont') . ' pas de numéro.' : ''),
            default => self::plural($inApp, 'client a reçu', 'clients ont reçu') . ' votre message dans Axi'
                . ($emailed > 0 ? ' et ' . $emailed . ' par email' : '') . '.'
                . ($targets !== [] ? ' ' . self::plural(count($targets), 'client n\'a', 'clients n\'ont') . ' pas Axi : envoyez-leur par WhatsApp.' : '')
                . ($noPhone > 0 ? ' ' . self::plural($noPhone, 'client injoignable', 'clients injoignables') . ' (ni compte, ni email, ni numéro).' : ''),
        };
        return [
            'channel' => $channel,
            'recipients' => $n,
            'notified' => $inApp,
            'emailed' => $emailed,
            'message' => $message,
            'whatsapp_targets' => $targets,
            'without_phone' => $noPhone,
            // Partage libre : WhatsApp ouvre le choix des contacts ou d'une liste de diffusion
            'whatsapp_url' => 'https://wa.me/?text=' . rawurlencode($share),
            'share_text' => $share,
        ];
    }

    /** Le message dans la discussion étal ↔ client (créée si besoin), compté comme non lu, avec alerte */
    private static function postAnnouncement(array $stall, int $merchantUserId, int $clientId, string $text): void
    {
        $now = Clock::now();
        Database::run('INSERT IGNORE INTO conversations (stall_id, client_id, created_at) VALUES (?, ?, ?)', [$stall['id'], $clientId, $now]);
        $c = Database::one('SELECT * FROM conversations WHERE stall_id = ? AND client_id = ?', [$stall['id'], $clientId]);
        $id = Database::insert(
            'INSERT INTO messages (conversation_id, sender_id, kind, is_announcement, body, created_at, expires_at) VALUES (?, ?, \'text\', 1, ?, ?, ?)',
            [$c['id'], $merchantUserId, $text, $now, Messaging::expiresAt($c)],
        );
        Database::run('UPDATE conversations SET last_message_at = ?, merchant_read_at = ?, merchant_read_msg_id = GREATEST(merchant_read_msg_id, ?) WHERE id = ?', [$now, $now, $id, $c['id']]);
        Push::toUser($clientId, (string) $stall['name'], '📢 ' . $text, ['url' => '/messages/' . Ids::encode('conv', (int) $c['id'])]);
    }

    private static function plural(int $n, string $one, string $many): string
    {
        return $n . ' ' . ($n > 1 ? $many : $one);
    }

    private static function summary(int $recipients, int $notified, int $emailed): string
    {
        if ($recipients === 0) {
            return 'Aucun client dans cette liste pour l\'instant.';
        }
        $parts = [];
        if ($notified > 0) {
            $parts[] = $notified . ' dans l\'application';
        }
        if ($emailed > 0) {
            $parts[] = $emailed . ' par email';
        }
        $reached = $parts === [] ? 'aucun client joignable par Axi' : implode(' et ', $parts);
        return "Message préparé pour $recipients client" . ($recipients > 1 ? 's' : '') . " : $reached. Partagez-le aussi sur WhatsApp.";
    }
}
