import React, { lazy, Suspense } from 'react';
import { useSearchParams } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';

const Reports = lazy(() => import('./IncidentReportsPage'));
const Inspections = lazy(() => import('./IncidentInspectionPage'));
const Items = lazy(() => import('./IncidentItemsRegisterPage'));
const TABS = [
  { key: 'reports', label: 'Incident Reports', Page: Reports },
  { key: 'inspections', label: 'Inspections', Page: Inspections },
  { key: 'items', label: 'Incident Items', Page: Items },
];

export default function IncidentWorkspace() {
  const [params, setParams] = useSearchParams();
  const selected = TABS.find((tab) => tab.key === params.get('tab')) || TABS[0];
  const Page = selected.Page;
  return (
    <AdminLayout>
      <div className="ak-page">
        <div role="tablist" aria-label="Incident sections" className="ak-toolbar" style={{ flexWrap: 'wrap', marginBottom: 20 }}>
          {TABS.map((tab) => <button key={tab.key} type="button"
            role="tab" id={`incident-tab-${tab.key}`} aria-controls="incident-panel"
            aria-selected={selected.key === tab.key} tabIndex={selected.key === tab.key ? 0 : -1}
            onKeyDown={(event) => {
              const index = TABS.findIndex((item) => item.key === tab.key);
              const nextIndex = { ArrowRight: (index + 1) % TABS.length, ArrowLeft: (index + TABS.length - 1) % TABS.length,
                Home: 0, End: TABS.length - 1 }[event.key];
              if (nextIndex === undefined) return;
              event.preventDefault();
              const next = new URLSearchParams(params);
              next.set('tab', TABS[nextIndex].key);
              setParams(next);
              event.currentTarget.parentElement.querySelectorAll('[role="tab"]')[nextIndex].focus();
            }}
            className={`ak-btn ${selected.key === tab.key ? 'ak-btn--primary' : 'ak-btn--ghost'}`}
            onClick={() => {
              const next = new URLSearchParams(params);
              next.set('tab', tab.key);
              setParams(next);
            }}>{tab.label}</button>)}
        </div>
        <section role="tabpanel" id="incident-panel" aria-labelledby={`incident-tab-${selected.key}`}>
          <Suspense fallback={<Loader label="Loading incident section" />}>
            <Page embedded />
          </Suspense>
        </section>
      </div>
    </AdminLayout>
  );
}
