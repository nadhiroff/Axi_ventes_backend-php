<?php
declare(strict_types=1);

namespace Axi\Web\Controllers;

use Axi\Core\Database;
use Axi\Core\Request;
use Axi\Services\AdminLog;
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
        $body = '<div class="row2"><div><h2 style="margin-top:0">Allées (sous-marchés)</h2>'
            . Html::table(['Ordre', 'Allée', 'Icône', '#Étals', 'État', ''], array_map(static fn (array $a): array => [
                (string) $a['sort_order'], '<strong>' . View::e($a['name']) . '</strong><br><span class="muted">' . View::e($a['id']) . '</span>', View::e(self::ICONS[$a['icon']]), (string) $a['stalls'],
                $a['active'] ? View::badge('Visible', 'ok') : View::badge('Masquée'),
                View::action('/pro/allees/allee', $a['active'] ? 'Masquer' : 'Afficher', ['id' => $a['id'], 'toggle' => '1'], $a['active'] ? 'Masquer cette allée dans l\'application ?' : null, 'btn ghost'),
            ], $aisles))
            . '<div class="card" style="margin-top:14px"><h2 style="margin-top:0">Ajouter ou modifier une allée</h2>' . Html::form('/pro/allees/allee', [
                'id' => ['Identifiant (minuscules, ex. chaussures)', 'text', '', ['attrs' => ' pattern="[a-z0-9-]{2,30}" required']],
                'name' => ['Nom affiché', 'text', '', ['attrs' => ' required']],
                'icon' => ['Icône', 'select', 'other', self::ICONS],
                'sort_order' => ['Ordre d\'affichage', 'number', '10', null],
            ], 'Enregistrer l\'allée') . '</div></div>'
            . '<div><h2 style="margin-top:0">Sites du marché</h2>'
            . Html::table(['Ordre', 'Site', 'Ville', '#Étals', 'État', ''], array_map(static fn (array $s): array => [
                (string) $s['sort_order'], '<strong>' . View::e($s['name']) . '</strong><br><span class="muted">' . View::e($s['id']) . '</span>', View::e($s['city']), (string) $s['stalls'],
                $s['active'] ? View::badge('Actif', 'ok') : View::badge('Inactif'),
                View::action('/pro/allees/site', $s['active'] ? 'Désactiver' : 'Activer', ['id' => $s['id'], 'toggle' => '1'], $s['active'] ? 'Désactiver ce site ?' : null, 'btn ghost'),
            ], $sites))
            . '<div class="card" style="margin-top:14px"><h2 style="margin-top:0">Ajouter ou modifier un site</h2>' . Html::form('/pro/allees/site', [
                'id' => ['Identifiant (minuscules, ex. dantokpa)', 'text', '', ['attrs' => ' pattern="[a-z0-9-]{2,30}" required']],
                'name' => ['Nom affiché', 'text', '', ['attrs' => ' required']],
                'city' => ['Ville', 'text', 'Cotonou', null],
                'map_url' => ['Plan d\'accès (lien https, facultatif)', 'text', '', null],
                'sort_order' => ['Ordre d\'affichage', 'number', '10', null],
            ], 'Enregistrer le site') . '</div></div></div>';
        Html::page('Sites et allées', $body, 'Le sous-marché du pilote n\'étant pas encore choisi, les allées et les sites se gèrent ici.');
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
        View::redirect('/pro/allees', 'Enregistré.');
    }
}
