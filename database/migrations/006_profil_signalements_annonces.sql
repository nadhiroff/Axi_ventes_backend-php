-- =============================================================================
-- 006 : présentation publique du profil, suppression d'un message « pour moi », signalements,
--       blocage d'accès avec motif, annonces d'arrivage écrites.
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================

-- « À propos » visible des personnes avec qui l'on échange ; motif affiché quand l'accès est bloqué
ALTER TABLE users
  ADD COLUMN bio VARCHAR(300) NULL AFTER avatar_visibility,
  ADD COLUMN suspended_reason VARCHAR(300) NULL AFTER status;

-- Message supprimé « pour moi » : il reste chez l'autre personne
CREATE TABLE message_hidden (
  user_id INT UNSIGNED NOT NULL,
  message_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, message_id),
  CONSTRAINT fk_hidden_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_hidden_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Signalement d'un profil : examiné par un agent Axi (bloquer l'accès ou classer)
CREATE TABLE reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reporter_id INT UNSIGNED NOT NULL,
  target_user_id INT UNSIGNED NOT NULL,
  conversation_id INT UNSIGNED NULL,
  reason ENUM('spam','fraud','insult','fake','other') NOT NULL,
  details VARCHAR(1000) NULL,
  status ENUM('open','blocked','dismissed') NOT NULL DEFAULT 'open',
  handled_by INT UNSIGNED NULL,
  handled_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY idx_reports_status (status, id),
  KEY idx_reports_target (target_user_id),
  CONSTRAINT fk_reports_reporter FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_reports_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_reports_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Annonce d'arrivage écrite (photo facultative), visible 7 jours sur la page de l'étal ;
-- pour un commerçant abonné, aussi en petite annonce sur l'accueil des clients
CREATE TABLE stall_announcements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_id INT UNSIGNED NOT NULL,
  body VARCHAR(500) NOT NULL,
  photo_path VARCHAR(255) NULL,
  photo_width SMALLINT UNSIGNED NULL,
  photo_height SMALLINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  KEY idx_announcements_stall (stall_id, expires_at),
  KEY idx_announcements_expiry (expires_at),
  CONSTRAINT fk_announcements_stall FOREIGN KEY (stall_id) REFERENCES stalls(id) ON DELETE CASCADE
) ENGINE=InnoDB;
