<?php
declare(strict_types=1);

namespace Axi\Controllers\Client;

use Axi\Core\HttpException;
use Axi\Core\Page;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Validator;
use Axi\Models\Aisle;
use Axi\Models\Follow;
use Axi\Models\Product;
use Axi\Models\Stall;

final class StallController
{
    /** GET /v1/aisles/{id}/stalls?market_id=&type=&verified=&q=&page= */
    public function byAisle(Request $request): never
    {
        $aisleId = $request->id();
        if (Aisle::find($aisleId) === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Allée introuvable');
        }
        $filters = Validator::validate($request->queryAll(), [
            'market_id' => 'int|min:1',
            'type' => 'string|in:retail,wholesale',
            'verified' => 'bool',
            'q' => 'string|max:60',
        ]);
        $page = Page::from($request);
        [$items, $total] = Stall::listByAisle($aisleId, $filters, $page);
        Response::ok($items, 200, $page->meta($total));
    }

    /** GET /v1/stalls/{id} : page d'un étal (identité, emplacement, produits du jour, catalogue) */
    public function show(Request $request): never
    {
        $row = Stall::findPublic($request->id());
        if ($row === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Étal introuvable');
        }
        $stallId = (int) $row['id'];
        $page = new Page(1, 20);
        [$products, $total] = Product::listForStall($stallId, $page);

        Response::ok([
            'stall' => Stall::present($row) + [
                'merchant_name' => $row['merchant_name'],
                'description' => $row['description'],
                'contact_phone' => $row['contact_phone'],
                'whatsapp' => $row['whatsapp'],
                'followers_count' => Stall::followersCount($stallId),
                'is_followed' => $request->user !== null && Follow::isFollowing($request->userId(), $stallId),
            ],
            'payment' => [
                'escrow' => true,
                'label' => 'Paiement gardé par Axi jusqu\'à la réception',
                'methods' => ['mobile_money'],
            ],
            'last_move' => Stall::lastMove($stallId),
            'today_products' => Product::todayForStall($stallId),
            'products' => $products,
            'products_meta' => $page->meta($total),
        ]);
    }

    /** GET /v1/stalls/{id}/products?today=1&page= */
    public function products(Request $request): never
    {
        $row = Stall::findPublic($request->id());
        if ($row === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Étal introuvable');
        }
        $in = Validator::validate($request->queryAll(), ['today' => 'bool']);
        $page = Page::from($request);
        [$items, $total] = Product::listForStall((int) $row['id'], $page, $in['today'] ?? false);
        Response::ok($items, 200, $page->meta($total));
    }

    /** POST /v1/stalls/{id}/follow */
    public function follow(Request $request): never
    {
        $row = Stall::findPublic($request->id());
        if ($row === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Étal introuvable');
        }
        Follow::follow($request->userId(), (int) $row['id']);
        Response::ok(['is_followed' => true, 'followers_count' => Stall::followersCount((int) $row['id'])]);
    }

    /** DELETE /v1/stalls/{id}/follow */
    public function unfollow(Request $request): never
    {
        $stallId = $request->id();
        Follow::unfollow($request->userId(), $stallId);
        Response::ok(['is_followed' => false, 'followers_count' => Stall::followersCount($stallId)]);
    }
}
