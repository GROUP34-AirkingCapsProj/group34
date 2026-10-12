import React, { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useAuth, useUserLocation } from '../context/AuthContext';
import { authAPI, layoutAPI } from '../services/api';
import ProfilePhoto from './ProfilePhoto';
import { getRoleKey, ROLE_META, ROLES } from '../utils/roles';
import { channelFor, REALTIME_EVENTS } from '../utils/realtimeChannel';
import { playNotifySound, initNotifySound } from '../utils/notifySound';
import { shouldChimeForBadge, hasNewWarehouseNotification } from '../utils/badgeChime';
import { buildSidebar } from '../utils/sidebarBuilder';
import Sidebar, { adminMenuSections, prefetchRoute, avatarColor } from '../components/Sidebar';
import LocationBadge from '../components/LocationBadge';
import ThemeToggle from '../components/ThemeToggle';
import '../styles/dashboard_air.css';
import '../styles/airking_ds.css';
import '../styles/theme_dark.css';

const AdminLayout = ({ children }) => {
  const navigate = useNavigate();
  const location = useLocation();
  const { user: _user, clearSession, permissions, can } = useAuth();
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
  const prevBadgeRef = useRef(0);
  const previousWarehouseIds = useRef([]);
  // Whether prevBadgeRef holds a real reading yet. The first summary response
  // describes what was already waiting when the page opened, which is not news
  // and must not chime -- without this, every page load with unread
  // notifications would ring.
  const badgeBaselineRef = useRef(false);
  const [pendingPrCount, setPendingPrCount] = useState(0);
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
        const nextBadge = Number(d.badge_count ?? 0);

        // The chime used to live only on the Reverb path, so deployments
        // without a WebSocket server were silent however many notifications
        // arrived -- the badge moved and nothing else did. Ringing on a rise
        // here is what actually makes the comment below ("falls back to the
        // polling fallback") true of the sound and not just the counts.
        //
        // See badgeChime.js for why a rise is the only case that rings.
        if (shouldChimeForBadge(prevBadgeRef.current, nextBadge, badgeBaselineRef.current)
            || hasNewWarehouseNotification(previousWarehouseIds.current, d.warehouse_unread_ids, badgeBaselineRef.current)) {
          playNotifySound();
        }
        badgeBaselineRef.current = true;
        previousWarehouseIds.current = d.warehouse_unread_ids ?? [];

        setUnreadNotifications(nextBadge);
        prevBadgeRef.current = nextBadge;
        setPendingPrCount(Number(d.pending_pr_count ?? 0));
        setAwaitingIstCount(Number(d.awaiting_fulfillment ?? 0) + Number(d.awaiting_receipt ?? 0));
      } catch (_) {
        // leave existing counts on transient failure
      }
    };
    syncBadges();
    const interval = setInterval(syncBadges, 15000);
    window.addEventListener('focus', syncBadges);
    window.addEventListener('approval-notifications-updated', syncBadges);
    window.addEventListener('storage', syncBadges);
    return () => {
      mounted = false;
      clearInterval(interval);
      window.removeEventListener('focus', syncBadges);
      window.removeEventListener('approval-notifications-updated', syncBadges);
      window.removeEventListener('storage', syncBadges);
    };
  }, []);

  // Realtime: defer Echo/Pusher until the browser is idle so dashboard APIs
  // win the first-paint race. Falls back to the 15s poll above when Reverb is
  // down -- for the chime as well as the counts, which is only true since the
  // poll learned to ring on a rise. Deployed builds have no
  // REACT_APP_REVERB_APP_KEY, so getEcho() returns null and this path never
  // runs there; the poll is the whole notification story in production.
  useEffect(() => {
    if (!can('notification_panel', 'read')) return;
    const channelName = channelFor(user);
    if (!channelName) return;

    let cancelled = false;
    let burstTimer = null;
    let channel;
    let echo;
    let idleId;

    const refreshAndChime = async () => {
      try {
        const res = await layoutAPI.getSummary();
        const d = res?.data ?? {};
        if (cancelled) return;
        const nextBadge = Number(d.badge_count ?? 0);
        if (shouldChimeForBadge(prevBadgeRef.current, nextBadge, badgeBaselineRef.current)
            || hasNewWarehouseNotification(previousWarehouseIds.current, d.warehouse_unread_ids, badgeBaselineRef.current)) {
          playNotifySound();
        }
        previousWarehouseIds.current = d.warehouse_unread_ids ?? [];
        setUnreadNotifications(Number(d.badge_count ?? 0));
        // Also marks the baseline as taken, so the next poll compares against
        // this reading and does not chime a second time for the same event.
        prevBadgeRef.current = Number(d.badge_count ?? 0);
        badgeBaselineRef.current = true;
        setPendingPrCount(Number(d.pending_pr_count ?? 0));
        setAwaitingIstCount(Number(d.awaiting_fulfillment ?? 0) + Number(d.awaiting_receipt ?? 0));
        // Refresh the open alerts page too, without chiming for unrelated edits.
        window.dispatchEvent(new CustomEvent('warehouse-notifications-updated'));
      } catch (_) {
        // ignore — poll will reconcile
      }
    };

    const onEvent = () => {
      if (burstTimer) clearTimeout(burstTimer);
      burstTimer = setTimeout(refreshAndChime, 300);
    };

    const subscribe = async () => {
      try {
        const mod = await import('../utils/echo');
        if (cancelled) return;
        echo = mod.getEcho();
        if (!echo) return;
        channel = echo.private(channelName);
        REALTIME_EVENTS.forEach((evt) => channel.listen(evt, onEvent));
      } catch (_) {
        // poll fallback remains
      }
    };

    if (typeof window !== 'undefined' && 'requestIdleCallback' in window) {
      idleId = window.requestIdleCallback(subscribe, { timeout: 4000 });
    } else {
      idleId = setTimeout(subscribe, 2000);
    }

    return () => {
      cancelled = true;
      if (burstTimer) clearTimeout(burstTimer);
      if (typeof window !== 'undefined' && 'cancelIdleCallback' in window && typeof idleId === 'number') {
        try { window.cancelIdleCallback(idleId); } catch (_) { /* ignore */ }
      } else {
        clearTimeout(idleId);
      }
      try {
        REALTIME_EVENTS.forEach((evt) => channel && channel.stopListening(evt));
        if (echo && channelName) echo.leave(channelName);
      } catch (_) {
        // ignore
      }
    };
  }, [user, can]);

  // Unlock audio on the first tap/click so socket-triggered chimes can play on mobile.
  useEffect(() => { initNotifySound(); }, []);

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

  // Soft prefetch: only a couple of likely next pages, after idle — hover still prefetches.
  useEffect(() => {
    const run = () => {
      ['/inventory', '/draft-po-creator'].forEach(prefetchRoute);
    };
    let idleId;
    if (typeof window !== 'undefined' && 'requestIdleCallback' in window) {
      idleId = window.requestIdleCallback(run, { timeout: 8000 });
      return () => {
        try { window.cancelIdleCallback(idleId); } catch (_) { /* ignore */ }
      };
    }
    idleId = setTimeout(run, 5000);
    return () => clearTimeout(idleId);
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
    navigate('/login');
  };

  const handleNotificationsClick = () => {
    navigate('/alerts');
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
        pendingPrCount={pendingPrCount}
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
          <div className="header-actions">
            <LocationBadge
              isCentralized={isCentralized}
              locationName={userLocationName}
              className="header-location-badge"
            />
            <ThemeToggle />
            {can('notification_panel', 'read') && (
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
                style={{ background: avatarColor(user.first_name || user.username || 'S'), color: '#fff', overflow: 'hidden' }}
              >
                {user.avatar_url ? (
                  <ProfilePhoto src={user.avatar_url} alt="Your profile photo" fallback={(user.first_name?.charAt(0) || user.username?.charAt(0) || 'S').toUpperCase()}
                    style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                ) : (
                  <span style={{ fontSize: 13, fontWeight: 700, lineHeight: 1 }}>
                    {user.first_name && user.last_name
                      ? user.first_name.charAt(0).toUpperCase() + user.last_name.charAt(0).toUpperCase()
                      : user.username
                      ? user.username.charAt(0).toUpperCase()
                      : 'S'}
                  </span>
                )}
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
                      style={{ background: avatarColor(user.first_name || user.username || 'S'), overflow: 'hidden' }}
                    >
                      {user.avatar_url ? (
                        <ProfilePhoto src={user.avatar_url} alt="Your profile photo" fallback={(user.first_name?.charAt(0) || user.username?.charAt(0) || 'S').toUpperCase()}
                          style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                      ) : (
                        user.first_name && user.last_name
                          ? user.first_name.charAt(0).toUpperCase() + user.last_name.charAt(0).toUpperCase()
                          : user.username
                          ? user.username.charAt(0).toUpperCase()
                          : 'S'
                      )}
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

                  <button
                    className="hpd-logout"
                    style={{ marginBottom: 8 }}
                    onClick={() => { setProfileOpen(false); navigate('/account'); }}
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                      <circle cx="12" cy="12" r="3" />
                      <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
                    </svg>
                    Account Settings
                  </button>
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
