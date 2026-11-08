# PO lifecycle foundation — Step 1 review

Scope authorized: Step 1 only. Step 2 and later are awaiting review.

## Changes

- Add canonical lifecycle values: draft, open, partially_received, fully_received, short_closed, cancelled.
- Keep legacy status lookup IDs and approval/rejection workflows during the staged rollout. The canonical status is exposed by PO list/detail responses; existing receiving guards and stock recommendations remain for Step 2.
- Derive received pieces from valid PO-linked receipt lines. Compute completion per line; overfill on one line never satisfies a different line.
- Freeze order unit and pieces per order unit at line creation, including PR conversion. Material prices are per package; appliance prices are per piece. No new financial arithmetic is introduced in this step.
- Store short-closed pieces (initially zero), replacement PO/line references, and original promised date. Short-close, cancellation and replacement actions are not enabled yet.
- Add a transactional historical backfill and read-only exception report. Receipts, payments, inventory and stock movement history are never rewritten by the backfill.
- Display canonical status, received/closed/remaining pieces, and historical review warnings in PO details.

## Historical limitations

Old lines never recorded package size. The migration freezes the current product value and reports every legacy material snapshot as unverified. Missing products use a provisional size of one and are flagged. This is a reconciliation baseline, not proof of the original size.

Similarly, existing delivery dates become the original-date baseline and are flagged as unverified: previous rescheduling cannot be reconstructed from the current field alone. Newly created POs retain the original date; if initially absent, the first date assigned becomes the baseline.

Unlinked receipts, cross-PO/product mismatches, negative receipts, over-receipts and inconsistent legacy completion/rejection states are reported. Unlinked/mismatched receipts are not assigned to guessed lines. Canonical balances on these POs require reconciliation. The report also includes archived POs.

Legacy lookup values remain intact; the migration does not infer a short-close from a legacy Completed or Rejected status. The exception table is evidence, not a workflow queue with resolution controls yet.

## Review and testing

Only the new migration was applied to `airkingV5_test` for verification. The configured application database has not been migrated.

An additional disposable copy, `airking_po_step1_test_20261013`, was used to avoid locks from concurrent work on the shared test database. No `.env` files were changed.

Verification: 59 backend tests passed, six frontend status tests passed, and the frontend production build passed. The new migration also rolled back and reapplied successfully on the disposable database. Tests cover receipt/header/stock preservation, package snapshot stability, unlinked receipt reporting, per-line completion, delivery-date baseline preservation and API balances. MySQL was exercised; PostgreSQL was not run in this review.

On a review database, apply only this migration from `back-end`:

```powershell
php artisan migrate --path=database/migrations/2026_10_13_000005_add_po_lifecycle_foundation.php
php artisan po:lifecycle-exceptions
php artisan po:lifecycle-exceptions --json
```

Ensure the connection points to the intended review database. Deploy the migration together with the model/controller changes: those changes require the new columns.

Open a partial PO: verify its canonical status and piece balances against receipt details. Create a material PO for two packages of ten pieces, then change the product's package size: its PO balance must still represent twenty ordered pieces. View historical POs with exceptions and confirm the warning and report context.

```powershell
# From back-end; phpunit.xml selects the isolated test database.
php vendor/bin/phpunit tests/Unit/PoLifecycleTest.php tests/Feature/Inventory/PoReceivingStatusServiceTest.php tests/Feature/Procurement tests/Feature/Inventory/ReceivingDraftSubmitTest.php tests/Feature/Inventory/ReceiveCountTest.php

# From frontend
npm.cmd test -- --runInBand src/admin/utils/poStatus.test.js
```

Receiving restrictions, on-order changes, permission changes including Finance, overdue queue, short-close/reopen actions, invoice matching and credits remain in subsequent steps. Accepted future defaults: fourteen days overdue, zero percent over-receipt tolerance, original-promise performance, below ninety percent fill warning. Bills/payments will be imported; accounting software and import format remain to be specified before the financial phase.
