<?php
declare(strict_types=1);

namespace Axi\Support;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Services\Settings;

/**
 * Abonnement du commerçant (au mois ou à l'année) et limites sans abonnement :
 * - catalogue limité à « free_product_limit » produits en vente (les autres sont masqués, pas supprimés) ;
 * - pas de messages groupés (nouvelle adresse, arrivage, relances).
 * Tout le reste fonctionne ; l'application annonce clairement ce que l'abonnement débloque.
 */
final class Subscription
{
    public const LIMITS_TEXT = 'Sans abonnement : %d produits en vente au maximum, pas de messages groupés à vos clients.';
    public const BENEFITS = [
        'Produits en vente sans limite',
        'Messages groupés à vos clients : nouvelle adresse, arrivages, relances',
        'Connexion Internet incluse',
    ];

    /** Annonces d'arrivage : durée maximale (jours) et nombre de photos, sans abonnement / abonné */
    public const ANNOUNCEMENT_FREE = ['days' => 7, 'photos' => 2];
    public const ANNOUNCEMENT_PRO = ['days' => 30, 'photos' => 5];

    /** @return array{days: int, photos: int} */
    public static function announcementLimits(int $stallId): array
    {
        return self::active($stallId) ? self::ANNOUNCEMENT_PRO : self::ANNOUNCEMENT_FREE;
    }

    public static function current(int $stallId): ?array
    {
        return Database::one(
            'SELECT * FROM subscriptions WHERE stall_id = ? AND status = \'active\' AND ends_on >= ? ORDER BY ends_on DESC LIMIT 1',
            [$stallId, Clock::localDate()],
        );
    }

    public static function active(int $stallId): bool
    {
        return self::current($stallId) !== null;
    }

    public static function productLimit(): int
    {
        return max(1, Settings::int('free_product_limit'));
    }

    /** Refuse une action réservée aux abonnés (402 SUBSCRIPTION_REQUIRED, avec le message affiché tel quel) */
    public static function require(int $stallId, string $what): void
    {
        if (!self::active($stallId)) {
            throw new HttpException(402, 'SUBSCRIPTION_REQUIRED', "$what est réservé aux commerçants abonnés. Abonnez-vous dans « Mon argent » : au mois ou à l'année.");
        }
    }

    /** Avant de mettre un produit de plus en vente : vérifie la limite sans abonnement */
    public static function checkProductSlot(int $stallId, ?int $exceptProductId = null): void
    {
        if (self::active($stallId)) {
            return;
        }
        $limit = self::productLimit();
        $count = (int) Database::value(
            'SELECT COUNT(*) FROM products WHERE stall_id = ? AND status = \'active\'' . ($exceptProductId !== null ? ' AND id <> ?' : ''),
            $exceptProductId !== null ? [$stallId, $exceptProductId] : [$stallId],
        );
        if ($count >= $limit) {
            throw new HttpException(402, 'SUBSCRIPTION_REQUIRED', "Sans abonnement, $limit produits peuvent être en vente en même temps. Abonnez-vous pour en publier plus, ou retirez un produit.");
        }
    }

    /** Sans abonnement : seuls les N produits les plus récents restent en vente, les autres sont masqués (« paused ») */
    public static function enforce(int $stallId): int
    {
        if (self::active($stallId)) {
            return 0;
        }
        $keep = array_column(Database::all(
            'SELECT id FROM products WHERE stall_id = ? AND status = \'active\' ORDER BY is_today DESC, updated_at DESC, id DESC LIMIT ' . self::productLimit(),
            [$stallId],
        ), 'id');
        if ($keep === []) {
            return 0;
        }
        return Database::run(
            'UPDATE products SET status = \'paused\' WHERE stall_id = ? AND status = \'active\' AND id NOT IN (' . Database::in($keep) . ')',
            [$stallId, ...$keep],
        )->rowCount();
    }

    /** Abonnement payé : les produits masqués reviennent en vente */
    public static function resume(int $stallId): void
    {
        Database::run('UPDATE products SET status = \'active\' WHERE stall_id = ? AND status = \'paused\'', [$stallId]);
    }

    /** Bloc « abonnement » de l'API : état, prix au mois et à l'année, limites et avantages */
    public static function present(array $stall): array
    {
        $current = self::current((int) $stall['id']);
        $last = $current ?? Database::one('SELECT * FROM subscriptions WHERE stall_id = ? AND status = \'active\' ORDER BY ends_on DESC LIMIT 1', [$stall['id']]);
        $plan = $stall['merchant_type'];
        $monthly = Settings::subscriptionAmount($plan);
        $yearly = Settings::subscriptionPrice($plan, 'year');
        $active = $current !== null;
        $limit = self::productLimit();
        $daysLeft = $active ? (int) floor((strtotime($current['ends_on']) - strtotime(Clock::localDate())) / 86400) + 1 : 0;
        return [
            'status' => $active ? 'active' : 'expired',
            'status_label' => $active ? 'Actif' : ($last === null ? 'Pas encore abonné' : 'Expiré'),
            'plan' => $plan,
            'plan_label' => $plan === 'wholesale' ? 'Formule Gros' : 'Formule Étal',
            'period' => $current['period'] ?? null,
            'amount' => $monthly,
            'prices' => [
                'month' => $monthly,
                'year' => $yearly,
                'year_discount_pct' => Settings::int('subscription_yearly_discount'),
                'year_saving' => max(0, $monthly * 12 - $yearly),
            ],
            'ends_on' => $last['ends_on'] ?? null,
            'days_left' => $daysLeft,
            'next_due' => $last === null ? Clock::localDate() : date('Y-m-d', strtotime($last['ends_on'] . ' +1 day')),
            'note' => ($last['paid_by'] ?? null) === 'anagem' ? 'Inclus dans votre place à ' . ($stall['site_name'] ?? 'votre marché') : null,
            'can_pay' => ($current['paid_by'] ?? 'merchant') === 'merchant',
            'limits' => [
                'product_limit' => $active ? null : $limit,
                'active_products' => (int) Database::value('SELECT COUNT(*) FROM products WHERE stall_id = ? AND status = \'active\'', [$stall['id']]),
                'paused_products' => (int) Database::value('SELECT COUNT(*) FROM products WHERE stall_id = ? AND status = \'paused\'', [$stall['id']]),
                'broadcasts' => $active,
                'announcement_days' => ($active ? self::ANNOUNCEMENT_PRO : self::ANNOUNCEMENT_FREE)['days'],
                'announcement_photos' => ($active ? self::ANNOUNCEMENT_PRO : self::ANNOUNCEMENT_FREE)['photos'],
                'text' => $active ? null : sprintf(self::LIMITS_TEXT, $limit),
            ],
            'benefits' => self::BENEFITS,
        ];
    }
}
