import React, { useState, useEffect } from 'react';
import { useLocation } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Modal from '../components/Modal';
import { categoriesAPI, brandsAPI, batchAPI } from '../services/api';
import { toast } from '../utils/toast';
import { useAuth } from '../context/AuthContext';
import { getRoleKey, ROLES } from '../utils/roles';
import '../styles/dashboard_air.css';
import '../styles/category_brand_management.css';

const CATEGORY_ICONS = {
  'Air Conditioning': 'ac',
  'Television': 'tv',
  'Washing Machine': 'wm',
  'Refrigerator': 'ref',
  'Small Appliances': 'small',
};

const formatMoney = (n, { decimals = 2 } = {}) =>
  '₱' + (Number(n) ?? 0).toLocaleString('en-PH', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });

const formatDate = (d) => {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
};

const CategoryBrandManagement = () => {
  const { user } = useAuth();
  const roleKey = getRoleKey(user || {});
  const isInventoryAnalystViewOnly = roleKey === ROLES.INVENTORY_ANALYST;

  const location = useLocation();
  const [categories, setCategories] = useState([]);
  const [brands, setBrands] = useState([]);
  const [performance, setPerformance] = useState([]);
  const [archivedCategories, setArchivedCategories] = useState([]);
  const [archivedBrands, setArchivedBrands] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [categoryModal, setCategoryModal] = useState(false);
  const [brandModal, setBrandModal] = useState(false);
  const [editingCategory, setEditingCategory] = useState(null);
  const [editingBrand, setEditingBrand] = useState(null);
  const [categoryForm, setCategoryForm] = useState({ category_name: '', category_type: 'product', description: '' });
  const [brandForm, setBrandForm] = useState({ brand_name: '', description: '' });
  const [submitting, setSubmitting] = useState(false);
  const [archiveTab, setArchiveTab] = useState('categories');

  const fetchAll = async () => {
    try {
      setLoading(true);
      const [batchRes, perfRes, archivedCatRes, archivedBrandRes] = await Promise.all([
        batchAPI.get({ include: ['categories', 'brands'] }),
        categoriesAPI.getPerformance(),
        categoriesAPI.getArchived(),
        brandsAPI.getArchived(),
      ]);
      const batchData = batchRes?.data || {};
      setCategories(Array.isArray(batchData.categories?.data) ? batchData.categories.data : []);
      setBrands(Array.isArray(batchData.brands?.data) ? batchData.brands.data : []);
      setPerformance(Array.isArray(perfRes?.data) ? perfRes.data : []);
      setArchivedCategories(Array.isArray(archivedCatRes?.data) ? archivedCatRes.data : []);
      setArchivedBrands(Array.isArray(archivedBrandRes?.data) ? archivedBrandRes.data : []);
      setError(null);
    } catch (err) {
      // Fallback to individual requests
      try {
        const [catRes, brandRes, perfRes, archivedCatRes, archivedBrandRes] = await Promise.all([
          categoriesAPI.getAll(),
          brandsAPI.getAll(),
          categoriesAPI.getPerformance(),
          categoriesAPI.getArchived(),
          brandsAPI.getArchived(),
        ]);
        setCategories(Array.isArray(catRes?.data) ? catRes.data : []);
        setBrands(Array.isArray(brandRes?.data) ? brandRes.data : []);
        setPerformance(Array.isArray(perfRes?.data) ? perfRes.data : []);
        setArchivedCategories(Array.isArray(archivedCatRes?.data) ? archivedCatRes.data : []);
        setArchivedBrands(Array.isArray(archivedBrandRes?.data) ? archivedBrandRes.data : []);
        setError(null);
      } catch (fallbackErr) {
        setError(fallbackErr.message || 'Failed to load data');
        setCategories([]);
        setBrands([]);
        setPerformance([]);
        setArchivedCategories([]);
        setArchivedBrands([]);
      }
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchAll();
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (loading) return;
    if (location.pathname !== '/admin/brands') return;
    const t = window.setTimeout(() => {
      document.getElementById('cb-section-brands')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 100);
    return () => window.clearTimeout(t);
  }, [location.pathname, loading]);

  const openAddCategory = () => {
    if (isInventoryAnalystViewOnly) {
      toast.info('Categories & Brands is view-only for Inventory Analyst.');
      return;
    }
    setEditingCategory(null);
    setCategoryForm({ category_name: '', category_type: 'product', description: '' });
    setCategoryModal(true);
  };
  const openEditCategory = (c) => {
    if (isInventoryAnalystViewOnly) {
      toast.info('Editing categories is disabled for Inventory Analyst.');
      return;
    }
    setEditingCategory(c);
    setCategoryForm({
      category_name: c.category_name || '',
      category_type: c.category_type || 'product',
      description: c.description || '',
    });
    setCategoryModal(true);
  };
  const openAddBrand = () => {
    if (isInventoryAnalystViewOnly) {
      toast.info('Categories & Brands is view-only for Inventory Analyst.');
      return;
    }
    setEditingBrand(null);
    setBrandForm({ brand_name: '', description: '' });
    setBrandModal(true);
  };
  const openEditBrand = (b) => {
    if (isInventoryAnalystViewOnly) {
      toast.info('Editing brands is disabled for Inventory Analyst.');
      return;
    }
    setEditingBrand(b);
    setBrandForm({ brand_name: b.brand_name || '', description: b.description || '' });
    setBrandModal(true);
  };

  const handleCategorySubmit = async (e) => {
    if (isInventoryAnalystViewOnly) {
      toast.info('Saving categories is disabled for Inventory Analyst.');
      return;
    }
    e.preventDefault();
    if (!categoryForm.category_name?.trim()) return;
    setSubmitting(true);
    try {
      if (editingCategory) {
        await categoriesAPI.update(editingCategory.category_id, categoryForm);
        toast.success('Category updated successfully.');
      } else {
        await categoriesAPI.create(categoryForm);
        toast.success('Category added successfully.');
      }
      setCategoryModal(false);
      fetchAll();
    } catch (err) {
      toast.error(err.errors?.category_name?.[0] || err.message || 'Failed to save category.');
    } finally {
      setSubmitting(false);
    }
  };

  const handleBrandSubmit = async (e) => {
    if (isInventoryAnalystViewOnly) {
      toast.info('Saving brands is disabled for Inventory Analyst.');
      return;
    }
    e.preventDefault();
    if (!brandForm.brand_name?.trim()) return;
    setSubmitting(true);
    try {
      if (editingBrand) {
        await brandsAPI.update(editingBrand.brand_id, brandForm);
        toast.success('Brand updated successfully.');
      } else {
        await brandsAPI.create(brandForm);
        toast.success('Brand added successfully.');
      }
      setBrandModal(false);
      fetchAll();
    } catch (err) {
      toast.error(err.errors?.brand_name?.[0] || err.message || 'Failed to save brand.');
    } finally {
      setSubmitting(false);
    }
  };

  const handleArchiveCategory = async (c) => {
    if (isInventoryAnalystViewOnly) {
      toast.info('Archiving categories is disabled for Inventory Analyst.');
      return;
    }
    if (!window.confirm(`Archive category "${c.category_name}"? You can restore it later.`)) return;
    try {
      await categoriesAPI.delete(c.category_id);
      toast.success(`Category "${c.category_name}" archived.`);
      fetchAll();
    } catch (err) {
      toast.error(err.message || 'Failed to archive category.');
    }
  };

  const handleArchiveBrand = async (b) => {
    if (isInventoryAnalystViewOnly) {
      toast.info('Archiving brands is disabled for Inventory Analyst.');
      return;
    }
    if (!window.confirm(`Archive brand "${b.brand_name}"? You can restore it later.`)) return;
    try {
      await brandsAPI.delete(b.brand_id);
      toast.success(`Brand "${b.brand_name}" archived.`);
      fetchAll();
    } catch (err) {
      toast.error(err.message || 'Failed to archive brand.');
    }
  };

  const handleRestoreCategory = async (c) => {
    if (isInventoryAnalystViewOnly) {
      toast.info('Restoring categories is disabled for Inventory Analyst.');
      return;
    }
    try {
      await categoriesAPI.restore(c.category_id);
      toast.success(`Category "${c.category_name}" restored.`);
      fetchAll();
    } catch (err) {
      toast.error(err.message || 'Failed to restore category.');
    }
  };

  const handleRestoreBrand = async (b) => {
    if (isInventoryAnalystViewOnly) {
      toast.info('Restoring brands is disabled for Inventory Analyst.');
      return;
    }
    try {
      await brandsAPI.restore(b.brand_id);
      toast.success(`Brand "${b.brand_name}" restored.`);
      fetchAll();
    } catch (err) {
      toast.error(err.message || 'Failed to restore brand.');
    }
  };

  const CategoryIcon = ({ name }) => {
    const key = Object.keys(CATEGORY_ICONS).find(k => name && name.toLowerCase().includes(k.split(' ')[0].toLowerCase()));
    const type = key ? CATEGORY_ICONS[key] : 'default';
    return (
      <div className={`cb-icon-tile cb-icon-tile-cat cb-icon-tile-${type}`} aria-hidden>
        {type === 'ac' && (
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M12 2v20M2 12h20M4.93 4.93l14.14 14.14M19.07 4.93L4.93 19.07"/></svg>
        )}
        {type === 'tv' && (
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="2" y="7" width="20" height="15" rx="2" ry="2"/><polyline points="17 2 12 7 7 2"/></svg>
        )}
        {type === 'wm' && (
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="2" width="18" height="20" rx="2"/><circle cx="12" cy="14" r="5"/></svg>
        )}
        {type === 'ref' && (
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="4" y="2" width="16" height="20" rx="2"/><line x1="4" y1="12" x2="20" y2="12"/></svg>
        )}
        {type === 'small' && (
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        )}
        {type === 'default' && (
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
        )}
      </div>
    );
  };

  const totalArchived = archivedCategories.length + archivedBrands.length;

  return (
    <AdminLayout>
      <div className="cb-page">
        <div className="cb-page-header">
          <div>
            <h1>Category &amp; Brand Management</h1>
            <p>Manage item categories and brands for classification and reporting.</p>
          </div>
        </div>

        {loading && <div className="cb-loading">Loading...</div>}
        {error && <div className="cb-error">Error: {error}</div>}

        {!loading && !error && (
          <>
            {/* Active Categories & Brands */}
            <div className="cb-two-cols">
              <section id="cb-section-categories" className="cb-section">
                <div className="cb-section-header">
                  <div>
                    <h2>Categories</h2>
                    <p className="cb-section-summary">{categories.length} active categories</p>
                  </div>
                  {!isInventoryAnalystViewOnly && (
                    <button type="button" className="cb-btn-add" onClick={openAddCategory}>+ Add Category</button>
                  )}
                </div>
                <ul className="cb-card-list">
                  {categories.length === 0 && (
                    <li className="cb-empty">No active categories.</li>
                  )}
                  {categories.map((c) => {
                    const n = c.items_count ?? 0;
                    return (
                      <li key={c.category_id} className="cb-card-row">
                        <CategoryIcon name={c.category_name} />
                        <div className="cb-card-body">
                          <div className="cb-card-name">{c.category_name}</div>
                          <div className="cb-card-meta">
                            {n} {n === 1 ? 'item' : 'items'} • {formatMoney(c.total_value, { decimals: 0 })}
                          </div>
                        </div>
                        {!isInventoryAnalystViewOnly && (
                          <div className="cb-card-actions">
                            <button type="button" className="cb-btn-edit" onClick={() => openEditCategory(c)}>Edit</button>
                            <button type="button" className="cb-btn-delete" onClick={() => handleArchiveCategory(c)}>Delete</button>
                          </div>
                        )}
                      </li>
                    );
                  })}
                </ul>
              </section>

              <section id="cb-section-brands" className="cb-section">
                <div className="cb-section-header">
                  <div>
                    <h2>Brands</h2>
                    <p className="cb-section-summary">{brands.length} active brands</p>
                  </div>
                  {!isInventoryAnalystViewOnly && (
                    <button type="button" className="cb-btn-add" onClick={openAddBrand}>+ Add Brand</button>
                  )}
                </div>
                <ul className="cb-card-list">
                  {brands.length === 0 && (
                    <li className="cb-empty">No active brands.</li>
                  )}
                  {brands.map((b) => {
                    const n = b.items_count ?? 0;
                    return (
                      <li key={b.brand_id} className="cb-card-row cb-card-row-brand">
                        <div className="cb-icon-tile cb-icon-tile-brand" aria-hidden>
                          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                        </div>
                        <div className="cb-card-body">
                          <div className="cb-card-name">{b.brand_name}</div>
                          <div className="cb-card-meta">
                            {n} {n === 1 ? 'item' : 'items'} • {formatMoney(b.total_value, { decimals: 0 })}
                          </div>
                        </div>
                        {!isInventoryAnalystViewOnly && (
                          <div className="cb-card-actions">
                            <button type="button" className="cb-btn-edit" onClick={() => openEditBrand(b)}>Edit</button>
                            <button type="button" className="cb-btn-delete" onClick={() => handleArchiveBrand(b)}>Delete</button>
                          </div>
                        )}
                      </li>
                    );
                  })}
                </ul>
              </section>
            </div>

            {/* Category Performance */}
            <section className="cb-performance">
              <div className="cb-performance-head">
                <h2>Category Performance</h2>
                <p className="cb-performance-subtitle">Items and value by category.</p>
              </div>
              <div className="cb-table-wrap">
                <table className="cb-table">
                  <thead>
                    <tr>
                      <th scope="col">Category</th>
                      <th scope="col" className="cb-th-num">Total items</th>
                      <th scope="col" className="cb-th-num">Total units</th>
                      <th scope="col" className="cb-th-num">Total value</th>
                      <th scope="col" className="cb-th-num">Avg price</th>
                    </tr>
                  </thead>
                  <tbody>
                    {performance.length === 0 ? (
                      <tr>
                        <td colSpan={5} className="cb-table-empty">No performance data yet.</td>
                      </tr>
                    ) : (
                      performance.map((row, i) => (
                        <tr key={row.category || i}>
                          <td className="cb-td-cat">{row.category}</td>
                          <td className="cb-td-num">{row.total_items ?? 0}</td>
                          <td className="cb-td-num">{(row.total_units ?? 0).toLocaleString('en-PH')}</td>
                          <td className="cb-td-num cb-value">{formatMoney(row.total_value, { decimals: 0 })}</td>
                          <td className="cb-td-num">{formatMoney(row.avg_price, { decimals: 2 })}</td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </section>

            {/* Archive Section */}
            <section className="cb-archive-section">
              <div className="cb-archive-header">
                <div className="cb-archive-title-row">
                  <div className="cb-archive-icon-wrap">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
                  </div>
                  <div>
                    <h2>Archive</h2>
                    <p className="cb-section-summary">
                      {totalArchived} archived record{totalArchived !== 1 ? 's' : ''} — restore anytime
                    </p>
                  </div>
                </div>
                <div className="cb-archive-tabs">
                  <button
                    type="button"
                    className={`cb-archive-tab ${archiveTab === 'categories' ? 'active' : ''}`}
                    onClick={() => setArchiveTab('categories')}
                  >
                    Categories
                    {archivedCategories.length > 0 && (
                      <span className="cb-archive-badge">{archivedCategories.length}</span>
                    )}
                  </button>
                  <button
                    type="button"
                    className={`cb-archive-tab ${archiveTab === 'brands' ? 'active' : ''}`}
                    onClick={() => setArchiveTab('brands')}
                  >
                    Brands
                    {archivedBrands.length > 0 && (
                      <span className="cb-archive-badge">{archivedBrands.length}</span>
                    )}
                  </button>
                </div>
              </div>

              {archiveTab === 'categories' && (
                <div className="cb-archive-body">
                  {archivedCategories.length === 0 ? (
                    <div className="cb-archive-empty">No archived categories.</div>
                  ) : (
                    <table className="cb-archive-table">
                      <thead>
                        <tr>
                          <th>Name</th>
                          <th>Type</th>
                          <th>Description</th>
                          <th>Archived On</th>
                          <th>Actions</th>
                        </tr>
                      </thead>
                      <tbody>
                        {archivedCategories.map((c) => (
                          <tr key={c.category_id}>
                            <td className="cb-archive-name">{c.category_name}</td>
                            <td className="cb-archive-type">{c.category_type || '—'}</td>
                            <td className="cb-archive-desc">{c.description || '—'}</td>
                            <td className="cb-archive-date">{formatDate(c.deleted_at)}</td>
                            <td>
                              {!isInventoryAnalystViewOnly && (
                                <button
                                  type="button"
                                  className="cb-btn-restore"
                                  onClick={() => handleRestoreCategory(c)}
                                >
                                  Restore
                                </button>
                              )}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  )}
                </div>
              )}

              {archiveTab === 'brands' && (
                <div className="cb-archive-body">
                  {archivedBrands.length === 0 ? (
                    <div className="cb-archive-empty">No archived brands.</div>
                  ) : (
                    <table className="cb-archive-table">
                      <thead>
                        <tr>
                          <th>Name</th>
                          <th>Description</th>
                          <th>Archived On</th>
                          <th>Actions</th>
                        </tr>
                      </thead>
                      <tbody>
                        {archivedBrands.map((b) => (
                          <tr key={b.brand_id}>
                            <td className="cb-archive-name">{b.brand_name}</td>
                            <td className="cb-archive-desc">{b.description || '—'}</td>
                            <td className="cb-archive-date">{formatDate(b.deleted_at)}</td>
                            <td>
                              {!isInventoryAnalystViewOnly && (
                                <button
                                  type="button"
                                  className="cb-btn-restore"
                                  onClick={() => handleRestoreBrand(b)}
                                >
                                  Restore
                                </button>
                              )}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  )}
                </div>
              )}
            </section>
          </>
        )}

        {/* Category Modal */}
        <Modal
          isOpen={categoryModal}
          title={editingCategory ? 'Edit Category' : 'Add Category'}
          onClose={() => setCategoryModal(false)}
        >
            <form onSubmit={handleCategorySubmit} className="cb-form">
              <div className="cb-form-group">
                <label>Category name</label>
                <input
                  type="text"
                  value={categoryForm.category_name}
                  onChange={(e) => setCategoryForm({ ...categoryForm, category_name: e.target.value })}
                  required
                  autoFocus
                  maxLength={100}
                />
              </div>
              <div className="cb-form-group">
                <label>Type</label>
                <select
                  value={categoryForm.category_type}
                  onChange={(e) => setCategoryForm({ ...categoryForm, category_type: e.target.value })}
                >
                  <option value="product">Product</option>
                  <option value="part">Part</option>
                  <option value="accessory">Accessory</option>
                  <option value="service">Service</option>
                </select>
              </div>
              <div className="cb-form-group">
                <label>Description (optional)</label>
                <textarea
                  value={categoryForm.description}
                  onChange={(e) => setCategoryForm({ ...categoryForm, description: e.target.value })}
                  rows={2}
                />
              </div>
              <div className="cb-form-actions">
                <button type="button" className="cb-btn-secondary" onClick={() => setCategoryModal(false)}>Cancel</button>
                <button type="submit" className="cb-btn-primary" disabled={submitting}>
                  {submitting ? 'Saving...' : (editingCategory ? 'Update' : 'Add Category')}
                </button>
              </div>
            </form>
        </Modal>

        {/* Brand Modal */}
        <Modal
          isOpen={brandModal}
          title={editingBrand ? 'Edit Brand' : 'Add Brand'}
          onClose={() => setBrandModal(false)}
        >
            <form onSubmit={handleBrandSubmit} className="cb-form">
              <div className="cb-form-group">
                <label>Brand name</label>
                <input
                  type="text"
                  value={brandForm.brand_name}
                  onChange={(e) => setBrandForm({ ...brandForm, brand_name: e.target.value })}
                  required
                  autoFocus
                  maxLength={100}
                />
              </div>
              <div className="cb-form-group">
                <label>Description (optional)</label>
                <textarea
                  value={brandForm.description}
                  onChange={(e) => setBrandForm({ ...brandForm, description: e.target.value })}
                  rows={2}
                />
              </div>
              <div className="cb-form-actions">
                <button type="button" className="cb-btn-secondary" onClick={() => setBrandModal(false)}>Cancel</button>
                <button type="submit" className="cb-btn-primary" disabled={submitting}>
                  {submitting ? 'Saving...' : (editingBrand ? 'Update' : 'Add Brand')}
                </button>
              </div>
            </form>
        </Modal>
      </div>
    </AdminLayout>
  );
};

export default CategoryBrandManagement;
