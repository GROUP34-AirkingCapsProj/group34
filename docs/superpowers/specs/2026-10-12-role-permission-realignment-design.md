# Role Permission Realignment

**Date:** 2026-10-12
**Status:** Approved

## Why

The seeded matrix had drifted from what each role is actually for. Some grants
were copied from legacy "open to all authenticated users" behaviour, some were
convenience during development, and one — PR approval — contradicted the
documented workflow outright. The result was people holding actions that are not
their job, which is the condition the permission system exists to prevent.

The responsibilities, as the client states them:

| Role | Responsibility |
|---|---|
| Admin | All access, focused on configuration |
| Inventory Analyst | Item master, purchasing, monitoring inventory |
| Branch Manager | The admin's right hand: approves purchases, uses the barcode scan features, monitors inventory |
| Warehouse Personnel | Barcode scanning, reporting incidents, warranty, monitoring inventory in their own location only |
| Auditor | Auditing and monitoring inventory, checking for discrepancies |

## Defects this fixes

1. **The branch manager could not approve a purchase request.**
   `purchase_request_approval.approve` seeded to `['admin']`, so the "Manager
   approves?" decision in the procurement flowchart had no one who could make
   it. The same route group gates `GET /purchase-requests/pending/list`, so
   there was no work queue either, and `purchase_request_creation.read` excluded
   them, so even granted `approve` they could not open the request to read it.
2. **Warehouse personnel could approve the warranty claims they filed.**
   `warranty_claims` granted `create` and `approve` to the same role — maker and
   approver in one person.
3. **The auditor held a report-generation grant that could never run.**
   `report_viewing.create` is granted, but `AuditorReadOnly` blocked the POST,
   so the role whose job is checking for discrepancies could only read reports
   somebody else had already generated.
4. **`warranty_records.update` was granted to all five roles including the
   auditor.** It guards `PUT /warranties/{id}/claim`. The seeder comment admits
   it reproduced legacy open-to-everyone behaviour rather than a decision.

## Grants changed

| Feature.action | Before | After |
|---|---|---|
| `purchase_request_approval.approve` | admin | admin, **branch_manager** |
| `purchase_request_creation.read` | admin, analyst | admin, analyst, **branch_manager** |
| `incident_reports.inspect` | admin, analyst, manager | admin, manager |
| `incident_reports.resolve` | admin, analyst | admin, **manager** |
| `inventory_transfers.approve` | admin, analyst | admin |
| `warranty_claims.approve` | admin, manager, warehouse | admin, manager |
| `warranty_records.update` | all five | admin, manager, warehouse |
| `sales_recording.create` / `.update` | admin, manager, warehouse | admin, manager |
| `customer_management.create` / `.update` | admin, manager, warehouse | admin, manager |
| `supplier_management.create` / `.update` | admin, analyst, manager | admin, analyst |
| `item_supplier_mapping.create` / `.update` | admin, analyst, manager | admin, analyst |

`read` is untouched everywhere it appears above. Warehouse personnel keep
`sales_recording.read` and `customer_management.read` because the delivery
receipt needs the customer and the issuance screens show what a unit was sold
against. The branch manager keeps `supplier_management.read` so they can see who
a PO is going to when approving the request behind it.

Warehouse personnel keep the whole picking path — `inventory_issuance` create,
update and `fifo_override`, `delivery_receipt_generation`, `barcode_assignment`,
`barcode_inventory_processing`, `delivery_receiving` — because that is barcode
work. What they lost is recording the sale and maintaining the customer
registry, which are the branch manager's.

## Middleware

`AuditorReadOnly` gains one carve-out: `POST /api/reports/generate`. It is an
export, not a mutation — it writes a file and a `report_runs` row and touches no
business data — and `permission:report_viewing,create` still decides who may
call it. Without it the matrix's deliberate grant was unreachable.

## Two consequences worth stating

**Stock transfer approval is now admin-only.** It left the analyst and was not
given to the branch manager, so `inventory_transfers.approve` has one holder.
Anyone else needs a per-user override, or the grant moves to branch_manager.

**Incident inspection and resolution now sit with the same role.** Before, the
manager could inspect but not resolve, so the examiner and the approver were
different roles — the separation the client's two signature lines describe. With
the analyst pulled out of both, admin and branch_manager each hold the pair, and
the control survives only per user: `IncidentInspectionController` refuses a
confirmation from the same person who submitted the inspection. To make it a
role boundary again, `resolve` has to leave `branch_manager`.

## Database

`2026_10_12_000002_realign_role_permissions` applies the same fifteen changes to
already-deployed databases, since the seeder only runs on a fresh one. It writes
`is_granted` for every role on each affected permission, true and false alike,
matching `RolePermissionsSeeder`'s shape. `down()` restores the previous grants
exactly.

Rows in `user_permission_overrides` are deliberately untouched: an override is an
admin's explicit decision about one person and outranks the role default by
design.

## Diagrams

The detailed use case diagram assigns a primary actor per use case, so five
associations moved with the grants: Manage Suppliers and Map Supplier Products
from Branch Manager to Inventory Analyst, Approve Purchase Request from
Administrator to Branch Manager, Resolve Incident from Administrator to Branch
Manager, and Approve Stock Transfer from Inventory Analyst to Administrator.
Both the `.drawio` and the rendered `.svg` were updated; every association
endpoint is recomputed against the ellipse boundary so the lines still meet the
shapes exactly.

The DFD and the procurement flowchart already showed the branch manager as the
approver — they were right and the matrix was wrong.

## Tests

`RbacRouteMatrixTest` data sets for `suppliers store`, `supplier products map`,
`transfers verify` and `purchase-requests approve` now assert the new boundary,
using a denied role that the change actually denies.

`IncidentInspectionEndpointTest` was built around the analyst being the approver.
Its approver is now a second branch_manager at the scenario's location — a
different user from the inspector, which is what the same-user rule requires —
through a `makeApprover()` helper. The read-back assertion deliberately uses the
inventory analyst, who after the realignment holds `read` but neither `inspect`
nor `resolve`, which is a stronger version of what it was checking.

## Verification

- `php artisan test` — 1,750 passed, 5 skipped, 0 failures
- Migration applied to `airkingvv6` and `airkingV5_test`, and the resulting
  `role_permissions` rows read back and checked against the table above
- Use case `.drawio` and `.svg` re-parsed as XML, and all 44 association lines
  confirmed to land exactly on an ellipse boundary
