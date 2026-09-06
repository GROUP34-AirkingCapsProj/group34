import React from 'react';
import DateInput from '../DateInput';
import SearchSelect from '../SearchSelect';

/**
 * What to narrow the print queue down to.
 *
 * Fully controlled: every change is handed straight up to the page, which
 * owns the filter object, mirrors it into the URL and re-queries. Keeping no
 * state here is what lets a reload or a shared link land on the same list.
 *
 * The three pickers are fed from `options` — the distinct products, receipts
 * and locations among the tags currently being listed, computed by the queue
 * endpoint from the same filter set. They therefore offer only values that
 * return rows, and they narrow as the other filters do.
 */
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

  return (
    <div className="bpp-filters no-print">
      <div className="bpp-filter">
        <label htmlFor="bpp-type">Product type</label>
        <select
          id="bpp-type"
          value={filters.product_type || ''}
          onChange={(e) => set('product_type')(e.target.value)}
        >
          <option value="">All types</option>
          <option value="installation_material">Installation materials</option>
          <option value="appliance">Appliances</option>
        </select>
      </div>

      {/* DateInput hands back the raw change EVENT, not a value — it wires
          `onChange={onChange}` straight onto a native date input. Reading
          e.target.value here is required; passing the event through would
          store an Event object in the filter and send [object Object] to the
          API. */}
      <div className="bpp-filter">
        <label htmlFor="bpp-from">Received from</label>
        <DateInput
          id="bpp-from"
          value={filters.date_from || ''}
          onChange={(e) => set('date_from')(e.target.value)}
        />
      </div>

      <div className="bpp-filter">
        <label htmlFor="bpp-to">Received to</label>
        <DateInput
          id="bpp-to"
          value={filters.date_to || ''}
          onChange={(e) => set('date_to')(e.target.value)}
        />
      </div>

      {/* SearchSelect renders its own input and takes no id, so these three
          labels carry no htmlFor rather than pointing at nothing. */}
      <div className="bpp-filter">
        <label>Location</label>
        <SearchSelect
          items={locations}
          value={filters.location_id || ''}
          onChange={(id) => set('location_id')(id)}
          placeholder="Search location…"
          emptyText="No locations with tags"
          clearLabel="All locations"
        />
      </div>

      <div className="bpp-filter">
        <label>Receipt or PO</label>
        <SearchSelect
          items={receipts}
          value={filters.receipt || ''}
          onChange={(value) => set('receipt')(value)}
          placeholder="Search receipt or PO…"
          emptyText="No receipts match"
          clearLabel="All receipts"
        />
      </div>

      <div className="bpp-filter">
        <label>Product</label>
        <SearchSelect
          items={products}
          value={filters.product_id || ''}
          onChange={(id) => set('product_id')(id)}
          placeholder="Search product…"
          emptyText="No products with tags"
          clearLabel="All products"
        />
      </div>

      <div className="bpp-filter">
        <label htmlFor="bpp-state">Print state</label>
        <select
          id="bpp-state"
          value={filters.print_state || 'all'}
          onChange={(e) => set('print_state')(e.target.value)}
        >
          <option value="all">All</option>
          <option value="unsent">Not yet sent</option>
          <option value="sent">Sent to printer</option>
        </select>
      </div>

      {counts && (
        <p className="bpp-filter-counts" role="status">
          {counts.total} tag{counts.total === 1 ? '' : 's'} · {counts.unsent} not yet sent
        </p>
      )}
    </div>
  );
}
