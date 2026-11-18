-- =====================================================================
--  Cadet Unit Management System — Complete Relational Schema
--  File: cadet_db.sql
--  Engine: InnoDB (transactional FKs) · Charset: utf8mb4
--  Import:  mysql -u root < cadet_db.sql   (or phpMyAdmin import)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `cadet_db`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE `cadet_db`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `user_modules`;
DROP TABLE IF EXISTS `portal_announcements`;
DROP TABLE IF EXISTS `recruitment_applications`;
DROP TABLE IF EXISTS `audit_log`;
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `system_settings`;
DROP TABLE IF EXISTS `discipline_awards`;
DROP TABLE IF EXISTS `duty_roster`;
DROP TABLE IF EXISTS `inventory`;
DROP TABLE IF EXISTS `attendance`;
DROP TABLE IF EXISTS `fitness_logs`;
DROP TABLE IF EXISTS `cadet_ranks`;
DROP TABLE IF EXISTS `rank_history`;
DROP TABLE IF EXISTS `contact_inquiries`;
DROP TABLE IF EXISTS `public_posts`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `cadets`;

-- ---------------------------------------------------------------------
-- 0. cadet_ranks — Ghana Air Force cadet rank ladder (lookup)
-- ---------------------------------------------------------------------
CREATE TABLE `cadet_ranks` (
  `rank_id`         INT           NOT NULL AUTO_INCREMENT,
  `rank_name`       VARCHAR(100)  NOT NULL,
  `abbreviation`    VARCHAR(20)   NOT NULL,
  `category`        ENUM('NCO','Officer') NOT NULL,
  `seniority_level` INT           NOT NULL,
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`rank_id`),
  UNIQUE KEY `uq_rank_seniority` (`seniority_level`),
  UNIQUE KEY `uq_rank_name` (`rank_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `cadet_ranks` (`rank_name`, `abbreviation`, `category`, `seniority_level`) VALUES
('Recruit / Cadet',        'CDT',       'NCO',     1),
('Lance Corporal',         'L/Cpl',     'NCO',     2),
('Corporal',               'Cpl',       'NCO',     3),
('Sergeant',               'Sgt',       'NCO',     4),
('Flight Sergeant',        'Flt Sgt',   'NCO',     5),
('Warrant Officer Class II','WO II',    'NCO',     6),
('Warrant Officer Class I','WO I',      'NCO',     7),
('Cadet Under Officer',    'CUO',       'Officer', 8),
('Cadet Flying Officer',   'C/Fg Off',  'Officer', 9),
('Cadet Flight Lieutenant','C/Flt Lt',  'Officer', 10),
('Cadet Squadron Leader',  'C/Sqn Ldr', 'Officer', 11);

-- ---------------------------------------------------------------------
-- 1. cadets — master roster / dossier (created first: users FK target)
-- ---------------------------------------------------------------------
CREATE TABLE `cadets` (
  `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `service_no`     VARCHAR(30)      NOT NULL,
  `full_name`      VARCHAR(100)     NOT NULL,
  `gender`         ENUM('Male','Female','Other') NOT NULL DEFAULT 'Male',
  `phone`          VARCHAR(30)      NULL,
  `email`          VARCHAR(120)     NULL,
  `department`     VARCHAR(80)      NULL,
  `current_rank`   VARCHAR(60)      NOT NULL DEFAULT 'Cadet',
  `current_role`   VARCHAR(60)      NULL COMMENT 'e.g. Platoon Commander, Signals',
  `photo`          VARCHAR(255)     NULL COMMENT 'relative path under storage/photos',
  `status`         ENUM('Active','Alumnus','Discharged') NOT NULL DEFAULT 'Active',
  `enrolled_date`  DATE             NOT NULL,
  `created_at`     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_no` (`service_no`),
  KEY `idx_cadet_status` (`status`),
  KEY `idx_cadet_name` (`full_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. users — portal accounts (RBAC: Admin / Officer / Viewer)
-- ---------------------------------------------------------------------
CREATE TABLE `users` (
  `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `cadet_id`        INT UNSIGNED     NULL COMMENT 'Optional link to a cadet dossier',
  `photo`           VARCHAR(255)     NULL COMMENT 'profile picture under storage/photos',
  `username`        VARCHAR(50)      NOT NULL,
  `password_hash`   VARCHAR(255)     NOT NULL COMMENT 'bcrypt via password_hash()',
  `role_level`      ENUM('Admin','Officer','Viewer') NOT NULL DEFAULT 'Viewer',
  `status`          ENUM('Active','Suspended')       NOT NULL DEFAULT 'Active',
  `failed_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`    DATETIME         NULL,
  `last_login`      DATETIME         NULL,
  `created_at`      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`),
  KEY `idx_role_level` (`role_level`),
  KEY `idx_status` (`status`),
  KEY `idx_users_cadet` (`cadet_id`),
  CONSTRAINT `fk_users_cadet` FOREIGN KEY (`cadet_id`)
    REFERENCES `cadets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. rank_history — promotion audit trail
-- ---------------------------------------------------------------------
CREATE TABLE `rank_history` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cadet_id`       INT UNSIGNED NOT NULL,
  `previous_rank`  VARCHAR(60)  NOT NULL,
  `new_rank`       VARCHAR(60)  NOT NULL,
  `promotion_date` DATE         NOT NULL,
  `remarks`        VARCHAR(255) NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rh_cadet` (`cadet_id`),
  KEY `idx_rh_date` (`promotion_date`),
  CONSTRAINT `fk_rh_cadet` FOREIGN KEY (`cadet_id`)
    REFERENCES `cadets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. fitness_logs — physical training metrics
-- ---------------------------------------------------------------------
CREATE TABLE `fitness_logs` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cadet_id`       INT UNSIGNED NOT NULL,
  `test_date`      DATE         NOT NULL,
  `push_ups`       INT UNSIGNED NULL,
  `sit_ups`        INT UNSIGNED NULL,
  `run_time_mins`  DECIMAL(5,2) NULL COMMENT 'minutes (e.g. 12.50)',
  `overall_grade`  ENUM('A','B','C','D') NOT NULL DEFAULT 'C',
  `remarks`        VARCHAR(255) NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fl_cadet` (`cadet_id`),
  KEY `idx_fl_date` (`test_date`),
  CONSTRAINT `fk_fl_cadet` FOREIGN KEY (`cadet_id`)
    REFERENCES `cadets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. attendance — parade / musters (flat: event name + date)
-- ---------------------------------------------------------------------
CREATE TABLE `attendance` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cadet_id`    INT UNSIGNED NOT NULL,
  `event_name`  VARCHAR(120) NOT NULL,
  `event_date`  DATE         NOT NULL,
  `status`      ENUM('Present','Absent','Excused') NOT NULL DEFAULT 'Present',
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_att_event_cadet` (`event_name`, `event_date`, `cadet_id`),
  KEY `idx_att_cadet` (`cadet_id`),
  KEY `idx_att_date` (`event_date`),
  CONSTRAINT `fk_att_cadet` FOREIGN KEY (`cadet_id`)
    REFERENCES `cadets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. inventory — gear / equipment issuance
-- ---------------------------------------------------------------------
CREATE TABLE `inventory` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `item_name`         VARCHAR(120) NOT NULL,
  `serial_no`         VARCHAR(60)  NOT NULL,
  `category`          VARCHAR(60)  NOT NULL DEFAULT 'General',
  `item_condition`    ENUM('New','Good','Fair','Damaged') NOT NULL DEFAULT 'Good',
  `assigned_cadet_id` INT UNSIGNED NULL,
  `date_issued`       DATE         NULL,
  `date_returned`     DATE         NULL,
  `created_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_serial_no` (`serial_no`),
  KEY `idx_inv_cadet` (`assigned_cadet_id`),
  KEY `idx_inv_category` (`category`),
  CONSTRAINT `fk_inv_cadet` FOREIGN KEY (`assigned_cadet_id`)
    REFERENCES `cadets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. duty_roster — shift assignments
-- ---------------------------------------------------------------------
CREATE TABLE `duty_roster` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `duty_name`         VARCHAR(120) NOT NULL,
  `assigned_cadet_id` INT UNSIGNED NOT NULL,
  `duty_date`         DATE         NOT NULL,
  `shift_time`        VARCHAR(60)  NOT NULL COMMENT 'e.g. 06:00-14:00',
  `status`            ENUM('Pending','Completed','Excused') NOT NULL DEFAULT 'Pending',
  `created_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_duty_cadet` (`assigned_cadet_id`),
  KEY `idx_duty_date` (`duty_date`),
  CONSTRAINT `fk_duty_cadet` FOREIGN KEY (`assigned_cadet_id`)
    REFERENCES `cadets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. discipline_awards — commendations & offences
-- ---------------------------------------------------------------------
CREATE TABLE `discipline_awards` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cadet_id`     INT UNSIGNED NOT NULL,
  `record_date`  DATE         NOT NULL,
  `record_type`  ENUM('Commendation','Offense') NOT NULL,
  `description`  VARCHAR(255) NOT NULL,
  `action_taken` VARCHAR(255) NULL,
  `issued_by`    VARCHAR(100) NULL COMMENT 'name/rank of issuing officer',
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_da_cadet` (`cadet_id`),
  KEY `idx_da_type` (`record_type`),
  CONSTRAINT `fk_da_cadet` FOREIGN KEY (`cadet_id`)
    REFERENCES `cadets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Supporting: login attempt tracking
-- ---------------------------------------------------------------------
CREATE TABLE `login_attempts` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`    VARCHAR(50)  NOT NULL,
  `ip_address`  VARCHAR(45)  NOT NULL COMMENT 'IPv4/IPv6',
  `attempted_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `successful`  TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_la_user_time` (`username`, `attempted_at`),
  KEY `idx_la_ip_time` (`ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Supporting: audit trail
-- ---------------------------------------------------------------------
CREATE TABLE `audit_log` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NULL,
  `username`    VARCHAR(50)  NULL,
  `action`      VARCHAR(80)  NOT NULL COMMENT 'login_success, login_fail, create_cadet, ...',
  `entity`      VARCHAR(60)  NULL COMMENT 'table/record touched',
  `entity_id`   INT UNSIGNED NULL,
  `detail`      VARCHAR(500) NULL,
  `ip_address`  VARCHAR(45)  NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_time` (`created_at`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Supporting: system settings (branding — name/logo, admin-managed)
-- ---------------------------------------------------------------------
CREATE TABLE `system_settings` (
  `setting_key`   VARCHAR(50)  NOT NULL,
  `setting_value` VARCHAR(255) NULL,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`    INT UNSIGNED NULL,
  PRIMARY KEY (`setting_key`),
  KEY `fk_settings_user` (`updated_by`),
  CONSTRAINT `fk_settings_user` FOREIGN KEY (`updated_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('system_name', 'Cadet Unit MS'),
('system_logo', NULL);

-- ---------------------------------------------------------------------
-- Supporting: per-user module grants (admin assigns nav modules to
-- Officer/Viewer accounts; Admin always has every module)
-- ---------------------------------------------------------------------
CREATE TABLE `user_modules` (
  `user_id`    INT UNSIGNED NOT NULL,
  `module_key` VARCHAR(30)  NOT NULL,
  PRIMARY KEY (`user_id`, `module_key`),
  KEY `idx_user_modules_key` (`module_key`),
  CONSTRAINT `fk_user_modules_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Public website: news / announcements (Admin CRUD via portal/admin/news.php)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `public_posts` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`          VARCHAR(200) NOT NULL,
  `content`        TEXT         NOT NULL,
  `author`         VARCHAR(100) NULL,
  `image_path`     VARCHAR(255) NULL COMMENT 'cover under storage/photos',
  `published_date` DATE         NOT NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_posts_date` (`published_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Public website: contact & inquiries (contact.php → portal/admin/inquiries.php)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `contact_inquiries` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sender_name`  VARCHAR(100) NOT NULL,
  `sender_email` VARCHAR(120) NOT NULL,
  `subject`      VARCHAR(150) NULL,
  `message`      TEXT         NOT NULL,
  `ip_address`   VARCHAR(45)  NULL,
  `status`       ENUM('Unread','Resolved') NOT NULL DEFAULT 'Unread',
  `received_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_inquiries_status` (`status`),
  KEY `idx_inquiries_ip_time` (`ip_address`, `received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Recruitment: public join form → portal/admin/applications.php
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `recruitment_applications` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name`    VARCHAR(100) NOT NULL,
  `email`        VARCHAR(120) NOT NULL,
  `phone`        VARCHAR(30)  NULL,
  `department`   VARCHAR(80)  NULL,
  `message`      TEXT         NULL,
  `ip_address`   VARCHAR(45)  NULL,
  `status`       ENUM('New','Reviewed','Accepted','Rejected') NOT NULL DEFAULT 'New',
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_app_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Portal bulletin: Admin posts → dashboard + optional public highlight
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `portal_announcements` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(200) NOT NULL,
  `body`       TEXT         NOT NULL,
  `priority`   ENUM('Normal','Urgent') NOT NULL DEFAULT 'Normal',
  `created_by` INT UNSIGNED NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ann_priority` (`priority`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
--  Default administrator account (created on import)
-- =====================================================================
INSERT INTO `users` (`username`, `password_hash`, `role_level`, `status`) VALUES
('admin', '$2y$10$6uzIGgZ9ZZVrk27oyE3NP.ZMKeoGB6KNvFubrwRKUttfg5KO7qhIS', 'Admin', 'Active');
