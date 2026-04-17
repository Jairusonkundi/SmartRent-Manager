<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../config/database.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /public/upload_csv.php');
    exit;
}

if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    setFlash('danger', 'Please select a valid CSV file to upload.');
    header('Location: /public/upload_csv.php');
    exit;
}

$csvPath = $_FILES['csv_file']['tmp_name'];
$handle = fopen($csvPath, 'rb');

if ($handle === false) {
    setFlash('danger', 'Unable to read the uploaded file.');
    header('Location: /public/upload_csv.php');
    exit;
}

$headers = fgetcsv($handle);
if ($headers === false) {
    fclose($handle);
    setFlash('danger', 'The CSV appears to be empty.');
    header('Location: /public/upload_csv.php');
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

$headerMap = [];
foreach ($headers as $idx => $header) {
    $headerMap[trim((string) $header)] = $idx;
}

foreach ($requiredHeaders as $requiredHeader) {
    if (!array_key_exists($requiredHeader, $headerMap)) {
        fclose($handle);
        setFlash('danger', "Missing required CSV header: {$requiredHeader}");
        header('Location: /public/upload_csv.php');
        exit;
    }
}

$pdo = Database::connection();
$processed = 0;

$insertProperty = $pdo->prepare(
    'INSERT INTO properties (name, location) VALUES (:name, :location)
     ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), name = VALUES(name)'
);
$insertUnit = $pdo->prepare(
    'INSERT INTO units (property_id, unit_number, status) VALUES (:property_id, :unit_number, :status)
     ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), status = VALUES(status)'
);
$findTenant = $pdo->prepare('SELECT id FROM tenants WHERE name = :name LIMIT 1');
$insertTenant = $pdo->prepare(
    'INSERT INTO tenants (name, phone, email, tenant_phone, tenant_email, status)
     VALUES (:name, :phone, :email, :tenant_phone, :tenant_email, :status)'
);
$updateTenant = $pdo->prepare(
    'UPDATE tenants
        SET phone = :phone,
            email = :email,
            tenant_phone = :tenant_phone,
            tenant_email = :tenant_email,
            status = :status
      WHERE id = :id'
);
$insertLease = $pdo->prepare(
    'INSERT INTO leases (tenant_id, unit_id, rent_amount, start_date, status)
     VALUES (:tenant_id, :unit_id, :rent_amount, :start_date, :status)
     ON DUPLICATE KEY UPDATE rent_amount = VALUES(rent_amount), status = VALUES(status)'
);
$insertRentSchedule = $pdo->prepare(
    'INSERT INTO rent_schedule (tenant_id, month, expected_rent, due_date, status)
     VALUES (:tenant_id, :month, :expected_rent, :due_date, :status)
     ON DUPLICATE KEY UPDATE expected_rent = VALUES(expected_rent), status = VALUES(status)'
);
$insertPayment = $pdo->prepare(
    'INSERT INTO payments (tenant_id, monthly_rent, amount_paid, payment_date, month, payment_status)
     VALUES (:tenant_id, :monthly_rent, :amount_paid, :payment_date, :month, :payment_status)'
);

try {
    $pdo->beginTransaction();

    while (($row = fgetcsv($handle)) !== false) {
        if (count(array_filter($row, static fn ($value): bool => trim((string) $value) !== '')) === 0) {
            continue;
        }

        $propertyName = trim((string) ($row[$headerMap['Property_Name']] ?? ''));
        $unitNumber = trim((string) ($row[$headerMap['Unit_Number']] ?? ''));
        $tenantName = trim((string) ($row[$headerMap['Tenant_Name']] ?? ''));
        $tenantEmail = trim((string) ($row[$headerMap['Tenant_Email']] ?? ''));
        $tenantPhone = trim((string) ($row[$headerMap['Tenant_Phone']] ?? ''));
        $monthlyRentRaw = trim((string) ($row[$headerMap['Monthly_Rent']] ?? '0'));
        $leaseStartRaw = trim((string) ($row[$headerMap['Lease_Start']] ?? ''));
        $paymentDateRaw = trim((string) ($row[$headerMap['Payment_Date']] ?? ''));
        $amountPaidRaw = trim((string) ($row[$headerMap['Amount_Paid']] ?? '0'));

        $monthlyRentNumeric = preg_replace('/[^\d.\-]/', '', str_replace(',', '', $monthlyRentRaw)) ?: '0';
        $amountPaidNumeric = preg_replace('/[^\d.\-]/', '', str_replace(',', '', $amountPaidRaw)) ?: '0';
        $monthlyRent = (float) $monthlyRentNumeric;
        $amountPaid = (float) $amountPaidNumeric;

        if ($propertyName === '' || $unitNumber === '' || $tenantName === '' || $monthlyRent <= 0) {
            continue;
        }

        $leaseStartTimestamp = strtotime($leaseStartRaw);
        $leaseStart = $leaseStartTimestamp !== false ? date('Y-m-d', $leaseStartTimestamp) : date('Y-m-d');

        $paymentTimestamp = strtotime($paymentDateRaw);
        $paymentDate = $paymentTimestamp !== false ? date('Y-m-d', $paymentTimestamp) : $leaseStart;

        $month = date('Y-m-01', strtotime($paymentDate));
        $dueDate = date('Y-m-10', strtotime($paymentDate));
        $paymentStatus = ((int) date('d', strtotime($paymentDate)) > 10) ? 'Late' : 'On Time';

        // Normalize imported values to decimal strings so DB DECIMAL math remains reliable.
        $monthlyRentDecimal = number_format($monthlyRent, 2, '.', '');
        $amountPaidDecimal = number_format($amountPaid, 2, '.', '');
        $balance = (float) $monthlyRentDecimal - (float) $amountPaidDecimal;
        $scheduleStatus = 'unpaid';
        if ($balance <= 0) {
            $scheduleStatus = 'paid';
        } elseif ($amountPaid > 0) {
            $scheduleStatus = 'partial';
        }

        $insertProperty->execute([
            'name' => $propertyName,
            'location' => 'Unspecified',
        ]);
        $propertyId = (int) $pdo->lastInsertId();

        $insertUnit->execute([
            'property_id' => $propertyId,
            'unit_number' => $unitNumber,
            'status' => 'occupied',
        ]);
        $unitId = (int) $pdo->lastInsertId();

        $findTenant->execute(['name' => $tenantName]);
        $tenantId = (int) ($findTenant->fetchColumn() ?: 0);

        if ($tenantId > 0) {
            $updateTenant->execute([
                'id' => $tenantId,
                'phone' => $tenantPhone,
                'email' => $tenantEmail,
                'tenant_phone' => $tenantPhone,
                'tenant_email' => $tenantEmail,
                'status' => 'active',
            ]);
        } else {
            $insertTenant->execute([
                'name' => $tenantName,
                'phone' => $tenantPhone,
                'email' => $tenantEmail,
                'tenant_phone' => $tenantPhone,
                'tenant_email' => $tenantEmail,
                'status' => 'active',
            ]);
            $tenantId = (int) $pdo->lastInsertId();
        }

        $insertLease->execute([
            'tenant_id' => $tenantId,
            'unit_id' => $unitId,
            'rent_amount' => $monthlyRentDecimal,
            'start_date' => $leaseStart,
            'status' => 'active',
        ]);

        $insertRentSchedule->execute([
            'tenant_id' => $tenantId,
            'month' => $month,
            'expected_rent' => $monthlyRentDecimal,
            'due_date' => $dueDate,
            'status' => $scheduleStatus,
        ]);

        $insertPayment->execute([
            'tenant_id' => $tenantId,
            'monthly_rent' => $monthlyRentDecimal,
            'amount_paid' => $amountPaidDecimal,
            'payment_date' => $paymentDate,
            'month' => $month,
            'payment_status' => $paymentStatus,
        ]);

        $processed++;
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fclose($handle);
    setFlash('danger', 'Error importing data.');
    header('Location: /public/upload_csv.php');
    exit;
}

fclose($handle);
setFlash('success', 'Data imported successfully!');
header('Location: /public/dashboard.php');
exit;
