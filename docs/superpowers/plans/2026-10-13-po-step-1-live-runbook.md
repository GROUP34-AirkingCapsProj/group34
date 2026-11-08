# Step 1 live deployment and historical reconciliation

These instructions apply to the reviewed Step 1 release, not the Step 2 working tree awaiting review. No live database operation was performed by Codex.

The current local `.env` selects MySQL, `127.0.0.1:3306`, database `airkingvv6`, user `root`. Confirm the actual live server uses those values. Laravel may use cached configuration or shell environment overrides, so the file alone is not proof.

## Backup and cutover

1. Keep a recoverable copy of the deployed backend, frontend build, and configuration outside the web root. Schedule a maintenance window; pause the scheduler and queue workers and drain in-flight requests. Maintenance mode alone does not stop those background writers. Do not allow schema changes during the database dump. Use the tested, reviewed Step 1 release.
2. From `C:\xampp\htdocs\Airking_Inventory\back-end`, confirm Laravel's effective connection without printing credentials:

```powershell
php artisan tinker --execute="dump(DB::connection()->getDriverName(), DB::connection()->getConfig('host'), DB::connection()->getConfig('port'), DB::connection()->getDatabaseName());"
php artisan down --retry=60
```

Stop if this selects the review/test database instead of the intended live database. Do not change connection settings until they have been verified with the deployment operator.

3. Create the backup outside the web root. `--password` prompts securely; press Enter if the configured account has no password. Do not put passwords in command arguments.

```powershell
New-Item -ItemType Directory -Force C:\AirkingBackups | Out-Null
$backup = "C:\AirkingBackups\airkingvv6-step1-$(Get-Date -Format yyyyMMdd-HHmmss).sql"
& C:\xampp\mysql\bin\mysqldump.exe --host=127.0.0.1 --port=3306 --user=root --password --single-transaction --routines --triggers --events --hex-blob --result-file="$backup" airkingvv6
if ($LASTEXITCODE -ne 0) { throw 'Backup failed. Do not migrate.' }
if ((Get-Item -LiteralPath $backup).Length -eq 0) { throw 'Backup is empty. Do not migrate.' }
Get-FileHash -LiteralPath $backup -Algorithm SHA256
```

Verify tables use InnoDB: `--single-transaction` does not provide a consistent snapshot of nontransactional tables. Writers remain paused throughout the backup and migration.

4. Prove the backup restores into a separate database. Pick a new name and keep the restored database disconnected from the live app. The following uses `airking_step1_restore_check_20261013`; if that name already exists, choose another, without dropping the existing database.

```powershell
& C:\xampp\mysql\bin\mysql.exe --host=127.0.0.1 --port=3306 --user=root --password --execute='CREATE DATABASE airking_step1_restore_check_20261013'
if ($LASTEXITCODE -ne 0) { throw 'Restore-check database creation failed.' }
$restoreFile = $backup.Replace('\', '/')
& C:\xampp\mysql\bin\mysql.exe --host=127.0.0.1 --port=3306 --user=root --password --database=airking_step1_restore_check_20261013 --execute="SOURCE $restoreFile"
if ($LASTEXITCODE -ne 0) { throw 'Backup restore verification failed. Do not migrate.' }
```

Check restored PO, receipt, inventory, and movement row counts and representative records against live data while writers remain paused. Backups contain business data; restrict access and retain an off-machine copy.

5. Deploy the Step 1 backend and built frontend under maintenance. Apply only its migration, not every pending migration:

```powershell
php artisan migrate:status
php artisan migrate --path=database/migrations/2026_10_13_000005_add_po_lifecycle_foundation.php --force
if ($LASTEXITCODE -ne 0) { throw 'Migration failed. Keep maintenance active and inspect the database.' }
php artisan po:lifecycle-exceptions --json | Set-Content -LiteralPath C:\AirkingBackups\step1-live-exceptions.json -Encoding utf8
& C:\xampp\mysql\bin\mysql.exe --host=127.0.0.1 --port=3306 --user=root --password --database=airkingvv6 --execute="SELECT migration, batch FROM migrations WHERE migration = '2026_10_13_000005_add_po_lifecycle_foundation'"
```

Record that batch number alongside the backup hash and release copy. Recheck PO line balances and statuses, receipt/stock history, audit access and existing approval screens. Restart workers and schedules using the deployment's usual service controls, then `php artisan up`.

## Rollback

Keep maintenance active and stop background writers. Before rolling back, export the exception report and any reconciliation evidence. Restore the pre-Step-1 application release together with the schema rollback; Step 1 code needs its new columns.

Replace `STEP1_BATCH_NUMBER` below with the recorded integer. The path restricts rollback to this migration; the batch explicitly selects its batch even if a later migration exists. Do not roll back Step 1 while Step 2 or later code remains deployed.

```powershell
php artisan migrate:rollback --batch=STEP1_BATCH_NUMBER --path=database/migrations/2026_10_13_000005_add_po_lifecycle_foundation.php --force
php artisan migrate:status
```

The rollback drops snapshots, original promised dates, replacement links, short-close quantities and the exception table. It preserves original PO columns, receipts, stock movements and payments. It is appropriate before later lifecycle data is used; otherwise preserve that data and plan a forward correction. Reopening traffic requires the old release and verification first.

MySQL DDL is not atomic: a failed `up()` can leave some new columns/tables in place without a migrations row. Do not repeatedly rerun it or assume rollback will find it. Inspect which statements completed on the review copy, then prepare a targeted repair or restore the verified backup under maintenance. A whole-database restore after new transactions occurred would discard those transactions; never use that as an automatic rollback.

## Resolve the flagged records

The report is a permanent record of migration findings, not a live resolution queue. Multiple findings may belong to one PO. Count distinct `po_id` and affected `receiving_detail_id`; placeholder counts in the review message are not actual counts.

For every finding, record its exception ID, PO/line/receipt IDs, supporting documents, reviewer, timestamp and decision. Keep original receipts, stock movements and payments unchanged. No live corrections should be guessed from totals alone.

| Finding | Required evidence and correction |
|---|---|
| `historical_package_size_unverified` | Confirm the original number of pieces per ordered package from the original PO, product specification at that time, or supplier acknowledgement. If the baseline is correct, record confirmation. If wrong, correct only `pieces_per_order_unit` and set `quantity_snapshot_source` to `reconciled` in an Admin-reviewed, audited transaction; then recompute that PO's lifecycle. Never change the current product size to fix an old PO. |
| `original_delivery_date_unverified` | Recover the supplier's original promised date. Correct only `original_expected_delivery_date`, with evidence and audit reason. Retain the current revised expected date. If the promise cannot be recovered, leave it unverified and exclude it from definitive on-time performance conclusions. |
| `missing_product` | Identify or restore the original product record from archive/backup. Do not substitute another product by name. Verify its order-unit snapshot before calculating the balance. |
| `receipt_requires_reconciliation` | Identify the exact original line using delivery documents, warehouse, product and PO identifiers. Null links, wrong-PO links, wrong products and negative quantities need individual review. Do not update historical receipt IDs/quantities to make the report disappear. An approved sidecar allocation/correction mapping is required; that reconciliation tool is not implemented in Steps 1–2. Keep these POs flagged and do not receive more against them. |
| `over_received` | Verify physical receipts and original ordered units first; this may be a wrong historical package-size baseline. If the overfill is real, preserve it as historical overfill. Do not reduce receipts, increase the ordered amount retroactively, or short-close a negative balance. Handle any actual return through the established return/adjustment process. |
| `legacy_status_inconsistent` | Resolve quantity/link issues first. Admin reviews approval evidence and calls `PoReceivingStatusService::sync()` in an audited transaction to derive received status. An empty PO or a formerly Completed PO with zero valid receipts cannot be assumed fulfilled: approval/draft state requires evidence. Rejection is verification/workflow evidence, not proof of cancellation or short-close. |

Use a reviewed reconciliation script with authenticated Admin attribution, PO-then-line locks, a required reason and explicit audit entry in the same transaction. The exception table currently has no resolution fields; do not delete its rows. Correcting a snapshot can fix balances while its original migration warning remains visible. Keep the review register until an approved resolution workflow can link those findings to their decisions.

Do not rerun `PoLifecycleBackfillService::run()` as a blanket live correction: it is a migration baseline, not an evidence-based reconciliation tool.
