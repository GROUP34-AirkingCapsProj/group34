import React, { useState, useEffect, useCallback, useRef } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { authAPI } from '../services/api';

/* Deterministic avatar colour from a string */
const AVATAR_COLORS = ['#dc2626', '#d97706', '#059669', '#2563eb', '#7c3aed', '#db2777'];
const avatarColor = (str = '') => {
  let h = 0;
  for (let i = 0; i < str.length; i++) h = (h * 31 + str.charCodeAt(i)) | 0;
  return AVATAR_COLORS[Math.abs(h) % AVATAR_COLORS.length];
};

// Route-to-chunk prefetch map — mirrors the lazy() imports in App.js
const routePrefetchMap = {
  '/admin/dashboard': () => import('../pages/Dashboard'),
  '/admin/analytics': () => import('../pages/AnalyticsDashboard'),
  '/admin/roles': () => import('../pages/AccessControlPage'),
  '/admin/locations': () => import('../pages/LocationManagement'),
  '/admin/branches': () => import('../pages/LocationManagement'),
  '/admin/warehouses': () => import('../pages/LocationManagement'),
  '/admin/categories': () => import('../pages/CategoryBrandManagement'),
  '/admin/brands': () => import('../pages/CategoryBrandManagement'),
  '/admin/items': () => import('../pages/ItemManagement'),
  '/admin/suppliers': () => import('../pages/SupplierNetwork'),
  '/admin/budget-management': () => import('../pages/BudgetManagementPage'),
  '/admin/purchase-requests': () => import('../pages/PurchaseRequestPage'),
  '/admin/draft-po-creator': () => import('../pages/PurchaseOrderPage'),
  '/admin/inventory': () => import('../pages/InventoryManagement'),
  '/admin/transfer-requests': () => import('../pages/TransferRequestPage'),
  '/admin/transfers': () => import('../pages/StockTransferManagement'),
  '/admin/ist': () => import('../pages/ISTManagementPage'),
  '/admin/issuances': () => import('../pages/IssuanceManagement'),
  '/admin/adjustments': () => import('../pages/AdjustmentManagement'),
  '/admin/inventory-operation': () => import('../pages/BarcodeScan'),
  '/admin/customers': () => import('../pages/CustomerManagement'),
  '/admin/transactions': () => import('../pages/TransactionManagement'),
  '/admin/delivery-receipts': () => import('../pages/DeliveryReceiptManagement'),
  '/admin/purchase-returns': () => import('../pages/PurchaseReturnManagement'),
  '/admin/reports': () => import('../pages/SystemReports'),
  '/admin/audit': () => import('../pages/AuditTrail'),
  '/admin/profit-loss': () => import('../pages/ProfitLossManagement'),
  '/admin/issuance-scan': () => import('../pages/IssuanceScanPage'),
  '/admin/warranties': () => import('../pages/WarrantyManagement'),
  '/admin/warranty-claims': () => import('../pages/WarrantyClaimPage'),
};

const prefetchedRoutes = new Set();
const prefetchRoute = (path) => {
  if (prefetchedRoutes.has(path) || !routePrefetchMap[path]) return;
  prefetchedRoutes.add(path);
  routePrefetchMap[path]();
};

const adminMenuSections = [
  {
    label: 'ADMINISTRATION',
    items: [
      { name: 'Dashboard', path: '/admin/dashboard', icon: 'dashboard' },
      { name: 'Analytics Dashboard', path: '/admin/analytics', icon: 'analytics' },
      // User Management now lives in System Configuration → User Management tab.
      { name: 'Role & Permissions', path: '/admin/roles', icon: 'roles' },
      { name: 'Permission Audit', path: '/admin/permission-audit', icon: 'audit' },
      { name: 'Location Management', path: '/admin/branches', icon: 'location' },
      { name: 'System Configuration', path: '/admin/config', icon: 'config' },
      { name: 'Archive Management', path: '/admin/archive', icon: 'archive' },
    ],
  },
  {
    label: 'PROCUREMENT & PLANNING',
    items: [
      { name: 'Budget Management', path: '/admin/budget-management', icon: 'budget' },
      { name: 'Purchase Requests', path: '/admin/purchase-requests', icon: 'approval' },
      { name: 'Purchase Orders', path: '/admin/draft-po-creator', icon: 'procurement' },
      { name: 'Supplier Network', path: '/admin/suppliers', icon: 'supplier' },
      { name: 'Item Master', path: '/admin/items', icon: 'items' },
      { name: 'Categories and Brands', path: '/admin/categories', icon: 'category' },
    ],
  },
  {
    label: 'INVENTORY INTELLIGENCE',
    items: [
      { name: 'Inventory Monitoring', path: '/admin/inventory', icon: 'inventory' },
      { name: 'Transfer Requests', path: '/admin/transfer-requests', icon: 'transfer' },
      { name: 'Stock Transfers', path: '/admin/transfers', icon: 'transfer' },
      { name: 'Interbranch Transfers', path: '/admin/ist', icon: 'transfer' },
    ],
  },
  {
    label: 'SALES & LOGISTICS',

    items: [
      { name: 'Transactions', path: '/admin/transactions', icon: 'globaltransactions' },
      { name: 'Customer Registry', path: '/admin/customers', icon: 'customers' },
      { name: 'Scan Barcode', path: '/admin/inventory-operation', icon: 'scan' },
    ],
  },
  {
    label: 'COMPLIANCE & SUPPORT',

    items: [
      { name: 'Compliance Review', path: '/admin/compliance', icon: 'compliance' },
      { name: 'System Reports', path: '/admin/reports', icon: 'reports' },
      { name: 'Warranty Records', path: '/admin/warranties', icon: 'warranty' },
      { name: 'Alerts & Notices', path: '/admin/alerts', icon: 'notifications' },
      { name: 'Audit Trail', path: '/admin/audit', icon: 'audit' },
    ],
  },
];

const renderIcon = (iconType) => {
  switch (iconType) {
    case 'dashboard':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <rect x="3" y="3" width="7" height="7"></rect>
          <rect x="14" y="3" width="7" height="7"></rect>
          <rect x="14" y="14" width="7" height="7"></rect>
          <rect x="3" y="14" width="7" height="7"></rect>
        </svg>
      );
    case 'analytics':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <line x1="18" y1="20" x2="18" y2="10"></line>
          <line x1="12" y1="20" x2="12" y2="4"></line>
          <line x1="6" y1="20" x2="6" y2="14"></line>
        </svg>
      );
    case 'users':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
          <circle cx="9" cy="7" r="4"></circle>
          <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
          <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
        </svg>
      );
    case 'roles':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
          <path d="M9 12l2 2 4-4"></path>
        </svg>
      );
    case 'branch':
    case 'location':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
          <polyline points="9 22 9 12 15 12 15 22"></polyline>
        </svg>
      );
    case 'warehouse':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
        </svg>
      );
    case 'customers':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
          <circle cx="12" cy="7" r="4"></circle>
        </svg>
      );
    case 'warranty':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
        </svg>
      );
    case 'items':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <line x1="8" y1="6" x2="21" y2="6"></line>
          <line x1="8" y1="12" x2="21" y2="12"></line>
          <line x1="8" y1="18" x2="21" y2="18"></line>
          <line x1="3" y1="6" x2="3.01" y2="6"></line>
          <line x1="3" y1="12" x2="3.01" y2="12"></line>
          <line x1="3" y1="18" x2="3.01" y2="18"></line>
        </svg>
      );
    case 'category':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
        </svg>
      );
    case 'brand':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
          <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>
          <line x1="7" y1="7" x2="7.01" y2="7"></line>
        </svg>
      );
    case 'scan':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M4 7V5a2 2 0 0 1 2-2h2"/>
          <path d="M16 3h2a2 2 0 0 1 2 2v2"/>
          <path d="M4 17v2a2 2 0 0 0 2 2h2"/>
          <path d="M16 21h2a2 2 0 0 0 2-2v-2"/>
          <line x1="8" y1="8" x2="8" y2="16"/>
          <line x1="12" y1="8" x2="12" y2="16"/>
          <line x1="16" y1="8" x2="16" y2="16"/>
        </svg>
      );
    case 'issuance-scan':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <rect x="2" y="3" width="20" height="14" rx="2"/>
          <path d="M8 21h8M12 17v4"/>
          <line x1="7" y1="8" x2="7" y2="12"/>
          <line x1="10" y1="7" x2="10" y2="13"/>
          <line x1="13" y1="8" x2="13" y2="12"/>
          <line x1="16" y1="7" x2="16" y2="13"/>
        </svg>
      );
    case 'inventory':
    case 'movement':
    case 'inventoryop':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
        </svg>
      );
    case 'transactions':
    case 'globaltransactions':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <line x1="12" y1="1" x2="12" y2="23"></line>
          <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
        </svg>
      );
    case 'po':
    case 'purchaseorders':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
          <polyline points="14 2 14 8 20 8"></polyline>
          <line x1="16" y1="13" x2="8" y2="13"></line>
          <line x1="16" y1="17" x2="8" y2="17"></line>
        </svg>
      );
    case 'restocking':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <polyline points="23 4 23 10 17 10"></polyline>
          <polyline points="1 20 1 14 7 14"></polyline>
          <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
        </svg>
      );
    case 'reports':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
          <polyline points="14 2 14 8 20 8"></polyline>
        </svg>
      );
    case 'audit':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <circle cx="11" cy="11" r="8"></circle>
          <path d="m21 21-4.35-4.35"></path>
        </svg>
      );
    case 'config':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <circle cx="12" cy="12" r="3"></circle>
          <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
        </svg>
      );
    case 'notifications':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
          <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
        </svg>
      );
    case 'archive':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <polyline points="21 8 21 21 3 21 3 8"></polyline>
          <rect x="1" y="3" width="22" height="5"></rect>
          <line x1="10" y1="12" x2="14" y2="12"></line>
        </svg>
      );
    case 'archive-settings':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <circle cx="12" cy="12" r="3"></circle>
          <polyline points="21 8 21 21 3 21 3 8"></polyline>
          <rect x="1" y="3" width="22" height="5"></rect>
        </svg>
      );
    case 'approval':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M9 11l3 3L22 4"></path>
          <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
        </svg>
      );
    case 'procurement':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
          <line x1="3" y1="6" x2="21" y2="6"></line>
          <path d="M16 10a4 4 0 0 1-8 0"></path>
        </svg>
      );
    case 'supplier':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <rect x="1" y="3" width="15" height="13"></rect>
          <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon>
          <circle cx="5.5" cy="18.5" r="2.5"></circle>
          <circle cx="18.5" cy="18.5" r="2.5"></circle>
        </svg>
      );
    case 'compliance':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
          <polyline points="14 2 14 8 20 8"></polyline>
          <path d="M9 15l2 2 4-4"></path>
        </svg>
      );
    case 'receiving':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M5 12h14"></path>
          <path d="m12 5 7 7-7 7"></path>
        </svg>
      );
    case 'transfer':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M17 3l4 4-4 4"></path>
          <path d="M3 7h18"></path>
          <path d="M7 21l-4-4 4-4"></path>
          <path d="M21 17H3"></path>
        </svg>
      );
    case 'issuance':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <polyline points="9 11 12 14 22 4"></polyline>
          <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
        </svg>
      );
    case 'adjustment':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <line x1="12" y1="5" x2="12" y2="19"></line>
          <line x1="5" y1="12" x2="19" y2="12"></line>
        </svg>
      );
    case 'delivery':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <rect x="1" y="3" width="15" height="13"></rect>
          <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon>
          <circle cx="5.5" cy="18.5" r="2.5"></circle>
          <circle cx="18.5" cy="18.5" r="2.5"></circle>
        </svg>
      );
    case 'return':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <polyline points="1 4 1 10 7 10"></polyline>
          <path d="M3.51 15a9 9 0 1 0 .49-4"></path>
        </svg>
      );
    case 'budget':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <line x1="12" y1="1" x2="12" y2="23"></line>
          <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
        </svg>
      );
    default:
      return null;
  }
};

const NavSkeleton = () => {
  const groups = [
    { items: 5, widths: [70, 58, 64, 50, 66] },
    { items: 4, widths: [60, 72, 54, 62] },
    { items: 3, widths: [56, 68, 60] },
  ];
  return (
    <div className="nav-skeleton" aria-hidden="true">
      {groups.map((g, gi) => (
        <div key={gi} className="nav-skel-section">
          <span className="nav-skel-label" style={{ width: 84 }} />
          {Array.from({ length: g.items }).map((_, i) => (
            <div key={i} className="nav-skel-item">
              <span className="nav-skel-icon" />
              <span className="nav-skel-text" style={{ width: `${g.widths[i]}%` }} />
            </div>
          ))}
        </div>
      ))}
    </div>
  );
};

const Sidebar = ({
  mobileNavOpen,
  closeMobileNav,
  activeItemName,
  onNavClick,
  onNavHover,
  isItemActive,
  menuSections,
  expandedSections,
  onToggleSection,
  navMenuRef,
  onLogout,
  pendingApprovalCount = 0,
  awaitingIstCount = 0,
  navLoading = false,
}) => {
  return (
    <aside className="sidebar">
      <div className="sidebar-mobile-bar">
        <span className="sidebar-mobile-title">Menu</span>
        <button
          type="button"
          className="sidebar-mobile-close"
          onClick={closeMobileNav}
          aria-label="Close menu"
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="20" height="20" aria-hidden>
            <polyline points="15 18 9 12 15 6" />
          </svg>
        </button>
      </div>

      {/* Brand */}
      <div className="sidebar-brand">
        <div className="header-brand">
          <img src="/images/air.png" alt="" className="header-brand-logo" />
          <div className="header-brand-text">
            <span className="brand-name">AIRKING</span>
            <span className="brand-subtitle">Air Conditioning</span>
          </div>
        </div>
      </div>

      {/* Navigation Menu */}
      <nav className="nav-menu" ref={navMenuRef}>
        {navLoading ? (
          <NavSkeleton />
        ) : menuSections.map((section, si) => {
          const renderItem = (item, i) => (
            <button
              key={i}
              className={`nav-item ${isItemActive(item) ? 'active' : ''}`}
              onClick={() => onNavClick(item)}
              onMouseEnter={() => onNavHover(item.path)}
            >
              <span className="nav-icon">{renderIcon(item.icon)}</span>
              <span className="nav-text">{item.name}</span>
              {item.path === '/admin/draft-po-creator' && pendingApprovalCount > 0 && (
                <span className="nav-item-badge">
                  {pendingApprovalCount > 99 ? '99+' : pendingApprovalCount}
                </span>
              )}
              {item.path === '/admin/ist' && awaitingIstCount > 0 && (
                <span className="nav-item-badge">
                  {awaitingIstCount > 99 ? '99+' : awaitingIstCount}
                </span>
              )}
            </button>
          );

          // A section with a single item needs no collapse control — render the
          // lone item directly so it always reads as a plain link, not a dropdown.
          if (section.items.length === 1) {
            return (
              <div key={si} className="nav-section nav-section--solo">
                {section.items.map(renderItem)}
              </div>
            );
          }

          const isExpanded = expandedSections.has(section.label);
          return (
            <div
              key={si}
              className={`nav-section ${isExpanded ? 'nav-section--expanded' : 'nav-section--collapsed'}`}
            >
              <button
                type="button"
                className="nav-section-header"
                onClick={() => onToggleSection(section.label)}
                aria-expanded={isExpanded}
              >
                <span className="nav-section-label">{section.label}</span>
                <svg
                  className="nav-section-toggle"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="2"
                  width="16"
                  height="16"
                  aria-hidden
                >
                  <polyline points="6 9 12 15 18 9" />
                </svg>
              </button>
              <div className="nav-section-items">
                {section.items.map(renderItem)}
              </div>
            </div>
          );
        })}
      </nav>

      {/* Logout Button */}
      <div className="sidebar-logout">
        <button className="logout-btn-sidebar" onClick={onLogout}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
            <polyline points="16 17 21 12 16 7"></polyline>
            <line x1="21" y1="12" x2="9" y2="12"></line>
          </svg>
          <span>Logout</span>
        </button>
      </div>
    </aside>
  );
};

export { adminMenuSections, prefetchRoute, avatarColor, renderIcon };
export default Sidebar;
