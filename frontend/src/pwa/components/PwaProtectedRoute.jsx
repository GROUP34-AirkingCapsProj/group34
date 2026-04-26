import { Navigate, useLocation } from 'react-router-dom';
import { useSupabaseAuth } from '../context/SupabaseAuthContext';

export default function PwaProtectedRoute({ children }) {
  const { configured, loading, session } = useSupabaseAuth();
  const loc = useLocation();

  if (!configured) {
    return (
      <div className="pwa-config-missing">
        <h1>Supabase not configured</h1>
        <p>
          Add <code>REACT_APP_SUPABASE_URL</code> and <code>REACT_APP_SUPABASE_ANON_KEY</code> to your
          environment, then rebuild.
        </p>
      </div>
    );
  }

  if (loading) {
    return (
      <div className="pwa-loading">
        <div className="pwa-spinner" aria-hidden="true" />
        <p>Loading session…</p>
      </div>
    );
  }

  if (!session) {
    return <Navigate to="/pwa/login" replace state={{ from: loc.pathname }} />;
  }

  return children;
}
