import React from 'react';
import DateInput from '../DateInput';
import LocationSelector from '../LocationSelector';

/**
 * What to narrow the print queue down to.
 *
 * Fully controlled: every change is handed straight up to the page, which
 * owns the filter object, mirrors it into the URL and re-queries. Keeping no
 * state here is what lets a reload or a shared link land on the same list.
 */
export default function TagFilterBar({ filters, onChange, counts, allowedLocations = [] }) {
  const set = (key) => (value) => onChange({ ...filters, [key]: value });

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

      {/* LocationSelector renders its own <label>Location</label> and its own
          fixed id, and takes no `id` prop — so it gets neither here, or the
          page would show the word twice. Unlike DateInput it hands back a
          VALUE (Number or null), not an event.

          It also does not fetch anything: `allowedLocations` must be supplied,
          and it degrades to a plain text input when that list is empty. The
          page sources it from useUserLocation() and passes it down. */}
      <div className="bpp-filter">
        <LocationSelector
          value={filters.location_id ? Number(filters.location_id) : null}
          onChange={(value) => set('location_id')(value != null ? String(value) : '')}
          allowedLocations={allowedLocations}
        />
      </div>

      <div className="bpp-filter">
        <label htmlFor="bpp-receipt">Receipt or PO</label>
        <input
          id="bpp-receipt"
          type="text"
          value={filters.receipt || ''}
          placeholder="RCV-… or PO-…"
          onChange={(e) => set('receipt')(e.target.value)}
        />
      </div>

      <div className="bpp-filter">
        <label htmlFor="bpp-q">Product</label>
        <input
          id="bpp-q"
          type="text"
          value={filters.q || ''}
          placeholder="Name or code"
          onChange={(e) => set('q')(e.target.value)}
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
