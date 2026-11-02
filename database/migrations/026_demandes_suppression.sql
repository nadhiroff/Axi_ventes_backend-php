-- =============================================================================
-- 026 : suppression de compte sur demande. La personne donne son mot de passe et la raison ; la demande attend
--   l'équipe Axi, qui efface définitivement le compte et ses informations, ou annule la demande (personne prévenue).
--   Après effacement, la ligne ne garde plus aucune donnée personnelle (raison vidée) : seulement qui, quand.
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer), ou php database/migrate.php.
-- =============================================================================
CREATE TABLE account_deletion_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  reason VARCHAR(1000) NULL,
  status ENUM('pending','erased','cancelled','withdrawn') NOT NULL DEFAULT 'pending',
  agent_note VARCHAR(500) NULL,
  decided_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  decided_at DATETIME NULL,
  KEY idx_deletion_status (status, created_at),
  KEY idx_deletion_user (user_id, status),
  CONSTRAINT fk_deletion_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_deletion_agent FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
