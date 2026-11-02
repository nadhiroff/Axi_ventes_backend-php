-- =============================================================================
-- 024 : historique des modifications de profil et d'étal, pour l'équipe Axi.
--   Chaque changement de nom, téléphone, présentation, photo de profil, nom d'étal, contacts ou
--   emplacement est gardé : avant, après, qui (la personne dans l'application ou un agent), quand.
--   Sert à repérer un vrai nom remplacé par un pseudonyme, ou un étal renommé avec le nom d'un autre.
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer), ou php database/migrate.php.
-- =============================================================================
CREATE TABLE profile_changes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  stall_id INT UNSIGNED NULL,
  field VARCHAR(40) NOT NULL,
  old_value VARCHAR(600) NULL,
  new_value VARCHAR(600) NULL,
  source ENUM('app','agent') NOT NULL DEFAULT 'app',
  actor_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_profile_changes_user (user_id, id),
  KEY idx_profile_changes_recent (field, created_at),
  CONSTRAINT fk_profile_changes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
