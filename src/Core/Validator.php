<?php
declare(strict_types=1);

namespace Axi\Core;

/**
 * Validation légère. Règles séparées par « | » :
 *   required, string, max:100 (longueur), digits:6, in:a,b,c, phone, date (AAAA-MM-JJ),
 *   int, min:1, maxval:9999 (valeur), bool, ids (liste d'identifiants)
 * Les champs absents ne sont pas renvoyés ; int, bool, ids et phone sont convertis.
 */
final class Validator
{
    public static function validate(array $data, array $rules): array
    {
        $errors = [];
        $clean = [];

        foreach ($rules as $field => $ruleString) {
            $value = $data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $ruleList = explode('|', $ruleString);

            if ($value === null || $value === '') {
                if (in_array('required', $ruleList, true)) {
                    $errors[$field] = 'requis';
                }
                continue;
            }

            foreach ($ruleList as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, '');
                $error = match ($name) {
                    'required' => null,
                    'string' => is_string($value) ? null : 'doit être un texte',
                    'max' => is_string($value) && preg_match('/^.{0,' . (int) $arg . '}$/us', $value) ? null : "$arg caractères maximum",
                    'digits' => is_string($value) && preg_match('/^\d{' . (int) $arg . '}$/', $value) ? null : "doit contenir $arg chiffres",
                    'in' => in_array($value, explode(',', $arg), true) ? null : 'valeur non autorisée',
                    'phone' => is_string($value) && self::phone($value) !== null ? null : 'numéro de téléphone invalide',
                    'date' => is_string($value) && self::isDate($value) ? null : 'date attendue au format AAAA-MM-JJ',
                    'int' => self::toInt($value) !== null ? null : 'doit être un nombre entier',
                    'min' => (self::toInt($value) ?? PHP_INT_MIN) >= (int) $arg ? null : "minimum $arg",
                    'maxval' => (self::toInt($value) ?? PHP_INT_MAX) <= (int) $arg ? null : "maximum $arg",
                    'bool' => self::toBool($value) !== null ? null : 'doit valoir true ou false',
                    'ids' => self::toIds($value) !== null ? null : 'liste d\'identifiants attendue',
                    default => throw new \LogicException("Règle de validation inconnue : $name"),
                };
                if ($error !== null) {
                    $errors[$field] = $error;
                    break;
                }
            }

            if (!isset($errors[$field])) {
                $clean[$field] = match (true) {
                    in_array('int', $ruleList, true) => self::toInt($value),
                    in_array('bool', $ruleList, true) => self::toBool($value),
                    in_array('ids', $ruleList, true) => self::toIds($value),
                    in_array('phone', $ruleList, true) => self::phone($value),
                    default => $value,
                };
            }
        }

        if ($errors) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Données invalides', ['fields' => $errors]);
        }
        return $clean;
    }

    /**
     * Normalise un numéro au format international (+229...).
     * Bénin : numéros à 10 chiffres commençant par 01 depuis 2024 ;
     * un ancien numéro à 8 chiffres reçoit le préfixe 01.
     * Les autres pays (Togo, Nigeria, diaspora) sont acceptés au format +indicatif.
     */
    public static function phone(string $raw): ?string
    {
        $p = (string) preg_replace('/[\s.\-()]/', '', $raw);
        if (str_starts_with($p, '00')) {
            $p = '+' . substr($p, 2);
        }
        if (preg_match('/^\d{8}$/', $p)) {
            $p = '+22901' . $p;
        } elseif (preg_match('/^01\d{8}$/', $p)) {
            $p = '+229' . $p;
        } elseif (preg_match('/^\+229(\d{8})$/', $p, $m)) {
            $p = '+22901' . $m[1];
        }

        if (str_starts_with($p, '+229')) {
            return preg_match('/^\+22901\d{8}$/', $p) ? $p : null;
        }
        return preg_match('/^\+[1-9]\d{7,14}$/', $p) ? $p : null;
    }

    private static function toInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 1e12) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^-?\d{1,12}$/', $value)) {
            return (int) $value;
        }
        return null;
    }

    private static function toBool(mixed $value): ?bool
    {
        return match ($value) {
            true, 1, '1', 'true' => true,
            false, 0, '0', 'false' => false,
            default => null,
        };
    }

    private static function toIds(mixed $value): ?array
    {
        if (!is_array($value) || count($value) > 2000) {
            return null;
        }
        $ids = [];
        foreach ($value as $item) {
            $id = self::toInt($item);
            if ($id === null || $id < 1) {
                return null;
            }
            $ids[$id] = $id;
        }
        return array_values($ids);
    }

    private static function isDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
