-- =============================================================================
-- 025 : un client ou un commerçant peut bloquer un autre utilisateur qui le dérange (sans passer par l'équipe Axi).
--   Tant que le blocage dure, les deux ne se voient plus nulle part : discussion, profil, étal, produits,
--   recherche, accueil, fichier clients, messages groupés ; aucun message ni commande de l'un à l'autre.
--   Les commandes déjà passées restent consultables (argent engagé). Débloquer rétablit tout.
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer), ou php database/migrate.php.
-- =============================================================================
CREATE TABLE user_blocks (
  blocker_id INT UNSIGNED NOT NULL,
  blocked_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (blocker_id, blocked_id),
  KEY idx_user_blocks_blocked (blocked_id, blocker_id),
  CONSTRAINT fk_user_blocks_blocker FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_blocks_blocked FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
