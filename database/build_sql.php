<?php
declare(strict_types=1);

// Régénère, à partir de database/migrations/*.sql :
//   database/database.sql : installation sur une base VIDE (structure + données de référence, aucun compte)
//   database/reset.sql    : remise à zéro complète (supprime toutes les tables Axi, recrée tout,
//                           ajoute un compte de test par rôle). À n'utiliser qu'en test.
// Usage (sur le PC de développement) : php database/build_sql.php

$files = glob(__DIR__ . '/migrations/*.sql');
sort($files);

$body = '';
$tables = [];
foreach ($files as $path) {
    $file = basename($path);
    $sql = (string) file_get_contents($path);
    $sql = (string) preg_replace('/^\s*(USE|CREATE DATABASE)\b[^;]*;\s*$/mi', '', $sql);
    $sql = (string) preg_replace('/^SET NAMES utf8mb4;\s*$/mi', '', $sql);
    preg_match_all('/^CREATE TABLE (\w+)/mi', $sql, $m);
    array_push($tables, ...$m[1]);
    $body .= "\n-- -----------------------------------------------------------------------------\n-- $file\n-- -----------------------------------------------------------------------------\n"
        . trim($sql) . "\n\nINSERT INTO schema_migrations (file, applied_at) VALUES ('$file', UTC_TIMESTAMP());\n";
}

$head = static fn (string $title, string $usage): string => <<<SQL
-- =============================================================================
-- Axi : $title
-- =============================================================================
-- Fichier généré par « php database/build_sql.php » à partir de database/migrations/.
-- Ne pas modifier à la main.
$usage
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

SQL;

$migrationsTable = "CREATE TABLE schema_migrations (\n  file VARCHAR(190) PRIMARY KEY,\n  applied_at DATETIME NOT NULL\n) ENGINE=InnoDB;\n";

file_put_contents(__DIR__ . '/database.sql', $head('installation complète de la base de données', <<<TXT
--
-- INSTALLATION (hébergement sans SSH) :
--   1. Créer une base VIDE dans le panneau de l'hébergeur, interclassement utf8mb4_unicode_ci.
--   2. phpMyAdmin > choisir CETTE base > onglet « Importer » > ce fichier > Exécuter.
--   3. Créer le premier compte partenaire (agent Axi) : php database/premier_partenaire.php (voir README).
--
-- MISE À JOUR d'une base déjà installée : ne PAS réimporter ce fichier ; importer seulement les nouveaux
-- fichiers de database/migrations/ absents de la table schema_migrations.
TXT) . $migrationsTable . $body);

// Tables d'anciennes versions, supprimées aussi par le reset
$legacy = ['otp_codes', 'login_failures', 'cart_items', 'sms_queue', 'stall_moves', 'catalog_items', 'promotions', 'delivery_options', 'stall_orders', 'merchant_customers', 'payout_requests', 'credit_offers', 'credit_requests', 'follows', 'markets'];
$drop = 'DROP TABLE IF EXISTS ' . implode(', ', array_unique(array_merge(array_reverse($tables), $legacy, ['schema_migrations']))) . ";\n";

// Comptes de test : mots de passe connus, empreinte bcrypt stable (sel fixe) pour un fichier reproductible
$hash = static fn (string $password): string => crypt($password, '$2y$10$AxiResetTestAccountsSalt');
$accounts = [
    // [prénom, nom, email, mot de passe, rôle, organisation]
    ['Cliente', 'Test', 'client@example.com', 'Client-2026!', 'client', null],
    ['Commerçante', 'Test', 'commercant@example.com', 'Commercant-2026!', 'merchant', null],
    ['Agent', 'Axi', 'partenaire@example.com', 'Partenaire-2026!', 'partner', 1],
    ['Service', 'ANaGeM', 'anagem@example.com', 'Partenaire-2026!', 'partner', 2],
    ['Marque', 'Annonceur', 'annonceur@example.com', 'Partenaire-2026!', 'partner', 3],
    ['Conseiller', 'IMF', 'imf@example.com', 'Partenaire-2026!', 'partner', 4],
];
$q = static fn (?string $v): string => $v === null ? 'NULL' : "'" . str_replace("'", "''", $v) . "'";
$seed = "\n-- -----------------------------------------------------------------------------\n-- Comptes de test (un par rôle, et un par type de partenaire). Changer les mots de passe en production.\n"
    . "-- -----------------------------------------------------------------------------\n"
    . "INSERT INTO organizations (id, name, type) VALUES (2, 'ANaGeM', 'anagem'), (3, 'Annonceur de test', 'advertiser'), (4, 'IMF de test', 'mfi');\n";
foreach ($accounts as $i => [$first, $last, $email, $password, $role, $org]) {
    $seed .= 'INSERT INTO users (id, first_name, last_name, name, email, password_hash, email_verified_at, role, organization_id) VALUES ('
        . implode(', ', [$i + 1, $q($first), $q($last), $q("$first $last"), $q($email), $q($hash($password)), 'UTC_TIMESTAMP()', $q($role), $org ?? 'NULL']) . ");\n";
}
$seed .= "-- Étal de la commerçante de test : NON vérifié, pour tester la vérification par un agent dans l'espace web\n"
    . "INSERT INTO stalls (user_id, name, tagline, site_id, aisle_id, row_label, stand, merchant_type, pay_on_delivery) VALUES (2, 'Étal de test', 'Tissus et pagnes', 'yoyo', 'tissus', 'Allée A', '1', 'retail', 1);\n";

file_put_contents(__DIR__ . '/reset.sql', $head('REMISE À ZÉRO de la base (TEST UNIQUEMENT)', <<<TXT
--
-- ATTENTION : supprime TOUTES les données Axi (comptes, commandes, paiements) puis recrée la base
-- avec les données de référence et un compte de test par rôle (mots de passe dans README.md).
-- phpMyAdmin > la base Axi > « Importer » > ce fichier. En local : mysql -u … axi < database/reset.sql
TXT) . "SET FOREIGN_KEY_CHECKS = 0;\n$drop" . "SET FOREIGN_KEY_CHECKS = 1;\n\n" . $migrationsTable . $body . $seed);

echo 'database.sql et reset.sql : ' . count($files) . ' migration(s), ' . count($tables) . " tables.\n";
