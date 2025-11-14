# 1. Identity and Actors

The Bintelx identity model is built on a triad of tables that separate the concepts of **authentication**, **authorization**, and **data ownership**. This separation provides flexibility and allows a single user to operate in multiple contexts (e.g., different companies, projects, or studies) with different roles and permissions.

---

## `Account`

The `Account` table is the entry point to the system. It is responsible for **authentication** and stores the credentials necessary for a user to log in.

*   **Purpose:** Answers the question, "Who are you as a person logging in?".
*   **Contains:** Secure credentials like username, password hash, and multi-factor authentication (MFA) secrets.
*   **Does Not Contain:** Personal data, roles, or permissions.

### Schema
* `account_id` (bigint unsigned, PK)
* `username` (varchar(255), Unique Index)
* `password_hash` (varchar(255))
* `mfa_secret` (varchar(255), Nullable)
* `last_login` (datetime, Nullable)
* `is_active` (tinyint default 1)
* `status` (varchar(50), default 'active') — e.g., `active`, `locked`, `pending_verification`
* `created_at` (datetime)
* `created_by_profile_id` (bigint unsigned)
* `updated_by_profile_id` (bigint unsigned)
* `updated_at` (timestamp)

---

## `Entity`

The `Entity` table represents the **subjects** of the data within the system. It is the "owner" of the information.

*   **Purpose:** Answers the question, "Who or what is this data about?".
*   **Represents:** People, organizations, companies, projects, studies, devices, locations, etc.
*   **Usage:** Every piece of data stored in the EAV system is linked to an `entity_id`, indicating who or what the data belongs to.

### Schema
* `entity_id` (bigint unsigned, PK)
* `entity_type` (varchar(100), Index) — e.g., `person`, `organization`, `company`, `project`, `study`, `warehouse`, `device`, `address`, `protocol`
* `primary_name` (varchar(500))
* `national_id` (varchar(100), Index, Nullable) — e.g., RUT, DNI
* `national_isocode` (varchar(3), Index, Nullable) — e.g., CL, BR, US
* `status` (varchar(50)) — e.g., `active`, `anonymized`, `merged`
* `created_at` (datetime)
* `created_by_profile_id` (bigint unsigned)
* `updated_by_profile_id` (bigint unsigned)
* `updated_at` (timestamp)

---

## `Profile`

The `Profile` table represents the **actors** who perform actions in the system. It is the "hat" or operational role a user wears when interacting with the platform.

*   **Purpose:** Represents a specific operational context for a user. It is the bridge between an `Account` and the `Entities` they can interact with.
*   **Key Concept:** A single `Account` can have multiple `Profile` records, allowing them to switch between different roles or organizational contexts without needing multiple logins.
*   **ALCOA+:** For auditing purposes, all actions recorded in the system are attributed to a `profile_id`, not an `account_id`.

### Schema
* `profile_id` (bigint unsigned, PK)
* `account_id` (bigint unsigned, Index) — The login that controls this profile.
* `primary_entity_id` (bigint unsigned, Index) — The `person` or `organization` entity that gives authority to this profile (e.g., the company, the clinic).
* `profile_name` (varchar(255)) — A descriptive name for the profile, e.g., "Personal Profile", "Accountant at Bintelx", "Coordinator for Study XYZ".
* `status` (varchar(50)) — e.g., `active`, `inactive`, `passive`
* `created_at` (datetime)
* `created_by_profile_id` (bigint unsigned)
* `updated_by_profile_id` (bigint unsigned)
* `updated_at` (timestamp)
