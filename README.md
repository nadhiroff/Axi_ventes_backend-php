# Axi : API backend (PHP 8)

API REST JSON de la plateforme Axi, le prolongement en ligne des grands marchés du Bénin.
PHP ≥ 8.1, MySQL 8 / MariaDB 10.5+, PDO. Aucun fichier `.env` : la configuration est en PHP.
Les dépendances (PHPMailer) sont incluses dans `vendor/` : **aucun `composer install` à lancer**.

- Contrat d'origine : `docs/API_CONTRACT.md` du dépôt frontend
- **Écarts et ajouts (connexion par email, paiement sans double débit, agents…) : [docs/POUR_LE_FRONTEND.md](docs/POUR_LE_FRONTEND.md)**

## Mise en ligne sur un hébergement mutualisé (sans SSH)

1. **Base de données** : créer une base vide dans le panneau de l'hébergeur (cPanel > « Bases de données MySQL »,
   interclassement `utf8mb4_unicode_ci`), puis phpMyAdmin > cette base > « Importer » > `database/database.sql`.
2. **Premier administrateur** : sur le PC, lancer
   `php database/premier_admin.php admin@votre-domaine.bj Prénom Nom +2290190000000`.
   Le mot de passe provisoire s'affiche une seule fois. Importer le fichier `database/premier_admin.sql`
   créé, puis le supprimer, et changer le mot de passe dès la première connexion.
3. **Configuration** : copier `config/config.local.php.example` en `config/config.local.php` et le remplir :
   `'env' => 'production'`, base de l'hébergeur, SMTP, `app_key`, `cron_key`, `public_url`, `cors_origins`.
   Générer une clé : `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` (64 caractères pour `app_key`, 40 ou plus pour `cron_key`).
4. **Envoi des fichiers** (FTP ou gestionnaire de fichiers) : tout le dossier, `vendor/` compris.
   Idéalement, le domaine pointe sur `public/` ; sinon le `.htaccess` racine y renvoie tout.
   `config/`, `src/`, `database/`, `storage/`, `vendor/`… ont chacun un `.htaccess` « Require all denied ».
5. **Droits d'écriture** pour PHP : `storage/logs/`, `storage/audio/`, `public/uploads/`.
   `config/config.local.php` en 640 (ou 600).
6. **Tâche planifiée** (cPanel > « Tâches cron », une fois par nuit) :
   `wget -qO- "https://api.votre-domaine.bj/v1/internal/cron?key=VOTRE_CRON_KEY" > /dev/null`
7. Vérifier : `https://api.votre-domaine.bj/v1/health` doit répondre `{"ok":true,…}`.

**Mise à jour d'une base déjà en ligne** : ne jamais réimporter `database.sql`. Importer seulement
les nouveaux fichiers de `database/migrations/` absents de la table `schema_migrations` (dans l'ordre).

Extensions PHP nécessaires : `pdo_mysql`, `gd` (avec WebP), `fileinfo`, `mbstring`, `openssl`, `zlib`.
Gmail limite l'envoi à environ 500 emails par jour : prévoir un service d'envoi transactionnel en production.

## Développement local

```bash
cd /var/www/html/Axi_ventes_backend-php
cp config/config.local.php.example config/config.local.php   # 'env' => 'local', 'payment_provider' => 'fake'
php database/migrate.php        # applique les migrations pas encore exécutées
php database/seed.php           # données de démonstration (local seulement), mot de passe axi-demo-2026
php cron/run.php                # prix médians d'Axi Pro
php -S 0.0.0.0:8000 -t public public/index.php
```

Téléphone sur le même Wi-Fi : `EXPO_PUBLIC_API_URL=http://<IP du PC>:8000` (`hostname -I`).
Après une nouvelle migration : `php database/build_sql.php` régénère `database/database.sql`.

## Tests

```bash
php tests/simulation.php        # 96 vérifications : tous les parcours + enregistrements en base + double paiement
tests/smoke.sh                  # parcours court : inscription → code → connexion → /v1/me → /v1/home → déconnexion
php tests/send_test_mail.php --check            # connexion SMTP seulement
php tests/send_test_mail.php vous@exemple.bj    # vrai email de test
```

En `env = local`, chaque code est aussi écrit dans `storage/logs/codes.log`, et chaque demande de débit
du prestataire factice dans `storage/logs/payments.log`. Les adresses `@example.com` ne reçoivent jamais d'email.

## Paiement : jamais deux débits

- L'application envoie `Idempotency-Key` sur `POST /v1/orders` : la même clé renvoie toujours la même commande.
- Sans clé, le même panier envoyé deux fois en 15 minutes renvoie la commande déjà créée.
- Un verrou MySQL par client bloque les envois simultanés ; un seul paiement par commande (index unique).
- La commande et son paiement (référence fixe `axi_pay_<id>`) sont enregistrés **avant** d'appeler le prestataire.
  Après une coupure réseau, le nouvel essai réutilise cette référence. Le futur prestataire réel
  (`src/Services/Payment/PaymentProvider.php`) doit la transmettre comme référence marchande / clé d'idempotence.
- L'argent reste chez Axi jusqu'à la réception (`confirm-receipt`), ou 7 jours après la remise au coursier.

## Structure

```
public/index.php     point d'entrée unique (CORS, gzip) ; public/uploads/p/ : photos WebP (URL immuables)
routes/api.php       toutes les routes et les rôles exigés
config/              config.php (défauts, versionné) + config.local.php (secrets, jamais versionné)
src/Core/            Router, Request, Response (enveloppe, ETag, 304), Database (PDO, verrous), Auth, Validator
src/Controllers/     Auth, Catalog (client), Order, Cron, Merchant/, Admin/ (agents), Pro/ (Axi Pro)
src/Services/        Mailer (PHPMailer), EmailCodes, Products, Uploads (WebP), Nightly, Payment/
src/Support/         mise en forme du JSON du contrat (Users, Stalls)
database/            database.sql (installation), migrations/, migrate.php, build_sql.php, seed.php, premier_admin.php
storage/             logs/ (error, mail, codes, payments, broadcasts) ; audio/ (vocaux, privés)
vendor/              PHPMailer (inclus, pas de Composer sur le serveur)
```
