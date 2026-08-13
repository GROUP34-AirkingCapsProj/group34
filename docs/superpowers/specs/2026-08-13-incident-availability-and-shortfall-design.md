# Quarantined stock must stop being promisable

Date: 2026-08-13
Status: implemented

## The problem

Filing an incident quarantines stock without deducting it — correct, because the
goods are physically present and a stock count would find them. Unpickability was
carried entirely by `IncidentItem::quarantinedBatchIds()`, which excludes the
named batch from FIFO picking. Two gaps followed from that being the only
mechanism.

**A report filed without a batch protected nothing.** `quarantinedBatchIds()`
filters `whereNotNull('ii.batch_id')`, and `batch_id` is `nullable` at filing
(`IncidentReportController` rules for both the flat and the `items[]` shapes). A
damage report raised without naming a batch excluded no batch, so the goods
stayed pickable until confirmation. If they were picked in the meantime,
`IncidentItemService::deduct()` clamped to what was left and the difference
disappeared with no record.

**Availability quotes promised quarantined units.**
`IssuanceOrderController::availabilityPool()` reads `inventory.available_quantity`
minus live reservations. Quarantine touches neither, so an order could be raised
for units the FIFO engine would then refuse as under review.

## The approach

Derive the quarantined quantity and subtract it at the point of use, rather than
writing it into `available_quantity` at filing.

This follows the pattern `ReservationService` already documents — "a hold never
touches available_quantity", availability is derived — and preserves the property
that makes the incident flow safe: confirming or dismissing a report flips item
status and the stock returns to the pool with no cleanup step to forget or
half-run.

Rejected alternatives:

- **Require a batch at filing.** Does not help products with no batches at all
  (legacy stock), and does nothing about quoting.
- **Decrement `available_quantity` at filing.** Adds a second writer to a column
  that is otherwise only moved by real stock movement, and risks double-deduction
  at confirmation, where `deduct()` already decrements it.

## The design

1. `IncidentItem::quarantinedQuantities(int $locationId): array` — `product_id =>
   pieces`, joining `incident_reports` for location, `status = quarantined`, and
   `is_inbound = 0`. Inbound items are excluded because their goods are arriving
   and were never in `quantity_on_hand`; subtracting them would deduct the same
   units twice.
2. `IncidentService::quarantinedQuantities()` — wrapper alongside
   `excludedBatchIds()`, so callers depend on the service rather than the model.
3. `IssuanceOrderController::availabilityPool()` subtracts it alongside
   `heldElsewhere`.
4. `FifoPickingService::validateScan()`, the no-batches branch: a product with no
   `inventory_batches` rows is waved through because there is no FIFO order to
   enforce. It is now blocked when an incident holds every piece on hand. A
   **partial** hold still passes — this path has no per-scan ledger to charge
   against, and the availability pool upstream is what stops an order being
   raised for more than the free quantity.

### Confirmation shortfalls

`deduct()` returns the pieces it could not take. When any item comes up short,
`applyOutcomes()` appends a line to the incident's `resolution_note` naming the
product and the missing quantity, inside the same transaction as the deduction.
`IncidentService::confirm()` re-reads the note and merges the admin's own note on
top rather than assigning over it, so the record of the discrepancy cannot be
erased by the same action that closes the report.

The confirmation still succeeds. Refusing to confirm would strand the report
behind a stock adjustment the admin has no way to file from that screen; clamping
silently was the original bug.

## Tests

`tests/Feature/Incidents/IncidentAvailabilityTest.php` covers: batchless
quarantine reduces availability; inbound quarantine does not; a settled item
stops reducing it; a batched item is counted too; a shortfall is recorded; a
clean confirmation records nothing; picking is blocked when a hold covers all
batchless stock, and allowed when the hold is partial.

## Known limitation

A partial hold on a product with no batches at all is still pickable past the
free quantity if the order was raised before the report was filed. Closing that
needs a per-scan ledger against free stock, which is a larger change than this
one and is not attempted here.
