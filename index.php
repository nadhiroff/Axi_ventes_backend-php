<?php
// Entrée à la racine du projet : mène directement à la page de connexion de l'espace partenaire Axi Pro.
// L'adresse passe par public/index.php : elle fonctionne même quand Apache ne lit pas les .htaccess
// (AllowOverride None). Avec la réécriture active, le .htaccess racine sert déjà tout depuis public/.
header('Location: public/index.php/pro/connexion', true, 302);
exit;
