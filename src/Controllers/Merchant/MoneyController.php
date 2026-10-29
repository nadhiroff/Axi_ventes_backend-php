<?php
declare(strict_types=1);

namespace Axi\Controllers\Merchant;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Ids;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Url;
use Axi\Core\Validator;
use Axi\Services\Payment\Escrow;
use Axi\Services\Payment\Gateways;
use Axi\Services\Settings;
use Axi\Support\Merchant;
use Axi\Support\Subscription;

/**
 * « Mon argent » : argent bloqué (payé, en attente de réception), libéré, versé ; abonnement (payé via la
 * passerelle) ; offre fournisseur ; crédit ; accord explicite et révocable de partage avec l'IMF.
 */
final class MoneyController
{
    public function money(Request $req): never
    {
        $stall = Merchant::stall($req);
        $mfi = $this->partner();
        $partner = $mfi['name'] ?? 'notre IMF partenaire';
        $consent = $mfi === null ? null : $this->consent($req->userId(), (int) $mfi['id']);
        $pending = Database::value('SELECT 1 FROM credits WHERE merchant_user_id = ? AND status IN (\'new\', \'in_review\') LIMIT 1', [$req->userId()]) !== null;
        $months = $this->monthsOfHistory((int) $stall['id']);
        $sums = Database::one(
            "SELECT COALESCE(SUM(CASE WHEN escrow_state IN ('held','disputed') THEN net_amount END), 0) AS held,
                    COALESCE(SUM(CASE WHEN escrow_state = 'released' AND payout_id IS NULL THEN net_amount END), 0) AS released,
                    COALESCE(SUM(CASE WHEN escrow_state = 'released' AND payout_id IS NOT NULL THEN net_amount END), 0) AS paid_out
             FROM order_stalls WHERE stall_id = ?",
            [$stall['id']],
        );
        $payouts = Database::all('SELECT * FROM payouts WHERE stall_id = ? ORDER BY id DESC LIMIT 20', [$stall['id']]);

        Response::ok([
            // Payé par le client, gardé par Axi jusqu'à la réception
            'pending_amount' => (int) $sums['held'],
            'released_amount' => (int) $sums['released'],
            'paid_out_amount' => (int) $sums['paid_out'],
            'commission_rate_pct' => Settings::int('commission_rate'),
            'subscription' => Subscription::present($stall),
            'supplier_offer' => $this->supplierOffer($stall),
            'credit' => [
                'title' => 'Votre historique ouvre l\'accès au crédit',
                'text' => ($months > 0 ? Merchant::plural($months, 'mois', 'mois') . ' de ventes enregistrées sur Axi. ' : 'Vendez sur Axi pour construire votre historique. ')
                    . "Demandez un crédit à $partner sans garantie à fournir.",
                'can_request' => $mfi !== null && !$pending,
                'request_pending' => $pending,
            ],
            'data_sharing' => [
                'partner_name' => $partner,
                'enabled' => $consent !== null,
                'scope' => $consent['scope'] ?? 'Historique des ventes Axi (montants mensuels, ancienneté)',
                'granted_at' => Clock::iso($consent['granted_at'] ?? null),
                'text' => 'Seulement si vous le décidez, et révocable à tout moment. Axi ne vend jamais vos données ni celles de vos clients.',
            ],
            'payouts' => array_map(static fn (array $p): array => [
                'id' => Ids::encode('vrs', $p['id']),
                'amount' => (int) $p['amount'],
                'status' => $p['status'],
                'status_label' => ['pending' => 'En cours', 'simulated' => 'Versé (simulation, mode test)', 'paid' => 'Versé', 'failed' => 'Échec'][$p['status']],
                'created_at' => Clock::iso($p['created_at']),
            ], $payouts),
        ], 200, 60);
    }

    /**
     * Payer l'abonnement via la passerelle : { period: month | year } (année = réduction réglable en base).
     * Réutilise le paiement en attente de la même durée : un double appui ne crée jamais deux paiements.
     */
    public function paySubscription(Request $req): never
    {
        $stall = Merchant::stall($req);
        $period = Validator::validate($req->all(), ['period' => 'in:month,year'], ['period' => 'Durée'])['period'] ?? 'month';
        $payment = Database::withLock('axi_sub_' . $stall['id'], 15, static function () use ($req, $stall, $period): array {
            $existing = Database::one(
                'SELECT p.* FROM payments p JOIN subscriptions s ON s.id = p.subscription_id
                 WHERE s.stall_id = ? AND s.period = ? AND p.status = \'pending\' AND p.created_at > ? ORDER BY p.id DESC LIMIT 1',
                [$stall['id'], $period, Clock::now(-3600)],
            );
            if ($existing !== null) {
                return $existing;
            }
            // Une demande en attente pour l'autre durée est abandonnée (jamais deux paiements ouverts)
            Database::run(
                'UPDATE payments p JOIN subscriptions s ON s.id = p.subscription_id SET p.status = \'cancelled\', p.failure_reason = \'remplacé par une autre durée\', s.status = \'cancelled\'
                 WHERE s.stall_id = ? AND p.status = \'pending\' AND p.provider_ref IS NULL',
                [$stall['id']],
            );
            $plan = $stall['merchant_type'];
            $amount = Settings::subscriptionPrice($plan, $period);
            return Database::transaction(static function () use ($req, $stall, $plan, $period, $amount): array {
                $subId = Database::insert(
                    'INSERT INTO subscriptions (stall_id, plan, period, amount, paid_by, status, starts_on, ends_on, created_by, created_at) VALUES (?, ?, ?, ?, \'merchant\', \'pending\', ?, ?, ?, ?)',
                    [$stall['id'], $plan, $period, $amount, Clock::localDate(), Clock::localDate(), $req->userId(), Clock::now()],
                );
                $id = Database::insert(
                    'INSERT INTO payments (purpose, subscription_id, user_id, provider, reference, phone, amount, created_at) VALUES (\'subscription\', ?, ?, ?, ?, ?, ?, ?)',
                    [$subId, $req->userId(), Gateways::get()->name(), 'axi_abo_' . $subId . '_' . bin2hex(random_bytes(3)), $stall['phone'], $amount, Clock::now()],
                );
                Escrow::log('subscription_created', ['payment' => $id], 'merchant', $req->userId(), null, 'pending', null, $amount);
                return Database::one('SELECT * FROM payments WHERE id = ?', [$id]);
            });
        });
        Escrow::ensureInitiated((int) $payment['id']);
        Response::ok($this->presentPayment((int) $payment['id']));
    }

    /** Vérification du paiement d'abonnement auprès de la passerelle (retour de la page de paiement) */
    public function verifySubscription(Request $req): never
    {
        $stall = Merchant::stall($req);
        $reference = (string) $req->input('reference', '');
        $payment = Database::one(
            'SELECT p.* FROM payments p JOIN subscriptions s ON s.id = p.subscription_id WHERE p.reference = ? AND s.stall_id = ?',
            [$reference, $stall['id']],
        ) ?? throw new HttpException(404, 'NOT_FOUND', 'Paiement introuvable.');
        $tx = $req->input('transaction_id');
        Escrow::ensureInitiated((int) $payment['id']);
        Escrow::refresh((int) $payment['id'], 'merchant', $req->userId(), is_scalar($tx) && $payment['provider'] === 'kkiapay' ? (string) $tx : null);
        Response::ok($this->presentPayment((int) $payment['id']) + ['subscription' => Subscription::present(Merchant::stall($req))]);
    }

    /** Refusé tant que le partage d'historique avec l'IMF n'est pas accepté */
    public function requestCredit(Request $req): never
    {
        Merchant::stall($req);
        $amount = Validator::validate($req->all(), ['amount' => 'int|min:5000|maxval:50000000'], ['amount' => 'Montant souhaité'])['amount'] ?? null;
        $mfi = $this->partner() ?? throw new HttpException(503, 'NO_CREDIT_PARTNER', 'Aucun partenaire de crédit n\'est encore disponible.');
        if ($this->consent($req->userId(), (int) $mfi['id']) === null) {
            throw new HttpException(422, 'DATA_SHARING_REQUIRED', 'Pour demander un crédit, acceptez d\'abord de partager votre historique avec ' . $mfi['name'] . '.');
        }
        if (Database::value('SELECT 1 FROM credits WHERE merchant_user_id = ? AND status IN (\'new\', \'in_review\')', [$req->userId()]) !== null) {
            throw new HttpException(409, 'CREDIT_REQUEST_PENDING', 'Votre demande de crédit est déjà en cours d\'étude.');
        }
        Database::run('INSERT INTO credits (merchant_user_id, organization_id, amount_requested, requested_at) VALUES (?, ?, ?, ?)', [$req->userId(), $mfi['id'], $amount, Clock::now()]);
        Response::ok(['message' => 'Demande envoyée. ' . $mfi['name'] . ' vous contactera sous 48 h.']);
    }

    /** Case « Partager mon historique avec l'IMF » : accord explicite (date, périmètre), révocable à tout moment */
    public function dataSharing(Request $req): never
    {
        Merchant::stall($req);
        $enabled = Validator::validate($req->all(), ['enabled' => 'required|bool'], ['enabled' => 'Partage'])['enabled'];
        $mfi = $this->partner() ?? throw new HttpException(503, 'NO_CREDIT_PARTNER', 'Aucun partenaire de crédit n\'est encore disponible.');
        $consent = $this->consent($req->userId(), (int) $mfi['id']);
        if ($enabled && $consent === null) {
            Database::run('INSERT INTO data_sharing_consents (merchant_user_id, organization_id, granted_at) VALUES (?, ?, ?)', [$req->userId(), $mfi['id'], Clock::now()]);
        } elseif (!$enabled && $consent !== null) {
            Database::run('UPDATE data_sharing_consents SET revoked_at = ? WHERE id = ?', [Clock::now(), $consent['id']]);
        }
        Response::ok(['enabled' => $enabled]);
    }

    private function presentPayment(int $paymentId): array
    {
        $p = Database::one('SELECT p.*, s.period FROM payments p JOIN subscriptions s ON s.id = p.subscription_id WHERE p.id = ?', [$paymentId]);
        return [
            'period' => $p['period'],
            'reference' => $p['reference'],
            'provider' => $p['provider'],
            'status' => $p['status'],
            'amount' => (int) $p['amount'],
            'payment_url' => $p['status'] === 'pending' ? $p['payment_url'] : null,
            'return_url_prefix' => Url::to('/v1/payments/return'),
            'message' => match ($p['status']) {
                'paid' => 'Abonnement payé pour ' . ($p['period'] === 'year' ? 'un an' : 'un mois') . ' : vos produits sont tous visibles.',
                'pending' => $p['payment_url'] === null ? 'La demande de paiement n\'a pas pu partir. Réessayez : vous ne serez débité qu\'une seule fois.' : 'Validez le paiement dans la page qui s\'ouvre.',
                default => 'Paiement non abouti. Aucun montant n\'a été prélevé.',
            },
        ];
    }

    /** IMF partenaire (la première active ; une seule pendant le pilote) */
    private function partner(): ?array
    {
        return Database::one('SELECT id, name FROM organizations WHERE type = \'mfi\' AND status = \'active\' ORDER BY id LIMIT 1');
    }

    private function consent(int $userId, int $orgId): ?array
    {
        return Database::one('SELECT * FROM data_sharing_consents WHERE merchant_user_id = ? AND organization_id = ? AND revoked_at IS NULL', [$userId, $orgId]);
    }

    private function monthsOfHistory(int $stallId): int
    {
        $first = Database::value('SELECT MIN(o.paid_at) FROM order_stalls os JOIN orders o ON o.id = os.order_id WHERE os.stall_id = ? AND o.paid_at IS NOT NULL', [$stallId]);
        return $first === null ? 0 : max(1, (int) floor((time() - strtotime($first . ' UTC')) / (30 * 86400)));
    }

    /** Campagne « Offre fournisseur » validée qui vise l'allée et le site du commerçant */
    private function supplierOffer(array $stall): ?array
    {
        $now = Clock::now();
        $c = Database::one(
            'SELECT * FROM campaigns WHERE format = \'supplier_offer\' AND status = \'active\'
               AND (aisle_id IS NULL OR aisle_id = ?) AND (site_id IS NULL OR site_id = ?)
               AND (starts_at IS NULL OR starts_at <= ?) AND (ends_at IS NULL OR ends_at > ?)
             ORDER BY MD5(CONCAT(id, CURDATE())) LIMIT 1',
            [$stall['aisle_id'], $stall['site_id'], $now, $now],
        );
        if ($c !== null) {
            Database::run('UPDATE campaigns SET impressions = impressions + 1 WHERE id = ?', [$c['id']]);
        }
        return $c === null ? null : [
            'id' => Ids::encode('off', $c['id']),
            'label' => 'Sponsorisé',
            'title' => $c['title'],
            'text' => $c['body'],
            'url' => $c['url'],
        ];
    }
}
