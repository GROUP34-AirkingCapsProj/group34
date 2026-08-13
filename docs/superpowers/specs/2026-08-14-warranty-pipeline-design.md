# Warranty pipeline: prove it, and stop it drifting

Date: 2026-08-14
Status: approved, implementing

## The problem

`warranty_records` is empty. All 208 appliance products carry a warranty term,
there is one customer, and of two issuances the only completed one was a
consumable. So the pipeline that creates warranty data has never run on a real
appliance sale.

It is not proven broken. It is **unproven**, and nothing in the test suite
exercises it: every warranty test in the repo fabricates `warranty_records` rows
directly, so `IssuanceScanController:664` — the one path that creates real
warranty data — has no coverage at all.

Separately, warranties do not expire on their own. `WarrantyController::index()`
runs a batch `UPDATE` flipping Active to Expired *before* listing, so a warranty
only expires when somebody opens that page. Claims are safe, because
`initClaim()` checks the date as well as the status, but customer history,
exports and reports all read the stored `status_id` and can show a stale
"Active" indefinitely. A read endpoint that writes is a problem in its own
right.

## Scope

Two things: prove the sale-to-warranty path with tests, and make expiry correct
without depending on a scheduler. Two known gaps are deliberately deferred and
recorded at the end.

## Part 1 — Prove a sale creates a warranty

`tests/Feature/Warranty/SaleCreatesWarrantyTest.php`, driving the real endpoints
a salesperson drives: `POST /api/issuance-scan/scan`, then
`POST /api/issuance-scan/complete/{issuance_id}`.

HTTP level rather than calling the controller directly, and deliberately so: the
risk here is in the WIRING — routes, permissions, issuance status transitions —
not in the date arithmetic. A test that calls the method directly would pass
while the flow a salesperson uses stayed broken. `IssuanceBatchFifoTest` already
proves this harness works against `/api/issuance-scan/scan`.

Cases:

| Scenario | Expected |
|---|---|
| Appliance with a scanned serial, 36-month term | One row: right customer, serial and product; start = sale date; end = sale + 36 months; `warranty_period_months` = 36; status Active |
| Consumable | No row — consumables carry no warranty |
| The same issuance completed twice | Still exactly one row |
| Appliance whose product has no term | No row |
| Appliance sold with NO serial scanned | No row, no warning — documents the deferred gap below rather than leaving it invisible |

Whatever these expose gets fixed. The outcome is genuinely unknown before the
tests run; that is the reason for writing them.

## Part 2 — Expiry that cannot drift

**Reads report the truth.** `WarrantyRecord::getIsExpiredAttribute()` already
derives expiry from `warranty_end_date`. A new `effective_status_id` accessor
builds on it: Claimed stays Claimed, otherwise a passed end date reports Expired
whatever the stored column says. The API serves that value, so screens are
correct even if no job has ever run.

Claimed is checked FIRST and never overridden. It is a real stored state that no
date can imply, and a claimed unit whose cover later lapses is still claimed.

**Filtering stays honest.** Filtering on `status_id = 33` alone would list a
warranty that lapsed last week under Active. A query scope translates the filter
instead:

- Active → `status_id = 33 AND warranty_end_date >= today`
- Expired → `status_id = 34 OR (status_id = 33 AND warranty_end_date < today)`
- Claimed → `status_id = 35`

**The column is tidied separately.** A `warranties:expire` command flips
Active to Expired for rows past their date, registered in the scheduler beside
`archive:auto` and runnable by hand. `index()` stops writing.

The scheduler is very likely not running anywhere — `DEPLOYMENT.md` mentions
neither cron nor `schedule:run` — which is exactly why correctness must not
depend on it. The command keeps the stored column useful for reporting; the
derived accessor keeps the screens right meanwhile. Moving the update into a job
and stopping there would have made things WORSE than the lazy update it
replaced.

`CustomerController` renders warranty state from the raw column and gets pointed
at the same helper, so it cannot disagree with the warranty page.

### Tests

- A warranty ended yesterday, column still Active, reads as Expired through the
  API with no command run.
- The Active filter excludes it; the Expired filter includes it.
- `warranties:expire` updates the column and is safe to run twice.
- A GET to the list leaves stored rows untouched.
- Claimed never becomes Expired, even past its end date.

## Deferred, deliberately

- **A serialized product issued without a scanned serial produces no warranty,
  silently.** Part 1 pins the behaviour in a test so it stays visible. Fixing it
  needs a decision — block completion, or complete and report which units got no
  cover.
- **The incident to warranty-claim handoff.** `claim_warranty` leaves an item at
  `awaiting_warranty_claim` and nothing creates or links a claim; that status is
  referenced in exactly one place, a label map. It crosses two modules and needs
  its own workflow decisions.
