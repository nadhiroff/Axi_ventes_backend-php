<?php
// Hébergement où le domaine pointe sur ce dossier sans réécriture d'URL : renvoi vers la page de
// connexion de l'espace partenaire. Avec le .htaccess (mod_rewrite), tout est déjà servi par public/.
header('Location: public/pro/connexion', true, 302);
exit;
