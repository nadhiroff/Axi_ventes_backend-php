<?php
declare(strict_types=1);

namespace Axi\Core;

/**
 * Validation légère. Règles séparées par « | » :
 *   required, string, digits:6, max:100, in:a,b,c, phone
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
                    'digits' => is_string($value) && preg_match('/^\d{' . (int) $arg . '}$/', $value) ? null : "doit contenir $arg chiffres",
                    'max' => is_string($value) && preg_match('/^.{0,' . (int) $arg . '}$/us', $value) ? null : "$arg caractères maximum",
                    'in' => in_array($value, explode(',', $arg), true) ? null : 'valeur non autorisée',
                    'phone' => is_string($value) && self::phone($value) !== null ? null : 'numéro de téléphone invalide',
                    default => throw new \LogicException("Règle de validation inconnue : $name"),
                };
                if ($error !== null) {
                    $errors[$field] = $error;
                    break;
                }
            }

            if (!isset($errors[$field])) {
                $clean[$field] = in_array('phone', $ruleList, true) ? self::phone($value) : $value;
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
}
