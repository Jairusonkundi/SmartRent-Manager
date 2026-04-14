<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireAuth();
$pdo = Database::connection();
$tenants = $pdo->query(
    "SELECT t.id, t.name, t.phone, t.email, u.unit_number, p.name AS property_name
     FROM tenants t
     LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
     LEFT JOIN units u ON u.id = l.unit_id
     LEFT JOIN properties p ON p.id = u.property_id
     WHERE t.status='active'
     ORDER BY t.name"
)->fetchAll();

renderHeader('Tenants');
?>
<article class="card">
    <h3>Active Tenants</h3>
    <table class="sortable">
        <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Property</th><th>Unit</th></tr></thead>
        <tbody>
        <?php foreach ($tenants as $tenant): ?>
            <tr>
                <td><?= h($tenant['name']) ?></td>
                <td><?= h($tenant['phone']) ?></td>
                <td><?= h($tenant['email']) ?></td>
                <td><?= h($tenant['property_name']) ?></td>
                <td><?= h($tenant['unit_number']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</article>
<?php renderFooter(); ?>
