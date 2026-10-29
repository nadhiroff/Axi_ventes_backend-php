<?php
declare(strict_types=1);

// Génère database/premier_admin.sql : le premier compte administrateur, à importer UNE fois
// dans phpMyAdmin après database.sql. À lancer sur le PC (pas besoin de SSH sur l'hébergement) :
//   php database/premier_admin.php admin@votre-domaine.bj Prénom Nom +2290190000000
// Le mot de passe provisoire est affiché une seule fois : le changer dès la première connexion
// (POST /v1/auth/change-password). Le fichier .sql généré n'est jamais versionné.

[, $email, $first, $last, $phone] = $argv + [null, null, null, null, null];
if (!filter_var((string) $email, FILTER_VALIDATE_EMAIL) || !$first || !$last || !preg_match('/^\+[1-9]\d{7,14}$/', (string) $phone)) {
    fwrite(STDERR, "Usage : php database/premier_admin.php email Prénom Nom +2290190000000\n");
    exit(2);
}
$password = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
$q = static fn (string $v): string => "'" . str_replace(["\\", "'"], ["\\\\", "''"], $v) . "'";
$email = strtolower($email);
$sql = "-- Premier administrateur Axi (généré le " . gmdate('Y-m-d H:i') . " UTC). À importer une seule fois, puis supprimer ce fichier.\n"
    . "INSERT INTO users (phone, first_name, last_name, name, email, password_hash, email_verified_at, role, created_at)\n"
    . 'VALUES (' . implode(', ', [$q($phone), $q($first), $q($last), $q("$first $last"), $q($email), $q(password_hash($password, PASSWORD_DEFAULT)), 'UTC_TIMESTAMP()', "'admin'", 'UTC_TIMESTAMP()']) . ");\n";
file_put_contents(__DIR__ . '/premier_admin.sql', $sql);
echo "database/premier_admin.sql créé pour $email\nMot de passe provisoire (affiché une seule fois) : $password\n";
