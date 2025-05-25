# `CDC\Query` - Clinical Data Query Management

**File:** `custom/cdc/Business/Query.php`
**Namespace:** `CDC`

## 1. Purpose

The `Query` class is responsible for managing the lifecycle of clinical data queries (often referred to as Data Clarification Forms - DCFs, or simply queries). These queries are typically raised by monitors (CRAs) or data managers to question or request clarification on specific data points entered into a `FormInstance`.

This class allows for:
* Creating new queries against specific fields within a `FormInstance`.
* Adding responses from site staff to existing queries.
* Resolving and closing queries by authorized personnel.
* Cancelling queries if raised in error or no longer applicable.
* Retrieving lists of queries associated with a `FormInstance` or fetching details of a specific query.

## 2. Dependencies

* `bX\CONN`: For all database interactions with the `cdc_query` table.
* `bX\Log`: For logging operations and errors.
* `bX\Profile`: Used internally to retrieve the `actorUserId` for actions requiring audit.
* `CDC\FormInstance`: To validate that the `form_instance_id` exists and potentially to check the status or other details of the form instance (like `flow_chart_version_actual` and `form_domain`) before a query is raised or actioned.
* `CDC\CRF`: (Potentially, indirectly) To validate that the `field_name` against which a query is raised exists within the form's schema (using `CRF::getFormSchema` with the `flow_chart_version_actual` associated with the `FormInstance`).
* `CDC\Study`: (Indirectly) For context if needed, though queries are primarily linked to `FormInstance`.

## 3. Database Table

* **`cdc_query`**: The primary table managed by this class, reflecting the structure in our consolidated `cdc.sql`. Key columns include:
  * `query_id` (PK)
  * `form_instance_id` (FK to `cdc_form_instance.form_instance_id`)
  * `field_name` (The specific `field_name` from `DataCaptureService` that is being queried)
  * `query_text` (The content/reason for the query)
  * `status` (ENUM: 'OPEN', 'ANSWERED', 'RESOLVED', 'CLOSED', 'CANCELLED')
  * `response_text` (Site's response to the query)
  * `resolution_text` (Data Manager's or resolver's notes on resolution)
  * `created_by_actor_id`
  * `updated_by_actor_id`
  * `resolved_by_actor_id`
  * `created_at`, `updated_at`, `resolved_at` (TIMESTAMPS)

## 4. Key Concepts

* **Query Lifecycle**: The progression of a query through various states: 'OPEN' -> 'ANSWERED' -> 'RESOLVED' -> 'CLOSED'. Queries can also be 'CANCELLED'.
* **Data Point Linkage**: Each query is precisely linked to a `form_instance_id` and a specific `field_name` within that instance's data context.
* **Role-Based Actions**: Different actions on a query (creating, responding, resolving, closing) are typically performed by different user roles, managed by application/UI layer permissions.
* **Auditability**: All actions on a query (creation, status changes, responses) are timestamped and associated with an actor (via `created_by_actor_id`, `updated_by_actor_id`, `resolved_by_actor_id`).

## 5. Core Static Methods

*Actor ID for all methods is obtained internally via `\bX\Profile`.*

### `createQuery(int $formInstanceId, string $fieldName, string $queryText): array`

* **Purpose:** Creates a new query against a specific field in a form instance. Sets initial status to 'OPEN'.
* **Parameters:**
  * `$formInstanceId` (int, **required**): The ID of the `cdc_form_instance` containing the queried data.
  * `$fieldName` (string, **required**): The `field_name` (as known by `DataCaptureService`) being queried.
  * `$queryText` (string, **required**): The text/reason for the query.
* **Returns:** `['success' => bool, 'query_id' => int|null, 'message' => string]`
* **Internal Logic Checks (Recommended during implementation):**
  * Validate existence of `formInstanceId` using `CDC\FormInstance::getFormInstanceDetails`.
  * Fetch `FormInstance` details to get `studyId` (public), `flow_chart_version_actual`, and `form_domain`.
  * Validate that `fieldName` is part of the schema for that `form_domain` and `flow_chart_version_actual` using `CDC\CRF::getFormSchema`.
  * Check if the `FormInstance` status allows new queries (e.g., not 'LOCKED' without specific override permissions).

### `addResponseToQuery(int $queryId, string $responseText): array`

* **Purpose:** Allows site staff or an authorized user to add a response to an existing 'OPEN' query. Typically changes status to 'ANSWERED'.
* **Parameters:**
  * `$queryId` (int, **required**): The ID of the query to respond to.
  * `$responseText` (string, **required**): The text of the response.
* **Returns:** `['success' => bool, 'message' => string]`
* **Internal Logic Checks:** Validate `queryId` exists and current status is 'OPEN'. Updates `response_text`, `status` to 'ANSWERED', `updated_at`, and `updated_by_actor_id`.

### `resolveQuery(int $queryId, string $resolutionText): array`

* **Purpose:** Allows a Data Manager or authorized role to mark a query as resolved. Typically changes status from 'ANSWERED' to 'RESOLVED'.
* **Parameters:**
  * `$queryId` (int, **required**): The ID of the query.
  * `$resolutionText` (string, **required**): Text describing the resolution.
* **Returns:** `['success' => bool, 'message' => string]`
* **Internal Logic Checks:** Validate `queryId` exists and current status is 'ANSWERED'. Updates `resolution_text`, `status` to 'RESOLVED', `resolved_at`, `resolved_by_actor_id`, `updated_at`, and `updated_by_actor_id`.

### `closeQuery(int $queryId, ?string $closingRemarks = null): array`

* **Purpose:** Formally closes a query. Typically changes status from 'RESOLVED' (or 'ANSWERED') to 'CLOSED'.
* **Parameters:**
  * `$queryId` (int, **required**).
  * `$closingRemarks` (string, optional): Final comments.
* **Returns:** `['success' => bool, 'message' => string]`
* **Internal Logic Checks:** Validate `queryId` exists and status allows closing. Updates `status` to 'CLOSED', appends `$closingRemarks` to `resolution_text` (or a dedicated field), updates `updated_at`, and `updated_by_actor_id`.

### `cancelQuery(int $queryId, string $cancellationReason): array`

* **Purpose:** Allows cancellation of a query. Typically changes status from 'OPEN' or 'ANSWERED' to 'CANCELLED'.
* **Parameters:**
  * `$queryId` (int, **required**).
  * `$cancellationReason` (string, **required**).
* **Returns:** `['success' => bool, 'message' => string]`
* **Internal Logic Checks:** Validate `queryId` exists and status allows cancellation. Updates `status` to 'CANCELLED', sets `resolution_text` (or a dedicated field) to `$cancellationReason`, updates `updated_at`, and `updated_by_actor_id`.

### `getQueriesForFormInstance(int $formInstanceId, ?string $status = null): array`

* **Purpose:** Retrieves queries for a `form_instance_id`, optionally filtered by `status`.
* **Parameters:** `$formInstanceId`, `$status` (optional).
* **Returns:** `['success' => bool, 'queries' => array|null, 'message' => string]`

### `getQueryDetails(int $queryId): array`

* **Purpose:** Retrieves full details of a single query.
* **Parameters:** `$queryId`.
* **Returns:** `['success' => bool, 'details' => array|null, 'message' => string]`

## 6. Example Workflow (Simplified Query Lifecycle)

```php
// Assume $formInstanceId = 101; (for a Demographics form instance)
// Assume $fieldName = 'DM_BRTHDTC';
// Actor IDs are obtained internally via \bX\Profile.

// 1. Data Manager creates a query
$createQueryResult = \CDC\Query::createQuery(
    $formInstanceId,
    $fieldName,
    "Birth date seems unlikely for patient's age. Please verify."
);
// ... (check $createQueryResult['success']) ...
$queryId = $createQueryResult['query_id'];

// 2. Site User adds a response
$responseResult = \CDC\Query::addResponseToQuery(
    $queryId,
    "Verified with source document. Birth date is correct as entered."
);
// ... (check $responseResult['success']) ...

// 3. Data Manager resolves the query
$resolveResult = \CDC\Query::resolveQuery(
    $queryId,
    "Response reviewed. Birth date confirmed. No data change needed."
);
// ... (check $resolveResult['success']) ...

// 4. Data Manager closes the query
$closeResult = \CDC\Query::closeQuery($queryId, "Issue resolved with site confirmation.");
// ... (check $closeResult['success']) ...
```

---