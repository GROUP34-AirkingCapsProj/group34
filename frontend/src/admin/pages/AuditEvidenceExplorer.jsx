import React, { useCallback, useEffect, useState } from 'react';
import AdminLayout from '../components/AdminLayout';
import Pagination from '../components/Pagination';
import { useAuth } from '../context/AuthContext';
import { useAuditEvidence } from '../hooks/useAuditEvidence';
import { auditEvidenceAPI } from '../services/api';
import SummaryTiles from '../components/audit/SummaryTiles';
import BreakdownTable from '../components/audit/BreakdownTable';
import MovementLedgerTable from '../components/audit/MovementLedgerTable';
import EvidenceDrawer from '../components/audit/EvidenceDrawer';
import ReconciliationPanel from '../components/audit/ReconciliationPanel';
import ExceptionsPanel from '../components/audit/ExceptionsPanel';
import '../styles/dashboard_air.css';
import '../styles/airking_ds.css';
import '../styles/audit_evidence.css';

const TABS = [
  { key: 'breakdown', label: 'Breakdown' },
  { key: 'movements', label: 'Movements' },
  { key: 'reconciliation', label: 'Reconciliation' },
  { key: 'exceptions', label: 'Exceptions' },
];

const PER_PAGE = 50;

/**
 * The Explorer shell: scope at the top, one level of detail below it, and the
 * evidence drawer over the whole thing.
 *
 * It holds no totals of its own. Every figure on this page came from the server,
 * so what an auditor reads here is what the ledger says — not what a browser
 * recomputed from six separately-fetched sources, which is how the page this
 * replaces drifted from reality.
 */
const AuditEvidenceExplorer = () => {
  const { permissions } = useAuth();
  const canResolve = permissions?.['audit_evidence.resolve_exception'] === true;

  const { scope, setScope, summary, loading, error } = useAuditEvidence();

  const [tab, setTab] = useState('breakdown');
  const [breakdown, setBreakdown] = useState([]);
  const [breakdownLoading, setBreakdownLoading] = useState(false);
  const [movements, setMovements] = useState([]);
  const [movementsLoading, setMovementsLoading] = useState(false);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [reconciliation, setReconciliation] = useState(null);
  const [openEvent, setOpenEvent] = useState(null);
  const [panelError, setPanelError] = useState('');

  useEffect(() => {
    if (tab !== 'breakdown') return undefined;

    let cancelled = false;
    setBreakdownLoading(true);
    setPanelError('');

    auditEvidenceAPI
      .getBreakdown({ ...scope, dimension: scope.dimension })
      .then((res) => {
        if (!cancelled) setBreakdown(Array.isArray(res?.data) ? res.data : []);
      })
      .catch((e) => {
        if (!cancelled) setPanelError(e?.message || 'Could not load the breakdown.');
      })
      .finally(() => {
        if (!cancelled) setBreakdownLoading(false);
      });

    return () => { cancelled = true; };
  }, [scope, tab]);

  useEffect(() => {
    if (tab !== 'movements') return undefined;

    let cancelled = false;
    setMovementsLoading(true);
    setPanelError('');

    auditEvidenceAPI
      .getMovements({ ...scope, page, per_page: PER_PAGE })
      .then((res) => {
        if (cancelled) return;
        setMovements(Array.isArray(res?.data) ? res.data : []);
        setLastPage(res?.pagination?.last_page ?? 1);
        setTotal(res?.pagination?.total ?? 0);
      })
      .catch((e) => {
        if (!cancelled) setPanelError(e?.message || 'Could not load movements.');
      })
      .finally(() => {
        if (!cancelled) setMovementsLoading(false);
      });

    return () => { cancelled = true; };
  }, [scope, tab, page]);

  useEffect(() => {
    if (tab !== 'reconciliation') return undefined;

    let cancelled = false;
    setPanelError('');

    auditEvidenceAPI
      .getReconciliation(scope)
      .then((res) => {
        if (!cancelled) setReconciliation(res?.data ?? null);
      })
      .catch((e) => {
        if (!cancelled) setPanelError(e?.message || 'Could not reconcile this period.');
      });

    return () => { cancelled = true; };
  }, [scope, tab]);

  const drillIntoMovements = useCallback(
    (row) => {
      // Carry the clicked group into the movement filter, so the drill-down
      // narrows the same scope rather than starting a fresh unrelated query.
      if (scope.dimension === 'movement_type') setScope({ movement_type: row.key });
      else if (scope.dimension === 'product') setScope({ product_id: row.key });
      else if (scope.dimension === 'user') setScope({ user_id: row.key });

      setPage(1);
      setTab('movements');
    },
    [scope.dimension, setScope]
  );

  return (
    <AdminLayout>
      <div className="ak-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Audit &amp; Oversight</p>
              <h1 className="ak-title">Audit &amp; Evidence Explorer</h1>
              <p className="ak-sub">
                Every recorded movement, with the document and the people behind it. Read-only.
              </p>
            </div>
            <div className="ae-tabs" role="group" aria-label="Lens">
              <button type="button" aria-pressed={scope.lens === 'inventory'} onClick={() => setScope({ lens: 'inventory' })}>
                Inventory
              </button>
              <button type="button" aria-pressed={scope.lens === 'finance'} onClick={() => setScope({ lens: 'finance' })}>
                Finance
              </button>
            </div>
          </div>
        </header>

        <div className="ae-card">
          <div className="ae-scope">
            <label className="ae-field">
              <span>From</span>
              <input type="date" value={scope.from} onChange={(e) => setScope({ from: e.target.value })} />
            </label>
            <label className="ae-field">
              <span>To</span>
              <input type="date" value={scope.to} onChange={(e) => setScope({ to: e.target.value })} />
            </label>
            <label className="ae-field">
              <span>Product ID</span>
              <input type="number" value={scope.product_id} onChange={(e) => setScope({ product_id: e.target.value })} />
            </label>
            <label className="ae-field">
              <span>Location ID</span>
              <input type="number" value={scope.location_id} onChange={(e) => setScope({ location_id: e.target.value })} />
            </label>
            {scope.movement_type !== '' && (
              <button type="button" className="ae-btn" onClick={() => setScope({ movement_type: '' })}>
                Clear type: {scope.movement_type}
              </button>
            )}
          </div>
        </div>

        {error !== '' && (
          <div className="ae-card">
            <p className="ae-warn" data-testid="explorer-error" style={{ padding: '1rem' }}>{error}</p>
          </div>
        )}

        {loading ? (
          <div className="ae-state">Loading this period…</div>
        ) : (
          <SummaryTiles summary={summary} emptyReason="no-movements" />
        )}

        <div className="ae-tabs" role="group" aria-label="Detail" style={{ marginTop: '1rem' }}>
          {TABS.map((t) => (
            <button key={t.key} type="button" aria-pressed={tab === t.key} onClick={() => setTab(t.key)}>
              {t.label}
            </button>
          ))}
        </div>

        {panelError !== '' && <p className="ae-warn" data-testid="panel-error">{panelError}</p>}

        {tab === 'breakdown' && (
          <BreakdownTable
            rows={breakdown}
            dimension={scope.dimension}
            loading={breakdownLoading}
            onDimensionChange={(d) => setScope({ dimension: d })}
            onDrillDown={drillIntoMovements}
          />
        )}

        {tab === 'movements' && (
          <div className="ae-card">
            <div className="ae-card-head">
              <div>
                <h2 className="ae-card-title">Movement ledger</h2>
                <p className="ae-card-meta">Reversals shown alongside what they reversed</p>
              </div>
            </div>
            <MovementLedgerTable rows={movements} loading={movementsLoading} onOpenEvidence={setOpenEvent} />
            {lastPage > 1 && (
              <Pagination
                currentPage={page}
                totalPages={lastPage}
                onPageChange={setPage}
                totalItems={total}
                pageSize={PER_PAGE}
                label="movements"
              />
            )}
          </div>
        )}

        {tab === 'reconciliation' && (
          <ReconciliationPanel
            data={reconciliation}
            onDrillDown={() => {
              setTab('movements');
              setPage(1);
            }}
          />
        )}

        {tab === 'exceptions' && <ExceptionsPanel canResolve={canResolve} />}

        <EvidenceDrawer correlationId={openEvent} onClose={() => setOpenEvent(null)} />
      </div>
    </AdminLayout>
  );
};

export default AuditEvidenceExplorer;
