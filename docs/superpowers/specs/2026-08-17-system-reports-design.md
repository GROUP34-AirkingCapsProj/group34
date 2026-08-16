# System Reports — Design

**Date:** 2026-08-17
**Status:** Approved

## Problem

`frontend/src/admin/pages/SystemReports.jsx` is entirely mock. Six report cards are
hardcoded in the component. "Generate Report" fires a toast. "Schedule Report" says
"coming soon". "Recent Reports" lists four invented filenames with invented dates and
file sizes. The page makes no API call.

The backend already holds the raw material. `routes/api.php` has a `reports/` group of
seven inline closures (`inventory-valuation`, `low-stock-report`, `sales-summary`,
`movement-history`, `adjustments-summary`, `receivings-summary`,
`profit-loss-summary`) returning raw paginated JSON. Nothing in the frontend calls any
of them. PDF generation is well established: `barryvdh/laravel-dompdf` rendering blade
views, used in seven controllers (`purchase_orders.document`, `ist.receipt`,
`warranty.claim_receipt`, and others). The data for all six cards exists —
`locations.capacity` is populated, `login_activities` records logins,
`inventory.reorder_level` and `products.recommended_stocks` carry stock thresholds.

The page was simply never wired to any of it.

## Scope

In scope: working generation for all six reports, and a truthful Recent Reports list
backed by stored files.

Out of scope: scheduled reports. The "Schedule Report" button is removed rather than
left as a lie. Scheduling requires a queue worker, a scheduler daemon, and mail
configuration, and is worthless until generation is solid.

## Decisions

| Decision | Choice | Reason |
|---|---|---|
| Scope | Generate + Recent Reports | Makes every element on the screen truthful. Scheduling is a separate project. |
| Parameters | Per-report modal dialog | The six reports honor genuinely different filters. A shared filter bar would show inputs that do nothing for half the cards — the same dishonesty being fixed. |
| Recent Reports storage | Store the generated file, 90-day retention | A run is a point-in-time record. Regenerating on demand would show today's numbers under last week's timestamp. |
| Formats | PDF + CSV | No spreadsheet library is installed, and the real workflow is PDF handed to a manager. CSV opens in Excel and streams without memory pressure. Cards are relabelled "PDF / CSV". |
| Location Performance | Stock movement + valuation, no revenue | Sales are recorded through Transactions, not the `sales` table. A revenue section would report zeros. |
| "Branch Performance" renamed | Location Performance | Branches and warehouses were unified into `locations`; the old title named a table that no longer exists. |
| Architecture | Report registry, one class per report | The six reports differ in query shape but share all plumbing: scoping, rendering, storage, sizing, audit row. |

## Architecture

### Report registry

`app/Reports/` holds a `ReportDefinition` interface and one class per report. Each
declares:

- `key()` — stable slug (`inventory-summary`), used in URLs and filenames
- `title()` / `description()` — card text
- `parameters()` — filters honored, each with a type (`date_range`, `location`,
  `category`, `select`) and a required flag
- `formats()` — `['pdf', 'csv']`
- `pdfRowCap()` — max rows renderable as PDF
- `availableTo(User $user): bool` — whether this user can run it at all
- `build(ReportParameters $params, User $user): ReportDataset` — rows plus summary
  totals

`ReportRegistry` holds the six, keyed by slug. Nothing outside it knows the list.

The frontend renders cards and dialogs from these declarations, so a card cannot
advertise a filter or format the backend does not implement. The card *is* the
backend's declaration.

### Runner

`ReportRunner` performs everything common, in order:

1. Resolve the definition from the registry.
2. Validate submitted parameters against `parameters()`. Unknown or unsupported
   parameters are rejected with 422, never silently ignored.
3. Call `build()`.
4. If the dataset is empty, abort with 422 (see Failure handling).
5. If format is PDF and row count exceeds `pdfRowCap()`, abort with 422.
6. Render via `PdfReportRenderer` (blade view, following the existing
   `purchase_orders.document` pattern) or `CsvReportRenderer` (streamed writer).
7. Write the file to `storage/app/reports/`.
8. Insert the `report_runs` row with the real byte size.

### Location scoping

Scoping is each definition's responsibility, not the runner's. `build()` receives the
`User` and applies `forUser()` from the existing
`app/Models/Concerns/ScopesToUserLocation.php` trait, which returns unfiltered results
for centralized users, filters to `location_id` for scoped users, and yields an empty
result for a scoped user with no assigned location.

This must be per-report because the underlying models differ. `Inventory` already uses
the trait; `login_activities` has no `location_id` column at all. Placing scoping in
the runner would require it to know every report's tables, defeating the split.

### Data model

New table `report_runs`:

| Column | Type | Notes |
|---|---|---|
| `report_run_id` | bigint PK | |
| `report_key` | string | e.g. `inventory-summary` |
| `title_snapshot` | string | title at generation time |
| `format` | string | `pdf` or `csv` |
| `parameters` | json | as submitted |
| `file_path` | string nullable | null once pruned |
| `file_name` | string | download filename |
| `file_size_bytes` | unsigned bigint | real size on disk |
| `row_count` | unsigned int | |
| `generated_by` | bigint FK users | |
| `created_at` | timestamp | |
| `expires_at` | timestamp | `created_at` + 90 days |
| `file_pruned_at` | timestamp nullable | set when the file is deleted |

The `parameters` snapshot is what allows an expired row to offer "re-run with the same
filters".

### API

All routes sit under the existing `auth:sanctum` group.

| Route | Permission | Purpose |
|---|---|---|
| `GET /api/reports/catalog` | `report_viewing,read` | Definitions available to this user |
| `POST /api/reports/generate` | `report_viewing,create` | `{report_key, format, parameters}` → the `report_runs` row |
| `GET /api/reports/runs` | `report_viewing,read` | Paginated recent runs |
| `GET /api/reports/runs/{id}/download` | `report_viewing,read` | Serves the stored file |

The download route uses the `auth.query.token` middleware, matching the existing PDF
download routes at the bottom of `routes/api.php`, so the browser can fetch it
directly. View is the same endpoint with `Content-Disposition: inline` for PDFs.

`report_viewing` currently declares only a `read` action in
`database/seeders/PermissionSeeder.php`. A `create` action is added, granted to the
same roles (`admin`, `inventory_analyst`, `auditor`) — generating and storing a file is
a write, and `read` alone should not authorize it.

### Cleanup

The seven closures in the `reports/` route group are deleted. Nothing calls them, and
`low-stock-report` is actively wrong: it compares stock against the global
`products.recommended_stocks` while ignoring the per-location
`inventory.reorder_level`, so it cannot report per-location shortfalls. The correct
logic moves into the Low Stock definition.

## The six reports

### 1. Inventory Summary

Parameters: location, category (both optional). No date range — this is a live
snapshot, and the report header says so explicitly. There is no historical stock table
from which an as-of date could be reconstructed.

Rows: product, category, brand, location, qty on hand, available qty, reorder level,
cost price, stock value.
Totals: SKU count, total units, total valuation from `products.cost_price`.

### 2. Transaction Report

Parameters: date range (required), location, transaction type.
Source: `transactions` (`branch_id`, `type`, `quantity`, `unit_price`,
`total_amount`, `date`). Note that `branch_id` is a legacy column name that holds a
`location_id` — `Transaction::branch()` already resolves it to `Location`. The column
is left alone; only report-facing labels say "location".

Rows: date, transaction code, type, location, product, qty, unit price, total.
Totals: count and value per type, grand total.

This is the report most likely to be large, which is why CSV streams rather than
buffering.

### 3. Location Performance

Key `location-performance`. Title "Location Performance", description "Location-wise
inventory movement and valuation".

There is no such thing as a branch in this system. The
`2025_01_15_000000_update_locations_unified` migration folded the separate `branches`
and `warehouses` tables into a single `locations` table with a `location_type` of
`warehouse`, `showroom`, or `branch_office`, and the sidebar has said "Location
Management" ever since. The card's old "Branch Performance" title named a table that
no longer exists.

Parameters: date range (required), location, location type.

Per location: units in (receivings + transfers in), units out (issuances + transfers
out), net adjustments, closing stock units, closing valuation. Each row also carries
the location's type, so a warehouse and a showroom are never silently averaged
together in a reader's head.

Movement comes from `material_movement_ledger` grouped by `from_location_id` /
`to_location_id` and `transaction_type_id`. Valuation joins `inventory` to
`products.cost_price`. No revenue section.

### 4. Low Stock

Parameters: location, category. No date range.

The effective threshold is `inventory.reorder_level`, falling back to
`products.recommended_stocks` where the per-location value is null.

Rows: product, location, qty on hand, threshold used, shortfall, supplier. The
threshold column names which source applied, so a row is never mysteriously flagged.
Totals: count of items below threshold.

### 5. Warehouse Utilization

Parameters: location type. Point-in-time.

Rows: location, type, capacity, occupied units (`SUM(quantity_on_hand)`), utilization
percentage, remaining capacity.

Locations with a null `capacity` render as "not set" and are excluded from the
aggregate percentage — never 0%, never a division error.

### 6. User Activity

Parameters: date range (required), user, outcome (success/failure).
Source: `login_activities` — timestamp, user, attempted username, outcome, failure
reason, IP address.
Totals: logins, unique users, failed attempts.

The card's subtitle promises "system usage and login statistics", which suggests a
per-user action count alongside the logins. There is no source for it:
`app/Models/ActivityLog.php` references a table `activity_log` that has no migration
and does not appear in the schema dump — the model is orphaned. The report therefore
covers logins only, and the card's description is reworded to "Login and session
activity" so it stops promising data the system does not record.

`login_activities` has no `location_id`, so this report cannot be location-scoped. Its
`availableTo()` returns true only for centralized users, and `GET /reports/catalog`
omits the card entirely for a location-scoped user rather than showing a card that
returns 403.

## Frontend

`SystemReports.jsx` keeps its current appearance; `system_reports.css` is retained and
extended. Both hardcoded arrays (`reportCards`, `recentReports`) are deleted.

On mount the page fetches `/reports/catalog` and `/reports/runs`. Cards render from the
catalog, so the "Available Formats" line prints what the definition returned. The
Schedule button is removed.

`GenerateReportDialog` builds its inputs from the definition's `parameters()`:
`date_range` renders two date inputs, `location` renders a select fed by the user's
accessible locations, `category` renders a category select. Only declared parameters
appear — Low Stock renders location and category but no date inputs, because its
definition declares none. The format radio comes from `formats()`.

Recent Reports renders real values: filename, report type, generated timestamp, who ran
it, and true byte size formatted for display. Download and View both hit
`/reports/runs/{id}/download`. A pruned row renders greyed with "File expired" and a
**Re-run** action opening the dialog prefilled from the stored parameters — labelled as
a new run, never as recovery of the old file.

Three states the current page lacks are added: a skeleton while the catalog loads, an
empty state when no reports have been generated, and an inline error when generation
fails.

New API methods are added to `frontend/src/admin/services/api.js` following the
existing conventions in that file.

## Failure handling

**No matching data.** Generation returns 422 with "No data matched these filters" and
writes neither a file nor a `report_runs` row. An empty PDF in Recent Reports is
exactly the junk that makes a reports page untrustworthy.

**Oversized PDF.** dompdf exhausts memory on very large tables. Each definition
declares a PDF row cap, starting at 5,000. Beyond it, generation fails with a message
directing the user to CSV or a narrower date range. Failing clearly beats a 502 from a
dead worker.

**Partial writes.** The file is written first, then the row inserted. If the insert
fails, the file is deleted. Neither an orphaned file nor a row pointing at a missing
file is left behind.

**Missing file on download.** If `file_path` is set but the file is absent from disk,
the endpoint returns 404 with a message distinguishing this from expiry.

## Retention

Retention is 90 days.

`bootstrap/app.php` configures no scheduler and this deployment runs on XAMPP, so a
cron-only prune would silently never fire. Retention is therefore implemented twice:

1. An artisan command `reports:prune`, usable manually and ready if a scheduler is
   wired later.
2. An opportunistic prune when `/reports/runs` is fetched, which is what enforces the
   90 days in practice.

Both share one service. Pruning deletes the file, stamps `file_pruned_at`, clears
`file_path`, and keeps the row.

## Testing

Backend feature tests under `tests/Feature/Reports/`, matching the existing layout:

- One test per definition, asserting correct rows and totals against seeded data.
- Scoping: a location-scoped user sees only their location, and User Activity is absent
  from their catalog.
- Permission: 403 on generate without `report_viewing.create`.
- Low Stock: the threshold falls back to `products.recommended_stocks` when
  `inventory.reorder_level` is null, and the row reports which source applied.
- Warehouse Utilization: a null-capacity location renders "not set" and is excluded
  from the aggregate percentage.
- Empty dataset returns 422 and writes no `report_runs` row.
- PDF row cap returns 422 above the limit.
- Prune deletes the file, stamps `file_pruned_at`, and retains the row.

Frontend, using the existing jest setup (`react-scripts test`), in
`SystemReports.test.jsx`:

- Cards render from a mocked catalog response, not from a hardcoded list.
- The dialog builds inputs from declared parameters — a report without a date range
  shows no date inputs.
- An expired run shows Re-run instead of Download.
