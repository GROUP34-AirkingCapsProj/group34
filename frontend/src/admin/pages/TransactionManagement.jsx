import React, { useMemo, useState, useEffect } from 'react';
import AdminLayout from '../components/AdminLayout';
import { receivingsAPI, issuancesAPI, transfersAPI, adjustmentsAPI } from '../services/api';
import '../styles/dashboard_air.css';
import '../styles/transaction_management.css';

const TransactionManagement = () => {
  const [transactions, setTransactions] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [typeFilter, setTypeFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [locationFilter, setLocationFilter] = useState('');
  const [locations, setLocations] = useState([]);

  const normalizeStatus = (status) => {
    if (status != null && typeof status === 'object') {
      const name = status.status_name ?? status.name ?? status.label;
      if (name != null && name !== '') return normalizeStatus(name);
      return 'Pending';
    }
    const value = String(status || '').toLowerCase();
    if (!value || value === '[object object]') return 'Pending';
    if (value.includes('complete') || value.includes('received') || value.includes('done')) return 'Completed';
    if (value.includes('cancel') || value.includes('reject') || value.includes('deny') || value.includes('void')) return 'Cancelled';
    return 'Pending';
  };

  const formatDateTime = (value) => {
    if (!value) return 'N/A';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString('en-PH', {
      year: 'numeric',
      month: 'short',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
    });
  };

  const mapReceiving = (row) => {
    const details = Array.isArray(row.details) ? row.details : [];
    const qtyFromLines = details.reduce((sum, d) => sum + Number(d.quantity_amount ?? 0), 0);
    const qty = Number(row.total_quantity_received ?? 0) || qtyFromLines;
    const firstProduct = details[0]?.product;
    const unitPrice = Number(
      row.unit_cost ?? row.unit_price ?? row.cost_price ?? firstProduct?.cost_price ?? firstProduct?.unit_price ?? 0,
    );
    const total = Number(row.total_amount ?? row.total ?? qty * unitPrice ?? 0);
    const location = row.location?.location_name || row.branch?.branch_name || row.warehouse?.warehouse_name || row.location_name || row.destination || 'N/A';
    const rid = row.receiving_id ?? row.id;
    const reference =
      row.receiving_number
      || row.reference_no
      || row.dr_no
      || row.rr_no
      || (rid != null && rid !== '' ? `RCV-${rid}` : 'Receiving');
    let status = normalizeStatus(row.status?.status_name ?? row.status ?? row.receiving_status);
    /* API receivings often have no status_id — if lines were posted and qty > 0, stock was received. */
    if (status === 'Pending' && qty > 0) status = 'Completed';

    return {
      id: `RCV-${rid ?? Math.random().toString(36).slice(2, 8)}`,
      type: 'Receiving',
      itemName:
        firstProduct?.product_name
        || row.product?.product_name
        || row.item_name
        || row.description
        || (details.length > 1 ? `Stock Receiving (${details.length} items)` : 'Stock Receiving'),
      location,
      reference,
      qty,
      unitPrice,
      total,
      status,
      occurredAt: row.received_at || row.receiving_date || row.transaction_date || row.created_at,
    };
  };

  const mapIssuance = (row) => {
    const details = Array.isArray(row.details) ? row.details : [];
    const qtyFromLines = details.reduce((sum, d) => sum + Number(d.quantity_issued ?? 0), 0);
    const qty = Number(row.total_quantity ?? row.quantity ?? row.issued_qty ?? row.qty ?? 0) || qtyFromLines;
    const firstProduct = details[0]?.product;
    const unitPrice = Number(row.unit_cost ?? row.unit_price ?? row.cost_price ?? firstProduct?.cost_price ?? 0);
    const total = Number(row.total_amount ?? row.total ?? qty * unitPrice ?? 0);
    const location = row.location?.location_name || row.branch?.branch_name || row.source || row.location_name || 'N/A';
    const iid = row.issuance_id ?? row.id;
    const reference =
      row.issuance_number
      || row.reference_no
      || row.issue_no
      || (iid != null && iid !== '' ? `ISS-${iid}` : 'Issuance');
    return {
      id: `ISS-${iid ?? Math.random().toString(36).slice(2, 8)}`,
      type: 'Issuance',
      itemName:
        firstProduct?.product_name
        || row.product?.product_name
        || row.item_name
        || row.description
        || (details.length > 1 ? `Stock Issuance (${details.length} items)` : 'Stock Issuance'),
      location,
      reference,
      qty,
      unitPrice,
      total,
      status: normalizeStatus(row.status?.status_name ?? row.status ?? row.issuance_status),
      occurredAt: row.issued_at || row.issuance_date || row.transaction_date || row.created_at,
    };
  };

  const mapTransfer = (row) => {
    const details = Array.isArray(row.details) ? row.details : [];
    const qtyFromLines = details.reduce((sum, d) => sum + Number(d.quantity_transferred ?? d.quantity ?? 0), 0);
    const qty = Number(row.total_quantity_transferred ?? row.quantity ?? row.transfer_qty ?? row.qty ?? 0) || qtyFromLines;
    const firstProduct = details[0]?.product;
    const unitPrice = Number(row.unit_cost ?? row.unit_price ?? row.cost_price ?? firstProduct?.cost_price ?? 0);
    const total = Number(row.total_amount ?? row.total ?? qty * unitPrice ?? 0);
    const fromLoc = row.from_location?.location_name || row.source_location?.location_name || row.source || row.from || 'N/A';
    const toLoc = row.to_location?.location_name || row.destination_location?.location_name || row.destination || row.to || 'N/A';
    const tid = row.transfer_id ?? row.id;
    const reference =
      row.transfer_number
      || row.reference_no
      || row.transfer_no
      || (tid != null && tid !== '' ? `TRF-${tid}` : 'Transfer');
    return {
      id: `TRF-${tid ?? Math.random().toString(36).slice(2, 8)}`,
      type: 'Transfer',
      itemName:
        firstProduct?.product_name
        || row.product?.product_name
        || row.item_name
        || row.description
        || (details.length > 1 ? `Stock Transfer (${details.length} items)` : 'Stock Transfer'),
      location: `${fromLoc} -> ${toLoc}`,
      reference,
      qty,
      unitPrice,
      total,
      status: normalizeStatus(row.status?.status_name ?? row.status ?? row.transfer_status),
      occurredAt: row.transfer_date || row.transaction_date || row.created_at,
    };
  };

  const mapAdjustment = (row) => {
    const qty = Number(row.quantity ?? row.adjusted_qty ?? row.qty ?? 0);
    const unitPrice = Number(row.unit_cost ?? row.unit_price ?? row.cost_price ?? 0);
    const total = Number(row.total_amount ?? row.total ?? Math.abs(qty) * unitPrice ?? 0);
    const location = row.location?.location_name || row.branch?.branch_name || row.location_name || 'N/A';
    return {
      id: `ADJ-${row.adjustment_id ?? row.id ?? Math.random().toString(36).slice(2, 8)}`,
      type: 'Adjustment',
      itemName: row.product?.product_name || row.item_name || row.reason || 'Stock Adjustment',
      location,
      reference: row.reference_no || row.adjustment_no || `ADJ-${row.adjustment_id ?? row.id ?? ''}`,
      qty,
      unitPrice,
      total,
      status: normalizeStatus(row.status || row.adjustment_status),
      occurredAt: row.adjustment_date || row.transaction_date || row.created_at,
    };
  };

  useEffect(() => {
    const fetchAllTransactions = async () => {
      setLoading(true);
      setError(null);
      try {
        const [receivingsRes, issuancesRes, transfersRes, adjustmentsRes] = await Promise.allSettled([
          receivingsAPI.getAll({ per_page: 300 }),
          issuancesAPI.getAll({ per_page: 300 }),
          transfersAPI.getAll({ per_page: 300 }),
          adjustmentsAPI.getAll({ per_page: 300 }),
        ]);

        const receivings = receivingsRes.status === 'fulfilled' && Array.isArray(receivingsRes.value?.data)
          ? receivingsRes.value.data.map(mapReceiving)
          : [];
        const issuances = issuancesRes.status === 'fulfilled' && Array.isArray(issuancesRes.value?.data)
          ? issuancesRes.value.data.map(mapIssuance)
          : [];
        const transfers = transfersRes.status === 'fulfilled' && Array.isArray(transfersRes.value?.data)
          ? transfersRes.value.data.map(mapTransfer)
          : [];
        const adjustments = adjustmentsRes.status === 'fulfilled' && Array.isArray(adjustmentsRes.value?.data)
          ? adjustmentsRes.value.data.map(mapAdjustment)
          : [];

        const allTransactions = [...receivings, ...issuances, ...transfers, ...adjustments].sort((a, b) => {
          const aTs = new Date(a.occurredAt || 0).getTime();
          const bTs = new Date(b.occurredAt || 0).getTime();
          return bTs - aTs;
        });

        setTransactions(allTransactions);
        const uniqueLocations = [...new Set(allTransactions.map((txn) => txn.location).filter(Boolean))];
        setLocations(uniqueLocations);
      } catch (err) {
        setError(err.message || 'Failed to load transactions.');
        setTransactions([]);
      } finally {
        setLoading(false);
      }
    };
    fetchAllTransactions();
  }, []);

  const getStatusClass = (status) => {
    if (status === 'Completed') return 'txn-status-completed';
    if (status === 'Pending') return 'txn-status-pending';
    if (status === 'Cancelled') return 'txn-status-cancelled';
    return '';
  };

  const getTypeClass = (type) => {
    if (type === 'Receiving') return 'txn-type-receiving';
    if (type === 'Issuance') return 'txn-type-issuance';
    if (type === 'Transfer') return 'txn-type-transfer';
    if (type === 'Adjustment') return 'txn-type-adjustment';
    return '';
  };

  const filteredTransactions = useMemo(() => {
    const q = searchTerm.trim().toLowerCase();
    return transactions.filter((txn) => {
      if (typeFilter && txn.type !== typeFilter) return false;
      if (statusFilter && txn.status !== statusFilter) return false;
      if (locationFilter && txn.location !== locationFilter) return false;
      if (!q) return true;
      const target = `${txn.itemName} ${txn.reference} ${txn.location} ${txn.id}`.toLowerCase();
      return target.includes(q);
    });
  }, [transactions, searchTerm, typeFilter, statusFilter, locationFilter]);

  const totalTransactions = filteredTransactions.length;

  return (
    <AdminLayout>
      <div className="txn-page">
        <div className="txn-page-header">
          <div className="txn-page-header-left">
            <h1>Transactions</h1>
            <p>All inventory movements including receiving, issuance, transfers, and adjustments</p>
          </div>
        </div>

        {loading && <div className="txn-loading">Loading transactions...</div>}
        {error && <div className="txn-error">Error: {error}</div>}

        <div className="txn-table-card">
          <div className="txn-table-top">
            <div className="txn-table-title">
              <h3>All Transactions</h3>
              <p>{totalTransactions} total transactions</p>
            </div>
          </div>

          <div className="txn-filter-row">
            <div className="txn-search-input">
              <input
                type="text"
                placeholder="Search by item, reference, location..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
              />
            </div>
            <div className="txn-filter-group">
              <select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)}>
                <option value="">All Types</option>
                <option value="Receiving">Receiving</option>
                <option value="Issuance">Issuance</option>
                <option value="Transfer">Transfer</option>
                <option value="Adjustment">Adjustment</option>
              </select>
              <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
                <option value="">All Status</option>
                <option value="Completed">Completed</option>
                <option value="Pending">Pending</option>
                <option value="Cancelled">Cancelled</option>
              </select>
              <select value={locationFilter} onChange={(e) => setLocationFilter(e.target.value)}>
                <option value="">All Locations</option>
                {locations.map((loc) => (
                  <option key={loc} value={loc}>{loc}</option>
                ))}
              </select>
              <button className="txn-btn-reset" onClick={() => {
                setSearchTerm('');
                setTypeFilter('');
                setStatusFilter('');
                setLocationFilter('');
              }}>Reset</button>
            </div>
          </div>

          <div className="txn-feed">
            {filteredTransactions.length === 0 ? (
              <div className="txn-empty">No transactions found.</div>
            ) : (
              filteredTransactions.map((txn) => (
                <article className="txn-feed-item" key={txn.id}>
                  <div className="txn-feed-left">
                    <div className="txn-feed-title-row">
                      <span className={`txn-type-badge ${getTypeClass(txn.type)}`}>{txn.type}</span>
                      <h4>{txn.itemName}</h4>
                    </div>
                    <p className="txn-feed-meta">
                      {txn.location} • {formatDateTime(txn.occurredAt)}
                    </p>
                    <p className="txn-feed-ref">Ref: {txn.reference || txn.id}</p>
                  </div>
                  <div className="txn-feed-right">
                    <span className={`txn-status-badge ${getStatusClass(txn.status)}`}>{txn.status}</span>
                    <span className="txn-feed-qty">{Math.abs(Number(txn.qty || 0)).toLocaleString()} units</span>
                  </div>
                </article>
              ))
            )}
          </div>
        </div>
      </div>
    </AdminLayout>
  );
};

export default TransactionManagement;
