# Axi : API backend (PHP 8 pur)

API REST JSON de la plateforme Axi (marchés de Dantokpa, Ouando, Tokpa Yôyô).
PHP ≥ 8.1, MySQL/MariaDB, PDO. Aucun framework, aucune dépendance Composer.

## Installation locale

1. Récupérer le code :
   ```bash
   cd /var/www/html
   git clone -b claude/compassionate-johnson-w7xr4e https://github.com/nadhiroff/Axi_ventes_backend-php.git
   cd Axi_ventes_backend-php
   ```
2. Créer la base :
   ```bash
   mysql -u admin -p < database/schema.sql
   ```
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

Mettre à jour : `git pull origin claude/compassionate-johnson-w7xr4e`

## Structure

```
public/          point d'entrée unique (index.php) + uploads/images, uploads/audio
config/          config.php (défauts) + config.local.php (non versionné)
routes/api.php   déclaration des routes
src/Core/        Router, Request, Response, Database (PDO), Auth, Validator
src/Controllers/ un contrôleur par écran
src/Models/      accès aux tables (requêtes préparées uniquement)
src/Services/    OTP, SMS, paiement, uploads...
database/        schema.sql
storage/logs/    error.log, sms.log
```

## Format des réponses

```json
{"ok":true,"data":{...}}
{"ok":false,"error":{"code":"OTP_INVALID","message":"Code incorrect ou expiré"}}
```
Montants en FCFA (entiers), dates en UTC ISO 8601.

## Authentification (téléphone + code SMS)

| Méthode | Route | Accès | Corps |
|---|---|---|---|
| POST | `/v1/auth/otp/request` | public | `{"phone":"0197000000"}` |
| POST | `/v1/auth/otp/verify` | public | `{"phone":"0197000000","code":"482913","name":"Maman Afi","role":"merchant"}` |
| GET | `/v1/auth/me` | connecté | — |
| POST | `/v1/auth/logout` | connecté | — |

- `name` est obligatoire seulement à la première inscription ; `role` accepte `client` ou `merchant`.
- Les numéros sont normalisés : `97000000`, `0197000000` et `+229 97 00 00 00` deviennent `+2290197000000`.
- Ensuite, chaque requête protégée envoie `Authorization: Bearer <token>`.
- En local (`env` = `local`), le code est renvoyé dans `debug_code` et écrit dans `storage/logs/sms.log`.

Codes d'erreur : `VALIDATION_FAILED` (422), `OTP_TOO_MANY` (429, avec `retry_in`), `OTP_LOCKED` (429),
`OTP_INVALID` (401), `UNAUTHENTICATED` (401), `TOKEN_INVALID` (401), `ACCOUNT_SUSPENDED` (403), `FORBIDDEN` (403).
