-- ============================================================
-- SmartRent-Manager — Test Seed Data
-- Run AFTER schema.sql.  Wipes all business data and inserts
-- a controlled set of tenants / leases / payments for testing
-- arrears, outstanding balances, and credit carry-forward.
--
-- Portfolio summary (as of 2026-05-26):
--   Tenants      : 8   (4 Jan · 2 Mar · 2 Apr 2026 starters)
--   Properties   : 2   (Sunrise Apartments · Green Valley Estate)
--   Occupied     : 8 units   Vacant: 2 units
--
-- Expected total portfolio AR (GREATEST approach, no netting):
--   Alice Mutua   : 15,000  (Mar 2026 partial)
--   Brian Omondi  : 56,000  (Feb 38k + Apr 18k)
--   Sarah Wanjiku : 52,000  (May 2026 unpaid)
--   Ken Njoroge   : 27,000  (Apr 2026 partial)
--   John Kamau    : 55,000  (Apr 20k + May 35k)
--   Jane Nafula   : 23,000  (Mar 2026 partial)
--   Peter Mwangi  : 17,000  (May 2026 partial)
--   Grace Otieno  :110,000  (Apr 55k + May 55k)
--   TOTAL         :355,000
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE payments;
TRUNCATE TABLE rent_schedule;
DELETE FROM leases;
DELETE FROM tenants;
DELETE FROM units;
DELETE FROM properties;
SET FOREIGN_KEY_CHECKS = 1;

-- Reset auto-increment sequences
ALTER TABLE properties  AUTO_INCREMENT = 1;
ALTER TABLE units        AUTO_INCREMENT = 1;
ALTER TABLE tenants      AUTO_INCREMENT = 1;
ALTER TABLE leases       AUTO_INCREMENT = 1;
ALTER TABLE payments     AUTO_INCREMENT = 1;
ALTER TABLE rent_schedule AUTO_INCREMENT = 1;

-- ── Properties ────────────────────────────────────────────────────────────────
INSERT INTO properties (id, name, location) VALUES
  (1, 'Sunrise Apartments',   'Westlands, Nairobi'),
  (2, 'Green Valley Estate',  'Kilimani, Nairobi');

-- ── Units ─────────────────────────────────────────────────────────────────────
INSERT INTO units (id, property_id, unit_number, status) VALUES
  (1,  1, 'A101', 'occupied'),
  (2,  1, 'A102', 'occupied'),
  (3,  1, 'A103', 'occupied'),
  (4,  1, 'A104', 'occupied'),
  (5,  1, 'A105', 'vacant'),
  (6,  2, 'B201', 'occupied'),
  (7,  2, 'B202', 'occupied'),
  (8,  2, 'B203', 'occupied'),
  (9,  2, 'B204', 'occupied'),
  (10, 2, 'B205', 'vacant');

-- ── Tenants ───────────────────────────────────────────────────────────────────
-- Alice: May 2026 overpayment of 5,000 → credit_balance = 5000.00
INSERT INTO tenants (id, name, phone, email, tenant_phone, tenant_email, status, credit_balance) VALUES
  (1, 'Alice Mutua',   '+254712100001', 'alice@example.com',  '+254712100001', 'alice@example.com',  'active', 5000.00),
  (2, 'Brian Omondi',  '+254712100002', 'brian@example.com',  '+254712100002', 'brian@example.com',  'active', 0.00),
  (3, 'John Kamau',    '+254712100003', 'john@example.com',   '+254712100003', 'john@example.com',   'active', 0.00),
  (4, 'Peter Mwangi',  '+254712100004', 'peter@example.com',  '+254712100004', 'peter@example.com',  'active', 0.00),
  (5, 'Sarah Wanjiku', '+254712100005', 'sarah@example.com',  '+254712100005', 'sarah@example.com',  'active', 0.00),
  (6, 'Ken Njoroge',   '+254712100006', 'ken@example.com',    '+254712100006', 'ken@example.com',    'active', 0.00),
  (7, 'Jane Nafula',   '+254712100007', 'jane@example.com',   '+254712100007', 'jane@example.com',   'active', 0.00),
  (8, 'Grace Otieno',  '+254712100008', 'grace@example.com',  '+254712100008', 'grace@example.com',  'active', 0.00);

-- ── Leases ────────────────────────────────────────────────────────────────────
-- January 2026 starters
INSERT INTO leases (id, tenant_id, unit_id, rent_amount, start_date, end_date, status) VALUES
  (1, 1, 1, 45000.00, '2026-01-01', NULL, 'active'),  -- Alice   → A101 45k
  (2, 2, 2, 38000.00, '2026-01-01', NULL, 'active'),  -- Brian   → A102 38k
  (3, 5, 6, 52000.00, '2026-01-01', NULL, 'active'),  -- Sarah   → B201 52k
  (4, 6, 7, 42000.00, '2026-01-01', NULL, 'active');  -- Ken     → B202 42k

-- March 2026 starters
INSERT INTO leases (id, tenant_id, unit_id, rent_amount, start_date, end_date, status) VALUES
  (5, 3, 3, 35000.00, '2026-03-01', NULL, 'active'),  -- John    → A103 35k
  (6, 7, 8, 48000.00, '2026-03-01', NULL, 'active');  -- Jane    → B203 48k

-- April 2026 starters
INSERT INTO leases (id, tenant_id, unit_id, rent_amount, start_date, end_date, status) VALUES
  (7, 4, 4, 42000.00, '2026-04-01', NULL, 'active'),  -- Peter   → A104 42k
  (8, 8, 9, 55000.00, '2026-04-01', NULL, 'active');  -- Grace   → B204 55k

-- ── Payments ──────────────────────────────────────────────────────────────────
-- Convention: amount_expected is set on the FIRST row for each (tenant, billing_month).
--             Subsequent rows use 0 to avoid double-counting. (Single-row per month here.)
--             monthly_rent = contract rent (always the full amount).
--             Unpaid months get amount_paid=0, payment_date=first of month.

-- ─── Alice Mutua (tenant_id=1, 45,000/mo) ────────────────────────────────────
-- Jan: Paid in full, on time
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(1,'2026-01',45000.00,45000.00,45000.00,'2026-01-08','2026-01-01','Paid','On Time','bank_transfer','TXN-2601-001',1);
-- Feb: Paid in full, on time
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(1,'2026-02',45000.00,45000.00,45000.00,'2026-02-07','2026-02-01','Paid','On Time','bank_transfer','TXN-2602-001',1);
-- Mar: Partial 30,000 — late payment (arrears: 15,000)
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(1,'2026-03',45000.00,45000.00,30000.00,'2026-03-15','2026-03-01','Partial','Late','cash','CASH-2603-001',1);
-- Apr: Paid in full, on time
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(1,'2026-04',45000.00,45000.00,45000.00,'2026-04-04','2026-04-01','Paid','On Time','bank_transfer','TXN-2604-001',1);
-- May: Overpayment 50,000 (credit carry: 5,000) — on time
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(1,'2026-05',45000.00,45000.00,50000.00,'2026-05-05','2026-05-01','Paid','On Time','bank_transfer','TXN-2605-001',1);

-- ─── Brian Omondi (tenant_id=2, 38,000/mo) ───────────────────────────────────
-- Jan: Paid in full, on time
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(2,'2026-01',38000.00,38000.00,38000.00,'2026-01-10','2026-01-01','Paid','On Time','bank_transfer','TXN-2601-002',1);
-- Feb: Unpaid — recorded as outstanding (arrears: 38,000)
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(2,'2026-02',38000.00,38000.00,0.00,'2026-02-28','2026-02-01','Unpaid','On Time','bank_transfer',NULL,1);
-- Mar: Paid in full, on time (Feb arrears still outstanding)
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(2,'2026-03',38000.00,38000.00,38000.00,'2026-03-08','2026-03-01','Paid','On Time','bank_transfer','TXN-2603-002',1);
-- Apr: Partial 20,000 — late (arrears: 18,000)
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(2,'2026-04',38000.00,38000.00,20000.00,'2026-04-18','2026-04-01','Partial','Late','cash','CASH-2604-001',1);
-- May: Paid in full, on time
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(2,'2026-05',38000.00,38000.00,38000.00,'2026-05-06','2026-05-01','Paid','On Time','bank_transfer','TXN-2605-002',1);

-- ─── Sarah Wanjiku (tenant_id=5, 52,000/mo) ──────────────────────────────────
-- Jan–Apr: All paid in full, on time
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(5,'2026-01',52000.00,52000.00,52000.00,'2026-01-05','2026-01-01','Paid','On Time','bank_transfer','TXN-2601-005',1);
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(5,'2026-02',52000.00,52000.00,52000.00,'2026-02-06','2026-02-01','Paid','On Time','bank_transfer','TXN-2602-005',1);
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(5,'2026-03',52000.00,52000.00,52000.00,'2026-03-07','2026-03-01','Paid','On Time','bank_transfer','TXN-2603-005',1);
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(5,'2026-04',52000.00,52000.00,52000.00,'2026-04-08','2026-04-01','Paid','On Time','bank_transfer','TXN-2604-005',1);
-- May: Unpaid (arrears: 52,000)
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(5,'2026-05',52000.00,52000.00,0.00,'2026-05-01','2026-05-01','Unpaid','On Time','bank_transfer',NULL,1);

-- ─── Ken Njoroge (tenant_id=6, 42,000/mo) ────────────────────────────────────
-- Jan–Mar: Paid in full
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(6,'2026-01',42000.00,42000.00,42000.00,'2026-01-09','2026-01-01','Paid','On Time','bank_transfer','TXN-2601-006',1);
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(6,'2026-02',42000.00,42000.00,42000.00,'2026-02-08','2026-02-01','Paid','On Time','bank_transfer','TXN-2602-006',1);
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(6,'2026-03',42000.00,42000.00,42000.00,'2026-03-09','2026-03-01','Paid','On Time','bank_transfer','TXN-2603-006',1);
-- Apr: Partial 15,000 — late (arrears: 27,000)
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(6,'2026-04',42000.00,42000.00,15000.00,'2026-04-22','2026-04-01','Partial','Late','cash','CASH-2604-002',1);
-- May: Paid in full, on time
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(6,'2026-05',42000.00,42000.00,42000.00,'2026-05-09','2026-05-01','Paid','On Time','bank_transfer','TXN-2605-006',1);

-- ─── John Kamau (tenant_id=3, 35,000/mo, starts Mar 2026) ────────────────────
-- Mar: Paid in full, on time
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(3,'2026-03',35000.00,35000.00,35000.00,'2026-03-05','2026-03-01','Paid','On Time','bank_transfer','TXN-2603-003',1);
-- Apr: Partial 15,000 — late (arrears: 20,000)
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(3,'2026-04',35000.00,35000.00,15000.00,'2026-04-20','2026-04-01','Partial','Late','cash','CASH-2604-003',1);
-- May: Unpaid (arrears: 35,000)
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(3,'2026-05',35000.00,35000.00,0.00,'2026-05-01','2026-05-01','Unpaid','On Time','bank_transfer',NULL,1);

-- ─── Jane Nafula (tenant_id=7, 48,000/mo, starts Mar 2026) ───────────────────
-- Mar: Partial 25,000 — late (arrears: 23,000)
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(7,'2026-03',48000.00,48000.00,25000.00,'2026-03-18','2026-03-01','Partial','Late','cash','CASH-2603-004',1);
-- Apr: Paid in full, on time
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(7,'2026-04',48000.00,48000.00,48000.00,'2026-04-09','2026-04-01','Paid','On Time','bank_transfer','TXN-2604-007',1);
-- May: Paid in full, on time
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(7,'2026-05',48000.00,48000.00,48000.00,'2026-05-08','2026-05-01','Paid','On Time','bank_transfer','TXN-2605-007',1);

-- ─── Peter Mwangi (tenant_id=4, 42,000/mo, starts Apr 2026) ──────────────────
-- Apr: Paid in full, on time
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(4,'2026-04',42000.00,42000.00,42000.00,'2026-04-03','2026-04-01','Paid','On Time','bank_transfer','TXN-2604-004',1);
-- May: Partial 25,000 — late (arrears: 17,000)
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(4,'2026-05',42000.00,42000.00,25000.00,'2026-05-14','2026-05-01','Partial','Late','cash','CASH-2605-001',1);

-- ─── Grace Otieno (tenant_id=8, 55,000/mo, starts Apr 2026) ──────────────────
-- Apr: Unpaid (arrears: 55,000)
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(8,'2026-04',55000.00,55000.00,0.00,'2026-04-01','2026-04-01','Unpaid','On Time','bank_transfer',NULL,1);
-- May: Unpaid (arrears: 55,000)
INSERT INTO payments (tenant_id,billing_month,amount_expected,monthly_rent,amount_paid,payment_date,month,collection_status,payment_status,payment_channel,reference_no,recorded_by) VALUES
(8,'2026-05',55000.00,55000.00,0.00,'2026-05-01','2026-05-01','Unpaid','On Time','bank_transfer',NULL,1);

-- ── Rent Schedule (mirrors payment data for cron consistency) ─────────────────
-- Alice
INSERT INTO rent_schedule (tenant_id,month,expected_rent,due_date,status) VALUES
(1,'2026-01-01',45000.00,'2026-01-10','paid'),
(1,'2026-02-01',45000.00,'2026-02-10','paid'),
(1,'2026-03-01',45000.00,'2026-03-10','partial'),
(1,'2026-04-01',45000.00,'2026-04-10','paid'),
(1,'2026-05-01',45000.00,'2026-05-10','paid');
-- Brian
INSERT INTO rent_schedule (tenant_id,month,expected_rent,due_date,status) VALUES
(2,'2026-01-01',38000.00,'2026-01-10','paid'),
(2,'2026-02-01',38000.00,'2026-02-10','unpaid'),
(2,'2026-03-01',38000.00,'2026-03-10','paid'),
(2,'2026-04-01',38000.00,'2026-04-10','partial'),
(2,'2026-05-01',38000.00,'2026-05-10','paid');
-- Sarah
INSERT INTO rent_schedule (tenant_id,month,expected_rent,due_date,status) VALUES
(5,'2026-01-01',52000.00,'2026-01-10','paid'),
(5,'2026-02-01',52000.00,'2026-02-10','paid'),
(5,'2026-03-01',52000.00,'2026-03-10','paid'),
(5,'2026-04-01',52000.00,'2026-04-10','paid'),
(5,'2026-05-01',52000.00,'2026-05-10','unpaid');
-- Ken
INSERT INTO rent_schedule (tenant_id,month,expected_rent,due_date,status) VALUES
(6,'2026-01-01',42000.00,'2026-01-10','paid'),
(6,'2026-02-01',42000.00,'2026-02-10','paid'),
(6,'2026-03-01',42000.00,'2026-03-10','paid'),
(6,'2026-04-01',42000.00,'2026-04-10','partial'),
(6,'2026-05-01',42000.00,'2026-05-10','paid');
-- John
INSERT INTO rent_schedule (tenant_id,month,expected_rent,due_date,status) VALUES
(3,'2026-03-01',35000.00,'2026-03-10','paid'),
(3,'2026-04-01',35000.00,'2026-04-10','partial'),
(3,'2026-05-01',35000.00,'2026-05-10','unpaid');
-- Jane
INSERT INTO rent_schedule (tenant_id,month,expected_rent,due_date,status) VALUES
(7,'2026-03-01',48000.00,'2026-03-10','partial'),
(7,'2026-04-01',48000.00,'2026-04-10','paid'),
(7,'2026-05-01',48000.00,'2026-05-10','paid');
-- Peter
INSERT INTO rent_schedule (tenant_id,month,expected_rent,due_date,status) VALUES
(4,'2026-04-01',42000.00,'2026-04-10','paid'),
(4,'2026-05-01',42000.00,'2026-05-10','partial');
-- Grace
INSERT INTO rent_schedule (tenant_id,month,expected_rent,due_date,status) VALUES
(8,'2026-04-01',55000.00,'2026-04-10','unpaid'),
(8,'2026-05-01',55000.00,'2026-05-10','unpaid');
