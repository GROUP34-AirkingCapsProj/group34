import React from 'react';
import { useNavigate } from 'react-router-dom';
import '../../admin/styles/landing.css';

const roles = [
  {
    title: 'System Admin',
    description: 'Head office - Full system access and management',
    icon: (
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
      </svg>
    ),
    color: 'red',
    path: '/admin/login'
  },
  {
    title: 'Inventory Analyst',
    description: 'Head office - Analytics and centralized reporting',
    icon: (
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <line x1="12" y1="20" x2="12" y2="10"></line>
        <line x1="18" y1="20" x2="18" y2="4"></line>
        <line x1="6" y1="20" x2="6" y2="16"></line>
      </svg>
    ),
    color: 'purple',
    path: '/admin/login'
  },
  {
    title: 'Branch Manager',
    description: 'Branch operations and local inventory oversight',
    icon: (
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
        <line x1="16" y1="2" x2="16" y2="6"></line>
        <line x1="8" y1="2" x2="8" y2="6"></line>
        <line x1="3" y1="10" x2="21" y2="10"></line>
      </svg>
    ),
    color: 'green',
    path: '/admin/login'
  },
  {
    title: 'Warehouse Personnel',
    description: 'Barcode scanning and product tracking',
    icon: (
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
        <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
        <line x1="12" y1="22.08" x2="12" y2="12"></line>
      </svg>
    ),
    color: 'blue',
    path: '/admin/login'
  },
  {
    title: 'Auditor',
    description: 'Head office - Compliance and audit trails',
    icon: (
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M9 11l3 3L22 4"></path>
        <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
      </svg>
    ),
    color: 'indigo',
    path: '/admin/login'
  }
];

const Landing = () => {
  const navigate = useNavigate();

  return (
    <div className="landing-container">
      {/* Header */}
      <div className="landing-header">
        <div className="brand">
          <div className="brand-logo">
            <img src="/images/air.png" alt="AirKing Logo" loading="lazy" />
          </div>
          <div>
            <h3>AIRKING</h3>
            <p>Air Conditioning</p>
          </div>
        </div>
      </div>

      {/* Main */}
      <div className="landing-content">
        <h1>Inventory Management System</h1>
        <p className="subtitle">
          Select your role to access the dashboard
        </p>

        <div className="role-grid">
          {roles.map((role, index) => (
            <div key={index} className="role-card">
              <div className={`role-icon ${role.color}`}>
                {role.icon}
              </div>
              <h3>{role.title}</h3>
              <p>{role.description}</p>
              <button
                className="access-btn"
                onClick={() => navigate(role.path)}
              >
                Access Dashboard →
              </button>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
};

export default Landing;