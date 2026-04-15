ALTER TABLE tenants
    ADD COLUMN IF NOT EXISTS tenant_email VARCHAR(160) NULL AFTER email,
    ADD COLUMN IF NOT EXISTS tenant_phone VARCHAR(30) NULL AFTER phone;

ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS payment_status ENUM('On Time', 'Late') NOT NULL DEFAULT 'On Time' AFTER payment_date,
    ADD COLUMN IF NOT EXISTS month_recorded VARCHAR(40) NULL AFTER month;
