# SmartRent-Manager Development Guidelines

## Environment & Architecture

- **Stack:** Plain PHP (no framework), MySQL via PDO singleton (`config/database.php`), Chart.js for frontend visualisations.
- **Routing:** Procedural file-based routing inside `public/`. Each `.php` file is a route (e.g., `public/dashboard.php`).
- **Styling:** Custom CSS only — `public/assets/css/styles.css`. CSS custom properties + CSS Grid. No Tailwind, no Bootstrap.
- **Currency:** Kenya Shillings (KSh). All monetary output goes through `formatKsh()` in `includes/functions.php`.
- **Entry point:** `public/index.php` redirects to `public/login.php`.
- **DB name:** `smartrent_manager` — host `127.0.0.1`, creds in `config/config.php`.

## Run / Build Commands

This is a plain PHP + XAMPP project. There is no build step.

```bash
# Start the app (XAMPP)
# Ensure Apache and MySQL are running in XAMPP Control Panel, then open:
http://localhost/projects/smart-rent-manager/SmartRent-Manager/public/login.php

# Default login (from database/schema.sql seed)
# Username: admin  Password: password

# Run schema import (first-time setup)
# Import database/schema.sql via phpMyAdmin or mysql CLI:
mysql -u root smartrent_manager < database/schema.sql

# Cron jobs (monthly rent + arrears detection)
php cron/generate_rent_schedule.php
php cron/detect_arrears.php
```

## Project Structure

```
config/           config.php (app + DB creds), database.php (PDO singleton)
cron/             generate_rent_schedule.php, detect_arrears.php
database/         schema.sql
includes/         auth.php, functions.php, layout.php, import_handler.php
modules/          RentCollectionService.php, DashboardService.php, PaymentService.php,
                  PropertyService.php, TenantService.php, ExpenseService.php
public/           All web-facing PHP pages + assets/
  api/            AJAX endpoints: units_for_property.php, tenant_for_unit.php
  assets/css/     styles.css (custom CSS — no Tailwind)
  assets/js/      app.js (Chart.js init + filter sync), upload.js
  reports/        monthly_report.php, collection_report.php
storage/          raw_uploads/latest-upload.csv (last imported file)
vendor/           dompdf/dompdf, phpoffice/phpspreadsheet
```

## Critical Architecture Guardrails — DO NOT BREAK

### 1. Filter Synchronisation
Changing **Year** or **View** in the top filter bar must immediately rebuild the **Reference Month/Quarter** `<select>` to match. This is handled in `app.js` (`initDashboardFilters`, `initBudgetFilters`) via `viewSelect` and `yearInput` change listeners. Do not remove these listeners or allow stale option values to persist across a view/year switch.

### 2. Financial Definitions
- **KPI cards** (Collection Efficiency, Accounts Receivable, Occupancy Rate, Late Payment Alert) reflect the **selected period** (month or quarter), not a rolling annual figure.
- The **Revenue Trend chart** is scoped year-to-date and is labelled accordingly ("Revenue Trend (Year-to-Date)"). Do not relabel cards or the chart as "Annual" projections.

### 3. Revenue Trend Chart
- Y-axis `beginAtZero: true` — baseline must remain at 0.
- Dataset is **truncated at the current calendar month** (`filteredTrend` in `app.js` lines 23–27). Never plot future months showing zero revenue.
- Both X and Y gridlines remain `display: true` for accountant-grade tracking alignment.

### 4. Payment Status Pie Chart
- Data must be **KSh monetary amounts** (`paid_total` vs. `arrears_total`), never tenant headcounts.
- `aspectRatio: 1` and `maintainAspectRatio: true` must be preserved to keep the 1:1 circular geometry.

### 5. Import / Export Behaviour
- **Backup Export** (`public/download_data.php` default, `?type=backup`): produces a ZIP file containing six CSVs (properties, units, tenants, leases, payments, expenses). This is the primary data export for backups.
- **Filtered Payments Export** (`?type=filtered`): queries the database and applies the active year/month/property/view filters. Output is an 8-column CSV scoped to the selected period. Used by the dashboard Download button.
- **Raw Export** (`?type=raw`): streams `storage/raw_uploads/latest-upload.csv` — the exact file from the last import, unmodified.
- **Import Engine** (`includes/import_handler.php` + `public/upload_csv.php`): **emergency-recovery tool only** — truncates and repopulates all data tables. For day-to-day data entry use `manage_properties.php`, `manage_tenants.php`, and `post_payment.php`.

## Coding Standards

- Keep business logic in `modules/*Service.php`. Views in `public/` call service methods; they do not contain raw SQL.
- Use `h()` from `includes/functions.php` for all HTML output escaping.
- Use `csrfToken()` / `verifyCsrfToken()` on every form submission.
- All queries use PDO prepared statements — no string-interpolated SQL values.
- Layout wrappers: `renderHeader($title)` and `renderFooter()` from `includes/layout.php`.
- Layouts use CSS Grid (`auto-fit` columns) to fill horizontal space. Avoid fixed-width containers that leave dead margin on wide screens.
- `declare(strict_types=1)` at the top of every PHP file.
- No framework abstractions, no new Composer dependencies without discussion.
