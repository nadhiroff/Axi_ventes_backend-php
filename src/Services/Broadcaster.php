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
