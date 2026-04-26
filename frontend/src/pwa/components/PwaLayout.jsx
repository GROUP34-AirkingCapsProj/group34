import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useSupabaseAuth } from '../context/SupabaseAuthContext';

export default function PwaLayout() {
  const navigate = useNavigate();
  const { signOut, user } = useSupabaseAuth();

  return (
    <div className="pwa-shell">
      <header className="pwa-header">
        <div className="pwa-header-inner">
          <span className="pwa-brand">AirKing · Inventory</span>
          <button
            type="button"
            className="pwa-header-signout"
            onClick={async () => {
              await signOut();
              navigate('/pwa/login', { replace: true });
            }}
          >
            Sign out
          </button>
        </div>
        {user?.email ? <p className="pwa-header-email">{user.email}</p> : null}
      </header>

      <main className="pwa-main">
        <Outlet />
      </main>

      <nav className="pwa-nav" aria-label="Primary">
        <NavLink to="/pwa/dashboard" className={({ isActive }) => (isActive ? 'active' : '')}>
          <span className="pwa-nav-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
              <polyline points="9 22 9 12 15 12 15 22" />
            </svg>
          </span>
          Home
        </NavLink>
        <NavLink to="/pwa/scan" className={({ isActive }) => (isActive ? 'active' : '')}>
          <span className="pwa-nav-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M3 7V5a2 2 0 0 1 2-2h2" />
              <path d="M17 3h2a2 2 0 0 1 2 2v2" />
              <path d="M21 17v2a2 2 0 0 1-2 2h-2" />
              <path d="M7 21H5a2 2 0 0 1-2-2v-2" />
              <line x1="7" y1="12" x2="17" y2="12" />
            </svg>
          </span>
          Scan
        </NavLink>
        <NavLink to="/pwa/products" className={({ isActive }) => (isActive ? 'active' : '')}>
          <span className="pwa-nav-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z" />
              <line x1="3" y1="6" x2="21" y2="6" />
              <path d="M16 10a4 4 0 0 1-8 0" />
            </svg>
          </span>
          Products
        </NavLink>
      </nav>
    </div>
  );
}
