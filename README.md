# Axi : backend PHP (API de l'application + espace web partenaire Axi Pro)

PHP ≥ 8.1, MySQL 8 / MariaDB 10.5+, PDO. Aucun fichier `.env` : configuration en PHP (`config/config.local.php`).
PHPMailer est inclus dans `vendor/` : **aucun `composer install` à lancer** sur l'hébergement.

- **Application mobile** (clients, commerçants) : API `/v1/…`, voir [docs/POUR_LE_FRONTEND.md](docs/POUR_LE_FRONTEND.md)
- **Espace web Axi Pro** (partenaires) : `/` et `/pro` → page de connexion (email + mot de passe)
- **3 rôles** : client, commerçant, partenaire (sous-types : agent Axi, ANaGeM, annonceur, IMF)

## Lancer en local

```bash
cd /var/www/html/Axi_ventes_backend-php
cp config/config.local.php.example config/config.local.php   # remplir : 'env' => 'local', base, SMTP, clés des passerelles
mysql -u admin -p axi < database/reset.sql                    # base neuve + un compte de test par rôle
php -S 0.0.0.0:8000 -t public public/index.php
```

- API : `http://<IP du PC>:8000/v1/health` (IP : `hostname -I`) → dans le frontend `EXPO_PUBLIC_API_URL=http://<IP>:8000`
- Espace partenaire : `http://localhost:8000/` (redirige vers `/pro/connexion`)

## Comptes de test (créés par `database/reset.sql`)

| Rôle | Email | Mot de passe |
|---|---|---|
| Client | `client@example.com` | `Client-2026!` |
| Commerçant (étal « Étal de test », **non vérifié**) | `commercant@example.com` | `Commercant-2026!` |
| Partenaire · agent Axi | `partenaire@example.com` | `Partenaire-2026!` |
| Partenaire · ANaGeM | `anagem@example.com` | `Partenaire-2026!` |
| Partenaire · annonceur | `annonceur@example.com` | `Partenaire-2026!` |
| Partenaire · IMF | `imf@example.com` | `Partenaire-2026!` |

Les adresses `@example.com` ne reçoivent jamais d'email : en `env = local`, les codes sont aussi écrits dans
`storage/logs/codes.log`. Pour recevoir les emails, créez un compte avec votre vraie adresse.
**En production, ne pas importer `reset.sql`** (mots de passe connus) : importer `database.sql`.

## Tests

```bash
mysql -u admin -p axi < database/reset.sql && php tests/simulation.php   # 99 vérifications (API, web, base)
tests/smoke.sh                                                            # parcours court de connexion
php tests/send_test_mail.php vous@exemple.bj                              # vrai email (SMTP Gmail)
```

La simulation utilise une passerelle factice (en local seulement) pour enchaîner les paiements sans action humaine,
puis crée une vraie transaction dans la sandbox de la passerelle active.

## Paiements sandbox (FedaPay, KkiaPay)

Passerelle active : `payments.provider` dans `config/config.local.php` (`fedapay` ou `kkiapay`). Toutes les clés y sont.

1. Dans l'application : panier → « Payer une fois en Mobile Money » → la page de la passerelle s'ouvre.
2. Numéros de test : **FedaPay** `64000001` (Moov, paiement réussi), `64000000` (Moov, échec), `66000000`
   (MTN, échec) ; **KkiaPay** `61000000` (MTN, réussi).
3. Au retour, l'application demande au serveur de vérifier le statut auprès de la passerelle ; la commande passe à
   « payé ». Dans l'espace partenaire : Paiements et séquestre → la transaction et sa référence.
4. Changer de passerelle : `'provider' => 'kkiapay'` puis recommencer.

**Webhooks** (si le retour de page ne suffit pas) : la passerelle doit joindre le serveur depuis Internet.
En local, exposer le port 8000 avec un tunnel, par exemple `cloudflared tunnel --url http://localhost:8000`
(ou `ngrok http 8000`), mettre l'adresse obtenue dans `public_url`, et déclarer :
- FedaPay (Workbench > Webhooks) : `https://<tunnel>/v1/webhooks/fedapay`, copier la clé dans `fedapay.webhook_secret` ;
- KkiaPay (tableau de bord > Webhooks) : `https://<tunnel>/v1/webhooks/kkiapay` (vérifié avec la clé `secret`).
Sans webhook, la vérification se fait au retour de la page, depuis le suivi de commande, depuis l'espace
partenaire (« Vérifier », « Réconcilier ») et par la tâche planifiée.

Remboursements : KkiaPay rembourse la totalité par API ; FedaPay ne propose pas de remboursement par API
(il est enregistré « en attente » et se fait dans son tableau de bord). Versements aux commerçants : simulés et
enregistrés en sandbox, envoyés par l'API FedaPay en production.

## Mise en ligne (hébergement sans SSH)

1. Créer une base vide (utf8mb4_unicode_ci), phpMyAdmin > Importer > `database/database.sql`.
2. Premier compte agent Axi : `php database/premier_partenaire.php email Prénom Nom` sur le PC, importer le
   `.sql` produit, le supprimer, changer le mot de passe dès la première connexion (« Mon compte »).
3. `config/config.local.php` (copie de l'exemple) : `'env' => 'production'`, base, SMTP, clés **live** des
   passerelles, `public_url`, `cron_key`. Droits 640.
4. Envoyer tout le dossier par FTP (`vendor/` compris). Idéalement, le domaine pointe sur `public/`.
5. Droits d'écriture : `storage/logs/`, `storage/audio/`, `public/uploads/`.
6. Tâche planifiée (cPanel, toutes les 15 min) : `wget -qO- "https://<domaine>/v1/internal/cron?key=<cron_key>"`
   (vérification des paiements, relance de paiement, annulations, libération automatique, rappels, prix médians).

## Structure

```
public/index.php     API (/v1) et espace web (/pro) ; uploads/p/ : photos WebP immuables
routes/api.php       routes de l'application (client, commerçant)
routes/web.php       pages de l'espace partenaire
config/              config.php (défauts) + config.local.php (secrets, non versionné)
src/Controllers/     API : Auth, Account, Catalog, Order, Payment, Sales, Media, Merchant/
src/Web/             espace partenaire : WebAuth (session, CSRF), View, Html, Controllers/
src/Services/        Settings (montants en base), Notifier, Mailer, Broadcaster, Products, Uploads,
                     Payment/ (Gateway, FedaPay, KkiaPay, Escrow), Nightly
database/            migrations/001_schema.sql, database.sql, reset.sql, build_sql.php, migrate.php
tests/               simulation.php, smoke.sh, send_test_mail.php
```
