-- =============================================================================
-- CDC Module - Updated SQL Schema
-- =============================================================================

-- Defines a Clinical Study (Parent Table)
CREATE TABLE `cdc_study` (
  `study_internal_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Primary key for the study',
  `study_id` VARCHAR(255) NOT NULL COMMENT 'Public/protocol identifier for the study, e.g., PROT-001',
  `sponsor_name` VARCHAR(255) NULL DEFAULT NULL,
  `protocol_id` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Official protocol ID',
  `study_title` VARCHAR(255) NULL DEFAULT NULL,
  `site_initiation_visit` TIMESTAMP NULL COMMENT 'Site Initiation Visit date (SIV).',
  `site_first_visit` TIMESTAMP NULL COMMENT 'Site First Patient First Visit date (FPFV).',
  `status` VARCHAR(50) NOT NULL DEFAULT 'PENDING_SETUP' COMMENT 'e.g., PENDING_SETUP, ACTIVE, CLOSED, ARCHIVED',
  `created_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`study_internal_id`),
  UNIQUE KEY `uq_cdc_study_study_id` (`study_id`)
) ENGINE=InnoDB COMMENT='Core details of a clinical study';

-- Defines the planned schedule of visits for a study (Flowchart)
CREATE TABLE `cdc_flow_chart` (
  `flow_chart_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `study_internal_id_ref` BIGINT UNSIGNED NOT NULL COMMENT 'FK to cdc_study',
  `flow_chart_version` VARCHAR(255) NOT NULL COMMENT 'Protocol Flowchart amendment version (e.g., v1.0, v2.1-Amd-3)',
  `visit_name` VARCHAR(255) NOT NULL COMMENT 'Descriptive name of the visit, e.g., SCREENING, WEEK 4 VISIT',
  `visit_num` VARCHAR(50) NULL DEFAULT NULL COMMENT 'Shorter visit identifier, e.g., V1, V2, SCR, WK1',
  `order_num` INT NULL DEFAULT 0 COMMENT 'Order of this visit in the sequence',
  `day_nominal` INT NULL DEFAULT NULL COMMENT 'Nominal day of the visit',
  `day_min` INT NULL DEFAULT NULL COMMENT 'Minimum day for the visit window',
  `day_max` INT NULL DEFAULT NULL COMMENT 'Maximum day for the visit window',
  `description` VARCHAR(255) NULL DEFAULT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`flow_chart_id`),
  INDEX `idx_cdc_flow_chart_study_version` (`study_internal_id_ref`, `flow_chart_version`, `is_active`),
  UNIQUE KEY `uq_cdc_flow_chart_study_ver_visit` (`study_internal_id_ref`, `flow_chart_version`, `visit_num`)
  -- ,FOREIGN KEY (`study_internal_id_ref`) REFERENCES `cdc_study`(`study_internal_id`) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Planned schedule of visits for a study version (Flowchart)';

-- Links forms/domains (items) to specific flowchart visit entries
-- Links forms/domains (items) to specific flowchart visit entries
CREATE TABLE `cdc_flow_chart_item` (
  `flow_chart_item_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `flow_chart_id_ref` BIGINT UNSIGNED NOT NULL COMMENT 'FK to cdc_flow_chart (a specific visit entry)',
  `form_domain` VARCHAR(50) NOT NULL COMMENT 'CDISC Domain code (e.g., VS, DM, AE). Links conceptually to cdc_form_fields',
  `item_title` VARCHAR(255) NULL DEFAULT NULL COMMENT 'User friendly title for this item, e.g., Vital Signs',
  `item_type` VARCHAR(50) NOT NULL DEFAULT 'FORM' COMMENT 'Type: FORM, SECTION, PROCEDURE',
  `item_order` INT NULL DEFAULT 0 COMMENT 'Order of this form/activity within the visit for the specified branch',
  `branch_code` VARCHAR(50) NULL DEFAULT '__COMMON__' COMMENT 'Branch/Arm identifier. "__COMMON__" or NULL for items applicable to all branches of this visit.',
  `is_mandatory` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'If this form MUST be filled for the visit and branch',
  `details_json` JSON NULL DEFAULT NULL COMMENT 'Optional: Visit-specific instructions for this item/branch, NOT form structure.',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`flow_chart_item_id`),
  INDEX `idx_cdc_flow_chart_item_flow_chart_id` (`flow_chart_id_ref`),
  UNIQUE KEY `uq_item_visit_domain_branch` (`flow_chart_id_ref`, `form_domain`, `branch_code`)
  -- ,FOREIGN KEY (`flow_chart_id_ref`) REFERENCES `cdc_flow_chart`(`flow_chart_id`) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Specific forms/activities planned for a visit in a flowchart, branch-aware.';

-- =============================================================================
-- NEW/ESSENTIAL TABLE: Defines the structure (fields, order, sections)
-- for a specific form_domain within a study.
-- =============================================================================
CREATE TABLE `cdc_form_fields` (
  `form_field_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `study_internal_id_ref` BIGINT UNSIGNED NOT NULL COMMENT 'FK to cdc_study. Defines this structure for a specific study.',
  `form_domain` VARCHAR(50) NOT NULL COMMENT 'The form/domain this field belongs to (e.g., VS, DM, AE).',
  `field_name` VARCHAR(255) NOT NULL COMMENT 'The field_name from DataCaptureService.capture_definition.',
  `item_order` INT NOT NULL DEFAULT 0 COMMENT 'Display order of this field within the form.',
  `section_name` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Optional: UI grouping/section title.',
  `is_mandatory` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'If this field is required when the form is filled.',
  `attributes_override_json` JSON NULL DEFAULT NULL COMMENT 'Optional: JSON to override/extend base attributes from capture_definition for this form context.',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`form_field_id`),
  INDEX `idx_cdc_form_fields_study_domain_order` (`study_internal_id_ref`, `form_domain`, `item_order`),
  UNIQUE KEY `uq_cdc_form_fields_study_domain_field` (`study_internal_id_ref`, `form_domain`, `field_name`)
  -- ,FOREIGN KEY (`study_internal_id_ref`) REFERENCES `cdc_study`(`study_internal_id`) ON DELETE CASCADE
  -- ,FOREIGN KEY (`field_name`) REFERENCES `capture_definition`(`field_name`) -- If desired & feasible
) ENGINE=InnoDB COMMENT='Defines structure (fields, order) for forms within studies.';


-- Investigator Site File entry - represents a data collection event
CREATE TABLE `cdc_isf` (
  `isf_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `study_internal_id_ref` BIGINT UNSIGNED NOT NULL COMMENT 'FK to cdc_study',
  `bnx_entity_id` VARCHAR(255) NOT NULL COMMENT 'Bintelx entity ID for the patient/subject',
  `flow_chart_id_ref` BIGINT UNSIGNED NOT NULL COMMENT 'FK to cdc_flow_chart, linking to a specific planned visit',
  `flow_chart_version` VARCHAR(255) NOT NULL COMMENT 'Denormalized: Protocol Flowchart version used at this visit event.',
  `visit_num_actual` VARCHAR(255) NOT NULL COMMENT 'Actual visit identifier when data was captured.',
  `status` ENUM('DRAFT', 'COMPLETE', 'FINALIZED', 'LOCKED', 'CANCELLED') NOT NULL DEFAULT 'DRAFT' COMMENT 'Status of this ISF entry (visit event).',
  `isf_data_version` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Internal version for this ISF entry.',
  `created_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
  `finalized_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `finalized_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`isf_id`),
  INDEX `idx_cdc_isf_study_entity_visit` (`study_internal_id_ref`, `bnx_entity_id`(100), `flow_chart_id_ref`)
  -- ,FOREIGN KEY (`study_internal_id_ref`) REFERENCES `cdc_study`(`study_internal_id`) ON DELETE RESTRICT
  -- ,FOREIGN KEY (`flow_chart_id_ref`) REFERENCES `cdc_flow_chart`(`flow_chart_id`) ON DELETE RESTRICT
) ENGINE=InnoDB COMMENT='Investigator Site File entries - Data collection events.';


-- Tracks instances of forms being filled (The Bridge to DCS)
CREATE TABLE `cdc_form_instance` (
  `form_instance_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `study_internal_id_ref` BIGINT UNSIGNED NOT NULL COMMENT 'FK to cdc_study',
  `bnx_entity_id` VARCHAR(255) NOT NULL COMMENT 'Bintelx entity ID for the patient/subject',
  `visit_num_actual` VARCHAR(255) NOT NULL COMMENT 'Actual visit identifier when data was captured',
  `flow_chart_item_id_ref` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Optional FK to cdc_flow_chart_item',
  `flow_chart_version` VARCHAR(255) NOT NULL COMMENT 'Denormalized: Protocol Flowchart version used for this data.',
  `branch_code_actual` VARCHAR(50) NULL DEFAULT NULL COMMENT 'The specific branch code active when this form instance data was captured/saved.',
  `form_domain` VARCHAR(50) NOT NULL COMMENT 'CDISC Domain code (e.g., VS, DM, AE)',
  `status` ENUM('DRAFT', 'OPEN', 'FINALIZED', 'CANCELLED', 'LOCKED') NOT NULL DEFAULT 'DRAFT' COMMENT 'Status of this specific form instance.',
  `form_version` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Internal version of this form instance data.',
  `data_capture_context_group_id` BIGINT UNSIGNED NULL COMMENT 'FK (Conceptual) to DataCaptureService.context_group.context_group_id',
  `created_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
  `finalized_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
  `locked_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `finalized_at` TIMESTAMP NULL DEFAULT NULL,
  `locked_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`form_instance_id`),
  INDEX `idx_cdc_form_inst_study_entity_visit_domain` (`study_internal_id_ref`, `bnx_entity_id`(100), `visit_num_actual`(50), `form_domain`),
  INDEX `idx_cdc_form_inst_status` (`status`),
  INDEX `idx_cdc_form_inst_dc_context_group_id` (`data_capture_context_group_id`)
  -- ,FOREIGN KEY (`study_internal_id_ref`) REFERENCES `cdc_study`(`study_internal_id`) ON DELETE RESTRICT
  -- ,FOREIGN KEY (`flow_chart_item_id_ref`) REFERENCES `cdc_flow_chart_item`(`flow_chart_item_id`) ON DELETE SET NULL
) ENGINE=InnoDB COMMENT='Tracks instances of clinical data forms being actioned.';

-- Linking table: cdc_isf_form_instances
CREATE TABLE `cdc_isf_form_instance_link` (
    `isf_form_link_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `isf_id_ref` BIGINT UNSIGNED NOT NULL COMMENT 'FK to cdc_isf',
    `form_instance_id_ref` BIGINT UNSIGNED NOT NULL COMMENT 'FK to cdc_form_instance',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`isf_form_link_id`),
    UNIQUE KEY `uq_isf_form_instance` (`isf_id_ref`, `form_instance_id_ref`)
    -- ,FOREIGN KEY (`isf_id_ref`) REFERENCES `cdc_isf`(`isf_id`) ON DELETE CASCADE
    -- ,FOREIGN KEY (`form_instance_id_ref`) REFERENCES `cdc_form_instance`(`form_instance_id`) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Links multiple form instances to a single ISF entry.';

-- Clinical queries or clarifications on specific data fields
CREATE TABLE `cdc_query` (
  `query_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `form_instance_id_ref` BIGINT UNSIGNED NOT NULL COMMENT 'FK to cdc_form_instance',
  `field_name` VARCHAR(255) NOT NULL COMMENT 'The field_name from DataCaptureService being queried',
  `query_text` VARCHAR(255) NOT NULL,
  `status` ENUM('OPEN', 'RESOLVED', 'CLOSED', 'CANCELLED') NOT NULL DEFAULT 'OPEN',
  `created_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
  `resolved_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` TIMESTAMP NULL DEFAULT NULL,
  `resolution_text` VARCHAR(255) NULL DEFAULT NULL,
  PRIMARY KEY (`query_id`),
  INDEX `idx_cdc_query_form_instance_field` (`form_instance_id_ref`, `field_name`(100))
  -- ,FOREIGN KEY (`form_instance_id_ref`) REFERENCES `cdc_form_instance`(`form_instance_id`) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Clinical queries on specific data fields.';