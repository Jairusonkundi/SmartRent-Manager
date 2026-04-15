<?php

declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

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

try {
    $pdo->beginTransaction();

    $propertyStmt = $pdo->prepare('INSERT INTO properties (name, location) VALUES (:name, :location) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), updated_at = CURRENT_TIMESTAMP');
    $unitStmt = $pdo->prepare('INSERT INTO units (property_id, unit_number, status) VALUES (:property_id, :unit_number, :status) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), status = VALUES(status), updated_at = CURRENT_TIMESTAMP');
    $tenantFindStmt = $pdo->prepare('SELECT id FROM tenants WHERE email = :email LIMIT 1');
    $tenantInsertStmt = $pdo->prepare('INSERT INTO tenants (name, phone, email, status) VALUES (:name, :phone, :email, :status)');
    $tenantUpdateStmt = $pdo->prepare('UPDATE tenants SET name = :name, phone = :phone, status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');

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
        'INSERT INTO payments (tenant_id, amount_paid, payment_date, month, payment_channel, reference_no, recorded_by)
         VALUES (:tenant_id, :amount_paid, :payment_date, :month, :payment_channel, :reference_no, :recorded_by)'
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
        $leaseStart = (new DateTimeImmutable((string) $data['Lease_Start']))->format('Y-m-d');
        $paymentDateObj = new DateTimeImmutable((string) $data['Payment_Date']);
        $paymentDate = $paymentDateObj->format('Y-m-d');
        $amountPaid = (float) $data['Amount_Paid'];

        $expectedRent = $monthlyRent;
        $balance = $expectedRent - $amountPaid;
        $status = $amountPaid >= $expectedRent ? 'paid' : ($amountPaid > 0 ? 'partial' : 'unpaid');
        $statusLabel = ucfirst($status);
        $monthKey = $paymentDateObj->modify('first day of this month')->format('Y-m-d');
        $quarter = 'Q' . (string) ceil(((int) $paymentDateObj->format('n')) / 3);
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
                'status' => 'active',
            ]);
            $tenantId = (int) $pdo->lastInsertId();
        } else {
            $tenantUpdateStmt->execute([
                'id' => $tenantId,
                'name' => $tenantName,
                'phone' => $tenantPhone,
                'status' => 'active',
            ]);
        }

        $leaseStmt->execute([
            'tenant_id' => $tenantId,
            'unit_id' => $unitId,
            'rent_amount' => $monthlyRent,
            'start_date' => $leaseStart,
            'status' => 'active',
        ]);

        $dueDate = $paymentDateObj->modify('first day of this month')->setDate((int) $paymentDateObj->format('Y'), (int) $paymentDateObj->format('m'), 10)->format('Y-m-d');
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
            'payment_channel' => 'bank_transfer',
            'reference_no' => "{$paymentTiming} {$quarter}",
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
            'month' => $paymentDateObj->format('F Y'),
            'quarter' => $quarter,
            'payment_status' => $paymentTiming,
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

header('Content-Type: application/json');
echo json_encode([
    'message' => 'CSV import completed successfully.',
    'rows_processed' => $rowsProcessed,
    'preview' => $resultPreview,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
