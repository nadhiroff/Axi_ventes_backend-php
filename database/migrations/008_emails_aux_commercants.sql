-- =============================================================================
-- 008 : email envoyé à un commerçant depuis la page de son étal (son adresse reste cachée au client).
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================
CREATE TABLE stall_emails (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  subject VARCHAR(150) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_stall_emails_user (user_id, created_at),
  CONSTRAINT fk_stall_emails_stall FOREIGN KEY (stall_id) REFERENCES stalls(id) ON DELETE CASCADE,
  CONSTRAINT fk_stall_emails_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
