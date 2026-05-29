-- Migration 002 (idempotent): Expand expenses table for full expense tracking
-- Safe to run on BOTH old installs (renames old columns) and fresh installs
-- (where new column names already exist from schema.sql).
-- Tested against MariaDB 10.4.

USE smartrent_manager;

DROP PROCEDURE IF EXISTS _migrate_expenses_002;
DELIMITER //
CREATE PROCEDURE _migrate_expenses_002()
BEGIN
    -- Rename old column: category → category_name (skip if already renamed)
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'category'
    ) THEN
        ALTER TABLE expenses CHANGE COLUMN `category` `category_name` VARCHAR(120) NOT NULL;
    END IF;

    -- Rename old column: date → expense_date (skip if already renamed)
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'date'
    ) THEN
        ALTER TABLE expenses CHANGE COLUMN `date` `expense_date` DATE NOT NULL;
    END IF;

    -- Rename old column: note → description (skip if already renamed)
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'note'
    ) THEN
        ALTER TABLE expenses CHANGE COLUMN `note` `description` TEXT DEFAULT NULL;
    END IF;

    -- Ensure amount precision is upgraded to DECIMAL(15,2)
    ALTER TABLE expenses MODIFY COLUMN `amount` DECIMAL(15,2) NOT NULL COMMENT 'KSH';

    -- Add new columns only if they are not already present
    ALTER TABLE expenses
        ADD COLUMN IF NOT EXISTS `status`           ENUM('Requisition Pending','Approved','Disbursed/Paid') NOT NULL DEFAULT 'Requisition Pending',
        ADD COLUMN IF NOT EXISTS `cheque_number`    VARCHAR(80)  DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS `reference_period` VARCHAR(20)  DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS `created_by`       BIGINT UNSIGNED DEFAULT NULL;

    -- Add FK for created_by only if not already defined
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'expenses'
          AND CONSTRAINT_NAME = 'fk_expenses_created_by'
    ) THEN
        ALTER TABLE expenses
            ADD CONSTRAINT fk_expenses_created_by
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
    END IF;

    -- Drop old category index if it was never renamed
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'expenses'
          AND INDEX_NAME = 'idx_expenses_category'
    ) THEN
        ALTER TABLE expenses DROP INDEX `idx_expenses_category`;
    END IF;

END //
DELIMITER ;

CALL _migrate_expenses_002();
DROP PROCEDURE IF EXISTS _migrate_expenses_002;

-- CREATE INDEX IF NOT EXISTS is safe to run multiple times on MariaDB 10.4
CREATE INDEX IF NOT EXISTS idx_expenses_category_name ON expenses (category_name);
CREATE INDEX IF NOT EXISTS idx_expenses_status        ON expenses (status);
