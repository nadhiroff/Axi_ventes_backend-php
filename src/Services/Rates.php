<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Clock;
use Axi\Core\Database;

/**
 * Taux de change pour l'affichage en euro ou en dollar (les paiements restent en F CFA).
 * Euro : parité fixe officielle du franc CFA (1 € = 655,957 F). Dollar : taux réel relu sur
 * open.er-api.com au plus toutes les 12 h et gardé en base ; en cas de panne, le dernier taux connu.
 */
final class Rates
{
    public const XOF_PER_EUR = 655.957;
    private const REFRESH_SECONDS = 12 * 3600;

    /** @return array{base: string, rates: array<string, float>, updated_at: ?string} F CFA pour 1 unité */
    public static function current(): array
    {
        $updated = Settings::text('rate_updated_at');
        if (strtotime($updated . ' UTC') < time() - self::REFRESH_SECONDS) {
            self::refresh();
            Settings::forget();
        }
        return [
            'base' => 'XOF',
            'rates' => ['XOF' => 1.0, 'EUR' => self::XOF_PER_EUR, 'USD' => (float) Settings::text('rate_xof_per_usd')],
            'updated_at' => Clock::iso(Settings::text('rate_updated_at')),
        ];
    }

    private static function refresh(): void
    {
        // Un seul appel à la fois, même si beaucoup de téléphones demandent le taux en même temps
        $got = (int) Database::value('SELECT GET_LOCK(?, 0)', ['axi_rates']);
        if ($got !== 1) {
            return;
        }
        try {
            $r = HttpClient::json('GET', 'https://open.er-api.com/v6/latest/USD', [], null, 5);
            $rate = $r['body']['rates']['XOF'] ?? null;
            if ($r['status'] === 200 && is_numeric($rate) && $rate > 100 && $rate < 2000) {
                Database::run('UPDATE settings SET v = ? WHERE k = \'rate_xof_per_usd\'', [number_format((float) $rate, 4, '.', '')]);
            }
            // Même en cas d'échec : prochain essai dans 12 h (le dernier taux connu reste affiché)
            Database::run('UPDATE settings SET v = ? WHERE k = \'rate_updated_at\'', [Clock::now()]);
        } finally {
            Database::value('SELECT RELEASE_LOCK(?)', ['axi_rates']);
        }
    }
}
