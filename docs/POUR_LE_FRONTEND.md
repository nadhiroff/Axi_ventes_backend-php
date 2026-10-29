# Axi : changements de l'API pour le frontend

Ce document liste **tout ce qui change par rapport à `docs/API_CONTRACT.md`** du frontend.
Le reste du contrat est appliqué tel quel : enveloppe, `snake_case`, montants entiers en FCFA,
dates ISO 8601 UTC, `null` plutôt qu'une clé absente, `[]` plutôt que `null`, ETag et `304` sur les `GET`.

Exemples tirés du serveur réel (données de démonstration, `php database/seed.php`).
Dans les exemples, `meta` est omis : il est toujours présent.

```json
"meta": { "server_time": "2026-10-29T11:10:25Z", "max_age_s": 0 }
```

## Sommaire

0. [Adresse de l'API](#0-adresse-de-lapi)
1. [Changements généraux](#1-changements-généraux)
2. [Authentification par email : remplace la section 10](#2-authentification-par-email--remplace-la-section-10)
3. [Écarts sur les routes existantes du contrat](#3-écarts-sur-les-routes-existantes-du-contrat)
4. [Routes ajoutées pour le commerçant](#4-routes-ajoutées-pour-le-commerçant)
5. [Espace agents et administrateurs (nouveau)](#5-espace-agents-et-administrateurs-nouveau)
6. [Axi Pro : précisions](#6-axi-pro--précisions)
7. [Codes d'erreur](#7-codes-derreur)
8. [Comptes de démonstration](#8-comptes-de-démonstration)

## 0. Adresse de l'API

```
# .env.local du FRONTEND (Expo) ; le backend, lui, n'utilise plus de .env
EXPO_PUBLIC_API_URL=http://192.168.100.175:8000
```

`localhost` ne marche que pour Expo web ou un émulateur iOS sur le même PC. Pour un émulateur Android,
utiliser `http://10.0.2.2:8000`.

## 1. Changements généraux

| Sujet | Contrat | Serveur |
|---|---|---|
| Connexion | code SMS, sans mot de passe (`/v1/auth/otp`, `/v1/auth/verify`) | **email et mot de passe**, adresse confirmée par un code à 6 chiffres (section 2) |
| Objet `user` | `id, name, phone, role, lang, organization` | ajoute `first_name`, `last_name`, `email`, `email_verified` (section 2.1) |
| `Session` | `{ token, is_new, user }` | `{ token, user }` : plus de `is_new`, le nom est demandé à l'inscription |
| Erreurs de saisie | — | `422 VALIDATION_ERROR` avec `error.fields` : `{ "email": "Cette adresse email ne semble pas valide." }` |
| Erreurs avec données | — | certaines erreurs ajoutent des clés à `error` : `remaining_attempts`, `retry_after`, `unavailable_product_ids` |
| Téléphones | exemples `+22997000000` | le Bénin est passé à 10 chiffres : le serveur **normalise** `97000000`, `0197000000` et `+22997000000` en `+2290197000000` |
| CORS (Expo web) | — | origines autorisées : `cors_origins` de `config/config.local.php` (`http://localhost:8081`, `http://localhost:19006`) ; en-têtes `Authorization`, `Content-Type`, `If-None-Match`, `Idempotency-Key` ; `ETag` et `Idempotent-Replayed` exposés |
| Identifiants supplémentaires | — | `usr_`, `org_`, `ref_`, `pt_`, `cmp_`, `spn_`, `off_`, `dos_`, `vis_` |

## 2. Authentification par email : remplace la section 10

Règles :

- Toute inscription crée un compte `client`. Le rôle n'est jamais modifiable par l'utilisateur.
- Mot de passe : 8 caractères minimum (72 maximum).
- Code : 6 chiffres, valable **15 minutes**, **5 essais**, seul le dernier code envoyé compte.
- Envoi d'un code : **1 par minute et 5 par heure** par adresse et par type de code.
- Connexion : **5 échecs par 15 minutes**, par email et par adresse IP.
- Jeton : 64 caractères hexadécimaux, valable **90 jours**, prolongé à chaque utilisation. On l'envoie ensuite en `Authorization: Bearer <token>`.
- `401 UNAUTHENTICATED` sur une route protégée : la session est fermée (jeton absent, expiré ou révoqué).
- Les réponses ne révèlent jamais si une adresse a un compte. Exceptions : `EMAIL_TAKEN` à l'inscription, et `EMAIL_NOT_VERIFIED` après un mot de passe correct.

Parcours conseillé :

```
Inscription ──► écran « Code reçu par email » ──► verify-email ──► connecté
Connexion ──► 403 EMAIL_NOT_VERIFIED ──► même écran « Code » (un code vient d'être envoyé)
Mot de passe oublié ──► forgot-password ──► écran « Code + nouveau mot de passe » ──► reset-password ──► connecté
```

### 2.1 Objet `user` (renvoyé partout)

```json
{
  "id": "usr_1",
  "first_name": "Afi",
  "last_name": "Houngbo",
  "name": "Afi Houngbo",
  "email": "afi@example.com",
  "email_verified": true,
  "phone": "+2290197000000",
  "role": "client",
  "lang": "fr",
  "organization": null
}
```

`name` = prénom + nom, mis à jour automatiquement : c'est lui qu'on affiche.
`organization` vaut `{ "id": "org_1", "name": "ANaGeM", "type": "anagem" }` pour un compte `pro`.

Type TypeScript proposé (remplace `User`, `OtpRequest`, `OtpSent`, `OtpVerify`, `Session`, `ProfilePatch`) :

```ts
export type User = {
  id: string;
  first_name: string;
  last_name: string;
  name: string;
  email: string;
  email_verified: boolean;
  phone: string; // E.164, téléphone Mobile Money
  role: Role;
  lang: Lang;
  organization: { id: string; name: string; type: OrganizationType } | null;
};
export type RegisterInput = { first_name: string; last_name: string; email: string; password: string; phone: string };
export type Session = { token: string; user: User };
export type CodePurpose = 'verify_email' | 'reset_password';
export type ProfilePatch = Partial<Pick<User, 'first_name' | 'last_name' | 'phone' | 'lang'>>;
```

### 2.2 `POST /v1/auth/register`

```json
{ "first_name": "Afi", "last_name": "Houngbo", "email": "afi@example.com", "password": "motdepasse1", "phone": "0197000000" }
```

`201` :

```json
{
  "ok": true,
  "data": {
    "user": { "id": "usr_1", "first_name": "Afi", "last_name": "Houngbo", "name": "Afi Houngbo", "email": "afi@example.com", "email_verified": false, "phone": "+2290197000000", "role": "client", "lang": "fr", "organization": null },
    "message": "Un code a été envoyé à votre adresse email."
  }
}
```

Aucun jeton n'est renvoyé : l'utilisateur doit d'abord confirmer son adresse.

Erreurs :

```json
{ "ok": false, "error": { "code": "VALIDATION_ERROR", "message": "Mot de passe : 8 caractères minimum.", "fields": { "password": "8 caractères minimum." } } }
```

- `409 EMAIL_TAKEN` : « Un compte existe déjà avec cette adresse email. Connectez-vous ou choisissez « Mot de passe oublié ». »
- `409 PHONE_TAKEN` : « Ce numéro de téléphone est déjà utilisé par un autre compte. »

### 2.3 `POST /v1/auth/verify-email`

```json
{ "email": "afi@example.com", "code": "563726", "device": "android Pixel 7" }
```

`200` (`Session`) :

```json
{ "ok": true, "data": { "token": "9876a4fe23deea2d80c30d5b4e0036af6c3f17314399fe2fbb603aa2b779c242", "user": { "...": "objet user, email_verified: true" } } }
```

Erreurs :

```json
{ "ok": false, "error": { "code": "CODE_INVALID", "message": "Code incorrect. Il vous reste 4 essais.", "remaining_attempts": 4 } }
```

- `422 CODE_EXPIRED` : « Ce code a expiré. Demandez un nouveau code. »
- `429 TOO_MANY_ATTEMPTS` : « Trop d'essais avec ce code. Demandez un nouveau code. »

### 2.4 `POST /v1/auth/resend-code`

```json
{ "email": "afi@example.com", "purpose": "verify_email" }
```

`purpose` vaut `verify_email` ou `reset_password`. La réponse est toujours la même, que l'adresse existe ou non :

```json
{ "ok": true, "data": { "message": "Si un compte correspond à cette adresse, un code vient d'y être envoyé." } }
```

Trop tôt : le bouton « Renvoyer » peut afficher le compte à rebours `retry_after` (en secondes).

```json
{ "ok": false, "error": { "code": "TOO_MANY_REQUESTS", "message": "Patientez 60 secondes avant de demander un nouveau code.", "retry_after": 60 } }
```

### 2.5 `POST /v1/auth/login`

```json
{ "email": "afi@example.com", "password": "motdepasse1", "device": "android Pixel 7" }
```

`200` : `Session` (`{ token, user }`), comme en 2.3. Erreurs :

| HTTP | `code` | `message` |
|---|---|---|
| 401 | `INVALID_CREDENTIALS` | Email ou mot de passe incorrect. |
| 403 | `EMAIL_NOT_VERIFIED` | Confirmez d'abord votre adresse email : un code vient de vous être envoyé. |
| 403 | `ACCOUNT_SUSPENDED` | Votre compte est suspendu. Contactez l'équipe Axi. |
| 429 | `TOO_MANY_REQUESTS` | Trop de tentatives de connexion. Réessayez dans 15 minutes. (`retry_after`) |

⚠️ `client.ts` appelle `onUnauthorized` sur tout 401 **si un jeton est en mémoire**. Sur l'écran de connexion,
il n'y a pas de jeton, donc pas de problème. Mais il faut bien vider le jeton avant d'afficher cet écran.

### 2.6 `POST /v1/auth/forgot-password`

```json
{ "email": "afi@example.com" }
```

Réponse toujours `200`, avec le même message, même si l'adresse est inconnue ou si la limite est atteinte :

```json
{ "ok": true, "data": { "message": "Si un compte correspond à cette adresse, un code vient d'y être envoyé." } }
```

### 2.7 `POST /v1/auth/reset-password`

```json
{ "email": "afi@example.com", "code": "481920", "password": "nouveau-mdp-2", "device": "android Pixel 7" }
```

`200` : `Session`. **Toutes les autres sessions de l'utilisateur sont fermées.** L'adresse est aussi
considérée comme confirmée. Erreurs : les mêmes qu'en 2.3, plus `VALIDATION_ERROR` sur `password`.

### 2.8 `POST /v1/auth/change-password` (connecté)

```json
{ "current_password": "nouveau-mdp-2", "new_password": "encore-un-3" }
```

```json
{ "ok": true, "data": { "changed": true } }
```

Les autres appareils sont déconnectés, la session en cours reste ouverte. Mauvais mot de passe actuel
(**422, pas 401**, pour ne pas fermer la session) :

```json
{ "ok": false, "error": { "code": "VALIDATION_ERROR", "message": "Mot de passe actuel incorrect.", "fields": { "current_password": "Mot de passe actuel incorrect." } } }
```

### 2.9 `GET /v1/me` et `PATCH /v1/me`

`GET` renvoie l'objet `user`. `PATCH` accepte n'importe quel sous-ensemble de
`{ "first_name", "last_name", "phone", "lang" }` :

```json
{ "first_name": "Afiwa", "lang": "fon" }
```

```json
{ "ok": true, "data": { "id": "usr_1", "first_name": "Afiwa", "last_name": "Houngbo", "name": "Afiwa Houngbo", "email": "afi@example.com", "email_verified": true, "phone": "+2290197000000", "role": "client", "lang": "fon", "organization": null } }
```

Erreurs : `409 PHONE_TAKEN`, `422 VALIDATION_ERROR`. L'email ne se change pas pour l'instant.

### 2.10 `POST /v1/auth/logout`

Sans corps. Réponse : `{ "logged_out": true }`. Le jeton est révoqué.

### 2.11 Routes supprimées

`POST /v1/auth/otp` et `POST /v1/auth/verify` n'existent pas côté serveur : il faut les retirer de `endpoints.ts` et de `mocks/auth.ts`.

## 3. Écarts sur les routes existantes du contrat

### `GET /v1/stalls/{id}` et `StallDetail`

- `owner_display_name` = `user.name` du commerçant, par exemple « Afi Houngbo ».
- `avatar` vaut toujours `null`. L'envoi d'une photo d'étal n'existe pas encore.
- `has_new_products` : un produit a été ajouté dans les 3 derniers jours.
- Un étal sans place fixe a un `location.label` du type « Tokpa Dahô · sans emplacement fixe ».

### `GET /v1/merchant/today`

- `relocation.title` ne dépend pas du genre (le profil ne le précise pas) : « Votre étal est maintenant à Tokpa Yôyô ».
- `relocation` est présent pendant 90 jours après un déménagement.
- `greeting_name` = `user.name`.

### `POST /v1/search/voice`

Pas encore de transcription : `503 VOICE_SEARCH_UNAVAILABLE`, « La recherche vocale arrive bientôt. Tapez votre recherche pour l'instant. »

### `POST /v1/orders` : paiement sans double débit (IMPORTANT)

**Envoyer l'en-tête `Idempotency-Key`**, une clé au hasard (8 à 64 caractères `A-Z a-z 0-9 - _`).
Elle est générée **une fois par tentative de paiement**, gardée tant que la commande n'est pas
confirmée, et renvoyée à l'identique à chaque nouvel essai : coupure réseau, délai dépassé, double appui,
application relancée. Le serveur renvoie alors la **même** commande, et le client n'est **jamais** débité deux fois.

```ts
// panier.tsx : une clé par tentative ; on la garde jusqu'au succès (et dans AsyncStorage si l'appli peut être tuée)
const keyRef = useRef<string | null>(null);
const pay = () => {
  keyRef.current ??= `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
  createOrder.mutate({ body, idempotencyKey: keyRef.current }, { onSuccess: () => { keyRef.current = null; } });
};
// endpoints.ts : apiSend doit accepter des en-têtes supplémentaires
createOrder: (body, key) => apiSend<Order>('POST', '/v1/orders', body, { 'Idempotency-Key': key }),
```

La clé peut aussi être envoyée dans le corps (`"idempotency_key": "…"`). Le serveur répond ainsi :

| Cas | Réponse |
|---|---|
| Première fois | `201`, la commande en `awaiting_payment` |
| Même clé, même panier (nouvel essai) | `200`, **la même commande**, en-tête `Idempotent-Replayed: true` |
| Même clé, panier différent | `422 IDEMPOTENCY_KEY_REUSED` : générer une nouvelle clé |
| Sans clé, même panier en moins de 15 min | `200`, la commande déjà créée (protection contre le double appui) |
| Deux envois exactement simultanés | un seul crée la commande, l'autre attend puis reçoit la même (`409 REQUEST_IN_PROGRESS` après 15 s d'attente) |
| Prestataire injoignable (coupure) | `201`, la commande est gardée et rien n'est débité. `payment_instructions` vaut « La demande de paiement n'a pas pu partir. Réessayez : vous ne serez débité qu'une seule fois. » : renvoyer la même requête avec la même clé |

Conseil : désactiver le bouton « Payer » pendant `createOrder.isPending`.

Autres règles :
- `reception.detail` pour `axi_point` = l'identifiant `pt_1` reçu dans `axi_points` du devis.
- `422 PRODUCT_UNAVAILABLE` ajoute `error.unavailable_product_ids`.
- En local, le prestataire est **factice** : la commande passe à `paid` dès le premier `GET /v1/orders/{id}`. Sans prestataire configuré : `503 PAYMENT_UNAVAILABLE`.
- `GET /v1/orders/{id}` d'une commande d'un autre client : `404 ORDER_NOT_FOUND`.

### `POST /v1/orders/{id}/confirm-receipt` (nouveau, client connecté)

Bouton « J'ai reçu ma commande » sur le suivi de commande. L'argent gardé par Axi est alors libéré aux commerçants.
Si le client ne confirme pas, l'argent est libéré automatiquement 7 jours après la remise au coursier.
Sans corps. Réponse : l'objet `Order` avec `status: "delivered"` (« Commande reçue »). Un nouvel appel ne libère rien de plus.
Commande non payée : `409 ORDER_NOT_PAID`.

### `POST /v1/merchant/products` (multipart)

Champs du contrat (`photo`, `name`, `price`, `is_today`), plus deux champs facultatifs : `price_max` (fourchette) et `in_stock`.
La photo est facultative (un agent peut saisir un produit sans photo).
WebP, JPEG ou PNG, 8 Mo maximum. Le serveur produit `thumb` (200 px) et `medium` (600 px) en WebP, à des URL immuables.

### `PATCH /v1/merchant/products/{id}`

En plus de `is_today` et `in_stock`, accepte `name`, `price` (entier, ou `null` pour « Prix à débattre ») et `price_max` :
le commerçant gère lui-même ses prix.

```json
{ "in_stock": false, "price": 19000 }
```

```json
{ "ok": true, "data": { "id": "prd_1", "name": "Wax hollandais, 6 yards", "image": null, "price": { "amount": 19000, "amount_max": null, "currency": "XOF" }, "in_stock": false, "is_today": true, "updated_at": "2026-10-29T11:17:30Z" } }
```

### `GET /v1/merchant/money`

- `data_sharing.partner_name` et le texte de `credit` portent le nom de l'IMF partenaire enregistrée.
- Sans IMF partenaire, `credit.can_request` vaut `false`, et `PUT /data-sharing` ou `POST /credit-requests` répondent `503 NO_CREDIT_PARTNER`.
- Une 2ᵉ demande de crédit pendant l'étude de la 1ʳᵉ : `409 CREDIT_REQUEST_PENDING`.

### `GET /v1/merchant/clients`

- `badge.label` : « Fidèle » (3 commandes ou plus), « Nouveau » (premier achat ou ajout depuis moins de 7 jours), « Relancer » (dernier achat il y a plus de 30 jours).
- `description` : « Revendeur · dernier achat hier », « Particulier · ajouté aujourd'hui ».
- `POST /v1/merchant/clients` avec un numéro déjà présent : `409 CLIENT_EXISTS`.

### Messages groupés (`/relocation/broadcast`, `/arrivals`, `/broadcasts`, `/clients/{id}/follow-up`)

La réponse `BroadcastResult` est conforme. Les messages sont enregistrés, mais **l'envoi réel (SMS, WhatsApp, notification) n'est pas encore branché**.

### `POST /v1/agent-visits`

Conforme. Un compte déjà commerçant reçoit `409 ALREADY_MERCHANT`.

## 4. Routes ajoutées pour le commerçant

### `PATCH /v1/merchant/stall`

Le commerçant modifie sa vitrine. L'emplacement (site, rangée, stand) ne change que par un agent :
ces champs sont ignorés ici. Champs acceptés : `tagline`, `phone`, `whatsapp`, `accepts_voice_messages`,
`pay_on_delivery`, `delivery_note`, `is_open`. Une chaîne vide efface `whatsapp` ou `delivery_note`.

```json
{ "is_open": false, "delivery_note": "Livraison Cotonou" }
```

```json
{ "ok": true, "data": { "stall": { "...StallDetail..." } } }
```

### `DELETE /v1/merchant/products/{id}`

```json
{ "ok": true, "data": { "deleted": true } }
```

## 5. Espace agents et administrateurs (nouveau)

Rôles `agent` et `admin`. Toute autre personne reçoit `403 FORBIDDEN`. **Chaque action est journalisée** (table `admin_actions`).
Logique : un client ne devient commerçant que par un agent ou un admin, après vérification de l'étal sur place.
L'agent peut saisir les premiers produits. Ensuite, le commerçant gère seul ses produits et ses prix.

### `GET /v1/admin/users?search=sena`

Recherche par email, téléphone ou nom (2 caractères minimum), 20 résultats maximum.

```json
{ "ok": true, "data": { "users": [ {
  "id": "usr_6", "first_name": "Séna", "last_name": "Hounsou", "name": "Séna Hounsou", "email": "sena@example.com",
  "email_verified": true, "phone": "+2290196000010", "role": "client", "lang": "fr", "organization": null,
  "status": "active", "stall_id": null, "created_at": "2026-10-29T11:14:41Z"
} ] } }
```

### `GET /v1/admin/agent-visits`

Demandes « Demander la visite d'un agent » encore ouvertes.

```json
{ "ok": true, "data": { "visits": [ { "id": "vis_1", "user_id": "usr_8", "name": "Divine Akakpo", "phone": "+2290196000012", "email": "divine@example.com", "status": "new", "created_at": "2026-10-29T11:14:41Z" } ] } }
```

### `POST /v1/admin/merchants`

Passe le compte en `merchant` et crée l'étal vérifié par l'agent connecté. La demande de visite est close.

```json
{
  "user_id": "usr_8",
  "stall": {
    "name": "Beauté Divine", "tagline": "Karité, savons et parfums",
    "site_id": "yoyo", "aisle_id": "cosmetiques", "row": "Allée D", "stand": "102",
    "has_physical_place": true, "phone": "0196000012", "whatsapp": "",
    "merchant_type": "retail", "pay_on_delivery": true, "delivery_note": null, "map_url": null
  }
}
```

Obligatoires : `name`, `site_id`, `aisle_id`. `merchant_type` vaut `retail` (détaillant, par défaut) ou `wholesale` (grossiste).
`phone` et `whatsapp` reprennent le téléphone du compte s'ils sont absents (une chaîne vide donne `null`).

`201` : objet « commerçant », aussi renvoyé par `GET` et `PATCH` :

```json
{ "ok": true, "data": {
  "user": { "...objet user...": "", "role": "merchant", "status": "active" },
  "stall": { "...StallDetail...": "", "status": "active", "merchant_type": "retail", "site_id": "yoyo", "has_physical_place": true },
  "products": [ { "...Product..." } ]
} }
```

Erreurs : `404 USER_NOT_FOUND`, `409 ALREADY_MERCHANT`, `409 ROLE_CONFLICT` (compte `pro`, `agent`…),
`409 ACCOUNT_SUSPENDED`, `422 VALIDATION_ERROR` (par exemple `fields.site_id` : « Site inconnu. »).

### `GET /v1/admin/merchants?site_id=yoyo&aisle_id=tissus&search=afi&cursor=`

Tous les filtres sont facultatifs. `search` porte sur le nom de l'étal ou du commerçant, l'email, le téléphone et le stand.

```json
{ "ok": true, "data": { "merchants": [ { "user": { "...": "" }, "stall": { "...StallDetail..." } } ], "next_cursor": null } }
```

### `GET /v1/admin/merchants/{id}`

`{id}` accepte l'étal (`stl_2`) ou le compte du commerçant (`usr_10`). Renvoie l'objet « commerçant », produits compris, même si l'étal est fermé.

### `PATCH /v1/admin/merchants/{id}`

```json
{ "stall": { "stand": "104" } }
```

Mêmes champs que la création, tous facultatifs. Changer `site_id`, `row` ou `stand` vaut **déménagement** :
`moved_at` est mis à jour et l'encart « nouvelle adresse » réapparaît chez le commerçant.
Toute modification par un agent renouvelle la vérification.

`{ "status": "suspended" | "active" | "closed" }` est **réservé aux admins** (`403 FORBIDDEN` pour un agent).
`suspended` bloque le compte, `closed` ferme l'étal.

### `POST /v1/admin/stalls/{id}/products` (multipart)

Même format que `POST /v1/merchant/products`. `{id}` = `stl_…`. Réponse `201` : le `Product`.

### `POST /v1/admin/orders/{id}/pickup`

L'agent ou le coursier a récupéré la part d'un étal (collecte dans les allées). Corps : `{ "stall_id": "stl_1" }`.
Sans `stall_id`, toutes les parts « prêtes » de la commande sont marquées récupérées.
Quand toutes les parts sont récupérées, la commande passe à `on_the_way`.

```json
{ "ok": true, "data": { "status": "on_the_way", "picked_up": 1 } }
```

## 6. Axi Pro : précisions

- **Seuil d'anonymat : 5 étals.**
- `GET /v1/pro/overview` ajoute `insufficient_data: boolean`. Quand il vaut `true` (moins de 5 étals sur le site choisi), les `kpis` valent `0`, sauf `sites_count`. Conseil : afficher « Données insuffisantes ».
- `outside_cotonou_pct` = part des commandes du mois expédiées par car.
- `median_prices` vient du calcul nocturne (`cron/run.php`) ; une ligne apparaît dès qu'un étal a un produit rattaché au référentiel.
- `GET /v1/pro/census` : les sites ou allées de moins de 5 étals sont regroupés dans « Autres (groupes de moins de 5 étals) », ou masqués si le groupe reste trop petit.
- `GET /v1/pro/campaigns` : `audiences[].id` est du type `aud_m_vivriers_daho` (commerçants d'une allée sur un site) ou `aud_c_cosmetiques` (clients qui suivent des étals de l'allée). Aucun public de moins de 5 personnes n'est proposé.
- `POST /v1/pro/campaigns` accepte en plus `title` et `text` facultatifs (texte de l'annonce). Un admin sans organisation reçoit `422 ORGANIZATION_REQUIRED`.
- `GET /v1/pro/financing` : `repayment_rate_pct` vaut `null` (les remboursements ne sont pas encore suivis). Un dossier `dos_…` existe dès que le commerçant a coché « Partager mon historique », même sans demande de crédit (statut `new`).
- Organisation non autorisée : `403 FORBIDDEN`, « Cet espace n'est pas ouvert à votre organisation. »

## 7. Codes d'erreur

| HTTP | `code` | Où |
|---|---|---|
| 400 | `INVALID_JSON` | corps JSON mal formé |
| 401 | `UNAUTHENTICATED` | jeton absent, expiré ou révoqué |
| 401 | `INVALID_CREDENTIALS` | connexion |
| 403 | `FORBIDDEN` | rôle ou organisation insuffisant |
| 403 | `EMAIL_NOT_VERIFIED`, `ACCOUNT_SUSPENDED` | connexion |
| 404 | `NOT_FOUND`, `STALL_NOT_FOUND`, `AISLE_NOT_FOUND`, `PRODUCT_NOT_FOUND`, `ORDER_NOT_FOUND`, `CLIENT_NOT_FOUND`, `USER_NOT_FOUND`, `MERCHANT_NOT_FOUND` | |
| 409 | `EMAIL_TAKEN`, `PHONE_TAKEN`, `CLIENT_EXISTS`, `ALREADY_MERCHANT`, `ROLE_CONFLICT`, `CREDIT_REQUEST_PENDING`, `ORDER_NOT_PREPARABLE`, `ORDER_NOT_PAID`, `REQUEST_IN_PROGRESS` | |
| 413 | `FILE_TOO_LARGE` | photo > 8 Mo, audio > 3 Mo |
| 422 | `VALIDATION_ERROR` (+ `fields`), `CODE_INVALID` (+ `remaining_attempts`), `CODE_EXPIRED`, `INVALID_PHONE`, `RECEPTION_DETAIL_REQUIRED`, `PRODUCT_UNAVAILABLE` (+ `unavailable_product_ids`), `DATA_SHARING_REQUIRED`, `INVALID_AUDIENCE`, `BUDGET_TOO_LOW`, `INVALID_PHOTO`, `INVALID_AUDIO`, `ORGANIZATION_REQUIRED`, `IDEMPOTENCY_KEY_REUSED` | |
| 429 | `TOO_MANY_REQUESTS` (+ `retry_after`), `TOO_MANY_ATTEMPTS` | |
| 500 | `SERVER_ERROR` | |
| 503 | `PAYMENT_UNAVAILABLE`, `VOICE_SEARCH_UNAVAILABLE`, `NO_CREDIT_PARTNER` | |

## 8. Comptes de démonstration

Créés par `php database/seed.php`, en local seulement. Mot de passe de tous les comptes : **`axi-demo-2026`**.
Les adresses `@example.com` ne reçoivent jamais d'email : les codes sont dans `storage/logs/codes.log` du backend.

| Email | Profil |
|---|---|
| `sena@example.com` | cliente (suit 2 étals, a une commande en cours) |
| `afi@example.com` | commerçante, « Chez Maman Afi » (`stl_1`), déménagée à Tokpa Yôyô |
| `kossi@example.com` | grossiste, Tokpa Dahô |
| `divine@example.com` | cliente qui attend la visite d'un agent |
| `agent@example.com` | agent Axi |
| `admin@example.com` | administrateur |
| `anagem@example.com` | Axi Pro, organisation ANaGeM |
| `imf@example.com` | Axi Pro, IMF |
| `annonceur@example.com` | Axi Pro, annonceur |
