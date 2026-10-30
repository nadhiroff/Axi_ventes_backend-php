-- =============================================================================
-- 009 : description d'un produit (matière, taille, couleur, origine…), visible sur sa fiche.
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================
ALTER TABLE products ADD COLUMN description VARCHAR(1000) NULL AFTER name;
