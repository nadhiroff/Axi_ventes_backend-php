<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Database;
use Axi\Core\Validator;

/** Champs d'un étal, saisis par le commerçant (création, gestion) ou par un agent Axi (espace web) */
final class StallInput
{
    public const LABELS = [
        'name' => 'Nom de l\'étal', 'tagline' => 'Description', 'site_id' => 'Site', 'aisle_id' => 'Allée',
        'row' => 'Rangée', 'stand' => 'Stand', 'phone' => 'Téléphone', 'whatsapp' => 'WhatsApp',
        'delivery_note' => 'Livraison', 'map_url' => 'Plan', 'merchant_type' => 'Type de commerçant',
        'site_other' => 'Nom du marché', 'aisle_other' => 'Nom de l\'allée',
    ];

    /** Choix « Autre » : l'étal est rangé dans ces entrées en attendant qu'un agent crée la vraie allée ou le vrai marché */
    public const OTHER_SITE = 'autre';
    public const OTHER_AISLE = 'autres';

    /** Champs d'emplacement : leur changement retire le badge « vérifié » jusqu'à la visite d'un agent */
    public const LOCATION_FIELDS = ['site_id', 'site_other', 'row_label', 'stand', 'has_physical_place'];

    /** @return array colonnes de la table stalls */
    public static function validate(array $data, bool $creating, ?array $only = null): array
    {
        $rules = [
            'name' => ($creating ? 'required|' : '') . 'string|max:100',
            'tagline' => 'string|max:160',
            'site_id' => ($creating ? 'required|' : '') . 'string|max:30',
            'aisle_id' => ($creating ? 'required|' : '') . 'string|max:30',
            'site_other' => 'string|max:100',
            'aisle_other' => 'string|max:100',
            'row' => 'string|max:30',
            'stand' => 'string|max:20',
            'has_physical_place' => 'bool',
            'merchant_type' => 'in:retail,wholesale',
            'phone' => 'phone',
            'whatsapp' => 'phone',
            'accepts_voice_messages' => 'bool',
            'pay_on_delivery' => 'bool',
            'delivery_note' => 'string|max:160',
            'is_open' => 'bool',
            'map_url' => 'string|max:255',
        ];
        if ($only !== null) {
            $rules = array_intersect_key($rules, array_flip($only));
        }
        $in = Validator::validate($data, $rules, self::LABELS);

        if (isset($in['site_id']) && Database::value('SELECT id FROM sites WHERE id = ? AND active = 1', [$in['site_id']]) === null) {
            Validator::fail(['site_id' => 'Site inconnu.'], self::LABELS);
        }
        if (isset($in['aisle_id']) && Database::value('SELECT id FROM aisles WHERE id = ? AND active = 1', [$in['aisle_id']]) === null) {
            Validator::fail(['aisle_id' => 'Allée inconnue.'], self::LABELS);
        }
        if (isset($in['map_url']) && !preg_match('#^https://#', $in['map_url'])) {
            Validator::fail(['map_url' => 'Lien https:// attendu.'], self::LABELS);
        }

        // « Autre » : un nom libre est obligatoire ; un vrai choix de la liste efface le nom libre
        foreach (['site' => self::OTHER_SITE, 'aisle' => self::OTHER_AISLE] as $name => $other) {
            if (!isset($in[$name . '_id'])) {
                unset($in[$name . '_other']);
                continue;
            }
            if ($in[$name . '_id'] === $other) {
                if (mb_strlen(trim((string) ($in[$name . '_other'] ?? ''))) < 2) {
                    Validator::fail([$name . '_other' => 'Écrivez le nom ' . ($name === 'site' ? 'du marché.' : 'de l\'allée.')], self::LABELS);
                }
                $in[$name . '_other'] = trim($in[$name . '_other']);
            } else {
                $in[$name . '_other'] = null;
            }
        }

        $columns = [];
        foreach ($in as $key => $value) {
            $columns[$key === 'row' ? 'row_label' : $key] = is_bool($value) ? (int) $value : $value;
        }
        // Champs texte vidés volontairement : null
        foreach (['row' => 'row_label', 'stand' => 'stand', 'phone' => 'phone', 'whatsapp' => 'whatsapp', 'delivery_note' => 'delivery_note', 'map_url' => 'map_url'] as $key => $column) {
            if (($only === null || in_array($key, $only, true)) && array_key_exists($key, $data) && ($data[$key] === null || $data[$key] === '')) {
                $columns[$column] = null;
            }
        }
        return $columns;
    }
}
