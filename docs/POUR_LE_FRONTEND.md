# Axi : API de l'application mobile

Référence des routes utilisées par l'application (clients et commerçants). Les partenaires (agents Axi,
ANaGeM, annonceurs, IMF) n'utilisent pas l'API : ils passent par l'espace web `/pro`.

## Conventions

| Règle | Valeur |
|---|---|
| Format | JSON UTF-8, clés en `snake_case` |
| Succès | `{ "ok": true, "data": …, "meta": { "server_time", "max_age_s" } }` |
| Erreur | `{ "ok": false, "error": { "code", "message", "fields"?, "retry_after"?, … } }` ; `message` s'affiche tel quel |
| Identifiants | préfixés : `usr_`, `stl_`, `prd_`, `cmd_`, `cli_`, `pt_`, `ntf_`, `ses_`, `cst_`, `vm_`, `vrs_` |
| Montants | entiers en FCFA |
| Dates | ISO 8601 UTC |
| Absent / vide | `null` / `[]` |
| Cache | `GET` : `ETag` + `If-None-Match` → `304` sans corps |
| Authentification | `Authorization: Bearer <jeton>` (64 caractères hexadécimaux, 90 jours glissants) |
| Rôles | `client`, `merchant` (application) ; `partner` (web uniquement : `403 PARTNER_USE_WEB` dans l'app) |

Erreurs communes : `401 UNAUTHENTICATED` (fermer la session), `403 FORBIDDEN`, `404 …_NOT_FOUND`,
`422 VALIDATION_ERROR` (+ `fields`), `429 TOO_MANY_REQUESTS` (+ `retry_after`), `503 PAYMENT_UNAVAILABLE`.

## Compte : email et mot de passe uniquement

Aucune connexion par téléphone ni par SMS. Le téléphone est un simple contact (Mobile Money, WhatsApp).

| Route | Corps | Réponse |
|---|---|---|
| `POST /v1/auth/register` | `first_name, last_name, email, password (8+), password_confirmation, phone?, lang?` | `201 { user, message }` + code envoyé par email ; **toujours client** |
| `POST /v1/auth/verify-email` | `email, code, device` | `{ token, user }` |
| `POST /v1/auth/resend-code` | `email, purpose: verify_email\|reset_password` | `{ message }` (1/min, 5/h) |
| `POST /v1/auth/login` | `email, password, device` | `{ token, user }` ; `403 EMAIL_NOT_VERIFIED` (code renvoyé), `403 PARTNER_USE_WEB` |
| `POST /v1/auth/forgot-password` | `email` | toujours `{ message }` |
| `POST /v1/auth/reset-password` | `email, code, password, device` | `{ token, user }` ; autres sessions fermées |
| `POST /v1/auth/change-password` | `current_password, new_password` | `{ changed: true }` ; autres appareils déconnectés |
| `POST /v1/auth/logout` · `POST /v1/auth/logout-all` | — | `{ logged_out: true }` |
| `GET /v1/me` · `PATCH /v1/me` | `first_name?, last_name?, phone? (null pour effacer), lang?, avatar_visibility? (everyone\|contacts\|nobody)` | `user` |
| `POST /v1/me/avatar` (multipart `photo`) · `DELETE /v1/me/avatar` | photo de profil facultative | `user` |
| `GET /v1/me/badges` | messages et notifications non lus, rôle, félicitations en attente (à interroger toutes les 15 s) | `{ unread_messages, unread_notifications, role, merchant_welcome }` |

```json
{ "id": "usr_2", "first_name": "Afi", "last_name": "Houngbo", "name": "Afi Houngbo", "email": "afi@exemple.bj",
  "email_verified": true, "phone": null, "role": "merchant", "lang": "fr", "organization": null,
  "merchant": { "has_stall": true, "stall_id": "stl_1", "merchant_type": "retail", "verified": false } }
```

`user` contient aussi `avatar`, `avatar_visibility`, `merchant_application { id, status: pending|approved|rejected, reject_reason }`
(client) et `merchant_welcome` (félicitations à afficher une fois, puis `POST /v1/me/merchant-welcome/seen`).

## Devenir commerçant

Tout le monde s'inscrit client. `POST /v1/me/merchant-application` (multipart) : `phone, id_type (cni|cip|passport|other),
id_number, stall_name, merchant_type, site_id | site_other, aisle_id | aisle_other, row?, stand?` + fichiers `id_front, id_back?,
selfie, stall_photo`. `409 APPLICATION_PENDING` si une demande attend déjà. Un agent Axi accepte ou refuse dans l'espace web ;
à l'acceptation : rôle `merchant`, étal créé et vérifié, email. `GET /v1/me/merchant-application` : dernière demande.
`GET /v1/merchant/stall-options` (tout compte connecté) : listes + `other_site_id` (`autre`) et `other_aisle_id` (`autres`).

## Messagerie client ↔ commerçant

| Route | Rôle |
|---|---|
| `GET /v1/conversations` | mes conversations (`my_role`, `other`, `kept`, `retention_note`, `unread_count`, `last_message`) |
| `POST /v1/stalls/{stl_…}/conversation` · `POST /v1/merchant/clients/{cli_…}/conversation` | ouvrir ou retrouver une conversation |
| `GET /v1/conversations/{conv_…}/messages?after=msg_…` | messages visibles (marque comme lu) ; ETag : 304 si rien de neuf |
| `POST /v1/conversations/{conv_…}/messages` | JSON `{ body }` ou multipart `photo` / `audio` ; 20 par minute au plus |
| `PATCH /v1/conversations/{conv_…}/messages/{msg_…}` `{ body }` · `DELETE …` | modifier (texte) ou supprimer pour tout le monde SON message |

Mes messages : `status` `sent` (✓), `delivered` (✓✓ : application de l'autre ouverte), `read` (✓✓ bleu) ; `edited`, `deleted`.
Conversation : `other_online` (application ouverte il y a moins d'une minute), `other_last_seen_at`.

Messages effacés 24 h après l'envoi (`message_ttl_hours`), sauf si le client suit l'étal ; s'il arrête, effacement 24 h plus tard.

## Paramètres et vie privée (enregistrés en base)

| Route | Rôle |
|---|---|
| `GET /v1/me/settings` · `PATCH /v1/me/settings` | `theme (system\|light\|dark), date_format, notifications {type: {app, email}}, default_address, preferred_point_id, preferred_operator, preferred_payment_phone, data_cap_mb` ; la réponse contient aussi `axi_points` et `data { cap_mb, used_bytes, used_pct }` |
| `POST /v1/me/data-usage` | `{ bytes }` : consommation mesurée par l'application |
| `GET /v1/me/sessions` · `DELETE /v1/me/sessions/{ses_…}` | appareils connectés |
| `GET /v1/me/login-history` | 30 dernières connexions (réussies et refusées) |
| `GET /v1/me/consents` · `DELETE /v1/me/consents/{cst_…}` | consentements IMF (date, périmètre, révocation) |
| `POST /v1/me/export` | `{ url }` : lien signé 10 min vers le JSON de mes données |
| `POST /v1/me/delete` | `{ password }` : données personnelles effacées ; `409 OPEN_ORDERS` si commande en cours |
| `GET /v1/notifications` · `POST /v1/notifications/read` | `{ ids: [...] }` ou `{ all: true }` |
| `GET /v1/favorites` · `POST/DELETE /v1/favorites/{prd_…}` · `GET /v1/me/recently-viewed` | favoris et produits vus |

Types de notifications : `order, payment, reminder, promotion, arrival, account` (dans l'app et/ou par email).

## Catalogue (visite libre)

| Route | Réponse |
|---|---|
| `GET /v1/home` | `city, market_notice, sponsored, aisles, followed_stalls, featured_stalls` |
| `GET /v1/aisles/{id}/stalls?cursor=` | `aisle, stalls, next_cursor` |
| `GET /v1/search?q=` | `query, stalls, products (+ stall)` |
| `GET /v1/stalls/{stl_…}` | `stall, products, products_updated_at, next_cursor` ; aucune publicité |
| `GET /v1/products/{prd_…}` | produit + `images[]` + `stall` + `is_favorite` ; enregistré dans « récemment vus » |
| `POST/DELETE /v1/stalls/{id}/follow` · `POST /v1/stalls/{id}/voice-messages` (multipart `audio`) | connecté |
| `POST /v1/promotions/{spn_…}/click` | compte un clic |

Chaque produit : `image` (1ʳᵉ photo ou `null`) et `images[]` (galerie, URL **absolues** WebP `thumb_url` ~200 px,
`medium_url` ~600 px, immuables). `price: null` = « Prix à débattre ».

## Commandes et paiement (FedaPay ou KkiaPay, séquestre Axi)

1. `POST /v1/orders/quote` `{ lines, reception: { mode: delivery|bus|axi_point, detail }, payment_mode?: online|on_site }` → devis
   calculé avec les montants **en base** : `total` (payé en ligne), `pay_on_site_available`, `service_fee`, `cash_total`.
   Sur place : le client paie en ligne réception + regroupement + frais de service (= commission), et la marchandise en espèces.
   Le stock des produits suivis est réservé à la commande et rendu si elle est annulée avant paiement.
2. `POST /v1/orders` (même corps + `payment: { operator: null, phone: "" }`), en-tête **`Idempotency-Key`**
   (même clé = même commande : jamais deux débits). Réponse : la commande avec `payment.payment_url`.
3. L'application ouvre `payment_url` dans une WebView. Le retour arrive sur `payment.return_url_prefix`
   (`…/v1/payments/return?ref=…`, avec `&transaction_id=` pour KkiaPay) : fermer la WebView puis
4. `POST /v1/orders/{id}/verify-payment` `{ transaction_id? }` : le **serveur** vérifie auprès de la passerelle.

| Route | Rôle |
|---|---|
| `GET /v1/orders` · `GET /v1/orders/{id}` | historique, suivi (statut revérifié tant que `awaiting_payment`) |
| `POST /v1/orders/{id}/cancel` | avant paiement seulement (`409 ORDER_NOT_CANCELLABLE` sinon) |
| `POST /v1/orders/{id}/confirm-receipt` | « J'ai bien reçu » : argent libéré aux commerçants |
| `POST /v1/orders/{id}/dispute` | `{ reason, stall_id? }` : argent bloqué jusqu'à la décision d'un agent |
| `GET /v1/sales?from&to&status&q` · `POST /v1/sales/export` | ventes et paiements (+ lien CSV signé) |

Commande : `status` (`awaiting_payment, paid, preparing, on_the_way, delivered, disputed, refunded, cancelled`),
`status_label`, montants, `reception`, `payment { reference, provider, provider_ref, status, payment_url, return_url_prefix }`,
`stalls[] { name, status_label, escrow_state, items[], phone, whatsapp }`, `can_cancel`, `can_confirm_receipt`, `can_dispute`, `dispute`.

## Commerçant

| Route | Rôle |
|---|---|
| `GET /v1/merchant/stall-options` | sites et allées actifs |
| `GET · PATCH /v1/merchant/stall` · `POST /v1/merchant/stall/photo` | vitrine ; changer site / rangée / stand retire le badge jusqu'à la visite d'un agent |
| `POST /v1/merchant/verification-request` | demander la visite d'un agent |
| `POST /v1/merchant/products` (multipart `photos[0..4]`, `name, price, price_max, is_today`) | produit en photo |
| `POST /v1/merchant/products/voice` (multipart `audio`, `photos[]?`) | brouillon dicté (`status: draft`, invisible des clients) |
| `PATCH /v1/merchant/products/{id}` | `name, price (null = à débattre), price_max, in_stock, stock_quantity (null = non suivi), is_today, status: active` (publier ou remettre en vente) |
| `POST /v1/merchant/products/{id}/photos` · `DELETE …/photos/{index}` · `DELETE /v1/merchant/products/{id}` | photos, suppression |
| `GET /v1/merchant/voice-messages` · `POST …/{vm_…}/listened` | messages vocaux des clients (lien audio signé) |
| `GET /v1/merchant/today` · `POST /v1/merchant/orders/{id}/ready` | Ma journée |
| `POST /v1/merchant/relocation/broadcast` · `POST /v1/merchant/arrivals` (multipart `audio`) | messages groupés |
| `GET /v1/merchant/clients?segment&q` · `POST` · `GET/PATCH/DELETE /v1/merchant/clients/{id}` · `POST …/follow-up` · `POST /v1/merchant/broadcasts` | fichier clients |
| `GET /v1/merchant/money` | `pending_amount, released_amount, paid_out_amount, subscription, supplier_offer, credit, data_sharing, payouts` |
| `POST /v1/merchant/subscription/pay` `{ period: month\|year }` · `POST /v1/merchant/subscription/verify` | abonnement via la passerelle (même parcours WebView) ; année = 12 mois − `subscription_yearly_discount` % |
| `POST /v1/merchant/credit-requests` (`amount?`) · `PUT /v1/merchant/data-sharing` (`enabled`) | crédit, consentement |

Messages groupés : réponse `{ recipients, notified, emailed, message, whatsapp_url, share_text }` : notification dans
l'app pour les clients qui ont un compte Axi, email pour les autres, lien WhatsApp à partager. **Aucun SMS.**

## Sans abonnement

`402 SUBSCRIPTION_REQUIRED` (message affiché tel quel) : plus de `free_product_limit` produits en vente (5), messages groupés
(relance, nouvelle adresse, arrivage, envoi à un segment). Les produits au-delà de la limite passent en `status: paused`
(masqués, pas supprimés) et reviennent en vente au paiement de l'abonnement. `subscription.limits` et `subscription.benefits`
décrivent ce qui est limité et ce que l'abonnement débloque.
