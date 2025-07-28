-- MySQL dump 10.19  Distrib 10.3.39-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: bnx_labtronic
-- ------------------------------------------------------
-- Server snapshot	10.3.39-MariaDB-0ubuntu0.20.04.2

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `snapshot`
--
DROP DATABASE IF EXISTS bnx_labtronic;
CREATE DATABASE IF NOT EXISTS `bnx_labtronic` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `bnx_labtronic`;

DROP TABLE IF EXISTS `snapshot`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `snapshot` (
                            `snapshot_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                            `comp_id` int(11) DEFAULT NULL,
                            `comp_branch_id` int(11) DEFAULT NULL,
                            `snapshot_module` varchar(50) NOT NULL,
                            `snapshot_operation` varchar(50) NOT NULL,
                            `snapshot_status` varchar(10) DEFAULT 'auto',
                            `snapshot_data` JSON DEFAULT ('{}') COMMENT 'be less than 5KB utf8mb4',
                            `snapshot_app_value1` varchar(100) DEFAULT '' COMMENT 'ID:',
                            `snapshot_app_value2` varchar(100) DEFAULT '' COMMENT 'SUBID:',
                            `snapshot_app_value3` varchar(100) DEFAULT '' COMMENT 'CAT:',
                            `snapshot_created_by` int(11) DEFAULT NULL,
                            `snapshot_created_role` varchar(100) DEFAULT NULL,
                            `snapshot_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                            `snapshot_updated_by` int(11) DEFAULT NULL,
                            `snapshot_updated_role` varchar(100) DEFAULT NULL,
                            `snapshot_updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                            PRIMARY KEY (`snapshot_id`),
                            KEY `idx_module_app_keys` (`snapshot_module`,`snapshot_operation`),
                            KEY `idx_comp` (`comp_id`,`comp_branch_id`),
                            KEY `idx_keys` (`snapshot_app_value1`,`snapshot_app_value2`,`snapshot_app_value3`),
                            KEY `idx_status` (`snapshot_status`),
                            KEY `idx_created_at` (`snapshot_created_at`),
                            KEY `idx_updated_at` (`snapshot_updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `snapshot`
--

LOCK TABLES `snapshot` WRITE;
/*!40000 ALTER TABLE `snapshot` DISABLE KEYS */;
/*!40000 ALTER TABLE `snapshot` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;


--
-- Table structure for table `account`
--
CREATE TABLE `customer` (
                            `client_id` INT AUTO_INCREMENT PRIMARY KEY,
                            `comp_id` int(11) DEFAULT 0,
                            `comp_branch_id` int(11) DEFAULT 0,
                            `entity_id` VARCHAR(255) NOT NULL,
                            `customer_status` VARCHAR(10) DEFAULT 'active',
                            `customer_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                            `customer_created_by` int(11) NOT NULL,
                            `customer_updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                            `customer_updated_by` int(11) NOT NULL,
                            KEY `comp` (`comp_id`, `comp_branch_id`)
);


CREATE TABLE `sequent` (
                           `sequent_id` INT AUTO_INCREMENT PRIMARY KEY,
                           `comp_id` int(11) NOT NULL DEFAULT 0,
                           `comp_branch_id` int(11) NOT NULL DEFAULT 0,
                           `sequent_family` VARCHAR(20) NOT NULL DEFAULT 'N',
                           `sequent_prefix` VARCHAR(20) NOT NULL DEFAULT '',
                           `sequent_last_number` INT NOT NULL DEFAULT 0,
                           `sequent_value` INT NOT NULL DEFAULT 0 COMMENT 'current value',
                           `sequent_increment_by` INT NOT NULL DEFAULT 1,
                           `sequent_padding_length` INT NOT NULL DEFAULT 0,
                           `sequent_padding` VARCHAR(3) NOT NULL DEFAULT '',
                           `sequent_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                           `sequent_created_by` int(11) NOT NULL,
                           `sequent_updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                           `sequent_updated_by` int(11) NOT NULL,
                           KEY `sequent_prefix` (`sequent_prefix`),
                           KEY `sequent_family` (`sequent_family`),
                           KEY `comp` (`comp_id`, `comp_branch_id`)
) ENGINE=InnoDB;

--
-- Table structure for table `order`
--

DROP TABLE IF EXISTS `order`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order` (
                         `order_id` int(11) NOT NULL AUTO_INCREMENT,
                         `comp_id` int(11) DEFAULT 0,
                         `comp_branch_id` int(11) DEFAULT 0,
                         `sequent_id` INT DEFAULT 0 COMMENT 'ORDER NUMBER',
                         `sequent_value` INT DEFAULT 0,
                         `customer_id` int(11) NOT NULL DEFAULT 0 COMMENT 'entity.customer',
                         `snapshot_id` bigint(20) unsigned DEFAULT 0 COMMENT 'Referencia a la tabla de snapshot de cambios',
                         `order_type` enum('repair','sale','quote','service','evaluation', '') NOT NULL DEFAULT 'sale',
                         `order_subject` VARCHAR(255) DEFAULT '',
                         `order_note` VARCHAR(255) DEFAULT '',
                         `order_due_date` DATETIME DEFAULT CURRENT_TIMESTAMP,
                         `order_assigned_to` INT DEFAULT 0 COMMENT 'entity_id',
                         `order_priority` ENUM('low', 'modarate', 'high', '') DEFAULT '',
                         `order_completed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                         `order_status` enum('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
                         `order_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                         `order_created_by` int(11) NOT NULL,
                         `order_updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                         `order_updated_by` int(11) NOT NULL,
                         PRIMARY KEY (`order_id`),
                         KEY `idx_customer` (`customer_id`),
                         KEY `comp` (`comp_id`,`comp_branch_id`),
                         KEY `idx_status` (`order_status`),
                         KEY `idx_creation_date` (`order_created_at`),
                         KEY `order_updated_by` (`order_updated_by`),
                         KEY `order_due_date` (`order_due_date`),
                         KEY `order_status` (`order_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


CREATE TABLE `order_device` (
                                `order_device_id` INT AUTO_INCREMENT PRIMARY KEY,
                                `order_id` INT NOT NULL,
                                `comp_id` int(11) DEFAULT 0,
                                `comp_branch_id` int(11) DEFAULT 0,
                                `device_id` INT DEFAULT 0,
                                `device_snapshot_id` INT DEFAULT 0,
                                `order_device_note` VARCHAR(1000),
                                `order_device_other_details` JSON NOT NULL DEFAULT ('{}') COMMENT ' less than 5KB utf8mb4 db in-row',
                                `order_device_status` VARCHAR(10) DEFAULT 'active',
                                KEY `order_device_order`(`order_id`),
                                KEY `orders`(`order_id`),
                                KEY `comp`(`comp_id`, `comp_branch_id`)
);


CREATE TABLE `device` (
                          `device_id` INT AUTO_INCREMENT PRIMARY KEY,
                          `comp_id` int(11) DEFAULT 0,
                          `comp_branch_id` int(11) DEFAULT 0,
                          `snapshot_id` INT DEFAULT 0,
                          `device_model` VARCHAR(255) DEFAULT '',
                          `device_serial_number` VARCHAR(255) DEFAULT '',
                          `device_type` ENUM('professional', 'industrial', 'everyday', 'other') DEFAULT 'other',
                          `device_notes` VARCHAR(1000) DEFAULT '',
                          `device_status` VARCHAR(10) DEFAULT 'active',
                          KEY `device_model`(`device_model`),
                          KEY `device_status`(`device_status`)
);
--
-- Dumping data for table `order`
--

LOCK TABLES `order` WRITE;
/*!40000 ALTER TABLE `order` DISABLE KEYS */;
/*!40000 ALTER TABLE `order` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `order_coms`
--

DROP TABLE IF EXISTS `order_coms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_coms` (
                              `order_coms_id` int(11) NOT NULL AUTO_INCREMENT,
                              `order_id` int(11) NOT NULL,
                              `comp_id` int(11) DEFAULT 0,
                              `comp_branch_id` int(11) DEFAULT 0,
                              `snapshot_id` bigint(20) unsigned DEFAULT 0 COMMENT 'Referencia a la tabla de snapshot de cambios',
                              `order_coms_visibility` enum('shared','private') NOT NULL DEFAULT 'private',
                              `status` enum('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
                              `order_coms_completed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                              `order_coms_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                              `order_coms_created_by` int(11) NOT NULL,
                              `order_coms_updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                              `order_coms_updated_by` int(11) NOT NULL,
                              PRIMARY KEY (`order_coms_id`),
                              KEY `idx_comp_branch` (`comp_id`,`comp_branch_id`),
                              KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `order_coms`
--

LOCK TABLES `order_coms` WRITE;
/*!40000 ALTER TABLE `order_coms` DISABLE KEYS */;
/*!40000 ALTER TABLE `order_coms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `order_pos`
--

DROP TABLE IF EXISTS `order_pos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_pos` (
                             `order_id` int(11) NOT NULL AUTO_INCREMENT,
                             `comp_id` int(11) DEFAULT 0,
                             `comp_branch_id` int(11) DEFAULT 0,
                             `snapshot_id` bigint(20) unsigned DEFAULT 0 COMMENT 'Referencia a la tabla de snapshot de cambios',
                             `pos_id` int(11) DEFAULT 0,
                             `type` enum('sale','quote') DEFAULT 'sale',
                             `status` enum('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
                             `order_pos_completed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                             `order_pos_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                             `order_pos_created_by` int(11) NOT NULL,
                             `order_pos_updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                             `order_pos_updated_by` int(11) NOT NULL,
                             PRIMARY KEY (`order_id`),
                             KEY `idx_comp_branch` (`comp_id`,`comp_branch_id`),
                             KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `entity`
--

DROP TABLE IF EXISTS `entity`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `entity` (
                          `entity_id` int(11) NOT NULL AUTO_INCREMENT,
                          `comp_id` int(11) DEFAULT NULL,
                          `comp_branch_id` int(11) DEFAULT NULL,
                          `snapshot_id` bigint(20) unsigned DEFAULT 0 COMMENT 'rápido acceso a historial de cambios ( snapshot)',
                          `entity_type` varchar(100) DEFAULT NULL COMMENT 'COMPANY,PERSON,ONG,DOG,ETC',
                          `entity_name` varchar(100) DEFAULT NULL,
                          `entity_idn`  varchar(100) DEFAULT NULL COMMENT 'Identifcation',
                          `entity_idn_clear` varchar(100) DEFAULT NULL COMMENT 'without format',
                          `entity_country` varchar(3) DEFAULT 'CL',
                          `entity_status` char(10) default 'active',
                          PRIMARY KEY (`entity_id`),
                          KEY `IDX_comp_id_comp_branch_id` (`comp_id`,`comp_branch_id`),
                          KEY `entity_search` (`entity_name`,`entity_type`),
                          KEY `entity_idn_clear` (`entity_idn_clear`),
                          KEY `entity_country` (`entity_country`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `entity`
--

LOCK TABLES `entity` WRITE;
/*!40000 ALTER TABLE `entity` DISABLE KEYS */;
INSERT INTO `entity` VALUES (1,1,0,0,'PERSON','woz','123.45.678-9','123456789','CL','active');
/*!40000 ALTER TABLE `entity` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `entity_correlation`
--

DROP TABLE IF EXISTS `entity_correlation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `entity_correlation` (
                                      `entity_correlation_id` int(11) NOT NULL AUTO_INCREMENT,
                                      `entity_id` int(11) DEFAULT 0,
                                      `comp_id` int(11) DEFAULT 0,
                                      `comp_branch_id` int(11) DEFAULT 0,
                                      `snapshot_id` bigint(20) unsigned DEFAULT 0,
                                      `entity_correlation_to` int(11) NOT NULL COMMENT 'correlated to entity_id',
                                      `entity_correlation_type` varchar(100) DEFAULT NULL COMMENT 'PROVIDER,CUSTOMER,ETCETC',
                                      `entity_correlation_status` varchar(10) DEFAULT 'active',
                                      PRIMARY KEY (`entity_correlation_id`),
                                      KEY `IDX_comp_id_comp_branch_id` (`comp_id`,`comp_branch_id`),
                                      KEY `entity_search` (`entity_correlation_type`,`entity_correlation_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `entity_correlation`
--

LOCK TABLES `entity_correlation` WRITE;
/*!40000 ALTER TABLE `entity_correlation` DISABLE KEYS */;
/*!40000 ALTER TABLE `entity_correlation` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `entity_model`
--

DROP TABLE IF EXISTS `entity_model`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `entity_model` (
                                `entity_model_id` int(11) NOT NULL AUTO_INCREMENT,
                                `entity_id` int(11) DEFAULT 0,
                                `comp_id` int(11) DEFAULT NULL,
                                `comp_branch_id` int(11) DEFAULT NULL,
                                `snapshot_id` bigint(20) unsigned DEFAULT 0,
                                `entity_model_type` varchar(50) DEFAULT '' COMMENT 'Tipo de modelo (ej. address, ig, mail, phone, etc.)',
                                `entity_model_prime` smallint(1) unsigned DEFAULT 0 COMMENT 'si es prime usar este por defecto',
                                `entity_model_name` varchar(100) DEFAULT  '' COMMENT 'Nombre del modelo',
                                `entity_model_value` varchar(255) DEFAULT  '' COMMENT 'Valor principal del modelo',
                                `entity_model_value2` varchar(255) DEFAULT '' COMMENT 'Valor secundario del modelo',
                                `entity_model_value3` varchar(255) DEFAULT '' COMMENT 'Valor terciario del modelo',
                                `entity_model_status` VARCHAR(10) DEFAULT 'active',
                                PRIMARY KEY (`entity_model_id`),
                                KEY `idx_entity` (`entity_id`),
                                KEY `idx_comp` (`comp_id`,`comp_branch_id`),
                                KEY `idx_entity_model_type_name` (`entity_model_type`,`entity_model_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `entity_model`
--

LOCK TABLES `entity_model` WRITE;
/*!40000 ALTER TABLE `entity_model` DISABLE KEYS */;
/*!40000 ALTER TABLE `entity_model` ENABLE KEYS */;
UNLOCK TABLES;



DROP TABLE IF EXISTS `account`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `account` (
                           `account_id` int(11) NOT NULL AUTO_INCREMENT,
                           `username` varchar(255) NOT NULL,
                           `password` varchar(255) NOT NULL,
                           `is_active` tinyint(1) DEFAULT 1,
                           `snapshot_id` bigint(20) unsigned DEFAULT 0 COMMENT 'Referencia a la tabla de snapshot de cambios',
                           PRIMARY KEY (`account_id`),
                           UNIQUE KEY `username` (`username`),
                           KEY `idx_username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `account`
--

LOCK TABLES `account` WRITE;
/*!40000 ALTER TABLE `account` DISABLE KEYS */;
INSERT INTO `account` VALUES (1,'woz','$2y$10$cen5bg.mpp6fLYvSdTXVTOIQf7E3Kmbh8foPxpeAOFOZ16ewhHlPG',1,0);
/*!40000 ALTER TABLE `account` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `auth_password_recovery`
--

DROP TABLE IF EXISTS `auth_password_recovery`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `auth_password_recovery` (
                                          `id` int(11) NOT NULL AUTO_INCREMENT,
                                          `auth_pr_key` varchar(255) DEFAULT NULL,
                                          `auth_pr_username` varchar(255) DEFAULT NULL,
                                          `auth_password_recovery_requested_at` int(11) NOT NULL DEFAULT 0,
                                          `auth_password_recovery_applied_at` int(11) NOT NULL DEFAULT 0,
                                          `auth_pr_status` int(11) NOT NULL DEFAULT 1,
                                          PRIMARY KEY (`id`),
                                          UNIQUE KEY `IDXU_pers_pr_key` (`auth_pr_username`,`auth_pr_key`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `auth_password_recovery`
--

LOCK TABLES `auth_password_recovery` WRITE;
/*!40000 ALTER TABLE `auth_password_recovery` DISABLE KEYS */;
INSERT INTO `auth_password_recovery` VALUES (1,'-YErIeE6yKeD3Epp25W8Cvda4tDrQHAo16KSIDf50E-9jZTMNPuTahR3-2bmmOrdEWS7jCYovZlo_vJNCdJpzOy0K9cnGayUphZJW5cJyPxu5GLoSGPio-N-','gmoura@wozniak.cl',1577378113,1577378113,1);
/*!40000 ALTER TABLE `auth_password_recovery` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `auth_token`
--

DROP TABLE IF EXISTS `auth_token`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `auth_token` (
                              `token_id` int(11) NOT NULL AUTO_INCREMENT,
                              `account_id` int(11) DEFAULT 0,
                              `auth_token_signature` binary(32) NOT NULL COMMENT 'bin sha256 token',
                              `auth_token_client_ip` varchar(45) NOT NULL,
                              `auth_token_created_at` datetime NOT NULL,
                              `auth_token_payload_raw` varchar(500) DEFAULT NULL,
                              PRIMARY KEY (`token_id`),
                              KEY `auth_token_signature` (`auth_token_signature`),
                              KEY `account_id` (`account_id`),
                              KEY `auth_token_client_ip` (`auth_token_client_ip`)
) ENGINE=InnoDB AUTO_INCREMENT=54 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `file`
--

DROP TABLE IF EXISTS `file`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `file` (
                        `id` int(11) NOT NULL AUTO_INCREMENT,
                        `comp_id` int(11) NOT NULL DEFAULT 0,
                        `comp_branch_id` int(11) NOT NULL DEFAULT 0,
                        `app_name` varchar(100) NOT NULL,
                        `app_freeuse1` varchar(100) NOT NULL COMMENT 'FREE TO USE BY APP MODULE',
                        `app_freeuse2` varchar(100) NOT NULL,
                        `app_freeuse3` varchar(100) NOT NULL,
                        `file_name` varchar(1000) NOT NULL,
                        `file_uuid` varchar(1000) NOT NULL,
                        `file_hashsum` varchar(1000) DEFAULT NULL COMMENT 'xxh32 sum',
                        `file_snapshot` varchar(1000) NOT NULL,
                        `file_path` varchar(1000) NOT NULL,
                        `file_mime` varchar(1000) NOT NULL,
                        `file_extension` varchar(1000) NOT NULL,
                        `file_mimetype` varchar(1000) DEFAULT NULL,
                        `file_mimeencoding` varchar(1000) DEFAULT NULL,
                        `file_size` int(11) NOT NULL DEFAULT 0,
                        `file_apptype` varchar(1000) DEFAULT NULL,
                        `file_is_dir` int(11) NOT NULL DEFAULT 0,
                        `file_is_hidden` smallint(6) NOT NULL DEFAULT 0,
                        `file_status` VARCHAR(10) DEFAULT 'active',
                        `file_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                        `file_created_by` int(11) NOT NULL,
                        `file_updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        `file_updated_by` int(11) NOT NULL,
                        PRIMARY KEY (`id`),
                        KEY `idx_comp_branch` (`comp_id`,`comp_branch_id`),
                        KEY `idx_app` (`app_name`,`app_freeuse1`,`app_freeuse2`,`app_freeuse3`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `file`
--

LOCK TABLES `file` WRITE;
/*!40000 ALTER TABLE `file` DISABLE KEYS */;
/*!40000 ALTER TABLE `file` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `input`
--

DROP TABLE IF EXISTS `input`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `input` (
                         `id` int(11) NOT NULL AUTO_INCREMENT,
                         `comp_id` int(11) DEFAULT NULL,
                         `comp_branch_id` int(11) DEFAULT NULL,
                         `brand_id` int(11) DEFAULT NULL,
                         `input_code` varchar(255) DEFAULT NULL,
                         `input_barcode` varchar(255) DEFAULT NULL,
                         `input_name` varchar(255) DEFAULT '',
                         `input_title` varchar(255) DEFAULT '',
                         `input_desc` varchar(2000) DEFAULT '',
                         `input_cat_id` int(11) DEFAULT NULL,
                         `input_subcat_id` int(11) DEFAULT NULL,
                         `unit_id` int(11) NOT NULL DEFAULT 0,
                         `input_purchasable` smallint(6) DEFAULT 1,
                         `input_sellable` smallint(6) DEFAULT 1,
                         `input_released` smallint(6) DEFAULT 1,
                         `input_img` varchar(255) DEFAULT '',
                         `input_number` varchar(50) DEFAULT '',
                         `input_number_prod` varchar(50) NOT NULL,
                         `input_sellprice` decimal(20,2) DEFAULT 0.00,
                         `input_tax` varchar(10) DEFAULT NULL,
                         `input_purchase_price` decimal(11,2) DEFAULT 0.00,
                         `input_wholesale_price` decimal(11,2) DEFAULT 0.00,
                         `input_iskit` smallint(6) NOT NULL DEFAULT 0,
                         `input_image_file` varchar(255) DEFAULT NULL,
                         `input_detail` varchar(2000) DEFAULT NULL,
                         `input_status` VARCHAR(10) DEFAULT 'active',
                         `input_created_by` int(11) NOT NULL DEFAULT 1,
                         `input_created_at` int(11) NOT NULL DEFAULT 0,
                         `input_updated_by` int(11) NOT NULL DEFAULT 0,
                         `input_updated_at` int(11) NOT NULL DEFAULT 0,
                         PRIMARY KEY (`id`),
                         KEY `IDX_comp` (`comp_id`,`comp_branch_id`),
                         KEY `IDX_input_search0` (`input_status`,`input_name`),
    -- KEY `IDX_input_search1` (`comp_id`,`input_code`,`input_barcode`),
    -- KEY `IDX_input_search2` (`input_title`),
    -- KEY `IDX_input_search3` (`input_status`,`comp_id`,`input_title`,`input_code`,`input_barcode`),
                         KEY `IDX_input_search4` (`input_updated_at`)
) ENGINE=InnoDB AUTO_INCREMENT=1477 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `input`
--

LOCK TABLES `input` WRITE;
/*!40000 ALTER TABLE `input` DISABLE KEYS */;
/*!40000 ALTER TABLE `input` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nav`
--

DROP TABLE IF EXISTS `nav`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `nav` (
                       `id` int(11) NOT NULL AUTO_INCREMENT,
                       `nav_path` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci DEFAULT '0',
                       `nav_name` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci DEFAULT NULL,
                       `nav_icon` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci DEFAULT NULL,
                       `nav_package` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci DEFAULT NULL,
                       `nav_index` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci DEFAULT NULL,
                       `nav_visible` smallint(6) NOT NULL DEFAULT 1,
                       `nav_public` smallint(6) NOT NULL DEFAULT 0,
                       `nav_static` smallint(6) NOT NULL DEFAULT 0,
                       `nav_args` varchar(255) DEFAULT NULL,
                       PRIMARY KEY (`id`),
                       KEY `IDX_nav_path` (`nav_path`),
                       KEY `IDX_nav_package` (`nav_package`)
) ENGINE=InnoDB AUTO_INCREMENT=72 DEFAULT CHARSET=utf8 COLLATE=utf8_spanish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nav`
--

LOCK TABLES `nav` WRITE;
/*!40000 ALTER TABLE `nav` DISABLE KEYS */;
/*!40000 ALTER TABLE `nav` ENABLE KEYS */;
UNLOCK TABLES;


--
-- Dumping data for table `order_pos`
--

LOCK TABLES `order_pos` WRITE;
/*!40000 ALTER TABLE `order_pos` DISABLE KEYS */;
/*!40000 ALTER TABLE `order_pos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `person_rol`
--

DROP TABLE IF EXISTS `person_rol`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `person_rol` (
                              `person_id` int(11) NOT NULL DEFAULT 0,
                              `rol_id` int(11) NOT NULL DEFAULT 0,
                              UNIQUE KEY `IDXU_person_rol_idx` (`person_id`,`rol_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `person_rol`
--

LOCK TABLES `person_rol` WRITE;
/*!40000 ALTER TABLE `person_rol` DISABLE KEYS */;
INSERT INTO `person_rol` VALUES (1,2),(1,28),(3,1),(4,1),(5000000,1),(5000000,2),(5000000,24),(5000000,25),(5000000,26),(5000000,27),(5000000,28),(5000000,29),(5000001,2),(5000001,4),(5000001,28),(5000001,29),(5000001,30),(5000001,31),(5000001,37),(5000002,2),(5000002,24),(5000002,25),(5000002,26),(5000003,2),(5000003,3),(5000003,4),(5000003,5),(5000003,6),(5000003,7),(5000003,8),(5000003,9),(5000003,10),(5000003,11),(5000003,12),(5000003,13),(5000003,14),(5000003,15),(5000003,16),(5000003,17),(5000003,18),(5000003,19),(5000003,20),(5000003,21),(5000003,22),(5000003,23),(5000003,24),(5000003,25),(5000003,26),(5000003,27),(5000003,28),(5000003,29),(5000003,30),(5000003,31),(5000003,32),(5000003,33),(5000003,34),(5000003,35),(5000003,36),(5000003,37),(5000003,38),(5000003,39),(5000006,2),(5000006,7),(5000006,9),(5000006,10),(5000006,28);
/*!40000 ALTER TABLE `person_rol` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pos`
--

DROP TABLE IF EXISTS `pos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pos` (
                       `id` int(11) NOT NULL AUTO_INCREMENT,
                       `comp_id` int(11) NOT NULL DEFAULT 0,
                       `comp_branch_id` int(11) NOT NULL DEFAULT 0,
                       `customer_id` int(11) NOT NULL DEFAULT 0,
                       `sequent_id` int(11) NOT NULL DEFAULT 0,
                       `pos_internal_id` int(11) NOT NULL DEFAULT 0,
                       `pos_internal_created_at` int(11) NOT NULL DEFAULT 0,
                       `pos_code` varchar(255) DEFAULT NULL,
                       `pos_discount_type` varchar(50) DEFAULT NULL,
                       `pos_note` varchar(1000) DEFAULT NULL,
                       `pos_payed` decimal(24,4) DEFAULT NULL COMMENT 'collected so far',
                       `pos_delivery` smallint(6) NOT NULL DEFAULT 0,
                       `pos_delivery_date` int(11) NOT NULL DEFAULT 0,
                       `pos_delivery_address` varchar(1000) DEFAULT NULL COMMENT 'entity_model.address',
                       `pos_delivery_sector` varchar(1000) DEFAULT NULL,
                       `pos_delivery_price` decimal(24,4) NOT NULL DEFAULT 0.0000,
                       `pos_price_net` decimal(24,4) NOT NULL DEFAULT 0.0000 COMMENT 'Pre-taxes && - discount',
                       `pos_discount` decimal(24,4) NOT NULL DEFAULT 0.0000 COMMENT 'discount',
                       `pos_tax_perc` decimal(7,2) NOT NULL DEFAULT 0.00,
                       `pos_tax_val` decimal(24,4) NOT NULL DEFAULT 0.0000 COMMENT 'pos_price_net * (pos_tax_perc / 100)',
                       `pos_price_gross` decimal(24,4) NOT NULL DEFAULT 0.0000 COMMENT 'pos_price_gross = pos_price_net + pos_tax_val',
                       `pos_item_count` smallint(11) NOT NULL DEFAULT 0,
                       `pos_purchase_price` decimal(24,4) NOT NULL DEFAULT 0.0000 COMMENT ' pos_price_net > pos_purchase_price',
                       `pos_internal_updated_at` int(11) DEFAULT NULL,
                       `pos_status` varchar(10) DEFAULT 'draft',
                       `pos_created_by` int(11) NOT NULL DEFAULT 1,
                       `pos_created_at` int(11) NOT NULL DEFAULT 0,
                       `pos_updated_by` int(11) NOT NULL DEFAULT 0,
                       `pos_updated_at` int(11) NOT NULL DEFAULT 0,
                       PRIMARY KEY (`id`),
                       UNIQUE KEY `UIDX_pos_key` (`comp_id`,`pos_internal_id`,`pos_internal_created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=668 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pos`
--

LOCK TABLES `pos` WRITE;
/*!40000 ALTER TABLE `pos` DISABLE KEYS */;
/*!40000 ALTER TABLE `pos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pos_input`
--

DROP TABLE IF EXISTS `pos_input`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pos_input` (
                             `pos_input` int(11) NOT NULL AUTO_INCREMENT,
                             `comp_id` int(11) NOT NULL DEFAULT 0,
                             `pos_id` int(11) NOT NULL DEFAULT 0,
                             `input_id` int(11) NOT NULL DEFAULT 0,
                             `input_snapshot_id` int(11) NOT NULL DEFAULT 0,
                             `pos_input_sellprice` decimal(11,2) NOT NULL DEFAULT 0.00,
                             `pos_input_quantity` int(11) NOT NULL DEFAULT 0,
                             `pos_input_discount` decimal(11,2) NOT NULL DEFAULT 0.00,
                             `pos_input_metadata` varchar(5000) DEFAULT NULL,
                             `pos_input_status` varchar(10) DEFAULT 'draft',
                             `pos_input_created_by` int(11) NOT NULL DEFAULT 1,
                             `pos_input_created_at` int(11) NOT NULL DEFAULT 0,
                             `pos_input_updated_by` int(11) NOT NULL DEFAULT 0,
                             `pos_input_updated_at` int(11) NOT NULL DEFAULT 0,
                             PRIMARY KEY (`pos_input`),
                             UNIQUE KEY `comp` (`comp_id`,`pos_id`,`input_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1966 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pos_input`
--

LOCK TABLES `pos_input` WRITE;
/*!40000 ALTER TABLE `pos_input` DISABLE KEYS */;
/*!40000 ALTER TABLE `pos_input` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pos_payment`
--

DROP TABLE IF EXISTS `pos_payment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pos_payment` (
                               `id` int(11) NOT NULL AUTO_INCREMENT,
                               `comp_id` int(11) NOT NULL DEFAULT 0,
                               `comp_branch_id` int(11) NOT NULL DEFAULT 0,
                               `pos_id` int(11) NOT NULL DEFAULT 0,
                               `pos_payment_pers_id` int(11) NOT NULL DEFAULT 0 COMMENT 'salesman',
                               `pos_payment_payed` decimal(20,4) DEFAULT NULL,
                               `pos_payment_payback` decimal(20,4) DEFAULT NULL,
                               `pos_payment_delivery` smallint(6) NOT NULL DEFAULT 0,
                               `pos_payment_note` varchar(1000) NOT NULL DEFAULT '0',
                               `pos_payment_cash_amount` decimal(11,2) DEFAULT NULL,
                               `pos_payment_cash_doc` varchar(50) DEFAULT NULL,
                               `pos_payment_debit_amount` decimal(11,2) DEFAULT NULL,
                               `pos_payment_debit_doc` varchar(50) DEFAULT NULL,
                               `pos_payment_credit_amount` decimal(11,2) DEFAULT NULL,
                               `pos_payment_credit_doc` varchar(50) DEFAULT NULL,
                               `pos_payment_check_amount` decimal(11,2) DEFAULT NULL,
                               `pos_payment_check_doc` varchar(50) DEFAULT NULL,
                               `pos_payment_installment_amount` decimal(11,2) DEFAULT NULL,
                               `pos_payment_installment_doc` varchar(50) DEFAULT NULL,
                               `pos_payment_internal_created_at` int(11) NOT NULL DEFAULT 0,
                               `pos_payment_status` varchar(10) DEFAULT 'payed',
                               `pos_payment_created_by` int(11) NOT NULL DEFAULT 1,
                               `pos_payment_created_at` int(11) NOT NULL DEFAULT 0,
                               `pos_payment_updated_by` int(11) NOT NULL DEFAULT 0,
                               `pos_payment_updated_at` int(11) NOT NULL DEFAULT 0,
                               PRIMARY KEY (`id`),
                               UNIQUE KEY `UIDX_pos_payment_crdate` (`comp_id`,`pos_id`,`pos_payment_internal_created_at`),
                               KEY `IDX_pos_payment_main` (`comp_id`,`pos_payment_updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pos_payment`
--

LOCK TABLES `pos_payment` WRITE;
/*!40000 ALTER TABLE `pos_payment` DISABLE KEYS */;
/*!40000 ALTER TABLE `pos_payment` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pos_salesman`
--

DROP TABLE IF EXISTS `pos_salesman`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pos_salesman` (
                                `id` int(11) NOT NULL AUTO_INCREMENT,
                                `comp_id` int(11) NOT NULL DEFAULT 0,
                                `pos_id` int(11) NOT NULL DEFAULT 0,
                                `pers_id` int(11) NOT NULL DEFAULT 0,
                                `pers_name` varchar(255) DEFAULT NULL,
                                `pos_salesman_status` varchar(10) DEFAULT 'active',
                                `pos_salesman_created_by` int(11) NOT NULL DEFAULT 1,
                                `pos_salesman_created_at` int(11) NOT NULL DEFAULT 0,
                                `pos_salesman_updated_by` int(11) NOT NULL DEFAULT 0,
                                `pos_salesman_updated_at` int(11) NOT NULL DEFAULT 0,
                                PRIMARY KEY (`id`),
                                KEY `IDXU_pos_salesman_main` (`comp_id`,`pos_salesman_updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pos_salesman`
--

LOCK TABLES `pos_salesman` WRITE;
/*!40000 ALTER TABLE `pos_salesman` DISABLE KEYS */;
/*!40000 ALTER TABLE `pos_salesman` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `privilege`
--

DROP TABLE IF EXISTS `privilege`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `privilege` (
                             `id` int(11) NOT NULL AUTO_INCREMENT,
                             `nav_id` int(11) NOT NULL DEFAULT 0,
                             `rol_id` int(11) NOT NULL DEFAULT 0,
                             `person_id` int(11) NOT NULL DEFAULT 0,
                             `co_person` int(11) NOT NULL DEFAULT 0,
                             `privil_name` varchar(100) DEFAULT NULL,
                             `privil_read` smallint(6) NOT NULL DEFAULT 0,
                             `privil_create` smallint(6) NOT NULL DEFAULT 0,
                             `privil_write` smallint(6) NOT NULL DEFAULT 0,
                             `privil_print` smallint(6) NOT NULL DEFAULT 0,
                             `privil_execute` smallint(6) NOT NULL DEFAULT 0,
                             `privil_api` smallint(6) NOT NULL DEFAULT 0,
                             PRIMARY KEY (`id`),
                             KEY `IDX_privil_nav_person` (`nav_id`,`person_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5000061 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `privilege`
--

LOCK TABLES `privilege` WRITE;
/*!40000 ALTER TABLE `privilege` DISABLE KEYS */;
INSERT INTO `privilege` VALUES (1,61,1,0,0,'',1,1,1,1,1,0),(2,59,1,0,0,'',1,1,1,1,1,0),(3,60,1,0,0,'',1,1,1,1,1,0),(4,62,1,0,0,'',1,1,1,1,1,0),(8,61,0,2,0,'',1,1,1,1,1,0),(9,59,0,2,0,'',1,1,1,1,1,0),(10,60,0,2,0,'',1,1,1,1,1,0),(11,62,0,2,0,'',1,1,1,1,1,0),(12,12,1,0,0,'',1,1,1,1,1,0),(13,14,1,0,0,'',1,1,1,1,1,0),(14,15,1,0,0,'',1,1,1,1,1,0),(15,32,1,0,0,'',1,1,1,1,1,0),(16,33,1,0,0,'',1,1,1,1,1,0),(17,34,1,0,0,'',1,1,1,1,1,0),(18,53,1,0,0,'',1,1,1,1,1,0),(19,58,1,0,0,'',1,1,1,1,1,0),(20,16,1,0,0,'',1,1,1,1,1,0),(21,17,1,0,0,'',1,1,1,1,1,0),(22,38,1,0,0,'',1,1,1,1,1,0),(23,18,1,0,0,'',1,1,1,1,1,0),(24,19,1,0,0,'',1,1,1,1,1,0),(25,45,1,0,0,'',1,1,1,1,1,0),(26,20,1,0,0,'',1,1,1,1,1,0),(27,13,1,0,0,'',1,1,1,1,1,0),(28,21,1,0,0,'',1,1,1,1,1,0),(29,22,1,0,0,'',1,1,1,1,1,0),(30,24,1,0,0,'',1,1,1,1,1,0),(31,27,1,0,0,'',1,1,1,1,1,0),(32,28,1,0,0,'',1,1,1,1,1,0),(33,48,1,0,0,'',1,1,1,1,1,0),(34,57,1,0,0,'',1,1,1,1,1,0),(35,23,1,0,0,'',1,1,1,1,1,0),(36,25,1,0,0,'',1,1,1,1,1,0),(37,26,1,0,0,'',1,1,1,1,1,0),(38,29,1,0,0,'',1,1,1,1,1,0),(39,30,1,0,0,'',1,1,1,1,1,0),(40,31,1,0,0,'',1,1,1,1,1,0),(41,35,1,0,0,'',1,1,1,1,1,0),(42,39,1,0,0,'',1,1,1,1,1,0),(43,43,1,0,0,'',1,1,1,1,1,0),(44,55,1,0,0,'',1,1,1,1,1,0),(45,56,1,0,0,'',1,1,1,1,1,0),(46,36,1,0,0,'',1,1,1,1,1,0),(47,40,1,0,0,'',1,1,1,1,1,0),(48,41,1,0,0,'',1,1,1,1,1,0),(49,46,1,0,0,'',1,1,1,1,1,0),(50,42,1,0,0,'',1,1,1,1,1,0),(51,47,1,0,0,'',1,1,1,1,1,0),(52,54,1,0,0,'',1,1,1,1,1,0),(53,8,1,0,0,'',1,1,1,1,1,0),(54,10,1,0,0,'',1,1,1,1,1,0),(55,11,1,0,0,'',1,1,1,1,1,0),(56,9,1,0,0,'',1,1,1,1,1,0),(75,12,0,2,0,'',1,1,1,1,1,0),(76,14,0,2,0,'',1,1,1,1,1,0),(77,15,0,2,0,'',1,1,1,1,1,0),(78,32,0,2,0,'',1,1,1,1,1,0),(79,33,0,2,0,'',1,1,1,1,1,0),(80,34,0,2,0,'',1,1,1,1,1,0),(81,53,0,2,0,'',1,1,1,1,1,0),(82,58,0,2,0,'',1,1,1,1,1,0),(83,16,0,2,0,'',1,1,1,1,1,0),(84,17,0,2,0,'',1,1,1,1,1,0),(85,38,0,2,0,'',1,1,1,1,1,0),(86,18,0,2,0,'',1,1,1,1,1,0),(87,19,0,2,0,'',1,1,1,1,1,0),(88,45,0,2,0,'',1,1,1,1,1,0),(89,20,0,2,0,'',1,1,1,1,1,0),(90,13,0,2,0,'',1,1,1,1,1,0),(91,21,0,2,0,'',1,1,1,1,1,0),(92,22,0,2,0,'',1,1,1,1,1,0),(93,24,0,2,0,'',1,1,1,1,1,0),(94,27,0,2,0,'',1,1,1,1,1,0),(95,28,0,2,0,'',1,1,1,1,1,0),(96,48,0,2,0,'',1,1,1,1,1,0),(97,57,0,2,0,'',1,1,1,1,1,0),(98,23,0,2,0,'',1,1,1,1,1,0),(99,25,0,2,0,'',1,1,1,1,1,0),(100,26,0,2,0,'',1,1,1,1,1,0),(101,29,0,2,0,'',1,1,1,1,1,0),(102,30,0,2,0,'',1,1,1,1,1,0),(103,31,0,2,0,'',1,1,1,1,1,0),(104,35,0,2,0,'',1,1,1,1,1,0),(105,39,0,2,0,'',1,1,1,1,1,0),(106,43,0,2,0,'',1,1,1,1,1,0),(107,55,0,2,0,'',1,1,1,1,1,0),(108,56,0,2,0,'',1,1,1,1,1,0),(109,36,0,2,0,'',1,1,1,1,1,0),(110,40,0,2,0,'',1,1,1,1,1,0),(111,41,0,2,0,'',1,1,1,1,1,0),(112,46,0,2,0,'',1,1,1,1,1,0),(113,42,0,2,0,'',1,1,1,1,1,0),(114,47,0,2,0,'',1,1,1,1,1,0),(115,54,0,2,0,'',1,1,1,1,1,0),(116,8,0,2,0,'',1,1,1,1,1,0),(117,10,0,2,0,'',1,1,1,1,1,0),(118,11,0,2,0,'',1,1,1,1,1,0),(119,9,0,2,0,'',1,1,1,1,1,0),(120,63,1,0,0,'',1,1,1,1,1,0),(121,64,1,0,0,'',1,1,1,1,1,0),(123,63,0,2,0,'',1,1,1,1,1,0),(124,64,0,2,0,'',1,1,1,1,1,0),(125,71,1,0,0,'',1,1,1,1,1,0),(126,71,0,2,0,'',1,1,1,1,1,0),(5000000,8,2,0,0,'',1,1,1,1,1,0),(5000001,9,2,0,0,'',1,1,1,1,1,0),(5000002,10,2,0,0,'',1,1,1,1,1,0),(5000003,11,2,0,0,'',1,1,1,1,1,0),(5000004,12,2,0,0,'',0,0,0,0,0,0),(5000005,13,2,0,0,'',0,0,0,0,0,0),(5000006,14,2,0,0,'',1,1,1,1,1,0),(5000007,15,2,0,0,'',1,1,1,1,1,0),(5000008,16,2,0,0,'',0,0,0,0,0,0),(5000009,17,2,0,0,'',0,0,0,0,0,0),(5000010,18,2,0,0,'',0,0,0,0,0,0),(5000011,19,2,0,0,'',0,0,0,0,0,0),(5000012,20,2,0,0,'',1,1,1,1,1,0),(5000013,21,2,0,0,'',0,0,0,0,0,0),(5000014,22,2,0,0,'',1,1,1,1,1,0),(5000015,23,2,0,0,'',1,1,1,1,1,0),(5000016,24,2,0,0,'',1,1,1,1,1,0),(5000017,25,2,0,0,'',1,1,1,1,1,0),(5000018,26,2,0,0,'',1,1,1,1,1,0),(5000019,27,2,0,0,'',0,0,0,0,0,0),(5000020,28,2,0,0,'',0,0,0,0,0,0),(5000021,29,2,0,0,'',0,0,0,0,0,0),(5000022,30,2,0,0,'',0,0,0,0,0,0),(5000023,31,2,0,0,'',0,0,0,0,0,0),(5000024,32,2,0,0,'',1,1,1,1,1,0),(5000025,33,2,0,0,'',1,1,1,1,1,0),(5000026,34,2,0,0,'',1,1,1,1,1,0),(5000027,35,2,0,0,'',0,0,0,0,0,0),(5000028,36,2,0,0,'',0,0,0,0,0,0),(5000029,38,2,0,0,'',0,0,0,0,0,0),(5000030,39,2,0,0,'',1,1,1,1,1,0),(5000031,40,2,0,0,'',0,0,0,0,0,0),(5000032,41,2,0,0,'',0,0,0,0,0,0),(5000033,42,2,0,0,'',1,1,1,1,1,0),(5000034,43,2,0,0,'',1,1,1,1,1,0),(5000035,45,2,0,0,'',0,0,0,0,0,0),(5000036,46,2,0,0,'',1,1,1,1,1,0),(5000037,47,2,0,0,'',1,1,1,1,1,0),(5000038,48,2,0,0,'',0,0,0,0,0,0),(5000039,53,2,0,0,'',1,1,1,1,1,0),(5000040,54,2,0,0,'',1,1,1,1,1,0),(5000041,55,2,0,0,'',0,0,0,0,0,0),(5000042,56,2,0,0,'',0,0,0,0,0,0),(5000043,57,2,0,0,'',0,0,0,0,0,0),(5000044,58,2,0,0,'',0,0,0,0,0,0),(5000045,59,2,0,0,'',1,1,1,1,1,0),(5000046,60,2,0,0,'',1,1,1,1,1,0),(5000047,61,2,0,0,'',1,1,1,1,1,0),(5000048,62,2,0,0,'',1,1,1,1,1,0),(5000049,63,2,0,0,'',1,1,1,1,1,0),(5000050,64,2,0,0,'',1,1,1,1,1,0),(5000051,71,2,0,0,'',0,0,0,0,0,0),(5000052,13,0,5000005,0,'',0,0,0,0,0,0),(5000053,27,0,5000005,0,'',0,0,0,0,0,0),(5000054,47,0,5000005,0,'',1,1,1,1,1,0),(5000055,55,0,5000005,0,'',0,0,0,0,0,0),(5000056,42,0,5000005,0,'',1,1,1,1,1,0),(5000057,43,0,5000005,0,'',0,0,0,0,0,0),(5000058,46,0,5000005,0,'',1,1,1,1,1,0),(5000059,54,0,5000005,0,'',1,1,1,1,1,0),(5000060,63,0,5000005,0,'',0,0,0,0,0,0);
/*!40000 ALTER TABLE `privilege` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `profile`
--

DROP TABLE IF EXISTS `profile`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `profile` (
                           `profile_id` int(11) NOT NULL AUTO_INCREMENT,
                           `comp_id` int(11) NOT NULL DEFAULT 0,
                           `comp_branch_id` int(11) NOT NULL DEFAULT 0,
                           `entity_id` int(11) DEFAULT NULL,
                           `account_id` int(11) DEFAULT 0,
                           `profile_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                           `profile_created_by` int(11) NOT NULL,
                           `profile_updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                           `profile_updated_by` int(11) NOT NULL,
                           PRIMARY KEY (`profile_id`),
                           UNIQUE KEY `unique_account_entity` (`account_id`,`entity_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5000008 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `profile`
--

LOCK TABLES `profile` WRITE;
/*!40000 ALTER TABLE `profile` DISABLE KEYS */;
INSERT INTO `profile` VALUES (5000007,1,0,1,1,'2025-01-16 03:14:22',1,'2025-01-16 03:14:22',1);
/*!40000 ALTER TABLE `profile` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `rol`
--

DROP TABLE IF EXISTS `rol`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rol` (
                       `id` int(11) NOT NULL AUTO_INCREMENT,
                       `rol_name` varchar(100) DEFAULT NULL,
                       `rol_status` varchar(10) DEFAULT 'active',
                       PRIMARY KEY (`id`),
                       KEY `IDX_status` (`rol_status`)
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rol`
--

LOCK TABLES `rol` WRITE;
/*!40000 ALTER TABLE `rol` DISABLE KEYS */;
INSERT INTO `rol` VALUES (1,'Doom',1),(2,'Gerente General',1),(3,'Colaborador',1),(4,'Acreditación',1),(5,'Gerente Funcional',1),(6,'Alta generencia',1),(7,'Gerente de Negocios',1),(8,'Gerente de Personas',1),(9,'Gerente de Producción',1),(10,'Gerente Comercial',1),(11,'Gerente Operaciones',1),(12,'Gerente Marketing',1),(13,'Crews',1),(14,'Segmento',1),(15,'Acreditación acreditador',1),(16,'Acreditación reportería',1),(17,'Acreditación contratista',1),(18,'Bodeguero',1),(19,'Ingresos a bodega',1),(20,'Salidas de bodega',1),(21,'Supervisor',1),(22,'Profesor',1),(23,'Alumno',1),(24,'Inventario',1),(25,'Inventario - Hacer ajuste',1),(26,'Inventario - dejar en cero',1),(27,'Inventario - eliminar faltantes',1),(28,'POS',1),(29,'POS - vendedor',1),(30,'POS - Ver historial',1),(31,'Movimiento bodega',1),(32,'Inventario - ver stock',1),(33,'Inventario - ver precios',1),(34,'Bodega',1),(35,'Abastecimiento',1),(36,'Piloto',1),(37,'Peoneta',1),(38,'Crear Curso',1),(39,'Crear Alumno',1);
/*!40000 ALTER TABLE `rol` ENABLE KEYS */;
UNLOCK TABLES;


/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-01-20 17:48:59
