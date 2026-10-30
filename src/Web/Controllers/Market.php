<?php
declare(strict_types=1);

namespace Axi\Web\Controllers;

use Axi\Core\Database;
use Axi\Core\Request;
use Axi\Services\AdminLog;
use Axi\Services\Notifier;
use Axi\Web\Html;
use Axi\Web\View;
use Axi\Web\WebAuth;

/** Agent Axi : sites du marché et allées (sous-marchés), gérés en base et non en dur */
final class Market
{
    private const ICONS = ['fabric' => 'Tissus', 'grain' => 'Vivriers', 'beauty' => 'Cosmétiques', 'hardware' => 'Quincaillerie', 'parts' => 'Pièces détachées', 'jewelry' => 'Bijoux', 'electronics' => 'Électronique', 'other' => 'Autre'];

    public function index(Request $req): never
    {
        WebAuth::require(['axi']);
        $aisles = Database::all('SELECT a.*, (SELECT COUNT(*) FROM stalls s WHERE s.aisle_id = a.id) AS stalls FROM aisles a ORDER BY a.sort_order, a.name');
        $sites = Database::all('SELECT si.*, (SELECT COUNT(*) FROM stalls s WHERE s.site_id = si.id) AS stalls FROM sites si ORDER BY si.sort_order, si.name');
        // Noms saisis par les commerçants dans « Autre » : à créer pour tout le monde ou à ranger dans l'existant
        $proposals = Database::all('SELECT s.id, s.name, s.site_other, s.aisle_other, u.name AS owner FROM stalls s JOIN users u ON u.id = s.user_id WHERE s.site_other IS NOT NULL OR s.aisle_other IS NOT NULL ORDER BY s.id');
        $proposalsHtml = '';
        if ($proposals !== []) {
            $aisleOptions = ['new' => 'Créer cette allée pour tout le monde'] + Merchants::options('aisles', false);
            $siteOptions = ['new' => 'Créer ce marché pour tout le monde'] + Merchants::options('sites', false);
            $select = static function (string $name, array $options): string {
                return '<select name="' . $name . '">' . implode('', array_map(static fn ($k, $v): string => '<option value="' . View::e($k) . '">' . View::e($v) . '</option>', array_keys($options), $options)) . '</select>';
            };
            $proposalsHtml = '<div class="card"><h2>Propositions des commerçants</h2><p class="note">Ces commerçants n\'ont pas trouvé leur allée ou leur marché dans la liste et ont écrit son nom. Créez-le pour tout le monde, ou rangez l\'étal dans une entrée existante.</p>'
                . Html::table(['Étal', 'Proposition', 'Décision', ''], array_map(static function (array $p) use ($aisleOptions, $siteOptions, $select): array {
                    $kind = $p['aisle_other'] !== null ? 'aisle' : 'site';
                    $form = '<form method="post" action="' . View::url('/pro/allees/proposition/' . $p['id']) . '" class="actions">' . View::csrf()
                        . '<input type="hidden" name="kind" value="' . $kind . '">' . $select('target', $kind === 'aisle' ? $aisleOptions : $siteOptions)
                        . '<button class="btn sm" type="submit">Appliquer</button></form>';
                    return [
                        '<a href="' . View::url('/pro/commercants/' . $p['id']) . '">' . View::e($p['name']) . '</a><br><span class="muted small">' . View::e($p['owner']) . '</span>',
                        View::badge(($kind === 'aisle' ? 'Allée : ' . $p['aisle_other'] : 'Marché : ' . $p['site_other']), 'warn'),
                        $form, '',
                    ];
                }, $proposals)) . '</div>';
        }
        // Un onglet à la fois : la liste, puis l'ajout replié en dessous
        $view = ($_GET['vue'] ?? '') === 'marches' ? 'marches' : 'allees';
        $tabs = '<nav class="tabs" aria-label="Allées et marchés">';
        foreach (['allees' => 'Allées (' . count($aisles) . ')', 'marches' => 'Marchés (' . count($sites) . ')'] as $key => $label) {
            $tabs .= '<a href="' . View::url('/pro/allees', ['vue' => $key]) . '" class="' . ($view === $key ? 'on' : '') . '">' . View::e($label) . '</a>';
        }
        $tabs .= '</nav>';
        $line = static fn (array $r): string => '<strong>' . View::e($r['name']) . '</strong><br><span class="muted small">' . View::e($r['id']) . '</span>';
        $list = $view === 'allees'
            ? Html::table(['Allée', 'Icône', '#Étals', 'Ordre', 'État', ''], array_map(static fn (array $a): array => [
                $line($a), View::e(self::ICONS[$a['icon']]), (string) $a['stalls'], (string) $a['sort_order'],
                $a['active'] ? View::badge('Visible', 'ok') : View::badge('Masquée'),
                View::action('/pro/allees/allee', $a['active'] ? 'Masquer' : 'Afficher', ['id' => $a['id'], 'toggle' => '1'], $a['active'] ? 'Masquer cette allée dans l\'application ?' : null, 'btn ghost sm'),
            ], $aisles))
              . '<details class="card new-campaign"><summary>Ajouter ou modifier une allée</summary>' . Html::form('/pro/allees/allee', [
                'name' => ['Nom affiché', 'text', '', ['attrs' => ' required']],
                'id' => ['Identifiant (minuscules, ex. chaussures)', 'text', '', ['attrs' => ' pattern="[a-z0-9-]{2,30}" required']],
                'icon' => ['Icône', 'select', 'other', self::ICONS],
                'sort_order' => ['Ordre d\'affichage', 'number', '10', null],
            ], 'Enregistrer l\'allée') . '</details>'
            : Html::table(['Marché', 'Ville', '#Étals', 'Ordre', 'État', ''], array_map(static fn (array $s): array => [
                $line($s), View::e($s['city']), (string) $s['stalls'], (string) $s['sort_order'],
                $s['active'] ? View::badge('Actif', 'ok') : View::badge('Inactif'),
                View::action('/pro/allees/site', $s['active'] ? 'Désactiver' : 'Activer', ['id' => $s['id'], 'toggle' => '1'], $s['active'] ? 'Désactiver ce marché ?' : null, 'btn ghost sm'),
            ], $sites))
              . '<details class="card new-campaign"><summary>Ajouter ou modifier un marché</summary>' . Html::form('/pro/allees/site', [
                'name' => ['Nom affiché', 'text', '', ['attrs' => ' required']],
                'id' => ['Identifiant (minuscules, ex. dantokpa)', 'text', '', ['attrs' => ' pattern="[a-z0-9-]{2,30}" required']],
                'city' => ['Ville', 'text', 'Cotonou', null],
                'sort_order' => ['Ordre d\'affichage', 'number', '10', null],
                'map_url' => ['Plan d\'accès (lien https, facultatif)', 'text', '', null],
            ], 'Enregistrer le marché') . '</details>';
        $body = $proposalsHtml . $tabs . $list;
        Html::page('Allées et marchés', $body, 'Les allées et les marchés proposés dans l\'application se gèrent ici.');
    }

    /** Proposition « Autre » d'un commerçant : créer l'allée / le marché pour tout le monde, ou ranger l'étal ailleurs */
    public function resolveProposal(Request $req): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $stall = Database::one('SELECT * FROM stalls WHERE id = ?', [(int) ($req->params['id'] ?? 0)]) ?? View::redirect('/pro/allees', 'Étal introuvable.', 'err');
        $aisle = ($_POST['kind'] ?? '') === 'aisle';
        $table = $aisle ? 'aisles' : 'sites';
        $proposed = $aisle ? $stall['aisle_other'] : $stall['site_other'];
        if ($proposed === null) {
            View::redirect('/pro/allees', 'Cette proposition a déjà été traitée.', 'err');
        }
        $target = (string) ($_POST['target'] ?? '');
        $created = $target === 'new';
        if ($target === 'new') {
            $id = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT', $proposed))), '-') ?: 'lieu';
            $id = substr($id, 0, 24);
            $existing = Database::value("SELECT id FROM $table WHERE LOWER(name) = LOWER(?)", [$proposed]);
            if ($existing !== null) {
                $target = (string) $existing;
            } else {
                while (Database::value("SELECT id FROM $table WHERE id = ?", [$id]) !== null) {
                    $id = substr($id, 0, 20) . '-' . random_int(10, 99);
                }
                $aisle
                    ? Database::run('INSERT INTO aisles (id, name, icon, sort_order) VALUES (?, ?, \'other\', 50)', [$id, $proposed])
                    : Database::run('INSERT INTO sites (id, name, sort_order) VALUES (?, ?, 50)', [$id, $proposed]);
                $target = $id;
            }
        } elseif (Database::value("SELECT id FROM $table WHERE id = ? AND active = 1", [$target]) === null) {
            View::redirect('/pro/allees', 'Choix invalide.', 'err');
        }
        // Tous les étals qui ont proposé le même nom sont rangés d'un coup
        $col = $aisle ? 'aisle' : 'site';
        $owners = array_column(Database::all("SELECT DISTINCT user_id FROM stalls WHERE LOWER({$col}_other) = LOWER(?)", [$proposed]), 'user_id');
        $n = Database::run("UPDATE stalls SET {$col}_id = ?, {$col}_other = NULL WHERE LOWER({$col}_other) = LOWER(?)", [$target, $proposed])->rowCount();
        // Le commerçant est prévenu (application, téléphone et email) : sa proposition ne reste pas sans réponse
        $word = $aisle ? 'allée' : 'marché';
        $name = (string) Database::value("SELECT name FROM $table WHERE id = ?", [$target]);
        foreach ($owners as $owner) {
            Notifier::send((int) $owner, 'account', $created ? 'Votre proposition est acceptée' : 'Votre étal a été rangé',
                $created ? "« $proposed » est maintenant dans la liste des {$word}s d'Axi, et votre étal y est rangé." : "« $proposed » n'a pas été ajouté : votre étal est rangé dans « $name ». Vous pouvez le changer dans Modifier mon étal.",
                ['url' => '/commercant/modifier-etal'], [], true);
        }
        $req->user = $agent;
        AdminLog::record($req, $col . '.proposal', $col, null, ['proposed' => $proposed, 'target' => $target, 'stalls' => $n]);
        View::redirect('/pro/allees', '« ' . $proposed . ' » : ' . $n . ' étal(s) rangé(s).');
    }

    public function saveAisle(Request $req): never
    {
        $this->save($req, 'aisles');
    }

    public function saveSite(Request $req): never
    {
        $this->save($req, 'sites');
    }

    private function save(Request $req, string $table): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $id = trim((string) ($_POST['id'] ?? ''));
        if (!preg_match('/^[a-z0-9-]{2,30}$/', $id)) {
            View::redirect('/pro/allees', 'Identifiant invalide : minuscules, chiffres et tirets (2 à 30).', 'err');
        }
        if (isset($_POST['toggle'])) {
            Database::run("UPDATE $table SET active = 1 - active WHERE id = ?", [$id]);
        } else {
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 100) {
                View::redirect('/pro/allees', 'Nom obligatoire (100 caractères maximum).', 'err');
            }
            $order = max(0, min(999, (int) ($_POST['sort_order'] ?? 0)));
            if ($table === 'aisles') {
                $icon = array_key_exists($_POST['icon'] ?? '', self::ICONS) ? $_POST['icon'] : 'other';
                Database::run('INSERT INTO aisles (id, name, icon, sort_order) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), icon = VALUES(icon), sort_order = VALUES(sort_order)', [$id, $name, $icon, $order]);
            } else {
                $map = trim((string) ($_POST['map_url'] ?? ''));
                if ($map !== '' && !str_starts_with($map, 'https://')) {
                    View::redirect('/pro/allees', 'Le plan d\'accès doit être un lien https://.', 'err');
                }
                Database::run('INSERT INTO sites (id, name, city, map_url, sort_order) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), city = VALUES(city), map_url = VALUES(map_url), sort_order = VALUES(sort_order)', [$id, $name, mb_substr(trim((string) ($_POST['city'] ?? 'Cotonou')) ?: 'Cotonou', 0, 100), $map === '' ? null : $map, $order]);
            }
        }
        $req->user = $agent;
        AdminLog::record($req, $table === 'aisles' ? 'aisle.save' : 'site.save', $table === 'aisles' ? 'aisle' : 'site', null, ['id' => $id] + array_diff_key($_POST, ['_csrf' => 1]));
        View::redirect('/pro/allees' . ($table === 'sites' ? '?vue=marches' : ''), 'Enregistré.');
    }
}
