import React, { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { authAPI } from '../services/api';
import { getRoleKey, ROLE_META, ROLE_MENUS, ROLES } from '../utils/roles';
import { getUnreadNotificationCount } from '../utils/approvalNotifications';
import '../styles/dashboard_air.css';

// Route-to-chunk prefetch map — mirrors the lazy() imports in App.js
const routePrefetchMap = {
  '/admin/dashboard': () => import('../pages/Dashboard'),
  '/admin/analytics': () => import('../pages/AnalyticsDashboard'),
  '/admin/users': () => import('../pages/UserManagement'),
  '/admin/locations': () => import('../pages/LocationManagement'),
  '/admin/branches': () => import('../pages/LocationManagement'),
  '/admin/warehouses': () => import('../pages/LocationManagement'),
  '/admin/roles': () => import('../pages/RolePermissions'),
  '/admin/categories': () => import('../pages/CategoryBrandManagement'),
  '/admin/brands': () => import('../pages/CategoryBrandManagement'),
  '/admin/items': () => import('../pages/ItemManagement'),
  '/admin/suppliers': () => import('../pages/SupplierNetwork'),
  '/admin/po-recommendations': () => import('../pages/PORecommendation'),
  '/admin/draft-po-creator': () => import('../pages/DraftPOCreator'),
  '/admin/receivings': () => import('../pages/ReceivingManagement'),
  '/admin/inventory': () => import('../pages/InventoryManagement'),
  '/admin/transfers': () => import('../pages/StockTransferManagement'),
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
};

const prefetchedRoutes = new Set();
const prefetchRoute = (path) => {
  if (prefetchedRoutes.has(path) || !routePrefetchMap[path]) return;
  prefetchedRoutes.add(path);
  routePrefetchMap[path]();
};

/* Deterministic avatar colour from a string */
const AVATAR_COLORS = ['#dc2626','#d97706','#059669','#2563eb','#7c3aed','#db2777'];
const avatarColor = (str = '') => {
  let h = 0;
  for (let i = 0; i < str.length; i++) h = (h * 31 + str.charCodeAt(i)) | 0;
  return AVATAR_COLORS[Math.abs(h) % AVATAR_COLORS.length];
};

const AdminLayout = ({ children }) => {
  const navigate = useNavigate();
  const location = useLocation();
  const { user: _user, clearSession } = useAuth();
  const user = _user || {};

  // Role-based setup
  const roleKey = useMemo(() => getRoleKey(user), [user]);
  const roleMeta = ROLE_META[roleKey] || ROLE_META[ROLES.ADMIN];
  const roleAccent = roleMeta.accent;

  /* profile dropdown */
  const [profileOpen, setProfileOpen] = useState(false);
  const profileRef = useRef(null);
  const [unreadNotifications, setUnreadNotifications] = useState(0);
  const [mobileNavOpen, setMobileNavOpen] = useState(false);
  const [expandedSections, setExpandedSections] = useState(() => new Set());

  const closeMobileNav = useCallback(() => setMobileNavOpen(false), []);
  const toggleSection = useCallback((label) => {
    setExpandedSections((prev) => {
      const next = new Set(prev);
      if (next.has(label)) next.delete(label);
      else next.add(label);
      return next;
    });
  }, []);

  useEffect(() => {
    const mq = window.matchMedia('(max-width: 768px)');
    const onMq = () => {
      if (!mq.matches) setMobileNavOpen(false);
    };
    mq.addEventListener('change', onMq);
    return () => mq.removeEventListener('change', onMq);
  }, []);

  useEffect(() => {
    if (!mobileNavOpen) return;
    const onKey = (e) => {
      if (e.key === 'Escape') setMobileNavOpen(false);
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [mobileNavOpen]);

  useEffect(() => {
    if (!mobileNavOpen) return;
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.body.style.overflow = prev;
    };
  }, [mobileNavOpen]);

  useEffect(() => {
    const syncUnread = () => setUnreadNotifications(getUnreadNotificationCount());
    syncUnread();
    window.addEventListener('approval-notifications-updated', syncUnread);
    window.addEventListener('storage', syncUnread);
    return () => {
      window.removeEventListener('approval-notifications-updated', syncUnread);
      window.removeEventListener('storage', syncUnread);
    };
  }, []);

  useEffect(() => {
    if (!profileOpen) return;
    const handler = (e) => {
      if (profileRef.current && !profileRef.current.contains(e.target)) {
        setProfileOpen(false);
      }
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [profileOpen]);

  // Track the explicitly clicked nav item name to disambiguate items sharing the same path
  const [activeItemName, setActiveItemName] = useState(() => {
    return sessionStorage.getItem('activeNavItem') || '';
  });

  // Preload adjacent/common routes on mount for instant navigation
  useEffect(() => {
    const timer = setTimeout(() => {
      ['/admin/dashboard', '/admin/inventory',
       '/admin/po-recommendations', '/admin/draft-po-creator',
       '/admin/items', '/admin/transactions', '/admin/receivings', '/admin/purchase-orders'].forEach(prefetchRoute);
    }, 1000);
    return () => clearTimeout(timer);
  }, []);

  // Prefetch a route chunk on sidebar hover
  const handleNavHover = useCallback((path) => {
    prefetchRoute(path);
  }, []);

  // Determine if a nav item should be active: use explicit click tracking for duplicate paths,
  // fall back to pathname matching for unique paths (e.g. direct URL navigation)
  const isItemActive = useCallback((item) => {
    if (activeItemName) {
      return item.name === activeItemName && location.pathname === item.path;
    }
    return location.pathname === item.path;
  }, [activeItemName, location.pathname]);

  const handleNavClick = useCallback((item) => {
    setActiveItemName(item.name);
    sessionStorage.setItem('activeNavItem', item.name);
    navigate(item.path);
    if (typeof window !== 'undefined' && window.matchMedia('(max-width: 768px)').matches) {
      setMobileNavOpen(false);
    }
  }, [navigate]);

  const navMenuRef = useRef(null);

  const handleLogout = async () => {
    setMobileNavOpen(false);
    try { await authAPI.logout(); } catch (_) {}
    clearSession();
    navigate('/admin/login');
  };

  const handleNotificationsClick = () => {
    navigate('/admin/alerts');
  };

  // Full admin menu sections
  const adminMenuSections = [
    {
      label: 'ADMINISTRATION',
      items: [
        { name: 'Dashboard', path: '/admin/dashboard', icon: 'dashboard' },
        { name: 'Approval Queue', path: '/admin/approval-queue', icon: 'approval' },
        { name: 'Analytics Dashboard', path: '/admin/analytics', icon: 'analytics' },
        { name: 'User Management', path: '/admin/users', icon: 'users' },
        { name: 'Location Management', path: '/admin/branches', icon: 'location' },
        { name: 'System Configuration', path: '/admin/config', icon: 'config' },
        { name: 'Archive Management', path: '/admin/archive', icon: 'archive' },
      ],
    },
    {
      label: 'PROCUREMENT & PLANNING',
      items: [
        { name: 'PO Recommendation', path: '/admin/po-recommendations', icon: 'po' },
        { name: 'Draft PO Creator', path: '/admin/draft-po-creator', icon: 'procurement' },
        { name: 'PO Receiving', path: '/admin/receivings', icon: 'receiving' },
        { name: 'Supplier Network', path: '/admin/suppliers', icon: 'supplier' },
        { name: 'Item Master', path: '/admin/items', icon: 'items' },
        { name: 'Categories and Brands', path: '/admin/categories', icon: 'category' },
      ],
    },
    {
      label: 'INVENTORY INTELLIGENCE',
      items: [
        { name: 'Stock Movement', path: '/admin/inventory', icon: 'movement' },
      ],
    },
    {
      label: 'SALES & LOGISTICS',
      items: [
        { name: 'POS Operations', path: '/admin/transactions', icon: 'transactions' },
        { name: 'Transactions', path: '/admin/transactions', icon: 'globaltransactions' },
        { name: 'Purchase Orders', path: '/admin/purchase-orders', icon: 'purchaseorders' },
        { name: 'Customer Registry', path: '/admin/customers', icon: 'customers' },
        { name: 'Inventory Operation', path: '/admin/inventory-operation', icon: 'inventoryop' },
        { name: 'Inventory Monitoring', path: '/admin/inventory', icon: 'inventory' },
      ],
    },
    {
      label: 'COMPLIANCE & SUPPORT',
      items: [
        { name: 'Compliance Review', path: '/admin/compliance', icon: 'compliance' },
        { name: 'System Reports', path: '/admin/reports', icon: 'reports' },
        { name: 'Warranty Hub', path: '/admin/warranty', icon: 'warranty' },
        { name: 'Alerts & Notices', path: '/admin/alerts', icon: 'notifications' },
        { name: 'Audit Trail', path: '/admin/audit', icon: 'audit' },
      ],
    },
  ];

  // Use role-specific menu or fall back to full admin menu
  const menuSections = ROLE_MENUS[roleKey] || adminMenuSections;

  // Sync active highlight when navigating from outside the sidebar (e.g. dashboard shortcuts)
  useEffect(() => {
    const allItems = menuSections.flatMap((s) => s.items);
    const currentMatch = allItems.find(
      (item) => item.name === activeItemName && item.path === location.pathname
    );
    if (!currentMatch) {
      const firstMatch = allItems.find((item) => item.path === location.pathname);
      if (firstMatch) {
        setActiveItemName(firstMatch.name);
        sessionStorage.setItem('activeNavItem', firstMatch.name);
      } else {
        setActiveItemName('');
        sessionStorage.removeItem('activeNavItem');
      }
    }
  }, [location.pathname, menuSections, activeItemName]);

  // Auto-expand the section containing the active nav item
  useEffect(() => {
    const allItems = menuSections.flatMap((s) => s.items);
    const activeItem =
      allItems.find((item) => item.name === activeItemName && item.path === location.pathname) ||
      allItems.find((item) => item.path === location.pathname);
    if (activeItem) {
      const activeSection = menuSections.find((s) => s.items.includes(activeItem));
      if (activeSection) {
        setExpandedSections((prev) => {
          if (prev.has(activeSection.label)) return prev;
          return new Set([...prev, activeSection.label]);
        });
      }
    }
  }, [location.pathname, menuSections, activeItemName]);

  // Auto-scroll the sidebar so the active item is visible
  useEffect(() => {
    if (!navMenuRef.current) return;
    const activeEl = navMenuRef.current.querySelector('.nav-item.active');
    if (activeEl) {
      activeEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
  }, [activeItemName]);

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
      case 'barcode':
        return (
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
            <line x1="7" y1="8" x2="7" y2="16"></line>
            <line x1="11" y1="8" x2="11" y2="16"></line>
            <line x1="15" y1="8" x2="15" y2="16"></line>
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
      case 'profitloss':
        return (
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
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
      case 'warehouse-exec':
        return (
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
            <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
            <line x1="12" y1="22.08" x2="12" y2="12"></line>
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
      default:
        return null;
    }
  };

  // Location label for roles that are tied to a specific location
  const locationLabel = user?.location?.location_name || user?.branch?.branch_name || null;
  const showLocationChip = (roleKey === ROLES.BRANCH_MANAGER || roleKey === ROLES.WAREHOUSE_PERSONNEL) && locationLabel;

  return (
    <div
      className={`admin-shell${mobileNavOpen ? ' admin-shell--mobile-nav-open' : ''}`}
      data-role={roleKey}
      style={{ '--role-accent': roleAccent }}
    >
      <button
        type="button"
        className="mobile-sidebar-backdrop"
        aria-label="Close menu"
        onClick={closeMobileNav}
      />

      {/* Sidebar */}
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
        {/* User Profile Section */}
        <div className="sidebar-user-section">
          <div className="sidebar-user-avatar" style={{ background: avatarColor(user.first_name || user.username || 'S') }}>
            <span>
              {user.first_name && user.last_name
                ? user.first_name.charAt(0).toUpperCase() + user.last_name.charAt(0).toUpperCase()
                : (user.username ? user.username.charAt(0).toUpperCase() : 'S')}
            </span>
          </div>
          <div className="sidebar-user-info">
            <span className="sidebar-user-name">
              {user.first_name && user.last_name
                ? `${user.first_name} ${user.last_name}`
                : user.username || 'System Admin'}
            </span>
            <span className="sidebar-user-role">{roleMeta.label}</span>
            {showLocationChip && (
              <span className="sidebar-location-chip">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="10" height="10">
                  <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                  <circle cx="12" cy="10" r="3"/>
                </svg>
                {locationLabel}
              </span>
            )}
          </div>
        </div>

        {/* Navigation Menu */}
        <nav className="nav-menu" ref={navMenuRef}>
          {menuSections.map((section, si) => {
            const isExpanded = expandedSections.has(section.label);
            return (
              <div
                key={si}
                className={`nav-section ${isExpanded ? 'nav-section--expanded' : 'nav-section--collapsed'}`}
              >
                <button
                  type="button"
                  className="nav-section-header"
                  onClick={() => toggleSection(section.label)}
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
                  {section.items.map((item, i) => (
                    <button
                      key={i}
                      className={`nav-item ${isItemActive(item) ? 'active' : ''}`}
                      onClick={() => handleNavClick(item)}
                      onMouseEnter={() => handleNavHover(item.path)}
                    >
                      <span className="nav-icon">{renderIcon(item.icon)}</span>
                      <span className="nav-text">{item.name}</span>
                    </button>
                  ))}
                </div>
              </div>
            );
          })}
        </nav>

        {/* Logout Button */}
        <div className="sidebar-logout">
          <button className="logout-btn-sidebar" onClick={handleLogout}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
              <polyline points="16 17 21 12 16 7"></polyline>
              <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
            <span>Logout</span>
          </button>
        </div>
      </aside>

      {/* Main Content */}
      <div className="content-wrapper">
        {/* Header */}
        <header className="top-header">
          <button
            type="button"
            className="mobile-nav-trigger"
            onClick={() => setMobileNavOpen(true)}
            aria-label="Open navigation menu"
            aria-expanded={mobileNavOpen}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="22" height="22" aria-hidden>
              <line x1="3" y1="6" x2="21" y2="6" />
              <line x1="3" y1="12" x2="21" y2="12" />
              <line x1="3" y1="18" x2="21" y2="18" />
            </svg>
          </button>
          <div className="header-brand">
            <img src="/images/air.png" alt="" className="header-brand-logo" />
            <div className="header-brand-text">
              <span className="brand-name">AIRKING</span>
              <span className="brand-subtitle">Air Conditioning</span>
            </div>
          </div>

          <div className="header-actions">
            {roleKey === ROLES.ADMIN && (
              <button type="button" className="notification-btn" onClick={handleNotificationsClick} aria-label="Alerts and notices">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                  <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                </svg>
                {unreadNotifications > 0 && (
                  <span className="notification-badge">{unreadNotifications}</span>
                )}
              </button>
            )}
            <div className="header-user-profile" ref={profileRef} onClick={() => setProfileOpen(o => !o)}>
              <div
                className="header-user-avatar"
                style={{ background: avatarColor(user.first_name || user.username || 'S'), color: '#fff' }}
              >
                <span style={{ fontSize: 13, fontWeight: 700, lineHeight: 1 }}>
                  {user.first_name && user.last_name
                    ? user.first_name.charAt(0).toUpperCase() + user.last_name.charAt(0).toUpperCase()
                    : (user.username ? user.username.charAt(0).toUpperCase() : 'S')}
                </span>
              </div>
              <div className="header-user-info">
                <span className="header-user-name">
                  {user?.email || user?.username || 'Loading...'}
                </span>
                <span className="header-user-role">
                  {user?.role?.role_name || user?.role || 'System Administrator'}
                </span>
              </div>
              <svg className={`hup-caret${profileOpen ? ' open' : ''}`} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                <polyline points="6 9 12 15 18 9" />
              </svg>

              {profileOpen && (
                <div className="header-profile-dropdown" onClick={(e) => e.stopPropagation()}>
                  {/* Identity block */}
                  <div className="hpd-header">
                    <div className="hpd-avatar" style={{ background: avatarColor(user.first_name || user.username || 'S') }}>
                      {user.first_name && user.last_name
                        ? user.first_name.charAt(0).toUpperCase() + user.last_name.charAt(0).toUpperCase()
                        : (user.username ? user.username.charAt(0).toUpperCase() : 'S')}
                    </div>
                    <div className="hpd-identity">
                      <div className="hpd-fullname">
                        {user.first_name && user.last_name
                          ? `${user.first_name} ${user.last_name}`
                          : user.username || 'System Admin'}
                      </div>
                      <div className="hpd-email">{user.email || '—'}</div>
                    </div>
                  </div>

                  {/* Role badge */}
                  <div className="hpd-role-row">
                    <span className="hpd-role-badge">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="12" height="12">
                        <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                      </svg>
                      {user.role?.role_name || user.role || 'System Administrator'}
                    </span>
                  </div>

                  {/* Logout */}
                  <button className="hpd-logout" onClick={handleLogout}>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                      <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                      <polyline points="16 17 21 12 16 7"/>
                      <line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                    Logout
                  </button>
                </div>
              )}
            </div>
          </div>
        </header>

        <main className="page-content">
          {children}
        </main>
      </div>
    </div>
  );
};

export default AdminLayout;
