import React, { useMemo, useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import Pagination from '../components/Pagination';
import Modal from '../components/Modal';
import {
  receivingsAPI, issuancesAPI, issuanceOrderAPI, transfersAPI,
  adjustmentsAPI, customersAPI, companySettingsAPI, inventoryAPI,
  incidentReportsAPI, warrantyClaimsAPI, openApiFile,
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
  const [sourceWarnings, setSourceWarnings] = useState([]);
  const [lastUpdated, setLastUpdated] = useState(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [typeFilter, setTypeFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [locationFilter, setLocationFilter] = useState('');
  const [locations, setLocations] = useState([]);
  const [currentPage, setCurrentPage] = useState(1);
  const [activeTab, setActiveTab] = useState('ledger');
  const [inventoryRows, setInventoryRows] = useState([]);
  const [incidents, setIncidents] = useState([]);
  const [logisticsLoading, setLogisticsLoading] = useState(true);
  const [computationView, setComputationView] = useState(null);
  /**
   * The ledger opens on the full history, not on today.
   *
   * This page is the one place that answers "what has happened to our stock",
   * and most of what has happened did not happen today. Defaulting to 'today'
   * made the page look like it had stopped recording receivings, transfers and
   * adjustments entirely: those are posted in bursts and then sit, while
   * issuances go out daily, so on a typical morning the only rows inside the
   * window were issuances. Nothing was missing from the data — the window was.
   *
   * The filter itself is client-side; every source is fetched in full either
   * way, so 'all' costs nothing extra to load.
   */
  const [datePeriod, setDatePeriod] = useState('all');
  const [customFrom, setCustomFrom] = useState('');
  const [customTo, setCustomTo] = useState('');

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
    // 'resolve' and 'dismiss' are incident-report terminal states. Without them
    // a Resolved incident fell through to the default and sat in the ledger as
    // Pending forever, and a Dismissed one looked like outstanding work.
    if (value.includes('complete') || value.includes('received') || value.includes('done') || value.includes('resolve')) return 'Completed';
    if (value.includes('cancel') || value.includes('reject') || value.includes('deny') || value.includes('void') || value.includes('dismiss')) return 'Cancelled';
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
    if (!row.status && !row.status_id && !row.receiving_status) status = 'Posted';
    const lineItems = details.map((d, index) => {
      const quantity = Number(d.quantity_amount ?? 0);
      const cost = Number(d.prod_price ?? d.unit_cost ?? d.product?.cost_price ?? 0);
      return { key: d.receiving_detail_id ?? index, product: d.product?.product_name || `Receiving item ${index + 1}`, quantity, unitCost: cost, value: quantity * cost, serial: d.scanned_barcode || null };
    });

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
      lineItems,
    };
  };

  const mapIssuance = (row) => {
    const details = Array.isArray(row.details) ? row.details : [];
    const qtyFromLines = details.reduce((sum, d) => sum + Number(d.quantity_issued ?? 0), 0);
    const qty = Number(row.total_quantity ?? row.quantity ?? row.issued_qty ?? row.qty ?? 0) || qtyFromLines;
    const firstProduct = details[0]?.product;
    const unitPrice = Number(row.unit_price_per_piece ?? row.unit_price ?? 0);
    const total = Number(row.total_amount ?? row.total ?? qty * unitPrice ?? 0);
    const location = row.location?.location_name || row.branch?.branch_name || row.source || row.location_name || 'N/A';
    const iid = row.issuance_id ?? row.id;
    const reference =
      row.dr_number
      || row.issuance_number
      || row.reference_no
      || row.issue_no
      || (iid != null && iid !== '' ? `ISS-${iid}` : 'Issuance');
    const lineItems = details.map((d, index) => {
      const quantity = Number(d.quantity_in_pieces ?? d.quantity_issued ?? 0);
      const price = Number(d.unit_price_per_piece ?? d.unit_price ?? 0);
      return { key: d.issuance_detail_id ?? index, product: d.product?.product_name || `Issued item ${index + 1}`, quantity, unitCost: price, value: d.subtotal != null ? Number(d.subtotal) : quantity * price, serial: d.serial_number || null };
    });
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
      lineItems,
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
    const lineItems = details.map((d, index) => {
      const quantity = Number(d.quantity_transferred ?? d.quantity_received ?? d.quantity ?? 0);
      const cost = Number(d.unit_cost ?? d.product?.cost_price ?? 0);
      return { key: d.transfer_detail_id ?? index, product: d.product?.product_name || `Transfer item ${index + 1}`, quantity, unitCost: cost, value: quantity * cost, serial: d.serial_number || d.barcode || null };
    });
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
      fromLocation: fromLoc,
      toLocation: toLoc,
      reference,
      qty,
      unitPrice,
      total,
      status: normalizeStatus(row.status?.status_name ?? row.status ?? row.transfer_status),
      occurredAt: row.transfer_date || row.transaction_date || row.created_at,
      lineItems,
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
    const lineItems = details.map((d, index) => {
      const quantity = Math.abs(Number(d.add_quantity || 0) - Number(d.deduct_quantity || 0));
      const cost = Number(d.unit_cost ?? d.product?.cost_price ?? unitPrice ?? 0);
      return { key: d.adjustment_detail_id ?? index, product: d.product?.product_name || row.product?.product_name || `Adjustment item ${index + 1}`, quantity, unitCost: cost, value: quantity * cost, serial: d.serial_number || null };
    });
    return {
      id: `ADJ-${row.adjustment_id ?? row.id ?? Math.random().toString(36).slice(2, 8)}`,
      entityId: row.adjustment_id ?? row.id ?? null,
      type: 'Adjustment',
      itemName: row.product?.product_name || row.item_name || row.reason || 'Stock Adjustment',
      location,
      // `adjustment_number` is the column the API returns and the one
      // AdjustmentController's search matches on; `adjustment_no` never
      // existed, so every adjustment fell through to the synthetic ADJ-<id>.
      reference: row.adjustment_number || row.reference_no || `ADJ-${row.adjustment_id ?? row.id ?? ''}`,
      qty,
      unitPrice,
      total,
      status: normalizeStatus(row.status || row.adjustment_status),
      occurredAt: row.adjustment_date || row.transaction_date || row.created_at,
      lineItems,
    };
  };

  /**
   * Incidents arrive already flattened — the controller maps each report to a
   * payload with location_name/product_name rather than nested relations, and
   * caps at 500 with no `pagination` key. Do not reach for row.location here.
   */
  const mapIncident = (row) => {
    const qty = Number(row.quantity ?? 0);
    return {
      id: `INC-${row.incident_id ?? Math.random().toString(36).slice(2, 8)}`,
      entityId: row.incident_id ?? null,
      type: 'Incident',
      itemName: row.product_name || row.reason || 'Incident report',
      location: row.location_name || 'N/A',
      reference: row.incident_number || `INC-${row.incident_id ?? ''}`,
      qty,
      unitPrice: 0,
      // An incident records loss, not a purchase — there is no money on the
      // row. Left at 0 so it never inflates the valuation panels.
      total: 0,
      status: normalizeStatus(row.status_name ?? row.status),
      occurredAt: row.occurred_at || row.created_at,
      lineItems: row.product_name
        ? [{ key: 'incident', product: row.product_name, quantity: qty, unitCost: 0, value: 0, serial: row.serial_number || null }]
        : [],
      // The incident list payload IS the full report — the controller maps every
      // field a detail view needs. Carried through so opening a row needs no
      // second request; see the detail effect below.
      raw: row,
    };
  };

  const mapWarrantyClaim = (row) => {
    const items = Array.isArray(row.items) ? row.items : [];
    // The index endpoint does not load claim items; a claim always covers the
    // one serial on the header, so fall back to that rather than showing 0.
    const qty = items.length || (row.serial_number ? 1 : 0);
    const partsCost = Number(row.parts_cost ?? 0);
    const cid = row.claim_id ?? row.id;
    return {
      id: `WC-${cid ?? Math.random().toString(36).slice(2, 8)}`,
      entityId: cid ?? null,
      type: 'Warranty',
      itemName: row.product?.product_name || row.item_name || 'Warranty claim',
      location: row.location?.location_name || row.location_name || 'N/A',
      reference: row.claim_number || (cid != null ? `WC-${cid}` : 'Warranty claim'),
      qty,
      unitPrice: qty ? partsCost / qty : partsCost,
      total: partsCost,
      status: normalizeStatus(row.status?.status_name ?? row.status),
      occurredAt: row.completed_at || row.created_at,
      lineItems: items.length
        ? items.map((item, index) => ({
            key: item.claim_item_id ?? index,
            product: item.product?.product_name || `Claim item ${index + 1}`,
            quantity: Number(item.quantity ?? 1),
            unitCost: Number(item.unit_cost ?? 0),
            value: Number(item.unit_cost ?? 0) * Number(item.quantity ?? 1),
            serial: item.serial_number || null,
          }))
        : [{ key: 'claim', product: row.product?.product_name || 'Warranty claim', quantity: qty, unitCost: partsCost, value: partsCost, serial: row.serial_number || null }],
    };
  };

  const fetchAllTransactions = useCallback(async () => {
      setLoading(true);
      setError(null);
      setSourceWarnings([]);
      try {
        const loadEveryPage = async (api, params = {}) => {
          const first = await api.getAll({ ...params, page: 1, per_page: 100 });
          const rows = Array.isArray(first?.data) ? [...first.data] : [];
          const lastPage = Number(first?.pagination?.last_page || 1);
          for (let page = 2; page <= lastPage; page += 1) {
            const response = await api.getAll({ ...params, page, per_page: 100 });
            if (Array.isArray(response?.data)) rows.push(...response.data);
          }
          return rows;
        };
        const [receivingsRes, issuancesRes, transfersRes, adjustmentsRes, incidentsRes, warrantyRes] = await Promise.allSettled([
          loadEveryPage(receivingsAPI),
          loadEveryPage(issuancesAPI),
          loadEveryPage(transfersAPI),
          loadEveryPage(adjustmentsAPI),
          // Not loadEveryPage: the incident endpoint returns a flat capped list
          // with no `pagination` block, so there is no second page to ask for.
          incidentReportsAPI.list().then((res) => (Array.isArray(res?.data) ? res.data : [])),
          loadEveryPage(warrantyClaimsAPI),
        ]);

        const results = [receivingsRes, issuancesRes, transfersRes, adjustmentsRes, incidentsRes, warrantyRes];
        const names = ['Receivings', 'Issuances', 'Transfers', 'Adjustments', 'Incident reports', 'Warranty claims'];
        const warnings = results.flatMap((result, index) => result.status === 'rejected' ? [`${names[index]} could not be loaded: ${result.reason?.message || 'request failed'}`] : []);
        setSourceWarnings(warnings);

        const receivings = receivingsRes.status === 'fulfilled'
          ? receivingsRes.value.map(mapReceiving)
          : [];
        const issuances = issuancesRes.status === 'fulfilled'
          ? issuancesRes.value.map(mapIssuance)
          : [];
        const transfers = transfersRes.status === 'fulfilled'
          ? transfersRes.value.map(mapTransfer)
          : [];
        const adjustments = adjustmentsRes.status === 'fulfilled'
          ? adjustmentsRes.value.map(mapAdjustment)
          : [];
        const incidentRows = incidentsRes.status === 'fulfilled' ? incidentsRes.value : [];
        const warrantyClaims = warrantyRes.status === 'fulfilled'
          ? warrantyRes.value.map(mapWarrantyClaim)
          : [];

        // The overview counters read the raw incident rows (status names, dates),
        // so they are shared from this one fetch rather than requested twice.
        setIncidents(incidentRows);

        const allTransactions = [
          ...receivings, ...issuances, ...transfers, ...adjustments,
          ...incidentRows.map(mapIncident), ...warrantyClaims,
        ].sort((a, b) => {
          const aTs = new Date(a.occurredAt || 0).getTime();
          const bTs = new Date(b.occurredAt || 0).getTime();
          return bTs - aTs;
        });

        setTransactions(allTransactions);
        setLastUpdated(new Date());
        const uniqueLocations = [...new Set(allTransactions.flatMap((txn) => txn.type === 'Transfer' ? [txn.fromLocation, txn.toLocation] : [txn.location]).filter(Boolean))];
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
    // Incidents are no longer requested here — fetchAllTransactions needs them
    // for the ledger and shares the same rows with the overview counters.
    inventoryAPI.getAll({ per_page: 1000 })
      .then((res) => setInventoryRows(Array.isArray(res?.data) ? res.data : []))
      .catch(() => {})
      .finally(() => setLogisticsLoading(false));
    companySettingsAPI.get()
      .then((settings) => setPdfPaperSize(settings?.pdf_paper_size || 'a4'))
      .catch(() => {});
  }, [fetchAllTransactions]);

  useEffect(() => {
    if (!activeTransaction || activeTransaction.type === 'Issuance') return undefined;

    // An incident carries its own full payload from the list endpoint, so it
    // needs no lookup at all. Without this it fell into the `adjustmentsAPI`
    // default below and an incident id was fetched as an adjustment id.
    if (activeTransaction.type === 'Incident') {
      setMovementError('');
      setMovementLoading(false);
      setMovementDetail(activeTransaction.raw ?? activeTransaction);
      return undefined;
    }

    let stale = false;
    const apiByType = {
      Receiving: receivingsAPI,
      Transfer: transfersAPI,
      Adjustment: adjustmentsAPI,
      Warranty: warrantyClaimsAPI,
    };
    const api = apiByType[activeTransaction.type];
    if (!api) return undefined;
    setMovementLoading(true);
    setMovementError('');
    api.getById(activeTransaction.entityId)
      .then((res) => { if (!stale) setMovementDetail(res?.data || res); })
      .catch((err) => { if (!stale) setMovementError(err?.message || 'Failed to load transaction details.'); })
      .finally(() => { if (!stale) setMovementLoading(false); });
    return () => { stale = true; };
  }, [activeTransaction]);

  const transactionDocumentPath = (txn, preview = false) => {
    if (!txn?.entityId) return null;
    const params = new URLSearchParams();
    params.set('paper_size', pdfPaperSize);
    if (preview) params.set('preview', '1');
    // A lookup, not a trailing else. The else used to be the adjustment PDF, so
    // any type without its own branch printed SOMEBODY ELSE'S document at the
    // same id — a warranty claim 21 fetched adjustment 21 and rendered it.
    // An unlisted type has no document; say so by returning null.
    const pathByType = {
      Issuance:   `/issuance-scan/delivery-receipt/${txn.entityId}/pdf`,
      Receiving:  `/receivings/${txn.entityId}/receipt/pdf`,
      Transfer:   `/transfers/${txn.entityId}/receipt/pdf`,
      Adjustment: `/adjustments/${txn.entityId}/pdf`,
      Warranty:   `/warranty-claims/${txn.entityId}/receipt/pdf`,
      // Incident: there is no incident PDF endpoint.
    };
    const path = pathByType[txn.type];
    if (!path) return null;
    return `${path}?${params.toString()}`;
  };

  const openTransactionDocument = (txn, download = false) => {
    const path = transactionDocumentPath(txn, !download);
    if (!path) return;
    openApiFile(path, {
      preview: !download,
      download,
      filename: `${txn.type || 'transaction'}-${txn.entityId}.pdf`,
    }).catch((error) => toast.error(error?.message || 'Failed to load PDF.'));
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
    if (status === 'Completed' || status === 'Posted') return 'txn-status-completed';
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
    if (type === 'Incident') return 'txn-type-incident';
    if (type === 'Warranty') return 'txn-type-warranty';
    return '';
  };

  const dateRange = useMemo(() => {
    const now = new Date();
    const startOfDay = (date) => new Date(date.getFullYear(), date.getMonth(), date.getDate());
    const endOfDay = (date) => new Date(date.getFullYear(), date.getMonth(), date.getDate(), 23, 59, 59, 999);
    if (datePeriod === 'all') return { from: null, to: null, label: 'All time' };
    if (datePeriod === 'custom') {
      const from = customFrom ? startOfDay(new Date(`${customFrom}T00:00:00`)) : null;
      const to = customTo ? endOfDay(new Date(`${customTo}T00:00:00`)) : null;
      return { from, to, label: from || to ? `${customFrom || 'Beginning'} – ${customTo || 'Today'}` : 'Custom range' };
    }
    let from = startOfDay(now);
    let to = endOfDay(now);
    if (datePeriod === 'yesterday') {
      from.setDate(from.getDate() - 1);
      to = endOfDay(from);
    } else if (datePeriod === 'week') {
      const mondayOffset = (from.getDay() + 6) % 7;
      from.setDate(from.getDate() - mondayOffset);
    } else if (datePeriod === 'month') {
      from = new Date(now.getFullYear(), now.getMonth(), 1);
    } else if (datePeriod === 'quarter') {
      from = new Date(now.getFullYear(), Math.floor(now.getMonth() / 3) * 3, 1);
    } else if (datePeriod === 'year') {
      from = new Date(now.getFullYear(), 0, 1);
    }
    const labels = { today: 'Today', yesterday: 'Yesterday', week: 'This week', month: 'This month', quarter: 'This quarter', year: 'This year' };
    return { from, to, label: labels[datePeriod] || 'Selected period' };
  }, [datePeriod, customFrom, customTo]);

  const isInDateRange = useCallback((value) => {
    if (!dateRange.from && !dateRange.to) return true;
    if (!value) return false;
    const time = new Date(value).getTime();
    if (Number.isNaN(time)) return false;
    return (!dateRange.from || time >= dateRange.from.getTime()) && (!dateRange.to || time <= dateRange.to.getTime());
  }, [dateRange]);

  const periodTransactions = useMemo(
    () => transactions.filter((txn) => isInDateRange(txn.occurredAt)),
    [transactions, isInDateRange],
  );

  const filteredTransactions = useMemo(() => {
    const q = searchTerm.trim().toLowerCase();
    return periodTransactions.filter((txn) => {
      if (typeFilter && txn.type !== typeFilter) return false;
      if (statusFilter && txn.status !== statusFilter) return false;
      if (locationFilter && txn.location !== locationFilter && txn.fromLocation !== locationFilter && txn.toLocation !== locationFilter) return false;
      if (!q) return true;
      const serials = (txn.lineItems || []).map((line) => line.serial).filter(Boolean).join(' ');
      const products = (txn.lineItems || []).map((line) => line.product).filter(Boolean).join(' ');
      const target = `${txn.itemName} ${products} ${serials} ${txn.reference} ${txn.location} ${txn.id}`.toLowerCase();
      return target.includes(q);
    });
  }, [periodTransactions, searchTerm, typeFilter, statusFilter, locationFilter]);

  const totalTransactions = filteredTransactions.length;
  const totalPages        = Math.ceil(totalTransactions / PAGE_SIZE) || 1;
  const pagedTransactions = filteredTransactions.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);

  // reset page when filters change
  useEffect(() => { setCurrentPage(1); }, [searchTerm, typeFilter, statusFilter, locationFilter]);

  const overview = useMemo(() => {
    const active = periodTransactions.filter((txn) => txn.status !== 'Cancelled' && txn.status !== 'Draft');
    const rowsForType = (type) => active.filter((txn) => txn.type === type);
    const sumType = (type) => rowsForType(type).reduce((sum, txn) => {
      const lines = Array.isArray(txn.lineItems) ? txn.lineItems : [];
      return sum + (lines.length ? lines.reduce((lineSum, line) => lineSum + Number(line.value || 0), 0) : Number(txn.total || 0));
    }, 0);
    const quantityType = (type) => rowsForType(type).reduce((sum, txn) => {
      const lines = Array.isArray(txn.lineItems) ? txn.lineItems : [];
      return sum + (lines.length ? lines.reduce((lineSum, line) => lineSum + Number(line.quantity || 0), 0) : Number(txn.qty || 0));
    }, 0);
    const stockUnits = inventoryRows.reduce((sum, row) => sum + Number(row.quantity_on_hand ?? row.quantity ?? 0), 0);
    const stockValue = inventoryRows.reduce((sum, row) => {
      const qty = Number(row.quantity_on_hand ?? row.quantity ?? 0);
      const cost = Number(row.average_cost ?? row.unit_cost ?? row.product?.cost_price ?? 0);
      return sum + (qty * cost);
    }, 0);
    const periodIncidents = incidents.filter((incident) => isInDateRange(incident.created_at || incident.reported_at));
    const unresolved = periodIncidents.filter((incident) => {
      const status = String(incident.status?.status_name ?? incident.status_name ?? incident.status ?? '').toLowerCase();
      return !['resolved', 'closed', 'dismissed', 'cancelled'].some((name) => status.includes(name));
    });
    return {
      total: periodTransactions.length,
      completed: periodTransactions.filter((txn) => txn.status === 'Completed').length,
      pending: periodTransactions.filter((txn) => ['Pending', 'Draft'].includes(txn.status)).length,
      movementUnits: active.reduce((sum, txn) => sum + Math.abs(Number(txn.qty || 0)), 0),
      stockUnits,
      stockValue,
      issuedUnits: quantityType('Issuance'),
      issuedValue: sumType('Issuance'),
      transferredUnits: quantityType('Transfer'),
      transferredValue: sumType('Transfer'),
      receivedUnits: quantityType('Receiving'),
      openIncidents: unresolved.length,
      incidentUnits: unresolved.reduce((sum, incident) => sum + Number(incident.quantity || 0), 0),
    };
  }, [periodTransactions, inventoryRows, incidents, isInDateRange]);

  const computation = useMemo(() => {
    if (!computationView) return null;
    if (computationView === 'overall-actual') {
      const rows = periodTransactions
        .filter((txn) => !['Cancelled', 'Draft'].includes(txn.status))
        .flatMap((txn) => {
          const lines = txn.lineItems?.length ? txn.lineItems : [{ key: 'header', product: txn.itemName, quantity: txn.qty, unitCost: txn.unitPrice, value: txn.total }];
          return lines.map((line, index) => ({
            key: `${txn.id}-${line.key ?? index}`,
            label: line.product,
            detail: `${txn.type} | ${txn.reference} | ${formatDateTime(txn.occurredAt)} | ${txn.location} | ${txn.status}${line.serial ? ` | Serial: ${line.serial}` : ''}`,
            type: txn.type,
            reference: txn.reference,
            occurredAt: txn.occurredAt,
            location: txn.location,
            status: txn.status,
            serial: line.serial || null,
            quantity: Number(line.quantity || 0),
            unitCost: Number(line.unitCost || 0),
            value: Number(line.value || 0),
          }));
        });
      return {
        title: `Overall transaction computation | ${dateRange.label}`,
        formula: 'Actual line quantity x recorded unit price/cost = line value; sum of all line values = total transaction activity',
        rows,
        total: rows.reduce((sum, row) => sum + row.value, 0),
        overall: true,
      };
    }
    if (computationView === 'overall') {
      return {
        title: `Overall computation · ${dateRange.label}`,
        formula: 'Current stock value + Issued-out value + Transfer activity value = Overall tracked value',
        rows: [
          { key: 'stock', label: 'Current inventory', detail: 'Live stock snapshot · quantity on hand × cost price', quantity: overview.stockUnits, unitCost: null, value: overview.stockValue },
          { key: 'issued', label: 'Issued / sales out', detail: `${dateRange.label} · excludes cancelled and draft records`, quantity: overview.issuedUnits, unitCost: null, value: overview.issuedValue },
          { key: 'transfer', label: 'Interbranch transfers', detail: `${dateRange.label} · movement value only; stock remains company-owned`, quantity: overview.transferredUnits, unitCost: null, value: overview.transferredValue },
          { key: 'received', label: 'Received stock', detail: `${dateRange.label} · shown for operational unit reconciliation`, quantity: overview.receivedUnits, unitCost: null, value: null },
          { key: 'incidents', label: 'Open incident reports', detail: `${overview.openIncidents.toLocaleString()} reports filed in ${dateRange.label.toLowerCase()}`, quantity: overview.incidentUnits, unitCost: null, value: null },
        ],
        total: overview.stockValue + overview.issuedValue + overview.transferredValue,
        overall: true,
      };
    }
    if (computationView === 'stock') {
      return {
        title: 'Current stock valuation',
        formula: 'Quantity on hand × Product cost price = Stock value',
        rows: inventoryRows.map((row, index) => {
          const quantity = Number(row.quantity_on_hand ?? row.quantity ?? 0);
          const unitCost = Number(row.average_cost ?? row.unit_cost ?? row.product?.cost_price ?? 0);
          return { key: row.inventory_id ?? index, label: row.product?.product_name || `Inventory #${row.inventory_id ?? index + 1}`, detail: row.location?.location_name || 'Location not specified', quantity, unitCost, value: quantity * unitCost };
        }),
        total: overview.stockValue,
      };
    }
    const type = computationView === 'issued' ? 'Issuance' : 'Transfer';
    const rows = periodTransactions.filter((txn) => txn.type === type && !['Cancelled', 'Draft'].includes(txn.status));
    return {
      title: computationView === 'issued' ? 'Issued-out valuation' : 'Transfer valuation',
      formula: 'Transaction quantity × Recorded unit cost = Movement value',
      rows: rows.map((txn) => ({ key: txn.id, label: txn.itemName, detail: `${txn.reference} · ${txn.location}`, quantity: Number(txn.qty || 0), unitCost: Number(txn.unitPrice || 0), value: Number(txn.total || 0) })),
      total: computationView === 'issued' ? overview.issuedValue : overview.transferredValue,
    };
  }, [computationView, inventoryRows, periodTransactions, overview, dateRange.label]);

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
        {sourceWarnings.length > 0 && <div className="txn-source-warnings" role="alert"><strong>Ledger is incomplete.</strong>{sourceWarnings.map((warning) => <span key={warning}>{warning}</span>)}</div>}

        <section className="txn-summary-grid" aria-label="Transaction summary">
          <div className="txn-summary-card txn-summary-card--red"><span className="txn-summary-icon">#</span><div><span>All transactions</span><strong>{overview.total.toLocaleString()}</strong><small>{overview.movementUnits.toLocaleString()} units moved</small></div></div>
          <div className="txn-summary-card txn-summary-card--green"><span className="txn-summary-icon">✓</span><div><span>Completed</span><strong>{overview.completed.toLocaleString()}</strong><small>Posted movements</small></div></div>
          <div className="txn-summary-card txn-summary-card--amber"><span className="txn-summary-icon">◷</span><div><span>Needs attention</span><strong>{overview.pending.toLocaleString()}</strong><small>Pending or draft</small></div></div>
          <div className="txn-summary-card txn-summary-card--blue"><span className="txn-summary-icon">↗</span><div><span>Issued out</span><strong>{overview.issuedUnits.toLocaleString()}</strong><small>{fmtMoney(overview.issuedValue)} value</small></div></div>
        </section>

        <div className="txn-tabs" role="tablist" aria-label="Transaction views">
          <button className={activeTab === 'ledger' ? 'is-active' : ''} onClick={() => setActiveTab('ledger')} role="tab" aria-selected={activeTab === 'ledger'}>Transaction ledger</button>
          <button className={activeTab === 'logistics' ? 'is-active' : ''} onClick={() => setActiveTab('logistics')} role="tab" aria-selected={activeTab === 'logistics'}>Sales &amp; logistics</button>
        </div>

        <section className="txn-period-bar" aria-label="Transaction date filter">
          <div><span className="txn-period-label">PERIOD</span><strong>{dateRange.label}</strong></div>
          <div className="txn-period-controls">
            <select className="ak-select" value={datePeriod} onChange={(e) => setDatePeriod(e.target.value)}>
              <option value="today">Today</option>
              <option value="yesterday">Last day</option>
              <option value="week">Weekly</option>
              <option value="month">Monthly</option>
              <option value="quarter">Quarterly</option>
              <option value="year">Yearly</option>
              <option value="custom">Custom range</option>
              <option value="all">All time</option>
            </select>
            {datePeriod === 'custom' && <>
              <label>From<input className="ak-input" type="date" value={customFrom} max={customTo || undefined} onChange={(e) => setCustomFrom(e.target.value)} /></label>
              <label>To<input className="ak-input" type="date" value={customTo} min={customFrom || undefined} onChange={(e) => setCustomTo(e.target.value)} /></label>
            </>}
          </div>
        </section>

        {activeTab === 'ledger' && <div className="ak-card txn-table-card">
          <div className="ak-card-head">
            <div>
              <h2 className="ak-card-title">All Transactions</h2>
              <p className="ak-card-meta">{totalTransactions} transactions · {dateRange.label}</p>
            </div>
            <div className="txn-refresh"><span>{lastUpdated ? `Updated ${lastUpdated.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}` : 'Not yet updated'}</span><button className="ak-btn ak-btn--ghost" onClick={fetchAllTransactions} disabled={loading}>Refresh</button></div>
          </div>

          <div className="ak-toolbar ak-toolbar--push">
            <div className="ak-search">
              <input
                className="ak-input"
                type="text"
                placeholder="Search item, reference, location, serial..."
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
              <option value="Incident">Incident</option>
              <option value="Warranty">Warranty</option>
            </select>
            <select className="ak-select" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
              <option value="">All Status</option>
              <option value="Completed">Completed</option>
              <option value="Posted">Posted</option>
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
        </div>}

        {activeTab === 'logistics' && (
          <section className="txn-logistics" role="tabpanel">
            {logisticsLoading && <Loader label="Calculating logistics valuation" />}
            <div className="txn-logistics-grid">
              <article className="txn-metric txn-metric--hero"><span className="txn-metric-kicker">CURRENT INVENTORY · LIVE SNAPSHOT</span><h3>{fmtMoney(overview.stockValue)}</h3><p>Estimated cost value of stock currently on hand</p><div className="txn-metric-foot"><span><strong>{overview.stockUnits.toLocaleString()}</strong> units across <strong>{inventoryRows.length.toLocaleString()}</strong> stock records</span><button onClick={() => setComputationView('stock')}>View computation</button></div></article>
              <article className="txn-metric"><span className="txn-metric-kicker">ISSUED / SALES OUT</span><h3>{fmtMoney(overview.issuedValue)}</h3><p>{overview.issuedUnits.toLocaleString()} units issued from inventory</p><div className="txn-progress"><i style={{ width: `${Math.min(100, overview.movementUnits ? (overview.issuedUnits / overview.movementUnits) * 100 : 0)}%` }} /></div><button className="txn-compute-link" onClick={() => setComputationView('issued')}>View computation →</button></article>
              <article className="txn-metric"><span className="txn-metric-kicker">INTERBRANCH TRANSFERS</span><h3>{fmtMoney(overview.transferredValue)}</h3><p>{overview.transferredUnits.toLocaleString()} units transferred between locations</p><div className="txn-progress txn-progress--blue"><i style={{ width: `${Math.min(100, overview.movementUnits ? (overview.transferredUnits / overview.movementUnits) * 100 : 0)}%` }} /></div><button className="txn-compute-link" onClick={() => setComputationView('transfer')}>View computation →</button></article>
              <article className="txn-metric txn-metric--incident"><span className="txn-metric-kicker">INCIDENT REPORTS</span><h3>{overview.openIncidents.toLocaleString()} open</h3><p>{overview.incidentUnits.toLocaleString()} units currently involved</p><button onClick={() => navigate('/admin/incidents')}>Review incidents <span>→</span></button></article>
            </div>
            <div className="txn-flow-card">
              <div><h3>Stock flow snapshot</h3><p>A simple operational view based on the loaded transaction ledger.</p><button className="txn-overall-button" onClick={() => setComputationView('overall-actual')}>View actual transaction computation →</button></div>
              <div className="txn-flow-steps">
                <div><span>Received</span><strong>{overview.receivedUnits.toLocaleString()}</strong><small>units in</small></div><b>→</b>
                <div><span>Current stock</span><strong>{overview.stockUnits.toLocaleString()}</strong><small>units on hand</small></div><b>→</b>
                <div><span>Issued</span><strong>{overview.issuedUnits.toLocaleString()}</strong><small>units out</small></div><b>+</b>
                <div><span>Transferred</span><strong>{overview.transferredUnits.toLocaleString()}</strong><small>units moved</small></div>
              </div>
            </div>
            <p className="txn-valuation-note">Valuations use the product cost price available on each record. Transfers show the value moved and do not reduce company-wide stock value.</p>
          </section>
        )}
      </div>

      <Modal isOpen={computationView != null} onClose={() => setComputationView(null)} title={computation?.title || 'Valuation computation'} maxWidth={860}>
        {computation && <div className="txn-computation">
          <div className="txn-formula"><span>FORMULA</span><strong>{computation.formula}</strong>{computation.overall && <small>{computation.rows.length.toLocaleString()} actual line item{computation.rows.length === 1 ? '' : 's'} included</small>}</div>
          <div className="txn-computation-table-wrap"><table className="txn-computation-table">
            {computation.overall ? <thead><tr><th>Type</th><th>Reference / Date</th><th>Actual product</th><th>Location / Status</th><th className="num">Qty</th><th className="num">Unit cost</th><th className="num">Line value</th></tr></thead> : <thead><tr><th>Record</th><th className="num">Quantity</th><th className="num">Unit cost</th><th className="num">Computed value</th></tr></thead>}
            <tbody>
              {computation.rows.length === 0 && <tr><td colSpan={computation.overall ? 7 : 4} className="txn-computation-empty">No qualifying records found.</td></tr>}
              {computation.rows.map((row) => computation.overall ? <tr key={row.key}><td><span className={`txn-type-badge ${getTypeClass(row.type)}`}>{row.type}</span></td><td><strong>{row.reference}</strong><small>{formatDateTime(row.occurredAt)}</small></td><td><strong>{row.label}</strong>{row.serial && <small>Serial / barcode: {row.serial}</small>}</td><td>{row.location}<small><span className={`txn-status-badge ${getStatusClass(row.status)}`}>{row.status}</span></small></td><td className="num">{row.quantity.toLocaleString()}</td><td className="num">{fmtMoney(row.unitCost)}</td><td className="num"><strong>{fmtMoney(row.value)}</strong></td></tr> : <tr key={row.key}><td><strong>{row.label}</strong><small>{row.detail}</small></td><td className="num">{row.quantity.toLocaleString()}</td><td className="num">{row.unitCost == null ? '—' : fmtMoney(row.unitCost)}</td><td className="num"><strong>{row.value == null ? 'Not valued' : fmtMoney(row.value)}</strong></td></tr>)}
            </tbody>
            <tfoot><tr><td colSpan={computation.overall ? 6 : 3}>{computation.overall ? 'TOTAL TRANSACTION ACTIVITY' : 'TOTAL VALUATION'}</td><td className="num">{fmtMoney(computation.total)}</td></tr></tfoot>
          </table></div>
          <p className="txn-computation-note">{computation.overall ? 'Overall tracked value is a visibility total, not accounting profit: transfer value measures internal activity and does not reduce company-wide inventory. Received and incident units are shown separately because mixing unpriced units into a peso total would be inaccurate.' : 'Cancelled and draft movements are excluded. A zero unit cost means no cost price was recorded for that entry.'}</p>
        </div>}
      </Modal>

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

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.6rem', borderTop: '1px solid var(--border-color)', paddingTop: '0.9rem' }}>
              {!detail.isDraft && (
                <>
                  <select className="ak-select" value={pdfPaperSize} onChange={(e) => setPdfPaperSize(e.target.value)}>
                    <option value="a4">A4</option><option value="letter">Letter</option>
                    <option value="legal">Legal</option><option value="a5">A5</option>
                  </select>
                  <button type="button" className="ak-btn" onClick={() => openTransactionDocument({ type: 'Issuance', entityId: activeIssuanceId })}>
                    View Document
                  </button>
                  <button type="button" className="ak-btn ak-btn--primary" onClick={() => openTransactionDocument({ type: 'Issuance', entityId: activeIssuanceId }, true)}>
                    Download PDF
                  </button>
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
          // A claim's lines come back as `items`, and an incident is a single
          // event with no line array at all. Both used to fall through to the
          // adjustment branch below, which reads `details` — so both rendered an
          // empty table under Added / Deducted / Net change.
          const lineSource = txn.type === 'Warranty'
            ? (Array.isArray(d.items) ? d.items : [])
            : txn.type === 'Incident'
              ? [d]
              : (Array.isArray(d.details) ? d.details : []);

          const rows = lineSource.map((line, index) => {
            if (txn.type === 'Warranty') {
              return {
                key: line.detail_id || index,
                product: line.product_name || `Product #${line.product_id}`,
                code: line.model_code || line.product_code || '—',
                values: [
                  Number(line.quantity_requested || 0),
                  Number(line.quantity_issued || 0),
                  line.serial_number || '—',
                ],
              };
            }
            if (txn.type === 'Incident') {
              return {
                key: line.incident_id || index,
                product: line.product_name || line.reason || 'Incident',
                code: line.product_code || '—',
                values: [
                  Number(line.quantity || 0),
                  line.reason || '—',
                  line.narrative || line.suspected_cause || '—',
                ],
              };
            }
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
          const headersByType = {
            Receiving: ['Received (pcs)', 'Serial / Scanned ID', 'Recorded price'],
            Transfer:  ['Requested (pcs)', 'Dispatched (pcs)', 'Received (pcs)'],
            Warranty:  ['Requested (pcs)', 'Issued (pcs)', 'Serial'],
            Incident:  ['Quantity (pcs)', 'Reason', 'Details'],
          };
          const headers = headersByType[txn.type] || ['Added', 'Deducted', 'Net change', 'Reason'];
          const createdBy = userFullName(d.created_by || d.createdBy) || d.adjusted_by || '—';
          const approvedBy = userFullName(d.approved_by || d.approvedBy) || '—';
          const receivedBy = userFullName(d.received_by || d.receivedBy) || '—';
          const statusName = d.status?.status_name || txn.status;

          return (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 16, paddingTop: 10 }}>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(165px,1fr))', gap: 12 }}>
                <div><div className="ak-label">Reference</div><strong>{txn.reference}</strong></div>
                <div><div className="ak-label">Status</div><span className={`txn-status-badge ${getStatusClass(normalizeStatus(statusName))}`}>{statusName || '—'}</span></div>
                <div><div className="ak-label">Transaction date</div>{formatDateTime(d.receiving_date || d.transfer_date || d.adjustment_date || d.occurred_at || d.completed_at || d.created_at)}</div>
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

              <div style={{ display: 'flex', justifyContent: 'flex-end', alignItems: 'center', flexWrap: 'wrap', gap: 8, borderTop: '1px solid var(--border-color)', paddingTop: 12 }}>
                {transactionDocumentPath(txn) && <>
                  <select className="ak-select" value={pdfPaperSize} onChange={(e) => setPdfPaperSize(e.target.value)}>
                    <option value="a4">A4</option><option value="letter">Letter</option>
                    <option value="legal">Legal</option><option value="a5">A5</option>
                  </select>
                  <button type="button" className="ak-btn" onClick={() => openTransactionDocument(txn)}>View Document</button>
                  <button type="button" className="ak-btn ak-btn--primary" onClick={() => openTransactionDocument(txn, true)}>Download PDF</button>
                </>}
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
