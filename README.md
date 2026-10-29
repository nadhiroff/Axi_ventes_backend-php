# Axi : API backend (PHP 8 pur)

API REST JSON de la plateforme Axi (marchés de Dantokpa, Ouando, Tokpa Yôyô).
PHP ≥ 8.1, MySQL 5.7+ / MariaDB 10.3+, PDO. Aucun framework, aucune dépendance Composer, **aucun fichier `.env`** :
toute la configuration est dans un fichier PHP (`config/config.local.php`), compatible avec tout hébergement mutualisé.

📄 **Contrat d'API complet : [docs/API.md](docs/API.md)**

## Installation locale

1. Récupérer le code :
   ```bash
   cd /var/www/html
   git clone -b claude/compassionate-johnson-w7xr4e https://github.com/nadhiroff/Axi_ventes_backend-php.git
   cd Axi_ventes_backend-php
   ```
2. Créer la base, les tables et les données de démonstration (dans cet ordre) :
   ```bash
   mysql -u admin -p < database/migrations/001_auth.sql
   mysql -u admin -p < database/migrations/002_marche.sql
   mysql -u admin -p < database/seed.sql
   ```
   Avec phpMyAdmin : onglet « Importer », un fichier après l'autre.
3. Configurer :
   ```bash
   cp config/config.local.php.example config/config.local.php
   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # coller le résultat dans app_secret
   ```
4. Donner à Apache le droit d'écrire les logs et les fichiers envoyés :
   ```bash
   sudo chown -R www-data:www-data storage public/uploads
   ```
5. Lancer, au choix :
   - **Serveur intégré de PHP** : `php -S localhost:8000 -t public public/index.php` puis `http://localhost:8000/v1/health`
   - **Apache** (mod_rewrite actif, `AllowOverride All`) : `http://localhost/Axi_ventes_backend-php/v1/health`

Mettre à jour le code : `git pull origin claude/compassionate-johnson-w7xr4e`
Les nouvelles migrations (`database/migrations/00X_*.sql`) s'exécutent une seule fois chacune, dans l'ordre.

### Comptes de démonstration (seed)

Connexion par code SMS : en local, le code est renvoyé dans `debug_code` et écrit dans `storage/logs/sms.log`.

| Profil | Numéro |
|---|---|
| Maman Afi, commerçante (tissus, a déménagé à Tokpa Yôyô) | `0197000001` |
| Koffi, grossiste (vivriers) | `0197000002` |
| Séna, cliente | `0196000001` |
| Alassane, revendeur à Parakou | `0196000002` |
| Agent Axi | `0195000001` |
| ANaGeM (Axi Pro) | `0194000001` |

## Paiements

FedaPay et Kkiapay, en mode séquestre : l'argent reste chez Axi jusqu'à la réception.
Renseigner les clés de test dans `config/config.local.php` (`payments`). Sans clé, le fournisseur répond `503 PROVIDER_NOT_CONFIGURED`.
En local (`env` = `local`), le fournisseur `simulation` permet de tester tout le parcours sans compte de paiement.
Webhooks à déclarer chez les fournisseurs : `/v1/webhooks/fedapay` et `/v1/webhooks/kkiapay`.

## Tâche planifiée

À lancer toutes les 5 minutes (cPanel > « Tâches cron », ou `crontab -e`) :
```
*/5 * * * * php /chemin/vers/Axi_ventes_backend-php/cron/run.php
```
Elle envoie les SMS en attente, annule les commandes non payées après 24 h, libère l'argent des commandes remises
et non confirmées après 7 jours, et supprime les anciens codes SMS.

## Hébergement mutualisé

- Envoyer tout le dossier. Si possible, faire pointer le domaine sur `public/`. Sinon, le `.htaccess` à la racine redirige tout vers `public/`, et `config/`, `src/`, `database/` ne sont jamais servis.
- Créer `config/config.local.php` directement sur le serveur, avec `'env' => 'production'`, les identifiants MySQL de l'hébergeur et `public_url` (ex. `https://api.axi.bj`).
- Extensions PHP nécessaires : `pdo_mysql`, `fileinfo`, `curl` (paiements), `zlib` (compression, conseillée).

## Structure

```
public/          point d'entrée unique (index.php) + uploads/images, uploads/audio
config/          config.php (défauts) + config.local.php (non versionné)
routes/api.php   déclaration des routes
src/Core/        Router, Request, Response, Database (PDO), Auth, Validator, Page, Url, Clock
src/Controllers/ Client/, Merchant/, Pro/, Auth, Paiements, Notifications, Agents
src/Models/      accès aux tables (requêtes préparées uniquement)
src/Services/    paiement (FedaPay, Kkiapay), panier, commandes, envois groupés, fichiers, Axi Pro
database/        migrations/ + seed.sql
cron/run.php     tâches planifiées
storage/logs/    error.log, sms.log, payments.log
docs/API.md      contrat d'API
```

## Réglages métier (config/config.php)

Les montants de la note de cadrage sont des hypothèses, toutes modifiables dans `config.local.php` :
frais de regroupement (250 FCFA par étal supplémentaire), commission (0 % pendant le pilote),
abonnement (2 000 FCFA en détail, 5 000 FCFA en gros), libération automatique (7 jours), seuil d'anonymat Axi Pro (3 étals),
mention ANaGeM sur l'accueil (`anagem_partnership`).
