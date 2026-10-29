<?php
declare(strict_types=1);

namespace Axi\Web;

/** Petits blocs HTML des pages web. Les cellules reçues sont déjà échappées par l'appelant (View::e). */
final class Html
{
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
            $inner = '<div class="l">' . View::e($label) . '</div><div class="v">' . View::e($v) . '</div>' . ($sub !== null ? '<div class="s">' . View::e($sub) . '</div>' : '');
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
                'file' => '<input id="' . $id . '" type="file" name="' . View::e($name) . '" accept="image/*"' . ($options['attrs'] ?? '') . '>',
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

    /** Filtres GET (recherche, listes) */
    public static function filters(string $action, array $fields): string
    {
        $html = '<form method="get" action="' . View::url($action) . '" class="filters">';
        foreach ($fields as $name => [$label, $type, $options]) {
            $value = $_GET[$name] ?? '';
            $html .= '<div><label for="q_' . View::e($name) . '">' . View::e($label) . '</label>';
            $html .= $type === 'select'
                ? '<select id="q_' . View::e($name) . '" name="' . View::e($name) . '">' . implode('', array_map(static fn ($k, $v): string => '<option value="' . View::e($k) . '"' . ((string) $k === (string) $value ? ' selected' : '') . '>' . View::e($v) . '</option>', array_keys($options), $options)) . '</select>'
                : '<input id="q_' . View::e($name) . '" type="' . View::e($type) . '" name="' . View::e($name) . '" value="' . View::e($value) . '">';
            $html .= '</div>';
        }
        return $html . '<div style="flex:0 0 auto"><button class="btn ghost" type="submit">Filtrer</button></div></form>';
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

    public static function page(string $title, string $body, ?string $subtitle = null): never
    {
        View::render('page', ['title' => $title, 'subtitle' => $subtitle, 'body' => $body], $title);
    }
}
