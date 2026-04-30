<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$pdo = Database::connection();

$sql = "
UPDATE rent_schedule rs
LEFT JOIN (
  SELECT tenant_id, month, SUM(amount_paid) AS total_paid
  FROM payments
  GROUP BY tenant_id, month
) p ON p.tenant_id = rs.tenant_id AND p.month = rs.month
SET rs.status = CASE
  WHEN COALESCE(p.total_paid,0) >= rs.expected_rent THEN 'paid'
  WHEN COALESCE(p.total_paid,0) > 0 THEN 'partial'
  ELSE 'unpaid'
END
WHERE rs.month <= CURRENT_DATE
";

$pdo->exec($sql);
echo "Arrears status refreshed" . PHP_EOL;
