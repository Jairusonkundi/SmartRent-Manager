<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireAuth();

$pdo    = Database::connection();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// ── Handle POST ────────────────────────────────────────────────────────────────
$formError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        $formError = 'Security token invalid. Please try again.';
    } else {
        $postTenantId     = filter_input(INPUT_POST, 'tenant_id',       FILTER_VALIDATE_INT);
        $postUnitId       = filter_input(INPUT_POST, 'unit_id',         FILTER_VALIDATE_INT);
        $postBillingMonth = trim((string) ($_POST['billing_month']     ?? ''));
        $postAmountPaid   = filter_input(INPUT_POST, 'amount_paid',     FILTER_VALIDATE_FLOAT);
        $postPaymentDate  = trim((string) ($_POST['payment_date']      ?? ''));
        $postChannel      = (string) ($_POST['payment_channel']        ?? 'bank_transfer');
        $postRefNo        = trim((string) ($_POST['reference_no']      ?? ''));
        $postAmountExp    = filter_input(INPUT_POST, 'amount_expected', FILTER_VALIDATE_FLOAT);

        $allowedChannels = ['bank_transfer', 'cash', 'cheque'];

        if (!$postTenantId || $postTenantId < 1) {
            $formError = 'Tenant could not be resolved. Please search and select a tenant.';
        } elseif (preg_match('/^\d{4}-\d{2}$/', $postBillingMonth) !== 1) {
            $formError = 'Please enter a valid billing month (YYYY-MM).';
        } elseif ($postAmountPaid === false || $postAmountPaid === null || $postAmountPaid < 0) {
            $formError = 'Please enter a valid amount paid (0 or more).';
        } elseif ($postPaymentDate === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $postPaymentDate) !== 1) {
            $formError = 'Please enter a valid payment date.';
        } elseif (!in_array($postChannel, $allowedChannels, true)) {
            $formError = 'Invalid payment channel.';
        } else {
            $tenantIdInt = (int) $postTenantId;
            $unitIdInt   = (int) ($postUnitId ?: 0);

            $amountExpected = $postAmountExp !== false && $postAmountExp !== null && $postAmountExp > 0
                ? (float) $postAmountExp
                : (float) $postAmountPaid;

            $amountPaidFloat  = (float) $postAmountPaid;
            $paymentDateDay = (int) date('j', strtotime($postPaymentDate));
            $paymentStatus  = $paymentDateDay > 10 ? 'Late' : 'On Time';
            $monthDate      = $postBillingMonth . '-01';
            $refNo          = $postRefNo !== '' ? $postRefNo : null;

            try {
                $pdo->beginTransaction();

                // Fetch prior totals for this tenant/month to compute cumulative state
                $prevStmt = $pdo->prepare(
                    'SELECT COALESCE(SUM(amount_expected), 0) AS prev_exp,
                            COALESCE(SUM(amount_paid), 0)     AS prev_paid
                     FROM payments WHERE tenant_id = ? AND billing_month = ?'
                );
                $prevStmt->execute([$tenantIdInt, $postBillingMonth]);
                $prevRow      = $prevStmt->fetch();
                $prevExpected = (float) $prevRow['prev_exp'];
                $prevPaid     = (float) $prevRow['prev_paid'];

                // Only the first payment row for a month carries amount_expected;
                // subsequent rows use 0 to avoid double-counting the obligation in SUM queries.
                $rowExpected = $prevExpected > 0 ? 0.0 : $amountExpected;

                $cumPaid = $prevPaid + $amountPaidFloat;
                if ($cumPaid >= $amountExpected && $amountExpected > 0) {
                    $collectionStatus = 'Paid';
                } elseif ($cumPaid > 0) {
                    $collectionStatus = 'Partial';
                } else {
                    $collectionStatus = 'Unpaid';
                }

                $pdo->prepare(
                    'INSERT INTO payments
                        (tenant_id, billing_month, amount_expected, monthly_rent, amount_paid,
                         payment_date, month, collection_status, payment_status,
                         payment_channel, reference_no, recorded_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $tenantIdInt,
                    $postBillingMonth,
                    $rowExpected,
                    $amountExpected,
                    $amountPaidFloat,
                    $postPaymentDate,
                    $monthDate,
                    $collectionStatus,
                    $paymentStatus,
                    $postChannel,
                    $refNo,
                    $userId,
                ]);

                // Sync rent_schedule.status for this tenant/month so the nightly cron
                // is never the only thing keeping the schedule consistent.
                // Affects 0 rows silently when no schedule row exists — that's fine.
                $pdo->prepare(
                    "UPDATE rent_schedule rs
                     JOIN (
                         SELECT COALESCE(SUM(amount_paid), 0) AS total_paid
                         FROM payments
                         WHERE tenant_id = ? AND billing_month = ?
                     ) p
                     SET rs.status = CASE
                         WHEN p.total_paid >= rs.expected_rent THEN 'paid'
                         WHEN p.total_paid > 0                 THEN 'partial'
                         ELSE 'unpaid'
                     END
                     WHERE rs.tenant_id = ? AND rs.month = ?"
                )->execute([$tenantIdInt, $postBillingMonth, $tenantIdInt, $monthDate]);

                // Bank any overpayment as credit for use against future months.
                $excess = $cumPaid > $amountExpected && $amountExpected > 0
                    ? round($cumPaid - $amountExpected, 2)
                    : 0.0;
                if ($excess > 0) {
                    $pdo->prepare('UPDATE tenants SET credit_balance = credit_balance + ? WHERE id = ?')
                        ->execute([$excess, $tenantIdInt]);
                }

                $pdo->commit();

                $cumulativeOutstanding = max(0.0, $amountExpected - $cumPaid);
                $flashMsg = sprintf(
                    'Payment recorded for %s — %s (outstanding: %s).',
                    $postBillingMonth,
                    $collectionStatus,
                    'KSh ' . number_format($cumulativeOutstanding, 2)
                );
                if ($excess > 0) {
                    $flashMsg .= sprintf(
                        ' KSh %s excess banked as credit — auto-applied next billing cycle.',
                        number_format($excess, 2)
                    );
                }
                setFlash('success', $flashMsg);
                header('Location: /public/post_payment.php');
                exit;

            } catch (\PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $formError = 'Database error — ' . $e->getMessage()
                    . ' (tenant_id=' . $tenantIdInt . ', month=' . $postBillingMonth . ')';
            }
        }
    }
}

// Retain POST values to repopulate the form if a DB error fires after validation passes.
$retainTenantId  = (int)   ($_POST['tenant_id']       ?? 0);
$retainUnitId    = (int)   ($_POST['unit_id']          ?? 0);
$retainAmountExp = (float) ($_POST['amount_expected']  ?? 0);

renderHeader('Post Payment');
?>

<section class="card budget-filter-card">
    <p class="muted-text">Post individual rent receipts or bank deposits. Search for a tenant by name,
       email, or phone — property, unit, and expected rent populate automatically.</p>
</section>

<!-- ── Step 1: Tenant Search ────────────────────────────────────────────────── -->
<section class="card">
    <h3>Step 1 — Find Tenant</h3>
    <div class="autocomplete-wrap">
        <input type="text" id="tenant_search" class="autocomplete-input"
               placeholder="Type name, email, or phone…" autocomplete="off">
        <div id="autocomplete_results" class="autocomplete-results" style="display:none"></div>
    </div>
    <div id="tenant_info" class="post-payment-tenant-info" style="display:none"></div>
</section>

<!-- ── Step 2: Payment Form ─────────────────────────────────────────────────── -->
<section class="card" id="payment_form_section" <?= $formError === null ? 'style="display:none"' : '' ?>>
    <h3>Step 2 — Post Payment</h3>
    <?php if ($formError !== null): ?>
        <div class="alert error"><?= h($formError) ?></div>
    <?php endif; ?>

    <form method="post" action="/public/post_payment.php" class="expense-form-grid" id="payment_form">
        <input type="hidden" name="csrf_token"      value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="tenant_id"       id="hidden_tenant_id"       value="<?= $retainTenantId  ?: '' ?>">
        <input type="hidden" name="unit_id"         id="hidden_unit_id"         value="<?= $retainUnitId    ?: '' ?>">
        <input type="hidden" name="amount_expected" id="hidden_amount_expected" value="<?= $retainAmountExp ?: '' ?>">

        <label>Reference Month <span class="required">*</span>
            <input type="month" name="billing_month" value="<?= h(date('Y-m')) ?>" required>
        </label>
        <label>Amount Paid (KSh) <span class="required">*</span>
            <input type="number" id="amount_paid_field" name="amount_paid"
                   min="0" step="0.01" placeholder="0.00" required
                   value="<?= $formError !== null && isset($_POST['amount_paid']) ? h((string) $_POST['amount_paid']) : '' ?>">
        </label>
        <label>Payment Date <span class="required">*</span>
            <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required>
        </label>
        <label>Payment Channel
            <select name="payment_channel">
                <option value="bank_transfer">Bank Transfer</option>
                <option value="cash">Cash</option>
                <option value="cheque">Cheque</option>
            </select>
        </label>
        <label>Reference / Receipt No. <em>(optional)</em>
            <input type="text" name="reference_no" placeholder="e.g. TXN-20250521-001">
        </label>

        <div class="control-actions" style="grid-column:1/-1; margin-top:8px;">
            <button type="submit" class="button">Post Payment</button>
            <button type="button" class="button button-secondary" id="clear_form_btn">Clear</button>
        </div>
    </form>
</section>

<script>
(function () {
    var searchInput     = document.getElementById('tenant_search');
    var resultsBox      = document.getElementById('autocomplete_results');
    var tenantInfo      = document.getElementById('tenant_info');
    var formSection     = document.getElementById('payment_form_section');
    var hiddenTenantId  = document.getElementById('hidden_tenant_id');
    var hiddenUnitId    = document.getElementById('hidden_unit_id');
    var hiddenAmountExp = document.getElementById('hidden_amount_expected');
    var amountPaidField = document.getElementById('amount_paid_field');
    var clearBtn        = document.getElementById('clear_form_btn');
    var debounceTimer   = null;

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function fmtKsh(amount) {
        return 'KSh ' + parseFloat(amount).toLocaleString('en-KE', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function hideResults() {
        resultsBox.style.display = 'none';
        resultsBox.innerHTML     = '';
    }

    function resetSelection() {
        hiddenTenantId.value      = '';
        hiddenUnitId.value        = '';
        hiddenAmountExp.value     = '';
        amountPaidField.value     = '';
        tenantInfo.style.display  = 'none';
        tenantInfo.innerHTML      = '';
        formSection.style.display = 'none';
    }

    function selectTenant(tenant) {
        hideResults();
        searchInput.value     = tenant.name;
        hiddenTenantId.value  = tenant.id;
        hiddenUnitId.value    = tenant.unit_id   || '';
        hiddenAmountExp.value = tenant.rent_amount || '';

        if (tenant.rent_amount) {
            amountPaidField.value = tenant.rent_amount;
        }

        var parts = ['<strong>' + escHtml(tenant.name) + '</strong>'];
        if (tenant.property_name) {
            parts.push('<strong>Property:</strong> ' + escHtml(tenant.property_name));
        }
        if (tenant.unit_number) {
            parts.push('<strong>Unit:</strong> ' + escHtml(tenant.unit_number));
        }
        if (tenant.rent_amount) {
            parts.push('<strong>Monthly Rent:</strong> ' + fmtKsh(tenant.rent_amount));
        }
        if (tenant.credit_balance && parseFloat(tenant.credit_balance) > 0) {
            parts.push('<strong style="color:var(--paid)">Credit Balance:</strong> '
                + '<span style="color:var(--paid)">' + fmtKsh(tenant.credit_balance)
                + ' (auto-applied at next billing)</span>');
        }

        tenantInfo.innerHTML     = parts.join(' &nbsp;|&nbsp; ');
        tenantInfo.style.display = 'block';

        if (tenant.unit_id && tenant.rent_amount) {
            formSection.style.display = 'block';
        } else {
            tenantInfo.innerHTML += ' &nbsp;&mdash; <span style="color:var(--danger,#dc3545)">No active lease.</span>'
                + ' <a href="/public/manage_tenants.php">Assign a lease first.</a>';
        }
    }

    function renderResults(tenants) {
        if (!tenants || tenants.length === 0) {
            resultsBox.innerHTML     = '<div class="autocomplete-empty">No matching tenants found.</div>';
            resultsBox.style.display = 'block';
            return;
        }
        if (tenants._error) {
            resultsBox.innerHTML     = '<div class="autocomplete-empty">Search unavailable — database schema may need updating.</div>';
            resultsBox.style.display = 'block';
            return;
        }

        var tenantMap = {};
        var html = '';
        tenants.forEach(function (t) {
            tenantMap[t.id] = t;
            var detail = [];
            if (t.phone)         detail.push(escHtml(t.phone));
            if (t.email)         detail.push(escHtml(t.email));
            if (t.property_name && t.unit_number) {
                detail.push(escHtml(t.property_name) + ' / ' + escHtml(t.unit_number));
            }
            html += '<div class="autocomplete-item" data-id="' + escHtml(String(t.id)) + '">'
                  + '<span class="autocomplete-item-name">' + escHtml(t.name) + '</span>'
                  + (detail.length
                        ? '<span class="autocomplete-item-detail">' + detail.join(' &middot; ') + '</span>'
                        : '')
                  + '</div>';
        });

        resultsBox.innerHTML     = html;
        resultsBox.style.display = 'block';

        resultsBox.querySelectorAll('.autocomplete-item').forEach(function (item) {
            item.addEventListener('click', function () {
                var id = parseInt(this.getAttribute('data-id'), 10);
                if (tenantMap[id]) { selectTenant(tenantMap[id]); }
            });
        });
    }

    searchInput.addEventListener('input', function () {
        var q = this.value.trim();
        clearTimeout(debounceTimer);
        resetSelection();

        if (q.length < 1) { hideResults(); return; }

        debounceTimer = setTimeout(function () {
            fetch('/public/api/search_tenants.php?q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(renderResults)
                .catch(function () {
                    resultsBox.innerHTML     = '<div class="autocomplete-empty">Error loading results.</div>';
                    resultsBox.style.display = 'block';
                });
        }, 300);
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.autocomplete-wrap')) { hideResults(); }
    });

    clearBtn.addEventListener('click', function () {
        searchInput.value = '';
        hideResults();
        resetSelection();
    });
}());
</script>

<?php renderFooter(); ?>
