# Data Versioning and Definition Systems in Bintelx

The Bintelx platform provides several distinct mechanisms for storing, versioning, and defining data. Each system serves a specific purpose and should be chosen based on the nature of the data and the required level of auditability.

The three primary mechanisms are:
1.  **`DataCaptureService`**: For fine-grained, field-level versioning with rich metadata.
2.  **`Snapshot`**: For coarse-grained, record-level versioning.
3.  **`Entity Model`**: For adding multiple, structured data points to an `Entity`.

---

## 1. `DataCaptureService` (DCS)

This is the most advanced and granular system, designed for data that requires a high degree of compliance and auditability (like GxP in clinical trials).

* **Main Purpose**: To capture and version individual data points over time, maintaining a complete and auditable history of every single change.
* **Versioning Mechanism**: **Fine-Grained (Field-Level)**. Every call to `saveRecord()` that modifies a value creates a new, immutable record in the `capture_data_version` table. This tracks who, what, when, and why for each change to a single field.
* **Data Definition**:
    * **Free Definition? No.** You cannot save data for an arbitrary key. Every field (e.g., `VSORRES_SYSBP`) must first be formally defined in the `capture_definition` table using the `DataCaptureService::defineCaptureField` method. This ensures that all captured data adheres to a pre-defined schema.
    * **Special Attributes (e.g., HTML pattern)? Yes, absolutely.** This is a core feature. The `defineCaptureField` method accepts an `attributes_json` parameter. This allows you to store rich, structured metadata about the field, such as validation rules, UI hints, default values, or, as you mentioned, an HTML input `pattern`.
* **Ideal Use Case**: Capturing clinical trial data (as in your CDC module), tracking financial transaction details, or any scenario where a complete, field-level audit trail is a requirement.

---

## 2. Snapshot

This system provides a simpler, less granular form of versioning.

* **Main Purpose**: To save a complete "photograph" of an entire record from another table (like `entity` or `order`) at a specific moment in time.
* **Versioning Mechanism**: **Coarse-Grained (Record-Level)**. It doesn't track individual field changes. Instead, it stores a full JSON representation of the object's state in the `snapshot_data` column. It answers the question, "What did this entire order look like when it was first created?".
* **Data Definition**:
    * **Free Definition? Yes.** The `snapshot_data` column is of type `JSON`, which is inherently schema-less. You can store any valid JSON structure you wish, giving you complete flexibility.
    * **Special Attributes? No.** The snapshot system itself is "unaware" of the meaning of the data within the JSON blob. It has no mechanism to define or enforce attributes like an HTML pattern for a field nested inside the JSON. It just stores the blob as-is.
* **Ideal Use Case**: Logging the state of a purchase order upon creation and completion, saving the state of a user's profile before a major change, or creating restore points for complex configuration objects.

---

## 3. Entity Model

This is not primarily a versioning system, but a structural tool for adding data to an `Entity`.

* **Main Purpose**: To associate multiple, structured data points of a specific type (like addresses, phone numbers, emails) to a single `Entity` record.
* **Versioning Mechanism**: **None, directly.** The `entity_model` table itself does not have a version history. Like the `entity` table, changes to a record would be `UPDATE` statements. Versioning could be achieved indirectly by linking these records to the `snapshot` system if required.
* **Data Definition**:
    * **Free Definition? No.** The structure is strictly defined by the columns of the `entity_model` table: `entity_model_type`, `entity_model_prime`, `entity_model_name`, `entity_model_value`, etc.. You cannot add arbitrary keys.
    * **Special Attributes? No.** Similar to `snapshot`, there is no dedicated column or mechanism within the `entity_model` table to store rich metadata (like an HTML `pattern`) for the `value` fields.
* **Ideal Use Case**: Storing the contact information for an `Entity`. For example, a single `Entity` can have multiple `entity_model` records with `entity_model_type = 'phone'`, and one of them can be marked as the primary contact with `entity_model_prime = 1`.

### Summary Table

| Feature | `DataCaptureService` | `Snapshot` | `Entity Model` |
| :--- | :--- | :--- | :--- |
| **Main Purpose** | Fine-grained data capture & audit | Coarse-grained record state history | Structured entity contact/profile data |
| **Versioning Granularity** | **Field-Level** | **Record-Level** | None (directly) |
| **Data Structure** | Pre-defined via `defineCaptureField` | Flexible (JSON blob) | Fixed (Table columns) |
| **Allows Free Definition?** | No | Yes | No |
| **Supports Rich Attributes?** | **Yes** (via `attributes_json`) | No | No |
| **Ideal Use Case** | Clinical trial data (CDC), financial ledgers | Saving an `order` state on creation | Storing multiple phone numbers for a client |