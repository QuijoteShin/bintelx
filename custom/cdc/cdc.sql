-- Defines a Clinical Study
CREATE TABLE `cdc_flow_chart` (
  `flow_chart_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `study_internal_id_ref` BIGINT UNSIGNED NOT NULL COMMENT 'Virtual FK to cdc_study',
  `flow_chart_version` VARCHAR(255) NOT NULL COMMENT 'Protocol Flowcart amendment version used in visit Grouping',
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
  INDEX `idx_cdc_flow_chart_study_id` (`study_internal_id_ref`, `is_active`),
) ENGINE=InnoDB COMMENT='Planned schedule of visits and activities for a study';

-- Defines the planned schedule of visits for a study (Flowchart)
CREATE TABLE `cdc_study` (
  `study_internal_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Primary key for the study',
  `study_id` VARCHAR(255) NOT NULL COMMENT 'Public/protocol identifier for the study, e.g., PROT-001',
  `sponsor_name` VARCHAR(255) NULL DEFAULT NULL,
  `protocol_id` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Official protocol ID',
  `study_title` VARCHAR(255) NULL DEFAULT NULL,
  `site_initiation_visit` TIMESTAMP NULL COMMENT 'Site Initital Visit date user TZ.',
  `site_first_visit` TIMESTAMP NULL COMMENT 'Site First Visit On Site TZ.',
  `status` VARCHAR(50) NOT NULL DEFAULT 'PENDING_SETUP' COMMENT 'e.g., PENDING_SETUP, ACTIVE, CLOSED, ARCHIVED',
  `created_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`study_internal_id`),
  UNIQUE KEY `uq_cdc_study_study_id` (`study_id`)
) ENGINE=InnoDB COMMENT='Core details of a clinical study';

-- Links forms/domains (items) to specific flowchart visit entries
CREATE TABLE `cdc_flow_chart_item` (
  `flow_chart_item_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `flow_chart_id_ref` BIGINT UNSIGNED NOT NULL COMMENT 'Virtual FK to cdc_flow_chart (a specific visit entry)',
  `flow_chart_version` VARCHAR(255) NOT NULL COMMENT 'Protocol Flowcart amendment version used in visit Grouping',
  `form_domain` VARCHAR(50) NOT NULL COMMENT 'CDISC Domain code for the form/section (e.g., VS, DM, AE)',
  `item_title` VARCHAR(255) NULL DEFAULT NULL COMMENT 'User friendly title for this item in the visit, e.g., Vital Signs Measurement',
  `item_type` VARCHAR(50) NOT NULL DEFAULT 'FORM' COMMENT 'Type of item, e.g., FORM, SECTION, PROCEDURE',
  `item_order` INT NULL DEFAULT 0 COMMENT 'Order of this item within the visit',
  `is_mandatory` BOOLEAN NOT NULL DEFAULT TRUE,
  `details_json` JSON NULL DEFAULT NULL COMMENT 'Additional details, e.g., specific instructions or sub-form linking',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`flow_chart_item_id`),
  INDEX `idx_cdc_flow_chart_item_flow_chart_id` (`flow_chart_id_ref`),
) ENGINE=InnoDB COMMENT='Specific forms or activities planned for a visit in flowchart';

-- Tracks instances of forms being filled for a patient, study, and visit (aligns with a DataCaptureService context)
CREATE TABLE `cdc_form_instance` (
  `form_instance_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `study_internal_id_ref` BIGINT UNSIGNED NOT NULL COMMENT 'Virtual FK to cdc_study',
  `bnx_entity_id` VARCHAR(255) NOT NULL COMMENT 'Bintelx entity ID for the patient/subject',
  `visit_num_actual` VARCHAR(255) NOT NULL COMMENT 'Actual visit identifier when data was captured',
  `flow_chart_item_id_ref` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Optional Virtual FK to cdc_flow_chart_item, if instance directly corresponds to a planned item',
  `flow_chart_version` VARCHAR(255) NOT NULL COMMENT 'Protocol Flowcart amendment version used in visit Grouping',
  `form_domain` VARCHAR(50) NOT NULL COMMENT 'CDISC Domain code (e.g., VS, DM, AE)',
  `status` ENUM('DRAFT', 'OPEN', 'FINALIZED', 'CANCELLED', 'LOCKED') NOT NULL DEFAULT 'DRAFT' COMMENT 'Status of this form data instance',
  `form_version` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Version of the data within this instance, incremented on saves when status is OPEN or upon finalization',
  `data_capture_context_group_id` BIGINT UNSIGNED NULL COMMENT 'Virtual FK to DataCaptureService.context_group.context_group_id',
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
  INDEX `idx_cdc_form_inst_dc_context_group_id` (`data_capture_context_group_id`),
) ENGINE=InnoDB COMMENT='Tracks instances of clinical data forms being actioned.';

-- Investigator Site File entry - represents a data collection event or "filled eCRF" for a patient visit.
CREATE TABLE `cdc_isf` (
  `isf_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `study_internal_id_ref` BIGINT UNSIGNED NOT NULL COMMENT 'Virtual FK to cdc_study',
  `bnx_entity_id` VARCHAR(255) NOT NULL COMMENT 'Bintelx entity ID for the patient/subject',
  `flow_chart_id_ref` BIGINT UNSIGNED NOT NULL COMMENT 'Virtual FK to cdc_flow_chart, linking to a specific planned visit',
  `flow_chart_version` VARCHAR(255) NOT NULL COMMENT 'Protocol Flowcart amendment version used in visit Grouping',
  `visit_num_actual` VARCHAR(255) NOT NULL COMMENT 'Actual visit identifier when data was captured (can be derived from flowchart or specified if unscheduled)',
  `status` ENUM('DRAFT', 'COMPLETE', 'FINALIZED', 'LOCKED', 'CANCELLED') NOT NULL DEFAULT 'DRAFT' COMMENT 'Status of this ISF entry',
  `isf_data_version` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Version of this ISF entry, incremented on significant changes or finalization',
  -- No direct data_capture_context_group_id here, as an ISF might group multiple form_domains.
  -- Each domain saved within this ISF (via a linking table or by convention in DataCaptureService context) would have its own context.
  -- Alternatively, if an ISF entry itself is a single DataCaptureService context containing all its forms, then it would be here.
  -- Based on "saveDataToISF(isfId, formDomain, ...)" it seems an ISF is a container, and data for each domain within it has its own context.
  -- So, individual form data captured under an ISF entry will likely be managed via cdc_form_instance records linked to this ISF,
  `created_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
  `finalized_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `finalized_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`isf_id`),
  INDEX `idx_cdc_isf_study_entity_visit` (`study_internal_id_ref`, `bnx_entity_id`(100), `flow_chart_id_ref`),
) ENGINE=InnoDB COMMENT='Investigator Site File entries, representing data collection events.';

-- Linking table: cdc_isf_form_instances
-- This table explicitly links multiple cdc_form_instance records to a single cdc_isf entry.
-- This supports the idea that an ISF entry is a collection of forms filled during one patient visit event.
CREATE TABLE `cdc_isf_form_instance_link` (
    `isf_form_link_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `isf_id_ref` BIGINT UNSIGNED NOT NULL,
    `form_instance_id_ref` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`isf_form_link_id`),
    UNIQUE KEY `uq_isf_form_instance` (`isf_id_ref`, `form_instance_id_ref`),
) ENGINE=InnoDB COMMENT='Links multiple form instances to a single ISF entry.';

-- Clinical queries or clarifications on specific data fields
CREATE TABLE `cdc_query` (
  `query_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `form_instance_id_ref` BIGINT UNSIGNED NOT NULL COMMENT 'Virtual FK to cdc_form_instance where the queried data resides',
  -- OR `isf_id_ref` if queries are at ISF level, but form_instance is more granular.
  -- For data in DataCaptureService, we need the context. form_instance_id_ref helps find that context.
  `field_name` VARCHAR(255) NOT NULL COMMENT 'CDISC-like field name from DataCaptureService being queried (e.g., VSORRES_SYSBP)',
  `query_text` VARCHAR(255) NOT NULL,
  `status` ENUM('OPEN', 'RESOLVED', 'CLOSED', 'CANCELLED') NOT NULL DEFAULT 'OPEN',
  `created_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
  `resolved_by_actor_id` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` TIMESTAMP NULL DEFAULT NULL,
  `resolution_text` VARCHAR(255) NULL DEFAULT NULL,
  PRIMARY KEY (`query_id`),
  INDEX `idx_cdc_query_form_instance_field` (`form_instance_id_ref`, `field_name`(100)),
) ENGINE=InnoDB COMMENT='Clinical queries on specific data fields within a form instance.';
