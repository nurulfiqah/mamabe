-- MamaBe database upgrade - run ONCE on the production database (MySQL 8.x).
-- Safe to re-run: every CREATE is IF NOT EXISTS. The app also creates these itself on first use,
-- so this file is the explicit, reviewable version of the same changes.

-- mamabe_voucher
CREATE TABLE IF NOT EXISTS `mamabe_voucher` (
  `id` int NOT NULL AUTO_INCREMENT,
  `voucher_code` varchar(20) NOT NULL,
  `type` tinyint unsigned NOT NULL COMMENT '1 = Mama, 2 = Baby',
  `baby_id` int DEFAULT NULL COMMENT 'mamabe_baby.id - set only when type = 2 (Baby)',
  `insert_date` datetime NOT NULL,
  `recycle` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_voucher_code` (`voucher_code`),
  KEY `idx_baby` (`baby_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mamabe_submission
CREATE TABLE IF NOT EXISTS `mamabe_submission` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `mama_id` int unsigned DEFAULT NULL COMMENT 'mamabe_mama.id the submission was saved into',
  `baby_id` int unsigned DEFAULT NULL COMMENT 'mamabe_baby.id (the pregnancy / baby row) the submission was saved into',
  `mama_is_new` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = this submission created the mamabe_mama row, 0 = matched an existing one',
  `baby_is_new` tinyint(1) NOT NULL DEFAULT '0',
  `batch_id` int unsigned DEFAULT NULL COMMENT 'links the rows of one registration request together (the pink + baby rows of a register_type=both submission, or twin/multi-baby rows) - equals the id of the first row inserted in the request; NULL = never grouped (standalone pink/baby row)',
  `register_type` varchar(4) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pink' COMMENT 'pink = pink book (EDD), baby = baby health book (baby name, DOB, MyKid)',
  `source` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'how she knows us: outlet / insurance / social_media',
  `preferred_outlet_id` int unsigned DEFAULT NULL COMMENT 'outlet.id she prefers to redeem at (also saved as mamabe_mama.outlet_id when that is still 0)',
  `mother_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'mama name as typed',
  `ic` varchar(14) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'mama IC / passport as typed (letters+digits only)',
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'mama phone, digits only',
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `address` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'mama address as typed (fills mamabe_mama.address when empty)',
  `language` tinyint unsigned NOT NULL COMMENT '1 English, 2 BM, 3 Chinese, 4 Tamil (same codes as mamabe_mama.language)',
  `edd` date NOT NULL COMMENT 'expected delivery date (baby health book: the baby DOB)',
  `child_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'baby name as typed (optional)',
  `baby_mykid` varchar(14) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'baby MyKid (optional)',
  `baby_dob` date DEFAULT NULL COMMENT 'baby date of birth (optional)',
  `baby_gender` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Male / Female / empty (same values as mamabe_baby.gender)',
  `pink_file` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'pink book image inside uploads/ (random, re-encoded JPEG) - pink registrations',
  `baby_file` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'baby health book image inside uploads/ - baby registrations',
  `status` tinyint unsigned NOT NULL DEFAULT '0',
  `extracted_child` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'baby name the AI read from the baby book',
  `extracted_mother` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'mother name the AI read from the document (pink book for register_type = pink; the baby book''s own "mother on doc" field for register_type = baby - the fallback match)',
  `extracted_edd` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'EDD text as read from the pink book (register_type = pink; not parsed/validated - MamabeVerifier::parseDocDate does that at check time)',
  `extracted_dob` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'date of birth text as read from the baby book (register_type = baby)',
  `extracted_mykid` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'baby MyKid number as read from the baby book - first 6 digits confirm a misread DOB year (register_type = baby)',
  `check_note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'why it is pending (which name mismatched / not found / AI error)',
  `nas_synced` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = images copied to the NAS and linked on mamabe_baby (pink_book / baby_health_book)',
  `nas_note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'last NAS copy error, retried by worker.php',
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'submitter IP, used only for rate limiting',
  `created_at` datetime NOT NULL,
  `checked_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  `voucher_id` int DEFAULT NULL COMMENT 'mamabe_voucher.id assigned when this person was approved',
  `rejected_at` datetime DEFAULT NULL,
  `rejected_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_voucher_unique` (`voucher_id`),
  KEY `idx_status_id` (`status`,`id`),
  KEY `idx_ip_created` (`ip_address`,`created_at`),
  KEY `idx_nas` (`nas_synced`,`status`),
  KEY `idx_phone` (`phone`),
  KEY `idx_batch` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mamabe_ai_log
CREATE TABLE IF NOT EXISTS `mamabe_ai_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `submission_id` int unsigned NOT NULL,
  `started_at` datetime NOT NULL COMMENT 'when the worker picked the unit up',
  `ai_ms` int unsigned NOT NULL COMMENT 'duration of the AI call itself in milliseconds (0 = never called, e.g. file missing)',
  `img_width` smallint unsigned DEFAULT NULL COMMENT 'stored image size in px (before the shrink to ai_max_px)',
  `img_height` smallint unsigned DEFAULT NULL,
  `img_bytes` int unsigned DEFAULT NULL COMMENT 'stored image file size',
  `ai_max_px` smallint unsigned NOT NULL COMMENT 'MAMABE_AI_MAX_PX at the time - longest side sent to the AI',
  `model` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'AI_VISION_MODEL at the time',
  `ai_ok` tinyint(1) NOT NULL COMMENT '1 = AI answered and the reply parsed',
  `ai_error` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'AI / parse error when ai_ok = 0',
  `result_status` tinyint unsigned NOT NULL COMMENT 'MamabeVerifier::STATUS_* decided for this read (2 proceed, 3 pending)',
  PRIMARY KEY (`id`),
  KEY `idx_started` (`started_at`),
  KEY `idx_submission` (`submission_id`),
  CONSTRAINT `fk_mamabe_ailog_submission` FOREIGN KEY (`submission_id`) REFERENCES `mamabe_submission` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mamabe_setting
CREATE TABLE IF NOT EXISTS `mamabe_setting` (
  `setting_key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_by` int unsigned DEFAULT NULL COMMENT 'staff.id of the admin who last changed it (NULL = seeded default)',
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mamabe_source
CREATE TABLE IF NOT EXISTS `mamabe_source` (
  `id` int NOT NULL AUTO_INCREMENT,
  `value` varchar(30) NOT NULL,
  `label` varchar(100) NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `recycle` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_value` (`value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mamabe_email_template
CREATE TABLE IF NOT EXISTS `mamabe_email_template` (
  `template_key` varchar(50) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `header_title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `updated_by` int DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`template_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mamabe_baby: voucher link columns (run only if SHOW COLUMNS shows them missing)
ALTER TABLE mamabe_baby
  ADD COLUMN mama_voucher_id INT NULL COMMENT 'mamabe_voucher.id - the Mama-type voucher issued for this pregnancy/baby row',
  ADD COLUMN baby_voucher_id INT NULL COMMENT 'mamabe_voucher.id - the Baby-type voucher issued for this baby',
  ADD UNIQUE KEY idx_mama_voucher_unique (mama_voucher_id),
  ADD UNIQUE KEY idx_baby_voucher_unique (baby_voucher_id);
