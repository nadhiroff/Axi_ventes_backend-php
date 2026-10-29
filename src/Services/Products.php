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
 * Création et modification des produits, par le commerçant ou par un agent à l'enrôlement.
 * Le prix est toujours celui du commerçant : Axi ne fixe aucun prix.
 */
final class Products
{
    private const LABELS = ['name' => 'Nom du produit', 'price' => 'Prix', 'price_max' => 'Prix maximum', 'product_ref_id' => 'Référence'];

    /** POST multipart : photo (facultative), name, price (entier ou vide = à débattre), price_max, is_today, in_stock */
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

        $photo = $req->file('photo');
        $image = $photo === null ? null : Uploads::photo($photo);
        $id = Database::insert(
            'INSERT INTO products (stall_id, product_ref_id, name, price_amount, price_max, in_stock, is_today, image_path, image_width, image_height, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$stallId, self::ref($in['product_ref_id'] ?? null), $in['name'], $in['price'] ?? null, $in['price_max'] ?? null,
                (int) ($in['in_stock'] ?? true), (int) ($in['is_today'] ?? false),
                $image['path'] ?? null, $image['width'] ?? null, $image['height'] ?? null, $createdBy, Clock::now(), Clock::now()],
        );
        Database::run('UPDATE stalls SET updated_at = ? WHERE id = ?', [Clock::now(), $stallId]);
        return Stalls::product(self::find($id, $stallId));
    }

    /** PATCH JSON : is_today, in_stock (contrat) ; name, price, price_max (le commerçant gère ses prix) */
    public static function update(int $productId, int $stallId, Request $req): array
    {
        $product = self::find($productId, $stallId);
        $body = $req->all();
        $in = Validator::validate($body, [
            'name' => 'string|max:120',
            'price' => 'int|min:1|maxval:100000000',
            'price_max' => 'int|min:1|maxval:100000000',
            'is_today' => 'bool',
            'in_stock' => 'bool',
        ], self::LABELS);

        $fields = [];
        foreach (['name' => 'name', 'is_today' => 'is_today', 'in_stock' => 'in_stock', 'price' => 'price_amount', 'price_max' => 'price_max'] as $key => $column) {
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
        self::checkRange($fields['price_amount'] ?? $product['price_amount'], array_key_exists('price_max', $fields) ? $fields['price_max'] : $product['price_max']);

        if ($fields !== []) {
            Database::updateById('products', $productId, $fields + ['updated_at' => Clock::now()]);
            Database::run('UPDATE stalls SET updated_at = ? WHERE id = ?', [Clock::now(), $stallId]);
        }
        return Stalls::product(self::find($productId, $stallId));
    }

    public static function delete(int $productId, int $stallId): void
    {
        self::find($productId, $stallId);
        Database::run('UPDATE products SET status = \'deleted\', updated_at = ? WHERE id = ?', [Clock::now(), $productId]);
    }

    public static function find(int $productId, int $stallId): array
    {
        return Database::one('SELECT * FROM products WHERE id = ? AND stall_id = ? AND status = \'active\'', [$productId, $stallId])
            ?? throw new HttpException(404, 'PRODUCT_NOT_FOUND', 'Ce produit n\'existe pas ou a été supprimé.');
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
