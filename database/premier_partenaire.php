<?php
declare(strict_types=1);

// Génère database/premier_partenaire.sql : le premier compte AGENT AXI (partenaire de l'organisation Axi),
// à importer UNE fois dans phpMyAdmin après database.sql. À lancer sur le PC (pas besoin de SSH) :
//   php database/premier_partenaire.php agent@votre-domaine.bj Prénom Nom
// Le mot de passe provisoire est affiché une seule fois : le changer dès la première connexion.

[, $email, $first, $last] = $argv + [null, null, null, null];
if (!filter_var((string) $email, FILTER_VALIDATE_EMAIL) || !$first || !$last) {
    fwrite(STDERR, "Usage : php database/premier_partenaire.php email Prénom Nom\n");
    exit(2);
}
$password = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
$q = static fn (string $v): string => "'" . str_replace(["\\", "'"], ["\\\\", "''"], $v) . "'";
$sql = "-- Premier agent Axi (généré le " . gmdate('Y-m-d H:i') . " UTC). À importer une seule fois, puis supprimer ce fichier.\n"
    . "INSERT INTO users (first_name, last_name, name, email, password_hash, email_verified_at, role, organization_id)\n"
    . 'VALUES (' . implode(', ', [$q($first), $q($last), $q("$first $last"), $q(strtolower($email)), $q(password_hash($password, PASSWORD_DEFAULT)), 'UTC_TIMESTAMP()', "'partner'", '1']) . ");\n";
file_put_contents(__DIR__ . '/premier_partenaire.sql', $sql);
echo "database/premier_partenaire.sql créé pour $email\nMot de passe provisoire (affiché une seule fois) : $password\n";
