import React, { useState, useEffect, useCallback, useMemo } from 'react';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import Modal from '../components/Modal';
import { transfersAPI, locationsAPI } from '../services/api';
import { useAuth } from '../context/AuthContext';
import { getRoleKey, ROLES } from '../utils/roles';
import { toast } from '../utils/toast';
import NewRequestModal from './transfers/NewRequestModal';
import VerifyPanel from './transfers/VerifyPanel';
import DispatchPanel from './transfers/DispatchPanel';
import ReceivePanel from './transfers/ReceivePanel';
import '../styles/airking_ds.css';
import '../styles/po_recommendation.css';
import '../styles/po_receiving.css';
import '../styles/transfers.css';

// Status → tab bucket + presentation.
const STATUS_META = {
  Pending:      { tab: 'verify',  badge: 'ak-badge--amber',  action: 'Verify' },
  Approved:     { tab: 'send',    badge: 'ak-badge--blue',   action: 'Dispatch' },
  'In Transit': { tab: 'receive', badge: 'ak-badge--violet', action: 'Receive' },
  Received:     { tab: 'done',    badge: 'ak-badge--green',  action: 'View' },
  Rejected:     { tab: 'done',    badge: 'ak-badge--red',    action: 'View' },
};

const INTENT_CLASS = { pull: 'tf-intent--pull', offload: 'tf-intent--offload', demand: 'tf-intent--demand' };
const INTENT_LABEL = { pull: 'Pull', offload: 'Offload', demand: 'IA-demand' };

const statusName = (t) => t?.status?.status_name || t?.status_name || 'Pending';
const fmtDate = (v) => {
  if (!v) return '—';
  const d = new Date(v);
  return Number.isNaN(d.getTime()) ? String(v).slice(0, 10) : d.toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
};

const TransfersPage = () => {
  const { user, can } = useAuth();
  const roleKey = getRoleKey(user);
  const isAdmin = roleKey === ROLES.ADMIN;
  const isIA = roleKey === ROLES.INVENTORY_ANALYST;
  // Verifiers: admin / inventory_analyst, or anyone granted the approve permission.
  const canVerify = isAdmin || isIA || (typeof can === 'function' && can('inventory_transfers', 'approve'));

  const TABS = useMemo(() => ([
    ...(canVerify ? [{ key: 'verify', label: 'To Verify' }] : []),
    { key: 'send', label: 'To Send' },
    { key: 'receive', label: 'To Receive' },
    { key: 'done', label: 'Done' },
  ]), [canVerify]);

  const [activeTab, setActiveTab] = useState(canVerify ? 'verify' : 'send');
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [locations, setLocations] = useState([]);
  const [showNew, setShowNew] = useState(false);

  // Detail drawer
  const [selected, setSelected] = useState(null);   // full transfer from getById
  const [drawerLoading, setDrawerLoading] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    transfersAPI.getAll({ per_page: 100 })
      .then((res) => setRows(Array.isArray(res?.data) ? res.data : []))
      .catch((e) => toast.error('Could not load transfers', e?.message))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    locationsAPI.getAll({ per_page: 500 })
      .then((res) => setLocations(Array.isArray(res?.data) ? res.data : []))
      .catch(() => setLocations([]));
  }, []);

  const grouped = useMemo(() => {
    const g = { verify: [], send: [], receive: [], done: [] };
    rows.forEach((t) => {
      const bucket = STATUS_META[statusName(t)]?.tab || 'done';
      g[bucket].push(t);
    });
    return g;
  }, [rows]);

  const openDrawer = (t) => {
    setSelected({ transfer_id: t.transfer_id, __loading: true });
    setDrawerLoading(true);
    transfersAPI.getById(t.transfer_id)
      .then((res) => setSelected(res?.data ?? res))
      .catch((e) => { toast.error('Could not open transfer', e?.message); setSelected(null); })
      .finally(() => setDrawerLoading(false));
  };

  const closeDrawer = () => setSelected(null);
  const afterAction = () => { closeDrawer(); load(); };

  const visibleRows = grouped[activeTab] || [];

  const renderRow = (t) => {
    const sName = statusName(t);
    const meta = STATUS_META[sName] || STATUS_META.Received;
    const from = t.fromLocation?.location_name || t.from_location?.location_name;
    const to = t.toLocation?.location_name || t.to_location?.location_name || '—';
    return (
      <tr key={t.transfer_id} className="tf-row-clickable" onClick={() => openDrawer(t)}>
        <td className="ak-mono">{t.transfer_number}</td>
        <td>
          <span className="tf-route">
            <span className={`tf-route-node${from ? '' : ' tf-route-node--muted'}`}>{from || 'Unassigned'}</span>
            <span className="tf-route-arrow">→</span>
            <span className="tf-route-node">{to}</span>
          </span>
        </td>
        <td><span className={`tf-intent ${INTENT_CLASS[t.intent] || 'tf-intent--pull'}`}>{INTENT_LABEL[t.intent] || t.intent}</span></td>
        <td className="ak-muted">{fmtDate(t.transfer_date)}</td>
        <td><span className={`ak-badge ${meta.badge}`}>{sName}</span></td>
        <td style={{ textAlign: 'right' }}>
          <button type="button" className="ak-btn ak-btn--sm" onClick={(e) => { e.stopPropagation(); openDrawer(t); }}>
            {meta.action}
          </button>
        </td>
      </tr>
    );
  };

  // Which panel to render inside the drawer for the selected transfer.
  const renderDrawerBody = () => {
    if (drawerLoading || selected?.__loading) return <Loader label="Loading transfer" />;
    if (!selected) return null;
    const sName = statusName(selected);
    const bucket = STATUS_META[sName]?.tab || 'done';
    const from = selected.fromLocation?.location_name || selected.from_location?.location_name;
    const to = selected.toLocation?.location_name || selected.to_location?.location_name || '—';

    return (
      <div className="ak-page" style={{ padding: 0 }}>
        <div className="tf-drawer-meta">
          <div className="tf-meta-item"><span className="tf-meta-label">Number</span><span className="tf-meta-value">{selected.transfer_number}</span></div>
          <div className="tf-meta-item"><span className="tf-meta-label">Intent</span><span className="tf-meta-value">{INTENT_LABEL[selected.intent] || selected.intent}</span></div>
          <div className="tf-meta-item"><span className="tf-meta-label">From</span><span className="tf-meta-value">{from || 'Unassigned'}</span></div>
          <div className="tf-meta-item"><span className="tf-meta-label">To</span><span className="tf-meta-value">{to}</span></div>
          <div className="tf-meta-item"><span className="tf-meta-label">Status</span><span className={`ak-badge ${STATUS_META[sName]?.badge || 'ak-badge--gray'}`}>{sName}</span></div>
        </div>

        {bucket === 'verify' && canVerify && (
          <VerifyPanel transfer={selected} locations={locations} onDone={afterAction} />
        )}
        {bucket === 'verify' && !canVerify && (
          <p className="ak-muted">This transfer is awaiting analyst verification.</p>
        )}
        {bucket === 'send' && <DispatchPanel transfer={selected} onDone={afterAction} />}
        {bucket === 'receive' && <ReceivePanel transfer={selected} onDone={afterAction} />}
        {bucket === 'done' && (
          <div>
            <h4 className="tf-panel-section-title">Lines</h4>
            {(selected.details || []).map((d) => (
              <div key={d.transfer_detail_id} className="tf-line">
                <div className="tf-line-name">
                  <b>{d.product?.product_name || `Product #${d.product_id}`}</b>
                  <small>requested {d.quantity_requested} · received {d.quantity_received ?? 0}</small>
                </div>
              </div>
            ))}
            {sName === 'Rejected' && selected.rejection_reason && (
              <div className="ak-state ak-state--error" style={{ marginTop: 12, textAlign: 'left' }}>
                <strong>Rejected:</strong> {selected.rejection_reason}
              </div>
            )}
          </div>
        )}
      </div>
    );
  };

  return (
    <AdminLayout>
      <div className="ak-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Inventory Intelligence</p>
              <h1 className="ak-title">Transfers</h1>
              <p className="ak-sub">Request, verify, dispatch, and receive stock movements across locations — one worklist for the whole flow.</p>
            </div>
            <div className="ak-header-aside">
              <button type="button" className="ak-btn ak-btn--primary" onClick={() => setShowNew(true)}>+ New Request</button>
            </div>
          </div>
        </header>

        <div className="ak-tabs">
          {TABS.map((t) => (
            <button
              key={t.key}
              type="button"
              className={`ak-tab${activeTab === t.key ? ' is-active' : ''}`}
              onClick={() => setActiveTab(t.key)}
            >
              {t.label}
              {grouped[t.key]?.length > 0 && <span className="ak-badge ak-badge--gray" style={{ marginLeft: 8 }}>{grouped[t.key].length}</span>}
            </button>
          ))}
        </div>

        {loading ? (
          <Loader label="Loading transfers" />
        ) : visibleRows.length === 0 ? (
          <div className="ak-state">No transfers in this stage.</div>
        ) : (
          <div className="ak-table-wrap">
            <table className="ak-table">
              <thead>
                <tr>
                  <th>Number</th>
                  <th>Route</th>
                  <th>Intent</th>
                  <th>Date</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>{visibleRows.map(renderRow)}</tbody>
            </table>
          </div>
        )}
      </div>

      {selected && (
        <Modal isOpen={!!selected} onClose={closeDrawer} title={`Transfer ${selected.transfer_number || ''}`} maxWidth={860}>
          {renderDrawerBody()}
        </Modal>
      )}

      <NewRequestModal isOpen={showNew} onClose={() => setShowNew(false)} onCreated={load} />
    </AdminLayout>
  );
};

export default TransfersPage;
