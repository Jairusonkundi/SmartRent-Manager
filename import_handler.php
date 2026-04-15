<?php

declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Use POST with a CSV file.';
    exit;
}

if (!isset($_FILES['csv_file']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
    http_response_code(422);
    echo 'Upload a valid CSV file using field name: csv_file';
    exit;
}

$requiredHeaders = [
    'Property_Name',
    'Unit_Number',
    'Tenant_Name',
    'Tenant_Email',
    'Tenant_Phone',
    'Monthly_Rent',
    'Lease_Start',
    'Payment_Date',
    'Amount_Paid',
];

$handle = fopen($_FILES['csv_file']['tmp_name'], 'rb');
if ($handle === false) {
    throw new RuntimeException('Unable to open uploaded CSV.');
}

$headers = fgetcsv($handle);
if ($headers === false || array_map('trim', $headers) !== $requiredHeaders) {
    http_response_code(422);
    echo 'CSV headers mismatch. Please use the exact template headers.';
    exit;
}

$pdo = dbConnect();
$rowsProcessed = 0;
$resultPreview = [];

$parseCsvDate = static function (string $rawDate): string {
    $normalized = trim($rawDate);
    if ($normalized === '') {
        throw new RuntimeException('Date value cannot be empty.');
    }

    $timestamp = strtotime($normalized);
    if ($timestamp !== false) {
        return date('Y-m-d', $timestamp);
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $normalized)
        ?: DateTimeImmutable::createFromFormat('Y-m-d', $normalized);

    if ($date === false) {
        throw new RuntimeException('Invalid date format: ' . $rawDate);
    }

    return $date->format('Y-m-d');
};

try {
    $pdo->beginTransaction();

    $propertyStmt = $pdo->prepare('INSERT INTO properties (name, location) VALUES (:name, :location) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), updated_at = CURRENT_TIMESTAMP');
    $unitStmt = $pdo->prepare('INSERT INTO units (property_id, unit_number, status) VALUES (:property_id, :unit_number, :status) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), status = VALUES(status), updated_at = CURRENT_TIMESTAMP');

    $tenantFindStmt = $pdo->prepare('SELECT id FROM tenants WHERE email = :email LIMIT 1');
    $tenantInsertStmt = $pdo->prepare('INSERT INTO tenants (name, phone, email, tenant_phone, tenant_email, status) VALUES (:name, :phone, :email, :tenant_phone, :tenant_email, :status)');
    $tenantUpdateStmt = $pdo->prepare('UPDATE tenants SET name = :name, phone = :phone, email = :email, tenant_phone = :tenant_phone, tenant_email = :tenant_email, status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');

    $leaseStmt = $pdo->prepare(
        'INSERT INTO leases (tenant_id, unit_id, rent_amount, start_date, status)
         VALUES (:tenant_id, :unit_id, :rent_amount, :start_date, :status)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), rent_amount = VALUES(rent_amount), status = VALUES(status), updated_at = CURRENT_TIMESTAMP'
    );

    $rentScheduleStmt = $pdo->prepare(
        'INSERT INTO rent_schedule (tenant_id, month, expected_rent, due_date, status)
         VALUES (:tenant_id, :month, :expected_rent, :due_date, :status)
         ON DUPLICATE KEY UPDATE expected_rent = VALUES(expected_rent), status = VALUES(status), updated_at = CURRENT_TIMESTAMP'
    );

    $paymentStmt = $pdo->prepare(
        'INSERT INTO payments (tenant_id, amount_paid, payment_date, month, month_recorded, payment_status, payment_channel, reference_no, recorded_by)
         VALUES (:tenant_id, :amount_paid, :payment_date, :month, :month_recorded, :payment_status, :payment_channel, :reference_no, :recorded_by)'
    );

    while (($row = fgetcsv($handle)) !== false) {
        if ($row === [null] || count($row) < count($requiredHeaders)) {
            continue;
        }

        $data = array_combine($requiredHeaders, $row);
        if ($data === false) {
            continue;
        }

        $propertyName = trim((string) $data['Property_Name']);
        $unitNumber = trim((string) $data['Unit_Number']);
        $tenantName = trim((string) $data['Tenant_Name']);
        $tenantEmail = strtolower(trim((string) $data['Tenant_Email']));
        $tenantPhone = trim((string) $data['Tenant_Phone']);
        $monthlyRent = (float) $data['Monthly_Rent'];

        $leaseStartDate = $parseCsvDate((string) $data['Lease_Start']);
        $paymentDate = $parseCsvDate((string) $data['Payment_Date']);
        $paymentDateObj = new DateTimeImmutable($paymentDate);
        $amountPaid = (float) $data['Amount_Paid'];

        $expectedRent = $monthlyRent;
        $balance = max(0, $expectedRent - $amountPaid);
        $status = $amountPaid >= $expectedRent ? 'paid' : ($amountPaid > 0 ? 'partial' : 'unpaid');
        $statusLabel = ucfirst($status);
        $statusColor = $amountPaid <= 0 ? 'Red' : ($balance <= 0 ? 'Green' : 'Yellow');

        $monthKey = $paymentDateObj->modify('first day of this month')->format('Y-m-d');
        $monthRecorded = $paymentDateObj->format('F Y');
        $paymentTiming = ((int) $paymentDateObj->format('j') > 10) ? 'Late' : 'On Time';

        $propertyStmt->execute(['name' => $propertyName, 'location' => 'Not specified']);
        $propertyId = (int) $pdo->lastInsertId();

        $unitStmt->execute([
            'property_id' => $propertyId,
            'unit_number' => $unitNumber,
            'status' => 'occupied',
        ]);
        $unitId = (int) $pdo->lastInsertId();

        $tenantFindStmt->execute(['email' => $tenantEmail]);
        $tenantId = (int) ($tenantFindStmt->fetch()['id'] ?? 0);

        if ($tenantId === 0) {
            $tenantInsertStmt->execute([
                'name' => $tenantName,
                'phone' => $tenantPhone,
                'email' => $tenantEmail,
                'tenant_phone' => $tenantPhone,
                'tenant_email' => $tenantEmail,
                'status' => 'active',
            ]);
            $tenantId = (int) $pdo->lastInsertId();
        } else {
            $tenantUpdateStmt->execute([
                'id' => $tenantId,
                'name' => $tenantName,
                'phone' => $tenantPhone,
                'email' => $tenantEmail,
                'tenant_phone' => $tenantPhone,
                'tenant_email' => $tenantEmail,
                'status' => 'active',
            ]);
        }

        $leaseStmt->execute([
            'tenant_id' => $tenantId,
            'unit_id' => $unitId,
            'rent_amount' => $monthlyRent,
            'start_date' => $leaseStartDate,
            'status' => 'active',
        ]);

        $dueDate = $paymentDateObj->setDate((int) $paymentDateObj->format('Y'), (int) $paymentDateObj->format('m'), 10)->format('Y-m-d');
        $rentScheduleStmt->execute([
            'tenant_id' => $tenantId,
            'month' => $monthKey,
            'expected_rent' => $expectedRent,
            'due_date' => $dueDate,
            'status' => $status,
        ]);

        $paymentStmt->execute([
            'tenant_id' => $tenantId,
            'amount_paid' => $amountPaid,
            'payment_date' => $paymentDate,
            'month' => $monthKey,
            'month_recorded' => $monthRecorded,
            'payment_status' => $paymentTiming,
            'payment_channel' => 'bank_transfer',
            'reference_no' => $paymentTiming,
            'recorded_by' => $_SESSION['finance_manager_id'] ?? null,
        ]);

        $rowsProcessed++;
        $resultPreview[] = [
            'property' => $propertyName,
            'unit' => $unitNumber,
            'tenant' => $tenantName,
            'expected_rent' => formatKsh($expectedRent),
            'amount_paid' => formatKsh($amountPaid),
            'balance' => formatKsh($balance),
            'status' => $statusLabel,
            'status_color' => $statusColor,
            'payment_status' => $paymentTiming,
            'month_recorded' => $monthRecorded,
        ];
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo 'Import failed: ' . $e->getMessage();
    exit;
} finally {
    fclose($handle);
}

$dashboardSummary = $pdo->query(
    'SELECT
        COALESCE(SUM(rs.expected_rent), 0) AS total_expected,
        COALESCE(SUM(paid.total_paid), 0) AS total_paid
     FROM rent_schedule rs
     LEFT JOIN (
        SELECT tenant_id, month, SUM(amount_paid) AS total_paid
        FROM payments
        GROUP BY tenant_id, month
     ) paid ON paid.tenant_id = rs.tenant_id AND paid.month = rs.month'
)->fetch();

$totalExpected = (float) ($dashboardSummary['total_expected'] ?? 0);
$totalPaid = (float) ($dashboardSummary['total_paid'] ?? 0);
$totalArrears = max(0, $totalExpected - $totalPaid);

header('Content-Type: application/json');
echo json_encode([
    'message' => 'CSV import completed successfully.',
    'rows_processed' => $rowsProcessed,
    'preview' => $resultPreview,
    'dashboard_totals' => [
        'expected' => formatKsh($totalExpected),
        'paid' => formatKsh($totalPaid),
        'arrears' => formatKsh($totalArrears),
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
