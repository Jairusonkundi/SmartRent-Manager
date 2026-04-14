<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$pdo = Database::connection();
$month = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
$dueDate = (new DateTimeImmutable($month))->setDate((int) date('Y'), (int) date('m'), 10)->format('Y-m-d');

$sql = "
INSERT INTO rent_schedule (tenant_id, month, expected_rent, due_date)
SELECT l.tenant_id, :month, l.rent_amount, :due_date
FROM leases l
JOIN tenants t ON t.id = l.tenant_id
WHERE l.status = 'active'
  AND t.status = 'active'
  AND :month BETWEEN l.start_date AND COALESCE(l.end_date, '9999-12-31')
ON DUPLICATE KEY UPDATE expected_rent = VALUES(expected_rent), due_date = VALUES(due_date)
";

$stmt = $pdo->prepare($sql);
$stmt->execute(['month' => $month, 'due_date' => $dueDate]);

echo "Rent schedule generated for {$month}" . PHP_EOL;
