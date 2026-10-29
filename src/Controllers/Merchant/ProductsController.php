<?php
declare(strict_types=1);

namespace Axi\Controllers\Merchant;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Services\Products;
use Axi\Services\StallInput;
use Axi\Support\Merchant;
use Axi\Support\Stalls;

/** « Mon étal » : le commerçant gère lui-même sa vitrine, ses produits et ses prix */
final class ProductsController
{
    public function stall(Request $req): never
    {
        $stall = Merchant::stall($req);
        Response::ok([
            'stall' => Stalls::detail($stall, null),
            'products' => array_map([Stalls::class, 'product'], Stalls::products((int) $stall['id'])),
        ], 200, 60);
    }

    /** Vitrine : description, contacts, ouverture… L'emplacement ne change que par un agent. */
    public function updateStall(Request $req): never
    {
        $stall = Merchant::stall($req);
        $columns = StallInput::validate($req->all(), false, StallInput::MERCHANT_FIELDS);
        if ($columns !== []) {
            Database::updateById('stalls', (int) $stall['id'], $columns + ['updated_at' => Clock::now()]);
        }
        Response::ok(['stall' => Stalls::detail(Merchant::stall($req), null)]);
    }

    public function create(Request $req): never
    {
        $stall = Merchant::stall($req);
        Response::ok(Products::create((int) $stall['id'], $req, $req->userId()), 201);
    }

    public function update(Request $req): never
    {
        $stall = Merchant::stall($req);
        $id = $req->id('prd', 'PRODUCT_NOT_FOUND', 'Ce produit n\'existe pas ou a été supprimé.');
        Response::ok(Products::update($id, (int) $stall['id'], $req));
    }

    public function delete(Request $req): never
    {
        $stall = Merchant::stall($req);
        $id = $req->id('prd', 'PRODUCT_NOT_FOUND', 'Ce produit n\'existe pas ou a été supprimé.');
        Products::delete($id, (int) $stall['id']);
        Response::ok(['deleted' => true]);
    }
}
