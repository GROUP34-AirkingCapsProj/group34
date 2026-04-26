import React from 'react';
import AdminLayout from '../components/AdminLayout';
import { toast } from '../utils/toast';
import '../styles/dashboard_air.css';
import '../styles/system_reports.css';

const SystemReports = () => {
  const reportCards = [
    {
      id: 1,
      title: 'Inventory Summary Report',
      desc: 'Complete inventory status across all locations',
      formats: 'PDF / Excel',
      iconType: 'inventory', // blue cube/box
    },
    {
      id: 2,
      title: 'Transaction Report',
      desc: 'All inventory movements and transactions',
      formats: 'PDF / Excel / CSV',
      iconType: 'transaction', // green cart
    },
    {
      id: 3,
      title: 'Branch Performance',
      desc: 'Branch-wise inventory and sales analysis',
      formats: 'PDF / Excel',
      iconType: 'branch', // purple building
    },
    {
      id: 4,
      title: 'Low Stock Report',
      desc: 'Items below reorder level',
      formats: 'PDF / Excel',
      iconType: 'lowstock', // orange chart
    },
    {
      id: 5,
      title: 'Warehouse Utilization',
      desc: 'Capacity and occupancy analysis',
      formats: 'PDF / Excel',
      iconType: 'warehouse', // grey warehouse
    },
    {
      id: 6,
      title: 'User Activity Report',
      desc: 'System usage and login statistics',
      formats: 'PDF / Excel',
      iconType: 'user', // grey users
    },
  ];

  const recentReports = [
    { id: 1, name: 'Inventory_Summary_2025-01-19.pdf', type: 'Inventory Summary', date: '2025-01-19 09:30', size: '2.4 MB' },
    { id: 2, name: 'Transaction_Report_2025-01-18.xlsx', type: 'Transaction Report', date: '2025-01-18 17:45', size: '5.1 MB' },
    { id: 3, name: 'Branch_Performance_Q4_2024.pdf', type: 'Branch Performance', date: '2025-01-15 14:20', size: '3.8 MB' },
    { id: 4, name: 'Low_Stock_Alert_2025-01-17.xlsx', type: 'Low Stock Report', date: '2025-01-17 08:00', size: '890 KB' },
  ];

  const handleGenerate = (title) => {
    toast.info(`Generating ${title}...`);
  };

  const handleSchedule = (title) => {
    toast.info(`Schedule ${title} - coming soon.`);
  };

  const handleDownload = (r) => {
    toast.info(`Download ${r.name}`);
  };

  const handleView = (r) => {
    toast.info(`View ${r.name}`);
  };

  const ReportIcon = ({ type }) => {
    const cls = `sr-report-icon sr-report-icon-${type}`;
    switch (type) {
      case 'inventory':
        return (
          <div className={cls}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
              <polyline points="3.27 6.96 12 12.01 20.73 6.96" />
              <line x1="12" y1="22.08" x2="12" y2="12" />
            </svg>
          </div>
        );
      case 'transaction':
        return (
          <div className={cls}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <circle cx="9" cy="21" r="1" />
              <circle cx="20" cy="21" r="1" />
              <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
            </svg>
          </div>
        );
      case 'branch':
        return (
          <div className={cls}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
              <polyline points="9 22 9 12 15 12 15 22" />
            </svg>
          </div>
        );
      case 'lowstock':
        return (
          <div className={cls}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <line x1="18" y1="20" x2="18" y2="10" />
              <line x1="12" y1="20" x2="12" y2="4" />
              <line x1="6" y1="20" x2="6" y2="14" />
            </svg>
          </div>
        );
      case 'warehouse':
        return (
          <div className={cls}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
              <polyline points="9 22 9 12 15 12 15 22" />
            </svg>
          </div>
        );
      case 'user':
        return (
          <div className={cls}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
              <circle cx="9" cy="7" r="4" />
              <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
              <path d="M16 3.13a4 4 0 0 1 0 7.75" />
            </svg>
          </div>
        );
      default:
        return <div className={cls} />;
    }
  };

  return (
    <AdminLayout>
      <div className="sr-page">
        <div className="sr-page-header">
          <h1 className="sr-page-title">Reports</h1>
          <p className="sr-page-subtitle">Generate and view system-wide inventory and transaction reports</p>
        </div>

        <div className="sr-cards-grid">
          {reportCards.map((r) => (
            <div className="sr-card" key={r.id}>
              <h3 className="sr-card-title">{r.title}</h3>
              <p className="sr-card-desc">{r.desc}</p>
              <div className="sr-card-content">
                <ReportIcon type={r.iconType} />
                <div className="sr-card-format-block">
                  <span className="sr-format-label">Available Formats</span>
                  <span className="sr-format-types">{r.formats}</span>
                </div>
              </div>
              <div className="sr-card-actions">
                <button type="button" className="sr-btn-generate" onClick={() => handleGenerate(r.title)}>
                  Generate Report
                </button>
                <button type="button" className="sr-btn-schedule" onClick={() => handleSchedule(r.title)}>
                  Schedule Report
                </button>
              </div>
            </div>
          ))}
        </div>

        <div className="sr-recent-card">
          <h3 className="sr-recent-title">Recent Reports</h3>
          <p className="sr-recent-subtitle">Previously generated reports</p>
          <div className="sr-recent-list">
            {recentReports.map((r) => (
              <div className="sr-recent-item" key={r.id}>
                <div className="sr-recent-item-left">
                  <div className="sr-file-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                      <polyline points="14 2 14 8 20 8" />
                      <line x1="16" y1="13" x2="8" y2="13" />
                      <line x1="16" y1="17" x2="8" y2="17" />
                    </svg>
                  </div>
                  <div className="sr-recent-info">
                    <span className="sr-recent-name">{r.name}</span>
                    <span className="sr-recent-meta">{r.type} • Generated: {r.date}</span>
                  </div>
                </div>
                <div className="sr-recent-item-right">
                  <span className="sr-recent-size">{r.size}</span>
                  <button type="button" className="sr-btn-download" onClick={() => handleDownload(r)}>Download</button>
                  <button type="button" className="sr-btn-view" onClick={() => handleView(r)}>View</button>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    </AdminLayout>
  );
};

export default SystemReports;
