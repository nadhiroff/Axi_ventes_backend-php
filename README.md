# Axi : API backend (PHP 8)

API REST JSON de la plateforme Axi, le prolongement en ligne des grands marchés du Bénin.
PHP ≥ 8.1, MySQL 8 / MariaDB 10.5+, PDO, Composer (PHPMailer). Configuration par fichier `.env`.

- Contrat d'origine : `docs/API_CONTRACT.md` du dépôt frontend
- **Écarts et ajouts (connexion par email, espace agents…) : [docs/POUR_LE_FRONTEND.md](docs/POUR_LE_FRONTEND.md)**

## Installation locale

```bash
cd /var/www/html/Axi_ventes_backend-php
composer install
cp .env.example .env            # puis compléter : DB_*, MAIL_*, et :
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # → APP_KEY
php database/migrate.php        # applique database/migrations/*.sql pas encore exécutés
php database/seed.php           # données de démonstration (APP_ENV=local seulement)
php cron/run.php                # calcule les prix médians d'Axi Pro
```

## Lancer l'API

```bash
php -S 0.0.0.0:8000 -t public public/index.php
```

- Sur le PC : `http://localhost:8000/v1/health`
- Depuis un téléphone sur le même Wi-Fi : `http://<IP du PC>:8000` (`hostname -I`, première adresse),
  à mettre dans le `.env.local` du frontend : `EXPO_PUBLIC_API_URL=http://<IP>:8000`.
  Si le téléphone n'y arrive pas : `sudo ufw allow 8000/tcp`.

Apache (`/var/www/html`) : les `.htaccess` exigent `AllowOverride All` et `mod_rewrite`
(actuellement `AllowOverride None` sur cette machine). L'API est alors à `http://localhost/Axi_ventes_backend-php/v1/…`.

## Tests

```bash
tests/smoke.sh                                  # inscription → code → vérification → connexion → /v1/me → /v1/home → déconnexion
tests/smoke.sh http://192.168.100.175:8000      # contre une autre adresse
php tests/send_test_mail.php --check            # connexion SMTP seulement, sans envoi
php tests/send_test_mail.php vous@exemple.bj    # vrai email de test
```

En `APP_ENV=local`, chaque code envoyé est aussi écrit dans `storage/logs/codes.log`. Les adresses
`@example.com` (et `.test`) ne reçoivent jamais d'email : idéal pour tester sans boîte mail.

Comptes de démonstration : voir `docs/POUR_LE_FRONTEND.md`, section 8 (mot de passe `axi-demo-2026`).

## Tâche planifiée

Une fois par nuit (prix médians d'Axi Pro, ménage des codes) :

```
30 2 * * * php /chemin/vers/Axi_ventes_backend-php/cron/run.php
```

## Structure

```
public/index.php     point d'entrée unique (CORS, gzip) ; public/uploads/p/ : photos WebP (URL immuables)
routes/api.php       toutes les routes et les rôles exigés
config/config.php    réglages (lus dans .env) et hypothèses métier : frais, abonnements, seuil d'anonymat (5)
src/Core/            Router, Request, Response (enveloppe, ETag, 304), Database (PDO), Auth, Validator, Env
src/Controllers/     Auth, Catalog (client), Order, Merchant/, Admin/ (agents), Pro/ (Axi Pro)
src/Services/        Mailer (PHPMailer), EmailCodes, Products, Uploads (WebP), Payment/ (PaymentProvider)
src/Support/         mise en forme du JSON du contrat (Users, Stalls)
database/            migrations/, migrate.php, seed.php
storage/logs/        error.log, mail.log, codes.log (local), broadcasts.log ; storage/audio/ : vocaux (privés)
```

## Hébergement

- Faire pointer le domaine sur `public/`, ou utiliser le `.htaccess` racine qui y renvoie tout.
- Envoyer `vendor/` (ou lancer `composer install --no-dev` sur le serveur) et créer `.env` sur le serveur,
  avec `APP_ENV=production`, sans `PAYMENT_PROVIDER=fake` (refusé hors local).
- Extensions PHP : `pdo_mysql`, `gd` (avec WebP), `fileinfo`, `mbstring`, `openssl`, `zlib`.
- Gmail limite l'envoi à environ 500 emails par jour : prévoir un service d'envoi transactionnel en production.
