# `CDC\Study` - Core Study Management

**File:** `custom/cdc/Business/Study.php`
**Namespace:** `CDC`

## 1. Purpose

The `Study` class provides the fundamental functionalities for managing the core metadata of clinical studies within the CDC module. It handles the creation, retrieval, and status updates of `cdc_study` records, acting as the primary interface for interacting with study-level information. It ensures that basic study data is managed consistently and provides methods to look up studies using either their public-facing `study_id` or their internal database ID.

## 2. Dependencies

* `bX\CONN`: Used for all database interactions (SELECT, INSERT, UPDATE) with the `cdc_study` table.
* `bX\Log`: Used for logging errors and informational messages during study operations.
* `bX\Profile`: Used internally by methods that create or modify data to capture the `actorUserId`.

## 3. Database Table

* **`cdc_study`**: The primary table managed by this class. Stores essential study details as defined in the consolidated `cdc.sql` (e.g., `study_internal_id`, `study_id`, `study_title`, `status`, `site_initiation_visit_date`, `site_first_patient_visit_date`, actor IDs, timestamps).

## 4. Core Static Methods

*Actor ID for methods creating/modifying data is obtained internally via `\bX\Profile`.*

### `createStudy(array $studyDetails): array`

* **Purpose:** Creates a new clinical study record in the `cdc_study` table. Checks for duplicates by `study_id`.
* **Parameters:**
    * `$studyDetails` (array, **required**): Associative array with study properties:
        * `'study_id'` (string, **required**): Public/protocol identifier.
        * `'study_title'` (string, **required**): Full title.
        * `'sponsor_name'` (string, optional).
        * `'protocol_id'` (string, optional).
        * `'site_initiation_visit_date'` (string 'YYYY-MM-DD HH:MM:SS' or null, optional): Stored as TIMESTAMP.
        * `'site_first_patient_visit_date'` (string 'YYYY-MM-DD HH:MM:SS' or null, optional): Stored as TIMESTAMP.
        * `'status'` (string, optional, default: 'PENDING_SETUP').
* **Returns:** `['success' => bool, 'study_internal_id' => int|null, 'study_id' => string|null, 'message' => string]`

### `getStudyDetails(string $studyId): array`

* **Purpose:** Retrieves full details of a study using its public `study_id`.
* **Parameters:**
    * `$studyId` (string, **required**).
* **Returns:** `['success' => bool, 'study_details' => array|null, 'message' => string]`. `study_details` contains all columns from `cdc_study`.

### `getStudyDetailsByInternalId(int $studyInternalId): array`

* **Purpose:** Retrieves full details of a study using its `study_internal_id`.
* **Parameters:**
    * `$studyInternalId` (int, **required**).
* **Returns:** `['success' => bool, 'study_details' => array|null, 'message' => string]`.

### `updateStudyStatus(string $studyId, string $newStatus): array`

* **Purpose:** Updates the `status` of an existing study.
* **Parameters:**
    * `$studyId` (string, **required**).
    * `$newStatus` (string, **required**).
* **Returns:** `['success' => bool, 'message' => string]`