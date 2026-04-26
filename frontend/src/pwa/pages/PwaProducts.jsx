import { useCallback, useEffect, useMemo, useState } from 'react';
import { useSupabaseAuth } from '../context/SupabaseAuthContext';
import '../styles/pwa.css';

export default function PwaProducts() {
  const { client } = useSupabaseAuth();
  const [rows, setRows] = useState([]);
  const [q, setQ] = useState('');
  const [loading, setLoading] = useState(true);
  const [err, setErr] = useState('');
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState({ name: '', quantity: '', price: '', category: '' });
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    if (!client) return;
    setLoading(true);
    setErr('');
    try {
      let query = client.from('products').select('*').order('created_at', { ascending: false }).limit(500);
      const term = q.trim();
      if (term) {
        const esc = term.replace(/%/g, '\\%').replace(/_/g, '\\_');
        query = query.or(`name.ilike.%${esc}%,barcode.ilike.%${esc}%`);
      }
      const { data, error } = await query;
      if (error) throw error;
      setRows(data || []);
    } catch (e) {
      setErr(e.message || 'Failed to load products.');
    } finally {
      setLoading(false);
    }
  }, [client, q]);

  useEffect(() => {
    const t = setTimeout(load, 280);
    return () => clearTimeout(t);
  }, [load]);

  const openEdit = (p) => {
    setEditing(p);
    setForm({
      name: p.name || '',
      quantity: String(p.quantity ?? 0),
      price: String(p.price ?? 0),
      category: p.category || '',
    });
  };

  const closeEdit = () => {
    setEditing(null);
  };

  const saveEdit = async (e) => {
    e.preventDefault();
    if (!client || !editing) return;
    setSaving(true);
    setErr('');
    try {
      const qty = Math.max(0, parseInt(form.quantity, 10) || 0);
      const price = Math.max(0, parseFloat(form.price) || 0);
      const { error } = await client
        .from('products')
        .update({
          name: form.name.trim() || `Item ${editing.barcode}`,
          quantity: qty,
          price,
          category: form.category.trim() || 'Uncategorized',
        })
        .eq('id', editing.id);
      if (error) throw error;
      closeEdit();
      load();
    } catch (e2) {
      setErr(e2.message || 'Save failed.');
    } finally {
      setSaving(false);
    }
  };

  const countLabel = useMemo(() => `${rows.length} loaded`, [rows.length]);

  return (
    <div>
      <h1 style={{ fontSize: '1.25rem', margin: '0 0 12px', color: '#111827' }}>Products</h1>

      <div className="pwa-search">
        <input
          className="pwa-input"
          style={{ marginBottom: 0 }}
          placeholder="Search name or barcode…"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          aria-label="Search products"
        />
        <p className="pwa-muted" style={{ marginTop: 6 }}>
          {loading ? 'Searching…' : countLabel} (up to 500 rows; refine search for large catalogs)
        </p>
      </div>

      {err ? <div className="pwa-login-error">{err}</div> : null}

      <div className="pwa-card" style={{ padding: '8px 16px' }}>
        {rows.length === 0 && !loading ? (
          <p className="pwa-muted">No products match.</p>
        ) : (
          rows.map((p) => (
            <button
              key={p.id}
              type="button"
              className="pwa-product-row"
              style={{ width: '100%', border: 'none', background: 'transparent', textAlign: 'left' }}
              onClick={() => openEdit(p)}
            >
              <div>
                <div style={{ fontWeight: 700 }}>{p.name}</div>
                <div className="pwa-muted">{p.barcode}</div>
              </div>
              <div style={{ textAlign: 'right' }}>
                <div style={{ fontWeight: 700 }}>Qty {p.quantity}</div>
                <div className="pwa-muted">₱{Number(p.price).toFixed(2)}</div>
              </div>
            </button>
          ))
        )}
      </div>

      {editing ? (
        <div
          className="pwa-modal-backdrop"
          role="dialog"
          aria-modal="true"
          aria-labelledby="pwa-edit-title"
          onClick={(ev) => {
            if (ev.target === ev.currentTarget) closeEdit();
          }}
        >
          <div className="pwa-modal" onClick={(e) => e.stopPropagation()}>
            <h3 id="pwa-edit-title">Edit product</h3>
            <p className="pwa-muted" style={{ marginTop: -8, marginBottom: 12 }}>
              Barcode <strong>{editing.barcode}</strong> (read-only)
            </p>
            <form onSubmit={saveEdit}>
              <label className="pwa-label" htmlFor="pwa-p-name">
                Name
              </label>
              <input
                id="pwa-p-name"
                className="pwa-input"
                value={form.name}
                onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                required
              />
              <label className="pwa-label" htmlFor="pwa-p-qty">
                Quantity
              </label>
              <input
                id="pwa-p-qty"
                className="pwa-input"
                inputMode="numeric"
                value={form.quantity}
                onChange={(e) => setForm((f) => ({ ...f, quantity: e.target.value.replace(/\D/g, '') }))}
                required
              />
              <label className="pwa-label" htmlFor="pwa-p-price">
                Price
              </label>
              <input
                id="pwa-p-price"
                className="pwa-input"
                inputMode="decimal"
                value={form.price}
                onChange={(e) => setForm((f) => ({ ...f, price: e.target.value.replace(/[^\d.]/g, '') }))}
                required
              />
              <label className="pwa-label" htmlFor="pwa-p-cat">
                Category
              </label>
              <input
                id="pwa-p-cat"
                className="pwa-input"
                value={form.category}
                onChange={(e) => setForm((f) => ({ ...f, category: e.target.value }))}
              />
              <button type="submit" className="pwa-btn pwa-btn-primary" disabled={saving}>
                {saving ? 'Saving…' : 'Save'}
              </button>
              <button type="button" className="pwa-btn pwa-btn-secondary" style={{ marginTop: 8 }} onClick={closeEdit}>
                Cancel
              </button>
            </form>
          </div>
        </div>
      ) : null}
    </div>
  );
}
