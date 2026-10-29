<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Ids;
use Axi\Core\Request;
use Axi\Core\Validator;
use Axi\Support\Stalls;

/**
 * Produits : ajout par PHOTO (jusqu'à 5 photos) ou par VOCAL (brouillon à compléter), modification,
 * suppression. Le prix est toujours celui du commerçant : Axi ne fixe aucun prix.
 */
final class Products
{
    public const MAX_PHOTOS = 5;
    private const LABELS = ['name' => 'Nom du produit', 'price' => 'Prix', 'price_max' => 'Prix maximum', 'product_ref_id' => 'Référence'];

    /** POST multipart : photos[] ou photo, name, price (entier ou vide = à débattre), price_max, is_today, in_stock */
    public static function create(int $stallId, Request $req, int $createdBy): array
    {
        $in = Validator::validate($req->all(), [
            'name' => 'required|string|max:120',
            'price' => 'int|min:1|maxval:100000000',
            'price_max' => 'int|min:1|maxval:100000000',
            'is_today' => 'bool',
            'in_stock' => 'bool',
            'product_ref_id' => 'string',
        ], self::LABELS);
        self::checkRange($in['price'] ?? null, $in['price_max'] ?? null);
        $photos = array_map([Uploads::class, 'photo'], $req->files('photos', 'photo', self::MAX_PHOTOS));

        $id = Database::transaction(static function () use ($stallId, $in, $createdBy, $photos): int {
            $id = Database::insert(
                'INSERT INTO products (stall_id, product_ref_id, name, price_amount, price_max, in_stock, is_today, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$stallId, self::ref($in['product_ref_id'] ?? null), $in['name'], $in['price'] ?? null, $in['price_max'] ?? null,
                    (int) ($in['in_stock'] ?? true), (int) ($in['is_today'] ?? false), $createdBy, Clock::now(), Clock::now()],
            );
            self::attach($id, $photos);
            Database::run('UPDATE stalls SET updated_at = ? WHERE id = ?', [Clock::now(), $stallId]);
            return $id;
        });
        return Stalls::product(self::find($id, $stallId));
    }

    /** Ajout par VOCAL : le commerçant dicte son produit ; brouillon non visible des clients, à compléter */
    public static function createVoiceDraft(int $stallId, Request $req, int $createdBy): array
    {
        $audio = $req->file('audio') ?? Validator::fail(['audio' => 'Enregistrez la description de votre produit.'], ['audio' => 'Message vocal']);
        $path = Uploads::audio($audio);
        $name = trim((string) $req->input('name', '')) ?: 'Produit dicté le ' . date('d/m à H:i', strtotime(Clock::now() . ' UTC') + 3600);
        $photos = array_map([Uploads::class, 'photo'], $req->files('photos', 'photo', self::MAX_PHOTOS));
        $id = Database::transaction(static function () use ($stallId, $path, $name, $createdBy, $photos): int {
            $id = Database::insert(
                'INSERT INTO products (stall_id, name, audio_path, status, created_by, created_at, updated_at) VALUES (?, ?, ?, \'draft\', ?, ?, ?)',
                [$stallId, mb_substr($name, 0, 120), $path, $createdBy, Clock::now(), Clock::now()],
            );
            self::attach($id, $photos);
            return $id;
        });
        return Stalls::product(self::find($id, $stallId, true));
    }

    /** Ajoute des photos à un produit existant (multipart photos[]) */
    public static function addPhotos(int $productId, int $stallId, Request $req): array
    {
        $product = self::find($productId, $stallId, true);
        $existing = (int) Database::value('SELECT COUNT(*) FROM product_images WHERE product_id = ?', [$productId]);
        $files = $req->files('photos', 'photo', self::MAX_PHOTOS);
        if ($files === []) {
            Validator::fail(['photos' => 'Choisissez au moins une photo.']);
        }
        if ($existing + count($files) > self::MAX_PHOTOS) {
            throw new HttpException(422, 'VALIDATION_ERROR', self::MAX_PHOTOS . ' photos maximum par produit.', ['fields' => ['photos' => self::MAX_PHOTOS . ' photos maximum par produit.']]);
        }
        self::attach($productId, array_map([Uploads::class, 'photo'], $files), $existing);
        Database::run('UPDATE products SET updated_at = ? WHERE id = ?', [Clock::now(), $productId]);
        return Stalls::product(self::find((int) $product['id'], $stallId, true));
    }

    public static function removePhoto(int $productId, int $stallId, int $index): array
    {
        self::find($productId, $stallId, true);
        $ids = array_column(Database::all('SELECT id FROM product_images WHERE product_id = ? ORDER BY position, id', [$productId]), 'id');
        if (!isset($ids[$index])) {
            throw new HttpException(404, 'NOT_FOUND', 'Photo introuvable.');
        }
        Database::run('DELETE FROM product_images WHERE id = ?', [$ids[$index]]);
        Database::run('UPDATE products SET updated_at = ? WHERE id = ?', [Clock::now(), $productId]);
        return Stalls::product(self::find($productId, $stallId, true));
    }

    /**
     * PATCH JSON : is_today, in_stock (contrat) ; name, price, price_max (le commerçant gère ses prix) ;
     * status « active » pour publier un brouillon vocal.
     */
    public static function update(int $productId, int $stallId, Request $req): array
    {
        $product = self::find($productId, $stallId, true);
        $body = $req->all();
        $in = Validator::validate($body, [
            'name' => 'string|max:120',
            'price' => 'int|min:1|maxval:100000000',
            'price_max' => 'int|min:1|maxval:100000000',
            'is_today' => 'bool',
            'in_stock' => 'bool',
            'status' => 'in:active',
        ], self::LABELS);

        $fields = [];
        foreach (['name' => 'name', 'is_today' => 'is_today', 'in_stock' => 'in_stock', 'price' => 'price_amount', 'price_max' => 'price_max', 'status' => 'status'] as $key => $column) {
            if (array_key_exists($key, $in)) {
                $fields[$column] = is_bool($in[$key]) ? (int) $in[$key] : $in[$key];
            } elseif (in_array($key, ['price', 'price_max'], true) && array_key_exists($key, $body) && ($body[$key] === null || $body[$key] === '')) {
                // price: null = « Prix à débattre »
                $fields[$column] = null;
            }
        }
        if (array_key_exists('price_amount', $fields) && $fields['price_amount'] === null) {
            $fields['price_max'] = null;
        }
        self::checkRange(
            array_key_exists('price_amount', $fields) ? $fields['price_amount'] : $product['price_amount'],
            array_key_exists('price_max', $fields) ? $fields['price_max'] : $product['price_max'],
        );
        if (($fields['status'] ?? null) === 'active' && str_starts_with((string) ($fields['name'] ?? $product['name']), 'Produit dicté le ')) {
            Validator::fail(['name' => 'Donnez un nom au produit avant de le publier.'], self::LABELS);
        }

        if ($fields !== []) {
            Database::updateById('products', $productId, $fields + ['updated_at' => Clock::now()]);
            Database::run('UPDATE stalls SET updated_at = ? WHERE id = ?', [Clock::now(), $stallId]);
        }
        return Stalls::product(self::find($productId, $stallId, true));
    }

    public static function delete(int $productId, int $stallId): void
    {
        self::find($productId, $stallId, true);
        Database::run('UPDATE products SET status = \'deleted\', updated_at = ? WHERE id = ?', [Clock::now(), $productId]);
    }

    public static function find(int $productId, int $stallId, bool $withDrafts = false): array
    {
        return Database::one(
            'SELECT * FROM products WHERE id = ? AND stall_id = ? AND status ' . ($withDrafts ? 'IN (\'active\', \'draft\')' : '= \'active\''),
            [$productId, $stallId],
        ) ?? throw new HttpException(404, 'PRODUCT_NOT_FOUND', 'Ce produit n\'existe pas ou a été supprimé.');
    }

    /** @param array<int, array{path: string, width: int, height: int}> $photos */
    private static function attach(int $productId, array $photos, int $startPosition = 0): void
    {
        foreach ($photos as $i => $photo) {
            Database::run(
                'INSERT INTO product_images (product_id, path, width, height, position, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$productId, $photo['path'], $photo['width'], $photo['height'], $startPosition + $i, Clock::now()],
            );
        }
    }

    private static function checkRange(mixed $price, mixed $max): void
    {
        if ($max !== null && $price === null) {
            Validator::fail(['price' => 'Indiquez le prix minimum de la fourchette.'], self::LABELS);
        }
        if ($max !== null && (int) $max < (int) $price) {
            Validator::fail(['price_max' => 'Le prix maximum doit être supérieur au prix minimum.'], self::LABELS);
        }
    }

    private static function ref(?string $ref): ?int
    {
        if ($ref === null) {
            return null;
        }
        $id = Ids::tryDecode('ref', $ref);
        if ($id === null || Database::value('SELECT id FROM product_refs WHERE id = ?', [$id]) === null) {
            Validator::fail(['product_ref_id' => 'Référence produit inconnue.'], self::LABELS);
        }
        return $id;
    }
}
