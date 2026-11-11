-- Adresse de contact en cas de problème (compte bloqué, aide) : support@rgz.bj
-- Modifiable ensuite par un agent Axi dans l'espace web (Réglages).
UPDATE settings SET v = 'support@rgz.bj', label = 'Email de contact en cas de problème (comptes bloqués, aide)' WHERE k = 'support_email';
