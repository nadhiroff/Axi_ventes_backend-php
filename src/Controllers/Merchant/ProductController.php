<?php
declare(strict_types=1);

namespace Axi\Controllers\Merchant;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Page;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Validator;
use Axi\Models\Product;
use Axi\Services\UploadService;

/**
 * Produits de l'étal. Création et modification en multipart/form-data (champs « photo » et « audio »)
 * ou en JSON. PHP ne lit les fichiers qu'en POST : la modification utilise donc POST /{id}.
 */
final class ProductController
{
    use MerchantContext;

    private const RULES = [
        'name' => 'string|max:120',
        'description' => 'string|max:500',
        'unit' => 'string|max:40',
        'price' => 'int|min:1|maxval:100000000',
        'price_max' => 'int|min:1|maxval:100000000',
        'wholesale_price' => 'int|min:1|maxval:100000000',
        'wholesale_min_qty' => 'int|min:2|maxval:9999',
        'stock_status' => 'string|in:available,low,out',
        'catalog_item_id' => 'int|min:1',
        'is_today' => 'bool',
    ];

    /** GET /v1/merchant/products?today=1&page= */
    public function index(Request $request): never
    {
        $stall = $this->stall($request);
        $in = Validator::validate($request->queryAll(), ['today' => 'bool']);
        $page = Page::from($request);
        [$items, $total] = Product::listForStall((int) $stall['id'], $page, $in['today'] ?? false);
        Response::ok($items, 200, $page->meta($total));
    }

    /** POST /v1/merchant/products : ajout en photo ou en vocal */
    public function create(Request $request): never
    {
        $stall = $this->stall($request);
        $in = Validator::validate($request->all(), ['price' => 'required|' . self::RULES['price']] + self::RULES);
        $audioFile = $request->file('audio');
        if (!isset($in['name']) && $audioFile === null) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Données invalides', ['fields' => ['name' => 'requis (ou envoyez un vocal)']]);
        }
        $this->checkConsistency($in, null);

        $photo = UploadService::store($request->file('photo'), 'image', 'photo');
        $audio = UploadService::store($audioFile, 'audio', 'audio');

        $id = Database::insert(
            'INSERT INTO products (stall_id, catalog_item_id, name, description, unit, price, price_max, wholesale_price, wholesale_min_qty, photo_path, audio_path, stock_status, daily_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $stall['id'], $in['catalog_item_id'] ?? null, $in['name'] ?? 'Produit en vocal', $in['description'] ?? null, $in['unit'] ?? null,
                $in['price'], $in['price_max'] ?? null, $in['wholesale_price'] ?? null, $in['wholesale_min_qty'] ?? null,
                $photo, $audio, $in['stock_status'] ?? 'available', ($in['is_today'] ?? true) ? Clock::localDate() : null,
            ]
        );
        Response::ok(Product::present(Product::findForStall($id, (int) $stall['id'])), 201);
    }

    /** POST /v1/merchant/products/{id} : modification partielle */
    public function update(Request $request): never
    {
        $stall = $this->stall($request);
        $product = $this->ownProduct($request, (int) $stall['id']);
        $in = Validator::validate($request->all(), self::RULES);
        $this->checkConsistency($in, $product);

        $fields = array_intersect_key($in, array_flip(Product::EDITABLE));
        if (isset($in['is_today'])) {
            $fields['daily_date'] = $in['is_today'] ? Clock::localDate() : null;
        }
        if (($photo = UploadService::store($request->file('photo'), 'image', 'photo')) !== null) {
            $fields['photo_path'] = $photo;
            UploadService::delete($product['photo_path']);
        }
        if (($audio = UploadService::store($request->file('audio'), 'audio', 'audio')) !== null) {
            $fields['audio_path'] = $audio;
            UploadService::delete($product['audio_path']);
        }
        Database::updateById('products', (int) $product['id'], $fields);
        Response::ok(Product::present(Product::findForStall((int) $product['id'], (int) $stall['id'])));
    }

    /** DELETE /v1/merchant/products/{id} : retiré de la vente (archivé, l'historique des commandes reste) */
    public function delete(Request $request): never
    {
        $stall = $this->stall($request);
        $product = $this->ownProduct($request, (int) $stall['id']);
        Database::run("UPDATE products SET status = 'archived' WHERE id = ?", [$product['id']]);
        Database::run('DELETE FROM cart_items WHERE product_id = ?', [$product['id']]);
        Response::noContent();
    }

    private function ownProduct(Request $request, int $stallId): array
    {
        $product = Product::findForStall($request->id(), $stallId);
        if ($product === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Produit introuvable');
        }
        return $product;
    }

    /** Fourchette et prix de gros cohérents avec le prix de base */
    private function checkConsistency(array $in, ?array $current): void
    {
        $price = $in['price'] ?? (int) ($current['price'] ?? 0);
        $errors = [];
        if (isset($in['price_max']) && $in['price_max'] < $price) {
            $errors['price_max'] = 'doit être supérieur ou égal au prix';
        }
        if (isset($in['wholesale_price']) && $in['wholesale_price'] > $price) {
            $errors['wholesale_price'] = 'doit être inférieur ou égal au prix';
        }
        $hasWholesale = isset($in['wholesale_price']) || ($current['wholesale_price'] ?? null) !== null;
        $hasMinQty = isset($in['wholesale_min_qty']) || ($current['wholesale_min_qty'] ?? null) !== null;
        if ($hasWholesale !== $hasMinQty) {
            $errors['wholesale_min_qty'] = 'prix de gros et quantité minimale vont ensemble';
        }
        if ($errors) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Données invalides', ['fields' => $errors]);
        }
    }
}
