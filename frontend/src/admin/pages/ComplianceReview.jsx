import React, { useState } from 'react';
import AdminLayout from '../components/AdminLayout';
import '../styles/dashboard_air.css';
import '../styles/compliance_review.css';

const ComplianceReview = () => {
  const [activeTab, setActiveTab] = useState('anomaly');

  const exceptions = [
    { id: 1, severity: 'HIGH', title: 'Discrepancy', description: 'DVD Main: Item RF-GAM 203 count mismatch (-2)', location: 'Davao City', },
    { id: 2, severity: 'MEDIUM', title: 'Manual Override', description: 'User WP011 bypassed serial validation on AC-PAN 1.5', location: 'CDO City', },
    { id: 3, severity: 'HIGH', title: 'Duplicate DR', description: 'Reference DR-99027 generated twice within 5 mins', location: 'Zamboanga City', },
  ];

  const getSeverityClass = (s) => {
    if (s === 'HIGH') return 'cr-severity-high';
    if (s === 'MEDIUM') return 'cr-severity-medium';
    return 'cr-severity-low';
  };

  return (
    <AdminLayout>
      <div className="cr-page">
        {/* Header */}
        <div className="cr-page-header">
          <div className="cr-page-header-left">
            <h1 className="cr-page-title">Compliance Review</h1>
            <p className="cr-page-subtitle">Audit internal controls and operational adherence</p>
          </div>
          <button className="cr-btn-export">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
              <polyline points="7 10 12 15 17 10"></polyline>
              <line x1="12" y1="15" x2="12" y2="3"></line>
            </svg>
            EXPORT AUDIT FINDINGS
          </button>
        </div>

        {/* Tabs */}
        <div className="cr-tabs">
          <button className={`cr-tab ${activeTab === 'anomaly' ? 'active' : ''}`} onClick={() => setActiveTab('anomaly')}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
              <circle cx="11" cy="11" r="8"></circle>
              <path d="m21 21-4.35-4.35"></path>
            </svg>
            Anomaly Detection
          </button>
          <button className={`cr-tab ${activeTab === 'pos' ? 'active' : ''}`} onClick={() => setActiveTab('pos')}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
              <polyline points="14 2 14 8 20 8"></polyline>
            </svg>
            POS Audit Trail
          </button>
          <button className={`cr-tab ${activeTab === 'po' ? 'active' : ''}`} onClick={() => setActiveTab('po')}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
            </svg>
            PO Integrity
          </button>
        </div>

        {/* Content Layout */}
        <div className="cr-content-layout">
          {/* Main Content */}
          <div className="cr-main-content">
            <div className="cr-section-header">
              <h3>Detected Exceptions</h3>
              <p>Automated system integrity checks</p>
            </div>

            <div className="cr-exceptions-list">
              {exceptions.map((ex) => (
                <div key={ex.id} className={`cr-exception-card ${getSeverityClass(ex.severity)}`}>
                  <div className="cr-exception-top">
                    <span className={`cr-severity-badge ${getSeverityClass(ex.severity)}`}>
                      {ex.severity} SEVERITY
                    </span>
                    <span className="cr-exception-location">{ex.location}</span>
                  </div>
                  <h4 className="cr-exception-title">{ex.title}</h4>
                  <p className="cr-exception-desc">{ex.description}</p>
                  <div className="cr-exception-actions">
                    <button className="cr-btn-investigate">INVESTIGATE</button>
                    <button className="cr-btn-dismiss">DISMISS</button>
                  </div>
                </div>
              ))}
            </div>
          </div>

          {/* Sidebar */}
          <div className="cr-sidebar">
            {/* Auditor Scorecard */}
            <div className="cr-scorecard">
              <h3 className="cr-scorecard-title">Auditor Scorecard</h3>
              <div className="cr-score-circle">
                <svg viewBox="0 0 120 120" width="120" height="120">
                  <circle cx="60" cy="60" r="52" fill="none" stroke="#f3f4f6" strokeWidth="8" />
                  <circle cx="60" cy="60" r="52" fill="none" stroke="#3b82f6" strokeWidth="8"
                    strokeDasharray={`${0.945 * 2 * Math.PI * 52} ${2 * Math.PI * 52}`}
                    strokeDashoffset="0" strokeLinecap="round"
                    transform="rotate(-90 60 60)" />
                </svg>
                <div className="cr-score-value">
                  <span className="cr-score-num">94.5%</span>
                  <span className="cr-score-label">INTEGRITY</span>
                </div>
              </div>
              <div className="cr-score-metrics">
                <div className="cr-score-metric">
                  <span>Inventory Accuracy</span>
                  <span className="cr-metric-val cr-metric-red">98.2%</span>
                </div>
                <div className="cr-score-metric">
                  <span>Scan Compliance</span>
                  <span className="cr-metric-val cr-metric-green">92.9%</span>
                </div>
              </div>
            </div>

            {/* Security Alerts */}
            <div className="cr-security-card">
              <h3 className="cr-security-title">Security Alerts</h3>
              <div className="cr-security-alert">
                <div className="cr-alert-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" strokeWidth="2" width="20" height="20">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                    <line x1="12" y1="9" x2="12" y2="13"></line>
                    <line x1="12" y1="17" x2="12.01" y2="17"></line>
                  </svg>
                </div>
                <div>
                  <span className="cr-alert-label">RECENT EXCEPTIONS</span>
                  <div className="cr-alert-count">
                    <span className="cr-alert-num">12</span>
                    <span className="cr-alert-sub">Unresolved</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </AdminLayout>
  );
};

export default ComplianceReview;
