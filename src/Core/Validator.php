<?php
declare(strict_types=1);

namespace Axi\Core;

/**
 * Validation légère. Règles séparées par « | » :
 *   required, string, max:100 / minlen:8 (longueur), digits:6, in:a,b,c, phone, email,
 *   int, min:1, maxval:9999 (valeur), bool
 * Les champs absents ne sont pas renvoyés ; int, bool, phone et email sont normalisés.
 * Erreur : 422 VALIDATION_ERROR, error.fields = { champ: message affichable }.
 */
final class Validator
{
    /** $labels : noms des champs pour le message principal (« Mot de passe : 8 caractères minimum. ») */
    public static function validate(array $data, array $rules, array $labels = []): array
    {
        $errors = [];
        $clean = [];

        foreach ($rules as $field => $ruleString) {
            $value = $data[$field] ?? null;
            $ruleList = explode('|', $ruleString);
            // Les mots de passe ne sont jamais modifiés (espaces compris)
            if (is_string($value) && !in_array('raw', $ruleList, true)) {
                $value = trim($value);
            }

            if ($value === null || $value === '') {
                if (in_array('required', $ruleList, true)) {
                    $errors[$field] = 'Ce champ est obligatoire.';
                }
                continue;
            }

            foreach ($ruleList as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, '');
                $error = match ($name) {
                    'required', 'raw' => null,
                    'string' => is_string($value) ? null : 'Texte attendu.',
                    'max' => is_string($value) && mb_strlen($value) <= (int) $arg ? null : "$arg caractères maximum.",
                    'minlen' => is_string($value) && mb_strlen($value) >= (int) $arg ? null : "$arg caractères minimum.",
                    'digits' => is_string($value) && preg_match('/^\d{' . (int) $arg . '}$/', $value) ? null : "Le code contient $arg chiffres.",
                    'in' => in_array($value, explode(',', $arg), true) ? null : 'Valeur non autorisée.',
                    'phone' => is_string($value) && self::phone($value) !== null ? null : 'Ce numéro de téléphone ne semble pas valide.',
                    'email' => is_string($value) && self::email($value) !== null ? null : 'Cette adresse email ne semble pas valide.',
                    'int' => self::toInt($value) !== null ? null : 'Nombre entier attendu.',
                    'min' => (self::toInt($value) ?? PHP_INT_MIN) >= (int) $arg ? null : "Minimum $arg.",
                    'maxval' => (self::toInt($value) ?? PHP_INT_MAX) <= (int) $arg ? null : "Maximum $arg.",
                    'bool' => self::toBool($value) !== null ? null : 'Valeur oui ou non attendue.',
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
                    in_array('phone', $ruleList, true) => self::phone($value),
                    in_array('email', $ruleList, true) => self::email($value),
                    default => $value,
                };
            }
        }

        if ($errors) {
            self::fail($errors, $labels);
        }
        return $clean;
    }

    /** Lève une 422 VALIDATION_ERROR (utilisable aussi pour des contrôles faits hors des règles) */
    public static function fail(array $errors, array $labels = []): never
    {
        $field = array_key_first($errors);
        $message = isset($labels[$field]) ? $labels[$field] . ' : ' . self::lcfirst($errors[$field]) : 'Vérifiez les informations saisies.';
        throw new HttpException(422, 'VALIDATION_ERROR', $message, ['fields' => $errors]);
    }

    public static function email(string $raw): ?string
    {
        $email = mb_strtolower(trim($raw));
        return mb_strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private static function lcfirst(string $text): string
    {
        return mb_strtolower(mb_substr($text, 0, 1)) . mb_substr($text, 1);
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

    private static function isDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
