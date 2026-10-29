-- Axi : un client ne paie jamais deux fois la même commande, et l'argent n'est libéré qu'à la réception.
-- - orders.idempotency_key : clé envoyée par l'application (en-tête Idempotency-Key) ; une même clé
--   renvoie toujours la même commande, même après une coupure réseau ou un double appui.
-- - orders.request_hash : empreinte du panier ; sans clé, le même panier envoyé deux fois en 15 minutes
--   renvoie la commande déjà créée.
-- - payments : un seul paiement par commande, une seule référence par prestataire.

SET NAMES utf8mb4;

ALTER TABLE orders
  ADD COLUMN idempotency_key VARCHAR(64) NULL AFTER client_id,
  ADD COLUMN request_hash CHAR(64) NULL AFTER idempotency_key,
  ADD UNIQUE KEY uq_orders_idempotency (client_id, idempotency_key),
  ADD KEY idx_orders_request (client_id, request_hash, created_at);

ALTER TABLE payments
  ADD COLUMN reference VARCHAR(40) NULL AFTER provider,
  ADD COLUMN released_at DATETIME NULL AFTER status,
  ADD UNIQUE KEY uq_payments_order (order_id),
  ADD UNIQUE KEY uq_payments_reference (reference),
  ADD UNIQUE KEY uq_payments_provider_ref (provider, provider_ref);

ALTER TABLE order_stalls
  ADD COLUMN picked_up_at DATETIME NULL AFTER ready_at,
  ADD COLUMN delivered_at DATETIME NULL AFTER picked_up_at;
