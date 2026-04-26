import { useCallback, useEffect, useState } from 'react';
import { useSupabaseAuth } from '../context/SupabaseAuthContext';
import { listPendingScans, clearPendingScans } from '../utils/offlineScanQueue';
import '../styles/pwa.css';

const LOW_STOCK_MAX = 5;

export default function PwaDashboard() {
  const { client, user } = useSupabaseAuth();
  const [total, setTotal] = useState(null);
  const [lowStock, setLowStock] = useState(null);
  const [recent, setRecent] = useState([]);
  const [pending, setPending] = useState([]);
  const [loading, setLoading] = useState(true);
  const [err, setErr] = useState('');

  const load = useCallback(async () => {
    if (!client) return;
    setErr('');
    setLoading(true);
    try {
      const { count: c1, error: e1 } = await client.from('products').select('*', { count: 'exact', head: true });
      if (e1) throw e1;
      setTotal(c1 ?? 0);

      const { count: c2, error: e2 } = await client
        .from('products')
        .select('*', { count: 'exact', head: true })
        .lte('quantity', LOW_STOCK_MAX);
      if (e2) throw e2;
      setLowStock(c2 ?? 0);

      const { data: scans, error: e3 } = await client
        .from('scan_events')
        .select('id, barcode, action, created_at, products(name)')
        .order('created_at', { ascending: false })
        .limit(12);
      if (e3) throw e3;
      setRecent(scans || []);
    } catch (e) {
      setErr(e.message || 'Could not load dashboard.');
    } finally {
      setLoading(false);
    }
  }, [client]);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    setPending(listPendingScans());
    const onOnline = () => load();
    window.addEventListener('online', onOnline);
    return () => window.removeEventListener('online', onOnline);
  }, [load]);

  useEffect(() => {
    if (!client || !user) return undefined;
    const channel = client
      .channel('pwa-dashboard')
      .on(
        'postgres_changes',
        { event: '*', schema: 'public', table: 'products' },
        () => load()
      )
      .on(
        'postgres_changes',
        { event: 'INSERT', schema: 'public', table: 'scan_events' },
        () => load()
      )
      .subscribe();
    return () => {
      client.removeChannel(channel);
    };
  }, [client, user, load]);

  const clearQueue = () => {
    clearPendingScans();
    setPending([]);
  };

  return (
    <div>
      <h1 style={{ fontSize: '1.25rem', margin: '0 0 16px', color: '#111827' }}>Dashboard</h1>

      {err ? <div className="pwa-login-error">{err}</div> : null}

      <div className="pwa-card">
        <h2>Overview</h2>
        {loading ? (
          <p className="pwa-muted">Loading…</p>
        ) : (
          <div className="pwa-stats">
            <div className="pwa-stat">
              <div className="pwa-stat-value">{total ?? '—'}</div>
              <div className="pwa-stat-label">Products</div>
            </div>
            <div className="pwa-stat warn">
              <div className="pwa-stat-value">{lowStock ?? '—'}</div>
              <div className="pwa-stat-label">Low stock (≤{LOW_STOCK_MAX})</div>
            </div>
            <div className="pwa-stat">
              <div className="pwa-stat-value">{pending.length}</div>
              <div className="pwa-stat-label">Offline queue</div>
            </div>
          </div>
        )}
      </div>

      {pending.length > 0 ? (
        <div className="pwa-card">
          <h2>Pending offline scans</h2>
          <p className="pwa-muted" style={{ marginBottom: 10 }}>
            These barcodes were captured while offline. Open <strong>Scan</strong> when online to process them, or
            clear the list.
          </p>
          <ul className="pwa-list">
            {pending.map((p) => (
              <li key={p.id}>
                <span>{p.barcode}</span>
                <span className="pwa-muted">{new Date(p.createdAt).toLocaleString()}</span>
              </li>
            ))}
          </ul>
          <button type="button" className="pwa-btn pwa-btn-secondary" onClick={clearQueue}>
            Clear queue
          </button>
        </div>
      ) : null}

      <div className="pwa-card">
        <h2>Recent scans</h2>
        {recent.length === 0 ? (
          <p className="pwa-muted">No scans yet. Use the Scan tab.</p>
        ) : (
          <ul className="pwa-list">
            {recent.map((row) => (
              <li key={row.id}>
                <div>
                  <strong>{row.barcode}</strong>
                  <div className="pwa-muted">{row.products?.name || '—'}</div>
                </div>
                <div style={{ textAlign: 'right' }}>
                  <span className={`pwa-pill ${row.action === 'created' ? 'created' : 'found'}`}>{row.action}</span>
                  <div className="pwa-muted" style={{ marginTop: 4 }}>
                    {row.created_at ? new Date(row.created_at).toLocaleString() : ''}
                  </div>
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
