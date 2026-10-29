-- =============================================================================
-- 004 : réponse à un message, galerie de photos de l'étal, taux de change (euro, dollar).
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================

ALTER TABLE messages ADD COLUMN reply_to_id INT UNSIGNED NULL AFTER file_height;

-- Photos supplémentaires de l'étal (la première photo reste stalls.photo_path), 8 au plus
CREATE TABLE stall_photos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_id INT UNSIGNED NOT NULL,
  path VARCHAR(255) NOT NULL,
  width SMALLINT UNSIGNED NOT NULL,
  height SMALLINT UNSIGNED NOT NULL,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY idx_stall_photos (stall_id, position),
  CONSTRAINT fk_stall_photos_stall FOREIGN KEY (stall_id) REFERENCES stalls(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Taux réels : l'euro est fixe (1 € = 655,957 F CFA, parité officielle) ; le dollar est relu
-- automatiquement (open.er-api.com) au plus toutes les 12 h. Valeur de départ, remplacée au premier appel.
INSERT INTO settings (k, v, label, type) VALUES
  ('rate_xof_per_usd', '600', 'Taux du dollar en F CFA (mis à jour automatiquement)', 'text'),
  ('rate_updated_at', '2000-01-01 00:00:00', 'Dernière mise à jour du taux du dollar (UTC)', 'text');
