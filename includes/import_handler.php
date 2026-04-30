<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../modules/TenantService.php';
require_once __DIR__ . '/../modules/PropertyService.php';

requireAuth();

$isAjaxRequest = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

$respond = static function (string $status, string $message, string $redirect) use ($isAjaxRequest): void {
    if ($isAjaxRequest) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => $status,
            'message' => $message,
            'redirect' => $redirect,
        ]);
        exit;
    }

    setFlash($status === 'success' ? 'success' : 'danger', $message);
    header("Location: {$redirect}");
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond('error', 'Invalid request method.', '/public/upload_csv.php');
}

if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    $respond('error', 'Please select a valid CSV file to upload.', '/public/upload_csv.php');
}

$csvPath = $_FILES['csv_file']['tmp_name'];
$handle = fopen($csvPath, 'rb');

if ($handle === false) {
    $respond('error', 'Unable to read the uploaded file.', '/public/upload_csv.php');
}

$headers = fgetcsv($handle);
if ($headers === false) {
    fclose($handle);
    $respond('error', 'The CSV appears to be empty.', '/public/upload_csv.php');
}

$requiredHeaders = [
    'Property_Name',
    'Unit_Number',
    'Tenant_Name',
    'Tenant_Email',
    'Tenant_Phone',
    'Monthly_Rent',
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
    if ($normalizedHeader === null || $normalizedHeader === '') {
        continue;
    }

    $headerMap[$normalizedHeader] = $idx;
}

foreach ($requiredHeaders as $requiredHeader) {
    if (!array_key_exists($requiredHeader, $headerMap)) {
        fclose($handle);
        $respond('error', "Missing required CSV header: {$requiredHeader}", '/public/upload_csv.php');
    }
}

foreach (array_keys($monthColumnMap) as $requiredHeader) {
    if (!array_key_exists($requiredHeader, $headerMap)) {
        fclose($handle);
        $respond('error', "Missing required CSV header: {$requiredHeader}", '/public/upload_csv.php');
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
$insertSchedule = $pdo->prepare(
    'INSERT INTO rent_schedule (tenant_id, month, expected_rent, due_date, status)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE expected_rent = VALUES(expected_rent), due_date = VALUES(due_date), status = VALUES(status)'
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
        if (count(array_filter($row, static fn($value): bool => trim((string) $value) !== '')) === 0) {
            continue;
        }

        $propertyName = trim((string) ($row[$headerMap['Property_Name']] ?? ''));
        $unitNumber = trim((string) ($row[$headerMap['Unit_Number']] ?? ''));
        $tenantName = trim((string) ($row[$headerMap['Tenant_Name']] ?? ''));
        $tenantEmail = trim((string) ($row[$headerMap['Tenant_Email']] ?? ''));
        $tenantPhone = trim((string) ($row[$headerMap['Tenant_Phone']] ?? ''));
        $monthlyRentRaw = trim((string) ($row[$headerMap['Monthly_Rent']] ?? '0'));
        $monthlyRentNumeric = preg_replace('/[^\d.\-]/', '', str_replace(',', '', $monthlyRentRaw)) ?: '0';
        $monthlyRent = (float) $monthlyRentNumeric;

        if ($propertyName === '' || $unitNumber === '' || $tenantName === '' || $monthlyRent <= 0) {
            continue;
        }

        $leaseStart = date('Y-m-d');
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

            $monthDate = $billingMonth . '-01';
            $paymentDate = $billingMonth . '-10';
            $collectionStatus = ((float) $amountPaidDecimal >= (float) $monthlyRentDecimal)
                ? 'Paid'
                : (((float) $amountPaidDecimal > 0) ? 'Partial' : 'Unpaid');

            $insertSchedule->execute([
                $tenantId,
                $monthDate,
                $monthlyRentDecimal,
                $paymentDate,
                strtolower($collectionStatus),
            ]);

            $insertPayment->execute([
                $tenantId,
                $billingMonth,
                $monthlyRentDecimal,
                $monthlyRentDecimal,
                $amountPaidDecimal,
                $paymentDate,
                $monthDate,
                $collectionStatus,
                'On Time',
                'On Time',
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
    $respond('error', 'Error importing data.', '/public/upload_csv.php');
}

fclose($handle);
$respond('success', 'Data imported successfully!', '/public/dashboard.php');
