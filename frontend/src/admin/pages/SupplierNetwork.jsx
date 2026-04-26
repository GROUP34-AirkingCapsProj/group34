import { useState, useEffect, useCallback, useRef } from 'react';
import AdminLayout from '../components/AdminLayout';
import Modal from '../components/Modal';
import { suppliersAPI, itemsAPI, statusAPI } from '../services/api';
import { toast } from '../utils/toast';
import { useAuth } from '../context/AuthContext';
import { getRoleKey, ROLES } from '../utils/roles';
import '../styles/dashboard_air.css';
import '../styles/supplier_network.css';


const EMPTY_FORM = {
  supplier_name: '',
  contact_person: '',
  contact_number: '',
  email: '',
  address: '',
  origin: 'Local',
  region: '',
  tin: '',
  notes: '',
  status_id: '',
};

const SupplierNetwork = () => {
  const { user } = useAuth();
  const roleKey = getRoleKey(user || {});
  const isInventoryAnalystViewOnly = roleKey === ROLES.INVENTORY_ANALYST;

  const [suppliers, setSuppliers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState('');
  const debounceRef = useRef(null);

  /* modal state */
  const [isFormOpen, setIsFormOpen] = useState(false);
  const [editingSupplier, setEditingSupplier] = useState(null);
  const [formData, setFormData] = useState(EMPTY_FORM);
  const [submitting, setSubmitting] = useState(false);

  /* statuses */
  const [statuses, setStatuses] = useState([]);

  useEffect(() => {
    statusAPI.getAll()
      .then((res) => setStatuses(Array.isArray(res.data) ? res.data : []))
      .catch(() => {});
  }, []);

  /* view mode */
  const [viewMode, setViewMode] = useState('cards'); // 'cards' | 'list'

  /* products modal */
  const [productsModal, setProductsModal] = useState(null);
  const [linkedProducts, setLinkedProducts] = useState([]);
  const [loadingProducts, setLoadingProducts] = useState(false);
  const [allProducts, setAllProducts] = useState([]);
  const [addProductId, setAddProductId] = useState('');
  const [addProductPrice, setAddProductPrice] = useState('');
  const [addProductCurrency, setAddProductCurrency] = useState('PHP');
  const [addProductStatus, setAddProductStatus] = useState('');
  const [addingProduct, setAddingProduct] = useState(false);

  /* view-only products modal (card / row click) */
  const [viewModal, setViewModal] = useState(null);
  const [viewProducts, setViewProducts] = useState([]);
  const [loadingViewProducts, setLoadingViewProducts] = useState(false);

  /* edit product link */
  const [editingProduct, setEditingProduct] = useState(null); // the linked product row being edited
  const [editPrice, setEditPrice] = useState('');
  const [editCurrency, setEditCurrency] = useState('PHP');
  const [editStatusId, setEditStatusId] = useState('');
  const [savingEdit, setSavingEdit] = useState(false);

  /* ── fetch suppliers ── */
  const fetchSuppliers = useCallback(async () => {
    try {
      setLoading(true);
      const params = { paginate: 'false' };
      if (searchTerm) params.search = searchTerm;
      const res = await suppliersAPI.getAll(params);
      setSuppliers(Array.isArray(res.data) ? res.data : []);
    } catch (err) {
      toast.error(err.message || 'Failed to load suppliers');
      setSuppliers([]);
    } finally {
      setLoading(false);
    }
  }, [searchTerm]);

  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(fetchSuppliers, 350);
    return () => clearTimeout(debounceRef.current);
  }, [fetchSuppliers]);

  /* ── helper: find Active status_id from loaded statuses ── */
  const getActiveStatusId = useCallback(() => {
    const active = statuses.find((s) => s.status_name?.toLowerCase() === 'active');
    return active ? String(active.status_id) : '';
  }, [statuses]);

  /* ── stats ── */
  const totalCount = suppliers.length;
  const localCount = suppliers.filter((s) => s.origin === 'Local').length;
  const activeCount = suppliers.filter(
    (s) => s.status?.status_name?.toLowerCase() === 'active'
  ).length;

  /* ── form handlers ── */
  const handleAdd = () => {
    if (isInventoryAnalystViewOnly) {
      toast.info('Supplier Network is view-only for Inventory Analyst.');
      return;
    }
    setEditingSupplier(null);
    setFormData({ ...EMPTY_FORM, status_id: getActiveStatusId() });
    setIsFormOpen(true);
  };

  const handleEdit = (s) => {
    if (isInventoryAnalystViewOnly) {
      toast.info('Editing suppliers is disabled for Inventory Analyst.');
      return;
    }
    setEditingSupplier(s);
    setFormData({
      supplier_name: s.supplier_name || '',
      contact_person: s.contact_person || '',
      contact_number: s.contact_number || '',
      email: s.email || '',
      address: s.address || '',
      origin: s.origin || 'Local',
      region: s.region || '',
      tin: s.tin || '',
      notes: s.notes || '',
      status_id: s.status_id || '',
    });
    setIsFormOpen(true);
  };

  const handleFormChange = (field, value) => {
    setFormData((prev) => {
      const next = { ...prev, [field]: value };
      if (field === 'origin') next.region = '';
      return next;
    });
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSubmitting(true);
    try {
      if (editingSupplier) {
        await suppliersAPI.update(editingSupplier.supplier_id, formData);
        toast.success('Supplier updated successfully');
      } else {
        await suppliersAPI.create(formData);
        toast.success('Supplier added successfully');
      }
      setIsFormOpen(false);
      fetchSuppliers();
    } catch (err) {
      const firstError = err.errors ? Object.values(err.errors).flat()[0] : null;
      toast.error(firstError || err.message || 'Failed to save supplier');
    } finally {
      setSubmitting(false);
    }
  };

  const handleDelete = async (s) => {
    if (isInventoryAnalystViewOnly) {
      toast.info('Deleting suppliers is disabled for Inventory Analyst.');
      return;
    }
    if (!window.confirm(`Delete supplier "${s.supplier_name}"? This cannot be undone.`)) return;
    try {
      await suppliersAPI.delete(s.supplier_id);
      toast.success('Supplier deleted');
      fetchSuppliers();
    } catch (err) {
      toast.error(err.message || 'Failed to delete supplier');
    }
  };

  /* ── products modal ── */
  const openProductsModal = async (supplier) => {
    setProductsModal(supplier);
    setLinkedProducts([]);
    setAddProductId('');
    setAddProductPrice('');
    setAddProductCurrency('PHP');
    setAddProductStatus(getActiveStatusId());
    setEditingProduct(null);
    setLoadingProducts(true);
    try {
      const [prodRes, allRes] = await Promise.all([
        suppliersAPI.getProducts(supplier.supplier_id),
        itemsAPI.getAll({ per_page: 200 }),
      ]);
      setLinkedProducts(Array.isArray(prodRes.data) ? prodRes.data : []);
      setAllProducts(Array.isArray(allRes.data) ? allRes.data : []);
    } catch (err) {
      toast.error('Failed to load products');
    } finally {
      setLoadingProducts(false);
    }
  };

  const openViewProductsModal = async (supplier) => {
    setViewModal(supplier);
    setViewProducts([]);
    setLoadingViewProducts(true);
    try {
      const res = await suppliersAPI.getProducts(supplier.supplier_id);
      setViewProducts(Array.isArray(res.data) ? res.data : []);
    } catch {
      toast.error('Failed to load products');
    } finally {
      setLoadingViewProducts(false);
    }
  };

  const handleAddProduct = async () => {
    if (isInventoryAnalystViewOnly) {
      toast.info('Linking products is disabled for Inventory Analyst.');
      return;
    }
    if (!addProductId) return;
    setAddingProduct(true);
    try {
      await suppliersAPI.addProduct(productsModal.supplier_id, {
        product_id: Number(addProductId),
        product_price: addProductPrice ? Number(addProductPrice) : null,
        currency: addProductCurrency || 'PHP',
        status_id: addProductStatus ? Number(addProductStatus) : null,
      });
      toast.success('Product linked');
      const res = await suppliersAPI.getProducts(productsModal.supplier_id);
      setLinkedProducts(Array.isArray(res.data) ? res.data : []);
      setAddProductId('');
      setAddProductPrice('');
      setAddProductStatus(getActiveStatusId());
    } catch (err) {
      toast.error(err.message || 'Failed to link product');
    } finally {
      setAddingProduct(false);
    }
  };

  const handleEditProduct = (p) => {
    if (isInventoryAnalystViewOnly) {
      toast.info('Editing product links is disabled for Inventory Analyst.');
      return;
    }
    setEditingProduct(p);
    setEditPrice(p.product_price != null ? String(p.product_price) : '');
    setEditCurrency(p.currency || 'PHP');
    setEditStatusId(p.status_id ? String(p.status_id) : '');
  };

  const handleSaveEditProduct = async () => {
    if (isInventoryAnalystViewOnly) {
      toast.info('Editing product links is disabled for Inventory Analyst.');
      return;
    }
    if (!editingProduct) return;
    setSavingEdit(true);
    try {
      await suppliersAPI.updateProduct(productsModal.supplier_id, editingProduct.supplier_prod_id, {
        product_price: editPrice ? Number(editPrice) : null,
        currency: editCurrency || 'PHP',
        status_id: editStatusId ? Number(editStatusId) : null,
      });
      toast.success('Product link updated');
      const res = await suppliersAPI.getProducts(productsModal.supplier_id);
      setLinkedProducts(Array.isArray(res.data) ? res.data : []);
      setEditingProduct(null);
    } catch (err) {
      toast.error(err.message || 'Failed to update product link');
    } finally {
      setSavingEdit(false);
    }
  };

  const handleRemoveProduct = async (productId) => {
    if (isInventoryAnalystViewOnly) {
      toast.info('Removing product links is disabled for Inventory Analyst.');
      return;
    }
    if (!window.confirm('Remove this product from the supplier?')) return;
    try {
      await suppliersAPI.removeProduct(productsModal.supplier_id, productId);
      toast.success('Product removed');
      setLinkedProducts((prev) => prev.filter((p) => p.product_id !== productId));
    } catch (err) {
      toast.error(err.message || 'Failed to remove product');
    }
  };

  /* ── available products (not yet linked) ── */
  const linkedIds = new Set(linkedProducts.map((p) => p.product_id));
  const availableProducts = allProducts.filter(
    (p) => !linkedIds.has(p.product_id ?? p.id)
  );

  return (
    <AdminLayout>
      <div className="sn-page">
        {/* ── Page Header ── */}
        <div className="sn-page-header">
          <div className="sn-page-header-left">
            <h1>Supplier Network</h1>
            <p>
              {isInventoryAnalystViewOnly
                ? 'View supplier profiles and linked products'
                : 'Manage local appliance vendors and their linked products'}
            </p>
          </div>
          <div className="sn-header-actions">
            <div className="sn-view-toggle">
              <button
                type="button"
                className={`sn-toggle-btn ${viewMode === 'cards' ? 'active' : ''}`}
                onClick={() => setViewMode('cards')}
              >
                Cards
              </button>
              <button
                type="button"
                className={`sn-toggle-btn ${viewMode === 'list' ? 'active' : ''}`}
                onClick={() => setViewMode('list')}
              >
                List
              </button>
            </div>
            {!isInventoryAnalystViewOnly && (
              <button className="sn-btn-add" onClick={handleAdd}>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15">
                  <line x1="12" y1="5" x2="12" y2="19" />
                  <line x1="5" y1="12" x2="19" y2="12" />
                </svg>
                ADD SUPPLIER
              </button>
            )}
          </div>
        </div>

        {/* ── Stats Row ── */}
        <div className="sn-stats-row">
          <div className="sn-stat-card">
            <div className="sn-stat-icon sn-stat-icon-red">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#dc2626" strokeWidth="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                <circle cx="9" cy="7" r="4" />
                <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                <path d="M16 3.13a4 4 0 0 1 0 7.75" />
              </svg>
            </div>
            <div className="sn-stat-info">
              <span className="sn-stat-label">Total Suppliers</span>
              <span className="sn-stat-number">{totalCount}</span>
            </div>
          </div>
          <div className="sn-stat-card">
            <div className="sn-stat-icon sn-stat-icon-blue">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" strokeWidth="2">
                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                <circle cx="12" cy="10" r="3" />
              </svg>
            </div>
            <div className="sn-stat-info">
              <span className="sn-stat-label">Local</span>
              <span className="sn-stat-number">{localCount}</span>
            </div>
          </div>
          <div className="sn-stat-card">
            <div className="sn-stat-icon sn-stat-icon-green">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#059669" strokeWidth="2">
                <polyline points="20 6 9 17 4 12" />
              </svg>
            </div>
            <div className="sn-stat-info">
              <span className="sn-stat-label">Active</span>
              <span className="sn-stat-number">{activeCount}</span>
            </div>
          </div>
        </div>

        {/* ── Filters / Toolbar ── */}
        <div className="sn-toolbar">
          <div className="sn-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15">
              <circle cx="11" cy="11" r="8" />
              <path d="m21 21-4.35-4.35" />
            </svg>
            <input
              type="text"
              placeholder="Search suppliers..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
            />
          </div>
        </div>

        {/* ── Supplier Content ── */}
        {loading ? (
          <div className="sn-loading">Loading suppliers...</div>
        ) : suppliers.length === 0 ? (
          <div className="sn-empty">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" strokeWidth="1.5">
              <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
              <circle cx="9" cy="7" r="4" />
              <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
              <path d="M16 3.13a4 4 0 0 1 0 7.75" />
            </svg>
            <p>No suppliers found</p>
            {!isInventoryAnalystViewOnly && (
              <button className="sn-btn-add" onClick={handleAdd}>Add your first supplier</button>
            )}
          </div>
        ) : viewMode === 'cards' ? (
          <div className="sn-cards-grid">
            {suppliers.map((s) => (
              <div key={s.supplier_id} className="sn-card" onClick={() => openViewProductsModal(s)} style={{ cursor: 'pointer' }}>
                <div className="sn-card-header">
                  <h3 className="sn-card-name">{s.supplier_name}</h3>
                  {s.contact_person && (
                    <p className="sn-card-contact-person">{s.contact_person}</p>
                  )}
                </div>

                <div className="sn-card-details">
                  {s.email && (
                    <div className="sn-card-detail-row">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" />
                        <polyline points="22,6 12,13 2,6" />
                      </svg>
                      <span>{s.email}</span>
                    </div>
                  )}
                  {s.contact_number && (
                    <div className="sn-card-detail-row">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.61 1h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" />
                      </svg>
                      <span>{s.contact_number}</span>
                    </div>
                  )}
                  {s.address && (
                    <div className="sn-card-detail-row">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13">
                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                        <circle cx="12" cy="10" r="3" />
                      </svg>
                      <span className="sn-card-address">{s.address}</span>
                    </div>
                  )}
                </div>

                <div className="sn-card-footer">
                  <div className={`sn-status-badge ${s.status?.status_name?.toLowerCase() === 'inactive' ? 'sn-status-inactive' : ''}`}>
                    <span className="sn-status-dot"></span>
                    {s.status?.status_name
                      ? s.status.status_name.toUpperCase() + ' VENDOR'
                      : 'ACTIVE VENDOR'}
                  </div>
                  {!isInventoryAnalystViewOnly && (
                    <div className="sn-card-actions" onClick={(e) => e.stopPropagation()}>
                      <button
                        className="sn-action-btn sn-action-products"
                        onClick={() => openProductsModal(s)}
                        title="Manage Products"
                      >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13">
                          <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
                        </svg>
                      </button>
                      <button
                        className="sn-action-btn sn-action-edit"
                        onClick={() => handleEdit(s)}
                        title="Edit"
                      >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13">
                          <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                          <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                        </svg>
                      </button>
                      <button
                        className="sn-action-btn sn-action-delete"
                        onClick={() => handleDelete(s)}
                        title="Delete"
                      >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13">
                          <polyline points="3 6 5 6 21 6" />
                          <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                          <path d="M10 11v6" />
                          <path d="M14 11v6" />
                          <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" />
                        </svg>
                      </button>
                    </div>
                  )}
                </div>
              </div>
            ))}
          </div>
        ) : (
          /* ── List View ── */
          <div className="sn-list-card">
            <div className="sn-table-wrapper">
              <table className="sn-table">
                <thead>
                  <tr>
                    <th>Supplier</th>
                    <th>Contact Number</th>
                    <th>Email</th>
                    <th>TIN</th>
                    <th>Status</th>
                    <th style={{ width: 110 }}>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {suppliers.map((s) => (
                    <tr key={s.supplier_id} onClick={() => openViewProductsModal(s)} style={{ cursor: 'pointer' }}>
                      <td>
                        <div className="sn-td-name">{s.supplier_name}</div>
                        {s.contact_person && (
                          <div className="sn-td-sub">{s.contact_person}</div>
                        )}
                      </td>
                      <td>{s.contact_number || '—'}</td>
                      <td className="sn-td-ellipsis" title={s.email || ''}>{s.email || '—'}</td>
                      <td className="sn-td-muted">{s.tin || '—'}</td>
                      <td>
                        <div className={`sn-status-badge ${s.status?.status_name?.toLowerCase() === 'inactive' ? 'sn-status-inactive' : ''}`}>
                          <span className="sn-status-dot"></span>
                          {s.status?.status_name
                            ? s.status.status_name.toUpperCase()
                            : 'ACTIVE'}
                        </div>
                      </td>
                      <td onClick={(e) => e.stopPropagation()}>
                        {!isInventoryAnalystViewOnly && (
                          <div className="sn-card-actions">
                            <button
                              className="sn-action-btn sn-action-products"
                              onClick={() => openProductsModal(s)}
                              title="Manage Products"
                            >
                              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13">
                                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
                              </svg>
                            </button>
                            <button
                              className="sn-action-btn sn-action-edit"
                              onClick={() => handleEdit(s)}
                              title="Edit"
                            >
                              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                              </svg>
                            </button>
                            <button
                              className="sn-action-btn sn-action-delete"
                              onClick={() => handleDelete(s)}
                              title="Delete"
                            >
                              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13">
                                <polyline points="3 6 5 6 21 6" />
                                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                                <path d="M10 11v6" />
                                <path d="M14 11v6" />
                                <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" />
                              </svg>
                            </button>
                          </div>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </div>

      {/* ── Add / Edit Supplier Modal ── */}
      <Modal
        isOpen={isFormOpen}
        onClose={() => setIsFormOpen(false)}
        title={editingSupplier ? 'Edit Supplier' : 'Add New Supplier'}
      >
        <form onSubmit={handleSubmit} className="sn-form">
          <div className="sn-form-row">
            <div className="sn-form-field">
              <label>SUPPLIER NAME *</label>
              <input
                type="text"
                value={formData.supplier_name}
                onChange={(e) => handleFormChange('supplier_name', e.target.value)}
                placeholder="e.g. Samsung Philippines"
                required
              />
            </div>
            <div className="sn-form-field">
              <label>CONTACT PERSON</label>
              <input
                type="text"
                value={formData.contact_person}
                onChange={(e) => handleFormChange('contact_person', e.target.value)}
                placeholder="e.g. Juan dela Cruz"
              />
            </div>
          </div>

          <div className="sn-form-row">
            <div className="sn-form-field">
              <label>CONTACT NUMBER *</label>
              <input
                type="text"
                value={formData.contact_number}
                onChange={(e) => handleFormChange('contact_number', e.target.value)}
                placeholder="+63 2 XXX XXXX"
                required
              />
            </div>
            <div className="sn-form-field">
              <label>EMAIL</label>
              <input
                type="email"
                value={formData.email}
                onChange={(e) => handleFormChange('email', e.target.value)}
                placeholder="supplier@example.com"
              />
            </div>
          </div>


          <div className="sn-form-field">
            <label>ADDRESS</label>
            <input
              type="text"
              value={formData.address}
              onChange={(e) => handleFormChange('address', e.target.value)}
              placeholder="Complete address"
            />
          </div>

          <div className={editingSupplier ? 'sn-form-row' : ''}>
            <div className="sn-form-field">
              <label>TIN</label>
              <input
                type="text"
                value={formData.tin}
                onChange={(e) => handleFormChange('tin', e.target.value)}
                placeholder="Tax Identification Number"
              />
            </div>
            {editingSupplier && (
              <div className="sn-form-field">
                <label>STATUS</label>
                <select
                  value={formData.status_id}
                  onChange={(e) => handleFormChange('status_id', e.target.value)}
                >
                  <option value="">Select status</option>
                  {statuses.map((st) => (
                    <option key={st.status_id} value={st.status_id}>
                      {st.status_name}
                    </option>
                  ))}
                </select>
              </div>
            )}
          </div>

          <div className="sn-form-field">
            <label>NOTES</label>
            <textarea
              value={formData.notes}
              onChange={(e) => handleFormChange('notes', e.target.value)}
              rows={2}
              placeholder="Additional notes..."
            />
          </div>

          <div className="sn-modal-footer">
            <button type="button" className="sn-btn-cancel" onClick={() => setIsFormOpen(false)}>
              CANCEL
            </button>
            <button type="submit" className="sn-btn-confirm" disabled={submitting}>
              {submitting ? 'SAVING...' : editingSupplier ? 'UPDATE SUPPLIER' : 'ADD SUPPLIER'}
            </button>
          </div>
        </form>
      </Modal>

      {/* ── View-Only Products Modal (card / row click) ── */}
      {viewModal && (
        <Modal
          isOpen={!!viewModal}
          onClose={() => setViewModal(null)}
          title={`Products — ${viewModal.supplier_name}`}
        >
          <div className="sn-products-modal">
            {loadingViewProducts ? (
              <div className="sn-loading">Loading...</div>
            ) : viewProducts.length === 0 ? (
              <div className="sn-products-empty">No products linked yet.</div>
            ) : (
              <table className="sn-products-table">
                <thead>
                  <tr>
                    <th>Product</th>
                    <th>Code</th>
                    <th>Category</th>
                    <th>Price</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {viewProducts.map((p) => (
                    <tr key={p.supplier_prod_id}>
                      <td>{p.product_name}</td>
                      <td className="sn-code-cell">{p.product_code}</td>
                      <td>{p.category || '—'}</td>
                      <td>
                        {p.product_price != null
                          ? `${p.currency || 'PHP'} ${Number(p.product_price).toLocaleString()}`
                          : '—'}
                      </td>
                      <td>
                        {p.status_name
                          ? <span className="sn-prod-status-badge">{p.status_name}</span>
                          : <span className="sn-td-muted">—</span>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
            <div className="sn-modal-footer">
              <button className="sn-btn-cancel" onClick={() => setViewModal(null)}>CLOSE</button>
            </div>
          </div>
        </Modal>
      )}

      {/* ── Supplier Products Modal ── */}
      {productsModal && (
        <Modal
          isOpen={!!productsModal}
          onClose={() => { setProductsModal(null); setEditingProduct(null); }}
          title={`Products — ${productsModal.supplier_name}`}
        >
          <div className="sn-products-modal">
            {/* ── Add product row ── */}
            <div className="sn-add-product-section">
              <div className="sn-add-product-row">
                <select
                  value={addProductId}
                  onChange={(e) => setAddProductId(e.target.value)}
                  className="sn-product-select"
                >
                  <option value="">Select product to link...</option>
                  {availableProducts.map((p) => (
                    <option key={p.product_id ?? p.id} value={p.product_id ?? p.id}>
                      {p.product_name || p.name} ({p.product_code || p.code})
                    </option>
                  ))}
                </select>
                <input
                  type="number"
                  step="0.01"
                  min="0"
                  placeholder="Price"
                  value={addProductPrice}
                  onChange={(e) => setAddProductPrice(e.target.value)}
                  className="sn-product-price-input"
                />
                <select
                  value={addProductCurrency}
                  onChange={(e) => setAddProductCurrency(e.target.value)}
                  className="sn-currency-select"
                >
                  <option value="PHP">PHP</option>
                  <option value="USD">USD</option>
                  <option value="EUR">EUR</option>
                  <option value="CNY">CNY</option>
                  <option value="JPY">JPY</option>
                </select>
                <select
                  value={addProductStatus}
                  onChange={(e) => setAddProductStatus(e.target.value)}
                  className="sn-status-select"
                >
                  <option value="">Status</option>
                  {statuses.map((st) => (
                    <option key={st.status_id} value={st.status_id}>{st.status_name}</option>
                  ))}
                </select>
                <button
                  className="sn-btn-confirm sn-btn-link"
                  onClick={handleAddProduct}
                  disabled={!addProductId || addingProduct}
                >
                  {addingProduct ? '...' : 'LINK'}
                </button>
              </div>
            </div>

            {/* ── Edit inline panel ── */}
            {editingProduct && (
              <div className="sn-edit-product-panel">
                <span className="sn-edit-product-label">
                  Editing: <strong>{editingProduct.product_name}</strong>
                </span>
                <input
                  type="number"
                  step="0.01"
                  min="0"
                  placeholder="Price"
                  value={editPrice}
                  onChange={(e) => setEditPrice(e.target.value)}
                  className="sn-product-price-input"
                />
                <select
                  value={editCurrency}
                  onChange={(e) => setEditCurrency(e.target.value)}
                  className="sn-currency-select"
                >
                  <option value="PHP">PHP</option>
                  <option value="USD">USD</option>
                  <option value="EUR">EUR</option>
                  <option value="CNY">CNY</option>
                  <option value="JPY">JPY</option>
                </select>
                <select
                  value={editStatusId}
                  onChange={(e) => setEditStatusId(e.target.value)}
                  className="sn-status-select"
                >
                  <option value="">No status</option>
                  {statuses.map((st) => (
                    <option key={st.status_id} value={st.status_id}>{st.status_name}</option>
                  ))}
                </select>
                <button
                  className="sn-btn-confirm sn-btn-link"
                  onClick={handleSaveEditProduct}
                  disabled={savingEdit}
                >
                  {savingEdit ? '...' : 'SAVE'}
                </button>
                <button
                  className="sn-btn-cancel"
                  onClick={() => setEditingProduct(null)}
                >
                  CANCEL
                </button>
              </div>
            )}

            {loadingProducts ? (
              <div className="sn-loading">Loading...</div>
            ) : linkedProducts.length === 0 ? (
              <div className="sn-products-empty">No products linked yet.</div>
            ) : (
              <table className="sn-products-table">
                <thead>
                  <tr>
                    <th>Product</th>
                    <th>Code</th>
                    <th>Category</th>
                    <th>Price</th>
                    <th>Status</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  {linkedProducts.map((p) => (
                    <tr key={p.supplier_prod_id} className={editingProduct?.supplier_prod_id === p.supplier_prod_id ? 'sn-row-editing' : ''}>
                      <td>{p.product_name}</td>
                      <td className="sn-code-cell">{p.product_code}</td>
                      <td>{p.category || '—'}</td>
                      <td>
                        {p.product_price != null
                          ? `${p.currency || 'PHP'} ${Number(p.product_price).toLocaleString()}`
                          : '—'}
                      </td>
                      <td>
                        {p.status_name
                          ? <span className="sn-prod-status-badge">{p.status_name}</span>
                          : <span className="sn-td-muted">—</span>}
                      </td>
                      <td>
                        <div className="sn-prod-actions">
                          <button
                            className="sn-edit-prod-btn"
                            onClick={() => handleEditProduct(p)}
                            title="Edit"
                          >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="11" height="11">
                              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                              <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                            </svg>
                          </button>
                          <button
                            className="sn-unlink-btn"
                            onClick={() => handleRemoveProduct(p.product_id)}
                            title="Remove"
                          >
                            ×
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}

            <div className="sn-modal-footer">
              <button className="sn-btn-cancel" onClick={() => { setProductsModal(null); setEditingProduct(null); }}>
                CLOSE
              </button>
            </div>
          </div>
        </Modal>
      )}
    </AdminLayout>
  );
};

export default SupplierNetwork;
