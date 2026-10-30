-- =============================================================================
-- 017 : « Mes achats et paiements » : vider l'historique ou le laisser disparaître après 3, 6 ou 12 mois.
--       Seulement caché pour la personne : les commandes et paiements restent en base (preuves, comptabilité).
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================
CREATE TABLE sales_hidden (
  user_id INT UNSIGNED NOT NULL,
  order_id INT UNSIGNED NOT NULL,
  side ENUM('buyer','seller') NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, order_id, side),
  CONSTRAINT fk_sales_hidden_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_hidden_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE user_settings ADD COLUMN history_months TINYINT UNSIGNED NOT NULL DEFAULT 0;
