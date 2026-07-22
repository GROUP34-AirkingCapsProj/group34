import React, { useState, useEffect, useCallback, useMemo } from 'react';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import Modal from '../components/Modal';
import { transfersAPI, locationsAPI } from '../services/api';
import { useAuth } from '../context/AuthContext';
import { getRoleKey, ROLES } from '../utils/roles';
import { toast } from '../utils/toast';
import NewRequestModal from './transfers/NewRequestModal';
import { confirmAction } from '../utils/confirm';
import VerifyPanel from './transfers/VerifyPanel';
import DispatchPanel from './transfers/DispatchPanel';
import ReceivePanel from './transfers/ReceivePanel';
import { TransferSteps, TransferHistory } from './transfers/TransferTimeline';
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
    { key: 'mine', label: 'My Requests' },
    ...(canVerify ? [{ key: 'verify', label: 'To Verify' }] : []),
    { key: 'send', label: 'To Send' },
    { key: 'receive', label: 'To Receive' },
    { key: 'done', label: 'Done' },
  ]), [canVerify]);

  const myLoc = user?.enforced_location_id ?? user?.location_id ?? null;

  const [activeTab, setActiveTab] = useState(canVerify ? 'verify' : 'mine');
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [locations, setLocations] = useState([]);
  const [showNew, setShowNew] = useState(false);
  const [editing, setEditing] = useState(null); // transfer being edited (Pending, requester/IA)

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
    const g = { mine: [], verify: [], send: [], receive: [], done: [] };
    rows.forEach((t) => {
      const bucket = STATUS_META[statusName(t)]?.tab || 'done';
      // To Send is the dispatch worklist — only the assigned source location
      // (or admin/IA) belongs there; others will see it in To Receive once shipped.
      const isSourceUser = myLoc != null && String(t.from_location_id) === String(myLoc);
      const excludeFromSend = bucket === 'send' && !isAdmin && !isIA && !isSourceUser;
      if (!excludeFromSend) g[bucket].push(t);
      // NOTE: the eager-loaded `requestedBy` relation serializes to the key
      // `requested_by`, shadowing the scalar FK — so this may be a user object.
      const reqBy = t.requested_by;
      const reqById = reqBy && typeof reqBy === 'object' ? (reqBy.user_id ?? reqBy.id) : reqBy;
      const uid = user?.user_id ?? user?.id;
      if (uid != null && reqById != null && String(reqById) === String(uid)) g.mine.push(t);
    });
    return g;
  }, [rows, user, isAdmin, isIA, myLoc]);

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

  const handleCancel = async (t) => {
    const ok = await confirmAction({
      title: `Cancel ${t.transfer_number || 'this request'}?`,
      text: 'This withdraws the request and cannot be undone.',
      confirmText: 'Cancel request',
      tone: 'danger',
    });
    if (!ok) return;
    try {
      await transfersAPI.cancelRequest(t.transfer_id);
      toast.success('Request cancelled');
      afterAction();
    } catch (err) {
      toast.error("Couldn't cancel request", err?.message || '');
    }
  };

  const visibleRows = grouped[activeTab] || [];

  const renderRow = (t) => {
    const sName = statusName(t);
    const meta = STATUS_META[sName] || STATUS_META.Received;
    // In My Requests the requester is only viewing their own request — never
    // dispatching/verifying — so the row action is always "View".
    const actionLabel = activeTab === 'mine' ? 'View' : meta.action;
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
            {actionLabel}
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

    // Only the assigned source location (or admin/IA) may dispatch.
    const isSource = myLoc != null && String(selected.from_location_id) === String(myLoc);
    const canDispatchThis = isAdmin || isIA || isSource;
    // Requester may edit/cancel only while still Pending. requested_by may serialize as a user object.
    const reqBy = selected.requested_by;
    const reqById = reqBy && typeof reqBy === 'object' ? (reqBy.user_id ?? reqBy.id) : reqBy;
    const uid = user?.user_id ?? user?.id;
    const isRequester = uid != null && reqById != null && String(reqById) === String(uid);
    const canEditReq = sName === 'Pending' && (isRequester || isAdmin || isIA);

    return (
      <div className="ak-page" style={{ padding: 0 }}>
        <div className="tf-drawer-meta">
          <div className="tf-meta-item"><span className="tf-meta-label">Number</span><span className="tf-meta-value">{selected.transfer_number}</span></div>
          <div className="tf-meta-item"><span className="tf-meta-label">Intent</span><span className="tf-meta-value">{INTENT_LABEL[selected.intent] || selected.intent}</span></div>
          <div className="tf-meta-item"><span className="tf-meta-label">From</span><span className="tf-meta-value">{from || 'Unassigned'}</span></div>
          <div className="tf-meta-item"><span className="tf-meta-label">To</span><span className="tf-meta-value">{to}</span></div>
          <div className="tf-meta-item"><span className="tf-meta-label">Status</span><span className={`ak-badge ${STATUS_META[sName]?.badge || 'ak-badge--gray'}`}>{sName}</span></div>
        </div>

        <TransferSteps transfer={selected} />

        {bucket === 'verify' && canVerify && (
          <VerifyPanel transfer={selected} locations={locations} onDone={afterAction} />
        )}
        {bucket === 'verify' && !canVerify && (
          <p className="ak-muted">This transfer is awaiting analyst verification.</p>
        )}
        {canEditReq && (
          <div className="tf-actions-row" style={{ marginTop: 8 }}>
            <button type="button" className="ak-btn ak-btn--danger" onClick={() => handleCancel(selected)}>Cancel request</button>
            <button type="button" className="ak-btn ak-btn--primary" onClick={() => { setEditing(selected); setSelected(null); }}>Edit request</button>
          </div>
        )}
        {bucket === 'send' && canDispatchThis && <DispatchPanel transfer={selected} onDone={afterAction} />}
        {bucket === 'send' && !canDispatchThis && (
          <p className="ak-muted">Awaiting dispatch by {from || 'the assigned warehouse'}.</p>
        )}
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

        <TransferHistory transfer={selected} />
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

      <NewRequestModal isOpen={showNew} onClose={() => setShowNew(false)} onCreated={() => { setActiveTab('mine'); load(); }} />
      <NewRequestModal isOpen={!!editing} editTransfer={editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); load(); }} />
    </AdminLayout>
  );
};

export default TransfersPage;
