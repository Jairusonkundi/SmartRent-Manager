# SmartRent Manager (Plain PHP + MySQL)

A production-oriented, framework-free Property Management System focused on finance operations.

## 1) Tech Stack
- PHP 8.2+ (no framework)
- MySQL 8+
- HTML/CSS + minimal vanilla JS
- Chart.js for analytics visualizations
- DOMPDF for PDF reports

## 2) Project Structure

```text
SmartRent-Manager/
├── config/
│   ├── config.php
│   └── database.php
├── cron/
│   ├── detect_arrears.php
│   └── generate_rent_schedule.php
├── database/
│   └── schema.sql
├── includes/
│   ├── auth.php
│   ├── functions.php
│   └── layout.php
├── modules/
│   ├── BudgetService.php
│   ├── DashboardService.php
│   └── PaymentService.php
└── public/
    ├── index.php
    ├── login.php
    ├── logout.php
    ├── dashboard.php
    ├── tenants.php
    ├── payments.php
    ├── budget.php
    ├── arrears.php
    ├── reports.php
    ├── assets/
    │   ├── css/styles.css
    │   └── js/app.js
    └── reports/
        ├── monthly_report.php
        └── collection_report.php
```

## 3) Database Design
- Fully normalized core tables: `properties`, `units`, `tenants`, `leases`, `rent_schedule`, `payments`, `expenses`.
- Performance-focused indexes on month/date/tenant keys.
- Strong FK relationships for integrity.

Run:
```bash
mysql -u root -p < database/schema.sql
```

## 4) Automation Rules Implemented
- Monthly rent generation from active leases (`cron/generate_rent_schedule.php`).
- Due date fixed to the 10th.
- Expected rent sourced from `leases.rent_amount`.
- Status auto-calculated from payments:
  - Paid: paid >= expected
  - Partial: 0 < paid < expected
  - Unpaid: paid = 0
- Timing classification in UI:
  - On Time: paid on/before 10th
  - Late: paid after 10th

## 5) Core Modules
- **Dashboard:** KPIs + trend and distribution charts.
- **Tenants:** active tenant listing with property/unit mapping.
- **Payments:** fast payment capture with validation and tenant dropdown.
- **Budget:** monthly and quarterly expected vs paid vs outstanding comparisons.
- **Arrears:** unpaid/partial tenants and high-risk balances.
- **Reports:** export monthly and collection PDFs.

## 6) CRON Setup
Example crontab:
```cron
0 0 1 * * /usr/bin/php /var/www/SmartRent-Manager/cron/generate_rent_schedule.php
30 0 * * * /usr/bin/php /var/www/SmartRent-Manager/cron/detect_arrears.php
```

## 7) PDF Reporting
Install dependency:
```bash
composer require dompdf/dompdf
```
Then use:
- `/public/reports/monthly_report.php?month=YYYY-MM-01`
- `/public/reports/collection_report.php?month=YYYY-MM-01`

## 8) Security & Data Integrity
- Session authentication (`includes/auth.php`).
- PDO prepared statements across transactional write paths.
- Input filtering/sanitization and constrained form controls.
- No manual input for calculated status fields.

## 9) UI/UX Principles Applied
- Clean dashboard cards for top KPIs.
- Color conventions:
  - Green = paid
  - Red = unpaid/late
  - Yellow = partial
- Sortable, readable tables with low cognitive load.
- Minimal-click workflows for finance manager daily use.

## 10) Local Run
1. Import schema.
2. Configure DB credentials in `config/config.php`.
3. Serve project:
   ```bash
   php -S localhost:8000 -t .
   ```
4. Open `http://localhost:8000/public/login.php`.
