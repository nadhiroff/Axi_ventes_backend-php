<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Services\Payment\Escrow;

/**
 * Crédit d'un commerçant : Axi ne prête pas et ne touche pas à l'argent de l'IMF.
 * demande → offre de l'IMF → acceptation du commerçant → versement par l'IMF → remboursements → soldé.
 * Le commerçant peut accepter une retenue : un pourcentage de chaque versement Axi rembourse son crédit.
 */
final class Credit
{
    public const LABELS = [
        'new' => 'Demande envoyée', 'in_review' => 'En cours d\'étude', 'offered' => 'Offre reçue', 'accepted' => 'Offre acceptée, versement en attente',
        'granted' => 'Crédit en cours', 'declined' => 'Demande refusée', 'repaid' => 'Crédit remboursé',
    ];
    /** Demande en cours : pas de nouvelle demande tant qu'elle n'est pas terminée */
    public const OPEN = ['new', 'in_review', 'offered', 'accepted', 'granted'];
    public const MAX_WITHHOLD_PCT = 50;

    public static function remaining(array $c): int
    {
        return max(0, (int) $c['repay_total'] - (int) $c['repaid_amount']);
    }

    public static function commission(int $amount): int
    {
        return (int) round($amount * Settings::int('credit_commission_rate') / 100);
    }

    /** Montant à retenir sur un versement au commerçant (0 sans crédit en cours ou sans retenue acceptée) */
    public static function withholding(int $userId, int $payout): int
    {
        $c = Database::one('SELECT * FROM credits WHERE merchant_user_id = ? AND status = \'granted\' AND withhold_pct > 0 ORDER BY id LIMIT 1', [$userId]);
        return $c === null ? 0 : min(self::remaining($c), (int) floor($payout * (int) $c['withhold_pct'] / 100));
    }

    /** Remboursement : plafonné au reste dû ; le crédit se solde tout seul. @return int montant enregistré */
    public static function repay(int $creditId, int $amount, string $source, ?int $payoutId, ?int $actorId): int
    {
        [$applied, $c, $left] = Database::transaction(static function () use ($creditId, $amount, $source, $payoutId, $actorId): array {
            $c = Database::one('SELECT * FROM credits WHERE id = ? FOR UPDATE', [$creditId]) ?? throw new HttpException(404, 'NOT_FOUND', 'Crédit introuvable.');
            if ($c['status'] !== 'granted') {
                throw new HttpException(409, 'CREDIT_NOT_ACTIVE', 'Ce crédit n\'est pas en cours de remboursement.');
            }
            $applied = min($amount, self::remaining($c));
            if ($applied <= 0) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Montant de remboursement invalide.');
            }
            Database::run('INSERT INTO credit_repayments (credit_id, amount, source, payout_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)', [$creditId, $applied, $source, $payoutId, $actorId, Clock::now()]);
            $left = self::remaining($c) - $applied;
            Database::run('UPDATE credits SET repaid_amount = repaid_amount + ?, status = ? WHERE id = ?', [$applied, $left === 0 ? 'repaid' : 'granted', $creditId]);
            return [$applied, $c, $left];
        });
        Notifier::send((int) $c['merchant_user_id'], 'account', $left === 0 ? 'Crédit entièrement remboursé' : 'Remboursement enregistré', Escrow::fcfa($applied) . ' enregistrés sur votre crédit.' . ($left === 0 ? ' Bravo, il est soldé.' : ' Reste à rembourser : ' . Escrow::fcfa($left) . '.'), [], [], true);
        return $applied;
    }
}
