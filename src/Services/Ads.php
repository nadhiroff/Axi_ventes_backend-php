<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\Ids;
use Axi\Support\Stalls;

/**
 * Annonces affichées dans l'application, selon le public (clients, commerçants ou tous) : plusieurs à la suite,
 * elles défilent seules.
 * L'annonce s'ouvre dans une petite fenêtre : image ou vidéo, texte, puis le lien s'il y en a un.
 */
final class Ads
{
    public const REPORT_REASONS = ['misleading' => 'Trompeuse', 'scam' => 'Arnaque', 'inappropriate' => 'Contenu choquant', 'other' => 'Autre'];

    private const MAX_SHOWN = 5;
    private const ONLINE = 'format IN (\'sponsored_home\', \'sponsored_aisle\') AND status = \'active\'
               AND (starts_at IS NULL OR starts_at <= ?) AND (ends_at IS NULL OR ends_at > ?)';

    /**
     * @param string $audience clients ou merchants
     * @return array<int, array> annonces en ligne pour ce public, dans un ordre tiré chaque jour
     */
    public static function online(string $audience): array
    {
        $now = Clock::now();
        $rows = Database::all(
            'SELECT * FROM campaigns WHERE ' . self::ONLINE . ' AND audience_type IN (?, \'all\')
             ORDER BY MD5(CONCAT(id, CURDATE())) LIMIT ' . self::MAX_SHOWN,
            [$now, $now, $audience],
        );
        return array_map([self::class, 'present'], $rows);
    }

    /**
     * Empreinte de ce qui est en ligne : change quand une annonce apparaît, change ou s'arrête (date comprise).
     * Les compteurs de vues et de clics n'y entrent pas : ils changent sans cesse et ne se voient pas dans l'app.
     */
    public static function fingerprint(): string
    {
        $now = Clock::now();
        return (string) Database::value(
            'SELECT CONCAT(COUNT(*), \':\', COALESCE(SUM(CRC32(CONCAT_WS(\':\', id, audience_type, title, body, url, image_path, video_path))), 0))
             FROM campaigns WHERE ' . self::ONLINE,
            [$now, $now],
        );
    }

    private static function present(array $c): array
    {
        return [
            'id' => Ids::encode('spn', $c['id']),
            'label' => 'Annonceur',
            'title' => $c['title'],
            'text' => $c['body'],
            'image' => Stalls::image($c['image_path'], 600, 300),
            'video_url' => $c['video_path'] === null ? null : SignedUrl::make('/v1/media/video', ['p' => $c['video_path']], 86400),
            'link_url' => $c['url'],
            // Anciennes annonces ciblant un étal ou une allée : bouton dans la fenêtre
            'target' => match (true) {
                $c['target_stall_id'] !== null => ['type' => 'stall', 'id' => Ids::encode('stl', $c['target_stall_id'])],
                $c['aisle_id'] !== null => ['type' => 'aisle', 'id' => $c['aisle_id']],
                default => null,
            },
        ];
    }
}
