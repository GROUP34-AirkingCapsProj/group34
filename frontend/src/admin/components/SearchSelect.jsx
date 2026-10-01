import React, { useState, useEffect, useMemo, useRef, useCallback } from 'react';
import { createPortal } from 'react-dom';

// ─── Type-to-search picker ────────────────────────────────────────────────
// Replaces a plain <select> for long lists. Knows nothing about what it is
// listing: the caller supplies rows and says what each is findable by.
//
// The result list is rendered in a portal (fixed-positioned at the input) so
// it floats above the page like a native dropdown and is never clipped by an
// ancestor's overflow — the forms this appears in live inside overflow:hidden
// cards and horizontally-scrolling table wrappers.
//
// Pick-only by design: typing filters the menu, and the value changes only
// when a row is chosen. A half-typed query is not a selection.
//
// props:
//   items       — [{ id, label, sublabel, extra, searchText, selectedLabel }]
//                 searchText is what the query matches; selectedLabel is what
//                 the closed box shows, defaulting to label.
//   value       — selected id, or ''
//   onChange(id)
//   placeholder — shown when nothing is typed or selected
//   emptyText   — shown when the query matches nothing
//   clearLabel  — text for the row that returns to no selection, e.g.
//                 "All products". Passing it also declares the value
//                 OPTIONAL, so emptying the box clears it too. Omit it for a
//                 picker whose value is required, where an empty box is a
//                 query rather than a clearing.
//   facets      — optional node rendered above the input
//   className   — class for the input; defaults to the app's po-select
//   disabled
const SearchSelect = ({
  items,
  value,
  onChange,
  placeholder = 'Search…',
  emptyText = 'No matches',
  clearLabel = null,
  facets = null,
  className = 'po-select',
  disabled,
}) => {
  const [open, setOpen] = useState(false);
  /* null means "not typing" — the box is showing the selection rather than a
     search. Blanking it on focus instead would make a set filter look
     cleared while it was still applied, and there would be nothing to delete
     for anyone who tried to clear it the obvious way. */
  const [query, setQuery] = useState(null);
  const [menuRect, setMenuRect] = useState(null);
  const wrapRef = useRef(null);
  const inputRef = useRef(null);
  const menuRef = useRef(null);

  const selected = useMemo(() => items.find((i) => i.id === value), [items, value]);

  // Typing shows the query; otherwise the box shows what is selected.
  const inputText = query !== null
    ? query
    : (selected ? (selected.selectedLabel || selected.label || '') : '');

  const matches = useMemo(() => {
    const q = (query || '').trim().toLowerCase();
    return items
      .filter((i) => !q || String(i.searchText || i.label || '').toLowerCase().includes(q))
      .slice(0, 50); // cap for perf
  }, [items, query]);

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
      setQuery(null); // back to showing the selection, not an abandoned search
    };
    document.addEventListener('mousedown', onDoc);
    return () => document.removeEventListener('mousedown', onDoc);
  }, []);

  return (
    <div className="prod-search" ref={wrapRef} style={{ position: 'relative' }}>
      {facets}

      <input
        ref={inputRef}
        type="text"
        className={className}
        style={{ fontSize: 12, width: '100%' }}
        disabled={disabled}
        value={inputText}
        placeholder={placeholder}
        onChange={(e) => {
          const next = e.target.value;
          setQuery(next);
          if (!open) { setOpen(true); }
          /* Emptying the box is how anyone clears a search box, and this
             looks like one. Only a typed edit reaches here, so a value is
             never dropped merely by looking at the box. */
          if (clearLabel && next === '' && value) { onChange(''); }
          positionMenu();
        }}
        /* The text is selected rather than blanked, so typing still replaces
           it in one keystroke while the box keeps telling the truth about
           what is filtered. */
        onFocus={(e) => { setOpen(true); setQuery(null); e.target.select(); positionMenu(); }}
      />

      {open && !disabled && menuRect && createPortal(
        <div
          ref={menuRef}
          className="prod-search-menu"
          style={{
            position: 'fixed', top: menuRect.top, left: menuRect.left, width: menuRect.width,
            zIndex: 10050, maxHeight: 300, overflowY: 'auto', background: 'var(--bg-card)',
            border: '1px solid var(--border-strong)', borderRadius: 8, boxShadow: '0 10px 28px rgba(0,0,0,.20)',
          }}
        >
          {/* Deliberately outside `matches`: it is not a row of data, so a
              query must never hide it. Focusing the box blanks it on screen
              without touching the value, so without this row a filter can be
              emptied in appearance and stay applied in fact. */}
          {clearLabel && value ? (
            <button
              type="button"
              className="prod-search-item prod-search-item--clear"
              onMouseDown={(e) => e.preventDefault()}
              onClick={() => { onChange(''); setOpen(false); setQuery(null); }}
              style={{
                display: 'block', width: '100%', textAlign: 'left', padding: '9px 12px',
                border: 'none', borderBottom: '1px solid var(--border-color)', background: 'var(--bg-muted)',
                cursor: 'pointer', fontSize: 13, color: 'var(--ink-blue-700)', fontWeight: 600,
              }}
            >
              {clearLabel}
            </button>
          ) : null}

          {matches.length === 0 ? (
            <div style={{ padding: '10px 12px', color: 'var(--ink-blue-300)', fontSize: 13 }}>{emptyText}</div>
          ) : matches.map((item) => (
            <button
              type="button"
              key={item.id}
              className="prod-search-item"
              onMouseDown={(e) => e.preventDefault()} // keep input focus; fire before blur
              onClick={() => { onChange(item.id); setOpen(false); setQuery(null); }}
              style={{
                display: 'block', width: '100%', textAlign: 'left', padding: '9px 12px', border: 'none',
                borderBottom: '1px solid var(--border-soft)',
                background: item.id === value ? 'var(--tint-blue-soft)' : 'var(--bg-card)', cursor: 'pointer', fontSize: 13,
              }}
            >
              <span style={{ fontWeight: 600, color: 'var(--ink-blue-800-3)' }}>{item.label}</span>
              {item.sublabel ? <span style={{ color: 'var(--ink-blue-500)' }}> · {item.sublabel}</span> : null}
              {item.extra ? <span style={{ color: 'var(--ink-blue-300)' }}> · {item.extra}</span> : null}
            </button>
          ))}
        </div>,
        document.body,
      )}
    </div>
  );
};

export default SearchSelect;
