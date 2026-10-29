<?php
declare(strict_types=1);

namespace Axi\Controllers;

use Axi\Core\Clock;
use Axi\Core\Config;
use Axi\Core\Cursor;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Ids;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Validator;
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
            'city' => Config::get('city'),
            'market_notice' => Config::get('anagem_partnership') ? ['text' => 'Service en ligne des marchés', 'partner' => 'ANaGeM'] : null,
            'sponsored' => $this->sponsored(),
            'aisles' => Stalls::aisles(),
            'followed_stalls' => array_map([Stalls::class, 'summary'], $followed),
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
            'products' => array_map([Stalls::class, 'product'], array_slice($products, 0, self::PRODUCTS_PER_PAGE)),
            'products_updated_at' => Clock::iso(Database::value('SELECT MAX(updated_at) FROM products WHERE stall_id = ? AND status = \'active\'', [$id])),
            'next_cursor' => Cursor::next($offset, self::PRODUCTS_PER_PAGE, count($products)),
        ], 200, 300);
    }

    public function follow(Request $req): never
    {
        $id = $this->stallId($req);
        Database::run('INSERT IGNORE INTO stall_follows (user_id, stall_id, created_at) VALUES (?, ?, ?)', [$req->userId(), $id, Clock::now()]);
        Response::ok(['is_followed' => true]);
    }

    public function unfollow(Request $req): never
    {
        $id = $this->stallId($req);
        Database::run('DELETE FROM stall_follows WHERE user_id = ? AND stall_id = ?', [$req->userId(), $id]);
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
             FROM products p JOIN stalls s ON s.id = p.stall_id
             WHERE p.status = \'active\' AND s.status = \'active\' AND p.name LIKE ?
             ORDER BY p.in_stock DESC, p.is_today DESC, p.updated_at DESC LIMIT 30',
            [$like],
        );
        return [
            'query' => $query,
            'stalls' => array_map([Stalls::class, 'summary'], $stalls),
            'products' => array_map(static fn (array $p): array => Stalls::product($p) + [
                'stall' => ['id' => Ids::encode('stl', $p['stall_id']), 'name' => $p['stall_name'], 'verified' => $p['stall_verified_at'] !== null],
            ], $products),
        ];
    }

    /** Campagne « Promotion sur l'accueil client » validée et en cours (une par jour, pour garder l'ETag stable), sinon null */
    private function sponsored(): ?array
    {
        $now = Clock::now();
        $c = Database::one(
            'SELECT * FROM campaigns WHERE format = \'sponsored_home\' AND status = \'active\' AND title IS NOT NULL
               AND (starts_at IS NULL OR starts_at <= ?) AND (ends_at IS NULL OR ends_at > ?)
             ORDER BY MD5(CONCAT(id, CURDATE())) LIMIT 1',
            [$now, $now],
        );
        if ($c === null) {
            return null;
        }
        $target = match (true) {
            $c['target_type'] === 'stall' && $c['target_id'] !== null => ['type' => 'stall', 'id' => Ids::encode('stl', $c['target_id'])],
            $c['target_type'] === 'aisle' && $c['target_id'] !== null => ['type' => 'aisle', 'id' => $c['target_id']],
            $c['aisle_id'] !== null => ['type' => 'aisle', 'id' => $c['aisle_id']],
            default => null,
        };
        if ($target === null) {
            return null;
        }
        return [
            'id' => Ids::encode('spn', $c['id']),
            'label' => 'Sponsorisé',
            'title' => $c['title'],
            'text' => (string) $c['body'],
            'image' => null,
            'target' => $target,
        ];
    }
}
