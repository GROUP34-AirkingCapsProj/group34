# Searchable Tag Filters Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the barcode printing page's three blind filters — Product, Receipt/PO and Location — with the type-to-search picker the rest of the app already uses, fed by the values actually present in the print queue.

**Architecture:** `ProductSearchSelect` already is the control we want, but it is product-shaped: two hardcoded category/brand dropdowns and an `onChange(productId)`. The generic half — input, portalled menu, outside-click, positioning, matching, cap — moves into a new `SearchSelect`, and `ProductSearchSelect` becomes a thin wrapper that supplies the product facets. Its three existing consumers see no change, which is why Task 1 pins its behaviour before anything moves. The option lists ride on the queue response, built from the same `filtered()` query as the rows, so a suggestion can never point at something the grid would refuse.

**Tech Stack:** React 18 (CRA), Jest + `@testing-library/react` via `npx react-scripts test`. Laravel 11 (PHP 8.2, MySQL), PHPUnit via `php artisan test`.

**Spec:** `docs/superpowers/specs/2026-09-07-searchable-tag-filters-design.md`

---

## Before You Start

Read the spec. Then read these two files — the plan assumes you know what they currently do:

- `frontend/src/admin/components/ProductSearchSelect.jsx` — all 171 lines. You are splitting this file in Task 2.
- `back-end/app/Http/Controllers/Api/BarcodeQueueController.php` — `filtered()` is the single filter set that `index()` and `ids()` both build on. Everything in Tasks 3–4 hangs off it.

### Running the tests

Frontend, from `frontend/`:

```bash
npx react-scripts test --watchAll=false --testPathPattern=SearchSelect
```

Backend, from `back-end/`:

```bash
php artisan test --filter=BarcodeQueueTest
```

### Local environment quirks (this repo, this machine)

- **The test database is separate and already populated.** `airkingV5_test` holds rows from earlier work. Any assertion of the form "the options list contains exactly these three products" will be flaky, because other tests' fixtures are in there too. **Every options test in Task 3 must filter to a freshly created location** and assert against that scoped result. `test_it_reports_the_matching_and_unsent_totals` (line 398) already does this — copy its shape.
- No migration is added by this plan, so nothing needs migrating.
- `ProductSearchSelect` has **no stylesheet**. It is entirely inline-styled apart from the `po-select` class on its input. Nothing in `styles/` moves in Task 2.

---

## File Structure

### Frontend — create

| File | Responsibility |
|---|---|
| `src/admin/components/ProductSearchSelect.test.jsx` | Characterisation tests pinning today's behaviour. Written first, never edited afterwards. |
| `src/admin/components/SearchSelect.jsx` | The generic type-to-search picker: input, portalled menu, matching, cap |
| `src/admin/components/SearchSelect.test.jsx` | Its own behaviour, independent of products |

### Frontend — modify

| File | Change |
|---|---|
| `src/admin/components/ProductSearchSelect.jsx` | Becomes a wrapper: keeps category/brand state, delegates the rest |
| `src/admin/components/tags/TagFilterBar.jsx` | Three `SearchSelect`s replace two text inputs and `LocationSelector` |
| `src/admin/pages/BarcodePrintingPage.jsx` | Store `options`; add `product_id` to `FILTER_KEYS` |
| `src/admin/pages/BarcodePrintingPage.test.jsx` | Picking a product filters by id |

### Backend — modify

| File | Change |
|---|---|
| `app/Http/Controllers/Api/BarcodeQueueController.php` | `options()` on the response; a `product_id` filter |
| `tests/Feature/Inventory/BarcodeQueueTest.php` | Options and `product_id` cases |

Not touched: `LocationSelector.jsx`, and the three pages that consume `ProductSearchSelect` (`issuance/OrderBuilder.jsx`, `PurchaseRequestPage.jsx`, `transfers/NewRequestModal.jsx`). If you find yourself editing any of those four, stop — the wrapper in Task 2 is wrong.

---

## Task 1: Pin ProductSearchSelect before moving it

**Files:**
- Create: `frontend/src/admin/components/ProductSearchSelect.test.jsx`

Three flows depend on this component and none of them are covered. These tests are the only thing standing between the Task 2 refactor and a regression in the issuance order builder. They are written against the **current** code, must pass before you change anything, and must still pass afterwards **without being edited**.

- [ ] **Step 1: Write the tests**

Create `frontend/src/admin/components/ProductSearchSelect.test.jsx`:

```jsx
import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import ProductSearchSelect from './ProductSearchSelect';

/* The normalized shape every caller maps into — see OrderBuilder.jsx:517. */
const products = [
  {
    id: '1', name: 'Copper Tube', code: 'CON-1',
    categoryId: 'c1', categoryName: 'Materials', brandId: 'b1', brandName: 'Acme',
  },
  {
    id: '2', name: 'Split Type AC', code: 'APP-9',
    categoryId: 'c2', categoryName: 'Appliances', brandId: 'b2', brandName: 'Zephyr',
  },
];

/* The menu only exists once the input has focus. */
const openMenu = () => fireEvent.focus(screen.getByPlaceholderText(/search product/i));

test('it lists the products once opened', () => {
  render(<ProductSearchSelect products={products} value="" onChange={jest.fn()} />);
  openMenu();

  expect(screen.getByText('Copper Tube')).toBeInTheDocument();
  expect(screen.getByText('Split Type AC')).toBeInTheDocument();
});

/* A picker who knows the code but not the display name is the reason the
   search reads more than product_name. */
test('typing a product code finds the product', () => {
  render(<ProductSearchSelect products={products} value="" onChange={jest.fn()} />);
  openMenu();

  fireEvent.change(screen.getByPlaceholderText(/search product/i), { target: { value: 'APP-9' } });

  expect(screen.getByText('Split Type AC')).toBeInTheDocument();
  expect(screen.queryByText('Copper Tube')).not.toBeInTheDocument();
});

test('typing a brand finds the product too', () => {
  render(<ProductSearchSelect products={products} value="" onChange={jest.fn()} />);
  openMenu();

  fireEvent.change(screen.getByPlaceholderText(/search product/i), { target: { value: 'Zephyr' } });

  expect(screen.getByText('Split Type AC')).toBeInTheDocument();
  expect(screen.queryByText('Copper Tube')).not.toBeInTheDocument();
});

test('picking a row reports that product id', () => {
  const onChange = jest.fn();
  render(<ProductSearchSelect products={products} value="" onChange={onChange} />);
  openMenu();

  fireEvent.click(screen.getByText('Copper Tube'));

  expect(onChange).toHaveBeenCalledWith('1');
});

test('the category filter narrows the list', () => {
  render(<ProductSearchSelect products={products} value="" onChange={jest.fn()} />);

  fireEvent.change(screen.getByTitle(/filter products by category/i), { target: { value: 'c2' } });
  openMenu();

  expect(screen.getByText('Split Type AC')).toBeInTheDocument();
  expect(screen.queryByText('Copper Tube')).not.toBeInTheDocument();
});

test('it says so when nothing matches', () => {
  render(<ProductSearchSelect products={products} value="" onChange={jest.fn()} />);
  openMenu();

  fireEvent.change(screen.getByPlaceholderText(/search product/i), { target: { value: 'zzzz' } });

  expect(screen.getByText(/no matching products/i)).toBeInTheDocument();
});

/* Closed, the box shows what is selected — not an empty search field, which
   would read as "nothing chosen". */
test('a selected product shows its code and name when the menu is closed', () => {
  render(<ProductSearchSelect products={products} value="1" onChange={jest.fn()} />);

  expect(screen.getByDisplayValue('[CON-1] Copper Tube')).toBeInTheDocument();
});

test('a disabled picker opens nothing', () => {
  render(<ProductSearchSelect products={products} value="" onChange={jest.fn()} disabled />);
  openMenu();

  expect(screen.queryByText('Copper Tube')).not.toBeInTheDocument();
});
```

- [ ] **Step 2: Run them against the untouched component**

```bash
cd frontend
npx react-scripts test --watchAll=false --testPathPattern="ProductSearchSelect"
```

Expected: PASS, 8 tests. If any fails, **stop and read the component** — you have mis-described current behaviour, and a wrong characterisation test is worse than none. Fix the test to match what the code does, not the other way round.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/components/ProductSearchSelect.test.jsx
git commit -m "test(picker): pin ProductSearchSelect before extracting it"
```

---

## Task 2: Extract SearchSelect

**Files:**
- Create: `frontend/src/admin/components/SearchSelect.jsx`
- Create: `frontend/src/admin/components/SearchSelect.test.jsx`
- Modify: `frontend/src/admin/components/ProductSearchSelect.jsx` (whole file)

- [ ] **Step 1: Write the failing test**

Create `frontend/src/admin/components/SearchSelect.test.jsx`:

```jsx
import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import SearchSelect from './SearchSelect';

const items = [
  { id: 'a', label: 'Copper Tube', sublabel: 'CON-1', searchText: 'Copper Tube CON-1' },
  { id: 'b', label: 'Split Type AC', sublabel: 'APP-9', searchText: 'Split Type AC APP-9' },
];

const openMenu = () => fireEvent.focus(screen.getByPlaceholderText(/search/i));

test('it lists the items once opened', () => {
  render(<SearchSelect items={items} value="" onChange={jest.fn()} placeholder="Search…" />);
  openMenu();

  expect(screen.getByText('Copper Tube')).toBeInTheDocument();
  expect(screen.getByText('Split Type AC')).toBeInTheDocument();
});

/* searchText, not label: the caller decides what a row is findable by, which
   is what lets one control serve products, receipts and locations. */
test('the query matches searchText, not just the label', () => {
  render(<SearchSelect items={items} value="" onChange={jest.fn()} placeholder="Search…" />);
  openMenu();

  fireEvent.change(screen.getByPlaceholderText(/search/i), { target: { value: 'APP-9' } });

  expect(screen.getByText('Split Type AC')).toBeInTheDocument();
  expect(screen.queryByText('Copper Tube')).not.toBeInTheDocument();
});

test('picking a row reports its id', () => {
  const onChange = jest.fn();
  render(<SearchSelect items={items} value="" onChange={onChange} placeholder="Search…" />);
  openMenu();

  fireEvent.click(screen.getByText('Copper Tube'));

  expect(onChange).toHaveBeenCalledWith('a');
});

test('it shows the caller\'s empty text when nothing matches', () => {
  render(
    <SearchSelect items={items} value="" onChange={jest.fn()}
                  placeholder="Search…" emptyText="No matching receipts" />,
  );
  openMenu();

  fireEvent.change(screen.getByPlaceholderText(/search/i), { target: { value: 'zzzz' } });

  expect(screen.getByText('No matching receipts')).toBeInTheDocument();
});

test('a selected item shows its selectedLabel when closed', () => {
  const labelled = [{ ...items[0], selectedLabel: '[CON-1] Copper Tube' }];

  render(<SearchSelect items={labelled} value="a" onChange={jest.fn()} placeholder="Search…" />);

  expect(screen.getByDisplayValue('[CON-1] Copper Tube')).toBeInTheDocument();
});

test('without a selectedLabel it falls back to the label', () => {
  render(<SearchSelect items={items} value="a" onChange={jest.fn()} placeholder="Search…" />);

  expect(screen.getByDisplayValue('Copper Tube')).toBeInTheDocument();
});

/* The seam ProductSearchSelect hangs its category and brand dropdowns on. */
test('it renders the facet node above the input', () => {
  render(
    <SearchSelect items={items} value="" onChange={jest.fn()} placeholder="Search…"
                  facets={<button type="button">All categories</button>} />,
  );

  expect(screen.getByRole('button', { name: /all categories/i })).toBeInTheDocument();
});

test('a disabled picker opens nothing', () => {
  render(<SearchSelect items={items} value="" onChange={jest.fn()} placeholder="Search…" disabled />);
  openMenu();

  expect(screen.queryByText('Copper Tube')).not.toBeInTheDocument();
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
npx react-scripts test --watchAll=false --testPathPattern="SearchSelect"
```

Expected: FAIL — `Cannot find module './SearchSelect'`.

Note this also matches `ProductSearchSelect.test.jsx`, which should still pass. That is intended: from here on, run both together.

- [ ] **Step 3: Write SearchSelect**

Create `frontend/src/admin/components/SearchSelect.jsx`:

```jsx
import React, { useState, useEffect, useMemo, useRef, useCallback } from 'react';
import { createPortal } from 'react-dom';

// ─── Type-to-search picker ────────────────────────────────────────────────
// Replaces a plain <select> for long lists. Knows nothing about what it is
// listing: the caller supplies rows and says what each is findable by.
//
// The result list is rendered in a portal (fixed-positioned at the input) so
// it floats above the page like a native dropdown and is never clipped by an
// ancestor's overflow — the forms this appears in live inside overflow:hidden
// cards and horizontally-scrolling table wrappers.
//
// Pick-only by design: typing filters the menu, and the value changes only
// when a row is chosen. A half-typed query is not a selection.
//
// props:
//   items       — [{ id, label, sublabel, extra, searchText, selectedLabel }]
//                 searchText is what the query matches; selectedLabel is what
//                 the closed box shows, defaulting to label.
//   value       — selected id, or ''
//   onChange(id)
//   placeholder — shown when nothing is typed or selected
//   emptyText   — shown when the query matches nothing
//   facets      — optional node rendered above the input
//   className   — class for the input; defaults to the app's po-select
//   disabled
const SearchSelect = ({
  items,
  value,
  onChange,
  placeholder = 'Search…',
  emptyText = 'No matches',
  facets = null,
  className = 'po-select',
  disabled,
}) => {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [menuRect, setMenuRect] = useState(null);
  const wrapRef = useRef(null);
  const inputRef = useRef(null);
  const menuRef = useRef(null);

  const selected = useMemo(() => items.find((i) => i.id === value), [items, value]);

  // When closed, show what is selected; when typing, show the query.
  const inputText = open
    ? query
    : (selected ? (selected.selectedLabel || selected.label || '') : '');

  const matches = useMemo(() => {
    const q = query.trim().toLowerCase();
    return items
      .filter((i) => !q || String(i.searchText || i.label || '').toLowerCase().includes(q))
      .slice(0, 50); // cap for perf
  }, [items, query]);

  // Position the portalled menu at the input's on-screen rect.
  const positionMenu = useCallback(() => {
    if (!inputRef.current) return;
    const r = inputRef.current.getBoundingClientRect();
    setMenuRect({ top: r.bottom + 2, left: r.left, width: r.width });
  }, []);

  // Keep the menu glued to the input while open (scroll/resize).
  useEffect(() => {
    if (!open) return undefined;
    positionMenu();
    const onMove = () => positionMenu();
    window.addEventListener('scroll', onMove, true); // capture → catches inner scroll containers
    window.addEventListener('resize', onMove);
    return () => {
      window.removeEventListener('scroll', onMove, true);
      window.removeEventListener('resize', onMove);
    };
  }, [open, positionMenu]);

  // Close on outside click (menu is portalled, so check both wrapper AND menu).
  useEffect(() => {
    const onDoc = (e) => {
      if (wrapRef.current && wrapRef.current.contains(e.target)) return;
      if (menuRef.current && menuRef.current.contains(e.target)) return;
      setOpen(false);
    };
    document.addEventListener('mousedown', onDoc);
    return () => document.removeEventListener('mousedown', onDoc);
  }, []);

  return (
    <div className="prod-search" ref={wrapRef} style={{ position: 'relative' }}>
      {facets}

      <input
        ref={inputRef}
        type="text"
        className={className}
        style={{ fontSize: 12, width: '100%' }}
        disabled={disabled}
        value={inputText}
        placeholder={placeholder}
        onChange={(e) => { setQuery(e.target.value); if (!open) { setOpen(true); } positionMenu(); }}
        onFocus={() => { setOpen(true); setQuery(''); positionMenu(); }}
      />

      {open && !disabled && menuRect && createPortal(
        <div
          ref={menuRef}
          className="prod-search-menu"
          style={{
            position: 'fixed', top: menuRect.top, left: menuRect.left, width: menuRect.width,
            zIndex: 10050, maxHeight: 300, overflowY: 'auto', background: '#fff',
            border: '1px solid #cbd5e1', borderRadius: 8, boxShadow: '0 10px 28px rgba(0,0,0,.20)',
          }}
        >
          {matches.length === 0 ? (
            <div style={{ padding: '10px 12px', color: '#94a3b8', fontSize: 13 }}>{emptyText}</div>
          ) : matches.map((item) => (
            <button
              type="button"
              key={item.id}
              className="prod-search-item"
              onMouseDown={(e) => e.preventDefault()} // keep input focus; fire before blur
              onClick={() => { onChange(item.id); setOpen(false); setQuery(''); }}
              style={{
                display: 'block', width: '100%', textAlign: 'left', padding: '9px 12px', border: 'none',
                borderBottom: '1px solid #f1f5f9',
                background: item.id === value ? '#eff6ff' : '#fff', cursor: 'pointer', fontSize: 13,
              }}
            >
              <span style={{ fontWeight: 600, color: '#1e293b' }}>{item.label}</span>
              {item.sublabel ? <span style={{ color: '#64748b' }}> · {item.sublabel}</span> : null}
              {item.extra ? <span style={{ color: '#94a3b8' }}> · {item.extra}</span> : null}
            </button>
          ))}
        </div>,
        document.body,
      )}
    </div>
  );
};

export default SearchSelect;
```

- [ ] **Step 4: Run the tests**

```bash
npx react-scripts test --watchAll=false --testPathPattern="SearchSelect"
```

Expected: PASS — 8 for `SearchSelect`, and `ProductSearchSelect`'s 8 still green because that file has not been touched yet.

- [ ] **Step 5: Rewrite ProductSearchSelect as a wrapper**

Replace the **entire contents** of `frontend/src/admin/components/ProductSearchSelect.jsx` with:

```jsx
import React, { useState, useMemo } from 'react';
import SearchSelect from './SearchSelect';

// ─── Type-to-search product picker ────────────────────────────────────────
// SearchSelect plus the two things that are specifically about products: the
// category and brand dropdowns, and the way a product reads in a row.
//
// The props are unchanged from when this file held the whole control —
// OrderBuilder, PurchaseRequestPage and NewRequestModal all pass the same
// normalized shape, { id, name, code, brandName, categoryId, ... }, and none
// of them know this was ever split.
//
// props:
//   products    — normalized array [{ id, name, code, brandName, ... }]
//   value       — selected product id (string) or ''
//   onChange(productId) — called with the chosen product's id
//   placeholder — shown when nothing is typed/selected
//   disabled
const ProductSearchSelect = ({ products, value, onChange, placeholder = 'Search product…', disabled }) => {
  const [filterCat, setFilterCat] = useState('');
  const [filterBrand, setFilterBrand] = useState('');

  // Category options derived from the full product list.
  const categoryOptions = useMemo(() => {
    const seen = new Map();
    products.forEach(p => { if (p.categoryId && !seen.has(p.categoryId)) seen.set(p.categoryId, p.categoryName || p.categoryId); });
    return [...seen.entries()].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name));
  }, [products]);

  // Brand options narrow to the selected category (if any).
  const brandOptions = useMemo(() => {
    const seen = new Map();
    products
      .filter(p => !filterCat || p.categoryId === filterCat)
      .forEach(p => { if (p.brandId && !seen.has(p.brandId)) seen.set(p.brandId, p.brandName || p.brandId); });
    return [...seen.entries()].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name));
  }, [products, filterCat]);

  /* The facets filter before the search does, exactly as they did when this
     was one file: the 50-row cap therefore applies to what is left after a
     category is chosen, not to the whole catalogue. */
  const items = useMemo(() => products
    .filter(p => (!filterCat || p.categoryId === filterCat)
              && (!filterBrand || p.brandId === filterBrand))
    .map(p => ({
      id: p.id,
      label: p.name,
      sublabel: p.code,
      extra: p.brandName,
      searchText: `${p.name || ''} ${p.code || ''} ${p.brandName || ''}`,
      selectedLabel: `${p.code ? `[${p.code}] ` : ''}${p.name}`,
    })), [products, filterCat, filterBrand]);

  const facets = (
    <div className="prod-search-filters" style={{ display: 'flex', gap: 4, flexWrap: 'wrap', marginBottom: 3 }}>
      <select
        className="po-select"
        style={{ flex: '1 1 45%', minWidth: 84, fontSize: 10.5, padding: '1px 4px', height: 24, lineHeight: '22px' }}
        value={filterCat}
        disabled={disabled}
        onChange={(e) => { setFilterCat(e.target.value); setFilterBrand(''); }}
        title="Filter products by category"
      >
        <option value="">All categories</option>
        {categoryOptions.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
      </select>
      <select
        className="po-select"
        style={{ flex: '1 1 45%', minWidth: 84, fontSize: 10.5, padding: '1px 4px', height: 24, lineHeight: '22px' }}
        value={filterBrand}
        disabled={disabled}
        onChange={(e) => setFilterBrand(e.target.value)}
        title="Filter products by brand"
      >
        <option value="">All brands</option>
        {brandOptions.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
      </select>
    </div>
  );

  return (
    <SearchSelect
      items={items}
      value={value}
      onChange={onChange}
      placeholder={placeholder}
      emptyText="No matching products"
      facets={facets}
      disabled={disabled}
    />
  );
};

export default ProductSearchSelect;
```

- [ ] **Step 6: Prove the extraction changed nothing**

```bash
npx react-scripts test --watchAll=false --testPathPattern="SearchSelect|OrderBuilder|PurchaseRequest|NewRequest"
```

Expected: PASS. `ProductSearchSelect.test.jsx` must be green **without having been edited** — that is the whole point of Task 1. If a case fails, the wrapper differs from the original; fix the wrapper, never the test.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin/components/SearchSelect.jsx frontend/src/admin/components/SearchSelect.test.jsx frontend/src/admin/components/ProductSearchSelect.jsx
git commit -m "refactor(picker): split the generic search-select out of the product one"
```

---

## Task 3: Option lists on the queue response

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/BarcodeQueueController.php`
- Test: `back-end/tests/Feature/Inventory/BarcodeQueueTest.php`

**Read the environment note above before writing these tests.** The test database is populated, so every assertion here scopes to a freshly created location.

- [ ] **Step 1: Write the failing tests**

Add to `back-end/tests/Feature/Inventory/BarcodeQueueTest.php`, after `test_it_reports_the_matching_and_unsent_totals`:

```php
    /** The options block for one query, so each case can scope itself. */
    private function options(array $query): array
    {
        $res = $this->actingAs($this->user())
            ->getJson('/api/barcode-labels/queue?' . http_build_query($query));

        $res->assertOk();

        return $res->json('options');
    }

    public function test_it_offers_the_products_present_in_the_queue(): void
    {
        $location = $this->location('OptionHouse');
        $copper   = $this->material();

        $this->tag($copper, ['location_id' => $location->location_id]);

        $names = collect($this->options(['location_id' => $location->location_id])['products'])
            ->pluck('product_name');

        $this->assertContains('Copper Tube', $names->all());
    }

    /* The list is built from the tags being listed, not from the product
       catalogue. A product nobody has received cannot be offered, because
       picking it would land on an empty grid that reads like a mistake. */
    public function test_a_product_with_no_tags_is_not_offered(): void
    {
        $location = $this->location('OptionHouse');
        $stocked  = $this->material();
        $unstocked = $this->material();

        $this->tag($stocked, ['location_id' => $location->location_id]);

        $ids = collect($this->options(['location_id' => $location->location_id])['products'])
            ->pluck('product_id');

        $this->assertContains($stocked->product_id, $ids->all());
        $this->assertNotContains($unstocked->product_id, $ids->all());
    }

    public function test_it_offers_both_grn_and_po_numbers(): void
    {
        $location = $this->location('OptionHouse');
        $po       = PurchaseOrder::create(['po_number' => 'PO-' . uniqid()]);

        $receipt = $this->receiptOn('2026-09-01', $location);
        $receipt->update(['po_id' => $po->po_id]);

        $this->tag($this->material(), [
            'location_id'  => $location->location_id,
            'receiving_id' => $receipt->receiving_id,
        ]);

        $receipts = collect($this->options(['location_id' => $location->location_id])['receipts']);

        $this->assertContains($receipt->receiving_number, $receipts->pluck('value')->all());
        $this->assertContains($po->po_number, $receipts->pluck('value')->all());
        $this->assertSame('grn', $receipts->firstWhere('value', $receipt->receiving_number)['kind']);
        $this->assertSame('po', $receipts->firstWhere('value', $po->po_number)['kind']);
    }

    public function test_it_offers_the_locations_holding_tags(): void
    {
        $location = $this->location('OptionHouse');
        $this->tag($this->material(), ['location_id' => $location->location_id]);

        $offered = collect($this->options(['location_id' => $location->location_id])['locations']);

        $this->assertContains($location->location_id, $offered->pluck('location_id')->all());
    }

    /* The load-bearing case. The options come off the same scoped query as the
       rows, so a branch clerk's menu cannot name another branch's delivery. An
       options query that rebuilt the filter set by hand — and forgot forUser()
       — would pass every other case in this file and leak here. */
    public function test_a_scoped_user_is_offered_only_their_own_location(): void
    {
        $mine   = $this->location('MineOptions');
        $theirs = $this->location('TheirOptions');

        $ours   = $this->receiptOn('2026-09-01', $mine);
        $others = $this->receiptOn('2026-09-01', $theirs);

        $this->tag($this->material(), ['location_id' => $mine->location_id,   'receiving_id' => $ours->receiving_id]);
        $this->tag($this->material(), ['location_id' => $theirs->location_id, 'receiving_id' => $others->receiving_id]);

        $res = $this->actingAs($this->scopedUserAt($mine))
            ->getJson('/api/barcode-labels/queue');

        $res->assertOk();

        $values = collect($res->json('options.receipts'))->pluck('value')->all();

        $this->assertContains($ours->receiving_number, $values);
        $this->assertNotContains($others->receiving_number, $values);
    }

    /* The lists narrow with the filters, which is the whole reason they ride
       on the queue response instead of a fixed endpoint of their own. */
    public function test_the_options_narrow_with_the_filters(): void
    {
        $here      = $this->location('HereOptions');
        $elsewhere = $this->location('ElsewhereOptions');

        $ours   = $this->receiptOn('2026-09-01', $here);
        $others = $this->receiptOn('2026-09-01', $elsewhere);

        $this->tag($this->material(), ['location_id' => $here->location_id,      'receiving_id' => $ours->receiving_id]);
        $this->tag($this->material(), ['location_id' => $elsewhere->location_id, 'receiving_id' => $others->receiving_id]);

        $values = collect($this->options(['location_id' => $here->location_id])['receipts'])
            ->pluck('value')->all();

        $this->assertContains($ours->receiving_number, $values);
        $this->assertNotContains($others->receiving_number, $values);
    }
```

- [ ] **Step 2: Run them to verify they fail**

```bash
cd back-end
php artisan test --filter=BarcodeQueueTest
```

Expected: FAIL — `options` is null, so the array access errors.

- [ ] **Step 3: Add the options block**

In `back-end/app/Http/Controllers/Api/BarcodeQueueController.php`, add these imports beside the existing ones:

```php
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
```

Add the cap constant beneath `MAX_SELECTABLE`:

```php
    /** Above this a menu stops being a shortlist. Unlisted values stay
        reachable by narrowing another filter, so nothing is lost. */
    private const MAX_OPTIONS = 50;
```

In `index()`, extend the merged response array:

```php
        return $response->setData(array_merge($response->getData(true), [
            'counts' => [
                'total'  => $page->total(),
                'unsent' => (clone $this->filtered($request))->whereNull('last_printed_at')->count(),
            ],
            'options' => $this->options($request),
        ]));
```

Add the method beneath `row()`:

```php
    /**
     * What the filter menus may offer.
     *
     * Built from filtered() — the same query the rows come from — so the
     * location scope is inherited rather than re-applied, and every value
     * offered is a value that returns rows. A menu assembled from the product
     * catalogue instead would list things nobody has received, and picking one
     * would land on an empty grid that reads exactly like a mistake.
     *
     * Ids are collected first and the display rows fetched separately: the
     * eager loads on filtered() are for rendering tags, and dragging them
     * through three DISTINCT queries would fire a page of joins to build a
     * list of names.
     */
    private function options(Request $request): array
    {
        $scoped = fn () => (clone $this->filtered($request))->setEagerLoads([]);

        $productIds = $scoped()->select('product_id')->distinct()
            ->limit(self::MAX_OPTIONS)->pluck('product_id');

        $locationIds = $scoped()->select('location_id')->distinct()
            ->limit(self::MAX_OPTIONS)->pluck('location_id');

        $receivingIds = $scoped()->whereNotNull('receiving_id')
            ->select('receiving_id')->distinct()
            ->limit(self::MAX_OPTIONS)->pluck('receiving_id');

        $receivings = Receiving::with('purchaseOrder')
            ->whereIn('receiving_id', $receivingIds)
            ->orderByDesc('receiving_date')
            ->get();

        /* One box searches GRN and PO numbers together, because a clerk holds
           whichever number the paperwork happened to print. `kind` is what
           lets the menu say which one a row is. */
        $receipts = $receivings
            ->flatMap(fn (Receiving $receiving) => array_filter([
                $receiving->receiving_number ? ['value' => $receiving->receiving_number, 'kind' => 'grn'] : null,
                $receiving->purchaseOrder?->po_number ? ['value' => $receiving->purchaseOrder->po_number, 'kind' => 'po'] : null,
            ]))
            ->unique('value')
            ->take(self::MAX_OPTIONS)
            ->values();

        return [
            'products' => Product::whereIn('product_id', $productIds)
                ->orderBy('product_name')
                ->get(['product_id', 'product_name', 'product_code']),
            'receipts' => $receipts,
            'locations' => Location::whereIn('location_id', $locationIds)
                ->orderBy('location_name')
                ->get(['location_id', 'location_name']),
        ];
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
php artisan test --filter=BarcodeQueueTest
```

Expected: PASS, including every pre-existing case.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/BarcodeQueueController.php back-end/tests/Feature/Inventory/BarcodeQueueTest.php
git commit -m "feat(labels): offer the queue's own products, receipts and locations"
```

---

## Task 4: The product_id filter

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/BarcodeQueueController.php`
- Test: `back-end/tests/Feature/Inventory/BarcodeQueueTest.php`

- [ ] **Step 1: Write the failing test**

Add after `test_it_searches_by_product_code_alone`:

```php
    /* Picking from a menu names one product, so the filter is an id rather
       than a substring. The second product exists to prove the difference:
       under the old `q` search, "Copper Tube" also matched "Copper Tube
       Insulation", and a clerk printing for one of them got both. */
    public function test_it_filters_to_exactly_one_product(): void
    {
        $copper = $this->material();

        $insulation = Product::create([
            'product_code' => 'CON-' . uniqid(),
            'product_name' => 'Copper Tube Insulation',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $insulation->product_id, 'pieces_per_package' => 10,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'package',
        ]);

        $wanted = $this->tag($copper);
        $other  = $this->tag($insulation);

        $ids = $this->ids(['product_id' => $copper->product_id]);

        $this->assertContains($wanted->serial_id, $ids);
        $this->assertNotContains($other->serial_id, $ids);
    }
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=test_it_filters_to_exactly_one_product
```

Expected: FAIL — the unknown filter is ignored, so both tags come back and `assertNotContains` fails.

- [ ] **Step 3: Add the filter**

In `filtered()`, directly beneath the `product_type` block:

```php
        /* An exact product, from the picker. `q` below is still the substring
           search — it stays for URLs people have already shared. */
        $query->when($request->filled('product_id'), fn (Builder $q) => $q
            ->where('product_id', (int) $request->input('product_id')));
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
php artisan test --filter=BarcodeQueueTest
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/BarcodeQueueController.php back-end/tests/Feature/Inventory/BarcodeQueueTest.php
git commit -m "feat(labels): filter the queue to exactly one product"
```

---

## Task 5: Rewire the filter bar

**Files:**
- Modify: `frontend/src/admin/components/tags/TagFilterBar.jsx`
- Modify: `frontend/src/admin/pages/BarcodePrintingPage.jsx`
- Test: `frontend/src/admin/pages/BarcodePrintingPage.test.jsx`

`TagFilterBar` has no test file of its own — every one of its behaviours is "the parent was told", so it is covered through the page, as it was when it was written.

- [ ] **Step 1: Write the failing tests**

In `frontend/src/admin/pages/BarcodePrintingPage.test.jsx`, extend `queueResponse` to carry options:

```js
const queueResponse = (rows, options = {}) => ({
  data: rows,
  pagination: { current_page: 1, last_page: 1, per_page: 24, total: rows.length },
  counts: { total: rows.length, unsent: rows.length },
  options: {
    products: [{ product_id: 5, product_name: 'Copper Tube', product_code: 'CON-1' }],
    receipts: [{ value: 'RCV-1', kind: 'grn' }],
    locations: [{ location_id: 2, location_name: 'Main' }],
    ...options,
  },
});
```

Then add these cases at the end of the file:

```jsx
test('picking a product filters by its id, not its name', async () => {
  renderPage();
  await screen.findByText('Copper Tube');

  fireEvent.focus(screen.getByPlaceholderText(/search product/i));
  fireEvent.click(await screen.findByText('Copper Tube', { selector: 'span' }));

  await waitFor(() => {
    expect(barcodeLabelsAPI.queue).toHaveBeenLastCalledWith(
      expect.objectContaining({ product_id: '5' }),
      1,
      expect.any(Number),
    );
  });
});

test('picking a location filters by its id', async () => {
  renderPage();
  await screen.findByText('Copper Tube');

  fireEvent.focus(screen.getByPlaceholderText(/search location/i));
  fireEvent.click(await screen.findByText('Main', { selector: 'span' }));

  await waitFor(() => {
    expect(barcodeLabelsAPI.queue).toHaveBeenLastCalledWith(
      expect.objectContaining({ location_id: '2' }),
      1,
      expect.any(Number),
    );
  });
});

/* A receipt has no id of its own, so the value IS the id — and the endpoint's
   substring match resolves it unchanged. */
test('picking a receipt filters by its number', async () => {
  renderPage();
  await screen.findByText('Copper Tube');

  fireEvent.focus(screen.getByPlaceholderText(/search receipt/i));
  fireEvent.click(await screen.findByText('RCV-1', { selector: 'span' }));

  await waitFor(() => {
    expect(barcodeLabelsAPI.queue).toHaveBeenLastCalledWith(
      expect.objectContaining({ receipt: 'RCV-1' }),
      1,
      expect.any(Number),
    );
  });
});
```

- [ ] **Step 2: Run them to verify they fail**

```bash
cd frontend
npx react-scripts test --watchAll=false --testPathPattern="BarcodePrintingPage"
```

Expected: FAIL — no element with placeholder `/search product/i`; the Product filter is still a plain text box.

- [ ] **Step 3: Rewire the filter bar**

In `frontend/src/admin/components/tags/TagFilterBar.jsx`, replace the `LocationSelector` import with `SearchSelect`:

```jsx
import React from 'react';
import DateInput from '../DateInput';
import SearchSelect from '../SearchSelect';
```

Change the signature to take `options`:

```jsx
export default function TagFilterBar({ filters, onChange, counts, options }) {
  const set = (key) => (value) => onChange({ ...filters, [key]: value });

  const products = (options?.products || []).map((p) => ({
    id: String(p.product_id),
    label: p.product_name,
    sublabel: p.product_code,
    searchText: `${p.product_name || ''} ${p.product_code || ''}`,
  }));

  /* A receipt has no id of its own, so its number is its id. `kind` becomes
     the row's badge: one box searches GRNs and POs together because a clerk
     holds whichever number the paperwork printed. */
  const receipts = (options?.receipts || []).map((r) => ({
    id: r.value,
    label: r.value,
    sublabel: r.kind === 'po' ? 'PO' : 'GRN',
    searchText: r.value,
  }));

  const locations = (options?.locations || []).map((l) => ({
    id: String(l.location_id),
    label: l.location_name,
    searchText: l.location_name,
  }));
```

Then replace the three filter blocks. Delete the whole `LocationSelector` block, the `Receipt or PO` block and the `Product` block, and put these in their place — location first, to keep the order the bar had:

```jsx
      <div className="bpp-filter">
        <label htmlFor="bpp-location">Location</label>
        <SearchSelect
          items={locations}
          value={filters.location_id || ''}
          onChange={(id) => set('location_id')(id)}
          placeholder="Search location…"
          emptyText="No locations with tags"
        />
      </div>

      <div className="bpp-filter">
        <label htmlFor="bpp-receipt">Receipt or PO</label>
        <SearchSelect
          items={receipts}
          value={filters.receipt || ''}
          onChange={(value) => set('receipt')(value)}
          placeholder="Search receipt or PO…"
          emptyText="No receipts match"
        />
      </div>

      <div className="bpp-filter">
        <label htmlFor="bpp-product">Product</label>
        <SearchSelect
          items={products}
          value={filters.product_id || ''}
          onChange={(id) => set('product_id')(id)}
          placeholder="Search product…"
          emptyText="No products with tags"
        />
      </div>
```

The `htmlFor` values no longer point at anything — `SearchSelect` renders its own input without an id. Drop the `htmlFor` attribute from these three labels rather than inventing ids the control does not accept:

```jsx
        <label>Location</label>
```

…and the same for the other two.

- [ ] **Step 4: Feed it from the page**

In `frontend/src/admin/pages/BarcodePrintingPage.jsx`:

Add `product_id` to the filter keys, keeping `q` so shared URLs still resolve:

```jsx
const FILTER_KEYS = [
  'product_type', 'product_id', 'date_from', 'date_to',
  'location_id', 'receipt', 'q', 'print_state',
];
```

Add the options state beside `counts`:

```jsx
  const [counts, setCounts] = useState(null);
  const [options, setOptions] = useState(null);
```

Store it in the queue effect, beside `setCounts`:

```jsx
        setCounts(res?.counts || null);
        setOptions(res?.options || null);
```

Pass it down, and stop passing `allowedLocations` — the location list now comes from the queue:

```jsx
        <TagFilterBar
          filters={filters}
          onChange={applyFilters}
          counts={counts}
          options={options}
        />
```

Remove the now-unused `useUserLocation` import and its call, or CRA's lint will warn on build:

```jsx
import { useLabelPrinter } from '../hooks/useLabelPrinter';
```

(the `import { useUserLocation } from '../context/AuthContext';` line goes, along with `const { allowedLocations } = useUserLocation();` and its comment.)

- [ ] **Step 5: Run the tests to verify they pass**

```bash
npx react-scripts test --watchAll=false --testPathPattern="BarcodePrintingPage"
```

Expected: PASS, 12 tests.

The page test mocks `../context/AuthContext`; that mock can stay even though the page no longer calls `useUserLocation`, because `AdminLayout` is mocked separately and nothing else reaches it. Leave it — a mock that is no longer needed is harmless, and removing it is a change with no test behind it.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/components/tags/TagFilterBar.jsx frontend/src/admin/pages/BarcodePrintingPage.jsx frontend/src/admin/pages/BarcodePrintingPage.test.jsx
git commit -m "feat(labels): pick products, receipts and locations from what is in the queue"
```

---

## Task 6: Style the pickers into the filter bar

**Files:**
- Modify: `frontend/src/admin/styles/barcode_printing.css`

`SearchSelect`'s input carries the `po-select` class and a `width: 100%` inline style. The filter bar's existing rule targets `.bpp-filter input`, which still matches, but the `min-width: 160px` on it fights the picker's full-width input inside a flex column.

- [ ] **Step 1: Add the rule**

Append to `frontend/src/admin/styles/barcode_printing.css`:

```css
/* The three type-to-search filters. SearchSelect renders its own input at
   width:100%, so the column has to have a width for it to fill — the menu is
   portalled and positions itself against the input's rect, which is zero-wide
   until this applies. */
.bpp-filter .prod-search {
  min-width: 190px;
}

.bpp-filter .prod-search input {
  min-width: 0;
}
```

- [ ] **Step 2: Verify nothing regressed**

```bash
npx react-scripts test --watchAll=false --testPathPattern="BarcodePrintingPage|SearchSelect"
```

Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/styles/barcode_printing.css
git commit -m "style(labels): size the search filters in the printing bar"
```

---

## Task 7: Full verification

- [ ] **Step 1: Run the whole backend suite**

```bash
cd back-end
php artisan test
```

Expected: PASS. Pay attention to `BarcodeQueueTest` — the `q`, `receipt` and `product_type` cases must all still be green, because `q` was kept deliberately and the new `product_id` filter sits beside it rather than replacing it in the endpoint.

- [ ] **Step 2: Run the whole frontend suite**

```bash
cd frontend
npx react-scripts test --watchAll=false
```

Expected: PASS, with `ProductSearchSelect.test.jsx` green and **unedited since Task 1** — confirm with:

```bash
git log --oneline -- frontend/src/admin/components/ProductSearchSelect.test.jsx
```

Expected: exactly one commit, the one from Task 1.

- [ ] **Step 3: Build, to catch what tests do not**

```bash
npx cross-env CI=false react-scripts build
```

Expected: "Compiled with warnings" is fine — the repo has pre-existing `no-unused-vars` warnings in `App.jsx`, `Sidebar.jsx` and several pages. **No warning may name `SearchSelect.jsx`, `ProductSearchSelect.jsx`, `TagFilterBar.jsx` or `BarcodePrintingPage.jsx`.** A warning there means a leftover import from Task 5.

- [ ] **Step 4: Confirm the spec is covered**

Walk the spec's five sections:

- Section 1 (the control, facets seam, pick-only) — Tasks 1–2
- Section 2 (options block, scope inherited, narrowing, cap) — Task 3
- Section 3 (`product_id`, `q` kept for old URLs) — Task 4
- Section 4 (wiring, id mapping per list) — Task 5
- Section 5 (test order) — Task 1 before Task 2, enforced by Step 2 above

- [ ] **Step 5: Check the tree**

```bash
git status
```

Expected: clean, apart from `frontend/src/admin/pages/PurchaseOrderPage.jsx` and `frontend/src/admin/styles/po_recommendation.css`, which were modified before any of this work started. Leave them alone.

---

## Notes for the implementer

**Task 1 is not optional and not reorderable.** `ProductSearchSelect` is consumed by the issuance order builder, purchase requests and transfer requests, and had no tests before this plan. The characterisation tests are the entire safety net for the Task 2 split. If they are written after the refactor they prove nothing — they would pin whatever the new code happens to do, including its bugs.

**The options come off `filtered()`, never off a query you rebuild.** The location fence lives in that one method. `test_a_scoped_user_is_offered_only_their_own_location` exists to catch an options query that reconstructs the filters by hand and forgets `forUser()` — a leak that every other test in the file would pass.

**Pick-only is the intended behaviour, not a gap.** Typing narrows the menu and nothing else; the filter changes when a row is clicked. If you find yourself adding "filter as you type" to `SearchSelect`, re-read the spec's Section 1 — the exact `product_id` depends on a selection having been made.

**`q` stays on the endpoint.** It is gone from the UI, not from the API, so that a URL someone bookmarked before this change still resolves. Deleting it would break links silently, which is the failure mode the URL-synced filters exist to prevent.
