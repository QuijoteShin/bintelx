# Bintelx Core Architecture Documentation

This documentation outlines the agnostic and extensible core architecture of the Bintelx platform, designed for flexibility, auditability (ALCOA+), and scalability.

## Start Here

👉 **[00_OVERVIEW.md](./00_OVERVIEW.md)**: Quick introduction to the architecture, conceptual model, and main use cases.

## Core Documentation

The architecture is divided into three main pillars, each detailed in its respective document:

1.  [**Identity and Actors (`Account`, `Profile`, `Entity`)**](./01_Identity.md): Describes the core model that separates user authentication from operational roles and data subjects.

2.  [**Roles and Permissions (`Roles`, `EntityRelationships`)**](./02_Roles_and_Permissions.md): Explains the flexible, data-driven system for managing access control and relationships between actors and entities.

3.  [**EAV Data Store and Versioning (`DataDictionary`, `ContextGroups`, `DataValues_history`)**](./03_EAV_and_Versioning.md): Details the powerful Entity-Attribute-Value (EAV) system that provides a complete, auditable, and versioned history for every piece of data in the platform.

4.  [**DataCaptureService**](./04_DataCaptureService.md): Describes the high-level service that orchestrates the EAV system, providing a simple interface for applications to read and write versioned data.

## Additional Resources

*   [**ARCHITECTURE_QA.md**](./ARCHITECTURE_QA.md): Comprehensive Q&A document that answers 14 key architectural questions about multi-company support, roles by project, data segregation, ALCOA compliance, and more.

*   [**target.md**](../../target.md): Complete technical specification of the architecture (IA-readable format).

*   [**Test Suite**](../../app/test/): Executable demonstration tests that validate the architecture and serve as living documentation.

## Design Principles

*   **No Foreign Keys (FKs):** Relational integrity is managed at the application layer.
*   **Agnostic Core:** The core tables are domain-agnostic and can support various applications (clinical trials, sales, inventory, etc.) without modification.
*   **Auditability by Design:** The system is built to comply with ALCOA+ principles, with every data change being attributable, legible, contemporaneous, original, and accurate.
*   **Flexibility:** The EAV model allows for the addition of new data fields without requiring database schema changes.

## Use Cases Covered

✓ Multi-company: One user works in multiple companies with a single login
✓ Roles by project: Different roles in different projects for the same profile
✓ Clinical multi-origin data: Blood pressure from clinic + study + personal device, unified in patient timeline
✓ ALCOA+ compliance: Complete traceability for regulatory compliance
✓ Revocation without data loss: Deactivate employee profile while preserving audit trail
✓ Total flexibility: New domains (sales, inventory, clinical) without schema changes
