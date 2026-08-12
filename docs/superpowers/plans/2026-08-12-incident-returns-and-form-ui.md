# Incident Returns and Unified Document Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Model returns properly (counterparty, reason, and the wrong/correct pair), fix inbound returns so they register arriving goods instead of deducting stock that isn't there, gate warranty claims on real warranty records, and collapse four UI surfaces into one document that travels from reporter to inspector to admin.

**Architecture:** `incident_items` gains `expected_product_id`, `expected_quantity` and a stored `is_inbound`. `IncidentItemService` — still the sole mutator — branches on `is_inbound`: outbound behaves exactly as today, inbound registers goods that are physically present and deliberately uncounted until confirmation accepts them. The inspector's outcome list is served per item, filtered by the unit's warranty state. One `IncidentDocument` component in `file` / `inspect` / `decide` modes replaces the wizard, the inspection page, the decision drawer and the print builder.

**Tech Stack:** Laravel 12 (PHP 8.2), MariaDB, PHPUnit with `DatabaseTransactions`; React 18 (CRA), Jest.

**Spec:** `docs/superpowers/specs/2026-08-12-incident-returns-and-form-ui-design.md`

---

## Before you start — read all of it

Every item here has already gone wrong on this feature.

- **The schema is a SQL dump, not a migration history.** A bare `php artisan migrate` FAILS. Apply one migration with `--path`.
- **There is no `.env.testing`.** `--env=testing` silently migrates the **dev** database while appearing to succeed. From `back-end/` in the **Bash** tool:
  ```bash
  php artisan migrate --path=database/migrations/<file>.php --force                                 # dev
  DB_DATABASE=airkingV5_test php artisan migrate --path=database/migrations/<file>.php --force       # test
  DB_DATABASE=airkingV5_test php artisan db:seed --class=<Seeder> --force
  ```
  Verify with `SHOW COLUMNS` against **both** database names before moving on.
- **Never hardcode a status id.** They differ between databases. New columns are plain string codes with class constants.
- **`validate()` returns only the keys it validated**, and that return value is what reaches `IncidentService::file()`. An unlisted key is **silently dropped, not rejected** — this bug has already shipped once. Every new field needs a rule in `IncidentReportController::store()` **and** a line in `IncidentService::file()`'s explicit column array.
- **Permissions need two seeders** — `PermissionSeeder` creates, `RolePermissionsSeeder` grants. An admin passes structurally via `isAdmin()`, so **test as an `inventory_analyst`**.
- **The sidebar takes two edits** — `navBundles.js` + `NavBundleSeeder` for the gate, `adminMenuSections` in `Sidebar.jsx` for the item.
- **Never name a test helper `seed()`** — collides with `TestCase::seed()`, a hard fatal that aborts the entire suite.
- `Sanctum::actingAs()` 500s here; use `$this->actingAs($user, 'sanctum')`.
- **`incident_items` has no `location_id`** — location lives on the parent report. Scoping joins `incident_reports` and filters `ir.location_id`.
- Backend baseline: **9 `WarrantyClaimTest`** failures plus the order-dependent `SerialRowMovementColumnsTest`. **Task 5 reads from the warranty module — do not keep assuming those 9 are unrelated once you touch it.**
- Frontend baseline: **3 failing suites** — `App.test.jsx`, `navConfigureMap`, `InventoryManagement`.
- Verify frontend with `npm run build` **and** `npx eslint` on each changed file.
- **~19 files are dirty** in the working tree, incl. `airkingv5.sql`. Stage only what each task names. **Never `git add -A`** — a predecessor swept two unrelated test files into a commit that way.
- Use Bash `git commit -F - <<'EOF' … EOF`. Do **not** push.

## Naming — fixed across every task

| Thing | Value |
|---|---|
| Return counterparty | `customer` · `supplier` · `branch` |
| Return reason code | `wrong_product` · `wrong_quantity` · `no_longer_needed` |
| Item statuses (7) | `quarantined` · `available` · `scrapped` · `returned` · `missing` · `under_repair` · `awaiting_warranty_claim` |
| Item outcomes (6) | `restock` · `return_to_supplier` · `claim_warranty` · `repair` · `scrap` · `write_off` |
| New evidence keys | `wrong_product_photo` · `correct_product_photo` · `returned_goods_photo` · `receipt_or_dor` |
| Warranty states | `in_warranty` · `expired` · `none` |
| Document modes | `file` · `inspect` · `decide` |

---

### Task 1: Schema — returns, the pair, and inbound

**Files:** Create `back-end/database/migrations/2026_08_13_000001_extend_incidents_for_returns.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Returns get a real model, and inbound returns get the column that makes
 * their arithmetic safe.
 *
 * `return_direction` (from_customer|to_supplier) conflated WHO the
 * counterparty was with WHICH WAY the goods travel, and could not express an
 * interbranch transfer at all. Direction is now derived from the counterparty
 * and stored per item as `is_inbound`.
 *
 * is_inbound is STORED, not computed at read time: it decides whether
 * confirmation adds or deducts, and a later edit to the report must not be
 * able to retroactively flip arithmetic that has already run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_reports', function (Blueprint $table) {
            $table->string('return_counterparty', 20)->nullable()->after('return_reason');
            $table->string('return_reason_code', 30)->nullable()->after('return_counterparty');
        });

        Schema::table('incident_items', function (Blueprint $table) {
            // What SHOULD have been delivered. Recorded and printed; nothing is
            // raised from it — getting the right unit out is a normal issuance.
            $table->unsignedBigInteger('expected_product_id')->nullable()->after('product_id');
            // What was ORDERED, when the quantity is what went wrong. The
            // delivered figure is the existing `quantity`, so the discrepancy is
            // recorded rather than implied.
            $table->integer('expected_quantity')->nullable()->after('quantity');
            // Inbound goods are physically present but NOT in quantity_on_hand.
            $table->boolean('is_inbound')->default(false)->after('unit_role');

            $table->foreign('expected_product_id')->references('product_id')->on('products');
        });
    }

    public function down(): void
    {
        Schema::table('incident_reports', function (Blueprint $table) {
            $table->dropColumn(['return_counterparty', 'return_reason_code']);
        });

        Schema::table('incident_items', function (Blueprint $table) {
            $table->dropForeign(['expected_product_id']);
            $table->dropColumn(['expected_product_id', 'expected_quantity', 'is_inbound']);
        });
    }
};
```

`return_direction` is **left in place**, unused. Dropping a column that three shipped tests still reference turns one task into two; Task 7 removes the references, and a later cleanup migration can drop it once nothing reads it.

- [ ] **Step 2: Apply to both databases and verify**

```bash
cd back-end
php artisan migrate --path=database/migrations/2026_08_13_000001_extend_incidents_for_returns.php --force
DB_DATABASE=airkingV5_test php artisan migrate --path=database/migrations/2026_08_13_000001_extend_incidents_for_returns.php --force
php -r '
foreach (["airkingv5","airkingV5_test"] as $db) {
  $c = new mysqli("127.0.0.1","root","",$db);
  $get = function($t) use ($c) { $o=[]; $r=$c->query("SHOW COLUMNS FROM $t"); while($x=$r->fetch_assoc()) $o[]=$x["Field"]; return $o; };
  $ir = $get("incident_reports"); $ii = $get("incident_items");
  $want = [["incident_reports",$ir,["return_counterparty","return_reason_code"]],
           ["incident_items",$ii,["expected_product_id","expected_quantity","is_inbound"]]];
  echo "$db:\n";
  foreach ($want as [$t,$have,$cols]) foreach ($cols as $col)
    echo "  ".str_pad("$t.$col",38).(in_array($col,$have)?"OK":"MISSING")."\n";
}'
```

Expected: five `OK` lines per database.

- [ ] **Step 3: Commit**

```bash
git add back-end/database/migrations/2026_08_13_000001_extend_incidents_for_returns.php
git commit -F - <<'EOF'
feat(incidents): return counterparty, the wrong/correct pair, and is_inbound

return_direction conflated who the counterparty was with which way the goods
travel and could not express an interbranch return at all. is_inbound is
stored rather than derived at read time because it decides whether
confirmation adds or deducts, and a later edit must not retroactively flip
arithmetic that already ran.
EOF
```

---

### Task 2: Model vocabulary

**Files:** Modify `back-end/app/Models/IncidentItem.php`, `back-end/app/Models/IncidentReport.php`

- [ ] **Step 1: Extend `IncidentItem`**

Add two statuses and two outcomes, and make explicit which outcomes move stock:

```php
    public const STATUS_UNDER_REPAIR             = 'under_repair';
    public const STATUS_AWAITING_WARRANTY_CLAIM  = 'awaiting_warranty_claim';

    public const STATUSES = [
        self::STATUS_QUARANTINED, self::STATUS_AVAILABLE, self::STATUS_SCRAPPED,
        self::STATUS_RETURNED, self::STATUS_MISSING,
        self::STATUS_UNDER_REPAIR, self::STATUS_AWAITING_WARRANTY_CLAIM,
    ];

    public const OUTCOME_CLAIM_WARRANTY = 'claim_warranty';
    public const OUTCOME_REPAIR         = 'repair';

    public const OUTCOMES = [
        self::OUTCOME_RESTOCK, self::OUTCOME_RETURN_TO_SUPPLIER, self::OUTCOME_CLAIM_WARRANTY,
        self::OUTCOME_REPAIR, self::OUTCOME_SCRAP, self::OUTCOME_WRITE_OFF,
    ];

    public const OUTCOME_STATUS = [
        self::OUTCOME_RESTOCK            => self::STATUS_AVAILABLE,
        self::OUTCOME_RETURN_TO_SUPPLIER => self::STATUS_RETURNED,
        self::OUTCOME_CLAIM_WARRANTY     => self::STATUS_AWAITING_WARRANTY_CLAIM,
        self::OUTCOME_REPAIR             => self::STATUS_UNDER_REPAIR,
        self::OUTCOME_SCRAP              => self::STATUS_SCRAPPED,
        self::OUTCOME_WRITE_OFF          => self::STATUS_MISSING,
    ];

    /**
     * Outcomes where the goods physically leave and the count must drop.
     *
     * claim_warranty and repair are deliberately ABSENT: the unit stays in the
     * building. A warranty claim holds it here while a claim is raised against
     * it; a repair holds it here while it is fixed. Treating either as a
     * departure would delete stock that is sitting on the bench.
     */
    public const OUTCOMES_THAT_REMOVE_STOCK = [
        self::OUTCOME_RETURN_TO_SUPPLIER,
        self::OUTCOME_SCRAP,
        self::OUTCOME_WRITE_OFF,
    ];
```

**`OUTCOME_RESTOCKS` is deleted.** It said "restock is the only outcome that keeps stock", which stopped being true the moment `claim_warranty` and `repair` existed — both keep it. Anything reading it must move to `OUTCOMES_THAT_REMOVE_STOCK`; grep for it and fix every hit in this task.

Add `expected_product_id`, `expected_quantity`, `is_inbound` to `$fillable`, cast `is_inbound` to boolean and `expected_quantity` to integer, and add:

```php
    /** What SHOULD have been delivered, on a wrong-product return. */
    public function expectedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'expected_product_id', 'product_id');
    }
```

- [ ] **Step 2: Extend `IncidentReport`**

```php
    public const RETURN_COUNTERPARTIES = ['customer', 'supplier', 'branch'];

    public const RETURN_REASON_CODES = ['wrong_product', 'wrong_quantity', 'no_longer_needed'];

    /**
     * Which counterparties send goods TOWARD us.
     *
     * Derived, never asked: a reporter who answers "from a customer" and then
     * "outbound" has created a contradiction with no correct resolution.
     */
    public const INBOUND_COUNTERPARTIES = ['customer', 'branch'];
```

Add `return_counterparty` and `return_reason_code` to `$fillable`.

- [ ] **Step 3: Verify and commit**

```bash
cd back-end && php artisan tinker --execute="
  echo count(App\Models\IncidentItem::STATUSES),' statuses, ',
       count(App\Models\IncidentItem::OUTCOMES),' outcomes',PHP_EOL;"
grep -rn "OUTCOME_RESTOCKS" app/ || echo "clean"
```

Expected: `7 statuses, 6 outcomes`, and `clean`.

```bash
git add back-end/app/Models/IncidentItem.php back-end/app/Models/IncidentReport.php
git commit -F - <<'EOF'
feat(incidents): warranty-claim and repair outcomes, return vocabulary

OUTCOMES_THAT_REMOVE_STOCK replaces OUTCOME_RESTOCKS, which claimed restock
was the only outcome that keeps the goods. claim_warranty and repair both
keep them — the unit sits on the bench or waits for a claim — so the old
list would have deleted stock that is physically present.
EOF
```

---

### Task 3: Evidence becomes reason-driven

**Files:** Modify `back-end/app/Models/IncidentEvidence.php`

- [ ] **Step 1: Add the four requirements and the matrix**

```php
    public const REQ_WRONG_PRODUCT_PHOTO   = 'wrong_product_photo';
    public const REQ_CORRECT_PRODUCT_PHOTO = 'correct_product_photo';
    public const REQ_RETURNED_GOODS_PHOTO  = 'returned_goods_photo';
    public const REQ_RECEIPT_OR_DOR        = 'receipt_or_dor';
```

Add all four to `REQUIREMENTS`, then replace `REQUIRED_BY_REASON` with a function that also takes the return reason:

```php
    /**
     * What a report is asked to attach.
     *
     * Reason-driven, not type-driven. A wrong-delivery return proves THE
     * DELIVERY WAS WRONG — the pair plus the receipt. A wrong-quantity or
     * unwanted return proves WHETHER THE GOODS ARE STILL SELLABLE — the goods,
     * the sticker, the box, the accessories. Neither needs the other's
     * evidence, and asking for it trains people to skip the checklist.
     *
     * `missing` asks for nothing at all: the item is gone, and demanding a
     * photograph of an absent unit teaches people to fake evidence.
     *
     * @return array<int,string>
     */
    public static function requiredFor(string $reason, ?string $returnReasonCode, bool $checkedByAsc = false): array
    {
        $required = match (true) {
            $reason === 'defective' => [
                self::REQ_DEFECT_PHOTO, self::REQ_MODEL_SERIAL_STICKER,
                self::REQ_BOX_TWO_SIDES, self::REQ_ACCESSORIES_WARRANTY,
            ],
            $reason === 'return' && $returnReasonCode === 'wrong_product' => [
                self::REQ_MODEL_SERIAL_STICKER, self::REQ_WRONG_PRODUCT_PHOTO,
                self::REQ_CORRECT_PRODUCT_PHOTO, self::REQ_RECEIPT_OR_DOR,
            ],
            $reason === 'return' => [
                self::REQ_MODEL_SERIAL_STICKER, self::REQ_BOX_TWO_SIDES,
                self::REQ_ACCESSORIES_WARRANTY, self::REQ_RETURNED_GOODS_PHOTO,
                self::REQ_RECEIPT_OR_DOR,
            ],
            $reason === 'wrong_type' => [self::REQ_MODEL_SERIAL_STICKER],
            default => [],
        };

        // Conditional on a fact, not on the type.
        if ($checkedByAsc && $reason === 'defective') {
            $required[] = self::REQ_ASC_SERVICE_REPORT;
        }

        return $required;
    }
```

Keep `REQUIRED_BY_REASON` only if something still reads it; otherwise delete it and fix the callers in this task. Grep before deciding.

- [ ] **Step 2: Commit**

```bash
git add back-end/app/Models/IncidentEvidence.php
git commit -F - <<'EOF'
feat(incidents): reason-driven evidence checklist

A wrong-delivery return proves the delivery was wrong — the wrong/correct
pair plus the receipt. A wrong-quantity or unwanted return proves whether
the goods are still sellable — goods, sticker, box, accessories. Asking
either for the other's evidence is how a checklist trains people to skip it.
EOF
```

---

### Task 4: Inbound returns register stock — the correctness fix

The most important task in the plan. Follow TDD strictly.

**Files:** Modify `back-end/app/Services/IncidentItemService.php`, `back-end/app/Services/IncidentService.php`; test `back-end/tests/Feature/Incidents/InboundReturnTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Incidents;

use App\Models\IncidentItem;
use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Services\IncidentItemService;
use App\Services\IncidentService;
use Tests\Feature\Sets\SetTestCase;

/**
 * A customer return was issued out weeks ago and is in NO batch. Filing one
 * used to quarantine an existing batch — quarantining stock that is not there.
 *
 * Inbound goods are physically present and deliberately NOT in
 * quantity_on_hand: counting a return before anyone has looked at it is how a
 * return that turns out to be scrap inflates the stock figure.
 */
class InboundReturnTest extends SetTestCase
{
    private function fileReturn(string $counterparty, int $onHand = 20, int $qty = 3): array
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();

        $this->setStock($loc->location_id, $p->product_id, $onHand);
        $b = $this->makeBatch($p, $loc, ['quantity_remaining' => $onHand, 'received_at' => now()]);

        $incident = app(IncidentService::class)->file([
            'source_type'         => 'standalone',
            'location_id'         => $loc->location_id,
            'product_id'          => $p->product_id,
            'batch_id'            => $b->batch_id,
            'reason'              => IncidentReport::REASON_RETURN,
            'return_counterparty' => $counterparty,
            'return_reason_code'  => 'no_longer_needed',
            'quantity'            => $qty,
            'narrative'           => 'test',
        ], (int) $wp->user_id);

        return compact('loc', 'p', 'b', 'incident');
    }

    private function itemFor(IncidentReport $i): IncidentItem
    {
        return IncidentItem::where('incident_id', $i->incident_id)->firstOrFail();
    }

    public function test_a_customer_return_is_marked_inbound(): void
    {
        ['incident' => $incident] = $this->fileReturn('customer');

        $this->assertTrue((bool) $this->itemFor($incident)->is_inbound);
    }

    public function test_a_supplier_return_is_not_inbound(): void
    {
        ['incident' => $incident] = $this->fileReturn('supplier');

        $this->assertFalse((bool) $this->itemFor($incident)->is_inbound);
    }

    /**
     * THE fix: an inbound item must not carry a batch_id at all.
     *
     * Quarantine already deducts nothing, so a batch assertion alone would pass
     * on the broken code too. What is actually wrong today is that the item is
     * BOUND to a batch the returned units are not in — and confirmation would
     * then restock them onto it, inflating a traceable batch with goods that
     * never came from it.
     */
    public function test_an_inbound_item_is_bound_to_no_batch(): void
    {
        ['b' => $b, 'incident' => $incident] = $this->fileReturn('customer', 20, 3);

        $item = $this->itemFor($incident);
        $this->assertNull($item->batch_id,
            'the returned units were issued out long ago and belong to no batch');
        $this->assertSame(20, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
        $this->assertSame(IncidentItem::STATUS_QUARANTINED, $item->status);
    }

    /** …and the goods are present but deliberately uncounted. */
    public function test_an_inbound_return_is_not_in_quantity_on_hand(): void
    {
        ['loc' => $loc, 'p' => $p, 'incident' => $incident] = $this->fileReturn('customer', 20, 3);

        $inv = Inventory::where('location_id', $loc->location_id)
            ->where('product_id', $p->product_id)->firstOrFail();

        $this->assertSame(20, (int) $inv->quantity_on_hand, 'not accepted back yet');
    }

    /** Accepting it back is what finally counts it. */
    public function test_restocking_an_inbound_return_adds_to_stock(): void
    {
        ['loc' => $loc, 'p' => $p, 'b' => $b, 'incident' => $incident] = $this->fileReturn('customer', 20, 3);
        $svc = app(IncidentItemService::class);

        $svc->setOutcome($this->itemFor($incident), IncidentItem::OUTCOME_RESTOCK, 'unopened');
        $svc->applyOutcomes($incident, (int) $this->makeUserAt($loc, 'inventory_analyst')->user_id);

        $inv = Inventory::where('location_id', $loc->location_id)
            ->where('product_id', $p->product_id)->firstOrFail();

        $this->assertSame(23, (int) $inv->quantity_on_hand, 'the three units are accepted back');
        $this->assertSame(23, (int) InventoryBatch::where('product_id', $p->product_id)
            ->where('location_id', $loc->location_id)->sum('quantity_remaining'),
            'and they land in a batch, or FIFO can never reach them');
    }

    /** Scrapping one changes nothing — it was never counted. */
    public function test_scrapping_an_inbound_return_changes_no_counts(): void
    {
        ['loc' => $loc, 'p' => $p, 'incident' => $incident] = $this->fileReturn('customer', 20, 3);
        $svc = app(IncidentItemService::class);

        $svc->setOutcome($this->itemFor($incident), IncidentItem::OUTCOME_SCRAP, 'water damaged');
        $svc->applyOutcomes($incident, (int) $this->makeUserAt($loc, 'inventory_analyst')->user_id);

        $inv = Inventory::where('location_id', $loc->location_id)
            ->where('product_id', $p->product_id)->firstOrFail();

        $this->assertSame(20, (int) $inv->quantity_on_hand);
    }

    /** The unchanged path: a supplier return still behaves exactly as before. */
    public function test_an_outbound_return_still_quarantines_and_deducts(): void
    {
        ['loc' => $loc, 'p' => $p, 'b' => $b, 'incident' => $incident] = $this->fileReturn('supplier', 20, 3);
        $svc = app(IncidentItemService::class);

        // Quarantine deducts NOTHING, inbound or outbound — the goods are
        // present and merely unpickable. Confirmation is the only deduction in
        // the whole lifecycle.
        $this->assertSame(20, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);

        $svc->setOutcome($this->itemFor($incident), IncidentItem::OUTCOME_RETURN_TO_SUPPLIER, 'over-shipped');
        $svc->applyOutcomes($incident, (int) $this->makeUserAt($loc, 'inventory_analyst')->user_id);

        $inv = Inventory::where('location_id', $loc->location_id)
            ->where('product_id', $p->product_id)->firstOrFail();
        $this->assertSame(17, (int) $inv->quantity_on_hand, 'the goods left, once, at confirmation');
        $this->assertSame(17, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
    }

    /** A warranty claim keeps the unit here — nothing may be deducted. */
    public function test_a_warranty_claim_deducts_nothing(): void
    {
        ['loc' => $loc, 'p' => $p, 'incident' => $incident] = $this->fileReturn('supplier', 20, 3);
        $svc = app(IncidentItemService::class);

        $svc->setOutcome($this->itemFor($incident), IncidentItem::OUTCOME_CLAIM_WARRANTY, 'panel cracked');
        $svc->applyOutcomes($incident, (int) $this->makeUserAt($loc, 'inventory_analyst')->user_id);

        $this->assertSame(IncidentItem::STATUS_AWAITING_WARRANTY_CLAIM,
            $this->itemFor($incident)->status);
        // The unit is on the bench awaiting a claim, not gone. Under the old
        // OUTCOME_RESTOCKS check — "anything that isn't restock is a departure"
        // — this would have deleted three units that are physically here.
        $inv = Inventory::where('location_id', $loc->location_id)
            ->where('product_id', $p->product_id)->firstOrFail();
        $this->assertSame(20, (int) $inv->quantity_on_hand);
    }
}
```

- [ ] **Step 2: Run and watch it fail**

```bash
cd back-end && php artisan test --filter=InboundReturnTest
```

Expected: failures on `is_inbound` being unset and on the batch being deducted.

- [ ] **Step 3: Set `is_inbound` at filing**

In `IncidentService::itemRowsFrom()`, stamp each row:

```php
        // Derived from the counterparty and STORED, so a later edit to the
        // report cannot retroactively flip arithmetic that has already run.
        $inbound = ($data['reason'] ?? null) === IncidentReport::REASON_RETURN
            && in_array($data['return_counterparty'] ?? null, IncidentReport::INBOUND_COUNTERPARTIES, true);
```

and pass `'is_inbound' => $inbound` on every row it builds (both the `items` branch and the flat fallback).

- [ ] **Step 4: Branch the service on it**

In `IncidentItemService::quarantine()`, skip the batch and inventory deduction entirely when the row is inbound — create the item at the requested quantity with `is_inbound = true` and touch nothing:

```php
            // Inbound goods are ARRIVING. They left inventory when they were
            // issued out, so there is no batch to take them from and nothing to
            // deduct. The item records that they are physically here and not
            // yet accepted back.
            if (! empty($row['is_inbound'])) {
                $created[] = IncidentItem::create([
                    ...,
                    'is_inbound' => true,
                    // NULLED on purpose. The reporter may have named the batch
                    // the goods originally shipped from, but they are not in it
                    // any more — restocking onto it would inflate a traceable
                    // batch with units that did not come back from it. A
                    // restocked inbound return lands in the legacy batch, whose
                    // null received_at sorts it first in FIFO.
                    'batch_id'   => null,
                    'quantity'   => max(1, (int) $row['quantity']),
                ]);
                $this->stampSerial($row['serial_id'] ?? null, 'Quarantined');
                continue;
            }
```

In `applyOutcomes()`, replace the `OUTCOME_RESTOCKS` check with:

```php
                if ($item->is_inbound) {
                    // Only acceptance counts them. Every other ending leaves the
                    // stock figure alone, because it never included them.
                    if ($item->outcome === IncidentItem::OUTCOME_RESTOCK) {
                        $this->returnToStock($item);
                    }
                } elseif (in_array($item->outcome, IncidentItem::OUTCOMES_THAT_REMOVE_STOCK, true)) {
                    $this->deduct($item);
                }
```

`returnToStock()` already exists from the earlier work and routes batchless quantities into the legacy batch — which is exactly right for an inbound return, whose units belong to no traceable batch.

- [ ] **Step 5: Run until green**

```bash
cd back-end && php artisan test --filter=InboundReturnTest
```

Expected: **8 passed.**

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Services/IncidentItemService.php back-end/app/Services/IncidentService.php back-end/tests/Feature/Incidents/InboundReturnTest.php
git commit -F - <<'EOF'
fix(incidents): inbound returns register goods instead of deducting stock

A customer return was issued out weeks ago and is in no batch, but filing
one quarantined an existing batch — deducting stock the returned units were
never part of. Inbound returns now register arriving goods and stay out of
quantity_on_hand until confirmation accepts them, because counting a return
that turns out to be scrap inflates the figure.

claim_warranty and repair deduct nothing: the unit is on the bench, not
gone.
EOF
```

---

### Task 5: The warranty gate

**Files:** Create `back-end/app/Services/WarrantyStateService.php`; modify `IncidentInspectionController`; test `back-end/tests/Feature/Incidents/WarrantyGateTest.php`

- [ ] **Step 1: The service**

```php
<?php

namespace App\Services;

use App\Models\IncidentItem;
use Illuminate\Support\Facades\DB;

/**
 * Whether a unit is still under warranty, and therefore which endings an
 * inspector may choose for it.
 *
 * Offering a warranty claim on an out-of-warranty unit wastes a warranty
 * officer's time, and it is invisible until they open the claim and find
 * nothing to claim against.
 */
class WarrantyStateService
{
    public const IN_WARRANTY = 'in_warranty';
    public const EXPIRED     = 'expired';
    public const NONE        = 'none';

    /**
     * Keyed on serial_number, because that is what warranty_records keys on. A
     * record exists only for a unit that was SOLD, via an issuance — so a
     * non-serialized material, or an appliance still in stock, has none.
     *
     * EXPIRED and NONE offer the same choices but are not the same fact: one
     * unit was sold and its cover ran out, the other was never registered at
     * all. Collapsing them hides the second, which is the one worth asking
     * about.
     */
    public function stateFor(IncidentItem $item): string
    {
        $serial = $item->serial_number ?: $item->serial?->serial_number;

        if ($serial === null || $serial === '') {
            return self::NONE;
        }

        $record = DB::table('warranty_records')
            ->where('serial_number', $serial)
            ->orderByDesc('warranty_end_date')
            ->first(['warranty_end_date']);

        if ($record === null) {
            return self::NONE;
        }

        return $record->warranty_end_date !== null
            && $record->warranty_end_date >= now()->toDateString()
                ? self::IN_WARRANTY
                : self::EXPIRED;
    }

    /**
     * Only claim_warranty is gated. return_to_supplier stays available
     * throughout — sending goods back commercially has nothing to do with
     * whether they are under warranty.
     *
     * @return array<int,string>
     */
    public function allowedOutcomes(IncidentItem $item): array
    {
        $base = [
            IncidentItem::OUTCOME_RESTOCK,
            IncidentItem::OUTCOME_RETURN_TO_SUPPLIER,
            IncidentItem::OUTCOME_REPAIR,
            IncidentItem::OUTCOME_SCRAP,
            // write_off is the ONLY outcome mapping to STATUS_MISSING, so
            // omitting it here would make every theft or missing report
            // permanently unsettleable — the inspector could never choose the
            // one ending that fits. Only claim_warranty is gated.
            IncidentItem::OUTCOME_WRITE_OFF,
        ];

        if ($this->stateFor($item) === self::IN_WARRANTY) {
            array_splice($base, 2, 0, [IncidentItem::OUTCOME_CLAIM_WARRANTY]);
        }

        return $base;
    }
}
```

- [ ] **Step 2: Serve it per item**

In `IncidentInspectionController::itemPayload()`, add `warranty_state` and `allowed_outcomes` from this service — the same discipline as the retired `allowed_dispositions`. The client renders the buttons it is given and **never re-derives the rule**.

Enforce it in `setOutcome`: reject with 422 when the chosen outcome is not in `allowedOutcomes()`. A rule only enforced in the UI is not a rule.

- [ ] **Step 3: Test**

Cover: in-warranty offers `claim_warranty`; expired does not; no-record does not; expired and none are distinguishable in the payload; and `setOutcome` **422s** on `claim_warranty` for an expired unit.

```bash
cd back-end && php artisan test --filter=WarrantyGateTest
```

**Note:** the 9 pre-existing `WarrantyClaimTest` failures live in the module you are now reading from. Check whether they touch `warranty_records` before assuming they stay unrelated, and say what you find.

- [ ] **Step 4: Commit**

---

### Task 6: Controller — validation and payload

**Files:** Modify `back-end/app/Http/Controllers/Api/IncidentReportController.php`, `IncidentInspectionController.php`, `back-end/app/Services/IncidentService.php`

- [ ] **Step 1: Validation**

```php
            'return_counterparty'   => ['nullable', Rule::in(IncidentReport::RETURN_COUNTERPARTIES)],
            'return_reason_code'    => ['nullable', Rule::in(IncidentReport::RETURN_REASON_CODES)],
            'items.*.expected_product_id' => 'nullable|integer|exists:products,product_id',
            'items.*.expected_quantity'   => 'nullable|integer|min:0',
```

- [ ] **Step 2: Persist**

Add `return_counterparty` and `return_reason_code` to `IncidentService::file()`'s **explicit column array** — `$fillable` alone silently drops them, which has already shipped once — and carry `expected_product_id` / `expected_quantity` through `itemRowsFrom()`.

- [ ] **Step 3: Serialize**

Add all four to the item and report payloads, plus `expected_product_name`, so the document and the printed form can show what should have been delivered.

- [ ] **Step 4: Extend the HTTP round-trip test**

`IncidentFilingHttpTest` already exists precisely because service-level tests missed a silently-dropped field. Add cases asserting a wrong-product return stores **both** product ids, and a wrong-quantity return stores the ordered quantity — asserted against the **database**, never the create response, which echoes the in-memory model.

- [ ] **Step 5: Run and commit**

```bash
cd back-end && php artisan test --filter="Incident|Warranty"
```

---

### Task 7: Rewrite tests on the old return vocabulary

**Files:** whichever the run below names

- [ ] **Step 1**

```bash
cd back-end && grep -rln "return_direction\|from_customer\|to_supplier\|OUTCOME_RESTOCKS" tests/
php artisan test 2>&1 | grep -E "FAIL|Tests:"
```

| Old | New |
|---|---|
| `return_direction: 'from_customer'` | `return_counterparty: 'customer'` + a `return_reason_code` |
| `return_direction: 'to_supplier'` | `return_counterparty: 'supplier'` |
| a customer return quarantines a batch | it registers inbound stock and **deducts nothing** |
| `OUTCOME_RESTOCKS` | `OUTCOMES_THAT_REMOVE_STOCK` (inverted sense — check every use) |

**Never weaken an assertion to make it pass.** Every changed assertion carries a one-line comment saying why.

- [ ] **Step 2: Baseline**

```bash
cd back-end && php artisan test 2>&1 | grep -E "FAIL|Tests:"
```

Expected: 9 `WarrantyClaimTest` + possibly `SerialRowMovementColumnsTest`, nothing else.

- [ ] **Step 3: Commit**

---

### Task 8: `IncidentDocument` — the shell and `file` mode

**Files:** Create `frontend/src/admin/components/incident/IncidentDocument.jsx` and `frontend/src/admin/components/incident/sections/*.jsx`; create `frontend/src/admin/styles/incident_document.css`

One component, three modes. **Read the spec's "One unified document, three modes" section before writing anything.**

| Mode | Reporter's sections | Inspector's sections | Actions |
|---|---|---|---|
| `file` | editable | visible, empty, **locked** | Submit report |
| `inspect` | read-only | editable | Print · Submit inspection |
| `decide` | read-only | read-only | Print · Confirm · Reject |

**Earlier sections lock — they never hide.** An inspector must see what the reporter claimed; an admin must see both. Hiding a completed section to tidy the screen is how someone approves what they never read.

Sections, in the client's order: `TypeSection` (the dropdown, driving everything) · `DealerSection` · `UnitsSection` (Model ID / Model OD rows, + Add unit, the wrong/correct pair on `wrong_product`) · `UnitDefectSection` · `ProblemSummarySection` · a visible rule · `AttachmentsSection` · `FindingsSection` · `OutcomesSection` · `SignaturesSection`.

Type → sections shown is in the spec's table. Reuse the existing `ProductPickField`, `QuantityInPieces`, `BatchSelect`, `EvidenceChecklist`, `CameraCapture` — do not rewrite them.

- [ ] Wire `file` mode into the Report Incident route in place of `IncidentFlow`. **Leave `IncidentFlow` on disk** until Task 10 has replaced all three surfaces, so the app is never without a working filing path.
- [ ] Verify: `npx eslint`, `npm run build`, `npm test -- --watchAll=false` (3 baseline suites).
- [ ] Commit.

---

### Task 9: `inspect` mode

**Files:** Modify `IncidentDocument.jsx`; retire `frontend/src/admin/pages/IncidentInspectionPage.jsx` to a thin wrapper that renders the document in `inspect` mode.

The inspector's half becomes editable: the reason-driven checklist (each requirement satisfied by photo or skipped **with a written reason**), findings, per-item outcome selectors driven by the server's `allowed_outcomes`, the ASC toggle, and the two signature-name fields.

Submit is blocked until every required requirement is satisfied or skipped-with-a-reason **and** every item has an outcome. Say which is missing — a disabled button with no explanation is a dead end.

- [ ] Verify and commit.

---

### Task 10: `decide` mode, and retire the old surfaces

**Files:** Modify `IncidentDocument.jsx`, `IncidentResolveDrawer.jsx`; delete `IncidentFlow.jsx` and its wizard-only steps once nothing imports them.

`decide` renders everything read-only with Confirm and Reject. A rejection **requires** a note — an inspector cannot act on "no". Confirm must state plainly that it is what moves the stock.

- [ ] `grep -rn "IncidentFlow" src/` must come back clean before deleting it.
- [ ] Verify and commit.

---

### Task 11: Print from the same document

**Files:** Modify `frontend/src/admin/styles/incident_document.css`; retire `frontend/src/admin/utils/incidentReportDocument.js`

The printed form becomes `@media print` rules over the same markup — letterhead shown, controls and chrome hidden, sections unbroken across pages. **What the inspector signs is what everyone saw**; a separate HTML builder is a second layout free to drift from the first.

Keep the existing document's letterhead text, the italic note, and the two signature lines verbatim — they are the client's wording.

- [ ] Verify by actually opening print preview on a defective report with an indoor+outdoor pair and one skipped requirement. Say plainly whether you previewed it or only read the markup.
- [ ] Commit.

---

### Task 12: Verification

- [ ] `cd back-end && php artisan test` → baseline only
- [ ] `cd frontend && npm test -- --watchAll=false` → 3 pre-existing suites only
- [ ] `npx eslint` on every changed file; `npm run build` clean
- [ ] **Invariant:**

```bash
php -r '
$c = new mysqli("127.0.0.1","root","","airkingv5");
$r = $c->query("SELECT i.product_id p, i.location_id l, i.quantity_on_hand a,
  COALESCE((SELECT SUM(ii.quantity) FROM incident_items ii
            JOIN incident_reports ir ON ir.incident_id=ii.incident_id
            WHERE ii.product_id=i.product_id AND ir.location_id=i.location_id
              AND ii.status=\"quarantined\" AND ii.is_inbound=0),0) q
  FROM inventory i");
while ($x=$r->fetch_assoc()) {
  $f = ($x["q"] > $x["a"]) ? "  <-- OUTBOUND QUARANTINE EXCEEDS ON-HAND" : "";
  echo "  product ".$x["p"]." @ ".$x["l"].": on-hand ".$x["a"].", outbound-quarantined ".$x["q"].$f."\n";
}'
```

Only **outbound** quarantine is a subset of on-hand. Inbound quarantined stock is deliberately outside it.

- [ ] **Manual pass**, as an `inventory_analyst`:
  1. File a **defective** split system → both units quarantined, count unchanged, batches unpickable
  2. File a **wrong-product customer return** → checklist asks for the pair + receipt, **not** the box; count unchanged; item is inbound
  3. Inspect it: photos, one skip with a reason, outcome restock → Awaiting Inspection
  4. **Print** → the client's layout, both units, the skip reason, two signature lines
  5. As an admin: **confirm** → the returned units are **added** to stock
  6. A **supplier** return with outcome `return_to_supplier` → deducted
  7. An in-warranty unit offers **Claim warranty**; an expired one does **not**
  8. **Reject** a report → back to Awaiting Inspection with the note, evidence intact
- [ ] Report what passed, what failed, and anything skipped.

---

## Notes

- The branch is shared and pushed. **Do not push** without asking.
- Tasks 8–11 replace three working surfaces. Keep the app filable at every commit — that is why `IncidentFlow` survives until Task 10.
- The 9 `WarrantyClaimTest` failures are in the module Task 5 reads from. Stop treating them as background noise once you touch it.
