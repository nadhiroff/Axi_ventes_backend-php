<?php
declare(strict_types=1);

namespace Axi\Controllers\Merchant;

use Axi\Core\Clock;
use Axi\Core\Config;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Ids;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Validator;
use Axi\Support\Merchant;

/** « Mon argent » : séquestre, abonnement, offre fournisseur, crédit, accord de partage avec l'IMF */
final class MoneyController
{
    public function money(Request $req): never
    {
        $stall = Merchant::stall($req);
        $mfi = $this->partner();
        $partner = $mfi['name'] ?? 'notre IMF partenaire';
        $sharing = $mfi !== null && $this->consentId($req->userId(), (int) $mfi['id']) !== null;
        $pending = $mfi !== null && Database::value(
            'SELECT 1 FROM credits WHERE merchant_user_id = ? AND status IN (\'new\', \'in_review\') LIMIT 1',
            [$req->userId()],
        ) !== null;
        $months = $this->monthsOfHistory((int) $stall['id']);

        Response::ok([
            // Payé par le client, gardé par Axi jusqu'à la réception
            'pending_amount' => (int) Database::value(
                'SELECT COALESCE(SUM(items_total), 0) FROM order_stalls WHERE stall_id = ? AND status IN (\'to_prepare\', \'ready\', \'picked_up\')',
                [$stall['id']],
            ),
            'subscription' => $this->subscription($stall),
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
                'enabled' => $sharing,
                'text' => 'Seulement si vous le décidez. Axi ne vend jamais vos données ni celles de vos clients.',
            ],
        ], 200, 300);
    }

    /** Refusé tant que le partage d'historique avec l'IMF n'est pas accepté */
    public function requestCredit(Request $req): never
    {
        Merchant::stall($req);
        $mfi = $this->partner() ?? throw new HttpException(503, 'NO_CREDIT_PARTNER', 'Aucun partenaire de crédit n\'est encore disponible.');
        if ($this->consentId($req->userId(), (int) $mfi['id']) === null) {
            throw new HttpException(422, 'DATA_SHARING_REQUIRED', 'Pour demander un crédit, acceptez d\'abord de partager votre historique avec ' . $mfi['name'] . '.');
        }
        if (Database::value('SELECT 1 FROM credits WHERE merchant_user_id = ? AND status IN (\'new\', \'in_review\')', [$req->userId()]) !== null) {
            throw new HttpException(409, 'CREDIT_REQUEST_PENDING', 'Votre demande de crédit est déjà en cours d\'étude.');
        }
        Database::run('INSERT INTO credits (merchant_user_id, organization_id, requested_at) VALUES (?, ?, ?)', [$req->userId(), $mfi['id'], Clock::now()]);
        Response::ok(['message' => 'Demande envoyée. ' . $mfi['name'] . ' vous appellera sous 48 h.']);
    }

    /** Case « Partager mon historique » : accord explicite, révocable à tout moment */
    public function dataSharing(Request $req): never
    {
        Merchant::stall($req);
        $enabled = Validator::validate($req->all(), ['enabled' => 'required|bool'], ['enabled' => 'Partage'])['enabled'];
        $mfi = $this->partner() ?? throw new HttpException(503, 'NO_CREDIT_PARTNER', 'Aucun partenaire de crédit n\'est encore disponible.');
        $consent = $this->consentId($req->userId(), (int) $mfi['id']);
        if ($enabled && $consent === null) {
            Database::run('INSERT INTO data_sharing_consents (merchant_user_id, organization_id, granted_at) VALUES (?, ?, ?)', [$req->userId(), $mfi['id'], Clock::now()]);
        } elseif (!$enabled && $consent !== null) {
            Database::run('UPDATE data_sharing_consents SET revoked_at = ? WHERE id = ?', [Clock::now(), $consent]);
        }
        Response::ok(['enabled' => $enabled]);
    }

    /** IMF partenaire (la première active ; une seule pendant le pilote) */
    private function partner(): ?array
    {
        return Database::one('SELECT id, name FROM organizations WHERE type = \'mfi\' AND status = \'active\' ORDER BY id LIMIT 1');
    }

    private function consentId(int $userId, int $orgId): ?int
    {
        $id = Database::value('SELECT id FROM data_sharing_consents WHERE merchant_user_id = ? AND organization_id = ? AND revoked_at IS NULL', [$userId, $orgId]);
        return $id === null ? null : (int) $id;
    }

    private function monthsOfHistory(int $stallId): int
    {
        $first = Database::value('SELECT MIN(o.paid_at) FROM order_stalls os JOIN orders o ON o.id = os.order_id WHERE os.stall_id = ? AND o.paid_at IS NOT NULL', [$stallId]);
        return $first === null ? 0 : max(1, (int) floor((time() - strtotime($first . ' UTC')) / (30 * 86400)));
    }

    private function subscription(array $stall): array
    {
        $sub = Database::one('SELECT * FROM subscriptions WHERE stall_id = ? ORDER BY ends_on DESC LIMIT 1', [$stall['id']]);
        $plan = $sub['plan'] ?? $stall['merchant_type'];
        $amount = (int) ($sub['amount'] ?? Config::get("pricing.subscription.$plan"));
        $active = $sub !== null && $sub['ends_on'] >= Clock::localDate();
        return [
            'status' => $active ? 'active' : 'expired',
            'status_label' => $active ? 'Actif' : 'Expiré',
            'plan_label' => ($plan === 'wholesale' ? 'Formule Gros' : 'Formule Étal') . ' · ' . number_format($amount, 0, ',', ' ') . ' F par mois · connexion incluse',
            'note' => ($sub['paid_by'] ?? null) === 'anagem' ? 'Inclus dans votre place à ' . $stall['site_name'] : null,
        ];
    }

    /** Campagne « Offre fournisseur » validée qui vise l'allée et le site du commerçant */
    private function supplierOffer(array $stall): ?array
    {
        $now = Clock::now();
        $c = Database::one(
            'SELECT * FROM campaigns WHERE format = \'supplier_offer\' AND status = \'active\' AND title IS NOT NULL
               AND (aisle_id IS NULL OR aisle_id = ?) AND (site_id IS NULL OR site_id = ?)
               AND (starts_at IS NULL OR starts_at <= ?) AND (ends_at IS NULL OR ends_at > ?)
             ORDER BY MD5(CONCAT(id, CURDATE())) LIMIT 1',
            [$stall['aisle_id'], $stall['site_id'], $now, $now],
        );
        return $c === null ? null : [
            'id' => Ids::encode('off', $c['id']),
            'label' => 'Sponsorisé',
            'title' => $c['title'],
            'text' => (string) $c['body'],
            'url' => $c['url'],
        ];
    }
}
