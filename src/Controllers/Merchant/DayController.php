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
use Axi\Models\Customer;
use Axi\Models\Order;
use Axi\Models\Product;
use Axi\Models\Stall;
use Axi\Models\Subscription;
use Axi\Services\OrderService;

final class DayController
{
    use MerchantContext;

    private const ACTIVE = "'new', 'confirmed', 'ready'";

    /** GET /v1/merchant/day : écran « Ma journée » */
    public function day(Request $request): never
    {
        $stall = $this->stall($request);
        $stallId = (int) $stall['id'];

        $sales = Database::one(
            "SELECT COUNT(*) AS orders_count, COALESCE(SUM(so.subtotal), 0) AS amount
             FROM stall_orders so JOIN orders o ON o.id = so.order_id
             WHERE so.stall_id = ? AND so.status NOT IN ('pending_payment', 'cancelled') AND o.paid_at >= ?",
            [$stallId, Clock::localDayStart()]
        );
        $toHandle = (int) Database::value('SELECT COUNT(*) FROM stall_orders WHERE stall_id = ? AND status IN (' . self::ACTIVE . ')', [$stallId]);

        Response::ok([
            'date' => Clock::localDate(),
            'stall' => Stall::present($stall),
            'sales_today' => [
                'orders_count' => (int) $sales['orders_count'],
                'amount' => (int) $sales['amount'],
            ],
            'orders_to_handle_count' => $toHandle,
            'orders_to_handle' => $this->orders($stallId, ['new', 'confirmed', 'ready'], new Page(1, 20))[0],
            'connection' => Subscription::present(Subscription::latest($stallId), $stall['merchant_type']),
            'products' => Product::countForStall($stallId),
            'followers_count' => Stall::followersCount($stallId),
            'customers_count' => Customer::counts($stallId)['total'],
            'last_move' => Stall::lastMove($stallId),
        ]);
    }

    /** GET /v1/merchant/orders?status=new|confirmed|ready|received|cancelled&page= */
    public function index(Request $request): never
    {
        $stall = $this->stall($request);
        $in = Validator::validate($request->queryAll(), ['status' => 'string|in:new,confirmed,ready,received,cancelled']);
        $statuses = isset($in['status']) ? [$in['status']] : ['new', 'confirmed', 'ready', 'received', 'cancelled'];
        $page = Page::from($request);
        [$items, $total] = $this->orders((int) $stall['id'], $statuses, $page);
        Response::ok($items, 200, $page->meta($total));
    }

    /**
     * PATCH /v1/merchant/orders/{id} {status}
     * new → confirmed → ready (remis à l'agent Axi). Annulation possible avant « ready ».
     */
    public function update(Request $request): never
    {
        $stall = $this->stall($request);
        $in = Validator::validate($request->all(), ['status' => 'required|string|in:confirmed,ready,cancelled']);
        $stallOrder = Database::one('SELECT * FROM stall_orders WHERE id = ? AND stall_id = ?', [$request->id(), $stall['id']]);
        if ($stallOrder === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Commande introuvable');
        }

        if ($in['status'] === 'cancelled') {
            OrderService::cancelStallOrder($stallOrder);
        } else {
            $from = $in['status'] === 'confirmed' ? 'new' : 'confirmed';
            $done = Database::run('UPDATE stall_orders SET status = ? WHERE id = ? AND status = ?', [$in['status'], $stallOrder['id'], $from])->rowCount();
            if ($done !== 1) {
                throw new HttpException(409, 'ORDER_STATUS_CONFLICT', "Passage impossible de « {$stallOrder['status']} » à « {$in['status']} »");
            }
        }
        [$items] = $this->orders((int) $stall['id'], ['new', 'confirmed', 'ready', 'received', 'cancelled'], new Page(1, 1), (int) $stallOrder['id']);
        Response::ok($items[0]);
    }

    /** Commandes de l'étal avec client, livraison et lignes */
    private function orders(int $stallId, array $statuses, Page $page, ?int $onlyId = null): array
    {
        $where = ' WHERE so.stall_id = ? AND so.status IN (' . Database::in($statuses) . ')';
        $params = [$stallId, ...$statuses];
        if ($onlyId !== null) {
            $where .= ' AND so.id = ?';
            $params[] = $onlyId;
        }
        $total = (int) Database::value('SELECT COUNT(*) FROM stall_orders so' . $where, $params);
        $rows = Database::all(
            'SELECT so.id, so.status, so.subtotal, so.net_amount, so.created_at, o.ref, o.paid_at, o.contact_phone,
                    u.name AS client_name, d.mode AS delivery_mode, d.label AS delivery_label
             FROM stall_orders so
             JOIN orders o ON o.id = so.order_id
             JOIN users u ON u.id = o.client_id
             JOIN delivery_options d ON d.id = o.delivery_option_id' . $where . ' ORDER BY so.id DESC' . $page->limitSql(),
            $params
        );
        $items = Order::itemsByStallOrder(array_column($rows, 'id'));
        return [array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'ref' => $r['ref'],
            'status' => $r['status'],
            'subtotal' => (int) $r['subtotal'],
            'net_amount' => (int) $r['net_amount'],
            'paid_at' => Clock::iso($r['paid_at']),
            'client' => ['name' => $r['client_name'], 'phone' => $r['contact_phone']],
            'delivery' => ['mode' => $r['delivery_mode'], 'label' => $r['delivery_label']],
            'items' => $items[$r['id']] ?? [],
        ], $rows), $total];
    }
}
