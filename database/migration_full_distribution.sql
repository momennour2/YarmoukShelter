-- Run once on an existing Yarmouk Shelter database. Safe to rerun.
SET @has_distribution_type=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='beneficiaries' AND COLUMN_NAME='distribution_type');
SET @ddl=IF(@has_distribution_type=0,"ALTER TABLE beneficiaries ADD COLUMN distribution_type ENUM('NORMAL','FULL') NOT NULL DEFAULT 'NORMAL' AFTER id",'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
