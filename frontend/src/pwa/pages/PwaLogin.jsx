import { useState } from 'react';
import { Link, Navigate, useLocation } from 'react-router-dom';
import { useSupabaseAuth } from '../context/SupabaseAuthContext';
import '../styles/pwa.css';

export default function PwaLogin() {
  const { configured, loading, session, signIn, signUp } = useSupabaseAuth();
  const loc = useLocation();
  const from = loc.state?.from || '/pwa/dashboard';

  const [mode, setMode] = useState('signin');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [fullName, setFullName] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [info, setInfo] = useState('');

  if (!configured && !loading) {
    return (
      <div className="pwa-login-page">
        <div className="pwa-login-card">
          <h1>Supabase required</h1>
          <p>
            Set <code>REACT_APP_SUPABASE_URL</code> and <code>REACT_APP_SUPABASE_ANON_KEY</code> in{' '}
            <code>.env</code>, then restart the dev server.
          </p>
        </div>
      </div>
    );
  }

  if (loading) {
    return (
      <div className="pwa-loading">
        <div className="pwa-spinner" />
        <p>Loading…</p>
      </div>
    );
  }

  if (session) {
    return <Navigate to={from} replace />;
  }

  const onSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setInfo('');
    setBusy(true);
    try {
      if (mode === 'signin') {
        const { error: err } = await signIn(email.trim(), password);
        if (err) setError(err.message || 'Sign in failed.');
      } else {
        const { error: err } = await signUp(email.trim(), password, fullName.trim());
        if (err) setError(err.message || 'Sign up failed.');
        else setInfo('Check your email to confirm your account (if confirmation is enabled in Supabase).');
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="pwa-login-page">
      <div className="pwa-login-card">
        <h1>Inventory PWA</h1>
        <p>Sign in with your Supabase account to scan and manage stock.</p>

        {error ? <div className="pwa-login-error">{error}</div> : null}
        {info ? <div className="pwa-scan-result">{info}</div> : null}

        <form onSubmit={onSubmit}>
          {mode === 'signup' ? (
            <>
              <label className="pwa-label" htmlFor="pwa-fullname">
                Full name
              </label>
              <input
                id="pwa-fullname"
                className="pwa-input"
                value={fullName}
                onChange={(e) => setFullName(e.target.value)}
                autoComplete="name"
                placeholder="Optional"
              />
            </>
          ) : null}

          <label className="pwa-label" htmlFor="pwa-email">
            Email
          </label>
          <input
            id="pwa-email"
            className="pwa-input"
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            autoComplete="email"
            required
          />

          <label className="pwa-label" htmlFor="pwa-password">
            Password
          </label>
          <input
            id="pwa-password"
            className="pwa-input"
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoComplete={mode === 'signin' ? 'current-password' : 'new-password'}
            minLength={6}
            required
          />

          <button type="submit" className="pwa-btn pwa-btn-primary" disabled={busy}>
            {busy ? 'Please wait…' : mode === 'signin' ? 'Sign in' : 'Create account'}
          </button>
        </form>

        <button
          type="button"
          className="pwa-btn pwa-btn-secondary"
          style={{ marginTop: 10 }}
          onClick={() => {
            setMode(mode === 'signin' ? 'signup' : 'signin');
            setError('');
            setInfo('');
          }}
        >
          {mode === 'signin' ? 'Need an account? Sign up' : 'Have an account? Sign in'}
        </button>

        <p className="pwa-muted" style={{ marginTop: 20, textAlign: 'center' }}>
          <Link to="/admin/login">← Back to admin (Laravel) login</Link>
        </p>
      </div>
    </div>
  );
}
