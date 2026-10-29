-- Axi : données de démonstration (noms fictifs, montants hypothétiques)
-- À exécuter après les migrations, sur une base vide :
--   mysql -u admin -p < database/seed.sql
-- Comptes de test (connexion par code SMS ; en local le code est renvoyé dans debug_code) :
--   Maman Afi (commerçante, tissus)   0197000001
--   Koffi (grossiste, vivriers)       0197000002
--   Séna (cliente)                    0196000001
--   Alassane (revendeur, Parakou)     0196000002
--   Agent Axi                         0195000001
--   ANaGeM (Axi Pro)                  0194000001

SET NAMES utf8mb4;

USE axi;

-- Référentiel ------------------------------------------------------------

INSERT INTO markets (id, slug, name, city, sort_order) VALUES
  (1, 'dantokpa', 'Dantokpa', 'Cotonou', 1),
  (2, 'ouando', 'Ouando', 'Porto-Novo', 2),
  (3, 'tokpa-yoyo', 'Tokpa Yôyô', NULL, 3);

INSERT INTO aisles (id, slug, name, icon, sort_order) VALUES
  (1, 'tissus-pagnes', 'Tissus et pagnes', 'fabric', 1),
  (2, 'vivriers', 'Vivriers', 'basket', 2),
  (3, 'quincaillerie', 'Quincaillerie', 'hammer', 3),
  (4, 'cosmetiques', 'Cosmétiques', 'lotion', 4),
  (5, 'pieces-detachees', 'Pièces détachées', 'gear', 5),
  (6, 'electronique', 'Électronique', 'phone', 6);

INSERT INTO catalog_items (id, aisle_id, name, unit) VALUES
  (1, 1, 'Pagne wax', '6 yards'),
  (2, 2, 'Riz parfumé', 'sac de 25 kg'),
  (3, 2, 'Huile rouge', 'bidon de 25 L'),
  (4, 2, 'Gari', 'sac de 50 kg');

INSERT INTO delivery_options (id, mode, label, city, address, fee, eta_label, sort_order) VALUES
  (1, 'home', 'Livraison à domicile', 'Cotonou', NULL, 1000, 'Aujourd''hui', 1),
  (2, 'home', 'Livraison à domicile', 'Abomey-Calavi', NULL, 1500, 'Aujourd''hui', 2),
  (3, 'bus', 'Expédition par car', 'Parakou', 'Gare routière de Parakou', 2500, '1 à 2 jours', 3),
  (4, 'bus', 'Expédition par car', 'Lomé', 'Gare de Lomé', 3000, '1 à 2 jours', 4),
  (5, 'pickup', 'Point Axi Dantokpa', 'Cotonou', 'Entrée principale, à côté du poste des agents Axi', 0, 'Dès demain', 5);

-- Utilisateurs -----------------------------------------------------------

INSERT INTO users (phone, name, org_name, role) VALUES
  ('+2290197000001', 'Maman Afi', NULL, 'merchant'),
  ('+2290197000002', 'Koffi', NULL, 'merchant'),
  ('+2290197000003', 'Tina', NULL, 'merchant'),
  ('+2290197000004', 'Rachidi', NULL, 'merchant'),
  ('+2290197000005', 'Mariam', NULL, 'merchant'),
  ('+2290196000001', 'Séna', NULL, 'client'),
  ('+2290196000002', 'Alassane', NULL, 'client'),
  ('+2290195000001', 'Agent Axi Dantokpa', NULL, 'agent'),
  ('+2290194000001', 'Service des données', 'ANaGeM', 'pro')
ON DUPLICATE KEY UPDATE name = VALUES(name);

SET @afi = (SELECT id FROM users WHERE phone = '+2290197000001');
SET @koffi = (SELECT id FROM users WHERE phone = '+2290197000002');
SET @tina = (SELECT id FROM users WHERE phone = '+2290197000003');
SET @rachidi = (SELECT id FROM users WHERE phone = '+2290197000004');
SET @mariam = (SELECT id FROM users WHERE phone = '+2290197000005');
SET @sena = (SELECT id FROM users WHERE phone = '+2290196000001');
SET @alassane = (SELECT id FROM users WHERE phone = '+2290196000002');
SET @agent = (SELECT id FROM users WHERE phone = '+2290195000001');
SET @pro = (SELECT id FROM users WHERE phone = '+2290194000001');
SET @now = UTC_TIMESTAMP();
-- Date du jour au Bénin (UTC+1, pas d'heure d'été)
SET @today = DATE(UTC_TIMESTAMP() + INTERVAL 1 HOUR);

-- Étals ------------------------------------------------------------------
-- Maman Afi a déménagé de Dantokpa vers Tokpa Yôyô il y a 2 jours

INSERT INTO stalls (id, merchant_id, market_id, aisle_id, name, description, row_label, stall_number, contact_phone, whatsapp, merchant_type, place_status, verified_at, verified_by, moved_at, created_at) VALUES
  (1, @afi, 3, 1, 'Chez Maman Afi', 'Pagnes wax, Vlisco et bazin. Conseils pour vos cérémonies.', 'B', '12', '+2290197000001', '+2290197000001', 'retail', 'holder', @now - INTERVAL 30 DAY, @agent, @now - INTERVAL 2 DAY, @now - INTERVAL 120 DAY),
  (2, @koffi, 1, 2, 'Koffi Vivriers Gros', 'Riz, huile, gari en gros. Expédition vers Parakou, Lomé, Niamey.', 'F', '3', '+2290197000002', NULL, 'wholesale', 'holder', @now - INTERVAL 60 DAY, @agent, NULL, @now - INTERVAL 150 DAY),
  (3, @tina, 1, 4, 'Tina Beauté', 'Karité, savon noir, huiles naturelles.', 'K', '27', '+2290197000003', NULL, 'retail', 'subtenant', NULL, NULL, NULL, @now - INTERVAL 40 DAY),
  (4, @rachidi, 1, 2, 'Chez Rachidi', 'Vivriers au détail et demi-gros.', 'F', '8', '+2290197000004', NULL, 'wholesale', 'holder', @now - INTERVAL 45 DAY, @agent, NULL, @now - INTERVAL 100 DAY),
  (5, @mariam, 1, 2, 'Mariam Produits Frais', 'Gari, piment, riz local.', NULL, NULL, '+2290197000005', NULL, 'retail', 'no_place', @now - INTERVAL 20 DAY, @agent, NULL, @now - INTERVAL 60 DAY);

INSERT INTO stall_moves (stall_id, from_market_id, from_row, from_number, to_market_id, to_row, to_number, recipients_count, created_at) VALUES
  (1, 1, 'C', '45', 3, 'B', '12', 3, @now - INTERVAL 2 DAY);

INSERT INTO products (id, stall_id, catalog_item_id, name, description, unit, price, price_max, wholesale_price, wholesale_min_qty, stock_status, daily_date) VALUES
  (1, 1, 1, 'Pagne wax hollandais', 'Motifs de la saison', '6 yards', 18000, 25000, NULL, NULL, 'available', @today),
  (2, 1, NULL, 'Pagne Vlisco', 'Original, avec étiquette', '6 yards', 45000, NULL, NULL, NULL, 'low', NULL),
  (3, 1, NULL, 'Bazin riche', 'Plusieurs couleurs', '5 mètres', 15000, NULL, 13000, 10, 'available', @today),
  (4, 2, 2, 'Riz parfumé', 'Arrivage de la semaine', 'sac de 25 kg', 17500, NULL, 16500, 10, 'available', @today),
  (5, 2, 3, 'Huile rouge', NULL, 'bidon de 25 L', 22000, NULL, 21000, 10, 'available', NULL),
  (6, 2, 4, 'Gari', NULL, 'sac de 50 kg', 21000, NULL, 20000, 10, 'available', NULL),
  (7, 3, NULL, 'Beurre de karité', 'Pur, du Nord', 'pot de 500 g', 2500, NULL, NULL, NULL, 'available', @today),
  (8, 3, NULL, 'Savon noir', NULL, 'boule', 1000, NULL, NULL, NULL, 'available', NULL),
  (9, 4, 2, 'Riz parfumé', NULL, 'sac de 25 kg', 18000, NULL, 17000, 10, 'available', NULL),
  (10, 4, 3, 'Huile rouge', NULL, 'bidon de 25 L', 21000, NULL, NULL, NULL, 'available', NULL),
  (11, 4, 4, 'Gari', 'Gari sohoui', 'sac de 50 kg', 20000, NULL, NULL, NULL, 'available', @today),
  (12, 5, 2, 'Riz parfumé', NULL, 'sac de 25 kg', 18500, NULL, NULL, NULL, 'available', NULL),
  (13, 5, 4, 'Gari', NULL, 'sac de 50 kg', 22500, NULL, NULL, NULL, 'available', NULL),
  (14, 5, NULL, 'Piment sec', NULL, '1 kg', 3000, NULL, NULL, NULL, 'available', @today);

INSERT INTO follows (user_id, stall_id, created_at) VALUES
  (@sena, 1, @now - INTERVAL 20 DAY),
  (@sena, 3, @now - INTERVAL 10 DAY),
  (@alassane, 2, @now - INTERVAL 30 DAY);

INSERT INTO subscriptions (stall_id, plan, amount, paid_by, starts_at, ends_at, created_at) VALUES
  (1, 'retail', 2000, 'merchant', @now - INTERVAL 10 DAY, @now + INTERVAL 20 DAY, @now - INTERVAL 10 DAY),
  (2, 'wholesale', 5000, 'merchant', @now - INTERVAL 5 DAY, @now + INTERVAL 25 DAY, @now - INTERVAL 5 DAY),
  (3, 'retail', 2000, 'merchant', @now - INTERVAL 40 DAY, @now - INTERVAL 10 DAY, @now - INTERVAL 40 DAY),
  (4, 'wholesale', 5000, 'merchant', @now - INTERVAL 15 DAY, @now + INTERVAL 15 DAY, @now - INTERVAL 15 DAY),
  (5, 'anagem', 0, 'anagem', @now - INTERVAL 15 DAY, @now + INTERVAL 15 DAY, @now - INTERVAL 15 DAY);

-- Promotions et offres -------------------------------------------------

INSERT INTO promotions (owner_id, sponsor_name, title, body, placement, target_type, target_id, audience_aisle_id, starts_at, ends_at, status, created_at) VALUES
  (NULL, 'Savon Lumière', 'Savon Lumière : 3 achetés, 1 offert', 'Chez les étals cosmétiques participants.', 'home', 'aisle', 4, NULL, @now - INTERVAL 1 DAY, @now + INTERVAL 30 DAY, 'active', @now),
  (NULL, 'Riz Bénin SA', 'Riz Bénin : prix grossiste garanti', 'Réapprovisionnez-vous livré à l''étal, payable sur vos ventes Axi.', 'merchant', 'none', NULL, 2, @now - INTERVAL 1 DAY, @now + INTERVAL 30 DAY, 'active', @now);

INSERT INTO credit_offers (id, partner_name, kind, title, description, supplier_name, min_amount, max_amount, max_months, rate_label, sponsored, audience_aisle_id) VALUES
  (1, 'MicroFi Bénin', 'cash', 'Crédit commerce', 'Fonds de roulement pour commerçants actifs sur Axi.', NULL, 50000, 1000000, 12, '2 % par mois', 0, NULL),
  (2, 'MicroFi Bénin', 'stock', 'Crédit stock riz', 'Le fournisseur livre, MicroFi paie, le remboursement est prélevé sur vos ventes Axi.', 'Riz Bénin SA', 100000, 2000000, 6, '1,5 % par mois', 1, 2),
  (3, 'MicroFi Bénin', 'stock', 'Crédit stock pagnes', 'Réassort wax financé, remboursé sur vos ventes Axi.', 'Wax Import Cotonou', 100000, 1500000, 6, '1,5 % par mois', 1, 1);

-- Commandes passées ------------------------------------------------------

INSERT INTO orders (id, ref, client_id, subtotal, grouping_fee, delivery_fee, total, delivery_option_id, delivery_address, contact_phone, status, paid_at, received_at, created_at) VALUES
  (1, 'AX000001', @sena, 41000, 250, 1000, 42250, 1, 'Fidjrossè, rue du marché', '+2290196000001', 'completed', @now - INTERVAL 10 DAY, @now - INTERVAL 9 DAY, @now - INTERVAL 10 DAY),
  (2, 'AX000002', @alassane, 330000, 0, 2500, 332500, 3, 'Gare routière de Parakou', '+2290196000002', 'completed', @now - INTERVAL 8 DAY, @now - INTERVAL 6 DAY, @now - INTERVAL 8 DAY),
  (3, 'AX000003', @sena, 26000, 250, 0, 26250, 5, 'Entrée principale, à côté du poste des agents Axi', '+2290196000001', 'completed', @now - INTERVAL 5 DAY, @now - INTERVAL 4 DAY, @now - INTERVAL 5 DAY),
  (4, 'AX000004', @alassane, 462500, 500, 2500, 465500, 3, 'Gare routière de Parakou', '+2290196000002', 'completed', @now - INTERVAL 3 DAY, @now - INTERVAL 1 DAY, @now - INTERVAL 3 DAY),
  (5, 'AX000005', @sena, 30000, 0, 1000, 31000, 1, 'Fidjrossè, rue du marché', '+2290196000001', 'paid', @now - INTERVAL 1 HOUR, NULL, @now - INTERVAL 1 HOUR);

INSERT INTO stall_orders (id, order_id, stall_id, subtotal, commission, net_amount, status, created_at) VALUES
  (1, 1, 1, 36000, 0, 36000, 'received', @now - INTERVAL 10 DAY),
  (2, 1, 3, 5000, 0, 5000, 'received', @now - INTERVAL 10 DAY),
  (3, 2, 2, 330000, 0, 330000, 'received', @now - INTERVAL 8 DAY),
  (4, 3, 4, 20000, 0, 20000, 'received', @now - INTERVAL 5 DAY),
  (5, 3, 5, 6000, 0, 6000, 'received', @now - INTERVAL 5 DAY),
  (6, 4, 2, 200000, 0, 200000, 'received', @now - INTERVAL 3 DAY),
  (7, 4, 4, 170000, 0, 170000, 'received', @now - INTERVAL 3 DAY),
  (8, 4, 5, 92500, 0, 92500, 'received', @now - INTERVAL 3 DAY),
  (9, 5, 1, 30000, 0, 30000, 'new', @now - INTERVAL 1 HOUR);

INSERT INTO order_items (stall_order_id, product_id, name, unit, unit_price, quantity, line_total) VALUES
  (1, 1, 'Pagne wax hollandais', '6 yards', 18000, 2, 36000),
  (2, 7, 'Beurre de karité', 'pot de 500 g', 2500, 2, 5000),
  (3, 4, 'Riz parfumé', 'sac de 25 kg', 16500, 20, 330000),
  (4, 11, 'Gari', 'sac de 50 kg', 20000, 1, 20000),
  (5, 14, 'Piment sec', '1 kg', 3000, 2, 6000),
  (6, 6, 'Gari', 'sac de 50 kg', 20000, 10, 200000),
  (7, 9, 'Riz parfumé', 'sac de 25 kg', 17000, 10, 170000),
  (8, 12, 'Riz parfumé', 'sac de 25 kg', 18500, 5, 92500),
  (9, 3, 'Bazin riche', '5 mètres', 15000, 2, 30000);

INSERT INTO payments (purpose, order_id, user_id, provider, provider_ref, amount, status, paid_at, created_at) VALUES
  ('order', 1, @sena, 'fedapay', 'DEMO-1', 42250, 'released', @now - INTERVAL 10 DAY, @now - INTERVAL 10 DAY),
  ('order', 2, @alassane, 'kkiapay', 'DEMO-2', 332500, 'released', @now - INTERVAL 8 DAY, @now - INTERVAL 8 DAY),
  ('order', 3, @sena, 'fedapay', 'DEMO-3', 26250, 'released', @now - INTERVAL 5 DAY, @now - INTERVAL 5 DAY),
  ('order', 4, @alassane, 'kkiapay', 'DEMO-4', 465500, 'released', @now - INTERVAL 3 DAY, @now - INTERVAL 3 DAY),
  ('order', 5, @sena, 'fedapay', 'DEMO-5', 31000, 'held', @now - INTERVAL 1 HOUR, @now - INTERVAL 1 HOUR);

INSERT INTO payouts (stall_id, amount, phone, status, created_at, processed_at) VALUES
  (2, 300000, '+2290197000002', 'paid', @now - INTERVAL 5 DAY, @now - INTERVAL 5 DAY);

-- Fichiers clients -------------------------------------------------------

INSERT INTO merchant_customers (stall_id, user_id, name, phone, customer_type, city, note, orders_count, total_spent, last_order_at, created_at) VALUES
  (1, @sena, 'Séna', '+2290196000001', 'retail', 'Cotonou', NULL, 2, 66000, @now - INTERVAL 1 HOUR, @now - INTERVAL 10 DAY),
  (1, NULL, 'Mme Houngbo', '+2290196100001', 'retail', 'Cotonou', 'Achète pour les mariages', 0, 0, NULL, @now - INTERVAL 60 DAY),
  (1, NULL, 'Mama Bintou', '+2290196100002', 'wholesale', 'Lomé', 'Revendeuse, prend 20 pagnes par mois', 4, 720000, @now - INTERVAL 45 DAY, @now - INTERVAL 90 DAY),
  (2, @alassane, 'Alassane', '+2290196000002', 'wholesale', 'Parakou', NULL, 2, 530000, @now - INTERVAL 3 DAY, @now - INTERVAL 8 DAY),
  (3, @sena, 'Séna', '+2290196000001', 'retail', 'Cotonou', NULL, 1, 5000, @now - INTERVAL 10 DAY, @now - INTERVAL 10 DAY),
  (4, @sena, 'Séna', '+2290196000001', 'retail', 'Cotonou', NULL, 1, 20000, @now - INTERVAL 5 DAY, @now - INTERVAL 5 DAY),
  (4, @alassane, 'Alassane', '+2290196000002', 'wholesale', 'Parakou', NULL, 1, 170000, @now - INTERVAL 3 DAY, @now - INTERVAL 3 DAY),
  (5, @sena, 'Séna', '+2290196000001', 'retail', 'Cotonou', NULL, 1, 6000, @now - INTERVAL 5 DAY, @now - INTERVAL 5 DAY),
  (5, @alassane, 'Alassane', '+2290196000002', 'wholesale', 'Parakou', NULL, 1, 92500, @now - INTERVAL 3 DAY, @now - INTERVAL 3 DAY);

-- Crédits (pour les statistiques Axi Pro) ------------------------------

INSERT INTO credit_requests (stall_id, offer_id, amount, duration_months, purpose, share_history, consent_at, status, approved_amount, decided_at, created_at) VALUES
  (2, 2, 1000000, 6, 'Stock de riz avant les fêtes', 1, @now - INTERVAL 20 DAY, 'approved', 800000, @now - INTERVAL 15 DAY, @now - INTERVAL 20 DAY),
  (4, 1, 300000, 6, 'Fonds de roulement', 1, @now - INTERVAL 18 DAY, 'approved', 300000, @now - INTERVAL 12 DAY, @now - INTERVAL 18 DAY),
  (5, 1, 150000, 3, 'Achat de gari', 1, @now - INTERVAL 10 DAY, 'rejected', NULL, @now - INTERVAL 7 DAY, @now - INTERVAL 10 DAY);

INSERT INTO notifications (user_id, type, title, body, data, created_at) VALUES
  (@sena, 'stall_move', 'Chez Maman Afi a déménagé', 'Nous avons déménagé ! Retrouvez-nous désormais à Tokpa Yôyô, rangée B, n°12. Commandez aussi sur Axi.', '{"stall_id":1}', @now - INTERVAL 2 DAY),
  (@afi, 'order_new', 'Nouvelle commande AX000005', 'Séna a payé 30000 FCFA. L''argent est gardé par Axi jusqu''à la réception.', '{"order_ref":"AX000005"}', @now - INTERVAL 1 HOUR);
