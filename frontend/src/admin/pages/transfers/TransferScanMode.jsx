import React, { useState, useEffect, useCallback } from 'react';
import { transfersAPI } from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { toast } from '../../utils/toast';
import Loader from '../../components/Loader';
import DispatchPanel from './DispatchPanel';
import ReceivePanel from './ReceivePanel';
import '../../styles/airking_ds.css';
import '../../styles/transfers.css';

const statusName = (t) => t?.status?.status_name || t?.status_name || '';
const route = (t) =>
  `${t?.fromLocation?.location_name || 'Unassigned'} → ${t?.toLocation?.location_name || '—'}`;

/**
 * Transfer scan operation embedded in the Scan Barcode page.
 * Lists the transfers assigned to the user's location that need a scan action —
 * Approved (dispatch, source side) and In Transit (receive, destination side) —
 * and reuses DispatchPanel / ReceivePanel to do the actual scanning.
 */
const TransferScanMode = ({ onBack }) => {
  const { user } = useAuth();
  const myLoc = user?.location_id ?? user?.enforced_location_id ?? null;

  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [selected, setSelected] = useState(null); // full transfer (from getById)
  const [panel, setPanel] = useState(null);        // 'dispatch' | 'receive'
  const [opening, setOpening] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await transfersAPI.getAll({ per_page: 100 });
      setRows(Array.isArray(res?.data) ? res.data : []);
    } catch (e) {
      toast.error('Failed to load transfers', e?.message || '');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  const dispatchList = rows.filter(
    (t) => statusName(t) === 'Approved' && (myLoc == null || Number(t.from_location_id) === Number(myLoc))
  );
  const receiveList = rows.filter(
    (t) => statusName(t) === 'In Transit' && (myLoc == null || Number(t.to_location_id) === Number(myLoc))
  );

  const open = async (t, which) => {
    setOpening(true);
    try {
      const res = await transfersAPI.getById(t.transfer_id);
      setSelected(res?.data || t);
      setPanel(which);
    } catch (e) {
      toast.error('Failed to open transfer', e?.message || '');
    } finally {
      setOpening(false);
    }
  };

  const afterDone = () => { setSelected(null); setPanel(null); load(); };

  if (selected && panel) {
    return (
      <div className="ak-scope tf-scan">
        <button type="button" className="ak-btn ak-btn--ghost" style={{ marginBottom: 12 }} onClick={afterDone}>
          ← Back to transfers
        </button>
        {panel === 'dispatch'
          ? <DispatchPanel transfer={selected} onDone={afterDone} />
          : <ReceivePanel transfer={selected} onDone={afterDone} />}
      </div>
    );
  }

  const renderGroup = (title, list, which, emptyMsg) => (
    <section className="ak-card tf-scan-group" style={{ marginBottom: 16 }}>
      <div className="ak-card__head">
        <h3 className="ak-card__title">{title} <span className="ak-muted">({list.length})</span></h3>
      </div>
      <div className="ak-card__body">
        {list.length === 0 ? (
          <p className="ak-muted" style={{ margin: 0 }}>{emptyMsg}</p>
        ) : (
          <div className="tf-scan-rows">
            {list.map((t) => (
              <button
                key={t.transfer_id}
                type="button"
                className="tf-scan-row"
                disabled={opening}
                onClick={() => open(t, which)}
              >
                <span className="tf-scan-row__num">{t.transfer_number}</span>
                <span className="tf-scan-row__route">{route(t)}</span>
                <span className="ak-btn ak-btn--sm ak-btn--primary">
                  {which === 'dispatch' ? 'Dispatch' : 'Receive'}
                </span>
              </button>
            ))}
          </div>
        )}
      </div>
    </section>
  );

  return (
    <div className="ak-scope tf-scan">
      <div className="tf-scan__head" style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 16 }}>
        <button type="button" className="ak-btn ak-btn--ghost" onClick={onBack}>← Modes</button>
        <h2 style={{ margin: 0 }}>Transfer</h2>
      </div>

      {loading ? (
        <Loader />
      ) : (
        <>
          {renderGroup('To Dispatch', dispatchList, 'dispatch', 'No transfers assigned to your warehouse to dispatch.')}
          {renderGroup('To Receive', receiveList, 'receive', 'No incoming transfers to receive.')}
        </>
      )}
    </div>
  );
};

export default TransferScanMode;
