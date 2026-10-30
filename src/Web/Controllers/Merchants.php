<?php
declare(strict_types=1);

namespace Axi\Web\Controllers;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Request;
use Axi\Core\Url;
use Axi\Services\AdminLog;
use Axi\Services\Notifier;
use Axi\Services\Payment\Escrow;
use Axi\Services\Settings;
use Axi\Services\StallInput;
use Axi\Services\Uploads;
use Axi\Support\Stalls;
use Axi\Web\Html;
use Axi\Web\View;
use Axi\Web\WebAuth;

/**
 * Agent Axi : recensement et vérification des étals sur place (photo à l'emplacement réel, badge « vérifié »),
 * gestion des commerçants (fiche, activation / désactivation, emplacement, abonnement, versement).
 */
final class Merchants
{
    private const PER_PAGE = 30;

    public function index(Request $req): never
    {
        WebAuth::require(['axi']);
        $where = ['1'];
        $params = [];
        foreach (['site_id' => 's.site_id', 'aisle_id' => 's.aisle_id'] as $key => $col) {
            if (($v = trim((string) ($_GET[$key] ?? ''))) !== '') {
                $where[] = "$col = ?";
                $params[] = $v;
            }
        }
        $state = (string) ($_GET['state'] ?? '');
        $where[] = match ($state) {
            'verified' => 's.verified_at IS NOT NULL',
            'unverified' => 's.verified_at IS NULL',
            'suspended' => "(s.status = 'suspended' OR u.status = 'suspended')",
            default => '1',
        };
        if (($q = trim((string) ($_GET['q'] ?? ''))) !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(s.name LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR s.stand LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $page = View::page();
        $rows = Database::all(
            'SELECT s.*, u.name AS owner, u.email, u.status AS user_status, si.name AS site, a.name AS aisle,
                    (SELECT MAX(ends_on) FROM subscriptions x WHERE x.stall_id = s.id AND x.status = \'active\') AS sub_end
             FROM stalls s JOIN users u ON u.id = s.user_id JOIN sites si ON si.id = s.site_id JOIN aisles a ON a.id = s.aisle_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY s.verified_at IS NOT NULL, s.id DESC LIMIT ' . (self::PER_PAGE + 1) . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params,
        );
        $more = count($rows) > self::PER_PAGE;
        $body = Html::filters('/pro/commercants', [
            'q' => ['Recherche (nom, email, téléphone, stand)', 'search', null],
            'site_id' => ['Site', 'select', ['' => 'Tous'] + self::options('sites')],
            'aisle_id' => ['Allée', 'select', ['' => 'Toutes'] + self::options('aisles')],
            'state' => ['État', 'select', ['' => 'Tous', 'unverified' => 'À vérifier', 'verified' => 'Vérifiés', 'suspended' => 'Suspendus']],
        ]) . Html::table(['Étal', 'Commerçant', 'Emplacement', 'Type', 'Badge', 'Abonnement', 'Compte'], array_map(static fn (array $s): array => [
            '<a href="' . View::url('/pro/commercants/' . $s['id']) . '"><strong>' . View::e($s['name']) . '</strong></a>',
            View::e($s['owner']) . '<br><span class="muted">' . View::e($s['email']) . '</span>',
            View::e($s['site'] . ' · ' . $s['aisle']) . '<br><span class="muted">' . View::e(trim(($s['row_label'] ?? '') . ' ' . ($s['stand'] !== null ? 'stand ' . $s['stand'] : '')) ?: ($s['has_physical_place'] ? '—' : 'Sans place')) . '</span>',
            View::e($s['merchant_type'] === 'wholesale' ? 'Grossiste' : 'Détaillant'),
            $s['verified_at'] !== null ? View::badge('Vérifié', 'ok') : View::badge('À vérifier', 'warn'),
            $s['sub_end'] !== null && $s['sub_end'] >= Clock::localDate() ? View::badge('Jusqu\'au ' . date('d/m/Y', strtotime($s['sub_end'])), 'ok') : View::badge('Inactif'),
            $s['user_status'] === 'active' && $s['status'] === 'active' ? View::badge('Actif', 'ok') : View::badge('Suspendu', 'danger'),
        ], array_slice($rows, 0, self::PER_PAGE)), 'Aucun commerçant ne correspond.')
            . View::pager('/pro/commercants', array_intersect_key($_GET, array_flip(['q', 'site_id', 'aisle_id', 'state'])), $page, $more);
        Html::page('Commerçants', $body, 'Recensement, vérification sur place et gestion des étals.');
    }

    public function show(Request $req): never
    {
        WebAuth::require(['axi']);
        $s = $this->stall((int) ($req->params['id'] ?? 0));
        $products = Stalls::productList(Stalls::products((int) $s['id'], 0, null, true));
        $sums = Database::one(
            "SELECT COALESCE(SUM(CASE WHEN escrow_state IN ('held','disputed') THEN net_amount END),0) AS held,
                    COALESCE(SUM(CASE WHEN escrow_state = 'released' AND payout_id IS NULL THEN net_amount END),0) AS released,
                    COALESCE(SUM(CASE WHEN escrow_state = 'released' AND payout_id IS NOT NULL THEN net_amount END),0) AS paid_out,
                    COUNT(*) AS orders
             FROM order_stalls WHERE stall_id = ? AND status <> 'cancelled'",
            [$s['id']],
        );
        $subs = Database::all('SELECT * FROM subscriptions WHERE stall_id = ? ORDER BY id DESC LIMIT 10', [$s['id']]);
        $orders = Database::all('SELECT os.*, o.created_at, u.name AS client FROM order_stalls os JOIN orders o ON o.id = os.order_id JOIN users u ON u.id = o.client_id WHERE os.stall_id = ? ORDER BY os.id DESC LIMIT 15', [$s['id']]);
        $id = (int) $s['id'];
        $base = '/pro/commercants/' . $id;

        $info = '<div class="card"><h2>Étal</h2><dl>'
            . '<dt>Commerçant</dt><dd>' . View::e($s['owner']) . ' · ' . View::e($s['email']) . '</dd>'
            . '<dt>Téléphone</dt><dd>' . View::e($s['phone'] ?? '—') . ' · WhatsApp ' . View::e($s['whatsapp'] ?? '—') . '</dd>'
            . '<dt>Emplacement</dt><dd>' . View::e(Stalls::location($s)['label']) . '</dd>'
            . '<dt>Allée</dt><dd>' . View::e($s['aisle']) . '</dd>'
            . '<dt>Profil</dt><dd>' . View::e($s['merchant_type'] === 'wholesale' ? 'Grossiste' : 'Détaillant') . '</dd>'
            . '<dt>Badge</dt><dd>' . ($s['verified_at'] !== null ? View::badge('Vérifié le ' . View::date($s['verified_at'], false), 'ok') : View::badge('Non vérifié', 'warn')) . '</dd>'
            . '<dt>Compte</dt><dd>' . ($s['user_status'] === 'active' && $s['status'] === 'active' ? View::badge('Actif', 'ok') : View::badge('Suspendu', 'danger')) . '</dd>'
            . '<dt>Inscrit le</dt><dd>' . View::date($s['created_at'], false) . '</dd></dl>'
            . ($s['verification_photo_path'] !== null ? '<p><img class="photo" alt="Photo de vérification" src="' . View::e(Url::asset($s['verification_photo_path'] . '/medium.webp')) . '"></p>' : '')
            . '<div class="actions">'
            . ($s['user_status'] === 'active' && $s['status'] === 'active'
                ? View::action("$base/suspendre", 'Suspendre le commerçant', [], 'Êtes-vous sûr de vouloir suspendre ce commerçant ? Son étal ne sera plus visible.', 'btn danger')
                : View::action("$base/activer", 'Réactiver le commerçant', [], 'Réactiver ce commerçant et son étal ?', 'btn ok'))
            . ($s['verified_at'] !== null ? View::action("$base/retirer-badge", 'Retirer le badge', [], 'Retirer le badge « vérifié » de cet étal ?', 'btn ghost') : '')
            . '</div></div>';

        $application = Database::value('SELECT id FROM merchant_applications WHERE user_id = ? AND status = \'approved\' ORDER BY id DESC LIMIT 1', [$s['user_id']]);
        $verify = '<div class="card"><h2>Vérification sur place</h2>'
            . '<p class="note">Le badge « vérifié par un agent Axi » dit aux clients qu\'un agent a vu cet étal à cet emplacement. '
            . 'La photo est <strong>facultative</strong> : elle sert de preuve en cas de litige ou de fraude, n\'est jamais montrée aux clients, '
            . 'et remplace la photo de la demande si l\'étal a changé de place.</p>'
            . Html::form("$base/verifier", [
                'photo' => ['Nouvelle photo à l\'emplacement réel (facultative)', 'file', '', null],
                'row' => ['Rangée constatée', 'text', $s['row_label'] ?? '', null],
                'stand' => ['Stand constaté', 'text', $s['stand'] ?? '', null],
            ], $s['verified_at'] !== null ? 'Confirmer à nouveau la vérification' : 'Valider la vérification', 'Vous confirmez avoir vu cet étal à cet emplacement.', true)
            . ($application !== null ? '<p style="margin:14px 0 0"><a href="' . View::url('/pro/demandes/' . $application) . '">Voir la demande et les pièces d\'identité →</a></p>' : '')
            . '</div>';

        $money = Html::kpis([
            'Bloqué (en attente de réception)' => View::fcfa($sums['held']),
            'Libéré, à verser' => View::fcfa($sums['released']),
            'Déjà versé' => View::fcfa($sums['paid_out']),
            'Commandes' => (string) $sums['orders'],
        ]) . ((int) $sums['released'] > 0 ? '<div class="actions" style="margin-bottom:16px">' . View::action("$base/verser", 'Verser ' . View::fcfa($sums['released']) . ' au commerçant', [], 'Confirmer le versement de ' . View::fcfa($sums['released']) . ' au commerçant ?', 'btn ok') . '</div>' : '');

        $edit = '<div class="card"><h2>Modifier l\'étal</h2>' . Html::form("$base/modifier", [
            'name' => ['Nom de l\'étal', 'text', $s['name'], null],
            'tagline' => ['Description', 'text', $s['tagline'], null],
            'site_id' => ['Marché', 'select', $s['site_id'], self::options('sites')],
            'site_other' => ['Nom du marché (si « Autre marché »)', 'text', $s['site_other'] ?? '', null],
            'aisle_id' => ['Allée', 'select', $s['aisle_id'], self::options('aisles')],
            'aisle_other' => ['Nom de l\'allée (si « Autres »)', 'text', $s['aisle_other'] ?? '', null],
            'row' => ['Rangée', 'text', $s['row_label'] ?? '', null],
            'stand' => ['Stand', 'text', $s['stand'] ?? '', null],
            'merchant_type' => ['Profil', 'select', $s['merchant_type'], ['retail' => 'Détaillant', 'wholesale' => 'Grossiste']],
            'has_physical_place' => ['A une place physique', 'checkbox', (bool) $s['has_physical_place'], null],
            'phone' => ['Téléphone', 'text', $s['phone'] ?? '', null],
            'whatsapp' => ['WhatsApp', 'text', $s['whatsapp'] ?? '', null],
        ], 'Mettre à jour', 'Les informations de cet étal seront mises à jour.') . '</div>';

        $amount = Settings::subscriptionAmount($s['merchant_type']);
        $sub = '<div class="card"><h2>Abonnement</h2>'
            . Html::table(['Période', 'Formule', '#Montant', 'Payé par', 'Statut'], array_map(static fn (array $x): array => [
                View::e(date('d/m/Y', strtotime($x['starts_on'])) . ' → ' . date('d/m/Y', strtotime($x['ends_on']))),
                View::e(($x['plan'] === 'wholesale' ? 'Gros' : 'Étal') . ($x['period'] === 'year' ? ', 1 an' : ', 1 mois')), View::fcfa($x['amount']),
                View::e(['merchant' => 'Commerçant', 'anagem' => 'ANaGeM', 'axi' => 'Offert par Axi'][$x['paid_by']]),
                View::badge(['pending' => 'En attente', 'active' => 'Actif', 'cancelled' => 'Annulé'][$x['status']], $x['status'] === 'active' ? 'ok' : 'muted'),
            ], $subs), 'Aucun abonnement.')
            . '<p class="muted" style="margin-top:12px">Abonnement pris en charge par l\'ANaGeM ou offert par Axi (sans paiement du commerçant) :</p>'
            . Html::form("$base/abonnement", [
                'paid_by' => ['Payé par', 'select', 'anagem', ['anagem' => 'ANaGeM (inclus dans la place)', 'axi' => 'Offert par Axi']],
                'months' => ['Durée (mois)', 'number', '1', ['attrs' => ' min="1" max="12"']],
            ], 'Activer l\'abonnement (' . View::fcfa($amount) . ' / mois)', 'Activer cet abonnement sans paiement du commerçant ?') . '</div>';

        $productsHtml = Html::title('Catalogue') . Html::table(['Photo', 'Produit', '#Prix', 'Statut'], array_map(static fn (array $p): array => [
            $p['image'] !== null ? '<img src="' . View::e($p['image']['thumb_url']) . '" alt="" style="width:56px;height:56px;object-fit:cover;border-radius:8px">' : '<span class="muted">—</span>',
            View::e($p['name']) . ($p['is_today'] ? ' ' . View::badge('Aujourd\'hui', 'ok') : ''),
            $p['price'] === null ? 'À débattre' : View::fcfa($p['price']['amount']) . ($p['price']['amount_max'] !== null ? ' – ' . View::fcfa($p['price']['amount_max']) : ''),
            match (true) {
                $p['status'] === 'draft' => View::badge('Brouillon vocal', 'warn'),
                $p['status'] === 'paused' => View::badge('Masqué (limite sans abonnement)'),
                $p['sold_out'] => View::badge('Épuisé', 'danger'),
                !$p['in_stock'] => View::badge('Indisponible'),
                default => View::badge($p['stock_quantity'] === null ? 'En vente' : 'En vente · stock ' . $p['stock_quantity'], 'ok'),
            },
        ], $products), 'Aucun produit.');

        $ordersHtml = Html::title('Dernières commandes') . Html::table(['Commande', 'Date', 'Client', '#Montant', 'Séquestre'], array_map(static fn (array $o): array => [
            '<a href="' . View::url('/pro/commandes/' . $o['order_id']) . '">cmd_' . (int) $o['order_id'] . '</a>', View::date($o['created_at']), View::e($o['client']), View::fcfa($o['items_total']), Orders::escrowBadge($o['escrow_state']),
        ], $orders), 'Aucune commande.');

        Html::page($s['name'], $money . '<div class="row2">' . $info . $verify . '</div><div class="row2">' . $edit . $sub . '</div>' . $productsHtml . $ordersHtml, 'Fiche commerçant');
    }

    public function verify(Request $req): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $s = $this->stall((int) ($req->params['id'] ?? 0));
        // Photo facultative : preuve interne (litige, fraude) ; sans nouvelle photo, la précédente est gardée
        $file = $req->file('photo');
        $columns = ['verified_at' => Clock::now(), 'verified_by' => $agent['id'], 'updated_at' => Clock::now()];
        if ($file !== null) {
            try {
                $columns['verification_photo_path'] = Uploads::photo($file)['path'];
            } catch (HttpException $e) {
                View::redirect('/pro/commercants/' . $s['id'], $e->getMessage(), 'err');
            }
        }
        foreach (['row' => 'row_label', 'stand' => 'stand'] as $field => $col) {
            $value = trim((string) ($_POST[$field] ?? ''));
            $columns[$col] = $value === '' ? null : mb_substr($value, 0, $field === 'row' ? 30 : 20);
        }
        Database::updateById('stalls', (int) $s['id'], $columns);
        Database::run("UPDATE agent_visit_requests SET status = 'done', assigned_agent_id = ? WHERE user_id = ? AND status IN ('new','scheduled')", [$agent['id'], $s['user_id']]);
        $this->log($req, $agent, 'stall.verify', (int) $s['id'], ['row' => $columns['row_label'], 'stand' => $columns['stand']]);
        Notifier::send((int) $s['user_id'], 'account', 'Votre étal est vérifié', 'Un agent Axi a vérifié votre étal sur place : le badge « vérifié » est maintenant visible par vos clients.');
        View::redirect('/pro/commercants/' . $s['id'], 'Étal vérifié : le badge est visible dans l\'application.');
    }

    public function unverify(Request $req): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $s = $this->stall((int) ($req->params['id'] ?? 0));
        Database::updateById('stalls', (int) $s['id'], ['verified_at' => null, 'verified_by' => null]);
        $this->log($req, $agent, 'stall.unverify', (int) $s['id']);
        View::redirect('/pro/commercants/' . $s['id'], 'Badge retiré.');
    }

    public function suspend(Request $req): never
    {
        $this->setStatus($req, 'suspended');
    }

    public function activate(Request $req): never
    {
        $this->setStatus($req, 'active');
    }

    public function update(Request $req): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $s = $this->stall((int) ($req->params['id'] ?? 0));
        $data = $_POST;
        $data['has_physical_place'] = isset($_POST['has_physical_place']) ? '1' : '0';
        unset($data['_csrf']);
        try {
            $columns = StallInput::validate($data, false);
        } catch (HttpException $e) {
            View::redirect('/pro/commercants/' . $s['id'], $e->getMessage(), 'err');
        }
        $moved = false;
        foreach (StallInput::LOCATION_FIELDS as $field) {
            $moved = $moved || (array_key_exists($field, $columns) && (string) $columns[$field] !== (string) $s[$field]);
        }
        if ($moved) {
            // Déplacement constaté par l'agent : vérifié par lui, encart « nouvelle adresse » chez le commerçant
            $columns += ['moved_at' => Clock::now(), 'relocation_sent_at' => null, 'verified_at' => Clock::now(), 'verified_by' => $agent['id']];
        }
        Database::updateById('stalls', (int) $s['id'], $columns + ['updated_at' => Clock::now()]);
        $this->log($req, $agent, 'stall.update', (int) $s['id'], $columns);
        View::redirect('/pro/commercants/' . $s['id'], 'Étal mis à jour.');
    }

    public function subscription(Request $req): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $s = $this->stall((int) ($req->params['id'] ?? 0));
        $paidBy = in_array($_POST['paid_by'] ?? '', ['anagem', 'axi'], true) ? $_POST['paid_by'] : 'anagem';
        $months = max(1, min(12, (int) ($_POST['months'] ?? 1)));
        $last = Database::value("SELECT MAX(ends_on) FROM subscriptions WHERE stall_id = ? AND status = 'active'", [$s['id']]);
        $start = $last !== null && $last >= Clock::localDate() ? date('Y-m-d', strtotime($last . ' +1 day')) : Clock::localDate();
        Database::run(
            'INSERT INTO subscriptions (stall_id, plan, period, amount, paid_by, status, starts_on, ends_on, created_by, created_at) VALUES (?, ?, ?, ?, ?, \'active\', ?, ?, ?, ?)',
            [$s['id'], $s['merchant_type'], $months >= 12 ? 'year' : 'month', Settings::subscriptionAmount($s['merchant_type']), $paidBy, $start, date('Y-m-d', strtotime($start . ' +' . (30 * $months) . ' days')), $agent['id'], Clock::now()],
        );
        \Axi\Support\Subscription::resume((int) $s['id']);
        $this->log($req, $agent, 'subscription.grant', (int) $s['id'], ['paid_by' => $paidBy, 'months' => $months]);
        Notifier::send((int) $s['user_id'], 'account', 'Abonnement activé', 'Votre abonnement Axi est actif (' . ($paidBy === 'anagem' ? 'inclus dans votre place' : 'offert par Axi') . ').');
        View::redirect('/pro/commercants/' . $s['id'], 'Abonnement activé.');
    }

    public function payout(Request $req): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $s = $this->stall((int) ($req->params['id'] ?? 0));
        try {
            $result = Escrow::payout((int) $s['id'], (int) $agent['id']);
        } catch (HttpException $e) {
            View::redirect('/pro/commercants/' . $s['id'], $e->getMessage(), 'err');
        }
        $this->log($req, $agent, 'payout.create', (int) $s['id'], ['amount' => $result['amount'], 'status' => $result['status']]);
        View::redirect('/pro/commercants/' . $s['id'], View::fcfa($result['amount']) . ' : ' . $result['message'], $result['status'] === 'failed' ? 'err' : 'ok');
    }

    /** Demandes de vérification (création d'étal, déménagement, demande du commerçant) */
    public function visits(Request $req): never
    {
        WebAuth::require(['axi']);
        $rows = Database::all(
            "SELECT v.*, u.name, u.email, u.phone, s.id AS stall_id, s.name AS stall, si.name AS site, s.row_label, s.stand
             FROM agent_visit_requests v JOIN users u ON u.id = v.user_id LEFT JOIN stalls s ON s.user_id = u.id LEFT JOIN sites si ON si.id = s.site_id
             WHERE v.status IN ('new','scheduled') ORDER BY v.id",
        );
        Html::page('Vérifications sur place', Html::table(['Demandée le', 'Commerçant', 'Étal', 'Emplacement déclaré', ''], array_map(static fn (array $v): array => [
            View::date($v['created_at']), View::e($v['name']) . '<br><span class="muted">' . View::e($v['phone'] ?? $v['email']) . '</span>',
            View::e($v['stall'] ?? 'Étal pas encore créé'), View::e(trim(($v['site'] ?? '') . ' ' . ($v['row_label'] ?? '') . ' ' . ($v['stand'] !== null ? 'stand ' . $v['stand'] : ''))),
            $v['stall_id'] !== null ? '<a class="btn" href="' . View::url('/pro/commercants/' . $v['stall_id']) . '">Ouvrir la fiche</a>' : '',
        ], $rows), 'Aucune vérification en attente.'), 'Étals créés ou déplacés par leur commerçant, à vérifier à leur emplacement réel.');
    }

    private function setStatus(Request $req, string $status): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $s = $this->stall((int) ($req->params['id'] ?? 0));
        Database::run('UPDATE users SET status = ? WHERE id = ?', [$status, $s['user_id']]);
        Database::run('UPDATE stalls SET status = ? WHERE id = ?', [$status, $s['id']]);
        if ($status === 'suspended') {
            \Axi\Core\Auth::revokeAll((int) $s['user_id']);
        }
        $this->log($req, $agent, 'merchant.' . ($status === 'active' ? 'activate' : 'suspend'), (int) $s['id']);
        View::redirect('/pro/commercants/' . $s['id'], $status === 'active' ? 'Commerçant réactivé.' : 'Commerçant suspendu : son étal n\'est plus visible.');
    }

    private function stall(int $id): array
    {
        return Database::one(
            'SELECT s.*, u.name AS owner, u.email, u.status AS user_status, si.name AS site_name, si.map_url AS site_map_url, a.name AS aisle
             FROM stalls s JOIN users u ON u.id = s.user_id JOIN sites si ON si.id = s.site_id JOIN aisles a ON a.id = s.aisle_id WHERE s.id = ?',
            [$id],
        ) ?? throw new HttpException(404, 'NOT_FOUND', 'Commerçant introuvable.');
    }

    private function log(Request $req, array $agent, string $action, int $stallId, array $details = []): void
    {
        $req->user = $agent;
        AdminLog::record($req, $action, 'stall', $stallId, $details);
    }

    /** @return array<string, string> $withOther : inclure « Autre marché » / « Autres » */
    public static function options(string $table, bool $withOther = true): array
    {
        $out = [];
        $where = $withOther ? '' : " AND id NOT IN ('" . StallInput::OTHER_SITE . "', '" . StallInput::OTHER_AISLE . "')";
        foreach (Database::all("SELECT id, name FROM $table WHERE active = 1$where ORDER BY sort_order, name") as $r) {
            $out[$r['id']] = $r['name'];
        }
        return $out;
    }
}
