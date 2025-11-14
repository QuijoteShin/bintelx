# 4. DataCaptureService

The `DataCaptureService` is a high-level service that acts as the primary interface for interacting with the EAV (Entity-Attribute-Value) data store. It abstracts away the complexity of the underlying tables (`DataDictionary`, `ContextGroups`, `DataValues_history`) and provides a simple, application-agnostic API for defining, saving, and retrieving versioned data.

Application modules should **always** use this service to interact with the EAV system rather than querying the tables directly.

## Core Responsibilities

*   **Orchestration:** Manages the relationships between data definitions, context groups, and data values.
*   **Transactional Integrity:** Ensures that data is saved atomically. The core `saveRecord` operation, which involves deactivating an old version and inserting a new one, is wrapped in a database transaction to prevent data corruption.
*   **Abstraction:** Provides a clean API that operates on logical application concepts (`applicationName`, `context`, `fieldName`) rather than internal database IDs.

## Key Service Methods

### `defineCaptureField`

*   **Purpose:** Defines a new variable in the `DataDictionary` or updates an existing one. This must be done before any data can be saved for that variable.
*   **Inputs:**
    *   `applicationName`: The namespace for the field.
    *   `fieldDefinition`: An array containing properties like `field_name`, `label`, `data_type`, and `attributes_json`.
    *   `actor_profile_id`: The profile performing the action.
*   **Internal Actions:**
    1.  Constructs a `unique_name` (e.g., `MY_APP.my_field`).
    2.  Inserts or updates the corresponding row in the `DataDictionary` table.

### `saveRecord`

*   **Purpose:** The primary method for saving or updating data. It handles the entire versioning process.
*   **Inputs:**
    *   `applicationName`: The application namespace.
    *   `context`: A set of business keys that identify the record (e.g., `['ORDER_ID' => '123']`).
    *   `fieldsData`: An array of fields to save, each with a `field_name` and `value`.
    *   `actor_profile_id`: The profile saving the data.
    *   `defaultChangeReason`: A default reason for the change.
*   **Internal Actions:**
    1.  **Resolves Context:** Finds or creates a `ContextGroup` based on the provided context keys, linking it to the subject entity and actor profile.
    2.  **Initiates Transaction:** Starts a database transaction.
    3.  **Processes Each Field:**
        *   Resolves the `variable_id` from the `field_name` via the `DataDictionary`.
        *   **Locks the active record:** Uses a `SELECT ... FOR UPDATE` to prevent race conditions.
        *   **Deactivates Old Version:** `UPDATE`s the currently active row for the variable, setting `is_active = false`.
        *   **Inserts New Version:** `INSERT`s a new row with the new value, an incremented `version` number, and `is_active = true`.
    4.  **Commits Transaction:** Commits the changes if all fields were processed successfully. If any step fails, the transaction is rolled back, ensuring the data remains in a consistent state.

### `getRecord`

*   **Purpose:** Retrieves the current, "hot" version of one or more data fields for a given context.
*   **Inputs:**
    *   `applicationName`: The application namespace.
    *   `context`: The business keys identifying the record.
    *   `fieldNames` (optional): The specific fields to retrieve.
*   **Internal Actions:**
    1.  Resolves the `entity_id` and `context_group_id` from the context.
    2.  Queries the `DataValues_history` table for the specified variables `WHERE is_active = true`.
    3.  Joins with `DataDictionary` to return rich metadata (label, data type, etc.) along with the value.

### `getAuditTrailForField`

*   **Purpose:** Retrieves the complete version history for a single field.
*   **Inputs:**
    *   `applicationName`, `context`, `fieldName`.
*   **Internal Actions:**
    1.  Resolves the `entity_id` and `variable_id`.
    2.  Queries the `DataValues_history` table for all versions of that variable, ordered by `version` or `timestamp`.
    3.  Returns a list containing each version's value, timestamp, actor, reason for change, etc.
