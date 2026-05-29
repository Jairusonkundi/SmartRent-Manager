<?php

declare(strict_types=1);

/**
 * TestingSanitySeeder
 *
 * Run from the project root:
 *     php database/seeders/TestingSanitySeeder.php
 *
 * Populates a blank smartrent_manager database with a controlled May 2026
 * testing scenario. Rolls back cleanly on any error.
 *
 * ┌─────────────────────────────────────────────────────────────┐
 * │ Properties / Rent                                           │
 * │   Gachie Apartments — units A1–A5 @ KSh 20,000/mo          │
 * │   Safa Towers        — units T1–T5 @ KSh 25,000/mo         │
 * ├─────────────────────────────────────────────────────────────┤
 * │ Payment Groups (billing period: Jan–May 2026)               │
 * │   Group A (7 tenants) — fully paid Jan–May  → KSh 0 arrears│
 * │   Group B (1 tenant)  — paid Jan–Mar only   → KSh 50,000   │
 * │   Group C (2 tenants) — paid Jan–Apr only   → KSh 25,000ea │
 * ├─────────────────────────────────────────────────────────────┤
 * │ Expected portfolio arrears after seeding: KSh 100,000       │
 * └─────────────────────────────────────────────────────────────┘
 */

require_once __DIR__ . '/../../config/database.php';

$pdo = Database::connection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

// Resolve the system admin ID so recorded_by is always valid.
$adminId = (int) $pdo->query("SELECT id FROM users WHERE username = 'admin' LIMIT 1")->fetchColumn() ?: null;

$pdo->beginTransaction();

try {

    // ── 1. Properties ────────────────────────────────────────────────────────────

    $insertProperty = $pdo->prepare(
        "INSERT INTO properties (name, location) VALUES (?, ?)"
    );

    $insertProperty->execute(['Gachie Apartments', 'Gachie, Kiambu']);
    $gachieId = (int) $pdo->lastInsertId();

    $insertProperty->execute(['Safa Towers', 'Karen, Nairobi']);
    $safaId = (int) $pdo->lastInsertId();

    // ── 2. Units ─────────────────────────────────────────────────────────────────

    $insertUnit = $pdo->prepare(
        "INSERT INTO units (property_id, unit_number, status) VALUES (?, ?, 'occupied')"
    );

    $gachieUnits = [];
    foreach (['A1', 'A2', 'A3', 'A4', 'A5'] as $number) {
        $insertUnit->execute([$gachieId, $number]);
        $gachieUnits[$number] = (int) $pdo->lastInsertId();
    }

    $safaUnits = [];
    foreach (['T1', 'T2', 'T3', 'T4', 'T5'] as $number) {
        $insertUnit->execute([$safaId, $number]);
        $safaUnits[$number] = (int) $pdo->lastInsertId();
    }

    // ── 3. Tenants & Leases ──────────────────────────────────────────────────────
    //
    // Columns: name, phone, email, unit_id, rent, group
    //   Group A — fully paid Jan–May  (last paid: 2026-05)
    //   Group B — paid Jan–Mar only   (last paid: 2026-03)
    //   Group C — paid Jan–Apr only   (last paid: 2026-04)

    $tenantDefs = [
        // Gachie Apartments @ KSh 20,000 — all Group A
        ['Alice Kamau',       '0712 001 001', 'alice@example.com',    $gachieUnits['A1'], 20000.00, 'A'],
        ['Brian Odhiambo',    '0712 001 002', 'brian@example.com',    $gachieUnits['A2'], 20000.00, 'A'],
        ['Catherine Wangari', '0712 001 003', 'catherine@example.com',$gachieUnits['A3'], 20000.00, 'A'],
        ['Dennis Mutua',      '0712 001 004', 'dennis@example.com',   $gachieUnits['A4'], 20000.00, 'A'],
        ['Esther Njeri',      '0712 001 005', 'esther@example.com',   $gachieUnits['A5'], 20000.00, 'A'],
        // Safa Towers @ KSh 25,000 — two Group A, one Group B, two Group C
        ['Francis Otieno',    '0712 001 006', 'francis@example.com',  $safaUnits['T1'],   25000.00, 'A'],
        ['Grace Wambui',      '0712 001 007', 'grace@example.com',    $safaUnits['T2'],   25000.00, 'A'],
        ['Henry Mwangi',      '0712 001 008', 'henry@example.com',    $safaUnits['T3'],   25000.00, 'B'],
        ['Irene Achieng',     '0712 001 009', 'irene@example.com',    $safaUnits['T4'],   25000.00, 'C'],
        ['James Kariuki',     '0712 001 010', 'james@example.com',    $safaUnits['T5'],   25000.00, 'C'],
    ];

    // Last month for which each group has a full payment recorded.
    $lastPaidByGroup = ['A' => '2026-05', 'B' => '2026-03', 'C' => '2026-04'];

    $insertTenant = $pdo->prepare(
        "INSERT INTO tenants (name, phone, email, status, credit_balance)
         VALUES (?, ?, ?, 'active', 0.00)"
    );
    $insertLease = $pdo->prepare(
        "INSERT INTO leases (tenant_id, unit_id, rent_amount, start_date, status)
         VALUES (?, ?, ?, '2026-01-01', 'active')"
    );
    $insertPayment = $pdo->prepare(
        "INSERT INTO payments
             (tenant_id, billing_month, amount_expected, monthly_rent, amount_paid,
              payment_date, month, collection_status, payment_status,
              payment_channel, recorded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'On Time', 'bank_transfer', ?)"
    );
    $insertSchedule = $pdo->prepare(
        "INSERT INTO rent_schedule (tenant_id, month, expected_rent, due_date, status)
         VALUES (?, ?, ?, ?, ?)"
    );

    // Billing months to seed: Jan 2026 through May 2026.
    $billingMonths = ['2026-01', '2026-02', '2026-03', '2026-04', '2026-05'];

    $seededTenants = [];

    foreach ($tenantDefs as [$name, $phone, $email, $unitId, $rent, $group]) {

        $insertTenant->execute([$name, $phone, $email]);
        $tenantId = (int) $pdo->lastInsertId();
        $insertLease->execute([$tenantId, $unitId, $rent]);

        $maxPaid = $lastPaidByGroup[$group];

        foreach ($billingMonths as $billingMonth) {
            $paid   = $billingMonth <= $maxPaid;
            $amount = $paid ? $rent : 0.00;
            $status = $paid ? 'Paid' : 'Unpaid';

            // One row per (tenant, billing_month).
            // amount_expected carries the rent on this — the only — row for the month.
            $insertPayment->execute([
                $tenantId,
                $billingMonth,
                $rent,                    // amount_expected
                $rent,                    // monthly_rent (lease rent reference)
                $amount,                  // amount_paid
                $billingMonth . '-10',    // payment_date: 10th of billing month
                $billingMonth . '-01',    // month (DATE column)
                $status,
                $adminId,
            ]);

            $insertSchedule->execute([
                $tenantId,
                $billingMonth . '-01',    // month (DATE)
                $rent,
                $billingMonth . '-05',    // due_date: 5th of billing month
                $paid ? 'paid' : 'unpaid',
            ]);
        }

        $seededTenants[] = ['name' => $name, 'rent' => $rent, 'group' => $group];
    }

    $pdo->commit();

    // ── Verification report ──────────────────────────────────────────────────────

    echo "\n";
    echo "TestingSanitySeeder completed successfully.\n";
    echo str_repeat('─', 62) . "\n";

    // Per-module row counts
    foreach (['properties', 'units', 'tenants', 'leases', 'payments', 'rent_schedule'] as $table) {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        printf("  %-18s %d rows\n", $table . ':', $count);
    }

    echo str_repeat('─', 62) . "\n";

    // Arrears breakdown — same GREATEST query used by the live modules
    $arrearsStmt = $pdo->query(
        "SELECT t.name, sub.billing_month,
                sub.expected, sub.paid,
                GREATEST(sub.expected - sub.paid, 0) AS arrears
         FROM (
             SELECT tenant_id, billing_month,
                    SUM(amount_expected) AS expected,
                    SUM(amount_paid)     AS paid
             FROM payments
             WHERE billing_month <= DATE_FORMAT(CURRENT_DATE, '%Y-%m')
             GROUP BY tenant_id, billing_month
             HAVING GREATEST(SUM(amount_expected) - SUM(amount_paid), 0) > 0
         ) sub
         JOIN tenants t ON t.id = sub.tenant_id
         ORDER BY t.name, sub.billing_month"
    );
    $arrearsRows = $arrearsStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($arrearsRows === []) {
        echo "  Arrears: none (all tenants fully paid).\n";
    } else {
        echo "  Arrears detail:\n";
        $portfolioTotal = 0.0;
        foreach ($arrearsRows as $row) {
            printf("    %-22s  %s  KSh %s\n",
                $row['name'],
                $row['billing_month'],
                number_format((float) $row['arrears'], 2)
            );
            $portfolioTotal += (float) $row['arrears'];
        }
        echo str_repeat('─', 62) . "\n";
        printf("  Portfolio arrears total: KSh %s\n", number_format($portfolioTotal, 2));
        printf("  Expected total:          KSh 100,000.00\n");
        printf("  Assertion: %s\n", abs($portfolioTotal - 100000.0) < 0.01
            ? 'PASS — figures match ✓'
            : 'FAIL — unexpected total ✗');
    }

    echo str_repeat('─', 62) . "\n\n";

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Seeder failed and rolled back: " . $e->getMessage() . "\n");
    exit(1);
}
