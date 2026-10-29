<?php
declare(strict_types=1);

namespace Axi\Web\Controllers;

use Axi\Core\Clock;
use Axi\Core\Config;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Request;
use Axi\Core\Url;
use Axi\Services\AdminLog;
use Axi\Services\Notifier;
use Axi\Services\StallInput;
use Axi\Web\Html;
use Axi\Web\View;
use Axi\Web\WebAuth;

/**
 * Agent Axi : demandes « Devenir commerçant » envoyées depuis l'application (pièce d'identité, selfie, photo de
 * l'étal à son emplacement, téléphone). Accepter crée l'étal (vérifié, avec la photo envoyée), passe le compte en
 * commerçant et le prévient par email et dans l'application. Refuser demande un motif, renvoyé au client.
 */
final class Applications
{
    private const ID_TYPES = ['cni' => 'Carte d\'identité', 'cip' => 'CIP', 'passport' => 'Passeport', 'other' => 'Autre pièce'];
    private const STATUS = ['pending' => ['À vérifier', 'warn'], 'approved' => ['Acceptée', 'ok'], 'rejected' => ['Refusée', 'danger']];

    public function index(Request $req): never
    {
        WebAuth::require(['axi']);
        $status = in_array($_GET['status'] ?? 'pending', ['pending', 'approved', 'rejected'], true) ? ($_GET['status'] ?? 'pending') : 'pending';
        $counts = [];
        foreach (Database::all('SELECT status, COUNT(*) AS n FROM merchant_applications GROUP BY status') as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        $rows = Database::all(
            'SELECT a.*, u.name, u.email, si.name AS site, al.name AS aisle FROM merchant_applications a JOIN users u ON u.id = a.user_id
             LEFT JOIN sites si ON si.id = a.site_id LEFT JOIN aisles al ON al.id = a.aisle_id
             WHERE a.status = ? ORDER BY a.id ' . ($status === 'pending' ? 'ASC' : 'DESC') . ' LIMIT 200',
            [$status],
        );
        $tabs = '<nav class="tabs">';
        foreach (self::STATUS as $key => [$label]) {
            $tabs .= '<a href="' . View::url('/pro/demandes', ['status' => $key]) . '" class="' . ($key === $status ? 'on' : '') . '">' . View::e($label) . ' (' . ($counts[$key] ?? 0) . ')</a>';
        }
        $tabs .= '</nav>';
        $body = $tabs . Html::table(['Reçue le', 'Personne', 'Étal demandé', 'Emplacement', 'Téléphone', ''], array_map(static fn (array $a): array => [
            View::date($a['created_at']),
            '<strong>' . View::e($a['name']) . '</strong><br><span class="muted small">' . View::e($a['email']) . '</span>',
            View::e($a['stall_name']) . '<br><span class="muted small">' . ($a['merchant_type'] === 'wholesale' ? 'Grossiste' : 'Détaillant') . '</span>',
            View::e(($a['site'] ?? ($a['site_other'] . ' (proposé)')) . ' · ' . ($a['aisle'] ?? ($a['aisle_other'] . ' (proposée)'))),
            View::e($a['phone']),
            '<a class="btn sm' . ($status === 'pending' ? '' : ' ghost') . '" href="' . View::url('/pro/demandes/' . $a['id']) . '">' . ($status === 'pending' ? 'Examiner' : 'Voir') . '</a>',
        ], $rows), $status === 'pending' ? 'Aucune demande en attente. Les nouvelles demandes apparaissent ici automatiquement.' : 'Aucune demande.');
        Html::page('Demandes commerçants', $body, 'Les clients qui veulent vendre sur Axi envoient leur pièce d\'identité et une photo de leur étal depuis l\'application. Vérifiez puis acceptez ou refusez.');
    }

    public function show(Request $req): never
    {
        WebAuth::require(['axi']);
        $a = $this->find((int) ($req->params['id'] ?? 0));
        $base = '/pro/demandes/' . $a['id'];
        [$label, $tone] = self::STATUS[$a['status']];

        $photo = static fn (string $caption, ?string $path, bool $private): string => $path === null ? '' :
            '<figure><a href="' . ($private ? View::privateFile($path) : View::e(Url::asset($path . '/medium.webp'))) . '" target="_blank" rel="noopener"><img alt="' . View::e($caption) . '" src="' . ($private ? View::privateFile($path) : View::e(Url::asset($path . '/medium.webp'))) . '"></a><figcaption>' . View::e($caption) . '</figcaption></figure>';

        $info = '<div class="card"><h2>Personne</h2><dl>'
            . '<dt>Nom</dt><dd>' . View::e($a['name']) . '</dd>'
            . '<dt>Email</dt><dd>' . View::e($a['email']) . '</dd>'
            . '<dt>Téléphone</dt><dd>' . View::e($a['phone']) . '</dd>'
            . '<dt>Pièce</dt><dd>' . View::e(self::ID_TYPES[$a['id_type']] . ' n° ' . $a['id_number']) . '</dd>'
            . '<dt>Compte créé le</dt><dd>' . View::date($a['user_created_at'], false) . '</dd>'
            . '<dt>Demande</dt><dd>' . View::badge($label, $tone) . ' · ' . View::date($a['created_at']) . '</dd>'
            . ($a['status'] === 'rejected' ? '<dt>Motif du refus</dt><dd>' . View::e($a['reject_reason']) . '</dd>' : '')
            . '</dl></div>'
            . '<div class="card"><h2>Étal demandé</h2><dl>'
            . '<dt>Nom</dt><dd>' . View::e($a['stall_name']) . '</dd>'
            . '<dt>Type</dt><dd>' . ($a['merchant_type'] === 'wholesale' ? 'Grossiste' : 'Détaillant') . '</dd>'
            . '<dt>Marché</dt><dd>' . View::e($a['site'] ?? '') . ($a['site_other'] !== null ? View::badge('Autre : ' . $a['site_other'], 'warn') : '') . '</dd>'
            . '<dt>Allée</dt><dd>' . View::e($a['aisle'] ?? '') . ($a['aisle_other'] !== null ? View::badge('Autre : ' . $a['aisle_other'], 'warn') : '') . '</dd>'
            . '<dt>Rangée, stand</dt><dd>' . View::e(trim(($a['row_label'] ?? '—') . ', ' . ($a['stand'] ?? '—'))) . '</dd>'
            . '</dl></div>';

        $photos = '<div class="card"><h2>Pièces et photos</h2><p class="note">Vérifiez que le visage du selfie correspond à la pièce d\'identité, et que la photo montre bien un étal sur un marché. Ces documents ne sont visibles que des agents Axi.</p><div class="photos">'
            . $photo('Pièce d\'identité (recto)', $a['id_front_path'], true)
            . $photo('Pièce d\'identité (verso)', $a['id_back_path'], true)
            . $photo('Selfie', $a['selfie_path'], true)
            . $photo('Étal à son emplacement', $a['stall_photo_path'], false)
            . '</div></div>';

        $decision = '';
        if ($a['status'] === 'pending') {
            $sites = ['' => '—'] + Merchants::options('sites', false);
            $aisles = ['' => '—'] + Merchants::options('aisles', false);
            $fields = [
                'stall_name' => ['Nom de l\'étal', 'text', $a['stall_name'], ['attrs' => ' required maxlength="100"']],
                'site_id' => ['Marché', 'select', $a['site_id'] ?? '', $sites],
                'aisle_id' => ['Allée', 'select', $a['aisle_id'] ?? '', $aisles],
            ];
            if ($a['site_other'] !== null) {
                $fields['site_id'][3] = ['new' => 'Créer « ' . $a['site_other'] . ' » pour tout le monde', StallInput::OTHER_SITE => 'Laisser dans « Autre marché »'] + $sites;
                $fields['site_id'][2] = 'new';
            }
            if ($a['aisle_other'] !== null) {
                $fields['aisle_id'][3] = ['new' => 'Créer « ' . $a['aisle_other'] . ' » pour tout le monde', StallInput::OTHER_AISLE => 'Laisser dans « Autres »'] + $aisles;
                $fields['aisle_id'][2] = 'new';
            }
            $decision = '<div class="row2"><div class="card"><h2>Accepter</h2><p class="muted">Le compte devient commerçant, l\'étal est créé avec le badge « vérifié » et la photo envoyée. Le commerçant reçoit un email et un message de félicitations dans l\'application.</p>'
                . Html::form("$base/accepter", $fields, 'Accepter et créer l\'étal', 'Cette personne pourra vendre sur Axi dès maintenant.') . '</div>'
                . '<div class="card"><h2>Refuser</h2><p class="muted">Le motif est envoyé à la personne, qui pourra corriger et renvoyer sa demande.</p>'
                . '<form method="post" action="' . View::url("$base/refuser") . '" class="form"' . View::confirmAttrs('La demande sera refusée et la personne prévenue avec ce motif.', 'Refuser la demande', true) . '>' . View::csrf()
                . '<div class="full"><label for="reason">Motif du refus</label><select id="reason_pick" onchange="document.getElementById(\'reason\').value=this.value"><option value="">Choisir un motif fréquent…</option>'
                . implode('', array_map(static fn (string $r): string => '<option>' . View::e($r) . '</option>', [
                    'La pièce d\'identité est illisible. Reprenez la photo en pleine lumière.',
                    'Le selfie ne correspond pas à la pièce d\'identité.',
                    'La photo ne montre pas votre étal. Prenez-le en photo à son emplacement.',
                    'La pièce d\'identité est expirée.',
                ])) . '</select></div>'
                . '<div class="full"><textarea id="reason" name="reason" rows="3" required maxlength="300" placeholder="Expliquez ce qu\'il faut corriger"></textarea></div>'
                . '<div class="full"><button class="btn danger" type="submit">Refuser la demande</button></div></form></div></div>';
        }
        Html::page('Demande de ' . $a['name'], '<p><a href="' . View::url('/pro/demandes') . '">← Toutes les demandes</a></p><div class="row2">' . $info . '</div>' . $photos . $decision, 'Demande pour devenir commerçant sur Axi.');
    }

    public function approve(Request $req): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $a = $this->find((int) ($req->params['id'] ?? 0));
        $base = '/pro/demandes/' . $a['id'];
        $name = trim((string) ($_POST['stall_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            View::redirect($base, 'Le nom de l\'étal est obligatoire (100 caractères maximum).', 'err');
        }
        if ($a['status'] !== 'pending') {
            View::redirect($base, 'Cette demande a déjà été traitée.', 'err');
        }

        $done = Database::transaction(function () use ($a, $agent, $name, $base): bool {
            // Verrou : deux agents qui valident en même temps ne créent jamais deux étals ni deux allées
            $row = Database::one('SELECT status FROM merchant_applications WHERE id = ? FOR UPDATE', [$a['id']]);
            if ($row['status'] !== 'pending') {
                return false;
            }
            [$siteId, $siteOther] = $this->place((string) ($_POST['site_id'] ?? ''), $a['site_other'], 'sites', StallInput::OTHER_SITE, $base);
            [$aisleId, $aisleOther] = $this->place((string) ($_POST['aisle_id'] ?? ''), $a['aisle_other'], 'aisles', StallInput::OTHER_AISLE, $base);
            $now = Clock::now();
            $size = @getimagesize(BASE_PATH . '/public/' . $a['stall_photo_path'] . '/medium.webp') ?: [null, null];
            $columns = [
                'name' => $name, 'site_id' => $siteId, 'site_other' => $siteOther, 'aisle_id' => $aisleId, 'aisle_other' => $aisleOther,
                'row_label' => $a['row_label'], 'stand' => $a['stand'], 'merchant_type' => $a['merchant_type'],
                'photo_path' => $a['stall_photo_path'], 'photo_width' => $size[0], 'photo_height' => $size[1],
                'verification_photo_path' => $a['stall_photo_path'], 'verified_at' => $now, 'verified_by' => $agent['id'],
                'phone' => $a['phone'], 'whatsapp' => $a['phone'], 'status' => 'active', 'updated_at' => $now,
            ];
            $existing = Database::value('SELECT id FROM stalls WHERE user_id = ?', [$a['user_id']]);
            if ($existing !== null) {
                Database::updateById('stalls', (int) $existing, $columns);
            } else {
                $columns += ['user_id' => $a['user_id'], 'created_at' => $now];
                Database::insert('INSERT INTO stalls (' . implode(', ', array_keys($columns)) . ') VALUES (' . Database::in($columns) . ')', array_values($columns));
            }
            Database::run('UPDATE users SET role = \'merchant\', merchant_welcome_pending = 1, phone = COALESCE(phone, ?) WHERE id = ?', [$a['phone'], $a['user_id']]);
            Database::run('UPDATE merchant_applications SET status = \'approved\', reviewed_by = ?, reviewed_at = ? WHERE id = ?', [$agent['id'], $now, $a['id']]);
            return true;
        });
        if (!$done) {
            View::redirect($base, 'Cette demande a déjà été traitée par un autre agent.', 'err');
        }
        $req->user = $agent;
        AdminLog::record($req, 'application.approve', 'user', (int) $a['user_id'], ['application' => (int) $a['id'], 'stall' => $name]);
        Notifier::send((int) $a['user_id'], 'account', 'Félicitations, vous êtes commerçant Axi !',
            'Votre demande est acceptée. Votre étal « ' . $name . ' » est créé et vérifié : ouvrez l\'application pour ajouter vos produits.',
            [], [
                ['Bonne nouvelle : votre demande pour vendre sur Axi est acceptée.'],
                ['rows' => [['Votre étal', $name], ['Badge', 'Vérifié par un agent Axi']]],
                ['Ouvrez l\'application Axi, puis passez en mode commerçant pour ajouter vos produits en photo. Sans abonnement, vous pouvez mettre ' . \Axi\Support\Subscription::productLimit() . ' produits en vente ; l\'abonnement débloque tout.'],
            ], true);
        View::redirect('/pro/demandes', 'Demande acceptée : ' . $a['name'] . ' est maintenant commerçant et a été prévenu par email.');
    }

    public function reject(Request $req): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $a = $this->find((int) ($req->params['id'] ?? 0));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            View::redirect('/pro/demandes/' . $a['id'], 'Indiquez le motif du refus : il est envoyé à la personne.', 'err');
        }
        $n = Database::run(
            'UPDATE merchant_applications SET status = \'rejected\', reject_reason = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ? AND status = \'pending\'',
            [mb_substr($reason, 0, 300), $agent['id'], Clock::now(), $a['id']],
        )->rowCount();
        if ($n === 0) {
            View::redirect('/pro/demandes/' . $a['id'], 'Cette demande a déjà été traitée par un autre agent.', 'err');
        }
        $req->user = $agent;
        AdminLog::record($req, 'application.reject', 'user', (int) $a['user_id'], ['application' => (int) $a['id'], 'reason' => $reason]);
        Notifier::send((int) $a['user_id'], 'account', 'Votre demande commerçant est à corriger', 'Motif : ' . $reason . ' Vous pouvez envoyer une nouvelle demande depuis Paramètres.', [], [
            ['Votre demande pour vendre sur Axi n\'a pas pu être acceptée pour le moment.'],
            ['rows' => [['Motif', $reason]]],
            ['Corrigez ce point puis renvoyez votre demande depuis l\'application : Paramètres > Devenir commerçant.'],
        ], true);
        View::redirect('/pro/demandes', 'Demande refusée. La personne a été prévenue avec le motif.');
    }

    /** Pièce d'identité ou selfie : servi uniquement à un agent Axi connecté, par un lien lié à sa session */
    public function file(Request $req): never
    {
        WebAuth::require(['axi']);
        $path = (string) ($_GET['p'] ?? '');
        $expected = hash_hmac('sha256', $path, (string) Config::get('app_key') . WebAuth::csrf());
        $file = realpath(BASE_PATH . '/storage/' . $path);
        $root = realpath(BASE_PATH . '/storage/private');
        if (!hash_equals($expected, (string) ($_GET['s'] ?? '')) || $file === false || $root === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
            throw new HttpException(404, 'NOT_FOUND', 'Fichier introuvable.');
        }
        header('Content-Type: image/webp');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    /**
     * Choix de l'agent pour le marché ou l'allée : un existant, « Autre » (en attente), ou « new » = créer l'entrée
     * proposée par le commerçant pour tout le monde. @return array{0: string, 1: ?string} id, nom libre restant
     */
    private function place(string $choice, ?string $proposed, string $table, string $other, string $back): array
    {
        if ($choice === 'new' && $proposed !== null) {
            $same = Database::value("SELECT id FROM $table WHERE LOWER(name) = LOWER(?)", [$proposed]);
            if ($same !== null) {
                return [(string) $same, null];
            }
            $id = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT', $proposed))), '-');
            $id = substr($id !== '' ? $id : 'lieu', 0, 24);
            while (Database::value("SELECT id FROM $table WHERE id = ?", [$id]) !== null) {
                $id = substr($id, 0, 20) . '-' . random_int(10, 99);
            }
            if ($table === 'aisles') {
                Database::run('INSERT INTO aisles (id, name, icon, sort_order) VALUES (?, ?, \'other\', 50)', [$id, $proposed]);
            } else {
                Database::run('INSERT INTO sites (id, name, sort_order) VALUES (?, ?, 50)', [$id, $proposed]);
            }
            return [$id, null];
        }
        if ($choice === $other && $proposed !== null) {
            return [$other, $proposed];
        }
        if ($choice === '' || Database::value("SELECT id FROM $table WHERE id = ? AND active = 1", [$choice]) === null) {
            View::redirect($back, $table === 'sites' ? 'Choisissez le marché de l\'étal.' : 'Choisissez l\'allée de l\'étal.', 'err');
        }
        return [$choice, null];
    }

    private function find(int $id): array
    {
        return Database::one(
            'SELECT a.*, u.name, u.email, u.created_at AS user_created_at, si.name AS site, al.name AS aisle FROM merchant_applications a JOIN users u ON u.id = a.user_id
             LEFT JOIN sites si ON si.id = a.site_id LEFT JOIN aisles al ON al.id = a.aisle_id WHERE a.id = ?',
            [$id],
        ) ?? throw new HttpException(404, 'NOT_FOUND', 'Demande introuvable.');
    }
}
