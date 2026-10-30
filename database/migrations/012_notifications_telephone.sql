-- =============================================================================
-- 012 : notifications du téléphone (alertes système, même application fermée).
--       Un jeton Expo par téléphone, lié à la session : déconnexion = plus d'alerte sur ce téléphone.
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================
CREATE TABLE push_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  auth_token_id INT UNSIGNED NOT NULL,
  token VARCHAR(255) NOT NULL,
  platform ENUM('ios','android','web') NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_push_token (token),
  KEY idx_push_user (user_id),
  CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_push_auth FOREIGN KEY (auth_token_id) REFERENCES auth_tokens(id) ON DELETE CASCADE
) ENGINE=InnoDB;
