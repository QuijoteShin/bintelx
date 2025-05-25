-- =============================================================================
-- CDC (Clinical Data Capture) Module
-- Version: 2.0 (Reflects discussions on setup versioning, branches)
-- =============================================================================

-- -----------------------------------------------------------------------------
-- General Settings
-- -----------------------------------------------------------------------------
SET NAMES utf8mb4;
SET TIME_ZONE='+00:00';
SET foreign_key_checks = 0; -- Disable temporarily for table creation order

-- Reminder: CDC_APPLICATION_NAME for DataCaptureService is 'CDC_APP' (defined in PHP: CDC\CRF::CDC_APPLICATION_NAME)

-- -----------------------------------------------------------------------------
-- Table: cdc_study
-- Description: Core details of a clinical study.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `cdc_study`;
CREATE TABLE `cdc_study` (
    `study_internal_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Primary key for the study',
    `study_id` VARCHAR(255) NOT NULL COMMENT 'Public/protocol identifier for the study (e.g., PROT-001)',
    `study_title` VARCHAR(255) NOT NULL COMMENT 'Full title of the study',
    `sponsor_name` VARCHAR(255) NULL DEFAULT NULL,
    `protocol_id` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Official protocol ID',
    `status` VARCHAR(50) NOT NULL DEFAULT 'PENDING_SETUP' COMMENT 'e.g., PENDING_SETUP, ACTIVE, CLOSED, ARCHIVED, ON_HOLD',
    `site_initiation_visit_date` TIMESTAMP NULL DEFAULT NULL COMMENT 'Site Initiation Visit date (SIV)',
    `site_first_patient_visit_date` TIMESTAMP NULL DEFAULT NULL COMMENT 'Site First Patient First Visit date (FPFV)',
    `created_by_actor_id` VARCHAR(255) NULL DEFAULT NULL COMMENT 'User who created the record',
    `updated_by_actor_id` VARCHAR(255) NULL DEFAULT NULL COMMENT 'User who last updated the record',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`study_internal_id`),
    UNIQUE KEY `uq_cdc_study_study_id` (`study_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Core details of a clinical study';

-- -----------------------------------------------------------------------------
-- Table: cdc_flowchart_versions_status
-- Description: Tracks the lifecycle status (Draft, Published, Archived) of named flowchart versions for a study.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `cdc_flowchart_versions_status`;
CREATE TABLE `cdc_flowchart_versions_status` (
    `flowchart_version_status_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `study_internal_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK to cdc_study.study_internal_id',
    `flow_chart_version_string` VARCHAR(255) NOT NULL COMMENT 'The named flowchart version (e.g., Protocol_v1.0, Amendment_2_DRAFT)',
    `status` ENUM('DRAFT', 'PUBLISHED', 'ARCHIVED') NOT NULL DEFAULT 'DRAFT' COMMENT 'Lifecycle status of this flowchart version',
    `description` TEXT NULL DEFAULT NULL COMMENT 'Optional description or notes for this version',
    `published_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Timestamp when this version was set to PUBLISHED',
    `created_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
    `updated_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`flowchart_version_status_id`),
    UNIQUE KEY `uq_fcvs_study_version_string` (`study_internal_id`, `flow_chart_version_string`),
    INDEX `idx_fcvs_study_id_status` (`study_internal_id`, `status`)
    -- ,CONSTRAINT `fk_fcvs_study_internal_id` FOREIGN KEY (`study_internal_id`) REFERENCES `cdc_study` (`study_internal_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Manages lifecycle status of flowchart versions per study';

-- -----------------------------------------------------------------------------
-- Table: cdc_visit_definitions
-- Description: Defines reusable templates or blueprints for types of visits within a study.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `cdc_visit_definitions`;
CREATE TABLE `cdc_visit_definitions` (
    `visit_definition_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `study_internal_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK to cdc_study.study_internal_id, making these definitions study-specific',
    `visit_code` VARCHAR(50) NOT NULL COMMENT 'Stable, short code for the visit type (e.g., SCR, V4, EOS), unique per study',
    `visit_name` VARCHAR(255) NOT NULL COMMENT 'Default descriptive name (e.g., Screening Visit)',
    `default_day_nominal` INT NULL DEFAULT NULL COMMENT 'Default nominal day, can be overridden in flowchart placement',
    `default_day_min` INT NULL DEFAULT NULL COMMENT 'Default min day for window',
    `default_day_max` INT NULL DEFAULT NULL COMMENT 'Default max day for window',
    `description` TEXT NULL DEFAULT NULL,
    `created_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
    `updated_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`visit_definition_id`),
    UNIQUE KEY `uq_vd_study_code` (`study_internal_id`, `visit_code`)
    -- ,CONSTRAINT `fk_vd_study_internal_id` FOREIGN KEY (`study_internal_id`) REFERENCES `cdc_study` (`study_internal_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Reusable visit type definitions/blueprints for a study';

-- -----------------------------------------------------------------------------
-- Table: cdc_flow_chart
-- Description: Defines a specific occurrence/placement of a visit type within a versioned flowchart.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `cdc_flow_chart`;
CREATE TABLE `cdc_flow_chart` (
    `flow_chart_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'PK: Identifies a visit''s specific placement in a flowchart version',
    `study_internal_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK to cdc_study.study_internal_id',
    `flow_chart_version` VARCHAR(255) NOT NULL COMMENT 'Named protocol/flowchart version (e.g., Protocol_v1.0). Links conceptually to cdc_flowchart_versions_status',
    `visit_definition_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK to cdc_visit_definitions.visit_definition_id. Defines *which* type of visit this is.',
    `order_num` INT NULL DEFAULT 0 COMMENT 'Order of this visit instance in the flowchart sequence for this version',
    `day_nominal` INT NULL DEFAULT NULL COMMENT 'Nominal day for this visit instance (can override definition default)',
    `day_min` INT NULL DEFAULT NULL COMMENT 'Min day for window (can override definition default)',
    `day_max` INT NULL DEFAULT NULL COMMENT 'Max day for window (can override definition default)',
    `visit_name_override` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Optional override for visit name in this specific flowchart context. Defaults to cdc_visit_definitions.visit_name.',
    `description_override` TEXT NULL DEFAULT NULL COMMENT 'Optional override for visit description in this context.',
    `is_active` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'If this visit is active within this flowchart version (used by Flowchart::setActiveFlowchartVersion)',
    `created_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
    `updated_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`flow_chart_id`),
    -- A visit type at a specific order number should be unique within a flowchart version for a study.
    -- If a visit type can appear multiple times, the order_num differentiates its instances.
    UNIQUE KEY `uq_fc_study_fcversion_visitdef_order` (`study_internal_id`, `flow_chart_version`, `visit_definition_id`, `order_num`),
    INDEX `idx_fc_study_fcversion_active` (`study_internal_id`, `flow_chart_version`, `is_active`),
    INDEX `idx_fc_visit_definition_id` (`visit_definition_id`)
    -- ,CONSTRAINT `fk_fc_study_internal_id` FOREIGN KEY (`study_internal_id`) REFERENCES `cdc_study` (`study_internal_id`) ON DELETE CASCADE ON UPDATE CASCADE
    -- ,CONSTRAINT `fk_fc_visit_definition_id` FOREIGN KEY (`visit_definition_id`) REFERENCES `cdc_visit_definitions` (`visit_definition_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Placement of visit types in a versioned study flowchart';

-- -----------------------------------------------------------------------------
-- Table: cdc_form_fields
-- Description: Defines specific fields within a form (form_domain) for a study AND for a specific flowchart_version, ensuring setup auditability.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `cdc_form_fields`;
CREATE TABLE `cdc_form_fields` (
    `form_field_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `study_internal_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK to cdc_study.study_internal_id',
    `flow_chart_version` VARCHAR(255) NOT NULL COMMENT 'Links this form structure to a specific flowchart version',
    `form_domain` VARCHAR(50) NOT NULL COMMENT 'CRF name or domain (e.g., DM, VS)',
    `field_name` VARCHAR(255) NOT NULL COMMENT 'References DataCaptureService.capture_definition.field_name conceptually',
    `item_order` INT NOT NULL DEFAULT 0 COMMENT 'Display order of this field within this version of the form',
    `section_name` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Optional: UI grouping/section title for this field',
    `is_mandatory` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'If this field is required when this version of the form is filled',
    `attributes_override_json` JSON NULL DEFAULT NULL COMMENT 'JSON to override base DataCaptureDefinition attributes for this field in this context',
    `created_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
    `updated_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`form_field_id`),
    UNIQUE KEY `uq_ff_study_fcver_domain_field` (`study_internal_id`, `flow_chart_version`, `form_domain`, `field_name`),
    INDEX `idx_ff_study_fcver_domain_order` (`study_internal_id`, `flow_chart_version`, `form_domain`, `item_order`)
    -- ,CONSTRAINT `fk_ff_study_internal_id` FOREIGN KEY (`study_internal_id`) REFERENCES `cdc_study` (`study_internal_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Versioned structure (fields, order) for forms within studies';

-- -----------------------------------------------------------------------------
-- Table: cdc_flow_chart_item
-- Description: Links forms/items (and branch specificity) to specific visit placements in a flowchart version.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `cdc_flow_chart_item`;
CREATE TABLE `cdc_flow_chart_item` (
    `flow_chart_item_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `flow_chart_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK to cdc_flow_chart.flow_chart_id (a specific visit instance in a flowchart version)',
    `form_domain` VARCHAR(50) NOT NULL COMMENT 'The form_domain to be used (structure from cdc_form_fields for the relevant flowchart_version)',
    `item_title_override` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Optional override for the display title of this form in this visit/branch context. Defaults to form_domain or label from cdc_form_fields.',
    `item_type` VARCHAR(50) NOT NULL DEFAULT 'FORM' COMMENT 'e.g., FORM, PROCEDURE, ASSESSMENT',
    `item_order` INT NOT NULL DEFAULT 0 COMMENT 'Order of this item within the visit for the specified branch',
    `branch_code` VARCHAR(50) NOT NULL DEFAULT '__COMMON__' COMMENT 'Branch this item applies to; "__COMMON__" or NULL for items applicable to all branches of this visit instance',
    `is_mandatory` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'If this form MUST be filled for this visit and branch. Can override cdc_form_fields.is_mandatory for this specific placement.',
    `details_json` JSON NULL DEFAULT NULL COMMENT 'Additional contextual details (e.g., visit-specific instructions for this item/branch)',
    `created_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
    `updated_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`flow_chart_item_id`),
    UNIQUE KEY `uq_fci_fcid_domain_branch` (`flow_chart_id`, `form_domain`, `branch_code`),
    INDEX `idx_fci_flow_chart_id` (`flow_chart_id`)
    -- ,CONSTRAINT `fk_fci_flow_chart_id` FOREIGN KEY (`flow_chart_id`) REFERENCES `cdc_flow_chart` (`flow_chart_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Specific forms/activities for a visit in a flowchart, branch-aware';

-- -----------------------------------------------------------------------------
-- Table: cdc_patient_study_branch
-- Description: Manages patient assignments to study branches and tracks history.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `cdc_patient_study_branch`;
CREATE TABLE `cdc_patient_study_branch` (
    `patient_study_branch_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `study_internal_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK to cdc_study.study_internal_id',
    `bnx_entity_id` VARCHAR(255) NOT NULL COMMENT 'Patient Identifier',
    `branch_code` VARCHAR(50) NOT NULL COMMENT 'e.g., TREATMENT_A, CONTROL, ARM_B',
    `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp when this branch assignment was made/became effective',
    `is_active` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Indicates if this is the currently active branch assignment for the patient in this study. Application logic must ensure only one is true.',
    `reason_for_assignment` TEXT NULL DEFAULT NULL COMMENT 'Optional reason for assignment or change',
    `created_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
    `updated_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`patient_study_branch_id`),
    UNIQUE KEY `uq_psb_study_entity_assigned` (`study_internal_id`, `bnx_entity_id`, `assigned_at`) COMMENT 'Ensures unique assignment events over time for history',
    INDEX `idx_psb_study_entity_active` (`study_internal_id`, `bnx_entity_id`, `is_active`)
    -- ,CONSTRAINT `fk_psb_study_internal_id` FOREIGN KEY (`study_internal_id`) REFERENCES `cdc_study` (`study_internal_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Patient branch assignments history and current status';

-- -----------------------------------------------------------------------------
-- Table: cdc_isf (Investigator Site File Entry / Visit Event)
-- Description: Represents a specific visit event for a patient.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `cdc_isf`;
CREATE TABLE `cdc_isf` (
    `isf_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `study_internal_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK to cdc_study.study_internal_id',
    `bnx_entity_id` VARCHAR(255) NOT NULL COMMENT 'Patient Identifier',
    `flow_chart_id` BIGINT UNSIGNED NULL COMMENT 'FK to cdc_flow_chart.flow_chart_id. Links to the specific planned visit instance in the flowchart, if applicable. NULL for unscheduled.',
    `visit_num_actual` VARCHAR(50) NOT NULL COMMENT 'Actual visit identifier (e.g., SCR, V1, UNSCH01). Derived from cdc_visit_definitions.visit_code or user-defined for unscheduled.',
    `visit_name_actual` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Actual name of the visit event, can be from cdc_visit_definitions.visit_name or user-defined.',
    `visit_date_actual` DATE NULL COMMENT 'Actual date the visit occurred',
    `flow_chart_version_actual` VARCHAR(255) NOT NULL COMMENT 'The specific flowchart version active for this patient at the time of this visit event.',
    `branch_code_actual` VARCHAR(50) NOT NULL DEFAULT '__COMMON__' COMMENT 'Actual branch this visit event adheres to, determined from cdc_patient_study_branch.',
    `status` ENUM('SCHEDULED', 'IN_PROGRESS', 'COMPLETED', 'DATA_ENTRY_COMPLETE', 'QUERIED', 'FINALIZED', 'LOCKED', 'CANCELLED', 'MISSED') NOT NULL DEFAULT 'SCHEDULED' COMMENT 'Status of the overall visit event',
    `created_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
    `updated_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
    `finalized_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
    `locked_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `finalized_at` TIMESTAMP NULL DEFAULT NULL,
    `locked_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`isf_id`),
    -- Consider uniqueness for a visit event. A patient might have multiple unscheduled visits.
    -- Perhaps UNIQUE(study_internal_id, bnx_entity_id, flow_chart_id, visit_date_actual) if scheduled, or handle in app logic for unscheduled.
    INDEX `idx_isf_study_entity_visitnum` (`study_internal_id`, `bnx_entity_id`, `visit_num_actual`),
    INDEX `idx_isf_flow_chart_id` (`flow_chart_id`)
    -- ,CONSTRAINT `fk_isf_study_internal_id` FOREIGN KEY (`study_internal_id`) REFERENCES `cdc_study` (`study_internal_id`) ON DELETE RESTRICT ON UPDATE CASCADE
    -- ,CONSTRAINT `fk_isf_flow_chart_id` FOREIGN KEY (`flow_chart_id`) REFERENCES `cdc_flow_chart` (`flow_chart_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Visit events (ISF entries) for patients';

-- -----------------------------------------------------------------------------
-- Table: cdc_form_instance
-- Description: Metadata for each instance of a data collection form.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `cdc_form_instance`;
CREATE TABLE `cdc_form_instance` (
    `form_instance_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `isf_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK to cdc_isf.isf_id. Links this form instance to a specific visit event.',
    `study_internal_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK to cdc_study.study_internal_id (denormalized for easier queries)',
    `bnx_entity_id` VARCHAR(255) NOT NULL COMMENT 'Patient Identifier (denormalized)',
    `flow_chart_item_id` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Optional FK to cdc_flow_chart_item.flow_chart_item_id (the planned item that triggered this instance)',
    `form_domain` VARCHAR(50) NOT NULL COMMENT 'e.g., DM, VS (CRF name)',
    `flow_chart_version_actual` VARCHAR(255) NOT NULL COMMENT 'The flowchart version active when this instance was created/data saved (denormalized from ISF).',
    `branch_code_actual` VARCHAR(50) NOT NULL DEFAULT '__COMMON__' COMMENT 'Actual branch this form instance adheres to (denormalized from ISF).',
    `status` ENUM('NOT_STARTED', 'DRAFT', 'OPEN', 'COMPLETED', 'FINALIZED', 'LOCKED', 'QUERIED', 'CANCELLED') NOT NULL DEFAULT 'NOT_STARTED',
    `form_version_instance` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Internal version of this form instance data (e.g. number of saves when OPEN)',
    `data_capture_context_group_id` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'ID from bX\DataCaptureService for the data group',
    `created_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
    `updated_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
    `finalized_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
    `locked_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `finalized_at` TIMESTAMP NULL DEFAULT NULL,
    `locked_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`form_instance_id`),
    -- A specific form domain for a branch should typically appear once per visit event (ISF).
    -- If repeats are allowed (e.g. multiple AE forms), this unique key needs adjustment or removal.
    UNIQUE KEY `uq_fi_isf_domain_branch` (`isf_id`, `form_domain`, `branch_code_actual`),
    INDEX `idx_fi_study_id` (`study_internal_id`),
    INDEX `idx_fi_bnx_entity_id` (`bnx_entity_id`),
    INDEX `idx_fi_flow_chart_item_id` (`flow_chart_item_id`),
    INDEX `idx_fi_dc_context_group_id` (`data_capture_context_group_id`),
    INDEX `idx_fi_status` (`status`)
    -- ,CONSTRAINT `fk_fi_isf_id` FOREIGN KEY (`isf_id`) REFERENCES `cdc_isf` (`isf_id`) ON DELETE CASCADE ON UPDATE CASCADE
    -- ,CONSTRAINT `fk_fi_study_internal_id` FOREIGN KEY (`study_internal_id`) REFERENCES `cdc_study` (`study_internal_id`) ON DELETE RESTRICT ON UPDATE CASCADE
    -- ,CONSTRAINT `fk_fi_flow_chart_item_id` FOREIGN KEY (`flow_chart_item_id`) REFERENCES `cdc_flow_chart_item` (`flow_chart_item_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Instances of data collection forms, linked to ISF and DCS';

-- -----------------------------------------------------------------------------
-- Table: cdc_query
-- Description: Data queries raised against specific form fields.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `cdc_query`;
CREATE TABLE `cdc_query` (
    `query_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `form_instance_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK to cdc_form_instance.form_instance_id',
    `field_name` VARCHAR(255) NOT NULL COMMENT 'Field name from DataCaptureService being queried',
    `query_text` TEXT NOT NULL,
    `status` ENUM('OPEN', 'ANSWERED', 'RESOLVED', 'CLOSED', 'CANCELLED') NOT NULL DEFAULT 'OPEN',
    `response_text` TEXT NULL DEFAULT NULL,
    `resolution_text` TEXT NULL DEFAULT NULL,
    `created_by_actor_id` VARCHAR(255) NOT NULL,
    `updated_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
    `resolved_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `resolved_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`query_id`),
    INDEX `idx_query_form_instance_id` (`form_instance_id`),
    INDEX `idx_query_status` (`status`),
    INDEX `idx_query_field_name` (`field_name`)
    -- ,CONSTRAINT `fk_query_form_instance_id` FOREIGN KEY (`form_instance_id`) REFERENCES `cdc_form_instance` (`form_instance_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Clinical queries on specific data fields';

SET foreign_key_checks = 1; -- Re-enable foreign key checks

-- End of CDC Module SQL Schema