<?php
declare(strict_types=1);

namespace Axi\Support;

use Axi\Core\Database;
use Axi\Core\Ids;

/** Objet « user » renvoyé partout par l'API. 3 rôles : client, merchant, partner. */
final class Users
{
    public const PARTNER_TYPES = ['axi' => 'Agent Axi', 'anagem' => 'ANaGeM', 'advertiser' => 'Annonceur', 'mfi' => 'IMF'];

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public static function findByEmail(string $email): ?array
    {
        return Database::one('SELECT * FROM users WHERE email = ? AND status <> \'deleted\'', [$email]);
    }

    public static function present(array $u): array
    {
        $organization = null;
        if (!empty($u['organization_id'])) {
            $org = Database::one('SELECT id, name, type FROM organizations WHERE id = ?', [$u['organization_id']]);
            $organization = $org === null ? null : ['id' => Ids::encode('org', $org['id']), 'name' => $org['name'], 'type' => $org['type']];
        }
        $stall = $u['role'] === 'merchant' ? Database::one('SELECT id, merchant_type, verified_at FROM stalls WHERE user_id = ?', [$u['id']]) : null;
        $application = $u['role'] === 'client'
            ? Database::one('SELECT id, status, reject_reason, created_at FROM merchant_applications WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$u['id']])
            : null;
        return [
            'id' => Ids::encode('usr', $u['id']),
            'first_name' => $u['first_name'],
            'last_name' => $u['last_name'],
            'name' => $u['name'],
            'email' => $u['email'],
            'email_verified' => $u['email_verified_at'] !== null,
            'phone' => $u['phone'],
            'avatar' => Stalls::image($u['avatar_path'] ?? null, 600, 600),
            'avatar_visibility' => $u['avatar_visibility'] ?? 'everyone',
            'bio' => $u['bio'] ?? null,
            'role' => $u['role'],
            'lang' => $u['lang'],
            'organization' => $organization,
            'merchant' => $u['role'] === 'merchant' ? [
                'has_stall' => $stall !== null,
                'stall_id' => $stall === null ? null : Ids::encode('stl', $stall['id']),
                'merchant_type' => $stall['merchant_type'] ?? null,
                'verified' => ($stall['verified_at'] ?? null) !== null,
            ] : null,
            // Demande « Devenir commerçant » du client (null s'il n'en a jamais envoyé)
            'merchant_application' => $application === null ? null : [
                'id' => Ids::encode('dem', $application['id']),
                'status' => $application['status'],
                'reject_reason' => $application['reject_reason'],
                'created_at' => \Axi\Core\Clock::iso($application['created_at']),
            ],
            // Félicitations à afficher une seule fois après la validation par un agent
            'merchant_welcome' => (bool) ($u['merchant_welcome_pending'] ?? false),
        ];
    }

    /**
     * Photo de profil vue par quelqu'un d'autre, selon le choix de la personne : tout le monde, ses contacts
     * (conversation ouverte avec elle) ou personne.
     */
    public static function avatarFor(array $u, ?int $viewerId, bool $isContact): ?array
    {
        $visible = match ($u['avatar_visibility'] ?? 'everyone') {
            'everyone' => true,
            'contacts' => $isContact || $viewerId === (int) $u['id'],
            default => $viewerId === (int) $u['id'],
        };
        return $visible ? Stalls::image($u['avatar_path'] ?? null, 600, 600) : null;
    }

    public static function presentById(int $id): array
    {
        return self::present(self::find($id) ?? throw new \LogicException("Utilisateur $id introuvable"));
    }
}
