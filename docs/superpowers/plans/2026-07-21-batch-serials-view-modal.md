# View Batch Serial Numbers in a Modal — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the inline batch-serial chips (Console expander + Warehouse Map cards) with a compact "View serials (N)" trigger that opens the serials in a shared modal.

**Architecture:** One new state value holds the batch whose serials are shown. Each view renders a trigger button that sets it; a single reused `Modal` renders the list. Purely presentational — no backend/API change.

**Tech Stack:** React (CRA). Existing `Modal` component + existing `inv-serial-chips` styles.

---

## Background / Context

- File: `frontend/src/admin/pages/InventoryManagement.jsx`.
- `Modal` (`frontend/src/admin/components/Modal.jsx`) is already imported (line 4) and takes
  `isOpen`, `onClose`, `title`, `maxWidth`; its body scrolls at `max-height: 90vh`.
- The endpoints already return `serials` (string[]) per batch (done in a prior change).
- Existing state hooks are declared around lines 100-137; the batch/zone ones end at
  `const [editingZoneValue, setEditingZoneValue] = useState('');` (line 125).
- Current inline chip markup (added previously) exists in two spots:
  - Console Batches expander `<td>` (renders `b.serials`).
  - Warehouse Map card `.inv-warehouse-map-serials` block (renders `batch.serials`).
- The existing detail `Modal` block ends at line 1530-1531 (`</Modal>` then `)}`), just
  before the container `</div>` (line 1532). The new modal goes there.
- `inv-action-link` is the existing link-button style used by the row "View"/"Batches"
  actions.

## File Structure

- Modify: `frontend/src/admin/pages/InventoryManagement.jsx` — add state, swap two inline
  chip blocks for triggers, add one shared modal.
- Modify: `frontend/src/admin/styles/inventory_management.css` — small modal-body styles.

---

## Task 1: Trigger + shared modal for batch serials

**Files:**
- Modify: `frontend/src/admin/pages/InventoryManagement.jsx`
- Modify: `frontend/src/admin/styles/inventory_management.css`

- [ ] **Step 1: Add the state hook**

Locate (line 125):

```jsx
  const [editingZoneValue, setEditingZoneValue] = useState('');
```

Insert immediately after it:

```jsx
  const [serialModalBatch, setSerialModalBatch] = useState(null);
```

- [ ] **Step 2: Replace the Console expander inline chips with a trigger**

Locate this exact block (the Serial Numbers `<td>` in the Console Batches expander):

```jsx
                                          <td>
                                            {b.serials && b.serials.length > 0 ? (
                                              <div className="inv-serial-chips">
                                                {b.serials.map((sn, i) => (
                                                  <span className="inv-serial-chip" key={i}>{sn}</span>
                                                ))}
                                              </div>
                                            ) : '—'}
                                          </td>
```

Replace with:

```jsx
                                          <td>
                                            {b.serials && b.serials.length > 0 ? (
                                              <button
                                                type="button"
                                                className="inv-action-link"
                                                onClick={() => setSerialModalBatch({
                                                  title: b.is_legacy ? 'Pre-tracking stock' : (b.po_number || 'Batch'),
                                                  serials: b.serials,
                                                })}
                                              >
                                                View serials ({b.serials.length})
                                              </button>
                                            ) : '—'}
                                          </td>
```

- [ ] **Step 3: Replace the Warehouse Map inline chips with a trigger**

Locate this exact block:

```jsx
                      <div className="inv-warehouse-map-serials">
                        <span className="inv-warehouse-map-meta-label">Serial Numbers</span>
                        {batch.serials && batch.serials.length > 0 ? (
                          <div className="inv-serial-chips">
                            {batch.serials.map((sn, i) => (
                              <span className="inv-serial-chip" key={i}>{sn}</span>
                            ))}
                          </div>
                        ) : (
                          <span className="inv-warehouse-map-meta-value">—</span>
                        )}
                      </div>
```

Replace with:

```jsx
                      <div className="inv-warehouse-map-serials">
                        <span className="inv-warehouse-map-meta-label">Serial Numbers</span>
                        {batch.serials && batch.serials.length > 0 ? (
                          <button
                            type="button"
                            className="inv-action-link"
                            onClick={() => setSerialModalBatch({
                              title: batch.product_name || batch.po_number || 'Batch',
                              serials: batch.serials,
                            })}
                          >
                            View serials ({batch.serials.length})
                          </button>
                        ) : (
                          <span className="inv-warehouse-map-meta-value">—</span>
                        )}
                      </div>
```

- [ ] **Step 4: Add the shared modal**

Locate the end of the existing detail modal block (lines 1530-1532):

```jsx
            </div>
          </Modal>
        )}
      </div>
    </AdminLayout>
```

Replace with (inserts the serials modal between the detail modal and the container close):

```jsx
            </div>
          </Modal>
        )}

        {serialModalBatch && (
          <Modal
            isOpen={!!serialModalBatch}
            onClose={() => setSerialModalBatch(null)}
            title={`Serial Numbers — ${serialModalBatch.title}`}
            maxWidth={560}
          >
            <div className="inv-serial-modal">
              <div className="inv-serial-modal-count">
                {serialModalBatch.serials.length} serial number{serialModalBatch.serials.length === 1 ? '' : 's'}
              </div>
              <div className="inv-serial-chips">
                {serialModalBatch.serials.map((sn, i) => (
                  <span className="inv-serial-chip" key={i}>{sn}</span>
                ))}
              </div>
            </div>
          </Modal>
        )}
      </div>
    </AdminLayout>
```

- [ ] **Step 5: Add modal-body styles**

In `frontend/src/admin/styles/inventory_management.css`, append at the end of the file:

```css
/* Batch serials modal body */
.inv-serial-modal {
  padding-top: 12px;
}
.inv-serial-modal-count {
  font-size: 12px;
  font-weight: 600;
  color: #6b7280;
  margin-bottom: 12px;
}
```

- [ ] **Step 6: Lint**

Run: `cd frontend && npx eslint src/admin/pages/InventoryManagement.jsx`
Expected: `0 errors`. The pre-existing `no-unused-vars` warning on `warehouses` (~line 104)
is unrelated and acceptable. Report the actual output.

- [ ] **Step 7: Manual verification**

Open the app → Inventory:
- **Console** tab → expand a SKU's **Batches**: a batch with serials shows `View serials (N)`;
  clicking opens the modal listing them (scrolls if long); a batch with none shows `—`.
- **Warehouse Map** tab: each card's Serial Numbers section shows the trigger or `—`; the
  modal title uses the product name.
- Close via the X, overlay click, and Esc — each clears the modal.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/admin/pages/InventoryManagement.jsx frontend/src/admin/styles/inventory_management.css
git commit -m "feat(inventory): view batch serial numbers in a modal instead of inline chips"
```

---

## Self-Review Notes

- **Spec coverage:** trigger in both views (Steps 2-3), single reused modal driven by state
  (Steps 1, 4), `—` empty case preserved (Steps 2-3), modal styles (Step 5) — all covered.
- **Consistency:** both triggers set `serialModalBatch` to a `{ title, serials }` snapshot;
  the modal reads exactly those; `inv-action-link` and `inv-serial-chips` are existing classes.
- **No backend change:** endpoints already return `serials`.
- **No placeholders:** all code shown in full.
