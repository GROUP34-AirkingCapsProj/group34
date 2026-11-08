# Step 2 — receiving protection and incoming stock

Scope: Step 2 only. Step 3 is awaiting review. No live migrations or `.env` edits were performed.

The [Step 1 live runbook](2026-10-13-po-step-1-live-runbook.md) contains backup verification, effective connection checks, targeted migration and batch-specific rollback commands, and the decision/evidence required for each migration exception.

## Changes

- Share server-side, transaction-bound receiving validation across scans, counts, staged submit, top-ups and legacy receipt creation. Lock PO header and line before inventory, then check current status, quantity, product, destination and user write scope. A warehouse-scoped user cannot receive at a different warehouse.
- Enforce 0% over-receipt tolerance even when a client supplies `accept_overage=true`. Existing API overage responses remain readable; the confirmation/submit-anyway buttons are removed.
- Block new receipts on Fully Received, Short-Closed and Cancelled POs and fully closed lines. Historical unlinked/cross-PO/wrong-product/negative receipt records require reconciliation before new stock posts.
- Honor the PO's package-size snapshot for scanning, counting, staging, verification, ceilings, and scan progress. Product packaging changes cannot redefine old ordered piece balances.
- Serialize draft mutation and submission and re-read open status/items under locks. A repeated submit cannot post again, and a submitted delivery cannot gain new staged rows.
- Preserve the permitted Fully Received verification exception: no stock increase. Corrections update every affected receipt batch, refuse removal of consumed batch stock and append verification ledger entries while retaining existing ledger entries. This is contents reconciliation, not another receiving action.
- Route legacy receipt creation through shared posting, with exact PO-line resolution, authentic receiver attribution, generated receipt numbers, validated serialized quantities, receipt/batch/tag/ledger consistency and transaction rollback.
- Freeze approved/received PO lines, supplier, destination and totals against general replacement. Receiving statuses cannot be assigned manually through general PO updates. New direct API-created POs begin Draft/Pending rather than bypass approval.
- Prevent archiving active approved POs with remaining quantity. Retain posted receipts permanently and protect referenced POs from force deletion, including generic archive/retention model paths. Legacy barcode correction cannot rewrite a PO receipt; carton verification or an authorized inventory adjustment is required.
- Calculate incoming pieces per line before warehouse/product aggregation: ordered snapshot pieces minus valid received pieces minus short-closed pieces, clamped at zero with historical overfill kept in evidence. Exclude drafts and closed/archived POs, and fall back to the header warehouse for old lines with no destination.
- Expose incoming pieces on inventory list/detail/history and in the inventory detail screen. Record an incoming-data version inside the same database transaction as PO/line/receipt changes. Rebuild the disposable recommendation snapshot on the next read when the version changes; preserve parameter suggestion decisions. Version recording does not depend on a post-commit cache write.
- Grant Inventory Analyst receiving access without granting transfer/issuance processing. Allow Admin and Branch Manager PO approval, enforce that restriction on the server, and keep the analyst's general purchasing edit permission. A permission migration records prior role/navigation grants for rollback and leaves per-user overrides untouched. The navigation bundle and frontend fallback definitions permit the analyst to open receiving.

No short-close/reopen/replacement action, overdue task queue, Finance role, invoice import, payment cap, supplier credit, or performance summary is enabled here. Existing financial valuation arithmetic is outside this quantity-protection step and remains part of the financial phase; Step 2 must not be treated as supplier payment approval controls.

## Review deployment

Use a database copy that already has Step 1. Verify its effective connection using the Step 1 runbook before any migration. Deploy the Step 2 source/build on that review environment and apply only:

```powershell
php artisan migrate --path=database/migrations/2026_10_13_000006_grant_po_receiving_permissions.php
```

The migration grants receiving read/create/update to Inventory Analyst, grants PO update to Branch Manager (the controller restricts their edits to approval/rejection), updates the receiving navigation gate, saves prior values for rollback, and marks the recommendation snapshot for its first refresh. It never changes receipts, stock movements or payments.

Sign out/in or reload permission data before testing newly granted roles. Existing per-user denials still take precedence. The migration is retry-safe for its saved rollback evidence. Do not reseed the whole permission matrix on a live deployment, since that can overwrite unrelated administrator decisions.

Before later administrator permission changes, rollback on the review copy can use the recorded Step 2 batch:

```powershell
php artisan migrate:rollback --batch=STEP2_BATCH_NUMBER --path=database/migrations/2026_10_13_000006_grant_po_receiving_permissions.php
```

Restore the matching Step 1 application release together with a Step 2 permission rollback. The rollback restores role/nav values saved immediately before migration; it would also undo later edits to those same grants, so do not use it blindly after administrative changes. Keep Step 1's schema.

## Manual acceptance

1. As Inventory Analyst, create a material PO for two packages of ten pieces. The analyst can edit the draft and receive but cannot approve it. Auditor verifies; Admin or Branch Manager approves. Warehouse destinations remain enforced.
2. Receive one package containing seven pieces. Stock increases by seven, the PO becomes Partially Received, incoming quantity is thirteen pieces, and each receipt/ledger/batch remains traceable.
3. Change the current product package size on the review copy. The old PO still expects twenty pieces in total. Receiving panels use its original snapshot.
4. Try receiving fourteen more pieces when thirteen remain, including an API request with `accept_overage=true`. Verify the refusal leaves receipt, stock, batch and ledger rows unchanged. The UI offers a correction rather than overage acceptance.
5. Stage a delivery whose combined rows exceed the remainder. Submit: nothing posts and the draft remains editable. Correct it, submit once, then repeat submit and try editing a submitted row: no second stock posting or new staged content.
6. Fully receive the PO. New scans/counts/top-ups fail. Verifying the same contents is allowed; verifying extra stock fails. A verified shortfall reduces the appropriate unconsumed receipt batch, appends a ledger correction and restores incoming remainder. Already consumed batch contents require an inventory adjustment.
7. View inventory incoming quantity and refresh restock recommendations after receiving or changing approved PO state. Previously suppressed recommendations can return after an incoming reduction. Draft quantities do not suppress reorder suggestions.
8. Attempt approved-line replacement, manually setting Completed, deleting a posted receipt, or forcing deletion of a referenced archived PO. Each operation must fail without damaging history.
9. Review an ambiguous historical PO from Step 1: further receiving is blocked with a reconciliation message. Preserve those findings and supporting documents. Do not guess an allocation or clear flags by deleting report rows.

Short-Closed/Cancelled fixtures and short-closed line balances are covered by automated tests; the user-facing short-close action is not implemented until Step 3. Use copied/test data for those fixtures rather than direct live field changes.

## Automated verification

Initial tests ran against `airking_po_step1_test_20261013`, a disposable database. When the local MySQL service stopped, final verification moved to a temporary instance on port 3307, using `airking_po_step2_final_test`, initialized from the repository CI schema and migrations. The stopped live service was not restarted and the real configured database was not migrated. Focused backend regression covers procurement, receiving/count/top-up/verification/staging, receipt history, inventory ledger and restock calculations/scoping. Frontend verification covers inventory detail, receipt rendering, status labels and navigation configuration, plus the production build. Permission migration tests verify previous grants/navigation are restored, retries retain rollback evidence, and per-user overrides are preserved.

The automated stale-quantity and repeated-submit tests verify server revalidation/idempotency; database locks implement concurrent serialization. A separate multi-process stress test was not run. MySQL was exercised; PostgreSQL was not run.
