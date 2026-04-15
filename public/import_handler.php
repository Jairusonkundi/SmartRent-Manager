<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../modules/DashboardService.php';
require_once __DIR__ . '/db_connect.php';

requireAuth();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_FILES['monthly_csv']) || (int) $_FILES['monthly_csv']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please upload a valid CSV file.']);
    exit;
}

$requiredColumns = [
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

function normalizeCsvDate(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d', $timestamp);
}

function paymentStatusByBalance(float $balance, float $amountPaid): string
{
    if ($amountPaid <= 0.0) {
        return 'unpaid';
    }

    return $balance <= 0.0 ? 'paid' : 'partial';
}

$pdo = dbConnection();
$service = new DashboardService();
$filePath = (string) $_FILES['monthly_csv']['tmp_name'];
$handle = fopen($filePath, 'rb');

if ($handle === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to read CSV upload.']);
    exit;
}

$header = fgetcsv($handle);
if ($header === false) {
    fclose($handle);
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'CSV is empty.']);
    exit;
}

$columnMap = [];
foreach ($header as $index => $columnName) {
    $columnMap[trim((string) $columnName)] = $index;
}

foreach ($requiredColumns as $column) {
    if (!array_key_exists($column, $columnMap)) {
        fclose($handle);
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => sprintf('Missing CSV column: %s', $column)]);
        exit;
    }
}

$tenantEmailColumnExists = (bool) $pdo->query("SHOW COLUMNS FROM tenants LIKE 'tenant_email'")->fetch();
$tenantPhoneColumnExists = (bool) $pdo->query("SHOW COLUMNS FROM tenants LIKE 'tenant_phone'")->fetch();
$paymentStatusColumnExists = (bool) $pdo->query("SHOW COLUMNS FROM payments LIKE 'payment_status'")->fetch();

try {
    $pdo->beginTransaction();

    $propertyLookup = $pdo->prepare('SELECT id FROM properties WHERE name = :name LIMIT 1');
    $propertyInsert = $pdo->prepare('INSERT INTO properties (name, location) VALUES (:name, :location)');

    $unitLookup = $pdo->prepare('SELECT id FROM units WHERE property_id = :property_id AND unit_number = :unit_number LIMIT 1');
    $unitInsert = $pdo->prepare('INSERT INTO units (property_id, unit_number, status) VALUES (:property_id, :unit_number, :status)');

    $tenantLookup = $pdo->prepare('SELECT id FROM tenants WHERE name = :name LIMIT 1');
    $tenantInsert = $pdo->prepare('INSERT INTO tenants (name, phone, email, status) VALUES (:name, :phone, :email, :status)');

    $tenantUpdateSql = 'UPDATE tenants SET phone = :phone, email = :email';
    if ($tenantEmailColumnExists) {
        $tenantUpdateSql .= ', tenant_email = :tenant_email';
    }
    if ($tenantPhoneColumnExists) {
        $tenantUpdateSql .= ', tenant_phone = :tenant_phone';
    }
    $tenantUpdateSql .= ' WHERE id = :id';
    $tenantUpdate = $pdo->prepare($tenantUpdateSql);

    $leaseUpsert = $pdo->prepare(
        'INSERT INTO leases (tenant_id, unit_id, rent_amount, start_date, status)
         VALUES (:tenant_id, :unit_id, :rent_amount, :start_date, :status)
         ON DUPLICATE KEY UPDATE rent_amount = VALUES(rent_amount), status = VALUES(status)'
    );

    $rentScheduleUpsert = $pdo->prepare(
        'INSERT INTO rent_schedule (tenant_id, month, expected_rent, due_date, status)
         VALUES (:tenant_id, :month, :expected_rent, :due_date, :status)
         ON DUPLICATE KEY UPDATE expected_rent = VALUES(expected_rent), due_date = VALUES(due_date), status = VALUES(status)'
    );

    if ($paymentStatusColumnExists) {
        $paymentInsert = $pdo->prepare(
            'INSERT INTO payments (tenant_id, amount_paid, payment_date, month, payment_channel, payment_status)
             VALUES (:tenant_id, :amount_paid, :payment_date, :month, :payment_channel, :payment_status)'
        );
    } else {
        $paymentInsert = $pdo->prepare(
            'INSERT INTO payments (tenant_id, amount_paid, payment_date, month, payment_channel)
             VALUES (:tenant_id, :amount_paid, :payment_date, :month, :payment_channel)'
        );
    }

    $rowsImported = 0;
    $latestMonth = date('Y-m-01');

    while (($row = fgetcsv($handle)) !== false) {
        $propertyName = trim((string) ($row[$columnMap['Property_Name']] ?? ''));
        $unitNumber = trim((string) ($row[$columnMap['Unit_Number']] ?? ''));
        $tenantName = trim((string) ($row[$columnMap['Tenant_Name']] ?? ''));
        $tenantEmail = trim((string) ($row[$columnMap['Tenant_Email']] ?? ''));
        $tenantPhone = trim((string) ($row[$columnMap['Tenant_Phone']] ?? ''));
        $monthlyRent = (float) ($row[$columnMap['Monthly_Rent']] ?? 0);
        $leaseStart = normalizeCsvDate((string) ($row[$columnMap['Lease_Start']] ?? ''));
        $paymentDate = normalizeCsvDate((string) ($row[$columnMap['Payment_Date']] ?? ''));
        $amountPaid = (float) ($row[$columnMap['Amount_Paid']] ?? 0);

        if ($propertyName === '' || $unitNumber === '' || $tenantName === '' || $leaseStart === null || $paymentDate === null) {
            continue;
        }

        $month = date('Y-m-01', strtotime($paymentDate));
        $latestMonth = max($latestMonth, $month);

        $propertyLookup->execute(['name' => $propertyName]);
        $propertyId = $propertyLookup->fetchColumn();
        if ($propertyId === false) {
            $propertyInsert->execute([
                'name' => $propertyName,
                'location' => 'Unspecified',
            ]);
            $propertyId = (int) $pdo->lastInsertId();
        }

        $unitLookup->execute([
            'property_id' => $propertyId,
            'unit_number' => $unitNumber,
        ]);
        $unitId = $unitLookup->fetchColumn();
        if ($unitId === false) {
            $unitInsert->execute([
                'property_id' => $propertyId,
                'unit_number' => $unitNumber,
                'status' => 'occupied',
            ]);
            $unitId = (int) $pdo->lastInsertId();
        }

        $tenantLookup->execute(['name' => $tenantName]);
        $tenantId = $tenantLookup->fetchColumn();
        if ($tenantId === false) {
            $tenantInsert->execute([
                'name' => $tenantName,
                'phone' => $tenantPhone !== '' ? $tenantPhone : 'N/A',
                'email' => $tenantEmail !== '' ? $tenantEmail : null,
                'status' => 'active',
            ]);
            $tenantId = (int) $pdo->lastInsertId();
        }

        $tenantUpdateParams = [
            'phone' => $tenantPhone !== '' ? $tenantPhone : 'N/A',
            'email' => $tenantEmail !== '' ? $tenantEmail : null,
            'id' => $tenantId,
        ];
        if ($tenantEmailColumnExists) {
            $tenantUpdateParams['tenant_email'] = $tenantEmail !== '' ? $tenantEmail : null;
        }
        if ($tenantPhoneColumnExists) {
            $tenantUpdateParams['tenant_phone'] = $tenantPhone !== '' ? $tenantPhone : 'N/A';
        }
        $tenantUpdate->execute($tenantUpdateParams);

        $leaseUpsert->execute([
            'tenant_id' => $tenantId,
            'unit_id' => $unitId,
            'rent_amount' => $monthlyRent,
            'start_date' => $leaseStart,
            'status' => 'active',
        ]);

        $balance = max($monthlyRent - $amountPaid, 0);
        $rentStatus = paymentStatusByBalance($balance, $amountPaid);

        $rentScheduleUpsert->execute([
            'tenant_id' => $tenantId,
            'month' => $month,
            'expected_rent' => $monthlyRent,
            'due_date' => date('Y-m-d', strtotime($month . ' +9 days')),
            'status' => $rentStatus,
        ]);

        $paymentDay = (int) date('d', strtotime($paymentDate));
        $paymentStatus = $paymentDay > 10 ? 'Late' : 'On Time';

        $paymentParams = [
            'tenant_id' => $tenantId,
            'amount_paid' => $amountPaid,
            'payment_date' => $paymentDate,
            'month' => $month,
            'payment_channel' => 'bank_transfer',
        ];
        if ($paymentStatusColumnExists) {
            $paymentParams['payment_status'] = $paymentStatus;
        }
        $paymentInsert->execute($paymentParams);

        $rowsImported++;
    }

    fclose($handle);
    $pdo->commit();

    $summary = $service->summary($latestMonth);
    $trend = $service->monthlyTrend();
    $distribution = $service->paymentStatusDistribution($latestMonth);

    echo json_encode([
        'success' => true,
        'message' => sprintf('Imported %d record(s) successfully.', $rowsImported),
        'month' => $latestMonth,
        'summary' => $summary,
        'trend' => $trend,
        'distribution' => $distribution,
    ]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fclose($handle);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Import failed: ' . $exception->getMessage(),
    ]);
}
