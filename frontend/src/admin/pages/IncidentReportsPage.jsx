import React, { useRef, useState } from 'react';
import AdminLayout from '../components/AdminLayout';
import IncidentList from '../components/incident/IncidentList';
import IncidentFileModal from '../components/IncidentFileModal';
import { useAuth } from '../context/AuthContext';

/**
 * Report + triage surface for incident reports. Incidents arrive two ways: as a
 * FIFO override filed from the barcode scanner mid-pick, and — via the Report
 * Incident button here — as a standalone report of something a user simply
 * noticed (theft, damage, a customer return).
 *
 * The list itself lives in IncidentList so the Report Incident page reached
 * from the barcode scanner can render the same table.
 */
const IncidentReportsPage = () => {
  const { can } = useAuth();
  const canCreate = typeof can === 'function' && can('incident_reports', 'create');

  const [filing, setFiling] = useState(false);
  const listRef = useRef(null);

  return (
    <AdminLayout>
      <div className="ak-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Inventory · Exceptions</p>
              <h1 className="ak-title">Incident Reports</h1>
              <p className="ak-sub">
                Report and triage stock problems — missing or stolen stock, damage in storage or delivery, the
                wrong product on the shelf, customer returns, or anything else that needs a record and a reviewer.
              </p>
            </div>
            <div className="ak-header-aside">
              {canCreate && (
                <button
                  type="button"
                  className="ak-btn ak-btn--primary"
                  onClick={() => setFiling(true)}
                >
                  Report Incident
                </button>
              )}
            </div>
          </div>
        </header>

        <IncidentList ref={listRef} />
      </div>

      <IncidentFileModal
        isOpen={filing}
        onClose={() => setFiling(false)}
        onFiled={() => { setFiling(false); listRef.current?.reload(); }}
      />
    </AdminLayout>
  );
};

export default IncidentReportsPage;
