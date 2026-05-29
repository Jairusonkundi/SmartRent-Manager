CREATE DATABASE IF NOT EXISTS smartrent_manager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE smartrent_manager;
-- Monetary amounts are recorded in Kenya Shillings (KSH).

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('finance_manager','admin') NOT NULL DEFAULT 'finance_manager',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;


INSERT INTO users (full_name, username, password_hash, role)
VALUES (
    'System Administrator',
    'admin',
    '$2y$12$bXswBP5qfi2gqke/xtYjbuzZwN9yKBnhldSgcDG7XyEjaP/Gh/4ZG',
    'admin'
)
ON DUPLICATE KEY UPDATE
    full_name = VALUES(full_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role);

CREATE TABLE properties (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    location VARCHAR(180) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_properties_name (name),
    KEY idx_properties_location (location)
) ENGINE=InnoDB;

CREATE TABLE units (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_id BIGINT UNSIGNED NOT NULL,
    unit_number VARCHAR(40) NOT NULL,
    status ENUM('occupied','vacant','maintenance') NOT NULL DEFAULT 'vacant',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_units_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    UNIQUE KEY uq_units_property_unit (property_id, unit_number),
    KEY idx_units_status (status)
) ENGINE=InnoDB;

CREATE TABLE tenants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    email VARCHAR(160) DEFAULT NULL,
    tenant_phone VARCHAR(20) DEFAULT NULL,
    tenant_email VARCHAR(100) DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    credit_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'KSH — accumulated overpayment credit',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_tenants_name (name),
    KEY idx_tenants_status (status)
) ENGINE=InnoDB;

CREATE TABLE leases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    unit_id BIGINT UNSIGNED NOT NULL,
    rent_amount DECIMAL(12,2) NOT NULL COMMENT 'KSH',
    start_date DATE NOT NULL,
    end_date DATE DEFAULT NULL,
    status ENUM('active','terminated') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_leases_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_leases_unit FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE,
    UNIQUE KEY uq_leases_tenant_unit_start (tenant_id, unit_id, start_date),
    KEY idx_leases_unit_status (unit_id, status),
    KEY idx_leases_dates (start_date, end_date)
) ENGINE=InnoDB;

CREATE TABLE rent_schedule (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    month DATE NOT NULL,
    expected_rent DECIMAL(12,2) NOT NULL COMMENT 'KSH',
    due_date DATE NOT NULL,
    status ENUM('paid','partial','unpaid') NOT NULL DEFAULT 'unpaid',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rent_schedule_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uq_rent_schedule_tenant_month (tenant_id, month),
    KEY idx_rent_schedule_due_status (due_date, status),
    KEY idx_rent_schedule_month (month)
) ENGINE=InnoDB;

CREATE TABLE payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    billing_month VARCHAR(7) NOT NULL COMMENT 'YYYY-MM',
    amount_expected DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'KSH expected rent for the billing month',
    monthly_rent DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'KSH',
    amount_paid DECIMAL(12,2) NOT NULL COMMENT 'KSH',
    payment_date DATE NOT NULL,
    month DATE NOT NULL,
    collection_status ENUM('Paid','Partial','Unpaid') NOT NULL DEFAULT 'Unpaid',
    payment_status ENUM('On Time','Late') NOT NULL DEFAULT 'On Time',
    payment_channel ENUM('bank_transfer','cash','cheque','credit_applied') NOT NULL DEFAULT 'bank_transfer',
    reference_no VARCHAR(80) DEFAULT NULL,
    recorded_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payments_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_payments_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_payments_tenant_billing_month (tenant_id, billing_month),
    KEY idx_payments_tenant_month (tenant_id, month),
    KEY idx_payments_date (payment_date),
    KEY idx_payments_month (month),
    KEY idx_payments_billing_month (billing_month)
) ENGINE=InnoDB;

ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS billing_month VARCHAR(7) AFTER tenant_id;

ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS amount_expected DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER billing_month,
    ADD COLUMN IF NOT EXISTS collection_status ENUM('Paid','Partial','Unpaid') NOT NULL DEFAULT 'Unpaid' AFTER month;

ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS user_id BIGINT UNSIGNED NULL AFTER payment_date,
    ADD COLUMN IF NOT EXISTS status ENUM('On Time','Late') NOT NULL DEFAULT 'On Time' AFTER user_id;


CREATE TABLE expenses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_id BIGINT UNSIGNED NOT NULL,
    category_name VARCHAR(120) NOT NULL,
    description TEXT DEFAULT NULL,
    amount DECIMAL(15,2) NOT NULL COMMENT 'KSH',
    status ENUM('Requisition Pending','Approved','Disbursed/Paid') NOT NULL DEFAULT 'Requisition Pending',
    cheque_number VARCHAR(80) DEFAULT NULL,
    expense_date DATE NOT NULL,
    reference_period VARCHAR(20) DEFAULT NULL,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_expenses_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    CONSTRAINT fk_expenses_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_expenses_property_date (property_id, expense_date),
    KEY idx_expenses_category_name (category_name),
    KEY idx_expenses_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS import_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_file VARCHAR(255) NOT NULL,
    records_processed INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_import_logs_created_at (created_at)
) ENGINE=InnoDB;

-- ── Idempotent migrations for existing deployments ────────────────────────────
ALTER TABLE tenants
    ADD COLUMN IF NOT EXISTS credit_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00
        COMMENT 'KSH — accumulated overpayment credit';

ALTER TABLE payments
    MODIFY COLUMN payment_channel
        ENUM('bank_transfer','cash','cheque','credit_applied') NOT NULL DEFAULT 'bank_transfer';
