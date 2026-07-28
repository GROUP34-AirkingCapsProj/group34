import React, { useMemo, useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import Pagination from '../components/Pagination';
import Modal from '../components/Modal';
import {
  receivingsAPI, issuancesAPI, issuanceOrderAPI, issuanceScanAPI, transfersAPI,
  adjustmentsAPI, customersAPI, companySettingsAPI, getApiBaseUrl, getToken,
} from '../services/api';
import { toast } from '../utils/toast';
import { confirmAction } from '../utils/confirm';
import '../styles/dashboard_air.css';
import '../styles/transaction_management.css';

const PAGE_SIZE = 20;

const fmtMoney = (val) => {
  const n = parseFloat(val);
  if (val === null || val === undefined || Number.isNaN(n)) return '—';
  return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

const userFullName = (u) =>
  u ? [u.first_name, u.last_name].filter(Boolean).join(' ').trim() || u.username || null : null;

// Class for the REAL issuance status name shown inside the detail modal.
const issuanceStatusClass = (name) => {
  switch (name) {
    case 'Completed': return 'txn-status-completed';
    case 'Pending':   return 'txn-status-pending';
    case 'Draft':     return 'txn-status-draft';
    case 'Voided':
    case 'Cancelled': return 'txn-status-cancelled';
    default:          return '';
  }
};

const TransactionManagement = () => {
  const [transactions, setTransactions] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [typeFilter, setTypeFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [locationFilter, setLocationFilter] = useState('');
  const [locations, setLocations] = useState([]);
  const [currentPage, setCurrentPage] = useState(1);

  const navigate = useNavigate();
  // Issue-out detail modal state.
  const [activeIssuanceId, setActiveIssuanceId] = useState(null);
  const [detail, setDetail] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState('');
  const [cancelling, setCancelling] = useState(false);
  const [activeTransaction, setActiveTransaction] = useState(null);
  const [movementDetail, setMovementDetail] = useState(null);
  const [movementLoading, setMovementLoading] = useState(false);
  const [movementError, setMovementError] = useState('');
  const [pdfPaperSize, setPdfPaperSize] = useState('a4');

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
    if (value.includes('draft')) return 'Draft';
    return 'Pending';
  };

  const formatDateTime = (value) => {
    if (!value) return 'N/A';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString('en-US', {
      month: '2-digit',
      day: '2-digit',
      year: 'numeric',
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
      entityId: rid ?? null,
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
      row.dr_number
      || row.issuance_number
      || row.reference_no
      || row.issue_no
      || (iid != null && iid !== '' ? `ISS-${iid}` : 'Issuance');
    return {
      id: `ISS-${iid ?? Math.random().toString(36).slice(2, 8)}`,
      entityId: iid ?? null,
      issuanceId: iid ?? null,
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
      entityId: tid ?? null,
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
    const details = Array.isArray(row.details) ? row.details : [];
    const qty = details.length
      ? details.reduce((sum, d) => sum + Math.abs(Number(d.add_quantity || 0) - Number(d.deduct_quantity || 0)), 0)
      : Number(row.total_variance_positive ?? 0) + Number(row.total_variance_negative ?? 0);
    const unitPrice = Number(row.unit_cost ?? row.unit_price ?? row.cost_price ?? 0);
    const total = Number(row.total_amount ?? row.total ?? Math.abs(qty) * unitPrice ?? 0);
    const location = row.location?.location_name || row.branch?.branch_name || row.location_name || 'N/A';
    return {
      id: `ADJ-${row.adjustment_id ?? row.id ?? Math.random().toString(36).slice(2, 8)}`,
      entityId: row.adjustment_id ?? row.id ?? null,
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

  const fetchAllTransactions = useCallback(async () => {
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
  }, []);

  useEffect(() => {
    fetchAllTransactions();
    companySettingsAPI.get()
      .then((settings) => setPdfPaperSize(settings?.pdf_paper_size || 'a4'))
      .catch(() => {});
  }, [fetchAllTransactions]);

  useEffect(() => {
    if (!activeTransaction || activeTransaction.type === 'Issuance') return undefined;
    let stale = false;
    const api = activeTransaction.type === 'Receiving'
      ? receivingsAPI
      : activeTransaction.type === 'Transfer'
        ? transfersAPI
        : adjustmentsAPI;
    setMovementLoading(true);
    setMovementError('');
    api.getById(activeTransaction.entityId)
      .then((res) => { if (!stale) setMovementDetail(res?.data || res); })
      .catch((err) => { if (!stale) setMovementError(err?.message || 'Failed to load transaction details.'); })
      .finally(() => { if (!stale) setMovementLoading(false); });
    return () => { stale = true; };
  }, [activeTransaction]);

  const transactionDocumentUrl = (txn, preview = false) => {
    if (!txn?.entityId) return null;
    if (txn.type === 'Issuance') return issuanceScanAPI.drPdfUrl(txn.entityId, preview, pdfPaperSize);
    const params = new URLSearchParams();
    const token = getToken();
    if (token) params.set('token', token);
    params.set('paper_size', pdfPaperSize);
    if (preview) params.set('preview', '1');
    const path = txn.type === 'Receiving'
      ? `/receivings/${txn.entityId}/receipt/pdf`
      : txn.type === 'Transfer'
        ? `/transfers/${txn.entityId}/receipt/pdf`
        : `/adjustments/${txn.entityId}/pdf`;
    return `${getApiBaseUrl()}${path}?${params.toString()}`;
  };

  // Fetch issue-out detail (header + priced quote + customer) when a row is opened.
  useEffect(() => {
    if (activeIssuanceId == null) return undefined;
    let stale = false;
    const load = async () => {
      setDetailLoading(true);
      setDetailError('');
      setDetail(null);
      try {
        const [showRes, quoteRes] = await Promise.allSettled([
          issuancesAPI.getById(activeIssuanceId),
          issuanceOrderAPI.quote(activeIssuanceId),
        ]);
        if (showRes.status !== 'fulfilled') {
          throw new Error(showRes.reason?.message || 'Failed to load issue-out.');
        }
        const header = showRes.value?.data || {};
        const quote = quoteRes.status === 'fulfilled' ? (quoteRes.value?.data || null) : null;

        // Recipient is the Customer relation (issued_to_user_id is unused for sales).
        // show does not eager-load `customer`, so resolve the name by id when needed.
        let customerName = header.customer?.customer_name || null;
        const customerId = header.customer_id ?? quote?.customer_id ?? null;
        if (!customerName && customerId) {
          try {
            const cRes = await customersAPI.getById(customerId);
            customerName = cRes?.data?.customer_name || null;
          } catch (e) { /* leave customerName null on failure */ }
        }

        const statusName = header.status?.status_name || header.status_name || '—';
        const quoteLines = Array.isArray(quote?.lines) ? quote.lines : [];
        const quoteByProduct = new Map(quoteLines.map((line) => [String(line.product_id), line]));
        const priced = quoteLines.length > 0;
        const headerLines = Array.isArray(header.details) ? header.details : [];
        const rawLines = (headerLines.length ? headerLines : quoteLines).map((d) => {
          const productId = d.product?.product_id ?? d.product_id ?? null;
          const pricedLine = quoteByProduct.get(String(productId));
          const qty = Number(d.quantity_issued ?? d.quantity_in_pieces ?? d.quantity_requested ?? 0) || 0;
          const unitPrice = d.unit_price_per_piece ?? d.unit_price ?? pricedLine?.unit_price ?? null;
          return {
            productId,
            name: d.product?.product_name || d.product_name || 'Item',
            code: d.product?.appliance_detail?.model_code || d.product?.product_code || '—',
            serial: d.serial_number || d.scanned_barcode || null,
            qty,
            unitPrice,
            lineTotal: d.subtotal != null ? Number(d.subtotal) : (unitPrice != null ? qty * Number(unitPrice) : null),
          };
        });

        // A FIFO pick records one line per scanned unit, so the same product can
        // appear many times. Group by product → one row per product, qty summed.
        const byProduct = new Map();
        const lines = [];
        rawLines.forEach((r) => {
          const k = r.productId != null ? `p:${r.productId}` : `n:${r.name}`;
          const existing = byProduct.get(k);
          if (existing) {
            existing.qty += r.qty;
            if (existing.lineTotal != null && r.lineTotal != null) existing.lineTotal += r.lineTotal;
          } else {
            const g = { key: k, name: r.name, code: r.code, serials: r.serial ? [r.serial] : [], qty: r.qty, unitPrice: r.unitPrice, lineTotal: r.lineTotal };
            byProduct.set(k, g);
            lines.push(g);
          }
          if (existing && r.serial && !existing.serials.includes(r.serial)) existing.serials.push(r.serial);
        });

        if (!stale) {
          setDetail({
            id: activeIssuanceId,
            drNumber: quote?.dr_number || header.dr_number || null,
            externalReference: header.external_reference || quote?.external_reference || null,
            customerName,
            customerId,
            locationName: header.location?.location_name || '—',
            issuedByName: userFullName(header.issuedByUser) || '—',
            issuanceDate: header.issuance_date || header.created_at || null,
            statusName,
            isDraft: statusName === 'Draft',
            priced,
            lines,
            subtotal: quote?.subtotal ?? null,
            discountAmount: quote?.discount_amount ?? null,
            totalAmount: quote?.total_amount ?? null,
          });
        }
      } catch (err) {
        if (!stale) {
          setDetailError(err.message || 'Failed to load issue-out.');
          toast.error(err.message || 'Failed to load issue-out.');
        }
      } finally {
        if (!stale) setDetailLoading(false);
      }
    };
    load();
    return () => { stale = true; };
  }, [activeIssuanceId]);

  const closeDetail = () => {
    setActiveIssuanceId(null);
    setDetail(null);
    setDetailError('');
  };

  const handleContinueDraft = () => {
    if (activeIssuanceId == null) return;
    navigate('/admin/issuance-scan?issuance=' + activeIssuanceId);
  };

  const handleCancelIssueOut = async () => {
    if (activeIssuanceId == null) return;
    const ok = await confirmAction({
      title: 'Cancel this issue-out?',
      text: 'It will be kept in history as Cancelled.',
      confirmText: 'Cancel issue-out',
      cancelText: 'Keep it',
      tone: 'danger',
    });
    if (!ok) return;
    setCancelling(true);
    try {
      await issuancesAPI.cancel(activeIssuanceId);
      toast.success('Issue-out cancelled.');
      closeDetail();
      fetchAllTransactions();
    } catch (err) {
      toast.error(err.message || 'Failed to cancel issue-out.');
    } finally {
      setCancelling(false);
    }
  };

  const getStatusClass = (status) => {
    if (status === 'Completed') return 'txn-status-completed';
    if (status === 'Pending') return 'txn-status-pending';
    if (status === 'Draft') return 'txn-status-draft';
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
  const totalPages        = Math.ceil(totalTransactions / PAGE_SIZE) || 1;
  const pagedTransactions = filteredTransactions.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);

  // reset page when filters change
  useEffect(() => { setCurrentPage(1); }, [searchTerm, typeFilter, statusFilter, locationFilter]);

  return (
    <AdminLayout>
      <div className="ak-page txn-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Inventory · Movement Ledger</p>
              <h1 className="ak-title">Transactions</h1>
              <p className="ak-sub">All inventory movements including receiving, issuance, transfers, and adjustments.</p>
            </div>
          </div>
        </header>

        {loading && <Loader label="Loading transactions" />}
        {error && <div className="ak-state ak-state--error">Error: {error}</div>}

        <div className="ak-card txn-table-card">
          <div className="ak-card-head">
            <div>
              <h2 className="ak-card-title">All Transactions</h2>
              <p className="ak-card-meta">{totalTransactions} total transactions</p>
            </div>
          </div>

          <div className="ak-toolbar ak-toolbar--push">
            <div className="ak-search">
              <input
                className="ak-input"
                type="text"
                placeholder="Search by item, reference, location..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
              />
            </div>
            <select className="ak-select" value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)}>
              <option value="">All Types</option>
              <option value="Receiving">Receiving</option>
              <option value="Issuance">Issuance</option>
              <option value="Transfer">Transfer</option>
              <option value="Adjustment">Adjustment</option>
            </select>
            <select className="ak-select" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
              <option value="">All Status</option>
              <option value="Completed">Completed</option>
              <option value="Pending">Pending</option>
              <option value="Draft">Draft</option>
              <option value="Cancelled">Cancelled</option>
            </select>
            <select className="ak-select" value={locationFilter} onChange={(e) => setLocationFilter(e.target.value)}>
              <option value="">All Locations</option>
              {locations.map((loc) => (
                <option key={loc} value={loc}>{loc}</option>
              ))}
            </select>
            <button className="ak-btn ak-btn--ghost" onClick={() => {
              setSearchTerm('');
              setTypeFilter('');
              setStatusFilter('');
              setLocationFilter('');
            }}>Reset</button>
          </div>

          <div className="txn-feed">
            {filteredTransactions.length === 0 ? (
              <div className="txn-empty">No transactions found.</div>
            ) : (
              pagedTransactions.map((txn) => {
                const isIssueOut = txn.type === 'Issuance' && txn.issuanceId != null;
                const openTransaction = () => {
                  if (isIssueOut) setActiveIssuanceId(txn.issuanceId);
                  else {
                    setMovementDetail(null);
                    setActiveTransaction(txn);
                  }
                };
                return (
                <article
                  className="txn-feed-item"
                  key={txn.id}
                  role="button"
                  tabIndex={0}
                  title={`View ${txn.type.toLowerCase()} details`}
                  style={{ cursor: 'pointer' }}
                  onClick={openTransaction}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                      e.preventDefault();
                      openTransaction();
                    }
                  }}
                >
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
                );
              })
            )}
          </div>
          <Pagination
            currentPage={currentPage}
            totalPages={totalPages}
            onPageChange={setCurrentPage}
            totalItems={totalTransactions}
            pageSize={PAGE_SIZE}
            label="transactions"
          />
        </div>
      </div>

      <Modal
        isOpen={activeIssuanceId != null}
        onClose={closeDetail}
        title={detail?.drNumber ? `Issue-out · ${detail.drNumber}` : 'Issue-out detail'}
        maxWidth={720}
      >
        {detailLoading && <Loader label="Loading issue-out" />}
        {!detailLoading && detailError && (
          <div className="ak-state ak-state--error" style={{ marginTop: '1rem' }}>{detailError}</div>
        )}
        {!detailLoading && !detailError && detail && (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem', paddingTop: '1rem' }}>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '0.7rem' }}>
              <div>
                <div className="ak-label">Customer</div>
                <div>{detail.customerName || '—'}</div>
              </div>
              <div>
                <div className="ak-label">Location</div>
                <div>{detail.locationName}</div>
              </div>
              <div>
                <div className="ak-label">Issued by</div>
                <div>{detail.issuedByName}</div>
              </div>
              <div>
                <div className="ak-label">Date</div>
                <div>{formatDateTime(detail.issuanceDate)}</div>
              </div>
              <div>
                <div className="ak-label">DR number</div>
                <div>{detail.drNumber || '—'}</div>
              </div>
              <div>
                <div className="ak-label">Status</div>
                <div>
                  <span className={`txn-status-badge ${issuanceStatusClass(detail.statusName)}`}>{detail.statusName}</span>
                </div>
              </div>
              <div>
                <div className="ak-label">External sales reference</div>
                <div>{detail.externalReference || 'Not recorded'}</div>
              </div>
            </div>

            <div>
              <div className="ak-label" style={{ marginBottom: '0.4rem' }}>Items</div>
              {detail.lines.length === 0 ? (
                <div className="ak-muted">No line items.</div>
              ) : (
                <table className="ak-table" style={{ width: '100%', borderCollapse: 'collapse' }}>
                  <thead>
                    <tr>
                      <th style={{ textAlign: 'left' }}>Product / Model Code</th>
                      <th style={{ textAlign: 'left' }}>Serial / Scanned ID</th>
                      <th style={{ textAlign: 'right' }}>Qty</th>
                      <th style={{ textAlign: 'right' }}>Unit price</th>
                      <th style={{ textAlign: 'right' }}>Line total</th>
                    </tr>
                  </thead>
                  <tbody>
                    {detail.lines.map((l) => (
                      <tr key={l.key}>
                        <td><strong>{l.name}</strong><div className="ak-muted">{l.code || '—'}</div></td>
                        <td>{l.serials?.length ? l.serials.join(', ') : '—'}</td>
                        <td style={{ textAlign: 'right' }}>{Number(l.qty || 0).toLocaleString()}</td>
                        <td style={{ textAlign: 'right' }}>{l.unitPrice == null ? '—' : fmtMoney(l.unitPrice)}</td>
                        <td style={{ textAlign: 'right' }}>{l.lineTotal == null ? '—' : fmtMoney(l.lineTotal)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
              {!detail.priced && (
                <p className="ak-muted" style={{ marginTop: '0.4rem', fontSize: '0.8rem' }}>
                  Pricing unavailable for this issue-out — showing issued quantities only.
                </p>
              )}
            </div>

            {detail.priced && (
              <div style={{ display: 'flex', flexDirection: 'column', gap: '0.25rem', alignItems: 'flex-end' }}>
                <div>Subtotal: <strong>{fmtMoney(detail.subtotal)}</strong></div>
                {Number(detail.discountAmount) > 0 && (
                  <div>Discount: <strong>-{fmtMoney(detail.discountAmount)}</strong></div>
                )}
                <div style={{ fontSize: '1.05rem' }}>Total: <strong>{fmtMoney(detail.totalAmount)}</strong></div>
              </div>
            )}

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.6rem', borderTop: '1px solid #e5e7eb', paddingTop: '0.9rem' }}>
              {!detail.isDraft && (
                <>
                  <select className="ak-select" value={pdfPaperSize} onChange={(e) => setPdfPaperSize(e.target.value)}>
                    <option value="a4">A4</option><option value="letter">Letter</option>
                    <option value="legal">Legal</option><option value="a5">A5</option>
                  </select>
                  <button type="button" className="ak-btn" onClick={() => window.open(transactionDocumentUrl({ type: 'Issuance', entityId: activeIssuanceId }, true), '_blank')}>
                    View Document
                  </button>
                  <a className="ak-btn ak-btn--primary" href={transactionDocumentUrl({ type: 'Issuance', entityId: activeIssuanceId })} style={{ textDecoration: 'none' }}>
                    Download PDF
                  </a>
                </>
              )}
              {detail.isDraft ? (
                <>
                  <button
                    type="button"
                    className="ak-btn"
                    onClick={handleCancelIssueOut}
                    disabled={cancelling}
                  >
                    {cancelling ? 'Cancelling…' : 'Cancel issue-out'}
                  </button>
                  <button type="button" className="ak-btn ak-btn--primary" onClick={handleContinueDraft} disabled={cancelling}>
                    Continue
                  </button>
                </>
              ) : (
                <button type="button" className="ak-btn" onClick={closeDetail}>Close</button>
              )}
            </div>
          </div>
        )}
      </Modal>

      <Modal
        isOpen={activeTransaction != null}
        onClose={() => { setActiveTransaction(null); setMovementDetail(null); setMovementError(''); }}
        title={activeTransaction ? `${activeTransaction.type} · ${activeTransaction.reference}` : 'Transaction details'}
        maxWidth={860}
      >
        {movementLoading && <Loader label="Loading transaction details" />}
        {!movementLoading && movementError && <div className="ak-state ak-state--error">{movementError}</div>}
        {!movementLoading && !movementError && movementDetail && (() => {
          const txn = activeTransaction;
          const d = movementDetail;
          const rows = (Array.isArray(d.details) ? d.details : []).map((line, index) => {
            const product = line.product || {};
            const code = product.model_code || product.appliance_detail?.model_code
              || product.applianceDetail?.model_code || product.product_code || '—';
            if (txn.type === 'Receiving') {
              return {
                key: line.receiving_detail_id || index,
                product: product.product_name || `Product #${line.product_id}`,
                code,
                values: [
                  Number(line.quantity_amount || 0),
                  line.scanned_barcode || '—',
                  line.prod_price != null ? fmtMoney(line.prod_price) : '—',
                ],
              };
            }
            if (txn.type === 'Transfer') {
              return {
                key: line.transfer_detail_id || index,
                product: product.product_name || `Product #${line.product_id}`,
                code,
                values: [
                  Number(line.quantity_requested || 0),
                  Number(line.quantity_transferred || 0),
                  Number(line.quantity_received || 0),
                ],
              };
            }
            const added = Number(line.add_quantity || 0);
            const deducted = Number(line.deduct_quantity || 0);
            return {
              key: line.adjustment_detail_id || index,
              product: product.product_name || `Product #${line.product_id}`,
              code,
              values: [added, deducted, `${added - deducted > 0 ? '+' : ''}${added - deducted}`, line.reason || '—'],
            };
          });
          const headers = txn.type === 'Receiving'
            ? ['Received (pcs)', 'Serial / Scanned ID', 'Recorded price']
            : txn.type === 'Transfer'
              ? ['Requested (pcs)', 'Dispatched (pcs)', 'Received (pcs)']
              : ['Added', 'Deducted', 'Net change', 'Reason'];
          const createdBy = userFullName(d.created_by || d.createdBy) || d.adjusted_by || '—';
          const approvedBy = userFullName(d.approved_by || d.approvedBy) || '—';
          const receivedBy = userFullName(d.received_by || d.receivedBy) || '—';
          const statusName = d.status?.status_name || txn.status;

          return (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 16, paddingTop: 10 }}>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(165px,1fr))', gap: 12 }}>
                <div><div className="ak-label">Reference</div><strong>{txn.reference}</strong></div>
                <div><div className="ak-label">Status</div><span className={`txn-status-badge ${getStatusClass(normalizeStatus(statusName))}`}>{statusName || '—'}</span></div>
                <div><div className="ak-label">Transaction date</div>{formatDateTime(d.receiving_date || d.transfer_date || d.adjustment_date || d.created_at)}</div>
                <div><div className="ak-label">Location</div>{d.location?.location_name || txn.location}</div>
                {txn.type === 'Receiving' && <>
                  <div><div className="ak-label">PO number</div>{d.purchase_order?.po_number || d.purchaseOrder?.po_number || '—'}</div>
                  <div><div className="ak-label">Supplier</div>{d.purchase_order?.supplier?.supplier_name || d.purchaseOrder?.supplier?.supplier_name || '—'}</div>
                  <div><div className="ak-label">Received by</div>{receivedBy}</div>
                  <div><div className="ak-label">Total received</div>{Number(d.total_quantity_received || 0).toLocaleString()} pcs</div>
                </>}
                {txn.type === 'Transfer' && <>
                  <div><div className="ak-label">From</div>{d.from_location?.location_name || d.fromLocation?.location_name || '—'}</div>
                  <div><div className="ak-label">To</div>{d.to_location?.location_name || d.toLocation?.location_name || '—'}</div>
                  <div><div className="ak-label">Prepared by</div>{userFullName(d.prepared_by || d.preparedBy) || '—'}</div>
                  <div><div className="ak-label">Received by</div>{receivedBy}</div>
                </>}
                {txn.type === 'Adjustment' && <>
                  <div><div className="ak-label">Adjustment type</div>{d.adjustment_type || '—'}</div>
                  <div><div className="ak-label">Created by</div>{createdBy}</div>
                  <div><div className="ak-label">Approved by</div>{approvedBy}</div>
                  <div><div className="ak-label">Notes</div>{d.notes || '—'}</div>
                </>}
              </div>

              <div style={{ overflowX: 'auto' }}>
                <table className="ak-table" style={{ width: '100%', borderCollapse: 'collapse' }}>
                  <thead><tr><th style={{ textAlign: 'left' }}>Item / Model Code</th>{headers.map((h) => <th key={h} style={{ textAlign: 'right' }}>{h}</th>)}</tr></thead>
                  <tbody>{rows.length ? rows.map((row) => (
                    <tr key={row.key}>
                      <td><strong>{row.product}</strong><div className="ak-muted">{row.code}</div></td>
                      {row.values.map((value, i) => <td key={i} style={{ textAlign: 'right' }}>{value}</td>)}
                    </tr>
                  )) : <tr><td colSpan={headers.length + 1}>No line items.</td></tr>}</tbody>
                </table>
              </div>

              <div style={{ display: 'flex', justifyContent: 'flex-end', alignItems: 'center', flexWrap: 'wrap', gap: 8, borderTop: '1px solid #e5e7eb', paddingTop: 12 }}>
                <select className="ak-select" value={pdfPaperSize} onChange={(e) => setPdfPaperSize(e.target.value)}>
                  <option value="a4">A4</option><option value="letter">Letter</option>
                  <option value="legal">Legal</option><option value="a5">A5</option>
                </select>
                <button type="button" className="ak-btn" onClick={() => window.open(transactionDocumentUrl(txn, true), '_blank')}>View Document</button>
                <a className="ak-btn ak-btn--primary" href={transactionDocumentUrl(txn)} style={{ textDecoration: 'none' }}>Download PDF</a>
                <button type="button" className="ak-btn" onClick={() => { setActiveTransaction(null); setMovementDetail(null); }}>Close</button>
              </div>
            </div>
          );
        })()}
      </Modal>
    </AdminLayout>
  );
};

export default TransactionManagement;
