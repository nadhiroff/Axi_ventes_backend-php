# Axi : contrat d'API v1

API REST JSON consommée par l'application React Native (client et commerçant) et par l'espace web Axi Pro.

## Conventions

- **Base** : `https://<hôte>/v1` (en local : `http://localhost:8000/v1` ou `http://localhost/Axi_ventes_backend-php/v1`).
- **Corps** : `Content-Type: application/json`, sauf envoi de fichiers en `multipart/form-data` (indiqué 📎).
- **Authentification** : `Authorization: Bearer <token>` (obtenu par `/auth/otp/verify`, valable 30 jours, prolongé à l'usage).
- **Réponses** : JSON minifié, compressé en gzip si le client envoie `Accept-Encoding: gzip`.
- **Montants** : entiers en FCFA. **Dates** : ISO 8601 en UTC (`2026-10-29T10:10:30Z`). « Aujourd'hui » suit l'heure du Bénin.
- **Pagination** : `?page=1&per_page=20` (maximum 50), renvoie `meta`.
- **Images/vocaux** : URL absolues dans `photo_url`, `audio_url`, `image_url` (ou `null`).

```json
{"ok":true,"data":{...}}
{"ok":true,"data":[...],"meta":{"page":1,"per_page":20,"total":134}}
{"ok":false,"error":{"code":"VALIDATION_FAILED","message":"Données invalides","fields":{"phone":"requis"}}}
```

### Accès

| Symbole | Signification |
|---|---|
| 🌐 | public |
| 🌐🔑 | public, mais reconnaît l'utilisateur s'il envoie son jeton (étals suivis, « je suis cet étal ») |
| 🔑 | tout utilisateur connecté |
| 🏪 | commerçant (`merchant`) |
| 🧭 | agent Axi (`agent`, `admin`) |
| 📊 | partenaire Axi Pro (`pro`, `admin`) |

### Codes d'erreur communs

| HTTP | code | Quand |
|---|---|---|
| 400 | `INVALID_JSON` | corps JSON illisible |
| 401 | `UNAUTHENTICATED` / `TOKEN_INVALID` | pas de jeton / jeton expiré ou révoqué → reconnecter |
| 403 | `FORBIDDEN` / `ACCOUNT_SUSPENDED` | mauvais profil / compte suspendu |
| 404 | `NOT_FOUND` / `STALL_NOT_FOUND` | ressource absente / le commerçant n'a pas encore d'étal |
| 405 | `METHOD_NOT_ALLOWED` | mauvaise méthode HTTP |
| 409 | `*_CONFLICT`, `*_EXISTS`, … | état incompatible (voir chaque route) |
| 413 / 415 | `FILE_TOO_LARGE` / `FILE_TYPE_NOT_ALLOWED` | fichier trop lourd / mauvais format |
| 422 | `VALIDATION_FAILED` | champs invalides, détail dans `fields` |
| 429 | `OTP_TOO_MANY` / `OTP_LOCKED` | trop de demandes de code |
| 503 | `PROVIDER_NOT_CONFIGURED` | moyen de paiement sans clés côté serveur |
| 500 | `SERVER_ERROR` | erreur interne |

### Objets réutilisés

**Étal (résumé)** : utilisé dans l'accueil, les allées, la recherche, les écrans commerçant.
```json
{"id":1,"name":"Chez Maman Afi","photo_url":null,
 "market":{"id":3,"name":"Tokpa Yôyô"},"aisle":{"id":1,"name":"Tissus et pagnes"},
 "location":{"row":"B","number":"12","label":"Tokpa Yôyô, rangée B, n°12"},
 "merchant_type":"retail","verified":true,"location_confirmed":false,"moved_at":"2026-10-27T10:09:50Z"}
```
- `verified` : badge « vérifié par un agent Axi ».
- `location_confirmed` : l'agent a vérifié l'emplacement **actuel** (faux si l'étal a déménagé depuis la visite).
- `moved_at` : afficher « nouvel emplacement » si récent.

**Produit**
```json
{"id":1,"name":"Pagne wax hollandais","description":"Motifs de la saison","unit":"6 yards",
 "price":18000,"price_max":25000,"wholesale_price":null,"wholesale_min_qty":null,
 "photo_url":null,"audio_url":null,"stock_status":"available","is_today":true,"catalog_item_id":1}
```
- `price` : prix ferme utilisé pour la commande en ligne. `price_max` : fourchette affichée (« 18 000 – 25 000 »).
- `wholesale_price` s'applique automatiquement dans le panier dès `wholesale_min_qty`.
- `stock_status` : `available` | `low` | `out` (un produit `out` ne peut pas être ajouté au panier).
- `is_today` : « produit du jour ».

---

## 1. Authentification

Connexion sans mot de passe : numéro de téléphone + code à 6 chiffres reçu par SMS.
Numéros acceptés : `97000000`, `0197000000`, `+229 01 97 00 00 00` → normalisés en `+2290197000000` ; autres pays au format `+indicatif`.

### POST /auth/otp/request 🌐
```json
{"phone":"0197000000"}
```
200 → `{"expires_in":300,"resend_in":60}` (+ `"debug_code":"482913"` **en local uniquement**)
Erreurs : 422, 429 `OTP_TOO_MANY` (`retry_in` en secondes, en-tête `Retry-After`).

### POST /auth/otp/verify 🌐
```json
{"phone":"0197000000","code":"482913","name":"Maman Afi","role":"merchant","lang":"fr"}
```
`name` obligatoire à la première inscription seulement. `role` : `client` (défaut) ou `merchant`. `lang` : `fr` | `fon` | `yo` | `en`.
200 (connexion) ou 201 (inscription) →
```json
{"token":"b888a9…(64)","expires_at":"2026-11-28T09:48:14Z","is_new":true,
 "user":{"id":1,"name":"Maman Afi","phone":"+2290197000000","role":"merchant","lang":"fr","org_name":null}}
```
Erreurs : 401 `OTP_INVALID`, 429 `OTP_LOCKED` (5 essais), 403 `ACCOUNT_SUSPENDED`, 422.

### GET /auth/me 🔑 → `{"user":{...}}`
### POST /auth/logout 🔑 → 204

---

## 2. Écran client « Accueil »

### GET /home?market_id= 🌐🔑
Tout l'écran en un seul appel.
```json
{"markets":[{"id":1,"slug":"dantokpa","name":"Dantokpa","city":"Cotonou"}],
 "aisles":[{"id":2,"slug":"vivriers","name":"Vivriers","icon":"basket","stalls_count":3}],
 "followed_stalls":[ /* Étal (résumé), les derniers déménagés en premier ; [] si anonyme */ ],
 "promotions":[{"id":1,"sponsored":true,"sponsor":"Savon Lumière","title":"…","body":"…","image_url":null,
                "target":{"type":"aisle","id":4,"url":null}}],
 "partner_notice":{"partner":"ANaGeM","text":"Service en ligne des marchés, en partenariat avec l'ANaGeM"}}
```
`partner_notice` vaut `null` tant que l'accord ANaGeM n'est pas activé (`anagem_partnership` dans la config).
`target.type` : `none` | `aisle` | `stall` | `product` | `url` → l'application ouvre l'écran correspondant.

### POST /promotions/{id}/click 🌐
Compte le clic sur une promotion et renvoie la promotion (avec sa cible).

### GET /markets 🌐 · GET /aisles?market_id= 🌐
Listes seules (mêmes objets que `/home`).

### GET /aisles/{id}/stalls 🌐
Filtres : `market_id`, `type` (`retail`|`wholesale`), `verified` (`1`), `q`, `page`. → liste d'Étals (résumé) + `meta`. Les étals vérifiés sont affichés en premier.

### GET /search?q=riz&market_id= 🌐
`q` de 2 à 60 caractères. → `{"stalls":[Étal…], "products":[Produit + "stall":{"id","name","market"}]}`

---

## 3. Écran client « Page d'un étal »

### GET /stalls/{id} 🌐🔑
```json
{"stall":{ /* Étal (résumé) */ "merchant_name":"Maman Afi","description":"…","contact_phone":"+229…","whatsapp":"+229…",
          "followers_count":1,"is_followed":true},
 "payment":{"escrow":true,"label":"Paiement gardé par Axi jusqu'à la réception","methods":["mobile_money"]},
 "last_move":{"at":"2026-10-27T10:09:50Z","location":"Tokpa Yôyô, rangée B, n°12","recipients":3},
 "today_products":[Produit…],
 "products":[Produit… (20 premiers)],
 "products_meta":{"page":1,"per_page":20,"total":3}}
```
Aucune publicité n'est affichée sur la page d'un étal.

### GET /stalls/{id}/products?today=1&page= 🌐
Suite du catalogue → liste de Produits + `meta`.

### POST /stalls/{id}/follow 🔑 · DELETE /stalls/{id}/follow 🔑
→ `{"is_followed":true,"followers_count":2}`

---

## 4. Écran client « Panier multi-étals »

Le panier est gardé côté serveur. Chaque action renvoie le **panier complet recalculé** :
```json
{"stalls":[
   {"stall":{"id":1,"name":"Chez Maman Afi","market":"Tokpa Yôyô","verified":true},
    "items":[{"product_id":1,"name":"Pagne wax hollandais","unit":"6 yards","photo_url":null,
              "unit_price":18000,"wholesale_applied":false,"quantity":2,"line_total":36000,"available":true}],
    "subtotal":36000},
   {"stall":{"id":2,"name":"Koffi Vivriers Gros","market":"Dantokpa","verified":true},
    "items":[{"product_id":4,"unit_price":16500,"wholesale_applied":true,"quantity":10,"line_total":165000,…}],
    "subtotal":165000}],
 "stalls_count":3,"items_count":13,"unavailable_product_ids":[],
 "subtotal":203500,
 "grouping_fee":500,"grouping_fee_per_extra_stall":250,
 "delivery":{"id":1,"mode":"home","label":"Livraison à domicile","city":"Cotonou","address":null,"fee":1000,"eta":"Aujourd'hui"},
 "delivery_fee":1000,
 "total":205000,
 "escrow":true}
```
- **Frais de regroupement** = (nombre d'étals − 1) × `grouping_fee_per_extra_stall` (250 FCFA, hypothèse réglable).
- `delivery` et `delivery_fee` valent `null` tant qu'aucun mode n'est choisi.
- Un produit devenu indisponible reste affiché (`available:false`, `line_total:0`) et bloque le paiement.

| Méthode | Route | Corps | Rôle |
|---|---|---|---|
| GET | `/cart?delivery_option_id=1` | — | voir le panier, avec frais de livraison si un mode est choisi |
| POST | `/cart/items` | `{"product_id":1,"quantity":2}` | ajouter (cumule ; `quantity` 1 par défaut) |
| PATCH | `/cart/items/{product_id}` | `{"quantity":3}` | changer la quantité (0 = retirer) |
| DELETE | `/cart/items/{product_id}` | — | retirer |
| DELETE | `/cart` | — | vider |

Accès 🔑. Erreur : 409 `PRODUCT_UNAVAILABLE` (`product_ids`).

### GET /delivery-options 🌐
```json
[{"id":1,"mode":"home","label":"Livraison à domicile","city":"Cotonou","address":null,"fee":1000,"eta":"Aujourd'hui"},
 {"id":3,"mode":"bus","label":"Expédition par car","city":"Parakou","address":"Gare routière de Parakou","fee":2500,"eta":"1 à 2 jours"},
 {"id":5,"mode":"pickup","label":"Point Axi Dantokpa","city":"Cotonou","address":"Entrée principale…","fee":0,"eta":"Dès demain"}]
```

### POST /checkout 🔑 : initialisation du paiement séquestre
```json
{"delivery_option_id":1,"provider":"fedapay","address":"Akpakpa, von de la pharmacie","contact_phone":"0196000001","note":"Appeler avant"}
```
- `provider` : `fedapay` | `kkiapay` (+ `simulation` en local).
- `address` obligatoire sauf pour `pickup`. `contact_phone` : numéro du compte par défaut.

201 →
```json
{"order":{ /* Commande (voir 5) */ "status":"pending_payment" },
 "payment":{"id":6,"purpose":"order","provider":"fedapay","amount":210000,"status":"pending","paid_at":null,
            "payment_url":"https://…fedapay…"}}
```
Selon le fournisseur, `payment` contient en plus :

| provider | Champ ajouté | Ce que fait l'application |
|---|---|---|
| `fedapay` | `payment_url` | ouvrir dans une WebView ; au retour sur `/v1/payments/fedapay/callback`, appeler `POST /payments/{id}/verify` |
| `kkiapay` | `kkiapay: {public_key, amount, reason, data, sandbox}` | ouvrir le widget du SDK React Native Kkiapay avec ces valeurs, puis `POST /payments/{id}/verify` avec `transaction_id` |
| `simulation` | `simulation: true` | appeler directement `POST /payments/{id}/verify` (local seulement) |

Erreurs : 422 `CART_EMPTY`, 409 `PRODUCT_UNAVAILABLE`, 503 `PROVIDER_NOT_CONFIGURED`, 502 `PAYMENT_INIT_FAILED` / `PAYMENT_PROVIDER_UNAVAILABLE` (la commande reste `pending_payment` : relancer avec `/orders/{id}/pay`).

---

## 5. Commandes et paiements (client)

### Cycle de vie
```
Commande : pending_payment → paid (argent gardé par Axi) → completed (reçue, argent libéré)
                          ↘ cancelled (non payée sous 24 h, ou tous les étals ont annulé)
Part d'un étal : pending_payment → new → confirmed → ready (remise à l'agent Axi) → received
                                     ↘ cancelled (remboursement ajouté à refund_due)
Paiement : pending → held (séquestre) → released ; ou failed ; abonnement : pending → succeeded
```
L'argent est libéré quand le client confirme la réception, ou automatiquement 7 jours après le paiement si toutes les parts sont `ready`.

### GET /orders?page= 🔑
`[{"id":6,"ref":"AX000006","status":"paid","total":210000,"created_at":"…","paid_at":"…","stalls_count":3}]` + `meta`

### GET /orders/{id} 🔑
```json
{"id":6,"ref":"AX000006","status":"paid","total":210000,"created_at":"…","paid_at":"…",
 "subtotal":208500,"grouping_fee":500,"delivery_fee":1000,"refund_due":0,
 "delivery":{"option":{…},"address":"Akpakpa","contact_phone":"+2290196000001"},
 "note":null,"received_at":null,
 "payment":{"id":6,"provider":"fedapay","status":"held","amount":210000},
 "stalls":[{"stall_order_id":10,"stall":{"id":1,"name":"Chez Maman Afi","photo_url":null,"location":"Tokpa Yôyô, rangée B, n°12"},
            "status":"ready","subtotal":36000,"items":[{"product_id":1,"name":"…","unit":"…","unit_price":18000,"quantity":2,"line_total":36000}]}]}
```

### POST /orders/{id}/pay 🔑
`{"provider":"kkiapay"}` → 201 `{"payment":{…}}`. Relance un paiement pour une commande `pending_payment`.

### POST /orders/{id}/confirm-receipt 🔑
Le client confirme la réception → la commande passe `completed` et l'argent est libéré aux commerçants. Renvoie la Commande.
Erreur : 409 `ORDER_STATUS_CONFLICT`.

### GET /payments/{id} 🔑
`{"id":6,"purpose":"order","provider":"fedapay","amount":210000,"status":"held","paid_at":"…"}`

### POST /payments/{id}/verify 🔑
`{"transaction_id":"…"}` (obligatoire pour Kkiapay, inutile pour FedaPay). Le serveur interroge le fournisseur et vérifie le montant. → Paiement.
Erreurs : 422 `TRANSACTION_ID_REQUIRED`, 409 `AMOUNT_MISMATCH` / `TRANSACTION_ALREADY_USED` / `TRANSACTION_MISMATCH`.

### Appels des fournisseurs (pas pour l'application)
| Route | Usage |
|---|---|
| `GET /payments/fedapay/callback?id=` 🌐 | URL de retour FedaPay ; vérifie et renvoie le Paiement |
| `POST /webhooks/fedapay` 🌐 | à déclarer dans le tableau de bord FedaPay |
| `POST /webhooks/kkiapay` 🌐 | à déclarer dans le tableau de bord Kkiapay |

Le serveur ne fait jamais confiance au contenu d'un webhook : il relit toujours la transaction chez le fournisseur.

---

## 6. Notifications (client et commerçant)

### GET /notifications?page= 🔑
```json
[{"id":6,"type":"stall_move","title":"Chez Maman Afi a déménagé","body":"…","data":{"stall_id":1},"read":false,"created_at":"…"}]
```
`meta.unread` = nombre de non lues. Types : `stall_move`, `stall_arrival`, `stall_reminder`, `order_new`, `order_cancelled`, `payment_released`, `stall_verified`.

### POST /notifications/read 🔑
`{"ids":[6,7]}` ou `{}` pour tout marquer comme lu → 204.

---

## 7. Écran commerçant « Ma journée »

### GET /merchant/day 🏪
```json
{"date":"2026-10-29",
 "stall":{ /* Étal (résumé) */ },
 "sales_today":{"orders_count":2,"amount":66000},
 "orders_to_handle_count":2,
 "orders_to_handle":[ /* Commande commerçant, voir ci-dessous */ ],
 "connection":{"active":true,"plan":"retail","amount":2000,"paid_by":"merchant","data_included":true,
               "starts_at":"…","ends_at":"…","days_left":20,"renew_price":2000},
 "products":{"total":3,"today":2},
 "followers_count":1,"customers_count":3,
 "last_move":{"at":"…","location":"Tokpa Yôyô, rangée B, n°12","recipients":3}}
```
`connection.data_included` = connexion zero-rating active (abonnement en cours).

**Commande commerçant**
```json
{"id":10,"ref":"AX000006","status":"new","subtotal":36000,"net_amount":36000,"paid_at":"…",
 "client":{"name":"Séna","phone":"+2290196000001"},"delivery":{"mode":"home","label":"Livraison à domicile"},
 "items":[{"product_id":1,"name":"Pagne wax hollandais","unit":"6 yards","unit_price":18000,"quantity":2,"line_total":36000}]}
```

### GET /merchant/orders?status=&page= 🏪
`status` : `new` | `confirmed` | `ready` | `received` | `cancelled`.

### PATCH /merchant/orders/{id} 🏪
`{"status":"confirmed"}` puis `{"status":"ready"}` (remise à l'agent Axi), ou `{"status":"cancelled"}` avant `ready`. → Commande commerçant.
Erreur : 409 `ORDER_STATUS_CONFLICT`.

### POST /merchant/stall/move 🏪 : « J'ai déménagé »
```json
{"market_id":3,"row_label":"B","stall_number":"12","message":"(facultatif, texte par défaut sinon)","notify":true}
```
Met à jour l'emplacement et envoie la nouvelle adresse à tous les clients du fichier et à tous les abonnés de l'étal : notification dans l'application pour les clients Axi, SMS pour les autres.
→ `{"stall":{…},"broadcast":{"id":3,"app_count":2,"sms_count":2}}`

### Produits : ajout en photo ou en vocal 🏪
| Méthode | Route | Rôle |
|---|---|---|
| GET | `/merchant/products?today=1&page=` | catalogue de l'étal |
| POST 📎 | `/merchant/products` | créer |
| POST 📎 | `/merchant/products/{id}` | modifier (POST car PHP ne lit les fichiers qu'en POST) |
| DELETE | `/merchant/products/{id}` | retirer de la vente (204) |

Champs (multipart ou JSON) : `name` (obligatoire sauf si un vocal est envoyé), `price` (obligatoire à la création), `price_max`, `unit`, `description`, `wholesale_price` + `wholesale_min_qty` (ensemble), `stock_status`, `catalog_item_id`, `is_today` (`true` par défaut à la création).
Fichiers : `photo` (jpg, png, webp ≤ 5 Mo), `audio` (mp3, webm, ogg, m4a, aac, wav ≤ 10 Mo).
→ Produit (201 à la création).

### Mon étal 🏪
- `GET /merchant/stall` → Étal + `description`, `contact_phone`, `whatsapp`, `place_status`, `status`, `verification_photo_url`, `followers_count`. 404 `STALL_NOT_FOUND` tant qu'il n'existe pas.
- `POST /merchant/stall` 📎 → crée l'étal (`name`, `market_id`, `aisle_id` obligatoires ; `row_label`, `stall_number`, `description`, `contact_phone`, `whatsapp`, `merchant_type`, `place_status` : `holder`|`subtenant`|`no_place`, fichier `photo`) ou modifie les champs envoyés. L'emplacement ne se change ensuite que par `/merchant/stall/move` (422 `USE_MOVE_ENDPOINT`).

---

## 8. Écran commerçant « Mes clients »

### GET /merchant/customers 🏪
Filtres : `type` (`retail` détaillants | `wholesale` grossistes), `segment` (`loyal` : 3 commandes et plus | `inactive` : rien depuis 30 jours | `new` : ajoutés depuis 30 jours), `q` (nom ou numéro), `page`.
```json
{"ok":true,"data":[{"id":3,"name":"Mama Bintou","phone":"+2290196100002","type":"wholesale","city":"Lomé",
  "note":"Revendeuse…","orders_count":4,"total_spent":720000,"last_order_at":"…","is_axi_user":false,"loyal":true}],
 "meta":{"page":1,"per_page":20,"total":1,"counts":{"total":3,"retail":2,"wholesale":1,"loyal":2}}}
```
Les clients qui commandent sur Axi sont ajoutés automatiquement au fichier.

| Méthode | Route | Corps |
|---|---|---|
| POST | `/merchant/customers` | `{"name","phone","type","city","note"}` → 201 (409 `CUSTOMER_EXISTS`) |
| PATCH | `/merchant/customers/{id}` | `{"name"?,"type"?,"city"?,"note"?}` |
| DELETE | `/merchant/customers/{id}` | → 204 |

### POST /merchant/broadcasts 🏪📎 : relance ou arrivage
```json
{"kind":"arrival","message":"Nouveaux wax arrivés !","product_id":1,"segment":"loyal"}
```
- `kind` : `arrival` (arrivage) | `reminder` (relance).
- Destinataires : `customer_ids` (liste choisie) **ou** les filtres `type` / `segment` (sans filtre = tous les clients).
- `message` facultatif si un fichier `audio` (vocal) est joint.

→ 201 `{"id":1,"app_count":1,"sms_count":1}`. Erreur : 422 `NO_RECIPIENTS`.

### GET /merchant/broadcasts 🏪
Historique : `[{"id","kind","message","audio_url","product_id","app_count","sms_count","created_at"}]`.

---

## 9. Écran commerçant « Mon argent »

### GET /merchant/money 🏪
```json
{"balance":{"to_receive":30000,"available":22000,"payouts_pending":50000,"paid_out":0},
 "movements":[{"type":"sale","ref":"AX000006","amount":36000,"status":"received","at":"…"},
              {"type":"payout","ref":null,"amount":50000,"status":"requested","at":"…"}],
 "subscription":{"active":true,"plan":"retail","amount":2000,"paid_by":"merchant","data_included":true,
                 "starts_at":"…","ends_at":"…","days_left":50,"renew_price":2000},
 "supplier_offers":[{"id":3,"partner":"MicroFi Bénin","supplier":"Wax Import Cotonou","title":"Crédit stock pagnes",
                     "description":"…","min_amount":100000,"max_amount":1500000,"max_months":6,"rate":"1,5 % par mois","sponsored":true}],
 "promotions":[ /* offres sponsorisées visant ce commerçant (campagnes Axi Pro) */ ],
 "credit":{"offers":[ /* offres de crédit en argent, même format */ ],
           "requests":[ /* 5 dernières demandes */ ],
           "shared_history":{"period_months":6,"months_on_axi":4,"orders_count":12,"sales_total":420000,"monthly_average":70000}}}
```
- `to_receive` : argent payé par les clients et gardé par Axi en attendant la réception.
- `available` : argent libéré, moins les retraits.
- `subscription.paid_by` : `anagem` si l'étal est inclus dans la place (`plan: anagem`).
- `shared_history` : exactement ce que verra le partenaire financier si le commerçant donne son accord.

| Méthode | Route | Corps | Réponse |
|---|---|---|---|
| POST | `/merchant/payouts` | `{"amount":50000,"phone"?}` (500 FCFA minimum) | 201 `{"payout":{…},"balance":{…}}` ; 422 `INSUFFICIENT_BALANCE` (`available`) |
| GET | `/merchant/payouts` | — | `[{"id","amount","phone","status":"requested|paid|rejected","created_at","processed_at"}]` |
| POST | `/merchant/subscription/renew` | `{"provider":"kkiapay"}` | 201 `{"payment":{…}}`, même suite que pour le checkout |
| POST | `/merchant/credit-requests` | `{"offer_id"?,"amount","duration_months","purpose"?,"share_history":true}` | 201 demande ; 422 `CONSENT_REQUIRED` / `OUTSIDE_OFFER_LIMITS` ; 409 `CREDIT_REQUEST_OPEN` |
| GET | `/merchant/credit-requests` | — | `[{"id","offer","amount","duration_months","purpose","status","approved_amount","consent_at","created_at"}]` |

Un abonnement payé s'ajoute à la fin de la période en cours (aucun jour perdu).

---

## 10. Agents Axi 🧭

- `GET /agent/stalls?market_id=&todo=1&page=` : étals à visiter (jamais vérifiés ou déménagés depuis la dernière visite). `todo=0` liste tous les étals.
- `POST /agent/stalls/{id}/verify` 📎 : fichier `photo` obligatoire (l'étal à son emplacement réel). Pose le badge « vérifié » et prévient le commerçant. → Étal.

---

## 11. Espace Axi Pro 📊

Données **agrégées et anonymes uniquement** : aucun nom, numéro ni identifiant d'étal. Tout groupe de moins de 3 étals (`min_group_size`) est masqué et compté dans `suppressed_groups`.

### GET /pro/census?market_id= : recensement des étals (ANaGeM)
```json
{"totals":{"stalls":5,"verified":4,"place":{"holder":3,"subtenant":1,"no_place":1},"type":{"retail":3,"wholesale":2}},
 "by_market":[{"id":1,"name":"Dantokpa","stalls":4,"verified":3,"place":{…},"type":{…}}],
 "by_aisle":[{"id":2,"name":"Vivriers","stalls":3,…}]}
```

### GET /pro/prices?market_id=&aisle_id=&catalog_item_id= : prix médians par produit et par site
```json
{"items":[{"item":{"id":2,"name":"Riz parfumé","unit":"sac de 25 kg"},"market":{"id":1,"name":"Dantokpa"},
           "median":18000,"min":17500,"max":18500,"stalls":3}],
 "suppressed_groups":2,"min_group_size":3}
```
Seuls les produits rattachés au référentiel (`catalog_item_id`) sont comparés. Le référentiel s'obtient avec `GET /pro/catalog-items`.

### GET /pro/trends?weeks=8&market_id= : tendances des ventes
```json
{"items":[{"week_start":"2026-10-26","market":{"id":1,"name":"Dantokpa"},"aisle":{"id":2,"name":"Vivriers"},
           "orders":3,"amount":462500,"stalls":3}],
 "suppressed_groups":4,"min_group_size":3,"weeks":4}
```

### GET /pro/credits : crédits accordés
```json
{"items":[{"market":{…},"aisle":{…},"requests":3,"approved":2,"approved_amount":1100000,"repaid":0,"stalls":3}],
 "suppressed_groups":1,"min_group_size":3}
```

### Campagnes ciblées
- `GET /pro/campaigns` : campagnes du partenaire, avec `stats: {impressions, clicks}`.
- `POST /pro/campaigns` 📎 :
```json
{"sponsor_name":"Ciment Bénin","title":"Ciment au prix usine","body":"…","placement":"merchant",
 "target_type":"url","target_url":"https://…","audience_market_id":1,"audience_aisle_id":3,"audience_merchant_type":"wholesale",
 "starts_on":"2026-11-01","ends_on":"2026-11-30","budget":500000}
```
`placement` : `home` (clients, accueil) | `merchant` (commerçants, « Mon argent »). Fichier `image` facultatif.
La campagne est créée au statut `pending` et n'est diffusée qu'après validation par Axi (`status = 'active'`).
