import React, { useCallback, useEffect, useState } from 'react';
import Modal from './Modal';
import Loader from './Loader';
import IncidentDocument from './incident/IncidentDocument';
import { DOC_MODES } from '../utils/incidentDocument';
import { incidentReportsAPI } from '../services/api';

/**
 * The admin's decision surface — the SAME document the reporter filled and the
 * inspector signed, rendered read-only with Confirm, Reject and Dismiss.
 *
 * This was ~580 lines of its own markup: a second rendering of the report that
 * showed the units, their outcomes and the evidence but NOT the dealer,
 * customer or site block. An admin therefore approved without seeing half of
 * what the reporter had claimed — which is the exact failure the one-document
 * design exists to prevent. A second layout is also a second thing to drift
 * from the paper that actually got signed.
 *
 * Props:
 *   incident   {object|null}  the LIST row; null keeps the drawer closed
 *   onClose    {() => void}
 *   canResolve {boolean}      whether this user holds incident_reports.resolve
 *   onResolved {() => void}   fires after a confirm, reject or dismiss
 */
export default function IncidentResolveDrawer({ incident, onClose, canResolve, onResolved }) {
  const [report, setReport] = useState(null);
  const [error, setError] = useState(null);

  const incidentId = incident?.incident_id ?? null;

  /**
   * The list row carries none of the client document block, the items, the
   * evidence or the findings — only the detail endpoint has those, and without
   * them this screen would ask an admin to approve a write-off sight unseen.
   */
  const load = useCallback(async () => {
    if (incidentId === null) return;
    try {
      const res = await incidentReportsAPI.get(incidentId);
      setReport(res?.data || null);
      setError(null);
    } catch (err) {
      setReport(null);
      setError(err.message || 'The report could not be loaded.');
    }
  }, [incidentId]);

  useEffect(() => {
    setReport(null);
    setError(null);
    load();
  }, [load]);

  if (incident === null || incident === undefined) return null;

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={`Incident ${incident.incident_number}`}
      maxWidth={900}
    >
      {error ? (
        <div className="ak-state ak-state--error">{error}</div>
      ) : report === null ? (
        <Loader label="Loading the report" />
      ) : (
        <IncidentDocument
          mode={DOC_MODES.DECIDE}
          report={report}
          canInspect={canResolve}
          onCancel={onClose}
          /* Re-fetch rather than close: a rejection keeps the drawer useful,
             and the parent list refreshes through onResolved either way. */
          onChanged={load}
          onSubmitted={() => { onResolved?.(); }}
        />
      )}
    </Modal>
  );
}
