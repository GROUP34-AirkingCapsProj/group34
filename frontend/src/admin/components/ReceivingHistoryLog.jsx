import { useState, useEffect, useCallback } from 'react';
import Loader from './Loader';
import Pagination from './Pagination';
import DateInput from './DateInput';
import { receivingsAPI } from '../services/api';
import '../styles/receiving_history_log.css';

const fmtDate = (s) => {
  if (!s) return '—';
  const [y, m, d] = String(s).slice(0, 10).split('-');
  return m && d ? `${m}/${d}/${y}` : String(s).slice(0, 10);
};

const EMPTY_PAGINATION = { current_page: 1, last_page: 1, per_page: 20, total: 0 };

/**
 * Persistent, PO-grouped log of receiving activity across all purchase orders.
 * Row click opens the shared PO History timeline via onSelectPo(po_id).
 */
export default function ReceivingHistoryLog({ locations = [], onSelectPo }) {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(false);
  const [search, setSearch] = useState('');
  const [locationId, setLocationId] = useState('');
  const [fromDate, setFromDate] = useState('');
  const [toDate, setToDate] = useState('');
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState(EMPTY_PAGINATION);

  const load = useCallback(() => {
    setLoading(true);
    const params = { page, per_page: 20 };
    if (search.trim()) params.search = search.trim();
    if (locationId) params.location_id = locationId;
    if (fromDate) params.from_date = fromDate;
    if (toDate) params.to_date = toDate;
    receivingsAPI.poLog(params)
      .then((res) => {
        setRows(Array.isArray(res?.data) ? res.data : []);
        setPagination(res?.pagination || EMPTY_PAGINATION);
      })
      .catch(() => { setRows([]); setPagination(EMPTY_PAGINATION); })
      .finally(() => setLoading(false));
  }, [page, search, locationId, fromDate, toDate]);

  useEffect(() => { load(); }, [load]);
  useEffect(() => { setPage(1); }, [search, locationId, fromDate, toDate]);

  return (
    <div className="rhl-panel">
      <div className="rhl-filters">
        <input
          className="rhl-search"
          placeholder="Search by PO number or supplier..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <select className="rhl-select" value={locationId} onChange={(e) => setLocationId(e.target.value)}>
          <option value="">All warehouses</option>
          {locations.map((l) => (
            <option key={l.location_id ?? l.id} value={l.location_id ?? l.id}>
              {l.location_name || l.name}
            </option>
          ))}
        </select>
        <DateInput value={fromDate} onChange={(e) => setFromDate(e.target.value)} className="rhl-date" aria-label="From date" />
        <span className="rhl-date-sep">to</span>
        <DateInput value={toDate} onChange={(e) => setToDate(e.target.value)} className="rhl-date" aria-label="To date" />
      </div>

      {loading ? (
        <Loader label="Loading receiving log" />
      ) : rows.length === 0 ? (
        <div className="rhl-empty">No receiving activity found.</div>
      ) : (
        <div className="rhl-list">
          {rows.map((po) => (
            <button
              type="button"
              key={po.po_id}
              className="rhl-row"
              onClick={() => onSelectPo(po.po_id)}
            >
              <div className="rhl-row-main">
                <span className="rhl-po-number">{po.po_number}</span>
                <span className="rhl-supplier">{po.supplier?.supplier_name || '—'}</span>
              </div>
              <div className="rhl-row-meta">
                <span>{Array.isArray(po.warehouses) && po.warehouses.length ? po.warehouses.join(', ') : '—'}</span>
                <span>{po.events_count} event{po.events_count === 1 ? '' : 's'}</span>
                <span>Last received {fmtDate(po.last_received_date)}</span>
                <span>{po.total_items ?? 0} items</span>
              </div>
            </button>
          ))}
        </div>
      )}

      <Pagination
        currentPage={pagination.current_page}
        totalPages={pagination.last_page}
        totalItems={pagination.total}
        pageSize={pagination.per_page}
        onPageChange={setPage}
        label="purchase orders"
      />
    </div>
  );
}
