# Fold the Full Matrix into per-navigation Configure

**Date:** 2026-07-14
**Area:** `frontend/src/admin/pages/RolePermissions.jsx`, `frontend/src/admin/utils/`

## Problem

The Role & Permissions page has two tabs:

- **Navigation Access** — a flat list of nav bundles, each with a *Configure* button
  opening a per-page mini-matrix that shows only that bundle's single **required**
  feature.
- **Advanced · Full Matrix** — the full feature×role table, grouped into 9 feature-group
  categories (I–IX).

Permissions are hard to locate in the Advanced matrix, and the two views duplicate
concepts. The user wants permissions grouped where they belong: inside the navigation
item's Configure modal.

## Goal

Collapse to a single **Navigation Access** view where:

1. Nav items are grouped under their **sidebar-section** headers (the navigation's own
   category — the 5 real sidebar sections).
2. Each nav item's **Configure** modal surfaces **all** permissions relevant to that page
   (its required feature plus assigned extras), grouped by feature.
3. Every catalog permission remains reachable — orphan features with no natural page land
   in a synthetic **OTHER / NO PAGE** row.
4. The **Advanced · Full Matrix** tab and the tab bar are removed.

No permission *semantics* change — this is a UI reorganization only.

## Approach: frontend mapping (no DB change)

The only new data needed is **which page is the "home" for each feature**. Express it as a
frontend constant (`FEATURE_HOME`) alongside `navBundles.js`, mirroring the existing
"frontend mirror, keep in sync" pattern. No migration, no seeder run. (A DB approach via
`nav_bundle_permissions.is_required=false` rows is possible but overkill for a display
concern.)

## Feature → home mapping

Each nav item's Configure shows its **required feature (R)** plus assigned extras.

| Section | Nav item | Features in its Configure |
|---|---|---|
| ADMINISTRATION | Location Management | location_based_inventory (R) |
| | Archive Management | archive_viewing (R), data_archiving |
| PROCUREMENT & PLANNING | Purchase Requests | purchase_request_creation (R), purchase_request_approval |
| | Purchase Orders | purchase_order_creation (R) |
| | Supplier Network | supplier_management (R), item_supplier_mapping |
| | Item Master | item_master_management (R), barcode_assignment |
| | Categories and Brands | category_management (R), brand_management |
| INVENTORY INTELLIGENCE | Transfer Requests | inventory_transfers (R) |
| | Interbranch Transfers | inventory_transfers (R) *(shared, as today)* |
| SALES & LOGISTICS | Transactions | sales_recording (R), delivery_receipt_generation |
| | Scan Barcode | barcode_inventory_processing (R), delivery_receiving, inventory_issuance, inventory_adjustments |
| COMPLIANCE & SUPPORT | Compliance Review | exception_detection (R) |
| | Warranty Records | warranty_records (R), warranty_claims |
| | System Reports | report_viewing (R) |
| | Alerts & Notices | notification_panel (R) |
| | Audit Trail | audit_trail_viewing (R) |
| **OTHER / NO PAGE** | *(synthetic row)* | user_account_management, role_assignment |

`inventory_transfers` is shared by two bundles (Transfer Requests + Interbranch Transfers)
and appears in both Configure modals — same `permission_id`, harmless, matches today's
behavior.

## Layout & modal

- **List:** nav rows grouped under `sidebar_section` headers, preserving `sort_order`.
  A trailing **OTHER / NO PAGE** group holds the synthetic row for homeless features.
- **Configure modal:** renders **one sub-block per feature** (feature title + its action
  rows × role columns) — the advanced matrix scoped to that page's features. Save /
  pending / admin-lock logic unchanged.
- **Completeness guarantee:** a unit test asserts every feature in the catalog is reachable
  **exactly once** across all homes + OTHER, so a newly added feature cannot be silently
  orphaned.

## Out of scope

- Backend/DB changes.
- Any change to who can grant/see permissions or to the grant-save API.
- Sidebar rendering (`sidebarBuilder`) — unaffected.
