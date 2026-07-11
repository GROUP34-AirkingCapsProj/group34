import React, { useState, useEffect, useMemo, useRef, useCallback } from 'react';
import { createPortal } from 'react-dom';

// ─── Type-to-search product picker ────────────────────────────────────────
// Replaces a plain <select> for long product lists. Product objects are the
// normalized shape used across the app: { id, name, code, brandName, ... }.
//
// The result list is rendered in a portal (fixed-positioned at the input) so
// it floats above the page like a native dropdown and is never clipped by an
// ancestor's overflow (the form lives inside overflow:hidden cards + a
// horizontally-scrolling table wrapper).
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
  const [filterCat, setFilterCat] = useState('');
  const [filterBrand, setFilterBrand] = useState('');
  const [menuRect, setMenuRect] = useState(null);
  const wrapRef  = useRef(null);
  const inputRef = useRef(null);
  const menuRef  = useRef(null);

  const selected = useMemo(() => products.find(p => p.id === value), [products, value]);

  // When closed, show the selected product's name; when typing, show the query.
  const inputText = open
    ? query
    : (selected ? `${selected.code ? `[${selected.code}] ` : ''}${selected.name}` : '');

  // Category options derived from the full product list.
  const categoryOptions = useMemo(() => {
    const seen = new Map();
    products.forEach(p => { if (p.categoryId && !seen.has(p.categoryId)) seen.set(p.categoryId, p.categoryName || p.categoryId); });
    return [...seen.entries()].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name));
  }, [products]);

  // Brand options narrow to the selected category (if any).
  const brandOptions = useMemo(() => {
    const seen = new Map();
    products
      .filter(p => !filterCat || p.categoryId === filterCat)
      .forEach(p => { if (p.brandId && !seen.has(p.brandId)) seen.set(p.brandId, p.brandName || p.brandId); });
    return [...seen.entries()].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name));
  }, [products, filterCat]);

  const matches = useMemo(() => {
    const q = query.trim().toLowerCase();
    return products.filter(p =>
      (!filterCat   || p.categoryId === filterCat) &&
      (!filterBrand || p.brandId === filterBrand) &&
      (!q || (p.name || '').toLowerCase().includes(q)
          || (p.code || '').toLowerCase().includes(q)
          || (p.brandName || '').toLowerCase().includes(q))
    ).slice(0, 50); // cap for perf
  }, [products, query, filterCat, filterBrand]);

  // Position the portalled menu at the input's on-screen rect.
  const positionMenu = useCallback(() => {
    if (!inputRef.current) return;
    const r = inputRef.current.getBoundingClientRect();
    setMenuRect({ top: r.bottom + 2, left: r.left, width: r.width });
  }, []);

  // Keep the menu glued to the input while open (scroll/resize).
  useEffect(() => {
    if (!open) return undefined;
    positionMenu();
    const onMove = () => positionMenu();
    window.addEventListener('scroll', onMove, true); // capture → catches inner scroll containers
    window.addEventListener('resize', onMove);
    return () => {
      window.removeEventListener('scroll', onMove, true);
      window.removeEventListener('resize', onMove);
    };
  }, [open, positionMenu]);

  // Close on outside click (menu is portalled, so check both wrapper AND menu).
  useEffect(() => {
    const onDoc = (e) => {
      if (wrapRef.current && wrapRef.current.contains(e.target)) return;
      if (menuRef.current && menuRef.current.contains(e.target)) return;
      setOpen(false);
    };
    document.addEventListener('mousedown', onDoc);
    return () => document.removeEventListener('mousedown', onDoc);
  }, []);

  return (
    <div className="prod-search" ref={wrapRef} style={{ position: 'relative' }}>
      {/* Always-visible filters — regular dropdowns, above the search box. */}
      <div className="prod-search-filters" style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 4 }}>
        <select
          className="po-select"
          style={{ flex: '1 1 45%', minWidth: 110, fontSize: 12 }}
          value={filterCat}
          disabled={disabled}
          onChange={(e) => { setFilterCat(e.target.value); setFilterBrand(''); }}
          title="Filter products by category"
        >
          <option value="">All categories</option>
          {categoryOptions.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
        <select
          className="po-select"
          style={{ flex: '1 1 45%', minWidth: 110, fontSize: 12 }}
          value={filterBrand}
          disabled={disabled}
          onChange={(e) => setFilterBrand(e.target.value)}
          title="Filter products by brand"
        >
          <option value="">All brands</option>
          {brandOptions.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
        </select>
      </div>

      <input
        ref={inputRef}
        type="text"
        className="po-select"
        style={{ fontSize: 12, width: '100%' }}
        disabled={disabled}
        value={inputText}
        placeholder={placeholder}
        onChange={(e) => { setQuery(e.target.value); if (!open) { setOpen(true); } positionMenu(); }}
        onFocus={() => { setOpen(true); setQuery(''); positionMenu(); }}
      />

      {open && !disabled && menuRect && createPortal(
        <div
          ref={menuRef}
          className="prod-search-menu"
          style={{
            position: 'fixed', top: menuRect.top, left: menuRect.left, width: menuRect.width,
            zIndex: 9999, maxHeight: 300, overflowY: 'auto', background: '#fff',
            border: '1px solid #cbd5e1', borderRadius: 8, boxShadow: '0 10px 28px rgba(0,0,0,.20)',
          }}
        >
          {matches.length === 0 ? (
            <div style={{ padding: '10px 12px', color: '#94a3b8', fontSize: 13 }}>No matching products</div>
          ) : matches.map(p => (
            <button
              type="button"
              key={p.id}
              className="prod-search-item"
              onMouseDown={(e) => e.preventDefault()} // keep input focus; fire before blur
              onClick={() => { onChange(p.id); setOpen(false); setQuery(''); }}
              style={{
                display: 'block', width: '100%', textAlign: 'left', padding: '9px 12px', border: 'none',
                borderBottom: '1px solid #f1f5f9',
                background: p.id === value ? '#eff6ff' : '#fff', cursor: 'pointer', fontSize: 13,
              }}
            >
              <span style={{ fontWeight: 600, color: '#1e293b' }}>{p.name}</span>
              {p.code ? <span style={{ color: '#64748b' }}> · {p.code}</span> : null}
              {p.brandName ? <span style={{ color: '#94a3b8' }}> · {p.brandName}</span> : null}
            </button>
          ))}
        </div>,
        document.body
      )}
    </div>
  );
};

export default ProductSearchSelect;
