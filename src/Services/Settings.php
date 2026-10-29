<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Database;
use Axi\Core\HttpException;

/**
 * Montants et règles métier, en base (table settings) : hypothèses de la note de cadrage,
 * modifiables par un agent Axi dans l'espace web, jamais écrites en dur dans le code.
 */
final class Settings
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Database::all('SELECT * FROM settings ORDER BY k') as $row) {
                self::$cache[$row['k']] = $row;
            }
        }
        return self::$cache;
    }

    public static function int(string $key): int
    {
        return (int) self::raw($key);
    }

    public static function bool(string $key): bool
    {
        return self::raw($key) === '1';
    }

    public static function text(string $key): string
    {
        return self::raw($key);
    }

    /** Commission Axi en fraction (0,02 pour 2 %) */
    public static function commissionRate(): float
    {
        return self::int('commission_rate') / 100;
    }

    public static function receptionFee(string $mode): int
    {
        return self::int('fee_' . $mode);
    }

    public static function subscriptionAmount(string $plan): int
    {
        return self::int('subscription_' . $plan);
    }

    /** Modification depuis l'espace web, avec contrôle de chaque valeur */
    public static function set(string $key, string $value, int $userId): void
    {
        $row = self::all()[$key] ?? throw new HttpException(404, 'NOT_FOUND', 'Réglage inconnu.');
        $value = trim($value);
        $valid = match ($row['type']) {
            'int' => preg_match('/^\d{1,9}$/', $value) === 1,
            'bool' => in_array($value, ['0', '1'], true),
            'percent' => in_array($value, ['0', '2', '3', '4'], true),
            'text' => $value !== '' && mb_strlen($value) <= 255,
        };
        if (!$valid) {
            throw new HttpException(422, 'VALIDATION_ERROR', match ($row['type']) {
                'percent' => 'La commission vaut 0 (désactivée), 2, 3 ou 4 %.',
                'bool' => 'Valeur oui ou non attendue.',
                'int' => 'Nombre entier positif attendu.',
                default => 'Texte de 1 à 255 caractères attendu.',
            });
        }
        Database::run('UPDATE settings SET v = ?, updated_by = ? WHERE k = ?', [$value, $userId, $key]);
        self::$cache = null;
    }

    private static function raw(string $key): string
    {
        return (string) (self::all()[$key]['v'] ?? throw new \LogicException("Réglage $key absent de la table settings"));
    }
}
