# 2. Roles and Permissions

The Bintelx permission system is designed to be both powerful and flexible. It moves access control logic from hard-coded application logic to a data-driven model. This is achieved through two core tables: `Roles` and `EntityRelationships`.

Modules and applications should never check for permissions based on a user's `profile_id`. Instead, they should ask, "Does this profile have a specific role in this context?" (e.g., `hasRole(profile_id, entity_id, 'project.manager')`).

---

## `Roles`

This table is a centralized catalog of all the textual roles that can be assigned within the system. Modules can define the roles they require, and these roles can be reused across different applications.

*   **Purpose:** To define a dictionary of available roles and their scopes.
*   **Key Concept:** Roles are defined by a `role_code` (e.g., `company.warehouse`, `clinical.pi`), which is what application logic should check against. This decouples the application from the internal `role_id`.

### Schema
* `role_code` (varchar(100), PK) — e.g., `company.warehouse`, `project.manager`, `clinical.pi`, `study.coordinator`, `system.auditor`
* `role_label` (varchar(255)) — A human-readable label, e.g., "Warehouse Manager", "Principal Investigator".
* `description` (varchar(1000), Nullable) — A functional description of the role.
* `scope_type` (varchar(50)) — The type of entity this role typically applies to, e.g., `organization`, `project`, `study`, `warehouse`, `global`.
* `status` (varchar(50), default `active`)
* `created_at` (datetime)
* `created_by_profile_id` (bigint unsigned)
* `updated_at` (timestamp)
* `updated_by_profile_id` (bigint unsigned)

---

## `EntityRelationships`

This is the pivot table that connects everything. It defines the many-to-many relationships between profiles (actors) and entities (subjects/scopes), and specifies the role that the profile has in that relationship.

*   **Purpose:** To grant a `Profile` a specific `Role` over an `Entity`.
*   **Semantics:** This table answers the question, "Who can do what, and to what?".

### Schema
* `relationship_id` (bigint unsigned, PK)
* `profile_id` (bigint unsigned, Index) — The actor (who).
* `entity_id` (bigint unsigned, Index) — The scope or subject (on what).
* `relation_kind` (varchar(50)) — The nature of the relationship, e.g., `owner`, `membership`, `permission`.
* `role_code` (varchar(100), Nullable, Index) — The specific role granted in this relationship (links to `Roles`).
* `grant_mode` (varchar(20), Nullable) — `active` (role applies when context is explicitly selected) or `passive` (permissions are always on).
* `relationship_label` (varchar(255), Nullable) — A user-friendly label, e.g., "My Employer", "Central Warehouse".
* `status` (varchar(50)) — `active`, `inactive`.
* `created_at` (datetime)
* `created_by_profile_id` (bigint unsigned)
* `updated_by_profile_id` (bigint unsigned)
* `updated_at` (timestamp)

### Key Use Cases

1.  **Ownership:** A `profile_id` can have an `owner` relationship with an `entity_id`. This typically grants full control over the entity and its sub-entities. For example, a user's personal profile is the owner of their corresponding "person" entity.

2.  **Membership:** A profile can be a `member` of an organization, granting baseline access without special permissions.

3.  **Granular Permissions:** The most common use case is a `permission` relationship that assigns a specific `role_code` to a profile for a given entity.

    *   **Example:** To make a user a "Warehouse Manager" for the "Central Warehouse", you would insert a row:
        *   `profile_id`: The user's profile ID.
        *   `entity_id`: The entity ID of the "Central Warehouse".
        *   `relation_kind`: `permission`.
        *   `role_code`: `company.warehouse`.

    *   The inventory module would then check `hasRole(current_profile, warehouse_entity, 'company.warehouse')` to decide whether to show administrative buttons. This check is clean, readable, and entirely data-driven.
