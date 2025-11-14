# 3. EAV Data Store and Versioning

The heart of the Bintelx platform is its data storage and versioning system. It uses a vertical **Entity-Attribute-Value (EAV)** model to provide a flexible, scalable, and fully auditable "single source of truth" for all application data. This architecture allows the platform to store data from any domain (e.g., clinical, sales, inventory) without requiring changes to the core database schema.

The EAV system is composed of three main tables: `DataDictionary`, `ContextGroups`, and `DataValues_history`.

---

## `DataDictionary`

This table defines the "Attribute" in the EAV model. It is a dictionary of every variable or type of data that can be stored in the system.

*   **Purpose:** To provide a schema for the data, defining the name, type, and other metadata for each variable.
*   **Key Concept:** Before a piece of data can be saved, its corresponding variable must be defined here. This prevents the storage of arbitrary, undefined data.

### Schema
* `variable_id` (bigint unsigned, PK)
* `unique_name` (varchar(255), Unique Index) — A system-wide unique name for the variable, e.g., `cdisc.vs.bmi`, `sales.order.item_discount_percent`.
* `label` (varchar(500)) — A human-readable label, e.g., "Body Mass Index".
* `data_type` (varchar(50)) — The primitive data type, e.g., `string`, `decimal`, `datetime`, `boolean`, `entity_ref`.
* `is_pii` (boolean) — Flags whether the data is Personally Identifiable Information.
* `attributes_json` (text, Nullable) — A JSON blob for storing rich metadata for UI rendering, validation rules, data lists, etc.
* `status` (varchar(50)) — `active`, `deprecated`.
* `created_at`, `created_by_profile_id`, `updated_at`, `updated_by_profile_id`

---

## `ContextGroups`

This table records the **event** or **milestone** that groups a set of data changes together. It provides the "why" and "where" for a data modification.

*   **Purpose:** To create a logical "ticket" or "transaction" that links multiple data points recorded at the same time for the same reason.
*   **Example:** When a user saves a form with multiple fields, all the resulting data changes are linked to a single `context_group_id`.

### Schema
* `context_group_id` (bigint unsigned, PK)
* `subject_entity_id` (bigint unsigned, Index) — The subject the event is about (e.g., the patient, the sales order).
* `scope_entity_id` (bigint unsigned, Index, Nullable) — The operational scope of the event (e.g., the company, the clinical study).
* `profile_id` (bigint unsigned, Index) — The actor who initiated the event.
* `timestamp` (datetime, Index) — When the event occurred.
* `parent_context_id` (bigint unsigned, Index, Nullable) — Allows for nesting events (e.g., a "blood pressure reading" event within a "Visit 1" event).
* `context_type` (varchar(100), Index) — The category of the event, e.g., `clinical_study_visit`, `primary_care_visit`, `device_stream`.
* `macro_context`, `event_context`, `sub_context` (varchar(255), Index, Nullable) — Three levels of business-defined context keys (e.g., Study ID, Visit Name, Form Name).
* `status`, `created_at`, `created_by_profile_id`, `updated_at`, `updated_by_profile_id`

---

## `DataValues_history`

This is the most critical table. It stores the "Value" in the EAV model and is the **immutable, single source of truth**. Every version of every piece of data is stored here.

*   **Purpose:** To store every version of every data point, creating a complete, auditable history.
*   **Key Concept:** Data is never updated in place (`UPDATE`). Instead, a new row is inserted with an incremented `version` number, and the previous version is marked as inactive.

### Schema
* `value_id` (bigint unsigned, PK)
* `entity_id` (bigint unsigned, Index) — The subject the data belongs to.
* `variable_id` (bigint unsigned, Index) — What the data is (links to `DataDictionary`).
* `context_group_id` (bigint unsigned, Index) — The event this change belongs to (links to `ContextGroups`).
* `profile_id` (bigint unsigned, Index) — The actor who recorded the data (ALCOA).
* `timestamp` (datetime, Index) — When the data was recorded (ALCOA).
* `inserted_at` (timestamp) — When the row was physically inserted.

**Value Columns**
* `value_string` (varchar(1000), Nullable)
* `value_decimal` (decimal(18, 9), Nullable)
* `value_datetime` (datetime, Nullable)
* `value_boolean` (boolean, Nullable)
* `value_entity_ref` (bigint unsigned, Nullable) — For linking to another entity (object pattern).

**Versioning and ALCOA+ Columns**
* `version` (int unsigned) — The version number for this variable and entity (1, 2, 3...).
* `is_active` (boolean, Index) — **The "hot pointer"**. `true` only for the latest, active version of the data.
* `reason_for_change` (varchar(500), Nullable) — The reason for the change/correction.
* `source_system` (varchar(100), Nullable) — The system that originated the data (e.g., `CDC_APP`, `external.ehr`).
* `device_id` (varchar(100), Nullable) — The specific device that generated the data.

### Strategic Indexes

To ensure performance, this table relies on several key indexes:

1.  **Unique Active Index:** `UNIQUE KEY uq_single_active (entity_id, variable_id, active_only)`
    *   This is a crucial index on a virtual column that guarantees only **one** active version of a variable can exist for an entity at any time, preventing race conditions.
2.  **Current Value Index:** `(entity_id, variable_id, is_active)`
    *   Optimized for the most common query: fetching the current ("hot") value of a variable.
3.  **History Index:** `(entity_id, variable_id, timestamp)`
    *   Optimized for retrieving the complete historical timeline of a variable for a subject.
4.  **Context Index:** `(context_group_id, is_active)`
    *   Optimized for reconstructing a form or event by finding all data associated with a specific context.
