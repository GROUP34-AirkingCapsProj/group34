import React, { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useAuth, useUserLocation } from '../context/AuthContext';
import { authAPI, layoutAPI } from '../services/api';
import { getRoleKey, ROLE_META, ROLES } from '../utils/roles';
import { buildSidebar } from '../utils/sidebarBuilder';
import Sidebar, { adminMenuSections, prefetchRoute, avatarColor } from '../components/Sidebar';
import LocationBadge from '../components/LocationBadge';
import '../styles/dashboard_air.css';
import '../styles/airking_ds.css';

const AdminLayout = ({ children }) => {
  const navigate = useNavigate();
  const location = useLocation();
  const { user: _user, clearSession, permissions, refreshPermissions } = useAuth();
  const user = _user || {};
  const { locationName: userLocationName } = useUserLocation();

  // Centralized roles: admin=1, inventory_analyst=2, auditor=5
  // Scoped roles: branch_manager=3, warehouse_personnel=4
  const isCentralized = [1, 2, 5].includes(Number(user?.role?.role_id));

  // Role-based setup
  const roleKey = useMemo(() => getRoleKey(user), [user]);
  const roleMeta = ROLE_META[roleKey] || ROLE_META[ROLES.ADMIN];
  const roleAccent = roleMeta.accent;

  const [profileOpen, setProfileOpen] = useState(false);
  const profileRef = useRef(null);
  const [unreadNotifications, setUnreadNotifications] = useState(0);
  const [pendingApprovalCount, setPendingApprovalCount] = useState(0);
  const [awaitingIstCount, setAwaitingIstCount] = useState(0);
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

  // Close sidebar on desktop resize
  useEffect(() => {
    const mq = window.matchMedia('(max-width: 768px)');
    const onMq = () => { if (!mq.matches) setMobileNavOpen(false); };
    mq.addEventListener('change', onMq);
    return () => mq.removeEventListener('change', onMq);
  }, []);

  // Close sidebar on Escape
  useEffect(() => {
    if (!mobileNavOpen) return;
    const onKey = (e) => { if (e.key === 'Escape') setMobileNavOpen(false); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [mobileNavOpen]);

  // Lock body scroll when mobile nav is open
  useEffect(() => {
    if (!mobileNavOpen) return;
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => { document.body.style.overflow = prev; };
  }, [mobileNavOpen]);

  // One request feeds all header badges (unread, pending approvals, awaiting IST)
  useEffect(() => {
    let mounted = true;
    const syncBadges = async () => {
      try {
        const res = await layoutAPI.getSummary();
        const d = res?.data ?? {};
        if (!mounted) return;
        setUnreadNotifications(Number(d.badge_count ?? 0));
        setPendingApprovalCount(Number(d.total_pending ?? 0));
        setAwaitingIstCount(Number(d.awaiting_fulfillment ?? 0) + Number(d.awaiting_receipt ?? 0));
      } catch (_) {
        // leave existing counts on transient failure
      }
    };
    syncBadges();
    const interval = setInterval(syncBadges, 60000);
    window.addEventListener('approval-notifications-updated', syncBadges);
    window.addEventListener('storage', syncBadges);
    return () => {
      mounted = false;
      clearInterval(interval);
      window.removeEventListener('approval-notifications-updated', syncBadges);
      window.removeEventListener('storage', syncBadges);
    };
  }, []);

  // Close profile dropdown on outside click
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

  const [activeItemName, setActiveItemName] = useState(() => {
    return sessionStorage.getItem('activeNavItem') || '';
  });

  // Prefetch common routes on mount
  useEffect(() => {
    const timer = setTimeout(() => {
      [
        '/admin/dashboard',
        '/admin/inventory',
        '/admin/po-recommendations',
        '/admin/draft-po-creator',
        '/admin/items',
        '/admin/transactions',
        '/admin/receivings',
        '/admin/purchase-orders',
      ].forEach(prefetchRoute);
    }, 1000);
    return () => clearTimeout(timer);
  }, []);

  const handleNavHover = useCallback((path) => {
    prefetchRoute(path);
  }, []);

  const isItemActive = useCallback(
    (item) => {
      if (activeItemName) {
        return item.name === activeItemName && location.pathname === item.path;
      }
      return location.pathname === item.path;
    },
    [activeItemName, location.pathname]
  );

  const handleNavClick = useCallback(
    (item) => {
      setActiveItemName(item.name);
      sessionStorage.setItem('activeNavItem', item.name);
      navigate(item.path);
      if (typeof window !== 'undefined' && window.matchMedia('(max-width: 768px)').matches) {
        setMobileNavOpen(false);
      }
    },
    [navigate]
  );

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

  // Permission-driven sidebar: keep items whose nav bundle is granted,
  // falling back to role check for unbundled pages, honouring adminOnly.
  const permissionsLoading = !!_user?.user_id && permissions == null;
  const menuSections = useMemo(
    () => buildSidebar(adminMenuSections, permissions || {}, user),
    [permissions, user]
  );

  // Sync active highlight when navigating from outside the sidebar
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

  // Auto-scroll sidebar to the active item
  useEffect(() => {
    if (!navMenuRef.current) return;
    const activeEl = navMenuRef.current.querySelector('.nav-item.active');
    if (activeEl) activeEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }, [activeItemName]);

  // Re-sync permissions on navigation so mid-session grants/revokes apply
  useEffect(() => {
    if (!_user?.user_id) return;
    refreshPermissions();
  }, [location.pathname]); // eslint-disable-line react-hooks/exhaustive-deps

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
      <Sidebar
        mobileNavOpen={mobileNavOpen}
        closeMobileNav={closeMobileNav}
        activeItemName={activeItemName}
        onNavClick={handleNavClick}
        onNavHover={handleNavHover}
        isItemActive={isItemActive}
        menuSections={menuSections}
        expandedSections={expandedSections}
        onToggleSection={toggleSection}
        navMenuRef={navMenuRef}
        user={user}
        roleKey={roleKey}
        roleMeta={roleMeta}
        onLogout={handleLogout}
        pendingApprovalCount={pendingApprovalCount}
        awaitingIstCount={awaitingIstCount}
        navLoading={permissionsLoading}
      />

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
            <LocationBadge
              isCentralized={isCentralized}
              locationName={userLocationName}
              className="header-location-badge"
            />
            {(roleKey === ROLES.ADMIN
              || roleKey === ROLES.BRANCH_MANAGER
              || roleKey === ROLES.INVENTORY_ANALYST) && (
              <button
                type="button"
                className="notification-btn"
                onClick={handleNotificationsClick}
                aria-label="Alerts and notices"
              >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                  <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                </svg>
                {unreadNotifications > 0 && (
                  <span className="notification-badge">{unreadNotifications}</span>
                )}
              </button>
            )}
            <div
              className="header-user-profile"
              ref={profileRef}
              onClick={() => setProfileOpen((o) => !o)}
            >
              <div
                className="header-user-avatar"
                style={{ background: avatarColor(user.first_name || user.username || 'S'), color: '#fff' }}
              >
                <span style={{ fontSize: 13, fontWeight: 700, lineHeight: 1 }}>
                  {user.first_name && user.last_name
                    ? user.first_name.charAt(0).toUpperCase() + user.last_name.charAt(0).toUpperCase()
                    : user.username
                    ? user.username.charAt(0).toUpperCase()
                    : 'S'}
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
              <svg
                className={`hup-caret${profileOpen ? ' open' : ''}`}
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2.5"
              >
                <polyline points="6 9 12 15 18 9" />
              </svg>

              {profileOpen && (
                <div className="header-profile-dropdown" onClick={(e) => e.stopPropagation()}>
                  <div className="hpd-header">
                    <div
                      className="hpd-avatar"
                      style={{ background: avatarColor(user.first_name || user.username || 'S') }}
                    >
                      {user.first_name && user.last_name
                        ? user.first_name.charAt(0).toUpperCase() + user.last_name.charAt(0).toUpperCase()
                        : user.username
                        ? user.username.charAt(0).toUpperCase()
                        : 'S'}
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

                  <div className="hpd-role-row">
                    <span className="hpd-role-badge">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="12" height="12">
                        <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
                      </svg>
                      {user.role?.role_name || user.role || 'System Administrator'}
                    </span>
                  </div>

                  <button className="hpd-logout" onClick={handleLogout}>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                      <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                      <polyline points="16 17 21 12 16 7" />
                      <line x1="21" y1="12" x2="9" y2="12" />
                    </svg>
                    Logout
                  </button>
                </div>
              )}
            </div>
          </div>
        </header>

        <main className="page-content">{children}</main>
      </div>
    </div>
  );
};

export default AdminLayout;