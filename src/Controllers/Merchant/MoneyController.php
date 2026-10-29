<?php
declare(strict_types=1);

namespace Axi\Controllers\Merchant;

use Axi\Core\Clock;
use Axi\Core\Config;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Validator;
use Axi\Models\Promotion;
use Axi\Models\Subscription;
use Axi\Services\PaymentService;

final class MoneyController
{
    use MerchantContext;

    /** GET /v1/merchant/money : écran « Mon argent » */
    public function money(Request $request): never
    {
        $stall = $this->stall($request);
        $stallId = (int) $stall['id'];

        Response::ok([
            'balance' => self::balance($stallId),
            'movements' => $this->movements($stallId),
            'subscription' => Subscription::present(Subscription::latest($stallId), $stall['merchant_type']),
            'supplier_offers' => $this->offers($stall, 'stock'),
            'promotions' => Promotion::live('merchant', [
                'market_id' => (int) $stall['market_id'],
                'aisle_id' => (int) $stall['aisle_id'],
                'merchant_type' => $stall['merchant_type'],
            ]),
            'credit' => [
                'offers' => $this->offers($stall, 'cash'),
                'requests' => $this->creditRequests($stallId, 5),
                'shared_history' => $this->historySummary($stall),
            ],
        ]);
    }

    /** POST /v1/merchant/payouts {amount, phone?} : retrait vers le Mobile Money du commerçant */
    public function requestPayout(Request $request): never
    {
        $stall = $this->stall($request);
        $in = Validator::validate($request->all(), [
            'amount' => 'required|int|min:' . (int) Config::get('pricing.min_payout'),
            'phone' => 'string|phone',
        ]);

        $id = Database::transaction(static function () use ($stall, $in, $request): int {
            // Verrou sur l'étal : deux demandes simultanées ne peuvent pas dépasser le solde
            Database::run('SELECT id FROM stalls WHERE id = ? FOR UPDATE', [$stall['id']]);
            $available = self::balance((int) $stall['id'])['available'];
            if ($in['amount'] > $available) {
                throw new HttpException(422, 'INSUFFICIENT_BALANCE', 'Montant supérieur à l\'argent disponible', ['available' => $available]);
            }
            return Database::insert(
                'INSERT INTO payouts (stall_id, amount, phone, created_at) VALUES (?, ?, ?, ?)',
                [$stall['id'], $in['amount'], $in['phone'] ?? $stall['contact_phone'] ?? $request->user['phone'], Clock::now()]
            );
        });

        $payout = Database::one('SELECT * FROM payouts WHERE id = ?', [$id]);
        Response::ok(['payout' => self::presentPayout($payout), 'balance' => self::balance((int) $stall['id'])], 201);
    }

    /** GET /v1/merchant/payouts */
    public function payouts(Request $request): never
    {
        $stall = $this->stall($request);
        Response::ok(array_map(
            [self::class, 'presentPayout'],
            Database::all('SELECT * FROM payouts WHERE stall_id = ? ORDER BY id DESC LIMIT 50', [$stall['id']])
        ));
    }

    /** POST /v1/merchant/subscription/renew {provider} : paiement de l'abonnement (connexion incluse) */
    public function renewSubscription(Request $request): never
    {
        $stall = $this->stall($request);
        $in = Validator::validate($request->all(), ['provider' => 'required|string|in:' . implode(',', PaymentService::providers())]);
        $amount = Subscription::priceFor($stall['merchant_type']);
        $payment = PaymentService::start('subscription', $request->userId(), $amount, $in['provider'], null, (int) $stall['id'], 'Abonnement Axi ' . $stall['name']);
        Response::ok(['payment' => $payment], 201);
    }

    /**
     * POST /v1/merchant/credit-requests {offer_id?, amount, duration_months, purpose?, share_history: true}
     * L'accord explicite pour partager l'historique de ventes est obligatoire.
     */
    public function createCreditRequest(Request $request): never
    {
        $stall = $this->stall($request);
        $in = Validator::validate($request->all(), [
            'offer_id' => 'int|min:1',
            'amount' => 'required|int|min:1000|maxval:100000000',
            'duration_months' => 'required|int|min:1|maxval:60',
            'purpose' => 'string|max:300',
            'share_history' => 'required|bool',
        ]);
        if ($in['share_history'] !== true) {
            throw new HttpException(422, 'CONSENT_REQUIRED', 'Votre accord pour partager votre historique de ventes est nécessaire');
        }
        if (isset($in['offer_id'])) {
            $offer = Database::one('SELECT * FROM credit_offers WHERE id = ? AND active = 1', [$in['offer_id']]);
            if ($offer === null) {
                throw new HttpException(422, 'VALIDATION_FAILED', 'Données invalides', ['fields' => ['offer_id' => 'offre inconnue']]);
            }
            if ($in['amount'] < (int) $offer['min_amount'] || $in['amount'] > (int) $offer['max_amount'] || $in['duration_months'] > (int) $offer['max_months']) {
                throw new HttpException(422, 'OUTSIDE_OFFER_LIMITS', 'Montant ou durée hors des limites de l\'offre', [
                    'min_amount' => (int) $offer['min_amount'], 'max_amount' => (int) $offer['max_amount'], 'max_months' => (int) $offer['max_months'],
                ]);
            }
        }
        $open = Database::value("SELECT 1 FROM credit_requests WHERE stall_id = ? AND status IN ('submitted', 'sent')", [$stall['id']]);
        if ($open !== null) {
            throw new HttpException(409, 'CREDIT_REQUEST_OPEN', 'Une demande de crédit est déjà en cours');
        }

        $now = Clock::now();
        Database::insert(
            'INSERT INTO credit_requests (stall_id, offer_id, amount, duration_months, purpose, share_history, consent_at, created_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)',
            [$stall['id'], $in['offer_id'] ?? null, $in['amount'], $in['duration_months'], $in['purpose'] ?? null, $now, $now]
        );
        Response::ok($this->creditRequests((int) $stall['id'], 1)[0], 201);
    }

    /** GET /v1/merchant/credit-requests */
    public function creditRequestsIndex(Request $request): never
    {
        Response::ok($this->creditRequests((int) $this->stall($request)['id']));
    }

    private function creditRequests(int $stallId, int $limit = 50): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'offer' => $r['offer_id'] === null ? null : ['id' => (int) $r['offer_id'], 'partner' => $r['partner_name'], 'title' => $r['title']],
            'amount' => (int) $r['amount'],
            'duration_months' => (int) $r['duration_months'],
            'purpose' => $r['purpose'],
            'status' => $r['status'],
            'approved_amount' => $r['approved_amount'] === null ? null : (int) $r['approved_amount'],
            'consent_at' => Clock::iso($r['consent_at']),
            'created_at' => Clock::iso($r['created_at']),
        ], Database::all(
            "SELECT cr.*, co.partner_name, co.title FROM credit_requests cr LEFT JOIN credit_offers co ON co.id = cr.offer_id
             WHERE cr.stall_id = ? ORDER BY cr.id DESC LIMIT $limit",
            [$stallId]
        ));
    }

    /** Soldes : à recevoir (séquestre), disponible (libéré moins retraits) */
    public static function balance(int $stallId): array
    {
        $sales = Database::one(
            "SELECT COALESCE(SUM(CASE WHEN status IN ('new', 'confirmed', 'ready') THEN net_amount END), 0) AS to_receive,
                    COALESCE(SUM(CASE WHEN status = 'received' THEN net_amount END), 0) AS released
             FROM stall_orders WHERE stall_id = ?",
            [$stallId]
        );
        $payouts = Database::one(
            "SELECT COALESCE(SUM(CASE WHEN status = 'paid' THEN amount END), 0) AS paid,
                    COALESCE(SUM(CASE WHEN status = 'requested' THEN amount END), 0) AS requested
             FROM payouts WHERE stall_id = ?",
            [$stallId]
        );
        return [
            'to_receive' => (int) $sales['to_receive'],
            'available' => (int) $sales['released'] - (int) $payouts['paid'] - (int) $payouts['requested'],
            'payouts_pending' => (int) $payouts['requested'],
            'paid_out' => (int) $payouts['paid'],
        ];
    }

    public static function presentPayout(array $p): array
    {
        return [
            'id' => (int) $p['id'],
            'amount' => (int) $p['amount'],
            'phone' => $p['phone'],
            'status' => $p['status'],
            'created_at' => Clock::iso($p['created_at']),
            'processed_at' => Clock::iso($p['processed_at']),
        ];
    }

    private function movements(int $stallId): array
    {
        $rows = Database::all(
            "SELECT 'sale' AS type, o.ref, so.net_amount AS amount, so.status, COALESCE(o.received_at, o.paid_at) AS at
             FROM stall_orders so JOIN orders o ON o.id = so.order_id
             WHERE so.stall_id = ? AND so.status IN ('new', 'confirmed', 'ready', 'received')
             UNION ALL
             SELECT 'payout' AS type, NULL AS ref, amount, status, created_at AS at FROM payouts WHERE stall_id = ?
             ORDER BY at DESC LIMIT 20",
            [$stallId, $stallId]
        );
        return array_map(static fn (array $r): array => [
            'type' => $r['type'],
            'ref' => $r['ref'],
            'amount' => (int) $r['amount'],
            // sale : new|confirmed|ready = à recevoir, received = libéré ; payout : requested|paid|rejected
            'status' => $r['status'],
            'at' => Clock::iso($r['at']),
        ], $rows);
    }

    private function offers(array $stall, string $kind): array
    {
        return array_map(static fn (array $o): array => [
            'id' => (int) $o['id'],
            'partner' => $o['partner_name'],
            'supplier' => $o['supplier_name'],
            'title' => $o['title'],
            'description' => $o['description'],
            'min_amount' => (int) $o['min_amount'],
            'max_amount' => (int) $o['max_amount'],
            'max_months' => (int) $o['max_months'],
            'rate' => $o['rate_label'],
            'sponsored' => (bool) $o['sponsored'],
        ], Database::all(
            'SELECT * FROM credit_offers WHERE active = 1 AND kind = ? AND (audience_aisle_id IS NULL OR audience_aisle_id = ?)
             ORDER BY sponsored DESC, id LIMIT 5',
            [$kind, $stall['aisle_id']]
        ));
    }

    /** Ce que verra le partenaire financier si le commerçant donne son accord */
    private function historySummary(array $stall): array
    {
        $since = Clock::now(-86400 * 182);
        $row = Database::one(
            "SELECT COUNT(*) AS orders_count, COALESCE(SUM(so.subtotal), 0) AS sales_total
             FROM stall_orders so JOIN orders o ON o.id = so.order_id
             WHERE so.stall_id = ? AND so.status = 'received' AND o.paid_at >= ?",
            [$stall['id'], $since]
        );
        $created = (string) Database::value('SELECT created_at FROM stalls WHERE id = ?', [$stall['id']]);
        $months = max(1, (int) floor((time() - strtotime($created . ' UTC')) / (86400 * 30.4)));
        return [
            'period_months' => 6,
            'months_on_axi' => $months,
            'orders_count' => (int) $row['orders_count'],
            'sales_total' => (int) $row['sales_total'],
            'monthly_average' => (int) round((int) $row['sales_total'] / min(6, $months)),
        ];
    }
}
