-- Ensure you are using the correct database
-- USE `your_database_name`;

-- Drop dependent tables first if they exist and need recreation
DROP TABLE IF EXISTS `capture_definition_version`;
DROP TABLE IF EXISTS `capture_data_version`;
DROP TABLE IF EXISTS `capture_data`;
-- context_group_item and context_group are usually fine unless their structure changes.
DROP TABLE IF EXISTS `context_group_item`;
DROP TABLE IF EXISTS `context_group`;
DROP TABLE IF EXISTS `capture_definition`;
DROP TABLE IF EXISTS `audit_trail_event`; -- Assuming recreation for completeness of script

-- -----------------------------------------------------
-- Table `capture_definition` (MODIFIED)
-- Defines the types of data fields that can be captured per application.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `capture_definition` (
                                                    `definition_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                                                    `application_name` VARCHAR(100) NOT NULL COMMENT 'e.g., CDC_APP, LAB_SYSTEM',
    `field_name` VARCHAR(100) NOT NULL COMMENT 'e.g., DM_BRTHDTC, AE_AETERM. Standardized within the app.',
    `label` VARCHAR(255) NULL COMMENT 'User-friendly label for UI presentation, e.g., "Date of Birth".',
    `data_type` VARCHAR(20) NOT NULL COMMENT 'VARCHAR, NUMERIC, DATE, BOOLEAN. Base storage type.',
    `attributes_json` TEXT NULL COMMENT 'JSON for UI hints, validation, datalists, calculation, constraints. e.g., {"pattern": "regex", "min": N, "control_type": "SELECT_SINGLE", "datalist_source": "Opt1|Opt2"}',
    `is_active` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Whether this field definition is currently active.',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`definition_id`),
    UNIQUE INDEX `uq_app_field_name` (`application_name` ASC, `field_name` ASC)
    ) ENGINE = InnoDB
    COMMENT = 'Stores definitions of data fields with UI/validation attributes.';

-- -----------------------------------------------------
-- Table `context_group` (No changes from your original)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `context_group` (
                                               `context_group_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                                               `application_name` VARCHAR(100) NOT NULL COMMENT 'Links to the app managing this context group',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`context_group_id`),
    INDEX `idx_app_name` (`application_name` ASC)
    ) ENGINE = InnoDB
    COMMENT = 'Groups related data captures for a specific application instance.';

-- -----------------------------------------------------
-- Table `context_group_item` (No changes from your original)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `context_group_item` (
                                                    `context_group_item_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                                                    `context_group_id_ref` BIGINT UNSIGNED NOT NULL COMMENT 'FK to context_group.context_group_id',
                                                    `context_key` VARCHAR(100) NOT NULL COMMENT 'e.g., BNX_PATIENT_ID, STUDY_ID, FORM_DOMAIN',
    `context_value` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`context_group_item_id`),
    INDEX `idx_ctx_group_id` (`context_group_id_ref` ASC),
    UNIQUE INDEX `uq_ctx_group_key` (`context_group_id_ref` ASC, `context_key` ASC),
    INDEX `idx_ctx_key_value` (`context_key` ASC, `context_value` ASC)
    ) ENGINE = InnoDB
    COMMENT = 'Stores key-value pairs that define a specific application context.';

-- -----------------------------------------------------
-- Table `capture_data` (No changes from your original)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `capture_data` (
                                              `capture_data_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                                              `definition_id_ref` BIGINT UNSIGNED NOT NULL COMMENT 'FK to capture_definition.definition_id',
                                              `context_group_id_ref` BIGINT UNSIGNED NOT NULL COMMENT 'FK to context_group.context_group_id',
                                              `field_value_varchar` VARCHAR(4000) NULL DEFAULT NULL,
    `field_value_numeric` DECIMAL(38,10) NULL DEFAULT NULL,
    `current_version_id_ref` BIGINT UNSIGNED NULL,
    `current_sequential_version_num` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`capture_data_id`),
    UNIQUE INDEX `uq_def_ctx_group` (`definition_id_ref` ASC, `context_group_id_ref` ASC),
    INDEX `idx_ctx_group_id_ref` (`context_group_id_ref` ASC)
    ) ENGINE = InnoDB
    COMMENT = 'Stores the current (hot) value of captured data fields.';

-- -----------------------------------------------------
-- Table `capture_data_version` (No changes from your original)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `capture_data_version` (
                                                      `version_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                                                      `capture_data_id_ref` BIGINT UNSIGNED NOT NULL COMMENT 'FK to capture_data.capture_data_id',
                                                      `sequential_version_num` INT UNSIGNED NOT NULL,
                                                      `field_value_varchar_versioned` VARCHAR(4000) NULL DEFAULT NULL,
    `field_value_numeric_versioned` DECIMAL(38,10) NULL DEFAULT NULL,
    `changed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `changed_by_user_id` VARCHAR(100) NOT NULL,
    `change_reason` VARCHAR(500) NULL DEFAULT NULL,
    `signature_type` VARCHAR(50) NULL DEFAULT NULL,
    `event_type` VARCHAR(100) NULL DEFAULT NULL,
    PRIMARY KEY (`version_id`),
    INDEX `idx_capture_data_id_ref` (`capture_data_id_ref` ASC),
    INDEX `idx_capture_data_id_seq_num` (`capture_data_id_ref` ASC, `sequential_version_num` ASC)
    ) ENGINE = InnoDB
    COMMENT = 'Stores historical versions of captured data values.';

-- -----------------------------------------------------
-- Table `capture_definition_version` (MODIFIED to reflect capture_definition changes)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `capture_definition_version` (
                                                            `definition_version_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                                                            `definition_id_ref` BIGINT UNSIGNED NOT NULL COMMENT 'FK to capture_definition.definition_id',
                                                            `effective_from` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp when this version of definition became effective',
                                                            `changed_by_user_id` VARCHAR(100) NOT NULL,
    `change_description` VARCHAR(500) NULL DEFAULT NULL COMMENT 'e.g., ATTRIBUTES_UPDATED, DATA_TYPE_CHANGED',
    -- Storing the full JSON of previous and new states is good for audit
    `previous_definition_json` TEXT NULL DEFAULT NULL COMMENT 'JSON representation of the full definition BEFORE this change',
    `new_definition_json` TEXT NULL DEFAULT NULL COMMENT 'JSON representation of the full definition AFTER this change',
    PRIMARY KEY (`definition_version_id`),
    INDEX `idx_def_id_ref_effective` (`definition_id_ref` ASC, `effective_from` DESC)
    ) ENGINE = InnoDB
    COMMENT = 'Stores versions of capture field definitions.';

-- -----------------------------------------------------
-- Table `audit_trail_event` (No changes from your original)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_trail_event` (
                                                   `event_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                                                   `timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                                   `user_id` VARCHAR(100) NOT NULL,
    `application_name` VARCHAR(100) NULL DEFAULT NULL,
    `event_type` VARCHAR(100) NOT NULL,
    `affected_entity_type` VARCHAR(100) NULL DEFAULT NULL,
    `affected_entity_id` VARCHAR(255) NULL DEFAULT NULL,
    `event_details_json` TEXT NULL DEFAULT NULL,
    PRIMARY KEY (`event_id`),
    INDEX `idx_event_type` (`event_type` ASC),
    INDEX `idx_user_id_timestamp` (`user_id` ASC, `timestamp` DESC),
    INDEX `idx_app_event_time` (`application_name` ASC, `event_type` ASC, `timestamp` DESC)
    ) ENGINE = InnoDB
    COMMENT = 'Stores general audit trail events for applications and system.';