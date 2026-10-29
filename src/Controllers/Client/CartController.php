<?php
declare(strict_types=1);

namespace Axi\Controllers\Client;

use Axi\Core\HttpException;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Validator;
use Axi\Models\CartItem;
use Axi\Models\DeliveryOption;
use Axi\Models\Product;
use Axi\Services\CartPricing;

/** Panier multi-étals, conservé côté serveur (même panier sur tous les appareils) */
final class CartController
{
    /** GET /v1/cart?delivery_option_id= : panier groupé par étal, avec frais calculés */
    public function show(Request $request): never
    {
        $in = Validator::validate($request->queryAll(), ['delivery_option_id' => 'int|min:1']);
        $this->respond($request->userId(), $in['delivery_option_id'] ?? null);
    }

    /** POST /v1/cart/items {product_id, quantity} : ajoute (cumule la quantité) */
    public function add(Request $request): never
    {
        $in = Validator::validate($request->all(), [
            'product_id' => 'required|int|min:1',
            'quantity' => 'int|min:1|maxval:9999',
        ]);
        $product = Product::findOrderable($in['product_id']);
        if ($product === null) {
            throw new HttpException(409, 'PRODUCT_UNAVAILABLE', 'Ce produit n\'est pas disponible', ['product_ids' => [$in['product_id']]]);
        }
        $quantity = min(9999, CartItem::quantity($request->userId(), $in['product_id']) + ($in['quantity'] ?? 1));
        CartItem::set($request->userId(), $in['product_id'], $quantity);
        $this->respond($request->userId(), null);
    }

    /** PATCH /v1/cart/items/{id} {quantity} : 0 retire la ligne */
    public function update(Request $request): never
    {
        $productId = $request->id();
        $in = Validator::validate($request->all(), ['quantity' => 'required|int|min:0|maxval:9999']);
        if (CartItem::quantity($request->userId(), $productId) === 0) {
            throw new HttpException(404, 'NOT_FOUND', 'Produit absent du panier');
        }
        if ($in['quantity'] === 0) {
            CartItem::remove($request->userId(), $productId);
        } else {
            CartItem::set($request->userId(), $productId, $in['quantity']);
        }
        $this->respond($request->userId(), null);
    }

    /** DELETE /v1/cart/items/{id} */
    public function remove(Request $request): never
    {
        CartItem::remove($request->userId(), $request->id());
        $this->respond($request->userId(), null);
    }

    /** DELETE /v1/cart */
    public function clear(Request $request): never
    {
        CartItem::clear($request->userId());
        $this->respond($request->userId(), null);
    }

    private function respond(int $userId, ?int $deliveryOptionId): never
    {
        $option = null;
        if ($deliveryOptionId !== null) {
            $option = DeliveryOption::find($deliveryOptionId);
            if ($option === null) {
                throw new HttpException(422, 'VALIDATION_FAILED', 'Données invalides', ['fields' => ['delivery_option_id' => 'mode de livraison inconnu']]);
            }
        }
        Response::ok(CartPricing::quote(CartItem::lines($userId), $option));
    }
}
