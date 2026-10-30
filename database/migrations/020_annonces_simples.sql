-- =============================================================================
-- 020 : annonces simples : image ou vidéo, partagées aux clients, aux commerçants ou à tous ;
--       chacun peut signaler une annonce, l'équipe Axi voit les signalements et la corrige directement.
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================
ALTER TABLE campaigns
  MODIFY audience_type ENUM('merchants','clients','all') NOT NULL,
  ADD COLUMN video_path VARCHAR(255) NULL AFTER image_path;

CREATE TABLE campaign_reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  reason VARCHAR(30) NOT NULL,
  status ENUM('open','handled') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_campaign_report (campaign_id, user_id),
  KEY idx_campaign_reports (status, campaign_id),
  CONSTRAINT fk_campaign_report FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
  CONSTRAINT fk_campaign_report_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
