-- =============================================================================
-- 011 : annonces d'arrivage avec plusieurs photos (2 sans abonnement, 5 abonné) ; la date de fin choisie
--       par le commerçant (7 jours au plus sans abonnement, 30 jours abonné) est gardée dans expires_at.
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================
CREATE TABLE stall_announcement_photos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  announcement_id INT UNSIGNED NOT NULL,
  path VARCHAR(255) NOT NULL,
  width SMALLINT UNSIGNED NOT NULL,
  height SMALLINT UNSIGNED NOT NULL,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_announcement_photos (announcement_id, position),
  CONSTRAINT fk_announcement_photos FOREIGN KEY (announcement_id) REFERENCES stall_announcements(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Photo unique des annonces existantes reprise dans la nouvelle table
INSERT INTO stall_announcement_photos (announcement_id, path, width, height, position)
SELECT id, photo_path, COALESCE(photo_width, 600), COALESCE(photo_height, 600), 0 FROM stall_announcements WHERE photo_path IS NOT NULL;
