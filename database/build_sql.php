<?php
declare(strict_types=1);

// Régénère database/database.sql : toutes les migrations dans l'ordre, en un seul fichier
// à importer avec phpMyAdmin sur une base VIDE (hébergement sans SSH).
// Usage (sur le PC de développement) : php database/build_sql.php

$files = glob(__DIR__ . '/migrations/*.sql');
sort($files);

$out = <<<SQL
-- =============================================================================
-- Axi : installation complète de la base de données
-- =============================================================================
-- Fichier généré par « php database/build_sql.php » à partir de database/migrations/.
-- Ne pas modifier à la main.
--
-- INSTALLATION (hébergement sans SSH) :
--   1. Créer une base vide dans le panneau de l'hébergeur (cPanel > Bases de données MySQL),
--      en interclassement utf8mb4_unicode_ci.
--   2. phpMyAdmin > choisir CETTE base > onglet « Importer » > ce fichier > Exécuter.
--   3. Créer le premier administrateur : importer database/premier_admin.sql (voir README).
--
-- MISE À JOUR d'une base déjà installée : ne PAS réimporter ce fichier. Importer seulement
-- les nouveaux fichiers de database/migrations/ absents de la table schema_migrations.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE schema_migrations (
  file VARCHAR(190) PRIMARY KEY,
  applied_at DATETIME NOT NULL
) ENGINE=InnoDB;

SQL;

foreach ($files as $path) {
    $file = basename($path);
    $sql = (string) file_get_contents($path);
    // La base est choisie dans phpMyAdmin : on retire CREATE DATABASE / USE
    $sql = (string) preg_replace('/^\s*(USE|CREATE DATABASE)\b[^;]*;\s*$/mi', '', $sql);
    $sql = (string) preg_replace('/^SET NAMES utf8mb4;\s*$/mi', '', $sql);
    $out .= "\n-- -----------------------------------------------------------------------------\n-- $file\n-- -----------------------------------------------------------------------------\n"
        . trim($sql) . "\n\nINSERT INTO schema_migrations (file, applied_at) VALUES ('$file', UTC_TIMESTAMP());\n";
}

file_put_contents(__DIR__ . '/database.sql', $out);
echo 'database/database.sql : ' . count($files) . " migration(s).\n";
