<?php
declare(strict_types=1);

namespace Axi\Controllers\Client;

use Axi\Core\HttpException;
use Axi\Core\Page;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Validator;
use Axi\Models\DeliveryOption;
use Axi\Models\Order;
use Axi\Services\OrderService;
use Axi\Services\PaymentService;

final class OrderController
{
    /**
     * POST /v1/checkout : panier => commande + lancement du paiement séquestre.
     * {delivery_option_id, provider, address?, contact_phone?, note?}
     */
    public function checkout(Request $request): never
    {
        $in = Validator::validate($request->all(), [
            'delivery_option_id' => 'required|int|min:1',
            'provider' => 'required|string|in:' . implode(',', PaymentService::providers()),
            'address' => 'string|max:255',
            'contact_phone' => 'string|phone',
            'note' => 'string|max:300',
        ]);
        $option = DeliveryOption::find($in['delivery_option_id']);
        if ($option === null) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Données invalides', ['fields' => ['delivery_option_id' => 'mode de livraison inconnu']]);
        }
        if ($option['mode'] !== 'pickup' && !isset($in['address'])) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Données invalides', ['fields' => ['address' => 'requise pour ce mode de livraison']]);
        }

        // Fournisseur vérifié avant de créer la commande et de vider le panier
        PaymentService::gateway($in['provider']);

        $orderId = OrderService::createFromCart(
            $request->userId(),
            $option,
            $in['address'] ?? $option['address'],
            $in['contact_phone'] ?? $request->user['phone'],
            $in['note'] ?? null
        );
        $order = Order::findForClient($orderId, $request->userId());
        $payment = PaymentService::start('order', $request->userId(), (int) $order['total'], $in['provider'], $orderId, null, 'Commande Axi ' . $order['ref']);

        Response::ok(['order' => Order::present($order), 'payment' => $payment], 201);
    }

    /** POST /v1/orders/{id}/pay {provider} : relancer un paiement (échec, abandon) */
    public function pay(Request $request): never
    {
        $order = $this->ownOrder($request);
        if ($order['status'] !== 'pending_payment') {
            throw new HttpException(409, 'ORDER_STATUS_CONFLICT', 'Cette commande n\'attend pas de paiement');
        }
        $in = Validator::validate($request->all(), ['provider' => 'required|string|in:' . implode(',', PaymentService::providers())]);
        $payment = PaymentService::start('order', $request->userId(), (int) $order['total'], $in['provider'], (int) $order['id'], null, 'Commande Axi ' . $order['ref']);
        Response::ok(['payment' => $payment], 201);
    }

    /** GET /v1/orders?page= */
    public function index(Request $request): never
    {
        $page = Page::from($request);
        [$items, $total] = Order::listForClient($request->userId(), $page);
        Response::ok($items, 200, $page->meta($total));
    }

    /** GET /v1/orders/{id} */
    public function show(Request $request): never
    {
        Response::ok(Order::present($this->ownOrder($request)));
    }

    /** POST /v1/orders/{id}/confirm-receipt : le client a reçu, l'argent est libéré aux commerçants */
    public function confirmReceipt(Request $request): never
    {
        $order = $this->ownOrder($request);
        if ($order['status'] !== 'paid' || !OrderService::release((int) $order['id'])) {
            throw new HttpException(409, 'ORDER_STATUS_CONFLICT', 'Cette commande ne peut pas être confirmée');
        }
        Response::ok(Order::present(Order::findForClient((int) $order['id'], $request->userId())));
    }

    private function ownOrder(Request $request): array
    {
        $order = Order::findForClient($request->id(), $request->userId());
        if ($order === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Commande introuvable');
        }
        return $order;
    }
}
