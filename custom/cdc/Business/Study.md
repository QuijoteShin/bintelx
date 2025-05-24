# `CDC\Study` - Core Study Management

**File:** `custom/cdc/Business/Study.php`

## 1. Purpose

The `Study` class provides the fundamental functionalities for managing the core metadata of clinical studies within the CDC module. It handles the creation, retrieval, and status updates of `cdc_study` records, acting as the primary interface for interacting with study-level information. It ensures that basic study data is managed consistently and provides methods to look up studies using either their public-facing `study_id` or their internal database ID.

## 2. Dependencies

* `bX\CONN`: Used for all database interactions (SELECT, INSERT, UPDATE) with the `cdc_study` table.
* `bX\Log`: Used for logging errors and informational messages during study operations.

## 3. Database Table

* **`cdc_study`**: The primary table managed by this class. It stores the essential details of each clinical study.

## 4. Core Static Methods

### `createStudy(array $studyDetails, string $actorUserId): array`

* **Purpose:** Creates a new clinical study record in the `cdc_study` table. It checks for existing studies with the same `study_id` to prevent duplicates.
* **Parameters:**
    * `$studyDetails` (array, **required**): An associative array containing the study's properties:
        * `'study_id'` (string, **required**): Public/protocol identifier (e.g., 'PROT-001').
        * `'study_title'` (string, **required**): Full title of the study.
        * `'sponsor_name'` (string, optional): Name of the sponsor.
        * `'protocol_id'` (string, optional): Official protocol identifier.
        * `'site_initiation_visit'` (timestamp, optional): site initital visit date.
        * `'site_first_visit'` (timestamp, optional): site first visit done visit date.
        * `'status'` (string, optional, default: 'PENDING_SETUP'): Initial status.
    * `$actorUserId` (string, **required**): The ID of the user or system performing the creation.
* **Returns:** `['success' => bool, 'study_internal_id' => int|null, 'study_id' => string|null, 'message' => string]`

### `getStudyDetails(string $studyId): array`

* **Purpose:** Retrieves the full details of a study using its public `study_id`.
* **Parameters:**
    * `$studyId` (string, **required**): The public/protocol identifier of the study.
* **Returns:** `['success' => bool, 'study_details' => array|null, 'message' => string]`. The `study_details` array contains all columns from the `cdc_study` record.

### `getStudyDetailsByInternalId(int $studyInternalId): array`

* **Purpose:** Retrieves the full details of a study using its internal database ID (`study_internal_id`).
* **Parameters:**
    * `$studyInternalId` (int, **required**): The primary key (internal ID) of the study.
* **Returns:** `['success' => bool, 'study_details' => array|null, 'message' => string]`. The `study_details` array contains all columns from the `cdc_study` record.

### `updateStudyStatus(string $studyId, string $newStatus, string $actorUserId): array`

* **Purpose:** Updates the `status` field of an existing study.
* **Parameters:**
    * `$studyId` (string, **required**): The public/protocol identifier of the study.
    * `$newStatus` (string, **required**): The new status to set for the study.
    * `$actorUserId` (string, **required**): The ID of the user or system performing the update.
* **Returns:** `['success' => bool, 'message' => string]`