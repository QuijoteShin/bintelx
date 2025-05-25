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
* `CDC\FormInstance`: To validate that the `form_instance_id` exists and potentially to check the status or other details of the form instance before a query is raised or actioned.
* `CDC\CRF`: (Potentially, indirectly) To validate that the `field_name` against which a query is raised exists within the form's schema for the relevant `flow_chart_version_actual` associated with the `FormInstance`.
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
    * `created_at`, `updated_at`, `resolved_at`

## 4. Key Concepts

* **Query Lifecycle**: The progression of a query through various states: 'OPEN' -> 'ANSWERED' -> 'RESOLVED' -> 'CLOSED'. Queries can also be 'CANCELLED'.
* **Data Point Linkage**: Each query is precisely linked to a `form_instance_id` and a specific `field_name` within that instance's data context.
* **Role-Based Actions**: Different actions on a query (creating, responding, resolving, closing) are typically performed by different user roles.
* **Auditability**: All actions on a query (creation, status changes, responses) are timestamped and associated with an actor.

## 5. Core Static Methods (Proposed)

*Actor ID for all methods is obtained internally via `\bX\Profile`.*

### `createQuery(int $formInstanceId, string $fieldName, string $queryText): array`

* **Purpose:** Creates a new query against a specific field in a form instance. Sets initial status to 'OPEN'.
* **Parameters:**
    * `$formInstanceId` (int, **required**): The ID of the `cdc_form_instance` containing the queried data.
    * `$fieldName` (string, **required**): The `field_name` (as known by `DataCaptureService`) being queried.
    * `$queryText` (string, **required**): The text/reason for the query.
* **Returns:** `['success' => bool, 'query_id' => int|null, 'message' => string]`
* **Internal Logic Checks:**
    * Validates existence of `formInstanceId`.
    * (Recommended) Validates that `fieldName` is a valid field for the `form_domain` and `flow_chart_version_actual` associated with the `formInstanceId` (may involve `CDC\FormInstance::getFormInstanceDetails` and `CDC\CRF::getFormSchema`).
    * (Recommended) Checks if the `FormInstance` status allows new queries (e.g., not 'LOCKED' without specific override permissions).

### `addResponseToQuery(int $queryId, string $responseText): array`

* **Purpose:** Allows site staff or an authorized user to add a response to an existing 'OPEN' query. Typically changes status to 'ANSWERED'.
* **Parameters:**
    * `$queryId` (int, **required**): The ID of the query to respond to.
    * `$responseText` (string, **required**): The text of the response.
* **Returns:** `['success' => bool, 'message' => string]`
* **Internal Logic Checks:**
    * Validates `queryId` exists and its current status is 'OPEN' (or other permissible status for response).
    * Updates `response_text`, `status` to 'ANSWERED', `updated_at`, and `updated_by_actor_id`.

### `resolveQuery(int $queryId, string $resolutionText): array`

* **Purpose:** Allows a Data Manager or authorized role to mark a query as resolved after reviewing the response and/or data. Typically changes status from 'ANSWERED' to 'RESOLVED'.
* **Parameters:**
    * `$queryId` (int, **required**): The ID of the query.
    * `$resolutionText` (string, **required**): Text describing the resolution or confirmation.
* **Returns:** `['success' => bool, 'message' => string]`
* **Internal Logic Checks:**
    * Validates `queryId` exists and its current status is 'ANSWERED' (or other permissible status for resolution).
    * Updates `resolution_text`, `status` to 'RESOLVED', `resolved_at`, `resolved_by_actor_id`, `updated_at`, and `updated_by_actor_id`.

### `closeQuery(int $queryId, ?string $closingRemarks = null): array`

* **Purpose:** Formally closes a query. This is often the final step after resolution. Typically changes status from 'RESOLVED' (or 'ANSWERED' if workflow allows direct close) to 'CLOSED'.
* **Parameters:**
    * `$queryId` (int, **required**): The ID of the query.
    * `$closingRemarks` (string, optional): Any final comments for closing. Can be appended to `resolution_text` or stored separately if a field exists.
* **Returns:** `['success' => bool, 'message' => string]`
* **Internal Logic Checks:**
    * Validates `queryId` exists and its current status allows closing (e.g., 'RESOLVED' or 'ANSWERED').
    * Updates `status` to 'CLOSED', `updated_at`, and `updated_by_actor_id`. `resolved_at` and `resolved_by_actor_id` should ideally be set during the 'RESOLVE' step.

### `cancelQuery(int $queryId, string $cancellationReason): array`

* **Purpose:** Allows cancellation of a query if it was raised in error or is no longer relevant. Typically changes status from 'OPEN' or 'ANSWERED' to 'CANCELLED'.
* **Parameters:**
    * `$queryId` (int, **required**): The ID of the query.
    * `$cancellationReason` (string, **required**): Reason for cancellation.
* **Returns:** `['success' => bool, 'message' => string]`
* **Internal Logic Checks:**
    * Validates `queryId` exists and status allows cancellation.
    * Updates `status` to 'CANCELLED', sets `resolution_text` (or a dedicated cancellation field) to `$cancellationReason`, updates `updated_at`, and `updated_by_actor_id`.

### `getQueriesForFormInstance(int $formInstanceId, ?string $status = null): array`

* **Purpose:** Retrieves all queries (or queries filtered by a specific `status`) associated with a given `form_instance_id`.
* **Parameters:**
    * `$formInstanceId` (int, **required**).
    * `$status` (string, optional): If provided, filters queries by this status.
* **Returns:** `['success' => bool, 'queries' => array|null, 'message' => string]`
    * `queries`: An array of query detail objects.

### `getQueryDetails(int $queryId): array`

* **Purpose:** Retrieves the full details of a single query by its `query_id`.
* **Parameters:**
    * `$queryId` (int, **required**).
* **Returns:** `['success' => bool, 'details' => array|null, 'message' => string]`
    * `details`: An associative array containing all fields from the `cdc_query` record.

## 6. Example Workflow (Simplified Query Lifecycle)

```php
<?php
// Assume $formInstanceId = 101; (for a Demographics form instance)
// Assume $fieldName = 'DM_BRTHDTC';
// Assume Data Manager actorId is available via \bX\Profile, Site User actorId too.

// 1. Data Manager creates a query
$createQueryResult = \CDC\Query::createQuery(
    $formInstanceId,
    $fieldName,
    "Birth date seems unlikely for patient's age. Please verify."
);

if (!$createQueryResult['success']) {
    // Handle error
    exit("Failed to create query: " . $createQueryResult['message']);
}
$queryId = $createQueryResult['query_id'];
\bX\Log::logInfo("Query $queryId created for FormInstance $formInstanceId, Field $fieldName.");

// 2. Site User adds a response
// (Site User logs in, their actorId is now in \bX\Profile)
$responseResult = \CDC\Query::addResponseToQuery(
    $queryId,
    "Verified with source document. Birth date is correct as entered. Patient appears younger."
);
if ($responseResult['success']) {
    \bX\Log::logInfo("Response added to Query $queryId.");
}

// 3. Data Manager resolves the query
// (Data Manager logs in)
$resolveResult = \CDC\Query::resolveQuery(
    $queryId,
    "Response reviewed. Birth date confirmed with site. No data change needed."
);
if ($resolveResult['success']) {
    \bX\Log::logInfo("Query $queryId resolved.");
}

// 4. Data Manager closes the query
$closeResult = \CDC\Query::closeQuery($queryId);
if ($closeResult['success']) {
    \bX\Log::logInfo("Query $queryId closed.");
}

// Later, to view queries for the form instance:
// $queriesResult = \CDC\Query::getQueriesForFormInstance($formInstanceId);
// if ($queriesResult['success']) {
//     print_r($queriesResult['queries']);
// }
```
