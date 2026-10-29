<?php
declare(strict_types=1);

namespace Axi\Support;

use Axi\Core\Database;
use Axi\Core\Ids;

/** Objet « user » renvoyé partout par l'API */
final class Users
{
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public static function findByEmail(string $email): ?array
    {
        return Database::one('SELECT * FROM users WHERE email = ?', [$email]);
    }

    public static function present(array $u): array
    {
        $organization = null;
        if (!empty($u['organization_id'])) {
            $org = Database::one('SELECT id, name, type FROM organizations WHERE id = ?', [$u['organization_id']]);
            $organization = $org === null ? null : ['id' => Ids::encode('org', $org['id']), 'name' => $org['name'], 'type' => $org['type']];
        }
        return [
            'id' => Ids::encode('usr', $u['id']),
            'first_name' => $u['first_name'],
            'last_name' => $u['last_name'],
            'name' => $u['name'],
            'email' => $u['email'],
            'email_verified' => $u['email_verified_at'] !== null,
            'phone' => $u['phone'],
            'role' => $u['role'],
            'lang' => $u['lang'],
            'organization' => $organization,
        ];
    }

    public static function presentById(int $id): array
    {
        return self::present(self::find($id) ?? throw new \LogicException("Utilisateur $id introuvable"));
    }
}
