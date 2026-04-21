<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../modules/TenantService.php';
require_once __DIR__ . '/../modules/PropertyService.php';

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
];

$monthColumnMap = [
    'Jan_Paid' => '2026-01',
    'Feb_Paid' => '2026-02',
    'Mar_Paid' => '2026-03',
    'Apr_Paid' => '2026-04',
    'May_Paid' => '2026-05',
    'Jun_Paid' => '2026-06',
    'Jul_Paid' => '2026-07',
    'Aug_Paid' => '2026-08',
    'Sep_Paid' => '2026-09',
    'Oct_Paid' => '2026-10',
    'Nov_Paid' => '2026-11',
    'Dec_Paid' => '2026-12',
];

$headerMap = [];
foreach ($headers as $idx => $header) {
    $normalizedHeader = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', trim((string) $header));
    if ($normalizedHeader === null || $normalizedHeader == '') {
        continue;
    }

    $headerMap[$normalizedHeader] = $idx;
}

foreach ($requiredHeaders as $requiredHeader) {
    if (!array_key_exists($requiredHeader, $headerMap)) {
        fclose($handle);
        setFlash('danger', "Missing required CSV header: {$requiredHeader}");
        header('Location: /public/upload_csv.php');
        exit;
    }
}

foreach (array_keys($monthColumnMap) as $requiredHeader) {
    if (!array_key_exists($requiredHeader, $headerMap)) {
        fclose($handle);
        setFlash('danger', "Missing required CSV header: {$requiredHeader}");
        header('Location: /public/upload_csv.php');
        exit;
    }
}

$pdo = Database::connection();
$tenantService = new TenantService();
$propertyService = new PropertyService();
$processed = 0;

$insertLease = $pdo->prepare(
    'INSERT INTO leases (tenant_id, unit_id, rent_amount, start_date, status)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE rent_amount = VALUES(rent_amount), status = VALUES(status)'
);
$insertPayment = $pdo->prepare(
    'INSERT INTO payments (tenant_id, billing_month, amount_expected, monthly_rent, amount_paid, payment_date, month, collection_status, payment_status, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

try {
    $pdo->beginTransaction();

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec('TRUNCATE TABLE payments');
    $pdo->exec('TRUNCATE TABLE rent_schedule');
    $pdo->exec('TRUNCATE TABLE leases');
    $pdo->exec('TRUNCATE TABLE tenants');
    $pdo->exec('TRUNCATE TABLE units');
    $pdo->exec('TRUNCATE TABLE properties');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

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
        $monthlyRentNumeric = preg_replace('/[^\d.\-]/', '', str_replace(',', '', $monthlyRentRaw)) ?: '0';
        $monthlyRent = (float) $monthlyRentNumeric;

        if ($propertyName === '' || $unitNumber === '' || $tenantName === '' || $monthlyRent <= 0) {
            continue;
        }

        $leaseStartTimestamp = strtotime($leaseStartRaw);
        $leaseStart = $leaseStartTimestamp !== false ? date('Y-m-d', $leaseStartTimestamp) : date('Y-m-d');

        $monthlyRentDecimal = number_format($monthlyRent, 2, '.', '');

        $propertyId = $propertyService->upsertProperty($propertyName, 'Unspecified');
        $unitId = $propertyService->upsertUnit($propertyId, $unitNumber, 'occupied');
        $tenantId = $tenantService->upsertTenant($tenantName, $tenantPhone, $tenantEmail);

        $insertLease->execute([$tenantId, $unitId, $monthlyRentDecimal, $leaseStart, 'active']);

        foreach ($monthColumnMap as $monthHeader => $billingMonth) {
            $amountPaidRaw = trim((string) ($row[$headerMap[$monthHeader]] ?? '0'));
            $amountPaidNumeric = preg_replace('/[^\d.\-]/', '', str_replace(',', '', $amountPaidRaw)) ?: '0';
            $amountPaid = (float) $amountPaidNumeric;
            $amountPaidDecimal = number_format(max($amountPaid, 0), 2, '.', '');

            $month = $billingMonth . '-01';
            $paymentDate = $month . '-10';
            $collectionStatus = ((float) $amountPaidDecimal >= (float) $monthlyRentDecimal)
                ? 'Paid'
                : (((float) $amountPaidDecimal > 0) ? 'Partial' : 'Unpaid');
            $paymentTimingStatus = 'On Time';

            $tenantService->ensureCurrentMonthBilling($tenantId, (float) $monthlyRentDecimal, $paymentDate);

            $insertPayment->execute([
                $tenantId,
                $billingMonth,
                $monthlyRentDecimal,
                $monthlyRentDecimal,
                $amountPaidDecimal,
                $paymentDate,
                $month,
                $collectionStatus,
                $paymentTimingStatus,
                $paymentTimingStatus,
            ]);
        }

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
