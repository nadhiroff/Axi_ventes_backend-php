<?php
declare(strict_types=1);

namespace Axi\Controllers;

use Axi\Core\Clock;
use Axi\Core\Cursor;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Ids;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Validator;
use Axi\Services\Notifier;
use Axi\Services\Settings;
use Axi\Services\Uploads;
use Axi\Support\Stalls;

/** Côté client, en visite libre : accueil, allées, recherche, page d'un étal. */
final class CatalogController
{
    private const STALLS_PER_PAGE = 30;
    private const PRODUCTS_PER_PAGE = 30;
    private const STALL_NOT_FOUND = 'Cet étal n\'existe pas ou a été fermé.';

    public function home(Request $req): never
    {
        $followed = [];
        if ($req->user !== null) {
            // Du plus récemment actif au moins actif
            $followed = Stalls::query(
                's.id IN (SELECT stall_id FROM stall_follows WHERE user_id = ?)',
                [$req->userId()],
                'ORDER BY GREATEST(s.updated_at, COALESCE((SELECT MAX(p.updated_at) FROM products p WHERE p.stall_id = s.id), s.updated_at)) DESC LIMIT 20',
            );
        }

        Response::ok([
            'city' => ['id' => 'cotonou', 'name' => Settings::text('city_name')],
            'market_notice' => Settings::bool('anagem_partnership') ? ['text' => 'Service en ligne des marchés', 'partner' => 'ANaGeM'] : null,
            'sponsored' => $this->sponsored(),
            'aisles' => Stalls::aisles(),
            'followed_stalls' => array_map([Stalls::class, 'summary'], $followed),
            'featured_stalls' => $this->featuredStalls(),
        ], 200, 300);
    }

    public function aisle(Request $req): never
    {
        $aisle = Stalls::aisles((string) ($req->params['id'] ?? ''))[0]
            ?? throw new HttpException(404, 'AISLE_NOT_FOUND', 'Cette allée n\'existe pas.');
        $offset = Cursor::offset($req->query('cursor'));
        $rows = Stalls::query(
            's.aisle_id = ?',
            [$aisle['id']],
            'ORDER BY s.verified_at IS NULL, s.is_open DESC, s.updated_at DESC, s.id DESC LIMIT ' . (self::STALLS_PER_PAGE + 1) . ' OFFSET ' . $offset,
        );
        Response::ok([
            'aisle' => $aisle,
            'stalls' => array_map([Stalls::class, 'summary'], array_slice($rows, 0, self::STALLS_PER_PAGE)),
            'next_cursor' => Cursor::next($offset, self::STALLS_PER_PAGE, count($rows)),
        ], 200, 300);
    }

    public function search(Request $req): never
    {
        $query = trim((string) $req->query('q', ''));
        if (mb_strlen($query) < 2) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Tapez au moins 2 caractères.', ['fields' => ['q' => 'Tapez au moins 2 caractères.']]);
        }
        Response::ok($this->results(mb_substr($query, 0, 80)), 200, 300);
    }

    public function voiceSearch(Request $req): never
    {
        // La transcription (français, fon, yoruba, anglais) n'est pas encore branchée
        throw new HttpException(503, 'VOICE_SEARCH_UNAVAILABLE', 'La recherche vocale arrive bientôt. Tapez votre recherche pour l\'instant.');
    }

    public function stall(Request $req): never
    {
        $id = $req->id('stl', 'STALL_NOT_FOUND', self::STALL_NOT_FOUND);
        $stall = Stalls::findActive($id) ?? throw new HttpException(404, 'STALL_NOT_FOUND', self::STALL_NOT_FOUND);
        $offset = Cursor::offset($req->query('cursor'));
        $products = Stalls::products($id, $offset, self::PRODUCTS_PER_PAGE);

        Response::ok([
            'stall' => Stalls::detail($stall, $req->user === null ? null : $req->userId()),
            'products' => Stalls::productList(array_slice($products, 0, self::PRODUCTS_PER_PAGE)),
            'products_updated_at' => Clock::iso(Database::value('SELECT MAX(p.updated_at) FROM products p WHERE p.stall_id = ? AND ' . Stalls::VISIBLE, [$id])),
            'next_cursor' => Cursor::next($offset, self::PRODUCTS_PER_PAGE, count($products)),
        ], 200, 300);
    }

    public function follow(Request $req): never
    {
        $id = $this->stallId($req);
        Database::run('INSERT IGNORE INTO stall_follows (user_id, stall_id, created_at) VALUES (?, ?, ?)', [$req->userId(), $id, Clock::now()]);
        // Suivre l'étal garde la conversation avec le commerçant (sinon effacée après 24 h)
        \Axi\Services\Messaging::keep($req->userId(), $id);
        Response::ok(['is_followed' => true]);
    }

    public function unfollow(Request $req): never
    {
        $id = $this->stallId($req);
        Database::run('DELETE FROM stall_follows WHERE user_id = ? AND stall_id = ?', [$req->userId(), $id]);
        \Axi\Services\Messaging::release($req->userId(), $id);
        Response::ok(['is_followed' => false]);
    }

    public function voiceMessage(Request $req): never
    {
        $id = $this->stallId($req);
        $audio = $req->file('audio') ?? Validator::fail(['audio' => 'Enregistrez un message vocal.']);
        Database::run(
            'INSERT INTO voice_messages (stall_id, user_id, audio_path, created_at) VALUES (?, ?, ?, ?)',
            [$id, $req->userId(), Uploads::audio($audio), Clock::now()],
        );
        $owner = (int) Database::value('SELECT user_id FROM stalls WHERE id = ?', [$id]);
        Notifier::send($owner, 'order', 'Nouveau message vocal', $req->user['name'] . ' vous a laissé un message vocal. Écoutez-le dans « Mes clients ».', ['voice_messages' => true]);
        Response::ok(['message' => 'Message vocal envoyé. Le commerçant vous répondra ici ou par téléphone.']);
    }

    private function stallId(Request $req): int
    {
        $id = $req->id('stl', 'STALL_NOT_FOUND', self::STALL_NOT_FOUND);
        if (Database::value('SELECT id FROM stalls WHERE id = ? AND status = \'active\'', [$id]) === null) {
            throw new HttpException(404, 'STALL_NOT_FOUND', self::STALL_NOT_FOUND);
        }
        return $id;
    }

    private function results(string $query): array
    {
        $like = '%' . addcslashes($query, '%_\\') . '%';
        $stalls = Stalls::query('(s.name LIKE ? OR s.tagline LIKE ?)', [$like, $like], 'ORDER BY s.verified_at IS NULL, s.name LIMIT 20');
        $products = Database::all(
            'SELECT p.*, s.name AS stall_name, s.verified_at AS stall_verified_at
             FROM products p JOIN stalls s ON s.id = p.stall_id JOIN users u ON u.id = s.user_id
             WHERE ' . Stalls::VISIBLE . ' AND s.status = \'active\' AND u.status = \'active\' AND p.name LIKE ?
             ORDER BY p.in_stock DESC, p.is_today DESC, p.updated_at DESC LIMIT 30',
            [$like],
        );
        return [
            'query' => $query,
            'stalls' => array_map([Stalls::class, 'summary'], $stalls),
            'products' => array_map(static fn (array $item, array $p): array => $item + [
                'stall' => ['id' => Ids::encode('stl', $p['stall_id']), 'name' => $p['stall_name'], 'verified' => $p['stall_verified_at'] !== null],
            ], Stalls::productList($products), $products),
        ];
    }

    /** Fiche produit : toutes ses photos (galerie), son étal, favori ; enregistrée dans « récemment vus » */
    public function product(Request $req): never
    {
        $id = $req->id('prd', 'PRODUCT_NOT_FOUND', 'Ce produit n\'existe pas ou a été supprimé.');
        $p = Database::one('SELECT p.* FROM products p WHERE p.id = ? AND ' . Stalls::VISIBLE, [$id]);
        $stall = $p === null ? null : Stalls::findActive((int) $p['stall_id']);
        if ($stall === null) {
            throw new HttpException(404, 'PRODUCT_NOT_FOUND', 'Ce produit n\'existe pas ou a été supprimé.');
        }
        $favorite = false;
        if ($req->user !== null) {
            Database::run('INSERT INTO product_views (user_id, product_id, viewed_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE viewed_at = VALUES(viewed_at)', [$req->userId(), $id, Clock::now()]);
            $favorite = Database::value('SELECT 1 FROM favorites WHERE user_id = ? AND product_id = ?', [$req->userId(), $id]) !== null;
        }
        Response::ok(Stalls::product($p) + ['stall' => Stalls::summary($stall), 'is_favorite' => $favorite]);
    }

    /**
     * Campagne « Promotion sur l'accueil client » validée par Axi et en cours (une par jour, pour garder
     * l'ETag stable), sinon null. Aucune publicité n'est affichée sur la page d'un étal.
     */
    private function sponsored(): ?array
    {
        $now = Clock::now();
        $c = Database::one(
            'SELECT * FROM campaigns WHERE format IN (\'sponsored_home\', \'sponsored_aisle\') AND status = \'active\'
               AND (starts_at IS NULL OR starts_at <= ?) AND (ends_at IS NULL OR ends_at > ?)
             ORDER BY MD5(CONCAT(id, CURDATE())) LIMIT 1',
            [$now, $now],
        );
        if ($c === null) {
            return null;
        }
        $target = match (true) {
            $c['target_stall_id'] !== null => ['type' => 'stall', 'id' => Ids::encode('stl', $c['target_stall_id'])],
            $c['aisle_id'] !== null => ['type' => 'aisle', 'id' => $c['aisle_id']],
            default => null,
        };
        if ($target === null) {
            return null;
        }
        Database::run('UPDATE campaigns SET impressions = impressions + 1 WHERE id = ?', [$c['id']]);
        return [
            'id' => Ids::encode('spn', $c['id']),
            'label' => 'Sponsorisé',
            'title' => $c['title'],
            'text' => $c['body'],
            'image' => Stalls::image($c['image_path'], 600, 300),
            'target' => $target,
        ];
    }

    /** Étals mis en avant par une campagne validée (« featured_stall ») */
    private function featuredStalls(): array
    {
        $now = Clock::now();
        $ids = array_column(Database::all(
            'SELECT DISTINCT target_stall_id FROM campaigns WHERE format = \'featured_stall\' AND status = \'active\' AND target_stall_id IS NOT NULL
               AND (starts_at IS NULL OR starts_at <= ?) AND (ends_at IS NULL OR ends_at > ?) LIMIT 6',
            [$now, $now],
        ), 'target_stall_id');
        return $ids === [] ? [] : array_map([Stalls::class, 'summary'], Stalls::query('s.id IN (' . Database::in($ids) . ')', $ids));
    }

    /** Clic sur une promotion (compté pour l'annonceur) */
    public function promotionClick(Request $req): never
    {
        $id = $req->id('spn');
        Database::run('UPDATE campaigns SET clicks = clicks + 1 WHERE id = ? AND status = \'active\'', [$id]);
        Response::ok(['counted' => true]);
    }

    /** Taux de change pour afficher les prix en euro ou en dollar (paiement toujours en F CFA) */
    public function rates(Request $req): never
    {
        Response::ok(\Axi\Services\Rates::current(), 200, 3600);
    }
}
