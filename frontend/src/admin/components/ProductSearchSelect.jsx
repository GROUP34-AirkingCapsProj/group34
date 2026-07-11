import React, { useState, useEffect, useMemo, useRef } from 'react';

// ─── Type-to-search product picker ────────────────────────────────────────
// Replaces a plain <select> for long product lists. Product objects are the
// normalized shape used across the app: { id, name, code, brandName, ... }.
//
// props:
//   products    — normalized array [{ id, name, code, brandName, ... }]
//   value       — selected product id (string) or ''
//   onChange(productId) — called with the chosen product's id
//   placeholder — shown when nothing is typed/selected
//   disabled
const ProductSearchSelect = ({ products, value, onChange, placeholder = 'Search product…', disabled }) => {
  const [open, setOpen]   = useState(false);
  const [query, setQuery] = useState('');
  const wrapRef = useRef(null);

  const selected = useMemo(() => products.find(p => p.id === value), [products, value]);

  // When closed, show the selected product's name; when typing, show the query.
  const inputText = open
    ? query
    : (selected ? `${selected.code ? `[${selected.code}] ` : ''}${selected.name}` : '');

  const matches = useMemo(() => {
    const q = query.trim().toLowerCase();
    const list = q
      ? products.filter(p =>
          (p.name || '').toLowerCase().includes(q) ||
          (p.code || '').toLowerCase().includes(q) ||
          (p.brandName || '').toLowerCase().includes(q))
      : products;
    return list.slice(0, 50); // cap for perf
  }, [products, query]);

  // Close on outside click.
  useEffect(() => {
    const onDoc = (e) => { if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', onDoc);
    return () => document.removeEventListener('mousedown', onDoc);
  }, []);

  return (
    <div className="prod-search" ref={wrapRef} style={{ position: 'relative' }}>
      <input
        type="text"
        className="po-select"
        style={{ fontSize: 12, width: '100%' }}
        disabled={disabled}
        value={inputText}
        placeholder={placeholder}
        onChange={(e) => { setQuery(e.target.value); if (!open) setOpen(true); }}
        onFocus={() => { setOpen(true); setQuery(''); }}
      />
      {open && !disabled && (
        <div
          className="prod-search-menu"
          style={{
            position: 'absolute', zIndex: 30, top: '100%', left: 0, right: 0, minWidth: 240,
            maxHeight: 260, overflowY: 'auto', background: '#fff', border: '1px solid #cbd5e1',
            borderRadius: 8, boxShadow: '0 8px 24px rgba(0,0,0,.12)', marginTop: 2,
          }}
        >
          {matches.length === 0 ? (
            <div style={{ padding: '10px 12px', color: '#94a3b8', fontSize: 13 }}>No matching products</div>
          ) : matches.map(p => (
            <button
              type="button"
              key={p.id}
              className="prod-search-item"
              onClick={() => { onChange(p.id); setOpen(false); setQuery(''); }}
              style={{
                display: 'block', width: '100%', textAlign: 'left', padding: '8px 12px', border: 'none',
                background: p.id === value ? '#eff6ff' : 'transparent', cursor: 'pointer', fontSize: 13,
              }}
            >
              <span style={{ fontWeight: 600 }}>{p.name}</span>
              {p.code ? <span style={{ color: '#64748b' }}> · {p.code}</span> : null}
              {p.brandName ? <span style={{ color: '#94a3b8' }}> · {p.brandName}</span> : null}
            </button>
          ))}
        </div>
      )}
    </div>
  );
};

export default ProductSearchSelect;
