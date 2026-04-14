CREATE DATABASE IF NOT EXISTS smartrent_manager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE smartrent_manager;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('finance_manager','admin') NOT NULL DEFAULT 'finance_manager',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

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
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_tenants_name (name),
    KEY idx_tenants_status (status)
) ENGINE=InnoDB;

CREATE TABLE leases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    unit_id BIGINT UNSIGNED NOT NULL,
    rent_amount DECIMAL(12,2) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE DEFAULT NULL,
    status ENUM('active','terminated') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_leases_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_leases_unit FOREIGN KEY (unit_id) REFERENCES units(id),
    UNIQUE KEY uq_leases_tenant_unit_start (tenant_id, unit_id, start_date),
    KEY idx_leases_unit_status (unit_id, status),
    KEY idx_leases_dates (start_date, end_date)
) ENGINE=InnoDB;

CREATE TABLE rent_schedule (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    month DATE NOT NULL,
    expected_rent DECIMAL(12,2) NOT NULL,
    due_date DATE NOT NULL,
    status ENUM('paid','partial','unpaid') NOT NULL DEFAULT 'unpaid',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rent_schedule_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    UNIQUE KEY uq_rent_schedule_tenant_month (tenant_id, month),
    KEY idx_rent_schedule_due_status (due_date, status),
    KEY idx_rent_schedule_month (month)
) ENGINE=InnoDB;

CREATE TABLE payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    amount_paid DECIMAL(12,2) NOT NULL,
    payment_date DATE NOT NULL,
    month DATE NOT NULL,
    payment_channel ENUM('bank_transfer','cash','cheque') NOT NULL DEFAULT 'bank_transfer',
    reference_no VARCHAR(80) DEFAULT NULL,
    recorded_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payments_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_payments_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_payments_tenant_month (tenant_id, month),
    KEY idx_payments_date (payment_date),
    KEY idx_payments_month (month)
) ENGINE=InnoDB;

CREATE TABLE expenses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(80) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    date DATE NOT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_expenses_property FOREIGN KEY (property_id) REFERENCES properties(id),
    KEY idx_expenses_property_date (property_id, date),
    KEY idx_expenses_category (category)
) ENGINE=InnoDB;
