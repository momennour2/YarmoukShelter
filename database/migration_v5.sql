-- Run once in phpMyAdmin AFTER selecting your existing Yarmouk database.
-- Safe to re-run.
-- =====================================================================
-- V5: roles, resident portal, institutions portal, tickets, allocations
-- =====================================================================
SET @c=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role');
SET @ddl=IF(@c=0,"ALTER TABLE users ADD COLUMN role VARCHAR(30) NOT NULL DEFAULT 'staff' AFTER full_name",'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @c=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='institution_id');
SET @ddl=IF(@c=0,'ALTER TABLE users ADD COLUMN institution_id BIGINT UNSIGNED NULL AFTER role','SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @c=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='assigned_sectors');
SET @ddl=IF(@c=0,'ALTER TABLE users ADD COLUMN assigned_sectors VARCHAR(255) NULL AFTER institution_id','SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @c=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='last_login_at');
SET @ddl=IF(@c=0,'ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL','SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE users SET role='general_manager' WHERE username='admin';

CREATE TABLE IF NOT EXISTS institutions(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(200) NOT NULL,
 org_type VARCHAR(60),
 contact_person VARCHAR(160),
 phone VARCHAR(40),
 email VARCHAR(160),
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tickets(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 profile_id BIGINT UNSIGNED NULL,
 national_id VARCHAR(40) NOT NULL,
 hh_id VARCHAR(40),
 full_name VARCHAR(180) NOT NULL,
 sector VARCHAR(10),
 tent VARCHAR(40),
 phone VARCHAR(40),
 ticket_type ENUM('complaint','suggestion','inquiry','aid_issue') NOT NULL DEFAULT 'complaint',
 subject VARCHAR(200) NOT NULL,
 body TEXT NOT NULL,
 status ENUM('new','in_review','resolved','rejected') NOT NULL DEFAULT 'new',
 priority ENUM('normal','high','urgent') NOT NULL DEFAULT 'normal',
 internal_note TEXT,
 response TEXT,
 responded_by BIGINT UNSIGNED NULL,
 responded_at DATETIME NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_t_nid(national_id),
 KEY idx_t_status(status,sector)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS data_requests(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 institution_id BIGINT UNSIGNED NOT NULL,
 requested_by BIGINT UNSIGNED NOT NULL,
 title VARCHAR(220) NOT NULL,
 request_type VARCHAR(40) NOT NULL,
 details TEXT,
 sectors VARCHAR(120),
 period_from DATE NULL,
 period_to DATE NULL,
 needed_by DATE NULL,
 status ENUM('pending','fulfilled','rejected') NOT NULL DEFAULT 'pending',
 response_note TEXT,
 response_data LONGTEXT,
 response_file VARCHAR(200),
 response_file_name VARCHAR(200),
 handled_by BIGINT UNSIGNED NULL,
 handled_at DATETIME NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 KEY idx_dr_inst(institution_id,status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sector_allocations(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 sector VARCHAR(10) NOT NULL,
 category ENUM('Logistics','Financial','Food') NOT NULL,
 package VARCHAR(180) NOT NULL,
 quantity INT NOT NULL,
 alloc_date DATE NOT NULL,
 note TEXT,
 created_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 KEY idx_alloc(sector,package)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS announcements(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(200) NOT NULL,
 body TEXT NOT NULL,
 audience ENUM('residents','institutions','all') NOT NULL DEFAULT 'residents',
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 kind VARCHAR(20) NOT NULL,
 identifier VARCHAR(80) NOT NULL,
 ip_address VARCHAR(45),
 success TINYINT(1) NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 KEY idx_la(kind,identifier,created_at),
 KEY idx_la_ip(ip_address,created_at)
) ENGINE=InnoDB;
