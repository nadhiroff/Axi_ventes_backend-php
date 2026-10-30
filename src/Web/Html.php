<?php
declare(strict_types=1);

namespace Axi\Web;

/** Petits blocs HTML des pages web. Les cellules reçues sont déjà échappées par l'appelant (View::e). */
final class Html
{
    /** Icônes dessinées en trait (SVG en ligne : aucun fichier à charger) */
    private const ICONS = [
        'home' => '<path d="M3 11 12 3l9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        'trend' => '<path d="m3 17 6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
        'megaphone' => '<path d="m3 11 16-6v14L3 13z"/><path d="M7 13.5V18a2 2 0 0 0 4 0v-3"/>',
        'map' => '<path d="m9 4-6 2v14l6-2 6 2 6-2V4l-6 2z"/><path d="M9 4v14M15 6v14"/>',
        'wallet' => '<path d="M3 7a2 2 0 0 1 2-2h13v4"/><path d="M3 7v11a2 2 0 0 0 2 2h15V9H5a2 2 0 0 1-2-2z"/><circle cx="16.5" cy="14.5" r="1"/>',
        'inbox' => '<path d="M3 13h5l2 3h4l2-3h5"/><path d="M5.5 5h13L21 13v6H3v-6z"/>',
        'store' => '<path d="M4 9 5.5 4h13L20 9"/><path d="M4 9a3 3 0 0 0 5 2 3 3 0 0 0 6 0 3 3 0 0 0 5-2"/><path d="M5 12v8h14v-8"/><path d="M10 20v-5h4v5"/>',
        'users' => '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M16 4a4 4 0 0 1 0 8M18 15a7 7 0 0 1 4 6"/>',
        'flag' => '<path d="M5 21V4h12l-2 4 2 4H5"/>',
        'package' => '<path d="m12 3 9 5v8l-9 5-9-5V8z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
        'card' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/>',
        'grid' => '<rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/>',
        'handshake' => '<path d="m11 7-3-2-5 4v7l6 5 2-1 2 1 8-7V9l-5-4-5 4 3 3 2-1"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'back' => '<path d="M19 12H5"/><path d="m12 19-7-7 7-7"/>',
        'shield' => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/>',
        'alert' => '<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17.5v.01"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'pin' => '<path d="M12 21s7-6.5 7-12a7 7 0 0 0-14 0c0 5.5 7 12 7 12z"/><circle cx="12" cy="9" r="2.5"/>',
    ];

    /** Icône de chaque chiffre clé, selon son libellé */
    private const KPI_ICONS = [
        'Étals recensés' => 'store', 'Étals actifs ce mois' => 'trend', 'Commandes en ligne' => 'package', 'Crédits accordés via Axi' => 'wallet',
        'Demandes commerçants' => 'inbox', 'Litiges ouverts' => 'alert', 'Commerçants à payer' => 'card', 'Vérifications sur place' => 'pin',
        'Campagnes à relire' => 'megaphone', 'Paiements bloqués' => 'clock',
        'Crédits en cours' => 'wallet', 'Montant versé' => 'card', 'Déjà remboursé' => 'shield', 'Commission Axi' => 'trend', 'Dossiers partagés' => 'inbox',
    ];

    public static function icon(string $name, int $size = 20): string
    {
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . (self::ICONS[$name] ?? '') . '</svg>';
    }

    /** @param array<int, string> $head en-têtes (texte brut) ; « # » en tête = colonne numérique */
    public static function table(array $head, array $rows, string $empty = 'Aucune donnée pour l\'instant.'): string
    {
        if ($rows === []) {
            return '<div class="card empty">' . View::e($empty) . '</div>';
        }
        $html = '<div class="table-wrap"><table><thead><tr>';
        $numeric = [];
        foreach ($head as $i => $h) {
            $numeric[$i] = str_starts_with($h, '#');
            $html .= '<th' . ($numeric[$i] ? ' class="num"' : '') . '>' . View::e(ltrim($h, '#')) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach (array_values($row) as $i => $cell) {
                // data-label : sur téléphone, chaque ligne devient une carte avec le nom de la colonne
                $html .= '<td data-label="' . View::e(ltrim($head[$i] ?? '', '#')) . '"' . (!empty($numeric[$i]) ? ' class="num"' : '') . '>' . $cell . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div>';
    }

    /**
     * Cartes de chiffres, comme la maquette : libellé, grand nombre, précision.
     * $items : [libellé => valeur] ou [libellé => [valeur, précision, lien ?]]
     */
    public static function kpis(array $items): string
    {
        $html = '<div class="grid">';
        foreach ($items as $label => $value) {
            [$v, $sub, $href] = is_array($value) ? $value + [null, null, null] : [$value, null, null];
            $inner = '<div class="ic">' . self::icon(self::KPI_ICONS[$label] ?? 'grid', 22) . '</div><div class="l">' . View::e($label) . '</div><div class="v">' . View::e($v) . '</div>' . ($sub !== null ? '<div class="s">' . View::e($sub) . '</div>' : '');
            $html .= $href !== null
                ? '<a class="card kpi' . ((int) $v > 0 ? ' alert' : '') . '" href="' . View::url($href) . '">' . $inner . '</a>'
                : '<div class="card kpi">' . $inner . '</div>';
        }
        return $html . '</div>';
    }

    /**
     * Formulaire POST. $fields : [nom => [libellé, type, valeur, options]] ; type : text, email, number, date,
     * password, textarea, select (options = [valeur => libellé]), checkbox, file, hidden.
     */
    public static function form(string $action, array $fields, string $submit, ?string $confirm = null, bool $multipart = false): string
    {
        $html = '<form method="post" action="' . View::url($action) . '" class="form"' . ($multipart ? ' enctype="multipart/form-data"' : '')
            . View::confirmAttrs($confirm, $submit) . '>' . View::csrf();
        $hasPassword = false;
        foreach ($fields as $name => [$label, $type, $value, $options]) {
            $id = 'f_' . preg_replace('/\W/', '_', $name);
            if ($type === 'hidden') {
                $html .= '<input type="hidden" name="' . View::e($name) . '" value="' . View::e($value) . '">';
                continue;
            }
            $full = in_array($type, ['textarea'], true) ? ' class="full"' : '';
            $html .= "<div$full><label for=\"$id\">" . View::e($label) . '</label>';
            $html .= match ($type) {
                'textarea' => '<textarea id="' . $id . '" name="' . View::e($name) . '" rows="3">' . View::e($value) . '</textarea>',
                'select' => '<select id="' . $id . '" name="' . View::e($name) . '">' . implode('', array_map(
                    static fn ($k, $v): string => '<option value="' . View::e($k) . '"' . ((string) $k === (string) $value ? ' selected' : '') . '>' . View::e($v) . '</option>',
                    array_keys($options ?? []), $options ?? [],
                )) . '</select>',
                'checkbox' => '<label class="check"><input id="' . $id . '" type="checkbox" name="' . View::e($name) . '" value="1"' . ($value ? ' checked' : '') . '> Oui</label>',
                'file' => '<input id="' . $id . '" type="file" name="' . View::e($name) . '" accept="' . View::e($options['accept'] ?? 'image/*') . '"' . ($options['attrs'] ?? '') . '>',
                'password' => '<input id="' . $id . '" type="password" data-password name="' . View::e($name) . '"' . ($options['attrs'] ?? '') . '>',
                default => '<input id="' . $id . '" type="' . View::e($type) . '" name="' . View::e($name) . '" value="' . View::e($value) . '"' . ($options['attrs'] ?? '') . '>',
            };
            if (isset($options['hint'])) {
                $html .= '<div class="hint">' . View::e($options['hint']) . '</div>';
            }
            $html .= '</div>';
            $hasPassword = $hasPassword || $type === 'password';
        }
        if ($hasPassword) {
            $html .= '<div class="full"><label class="check"><input type="checkbox" data-show-password> Afficher les mots de passe</label></div>';
        }
        return $html . '<div class="full"><button class="btn" type="submit">' . View::e($submit) . '</button></div></form>';
    }

    /** Filtres GET (recherche, listes) : la liste se met à jour dès qu'un filtre change, sans bouton */
    public static function filters(string $action, array $fields): string
    {
        $html = '<form method="get" action="' . View::url($action) . '" class="filters" data-auto-filter>';
        foreach ($fields as $name => [$label, $type, $options]) {
            $value = $_GET[$name] ?? '';
            $html .= '<div><label for="q_' . View::e($name) . '">' . View::e($label) . '</label>';
            $html .= $type === 'select'
                ? '<select id="q_' . View::e($name) . '" name="' . View::e($name) . '">' . implode('', array_map(static fn ($k, $v): string => '<option value="' . View::e($k) . '"' . ((string) $k === (string) $value ? ' selected' : '') . '>' . View::e($v) . '</option>', array_keys($options), $options)) . '</select>'
                : '<input id="q_' . View::e($name) . '" type="' . View::e($type) . '" name="' . View::e($name) . '" value="' . View::e($value) . '">';
            $html .= '</div>';
        }
        return $html . '<noscript><div style="flex:0 0 auto"><button class="btn ghost" type="submit">Filtrer</button></div></noscript></form>';
    }

    /** Titre de section avec, à droite, des boutons ou liens */
    public static function title(string $title, string $right = ''): string
    {
        return '<div class="section-title"><h2>' . View::e($title) . '</h2>' . ($right !== '' ? '<div class="actions">' . $right . '</div>' : '') . '</div>';
    }

    public static function card(string $title, string $body): string
    {
        return '<div class="card"><h2>' . View::e($title) . '</h2>' . $body . '</div>';
    }

    /** $hero : bandeau d'accueil du tableau de bord (surtitre et liens [libellé, chemin]) */
    public static function page(string $title, string $body, ?string $subtitle = null, ?array $hero = null): never
    {
        View::render('page', ['title' => $title, 'subtitle' => $subtitle, 'body' => $body, 'hero' => $hero], $title);
    }
}
