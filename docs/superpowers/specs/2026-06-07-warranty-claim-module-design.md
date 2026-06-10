# Warranty Claim Module — Design Spec

**Date:** 2026-06-07
**Status:** Approved (pending spec review)
**Approach:** A — Dedicated warranty-claim module reusing scan components

---

## 1. Background & problem

The system has two main customer-facing functions tied together — **issuance**, **warranty**, and
**customer management** — but the warranty side is broken and disconnected:

1. **Two unrelated warranty systems exist.**
   - `WarrantyRecord` (table `warranty_records`, `WarrantyController`) — the live system. Auto-created on
     issuance completion for serialized appliances; has real FKs (`customer_id`, `product_id`,
     `issuance_id`, `serial_number`); statuses Active (33) → Expired (34) → Claimed (35).
   - `WarrantyClaim` (table `warranty_claims`, `WarrantyClaimController`) — **dead code**: not imported and
     **no routes registered** in `routes/api.php`. Has a repair-style lifecycle (technician, priority,
     Open/In-Repair/Completed/Closed) but **no foreign keys** — `customer_name`/`serial_number` are free
     text, so it can never connect to a customer, product, or warranty record.

2. **Claim and replacement are not linked.** The only working "claim" action is
   `WarrantyController::markClaimed` (flips a warranty to Claimed + notes). The warranty-type issuance
   ("free replacement") has no field referencing the original warranty — it just applies a 100% discount,
   so:
   - Issuing a replacement does **not** mark the original warranty Claimed → the same serial can be
     replaced for free repeatedly, and stays Active forever.
   - Marking a warranty Claimed does **not** produce a replacement or a repair — disconnected manual steps.
   - There is **no mechanism to consume repair parts** from inventory.

3. **Customer management is blind to claims/repairs.** `CustomerController::history` and the customer
   `withCount` read only `issuances` + `warranty_records`; there is no claim/repair entity tied to the
   customer, so service history is invisible.

## 2. Business rules (confirmed with client)

- A sold appliance under warranty can develop a problem; staff choose **Repair** or **Replacement**
  depending on severity.
- **Repair**: company creates a warranty claim and selects the **consumable spare parts** needed to fix
  the unit. Parts are deducted from inventory, **free to the customer**.
- **Replacement**: the unit can't be repaired; a **replacement unit** is issued **free**.
- **Warranty validation:** a warranty can only be claimed while **Active**. If **Expired**, no claim is
  allowed (neither repair nor replacement). An already-**Claimed** warranty record cannot be claimed again.
- **Claim-once rule, reconciled:**
  - Each **warranty record** can be claimed **once**.
  - A **repair** consumes that one claim → the original warranty becomes **Claimed**, done.
  - A **replacement** marks the original **Claimed**, **and** creates a **new warranty record on the
    replacement unit's serial** that **inherits the original `warranty_end_date`** (remaining days) and is
    **Active / claimable again**. Coverage follows the customer until the original term expires.

  > ⚠️ **Key rule to re-verify:** repair consumes the warranty (Claimed, no further claims even if the
  > unit fails again inside the original term); replacement keeps coverage alive via a new inherited-end-date
  > record. This asymmetry is intentional per client.

- **Repair parts are consumables/spare parts only** (non-serialized). Replacement units are serialized
  appliances (scanned by serial).

## 3. User flow — two-step wizard (with progress bar)

Launched from the **Warranty Management** page.

**Step 1 — Create claim (form):**
- Choose the **customer**.
- Enter the **serial number** being claimed — manual input **or** barcode scan.
- Choose **claim type**: Repair or Replacement.
- Enter **notes** (problem/reason).
- **Validation on submit:** look up the serial's warranty record.
  - Not found → reject.
  - Warranty Expired → reject ("warranty expired, cannot claim").
  - Warranty already Claimed → reject ("already claimed").
  - Serial's warranty `customer_id` does not match the chosen customer → reject with a clear message
    (the warranty record is authoritative for ownership).
  - Active + unclaimed → create a `pending` claim and advance.

**Step 2 — Pick items (progress bar advances):**
- **Repair** → scan/manually add the **consumable parts** needed. Each add deducts from inventory at the
  claim location and records internal cost.
- **Replacement** → scan the **replacement unit** serial. Deducts the unit from inventory (removes/flags
  the `item_serial`, mirroring issuance) and records internal cost.
- Review list; items can be removed (restores stock) while the claim is `pending`.
- **Complete** → finalizes (see §5).

## 4. Data model

Rebuild the dead `warranty_claims` table (drop the unused free-text columns) and add a details table.

### `warranty_claims` (header)
| column | type | notes |
|---|---|---|
| `claim_id` | PK | |
| `claim_number` | string, unique | `WC-YYYYMMDD-XXXXX` |
| `warranty_id` | FK → `warranty_records` | the claimed warranty |
| `customer_id` | FK → `customers` | |
| `product_id` | FK → `products` | claimed product |
| `serial_number` | string | claimed unit serial |
| `claim_type` | enum `repair` \| `replacement` | |
| `notes` | text | problem/reason |
| `location_id` | FK → `locations` | where parts pulled / service done |
| `status_id` | FK → `status_lookup` | `pending` (wizard) → `completed` |
| `parts_cost` | decimal | internal total cost of consumed items |
| `created_by` | FK → `users` | |
| `completed_at` | datetime nullable | |
| timestamps + soft deletes | | |

### `warranty_claim_details` (consumed items)
| column | type | notes |
|---|---|---|
| `claim_detail_id` | PK | |
| `claim_id` | FK → `warranty_claims` | |
| `product_id` | FK → `products` | |
| `serial_number` | string nullable | set for a replacement unit; null for consumable parts |
| `quantity_issued` | int | |
| `quantity_in_pieces` | int | |
| `quantity_unit` | string | |
| `unit_cost` | decimal | internal |
| `line_cost` | decimal | internal |
| timestamps | | |

### New status_lookup values (if not present)
- Claim `pending`, claim `completed` (category e.g. `warranty_claim`).

## 5. Backend — `WarrantyClaimController` (rewritten) + routes

Register routes in `routes/api.php` under a `warranty-claims` prefix.

- `initClaim` (POST) — validate per §3 Step 1; create `pending` claim; return claim id + warranty/customer
  context.
- `addItem` (POST) — repair: consumable barcode + quantity; replacement: unit serial. Deduct inventory at
  `location_id` using shared deduction logic; record `unit_cost`/`line_cost` from `cost_price`.
- `removeItem` (DELETE) — undo an item before completion; restore stock (and restore `item_serial` for a
  replacement unit, mirroring `IssuanceScanController::removeItem`).
- `complete` (POST) — inside a DB transaction:
  - Re-check the warranty is still Active and unclaimed (race-safe).
  - Mark the original warranty **Claimed** (`status_id` 35, `claim_date`, `claimed_by`, claim notes).
  - If `replacement`: create a new `warranty_records` row on the replacement serial with
    `warranty_end_date` = original end date, `warranty_start_date` = today, status Active, same customer.
  - Compute and store `parts_cost`.
  - Write an **audit trail** entry (who, when, claim type, items).
  - Set claim `completed`, `completed_at`.
- `getStatus` (GET) — current claim + items.
- `downloadSlip` (GET) — printable **service/claim slip** CSV (customer, claimed serial, claim type,
  parts/replacement unit, notes, internal cost).

### Shared scan/deduction logic
Extract the barcode-candidate lookup + inventory-deduction used by `IssuanceScanController` into a shared
trait/service so issuance and claims do not duplicate it. (Targeted refactor in service of this work — not a
broad rewrite.)

## 6. Connection fixes

- **Replacement always linked** to the claimed warranty and marks it Claimed → no more infinite free
  replacements.
- **Retire** `issuance_type = 'warranty'` from the issuance scan flow and the
  `IssuanceScanController::completeIssuance` 100%-discount/warranty-replacement branch; replacements now go
  through claims only.
- **Customer history:** `CustomerController::history` includes the customer's warranty claims (repairs and
  replacements) with type, date, items, and notes; `withCount` includes a `warrantyClaims` count.

## 7. Edge cases

- Expired warranty → claim blocked.
- Already-Claimed warranty → blocked.
- Serial not found / serial-customer mismatch → blocked.
- Empty claim cannot be completed.
- Abandoned `pending` claim never consumes the warranty (status flip only on `complete`).
- Concurrency: warranty status re-validated inside the `complete` transaction; inventory deductions use
  `lockForUpdate`.
- Insufficient stock for a repair part / replacement unit → reject that add with a clear message.

## 8. Testing

- Validation: active proceeds; expired blocked; already-claimed blocked; mismatched customer blocked.
- Repair: consumable parts deducted from inventory at location; `parts_cost` correct; original warranty
  Claimed.
- Replacement: replacement serial deducted/flagged; original Claimed; new warranty record created with
  inherited end date and Active/claimable status.
- removeItem restores stock (and `item_serial` for replacement).
- Empty-claim completion rejected; abandoned pending claim leaves warranty Active.
- Customer history surfaces claims.
- Claim slip output contains expected rows.

## 9. Out of scope

- Multi-stage repair lifecycle (technician, priority, In-Repair/Completed/Closed) — client confirmed
  "simple: claim + parts + notes."
- Charging the customer for repairs/replacements (always free under warranty).
- Changes to the internal-use issuance vs customer-scan issuance overlap (separate concern, issuance itself
  is considered OK by the client for now).
