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
            return '<div class="card muted">' . View::e($empty) . '</div>';
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
                $html .= '<td' . (!empty($numeric[$i]) ? ' class="num"' : '') . '>' . $cell . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div>';
    }

    public static function kpis(array $items): string
    {
        $html = '<div class="grid">';
        foreach ($items as $label => $value) {
            $html .= '<div class="card kpi"><div class="v">' . View::e($value) . '</div><div class="l">' . View::e($label) . '</div></div>';
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
            . ($confirm !== null ? ' onsubmit="return confirm(' . View::e(json_encode($confirm, JSON_UNESCAPED_UNICODE)) . ')"' : '') . '>' . View::csrf();
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
                'checkbox' => '<input id="' . $id . '" type="checkbox" name="' . View::e($name) . '" value="1"' . ($value ? ' checked' : '') . ' style="width:auto;min-height:0">',
                'file' => '<input id="' . $id . '" type="file" name="' . View::e($name) . '" accept="image/*">',
                default => '<input id="' . $id . '" type="' . View::e($type) . '" name="' . View::e($name) . '" value="' . View::e($value) . '"' . ($options['attrs'] ?? '') . '>',
            };
            $html .= '</div>';
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

    public static function page(string $title, string $body, ?string $subtitle = null): never
    {
        View::render('page', ['title' => $title, 'subtitle' => $subtitle, 'body' => $body], $title);
    }
}
