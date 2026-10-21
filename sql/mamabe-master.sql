-- MySQL dump 10.13  Distrib 8.4.3, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: odb
-- ------------------------------------------------------
-- Server version	8.4.3

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `mamabe_admin_override_log`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `mamabe_admin_override_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `staff_name` varchar(150) NOT NULL DEFAULT '',
  `action` varchar(10) NOT NULL DEFAULT '',
  `timestamp` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mamabe_ai_log`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mamabe_baby`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `mamabe_baby` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `mama_id` int unsigned NOT NULL,
  `lmp` date DEFAULT NULL,
  `edd` date DEFAULT NULL,
  `baby_name` varchar(100) NOT NULL DEFAULT '',
  `mykid` varchar(14) NOT NULL DEFAULT '',
  `dob` date DEFAULT NULL,
  `gender` varchar(10) NOT NULL DEFAULT '',
  `remarks` text NOT NULL,
  `is_delivered` tinyint NOT NULL DEFAULT '0',
  `recycle` int NOT NULL DEFAULT '0',
  `timestamp` datetime NOT NULL,
  `miscarriage_cause` varchar(255) DEFAULT '',
  `miscarriage_date` varchar(20) DEFAULT '',
  `miscarriage_time` varchar(20) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `updated_by` int NOT NULL DEFAULT '0',
  `updated_by_name` varchar(150) NOT NULL DEFAULT '',
  `sensitivity` varchar(255) NOT NULL DEFAULT '',
  `baby_health_book` varchar(255) NOT NULL DEFAULT '',
  `benefit_baby_book` tinyint(1) NOT NULL DEFAULT '0',
  `benefit_free_diapers` tinyint(1) NOT NULL DEFAULT '0',
  `benefit_free_diapers2` tinyint(1) NOT NULL DEFAULT '0',
  `benefit_50_off_baby` tinyint(1) NOT NULL DEFAULT '0',
  `baby_receipt_no` varchar(100) NOT NULL DEFAULT '',
  `benefit_baby_book_at` datetime DEFAULT NULL,
  `benefit_free_diapers_at` datetime DEFAULT NULL,
  `benefit_free_diapers2_at` datetime DEFAULT NULL,
  `benefit_50_off_baby_at` datetime DEFAULT NULL,
  `benefit_50_off_baby_items` varchar(500) NOT NULL DEFAULT '',
  `pink_book` varchar(255) NOT NULL DEFAULT '',
  `benefit_mama_book` tinyint(1) NOT NULL DEFAULT '0',
  `benefit_vitamin_d` tinyint(1) NOT NULL DEFAULT '0',
  `benefit_50_off` tinyint(1) NOT NULL DEFAULT '0',
  `benefit_mama_book_at` datetime DEFAULT NULL,
  `benefit_vitamin_d_at` datetime DEFAULT NULL,
  `benefit_50_off_at` datetime DEFAULT NULL,
  `benefit_50_off_items` text NOT NULL,
  `receipt_no` varchar(100) NOT NULL DEFAULT '',
  `twin_id` int NOT NULL DEFAULT '0',
  `receipt_price_mismatch` text NOT NULL,
  `baby_receipt_price_mismatch` text NOT NULL,
  `vd_receipt_no` varchar(100) NOT NULL DEFAULT '',
  `preg_filled_by` varchar(150) NOT NULL DEFAULT '',
  `preg_filled_at` datetime DEFAULT NULL,
  `baby_filled_by` varchar(150) NOT NULL DEFAULT '',
  `baby_filled_at` datetime DEFAULT NULL,
  `benefit_free_items` text NOT NULL,
  `benefit_free_items_at` datetime DEFAULT NULL,
  `benefit_free_items_baby` text NOT NULL,
  `benefit_free_items_baby_at` datetime DEFAULT NULL,
  `mismatch_remark` text NOT NULL,
  `baby_mismatch_remark` text NOT NULL,
  `mama_voucher_id` int DEFAULT NULL COMMENT 'mamabe_voucher.id - the Mama-type voucher issued for this pregnancy/baby row',
  `baby_voucher_id` int DEFAULT NULL COMMENT 'mamabe_voucher.id - the Baby-type voucher issued for this baby',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_mama_voucher_unique` (`mama_voucher_id`),
  UNIQUE KEY `idx_baby_voucher_unique` (`baby_voucher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mamabe_blast_log`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `mamabe_blast_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` int unsigned NOT NULL,
  `mama_id` int unsigned NOT NULL,
  `baby_id` int unsigned NOT NULL DEFAULT '0',
  `blast_date` date NOT NULL,
  `wa_sent` int NOT NULL DEFAULT '0',
  `wa_op` int NOT NULL DEFAULT '0',
  `wa_datetime` datetime DEFAULT NULL,
  `timestamp` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_blast` (`campaign_id`,`mama_id`,`baby_id`,`blast_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mamabe_campaign`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `mamabe_campaign` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `duration` int NOT NULL DEFAULT '1',
  `grace_days` int NOT NULL DEFAULT '0',
  `date_type` int NOT NULL DEFAULT '1',
  `event` varchar(200) NOT NULL,
  `instruction` text NOT NULL,
  `operator` int NOT NULL DEFAULT '0',
  `recycle` int NOT NULL DEFAULT '0',
  `timestamp` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mamabe_diaper_config`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `mamabe_diaper_config` (
  `slot` int NOT NULL,
  `item_code` varchar(50) NOT NULL DEFAULT '',
  `item_name` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`slot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mamabe_email_template`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `mamabe_email_template` (
  `template_key` varchar(50) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `header_title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `updated_by` int DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`template_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mamabe_free_item_catalog`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `mamabe_free_item_catalog` (
  `id` int NOT NULL AUTO_INCREMENT,
  `scope` varchar(10) NOT NULL DEFAULT 'baby',
  `item_code` varchar(50) NOT NULL DEFAULT '',
  `item_name` varchar(255) NOT NULL DEFAULT '',
  `recycle` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mamabe_mama`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `mamabe_mama` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int NOT NULL,
  `mama_name` varchar(100) NOT NULL,
  `ic` varchar(14) NOT NULL,
  `phone` varchar(20) NOT NULL DEFAULT '',
  `address` text NOT NULL,
  `language` int NOT NULL DEFAULT '1',
  `outlet_id` int NOT NULL,
  `recycle` int NOT NULL DEFAULT '0',
  `timestamp` datetime NOT NULL,
  `registered_by` varchar(150) NOT NULL DEFAULT '',
  `email` varchar(100) NOT NULL DEFAULT '',
  `gender` varchar(10) NOT NULL DEFAULT '',
  `birth_date` date DEFAULT NULL,
  `race` varchar(100) NOT NULL DEFAULT '',
  `nationality` varchar(100) NOT NULL DEFAULT '',
  `diagnosis` text NOT NULL,
  `allergic` varchar(255) NOT NULL DEFAULT '',
  `pink_book` varchar(255) NOT NULL DEFAULT '',
  `benefit_mama_book` tinyint(1) NOT NULL DEFAULT '0',
  `benefit_vitamin_d` tinyint(1) NOT NULL DEFAULT '0',
  `benefit_50_off` tinyint(1) NOT NULL DEFAULT '0',
  `receipt_no` varchar(100) NOT NULL DEFAULT '',
  `benefit_mama_book_at` datetime DEFAULT NULL,
  `benefit_vitamin_d_at` datetime DEFAULT NULL,
  `benefit_50_off_at` datetime DEFAULT NULL,
  `benefit_50_off_items` varchar(500) NOT NULL DEFAULT '',
  `receipt_price_mismatch` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mamabe_reactivation_log`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `mamabe_reactivation_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mama_id` int NOT NULL,
  `staff_name` varchar(150) NOT NULL DEFAULT '',
  `old_timestamp` datetime NOT NULL,
  `new_timestamp` datetime NOT NULL,
  `reactivated_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mamabe_receipt_benefit_config`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `mamabe_receipt_benefit_config` (
  `slot` int NOT NULL,
  `item_code` varchar(50) NOT NULL DEFAULT '',
  `item_name` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`slot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mamabe_setting`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `mamabe_setting` (
  `setting_key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_by` int unsigned DEFAULT NULL COMMENT 'staff.id of the admin who last changed it (NULL = seeded default)',
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mamabe_sku_baby`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `mamabe_sku_baby` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sku` varchar(50) NOT NULL,
  `item_name` varchar(255) NOT NULL DEFAULT '',
  `recycle` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mamabe_sku_mama`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `mamabe_sku_mama` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sku` varchar(50) NOT NULL,
  `item_name` varchar(255) NOT NULL DEFAULT '',
  `recycle` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mamabe_sku_master`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `mamabe_sku_master` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sku` varchar(50) NOT NULL,
  `item_name` varchar(255) NOT NULL DEFAULT '',
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `type` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1=mama, 2=baby',
  `recycle` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mamabe_source`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `mamabe_source` (
  `id` int NOT NULL AUTO_INCREMENT,
  `value` varchar(30) NOT NULL,
  `label` varchar(100) NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `recycle` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_value` (`value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mamabe_submission`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mamabe_voucher`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mamabe_wa`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `mamabe_wa` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` int unsigned NOT NULL,
  `wa_bi` text NOT NULL,
  `wa_bm` text NOT NULL,
  `wa_cn` text NOT NULL,
  `operator` int NOT NULL DEFAULT '0',
  `timestamp` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-21 14:42:28
