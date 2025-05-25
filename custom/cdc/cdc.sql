-- SQL Schema for CDC (Clinical Data Capture) Module
-- Target DBMS: MySQL (using InnoDB)

-- -----------------------------------------------------------------------------
-- General Settings
-- -----------------------------------------------------------------------------
SET NAMES utf8mb4;
SET TIME_ZONE='+00:00';
SET foreign_key_checks = 0; -- Disable temporarily for table creation order

-- Reminder for application-level constants
-- Define CDC_APPLICATION_NAME for DataCaptureService, ideally in PHP code (e.g., CDC\CRF::CDC_APPLICATION_NAME = 'CDC_APP';)

-- -----------------------------------------------------------------------------
-- Table: cdc_study
-- Description: Stores information about each clinical study.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `cdc_study`;
CREATE TABLE `cdc_study` (
    `study_internal_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `study_id` VARCHAR(100) NOT NULL COMMENT 'Public Study Identifier',
    `study_title` VARCHAR(255) NOT NULL,
    `sponsor_name` VARCHAR(255) NULL DEFAULT NULL,
    `protocol_id` VARCHAR(100) NULL DEFAULT NULL,
    `status` VARCHAR(50) NOT NULL DEFAULT 'PENDING_SETUP' COMMENT 'e.g., PENDING_SETUP, ACTIVE, CLOSED, ON_HOLD',
    `site_initiation_date` DATE NULL DEFAULT NULL COMMENT 'Site Initiation Visit date',
    `site_first_patient_visit_date` DATE NULL DEFAULT NULL COMMENT 'Site First Patient First Visit date',
    `actor_profile_id` VARCHAR(255) NULL DEFAULT NULL COMMENT 'User who last modified the record',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`study_internal_id`),
    UNIQUE KEY `uq_study_id` (`study_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table: cdc_flow_chart
-- Description: Defines the visits for each version of a study's flowchart.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `cdc_flow_chart`;
CREATE TABLE `cdc_flow_chart` (
    `flow_chart_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `study_internal_id_ref` BIGINT UNSIGNED NOT NULL,
    `flow_chart_version` VARCHAR(50) NOT NULL,
    `visit_num` VARCHAR(50) NOT NULL COMMENT 'e.g., SCR, V1, W4, UNSCHED',
    `visit_name` VARCHAR(255) NOT NULL,
    `order_num` INT NOT NULL DEFAULT 0 COMMENT 'Order of this visit in the flowchart sequence',
    `day_nominal` INT NULL DEFAULT NULL COMMENT 'Nominal day of the visit',
    `day_min` INT NULL DEFAULT NULL COMMENT 'Minimum day for the visit window',
    `day_max` INT NULL DEFAULT NULL COMMENT 'Maximum day for the visit window',
    `description` TEXT NULL DEFAULT NULL,
    `is_active` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Indicates if this visit (version) is currently active in the flowchart',
    `actor_profile_id` VARCHAR(255) NULL DEFAULT NULL COMMENT 'User who last modified the record',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`flow_chart_id`),
    UNIQUE KEY `uq_study_version_visitnum` (`study_internal_id_ref`, `flow_chart_version`, `visit_num`),
    INDEX `idx_fc_study_internal_id_ref` (`study_internal_id_ref`),
    -- CONSTRAINT `fk_fc_study_internal_id` FOREIGN KEY (`study_internal_id_ref`) REFERENCES `cdc_study` (`study_internal_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table: cdc_flow_chart_item
-- Description: Defines forms or items associated with each visit in a flowchart.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `cdc_flow_chart_item`;
CREATE TABLE `cdc_flow_chart_item` (
    `flow_chart_item_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `flow_chart_id_ref` BIGINT UNSIGNED NOT NULL COMMENT 'References cdc_flow_chart.flow_chart_id',
    `form_domain` VARCHAR(50) NOT NULL COMMENT 'e.g., DM, VS, AE, CM (CRF name)',
    `item_title` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Display title for this item in the visit',
    `item_type` VARCHAR(50) NOT NULL DEFAULT 'FORM' COMMENT 'e.g., FORM, PROCEDURE, ASSESSMENT',
    `item_order` INT NOT NULL DEFAULT 0 COMMENT 'Order of this item within the visit',
    `branch_code` VARCHAR(50) NOT NULL DEFAULT '__COMMON__' COMMENT 'Branch this item applies to, __COMMON__ for all branches',
    `is_mandatory` BOOLEAN NOT NULL DEFAULT TRUE,
    `details_json` JSON NULL DEFAULT NULL COMMENT 'Additional details specific to this item, e.g., conditional logic hints',
    `actor_profile_id` VARCHAR(255) NULL DEFAULT NULL COMMENT 'User who last modified the record',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`flow_chart_item_id`),
    UNIQUE KEY `uq_item_visit_domain_branch` (`flow_chart_id_ref`, `form_domain`, `branch_code`),
    INDEX `idx_fci_flow_chart_id_ref` (`flow_chart_id_ref`),
    -- CONSTRAINT `fk_fci_flow_chart_id` FOREIGN KEY (`flow_chart_id_ref`) REFERENCES `cdc_flow_chart` (`flow_chart_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table: cdc_form_fields
-- Description: Defines specific fields within a form (CRF) for a study, allowing overrides.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `cdc_form_fields`;
CREATE TABLE `cdc_form_fields` (
    `form_field_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `study_internal_id_ref` BIGINT UNSIGNED NOT NULL,
    `form_domain` VARCHAR(50) NOT NULL COMMENT 'CRF name or domain, e.g., DM, VS',
    `field_name` VARCHAR(100) NOT NULL COMMENT 'References capture_definition.field_name conceptually',
    `item_order` INT NOT NULL DEFAULT 0,
    `is_mandatory` BOOLEAN NOT NULL DEFAULT TRUE,
    `attributes_override_json` JSON NULL DEFAULT NULL COMMENT 'JSON to override bX DataCaptureDefinition attributes for this field in this study/form context',
    `section_name` VARCHAR(100) NULL DEFAULT NULL COMMENT 'Section or group name for display purposes',
    `actor_profile_id` VARCHAR(255) NULL DEFAULT NULL COMMENT 'User who last modified the record',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`form_field_id`),
    UNIQUE KEY `uq_study_domain_field` (`study_internal_id_ref`, `form_domain`, `field_name`),
    INDEX `idx_ff_study_internal_id_ref` (`study_internal_id_ref`),
    -- CONSTRAINT `fk_ff_study_internal_id` FOREIGN KEY (`study_internal_id_ref`) REFERENCES `cdc_study` (`study_internal_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table: cdc_patient_study_branch
-- Description: Manages the treatment or study branch assignments for patients.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `cdc_patient_study_branch`;
CREATE TABLE `cdc_patient_study_branch` (
    `patient_study_branch_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `study_internal_id_ref` BIGINT UNSIGNED NOT NULL,
    `bnx_entity_id_ref` VARCHAR(255) NOT NULL COMMENT 'Patient Identifier, conceptually FK to patient system',
    `branch_code` VARCHAR(50) NOT NULL COMMENT 'e.g., TREATMENT_A, CONTROL, ARM_B',
    `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp when this branch was assigned/became active',
    `is_active` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Indicates if this is the currently active branch for the patient in this study',
    `actor_profile_id` VARCHAR(255) NULL DEFAULT NULL COMMENT 'User who last modified the record',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`patient_study_branch_id`),
    UNIQUE KEY `uq_patient_study_branch_active` (`study_internal_id_ref`, `bnx_entity_id_ref`, `branch_code`),
    INDEX `idx_psb_study_internal_id_ref` (`study_internal_id_ref`),
    INDEX `idx_psb_bnx_entity_id_ref` (`bnx_entity_id_ref`),
    -- CONSTRAINT `fk_psb_study_internal_id` FOREIGN KEY (`study_internal_id_ref`) REFERENCES `cdc_study` (`study_internal_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table: cdc_isf
-- Description: Integrated Study Form - Represents a specific visit event for a patient.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `cdc_isf`;
CREATE TABLE `cdc_isf` (
    `isf_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `study_internal_id_ref` BIGINT UNSIGNED NOT NULL,
    `bnx_entity_id_ref` VARCHAR(255) NOT NULL,
    `flowchart_version` VARCHAR(50) NOT NULL,
    `visit_num` VARCHAR(50) NOT NULL COMMENT 'From cdc_flow_chart.visit_num',
    `visit_name_actual` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Actual name of the visit if different from planned, e.g., for unscheduled',
    `branch_code_actual` VARCHAR(50) NOT NULL COMMENT 'Actual branch this visit event adheres to',
    `isf_status` VARCHAR(50) NOT NULL DEFAULT 'IN_PROGRESS' COMMENT 'e.g., IN_PROGRESS, FINALIZED, CANCELLED',
    `actor_profile_id` VARCHAR(255) NULL DEFAULT NULL COMMENT 'User who last modified the record',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`isf_id`),
    UNIQUE KEY `uq_isf_visit_event` (`study_internal_id_ref`, `bnx_entity_id_ref`, `visit_num`, `flowchart_version`, `branch_code_actual`),
    INDEX `idx_isf_study_internal_id_ref` (`study_internal_id_ref`),
    INDEX `idx_isf_bnx_entity_id_ref` (`bnx_entity_id_ref`),
    -- CONSTRAINT `fk_isf_study_internal_id` FOREIGN KEY (`study_internal_id_ref`) REFERENCES `cdc_study` (`study_internal_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table: cdc_form_instance
-- Description: Stores metadata for each instance of a data collection form.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `cdc_form_instance`;
CREATE TABLE `cdc_form_instance` (
    `form_instance_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `study_internal_id_ref` BIGINT UNSIGNED NOT NULL,
    `isf_id_ref` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to cdc_isf.isf_id, if this form instance is part of an ISF event',
    `bnx_entity_id_ref` VARCHAR(255) NOT NULL,
    `flow_chart_item_id_ref` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to cdc_flow_chart_item.flow_chart_item_id',
    `form_domain` VARCHAR(50) NOT NULL COMMENT 'e.g., DM, VS (CRF name)',
    `branch_code_actual` VARCHAR(50) NOT NULL COMMENT 'Actual branch this form instance adheres to',
    `form_instance_status` VARCHAR(50) NOT NULL DEFAULT 'DRAFT' COMMENT 'e.g., DRAFT, OPEN, COMPLETED, FINALIZED, LOCKED, QUERIED',
    `data_capture_context_group_id` VARCHAR(255) NULL DEFAULT NULL COMMENT 'ID from bX DataCaptureService for the data group',
    `actor_profile_id` VARCHAR(255) NULL DEFAULT NULL COMMENT 'User who last modified the record',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`form_instance_id`),
    UNIQUE KEY `uq_form_instance_in_isf` (`isf_id_ref`, `form_domain`),
    INDEX `idx_fi_study_internal_id_ref` (`study_internal_id_ref`),
    INDEX `idx_fi_isf_id_ref` (`isf_id_ref`),
    INDEX `idx_fi_bnx_entity_id_ref` (`bnx_entity_id_ref`),
    INDEX `idx_fi_flow_chart_item_id_ref` (`flow_chart_item_id_ref`),
    INDEX `idx_fi_data_capture_context_group_id` (`data_capture_context_group_id`),
    -- CONSTRAINT `fk_fi_study_internal_id` FOREIGN KEY (`study_internal_id_ref`) REFERENCES `cdc_study` (`study_internal_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    -- CONSTRAINT `fk_fi_isf_id` FOREIGN KEY (`isf_id_ref`) REFERENCES `cdc_isf` (`isf_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    -- CONSTRAINT `fk_fi_flow_chart_item_id` FOREIGN KEY (`flow_chart_item_id_ref`) REFERENCES `cdc_flow_chart_item` (`flow_chart_item_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table: cdc_isf_form_instance_link
-- Description: Links ISF records to their constituent FormInstance records.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `cdc_isf_form_instance_link`;
CREATE TABLE `cdc_isf_form_instance_link` (
    `isf_form_instance_link_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `isf_id_ref` BIGINT UNSIGNED NOT NULL,
    `form_instance_id_ref` BIGINT UNSIGNED NOT NULL,
    `actor_profile_id` VARCHAR(255) NULL DEFAULT NULL COMMENT 'User who last modified the record',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`isf_form_instance_link_id`),
    UNIQUE KEY `uq_link` (`isf_id_ref`, `form_instance_id_ref`),
    INDEX `idx_ifil_isf_id_ref` (`isf_id_ref`),
    INDEX `idx_ifil_form_instance_id_ref` (`form_instance_id_ref`),
    -- CONSTRAINT `fk_ifil_isf_id` FOREIGN KEY (`isf_id_ref`) REFERENCES `cdc_isf` (`isf_id`) ON DELETE CASCADE ON UPDATE CASCADE,
    -- CONSTRAINT `fk_ifil_form_instance_id` FOREIGN KEY (`form_instance_id_ref`) REFERENCES `cdc_form_instance` (`form_instance_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table: cdc_query
-- Description: Stores data queries raised against specific form fields.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `cdc_query`;
CREATE TABLE `cdc_query` (
    `query_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `form_instance_id_ref` BIGINT UNSIGNED NOT NULL,
    `field_name` VARCHAR(100) NOT NULL COMMENT 'Field name within the form instance being queried',
    `query_text` TEXT NOT NULL,
    `query_status` VARCHAR(50) NOT NULL DEFAULT 'OPEN' COMMENT 'e.g., OPEN, ANSWERED, CLOSED, CANCELLED',
    `response_text` TEXT NULL DEFAULT NULL,
    `resolution_text` TEXT NULL DEFAULT NULL,
    `created_by_profile_id` VARCHAR(255) NOT NULL,
    `updated_by_profile_id` VARCHAR(255) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`query_id`),
    INDEX `idx_query_form_instance_id_ref` (`form_instance_id_ref`),
    INDEX `idx_query_status` (`query_status`),
    INDEX `idx_query_field_name` (`field_name`),
    INDEX `idx_query_created_by_profile_id` (`created_by_profile_id`),
    -- CONSTRAINT `fk_query_form_instance_id` FOREIGN KEY (`form_instance_id_ref`) REFERENCES `cdc_form_instance` (`form_instance_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1; -- Re-enable foreign key checks

-- End of CDC Module SQL Schema