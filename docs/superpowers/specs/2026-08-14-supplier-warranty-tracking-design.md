# Supplier warranty: the second clock on a unit

Date: 2026-08-14
Status: approved, implementing

## The problem

An incident filed on a defective unit still in stock reports "No warranty record
exists for this serial — it was never registered, or the unit carries no serial
at all." The message is literally true and completely misleading.

`warranty_records` rows are created only when a serialized unit is issued to a
customer (`IssuanceScanController:664`). A unit sitting in the warehouse has
never been sold, so it has no record — and the screen makes that read like lost
data on a unit whose model carries a 36-month warranty.

The business has two warranty relationships, and the system models one:

1. **Customer warranty** — the client's promise to the buyer. Starts at sale.
   Already tracked in `warranty_records`.
2. **Supplier warranty** — the supplier's promise to the client. Starts when the
   client bought the goods. Not tracked at all.

The second is what decides whether a defective unit can go back to the supplier,
which is exactly the decision an inspector makes on an incident.

## Scope

Show supplier warranty state and gate one outcome on it. No supplier claim
records, no claim workflow, no new tables — every field this needs already
exists and is populated.

## Deriving supplier state

For the unit in the reported incident the whole chain already resolves:

```
batch #24 → receiving #44 (2026-08-12) → PO #64 → supplier 1 "Supplier A"
2026-08-12 + 36 months = 2029-08-12 → active
```

1. **Receiving.** Serialized items use `item_serial.receiving_id`; every serial
   in the database has one. Non-serialized items use
   `incident_items.batch_id → inventory_batches.receiving_id`.
2. **Start** = `receivings.receiving_date`.
3. **Term** = `appliance_details.warranty_period_months`, falling back to
   `computeWarrantyMonths()` — the same fallback the issuance path uses, so the
   customer and supplier clocks cannot drift apart.
4. **State** = `in_warranty` / `expired` / `none`. `none` means genuinely
   underivable — no traceable receiving, no receiving date, or no term
   configured. It never means "not looked up".
5. **Supplier** = `receivings.supplier_id`, falling back to
   `receivings.po_id → purchase_orders.supplier_id`. Both existing receivings
   have NULL in the direct column, so without the fallback every unit in the
   system would show a blank supplier.

## Shape

`WarrantyStateService` keeps `stateFor()` and `outcomesForState()` unchanged —
they answer the customer question and existing callers and tests depend on them.
It gains:

- `supplierStateFor(IncidentItem): SupplierWarranty` — a readonly value object
  carrying state, start, end, months, supplier id and name, receiving number and
  PO number. The detail travels with the state because an inspector told
  "expired" needs to see the date it expired and who the supplier was.
- `evaluate(IncidentItem): WarrantyDecision` — customer state, supplier object,
  and the allowed outcome list, resolved together in one pass.

`evaluate()` exists so the endpoint's gate and the payload's `allowed_outcomes`
cannot disagree. They are the same list from the same call; a screen that offers
a button the endpoint then refuses is the failure this avoids.

## The gate

Today `return_to_supplier` is deliberately ungated: the service's own docblock
says "sending goods back commercially has nothing to do with whether they are
under warranty." That stays true for commercial returns and stops being true for
warranty returns, so the rule splits by the incident's reason:

- **`defective`** → `return_to_supplier` requires supplier state `in_warranty`.
  Refused otherwise, with the expiry date and supplier name in the message.
- **every other reason** (`wrong_type`, `return`, `missing`, …) → unchanged and
  never blocked. A supplier who shipped the wrong item is wrong on day 400 as
  much as on day 4, and gating that would strand a return the client is owed.

## Surfacing

`IncidentInspectionController::itemPayload()` gains a `supplier_warranty` block
beside the existing `warranty_state`. `incidentItems.js` gains a matching badge
set, and `IncidentDocument.jsx` renders it under the customer badge:
"Supplier warranty active until 2029-08-12 · Supplier A · RCV-… / PO-…".

The customer badge's `none` help text is reworded. "It was never registered"
describes a data failure; the true statement is that the unit has not been sold,
so no customer warranty exists yet.

## Tests

- A serialized unit within its term reports `in_warranty` with the right end date.
- The same unit received long ago reports `expired`.
- A batch-only item resolves its receiving through `inventory_batches`.
- A product with no warranty term reports `none`.
- Supplier name resolves through the PO when `receivings.supplier_id` is null.
- A `defective` item with expired supplier cover is refused `return_to_supplier`
  by the endpoint, not merely hidden in the UI.
- A `wrong_type` item with expired supplier cover keeps `return_to_supplier`.
- `evaluate()` returns the same outcome list the gate enforces.

## Out of scope

Supplier claim records, replacement tracking, and any backfill of
`receivings.supplier_id` — the PO fallback makes the column's emptiness
harmless, and populating it is a data question, not a code one.
