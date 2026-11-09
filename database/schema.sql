CREATE DATABASE IF NOT EXISTS yarmouk_shelter_v3 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE yarmouk_shelter_v3;
CREATE TABLE IF NOT EXISTS users(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,username VARCHAR(80) NOT NULL UNIQUE,full_name VARCHAR(160) NOT NULL,password_hash VARCHAR(255) NOT NULL,is_active TINYINT(1) NOT NULL DEFAULT 1,must_change_password TINYINT(1) NOT NULL DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS user_permissions(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,module VARCHAR(40) NOT NULL,action VARCHAR(20) NOT NULL,sectors VARCHAR(255) NOT NULL DEFAULT '*',UNIQUE KEY uq_user_permission(user_id,module,action),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS profiles(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,sector ENUM('A','B','C','D','E','S','S1','R','AS','BS','CS','DS') NOT NULL,archived_from_sector VARCHAR(10) NULL,tent VARCHAR(40),tent_type VARCHAR(40),tent_condition VARCHAR(40),national_id VARCHAR(40) NOT NULL,full_name VARCHAR(180) NOT NULL,gender VARCHAR(20),birth_date DATE,social_status VARCHAR(30),family_member VARCHAR(30),death_date DATE,is_household_head TINYINT(1) NOT NULL DEFAULT 0,hh_id VARCHAR(40) NOT NULL,hospitality VARCHAR(20),partner_name VARCHAR(180),motherhood VARCHAR(40),war_injury TEXT,disability TEXT,chronic_diseases TEXT,house_status VARCHAR(40),region VARCHAR(80),phone VARCHAR(40),other_phone VARCHAR(40),original_address TEXT,nearest_place VARCHAR(180),shelter_address VARCHAR(180),fc VARCHAR(80),fc_no VARCHAR(80),job_position VARCHAR(180),note TEXT,added_at DATE,archived_at DATETIME,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_hh(hh_id),KEY idx_sector_tent(sector,tent),KEY idx_status(social_status)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS beneficiaries(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,distribution_type ENUM('NORMAL','FULL') NOT NULL DEFAULT 'NORMAL',sector VARCHAR(10) NOT NULL,allocation_sector VARCHAR(10) NOT NULL,tent VARCHAR(40),beneficiary_name VARCHAR(180) NOT NULL,national_id VARCHAR(40),category ENUM('Logistics','Financial','Food') NOT NULL,package VARCHAR(180) NOT NULL,amount DECIMAL(14,2),delivery_date DATE NOT NULL,donor VARCHAR(180),members INT,phone VARCHAR(40),note TEXT,created_by BIGINT UNSIGNED,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,KEY idx_bnf(national_id,category,delivery_date),KEY idx_bnf_sector(allocation_sector,category)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS finance(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,flow ENUM('Expenses','Income') NOT NULL,transaction_date DATE NOT NULL,category VARCHAR(120) NOT NULL,item VARCHAR(180) NOT NULL,amount DECIMAL(14,2) NOT NULL,note TEXT,created_by BIGINT UNSIGNED,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,KEY idx_fin_date(transaction_date)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS aids(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,aid_date DATE NOT NULL,package VARCHAR(180) NOT NULL,quantity DECIMAL(14,3) NOT NULL,unit VARCHAR(40) NOT NULL,donor VARCHAR(180),note TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS employees(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,sector VARCHAR(10),job_position VARCHAR(180) NOT NULL,full_name VARCHAR(180) NOT NULL,national_id VARCHAR(40),gender VARCHAR(20),age INT,social_status VARCHAR(40),phone VARCHAR(40),committee_name VARCHAR(180),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS warehouse_items(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_code VARCHAR(80),item_date DATE NOT NULL,category VARCHAR(120) NOT NULL,item_name VARCHAR(180) NOT NULL,in_qty DECIMAL(14,3) NOT NULL DEFAULT 0,out_qty DECIMAL(14,3) NOT NULL DEFAULT 0,unit VARCHAR(40) NOT NULL,note TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS audit_logs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED,action VARCHAR(60) NOT NULL,module VARCHAR(60) NOT NULL,record_id BIGINT,details TEXT,ip_address VARCHAR(45),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,KEY idx_audit_created(created_at)) ENGINE=InnoDB;

-- Upgrade existing V3 databases without dropping data
SET @has_archived_from_sector=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='profiles' AND COLUMN_NAME='archived_from_sector');
SET @ddl=IF(@has_archived_from_sector=0,'ALTER TABLE profiles ADD COLUMN archived_from_sector VARCHAR(10) NULL AFTER sector','SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- FULL distribution tracking: FULL rows are documentation-only and never consume candidate eligibility.
SET @has_distribution_type=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='beneficiaries' AND COLUMN_NAME='distribution_type');
SET @ddl=IF(@has_distribution_type=0,"ALTER TABLE beneficiaries ADD COLUMN distribution_type ENUM('NORMAL','FULL') NOT NULL DEFAULT 'NORMAL' AFTER id",'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

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
