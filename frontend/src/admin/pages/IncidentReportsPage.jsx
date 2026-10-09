import React, { useState } from 'react';
import AdminLayout from '../components/AdminLayout';
import IncidentList from '../components/incident/IncidentList';
import IncidentDocument from '../components/incident/IncidentDocument';
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
const IncidentReportsPage = ({ embedded = false }) => {
  const Layout = embedded ? React.Fragment : AdminLayout;
  const { can } = useAuth();
  const canCreate = typeof can === 'function' && can('incident_reports', 'create');

  const [filing, setFiling] = useState(false);
  // The flow replaces the list rather than opening over it, so the list is
  // unmounted while filing and a reload() through a ref would hit null. Bumping
  // this key remounts the list, and its mount effect refetches — one mechanism
  // that works whether or not the list happens to be on screen.
  const [refreshToken, setRefreshToken] = useState(0);

  return (
    <Layout>
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
              {/* Hidden while the form is open — it carries its own
                  "← Back to list". */}
              {canCreate && !filing && (
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

        {filing ? (
          <IncidentDocument
            onCancel={() => setFiling(false)}
            onFiled={() => { setFiling(false); setRefreshToken((n) => n + 1); }}
          />
        ) : (
          <IncidentList key={refreshToken} />
        )}
      </div>
    </Layout>
  );
};

export default IncidentReportsPage;
