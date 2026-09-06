import React, { useState, useMemo } from 'react';
import SearchSelect from './SearchSelect';

// ─── Type-to-search product picker ────────────────────────────────────────
// SearchSelect plus the two things that are specifically about products: the
// category and brand dropdowns, and the way a product reads in a row.
//
// The props are unchanged from when this file held the whole control —
// OrderBuilder, PurchaseRequestPage and NewRequestModal all pass the same
// normalized shape, { id, name, code, brandName, categoryId, ... }, and none
// of them know this was ever split.
//
// props:
//   products    — normalized array [{ id, name, code, brandName, ... }]
//   value       — selected product id (string) or ''
//   onChange(productId) — called with the chosen product's id
//   placeholder — shown when nothing is typed/selected
//   disabled
const ProductSearchSelect = ({ products, value, onChange, placeholder = 'Search product…', disabled }) => {
  const [filterCat, setFilterCat] = useState('');
  const [filterBrand, setFilterBrand] = useState('');

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

  /* The facets filter before the search does, exactly as they did when this
     was one file: the 50-row cap therefore applies to what is left after a
     category is chosen, not to the whole catalogue. */
  const items = useMemo(() => products
    .filter(p => (!filterCat || p.categoryId === filterCat)
              && (!filterBrand || p.brandId === filterBrand))
    .map(p => ({
      id: p.id,
      label: p.name,
      sublabel: p.code,
      extra: p.brandName,
      searchText: `${p.name || ''} ${p.code || ''} ${p.brandName || ''}`,
      selectedLabel: `${p.code ? `[${p.code}] ` : ''}${p.name}`,
    })), [products, filterCat, filterBrand]);

  const facets = (
    <div className="prod-search-filters" style={{ display: 'flex', gap: 4, flexWrap: 'wrap', marginBottom: 3 }}>
      <select
        className="po-select"
        style={{ flex: '1 1 45%', minWidth: 84, fontSize: 10.5, padding: '1px 4px', height: 24, lineHeight: '22px' }}
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
        style={{ flex: '1 1 45%', minWidth: 84, fontSize: 10.5, padding: '1px 4px', height: 24, lineHeight: '22px' }}
        value={filterBrand}
        disabled={disabled}
        onChange={(e) => setFilterBrand(e.target.value)}
        title="Filter products by brand"
      >
        <option value="">All brands</option>
        {brandOptions.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
      </select>
    </div>
  );

  return (
    <SearchSelect
      items={items}
      value={value}
      onChange={onChange}
      placeholder={placeholder}
      emptyText="No matching products"
      facets={facets}
      disabled={disabled}
    />
  );
};

export default ProductSearchSelect;
