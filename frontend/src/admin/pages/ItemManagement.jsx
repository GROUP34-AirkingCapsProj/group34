import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import Modal from '../components/Modal';
import ArchiveButton from '../components/ArchiveButton';
import Barcode from 'react-barcode';
import {
  productsAPI,
  categoriesAPI,
  brandsAPI,
  unitsAPI,
  batchAPI,
  statusAPI,
  capacitiesAPI,
  variantsAPI,
} from '../services/api';
import { toast } from '../utils/toast';
import { useFocusTarget } from '../hooks/useFocusTarget';
import { useFocusPulse } from '../hooks/useFocusPulse';
import { findById } from '../utils/findById';
import { getPageBatch } from '../utils/pageBatch';
import { useAuth } from '../context/AuthContext';
import { getRoleKey, ROLES } from '../utils/roles';
import '../styles/dashboard_air.css';
import '../styles/item_management.css';
import '../styles/archive_management.css';

const ItemManagement = () => {
  const { user, can } = useAuth();
  const roleKey = getRoleKey(user || {});
  // Permission-driven: can edit items if admin or granted item master create/update.
  const canManageItems = roleKey === ROLES.ADMIN
    || (typeof can === 'function' && (can('item_master_management', 'create') || can('item_master_management', 'update')));
  const isViewOnly = !canManageItems;
  const [items, setItems] = useState([]);
  const [searchTerm, setSearchTerm] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingItem, setEditingItem] = useState(null);
  const [viewingItem, setViewingItem] = useState(null);
  const focusProductId = useFocusTarget('product');
  const focusHandledRef = useRef(false);
  const [itemPulse, fireItemPulse] = useFocusPulse();
  const [currentPage, setCurrentPage] = useState(1);
  const [pagination, setPagination] = useState({ total: 0, last_page: 1 });
  const [productType, setProductType] = useState('appliance');
  const [activeTab, setActiveTab] = useState('all');
  const [typeCounts, setTypeCounts] = useState({ all: 0, appliance: 0, consumable: 0 });
  const [filterCategory, setFilterCategory] = useState('');
  const [filterBrand, setFilterBrand] = useState('');
  const rowsPerPage = 10;
  const debounceRef = useRef(null);

  const [categories, setCategories] = useState([]);
  const [brands, setBrands] = useState([]);
  const [units, setUnits] = useState([]);
  const [statuses, setStatuses] = useState([]);
  const [capacities, setCapacities] = useState([]);
  const [variants, setVariants] = useState([]);
  const [isAddCategoryOpen, setIsAddCategoryOpen] = useState(false);
  const [isAddBrandOpen, setIsAddBrandOpen] = useState(false);
  const [isAddUnitOpen, setIsAddUnitOpen] = useState(false);
  const [newCategoryName, setNewCategoryName] = useState('');
  const [newBrandName, setNewBrandName] = useState('');
  const [newUnitName, setNewUnitName] = useState('');
  const [newUnitAbbrev, setNewUnitAbbrev] = useState('');
  const [formData, setFormData] = useState({
    name: '',
    code: '',
    barcode: '',
    category_id: '',
    brand_id: '',
    model_code: '',
    unit_id: '',
    unit_price: '',
    cost_price: '',
    warranty_value: '',
    warranty_unit: 'month',
    status_id: '',
    variant: '',
    color: '',
    capacity_rating: '',
    description: '',
    pieces_per_package: '',
    package_unit: '',
    recommended_stocks: '',
    safety_stock: '',
    max_stock_level: '',
  });

  const fetchLookups = useCallback(async () => {
    try {
      const batchRes = await batchAPI.get({ include: ['categories', 'brands', 'units', 'capacities', 'variants'] });
      const batchData = batchRes?.data || {};
      setCategories(Array.isArray(batchData.categories?.data) ? batchData.categories.data : []);
      setBrands(Array.isArray(batchData.brands?.data) ? batchData.brands.data : []);
      setUnits(Array.isArray(batchData.units?.data) ? batchData.units.data : []);
      setCapacities(Array.isArray(batchData.capacities?.data) ? batchData.capacities.data : []);
      setVariants(Array.isArray(batchData.variants?.data) ? batchData.variants.data : []);
    } catch (err) {
      console.error('Failed to fetch lookups via batch:', err);
      try {
        const [catRes, brandRes, unitsRes, capRes, varRes] = await Promise.all([
          categoriesAPI.getAll(),
          brandsAPI.getAll(),
          unitsAPI.getAll(),
          capacitiesAPI.getAll(),
          variantsAPI.getAll(),
        ]);
        setCategories(Array.isArray(catRes?.data) ? catRes.data : []);
        setBrands(Array.isArray(brandRes?.data) ? brandRes.data : []);
        setCapacities(Array.isArray(capRes?.data) ? capRes.data : []);
        setVariants(Array.isArray(varRes?.data) ? varRes.data : []);
        setUnits(Array.isArray(unitsRes?.data) ? unitsRes.data : []);
      } catch (fallbackErr) {
        console.error('Fallback fetch also failed:', fallbackErr);
      }
    }
    // Statuses — filter to product category only (3=Active, 4=Inactive)
    try {
      const statusRes = await statusAPI.getAll();
      const all = Array.isArray(statusRes?.data) ? statusRes.data : [];
      setStatuses(all.filter(s => s.status_category === 'product'));
    } catch (statusErr) {
      console.error('Failed to fetch statuses:', statusErr);
      setStatuses([]);
    }
  }, []);

  useEffect(() => {
    fetchLookups();
  }, [fetchLookups]);

  const fetchItems = useCallback(async (page = 1) => {
    try {
      setLoading(true);
      const params = { page, per_page: rowsPerPage };
      if (searchTerm) params.search = searchTerm;
      if (filterCategory) params.category_id = filterCategory;
      if (filterBrand) params.brand_id = filterBrand;
      if (activeTab !== 'all') params.product_type = activeTab;
      const res = await productsAPI.getAll(params);
      const list =
        Array.isArray(res?.data)
          ? res.data
          : Array.isArray(res)
            ? res
            : Array.isArray(res?.data?.data)
              ? res.data.data
              : [];
      setItems(list);
      setPagination(res?.pagination || res?.meta || { total: list.length, last_page: 1 });
      setError(null);
    } catch (err) {
      setError(err.message);
      setItems([]);
      console.error('Failed to fetch items:', err);
    } finally {
      setLoading(false);
    }
  }, [searchTerm, filterCategory, filterBrand, activeTab, rowsPerPage]);

  useEffect(() => {
    fetchItems(currentPage);
  }, [currentPage, fetchItems]);

  useEffect(() => {
    if (!focusProductId || focusHandledRef.current) return;
    if (loading) return;
    focusHandledRef.current = true;
    const item = findById(items, ['product_id', 'id'], focusProductId);
    if (item) { setViewingItem(item); fireItemPulse(); }
    else toast.error('That product is not available.');
  }, [focusProductId, loading, items, fireItemPulse]);

  useEffect(() => {
    const fetchTypeCounts = async () => {
      try {
        const shared = {};
        if (searchTerm) shared.search = searchTerm;
        if (filterCategory) shared.category_id = filterCategory;
        if (filterBrand) shared.brand_id = filterBrand;
        const [allRes, appRes, conRes] = await Promise.all([
          productsAPI.getAll({ ...shared, per_page: 1 }),
          productsAPI.getAll({ ...shared, per_page: 1, product_type: 'appliance' }),
          productsAPI.getAll({ ...shared, per_page: 1, product_type: 'installation_material' }),
        ]);
        const getTotal = (res) => {
          if (!res) return 0;
          if (res.pagination?.total != null) return res.pagination.total;
          if (res.meta?.total != null) return res.meta.total;
          if (Array.isArray(res?.data)) return res.data.length;
          if (Array.isArray(res)) return res.length;
          if (Array.isArray(res?.data?.data)) return res.data.data.length;
          return 0;
        };
        setTypeCounts({
          all: getTotal(allRes),
          appliance: getTotal(appRes),
          consumable: getTotal(conRes),
        });
      } catch {}
    };
    fetchTypeCounts();
  }, [searchTerm, filterCategory, filterBrand]);

  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => {
      setCurrentPage(1);
    }, 400);
    return () => clearTimeout(debounceRef.current);
  }, [searchTerm, filterCategory, filterBrand, activeTab]);

  const resetForm = () => {
    setFormData({
      name: '', code: '', barcode: '', category_id: '', brand_id: '', model_code: '', unit_id: '',
      unit_price: '', cost_price: '', warranty_value: 0, warranty_unit: 'month', status_id: '',
      variant: '', color: '',
      capacity_rating: '', description: '', pieces_per_package: '', package_unit: '',
      recommended_stocks: '', safety_stock: '', max_stock_level: '',
    });
    setProductType('appliance');
  };

  const handleAdd = () => {
    if (isViewOnly) {
      toast.info('Item Master is view-only for Inventory Analyst.');
      return;
    }
    setEditingItem(null);
    resetForm();
    setIsModalOpen(true);
  };

  const handleArchive = useCallback(async (item) => {
    try {
      await productsAPI.delete(item.product_id ?? item.id);
      toast.success('Product archived.');
      fetchItems(currentPage);
    } catch (err) { toast.error(err.message || 'Failed to archive.'); }
  }, [currentPage]);

  const handleEdit = (item) => {
    if (isViewOnly) {
      toast.info('Editing items is disabled for Inventory Analyst.');
      return;
    }
    setEditingItem(item);
    const storedType = item.product_type;
    const legacyIsConsumable =
      !storedType &&
      ((item.unit?.unit_name || '').toLowerCase() === 'roll' ||
        (item.category?.category_name || '').toLowerCase().includes('consumable') ||
        (item.category?.category_name || '').toLowerCase().includes('parts') ||
        (item.category?.category_name || '').toLowerCase().includes('installation'));
    const resolvedType = storedType || (legacyIsConsumable ? 'installation_material' : 'appliance');
    setProductType(resolvedType);
    setFormData({
      name: item.product_name || item.name || '',
      code: item.product_code || item.code || '',
      barcode: item.barcode || '',
      category_id: item.category_id ?? item.category?.category_id ?? '',
      // V5 flat shim fields — set directly on product object by backend
      brand_id: item.brand_id ?? '',
      model_code: item.model_code || item.model_name || '',
      unit_id: item.unit_id ?? '',
      unit_price: item.unit_price ?? item.price ?? '',
      cost_price: item.cost_price ?? item.unit_price ?? item.price ?? '',
      warranty_value: item.warranty_value != null ? item.warranty_value : (() => {
        const m = item.warranty_period_months ?? 0;
        if (m > 0 && m % 12 === 0) return m / 12;
        return m > 0 ? m : 0;
      })(),
      warranty_unit: item.warranty_unit ?? (() => {
        const m = item.warranty_period_months ?? 0;
        return (m > 0 && m % 12 === 0) ? 'year' : 'month';
      })(),
      status_id: item.status_id ?? '',
      variant: item.variant || '',
      color: item.color || '',
      capacity_rating: item.capacity_rating || '',
      description: item.description || '',
      pieces_per_package: item.pieces_per_package ?? '',
      package_unit: item.package_unit || '',
      recommended_stocks: item.recommended_stocks ?? '',
      safety_stock: item.safety_stock ?? '',
      max_stock_level: item.max_stock_level ?? '',
    });
    setIsModalOpen(true);
  };

  const handleView = (item) => {
    setViewingItem(item);
  };

  const handleCreateCategory = async (e) => {
    if (isViewOnly) {
      toast.info('Creating categories is disabled for Inventory Analyst.');
      return;
    }
    e.preventDefault();
    const name = newCategoryName.trim();
    if (!name) return;
    try {
      // Tag the new category with the current form's product type so it appears
      // in the type-filtered category dropdown (appliance vs installation material).
      const category_type = productType === 'installation_material' ? 'installation_material' : 'appliance';
      const res = await categoriesAPI.create({ category_name: name, category_type });
      const created = res?.data || res;
      if (created) {
        setCategories((prev) => [...prev, created]);
        setFormData((prev) => ({
          ...prev,
          category_id: created.category_id ?? prev.category_id,
        }));
      }
      setNewCategoryName('');
      setIsAddCategoryOpen(false);
      toast.success('Category added.');
    } catch (err) {
      toast.error(err.message || 'Failed to add category');
    }
  };

  const handleCreateBrand = async (e) => {
    if (isViewOnly) {
      toast.info('Creating brands is disabled for Inventory Analyst.');
      return;
    }
    e.preventDefault();
    const name = newBrandName.trim();
    if (!name) return;
    try {
      const res = await brandsAPI.create({ brand_name: name });
      const created = res?.data || res;
      if (created) {
        setBrands((prev) => [...prev, created]);
        setFormData((prev) => ({
          ...prev,
          brand_id: created.brand_id ?? prev.brand_id,
        }));
      }
      setNewBrandName('');
      setIsAddBrandOpen(false);
      toast.success('Brand added.');
    } catch (err) {
      toast.error(err.message || 'Failed to add brand');
    }
  };

  const handleCreateUnit = async (e) => {
    e.preventDefault();
    const name = newUnitName.trim();
    if (!name) return;
    try {
      const res = await unitsAPI.create({
        unit_name: name,
        unit_abbreviation: newUnitAbbrev.trim() || null,
      });
      const created = res?.data || res;
      if (created) {
        setUnits((prev) => [...prev, created]);
        if (created.unit_id != null) {
          setFormData((prev) => ({ ...prev, unit_id: created.unit_id }));
        }
      }
      setNewUnitName('');
      setNewUnitAbbrev('');
      setIsAddUnitOpen(false);
      toast.success('Unit added.');
    } catch (err) {
      toast.error(err.message || 'Failed to add unit');
    }
  };

  const handleGenerateBarcode = () => {
    const typeDigit = productType === 'installation_material' ? '2' : '1';
    const timePart = Date.now().toString().slice(-8);
    const randomPart = String(Math.floor(Math.random() * 1000)).padStart(3, '0');
    const payload12 = `${typeDigit}${timePart}${randomPart}`;
    const sum = payload12
      .split('')
      .map((d) => Number(d))
      .reduce((acc, d, idx) => acc + (idx % 2 === 0 ? d : d * 3), 0);
    const checksum = (10 - (sum % 10)) % 10;
    const generated = `${payload12}${checksum}`;
    setFormData((prev) => ({ ...prev, barcode: generated }));
    toast.success('Barcode generated.');
  };

  const getBarcodeFormat = (value) =>
    /^\d{13}$/.test(String(value || '')) ? 'EAN13' : 'CODE128';

  const handleSubmit = async (e) => {
    if (isViewOnly) {
      toast.info('Saving items is disabled for Inventory Analyst.');
      return;
    }
    e.preventDefault();
    const isAppliance = productType === 'appliance';
    if (isAppliance && isCapacityRequired() && !formData.capacity_rating) {
      toast.error('Capacity is required only for Aircon and Refrigerator categories.');
      return;
    }
    const payload = {
      // Shared fields
      product_code:       formData.code || undefined,
      product_name:       formData.name,
      product_type:       productType,
      barcode:            formData.barcode || null,
      category_id:        formData.category_id || null,
      description:        formData.description || null,
      unit_price:         Number(formData.unit_price) || 0,
      cost_price:         Number(formData.cost_price) || Number(formData.unit_price) || 0,
      status_id:          formData.status_id || null,
      recommended_stocks: formData.recommended_stocks !== '' ? Number(formData.recommended_stocks) : null,
      safety_stock:       formData.safety_stock !== '' ? Number(formData.safety_stock) : null,
      max_stock_level:    formData.max_stock_level !== '' ? Number(formData.max_stock_level) : null,
      // Appliance-specific
      ...(isAppliance ? {
        brand_id:        formData.brand_id || null,
        model_code:      formData.model_code || null,
        capacity_rating: formData.capacity_rating || null,
        variant:         formData.variant || null,
        color:           formData.color || null,
        warranty_value:  Number(formData.warranty_value) || 0,
        warranty_unit:   formData.warranty_unit || 'month',
      } : {}),
      // Consumable-specific
      ...(!isAppliance ? {
        unit_id:            formData.unit_id || null,
        pieces_per_package: formData.pieces_per_package ? Number(formData.pieces_per_package) : null,
        package_unit:       formData.package_unit || null,
        warranty_value:     Number(formData.warranty_value) || 0,
        warranty_unit:      formData.warranty_unit || 'month',
      } : {}),
    };
    try {
      if (editingItem) {
        await productsAPI.update(editingItem.product_id ?? editingItem.id, payload);
        toast.success('Product updated successfully!');
      } else {
        await productsAPI.create(payload);
        toast.success('Product registered successfully!');
      }
      setIsModalOpen(false);
      fetchItems(currentPage);
    } catch (err) {
      if (err.errors && typeof err.errors === 'object') {
        const messages = Object.entries(err.errors)
          .map(([field, msgs]) => `${field}: ${Array.isArray(msgs) ? msgs.join(', ') : msgs}`)
          .join('; ');
        toast.error(messages || err.message || 'Failed to save product');
        console.error('Validation errors:', err.errors);
      } else {
        toast.error(err.message || 'Failed to save product');
      }
    }
  };

  const totalPages = pagination.last_page || 1;

  // Compute stats — use the stored product_type; fall back to heuristic for legacy rows
  const resolveType = (i) => {
    if (i.product_type) return i.product_type;
    const u = (i.unit?.unit_name || '').toLowerCase();
    const c = (i.category?.category_name || '').toLowerCase();
    return (u === 'roll' || u === 'box' || c.includes('consumable') || c.includes('parts') || c.includes('installation'))
      ? 'installation_material' : 'appliance';
  };
  const categoryCount = [...new Set(items.map(i => i.category_id || i.category?.category_id).filter(Boolean))].length;

  const normalizeFilterValue = (value) => (value == null ? '' : String(value));

  /** Hide incomplete catalog rows: barcode always; appliances also need capacity. */
  const passesCatalogCompleteness = (item) => {
    if (!String(item.barcode || '').trim()) return false;
    if (resolveType(item) === 'appliance') {
      if (!String(item.capacity_rating || '').trim()) return false;
    }
    return true;
  };

  const isCapacityRequired = () => {
    if (productType !== 'appliance') return false;
    const cat = categories.find(
      (c) => String(c.category_id) === String(formData.category_id || '')
    );
    if (!cat) return false;
    const name = (cat.category_name || '').toLowerCase();
    return name.includes('refrigerator') || name.includes('aircon') || name.includes('air conditioning');
  };

  /** Capacity options, plus the item's current value if it would otherwise be dropped. */
  const filteredCapacities = useMemo(() => {
    const base = capacities;
    const current = formData.capacity_rating;
    if (current && !base.some((c) => c.capacity_value === current)) {
      return [...base, { capacity_id: `__current-${current}`, capacity_value: current, __archived: true }];
    }
    return base;
  }, [capacities, formData.capacity_rating]);

  /** Variant options, plus the item's current value if it would otherwise be dropped. */
  const filteredVariants = useMemo(() => {
    const base = variants;
    const current = formData.variant;
    if (current && !base.some((v) => v.variant_name === current)) {
      return [...base, { variant_id: `__current-${current}`, variant_name: current, __archived: true }];
    }
    return base;
  }, [variants, formData.variant]);

  /** True once at least one real (non-archived) capacity/variant option exists. */
  const hasFilteredCapacities = filteredCapacities.some((c) => !c.__archived);
  const hasFilteredVariants = filteredVariants.some((v) => !v.__archived);

  // Filtered items (client-side safeguard; backend params are also applied)
  const filteredItems = items.filter(item => {
    if (
      filterCategory &&
      normalizeFilterValue(item.category_id ?? item.category?.category_id) !== normalizeFilterValue(filterCategory)
    ) return false;
    if (
      filterBrand &&
      normalizeFilterValue(item.brand_id ?? item.brand?.brand_id) !== normalizeFilterValue(filterBrand)
    ) return false;
    if (activeTab !== 'all' && resolveType(item) !== activeTab) return false;
    return true;
  });

  const getTypeBadge = (item) => {
    const type = resolveType(item);
    if (type === 'installation_material') {
      return { label: 'INSTALLATION MATERIAL', className: 'im-type-consumable' };
    }
    return { label: 'APPLIANCE', className: 'im-type-unit' };
  };

  // Stock parameters for installation materials are entered in PIECES
  // (convention: docs/superpowers/specs/2026-07-20-restock-package-units-design.md).
  const stockParamUnitHint = (() => {
    if (productType !== 'installation_material') return null;
    const u = units.find(un => String(un.unit_id) === String(formData.unit_id));
    return `in pieces${u?.unit_abbreviation ? ` (${u.unit_abbreviation})` : ''}`;
  })();

  return (
    <AdminLayout>
      <div className="ak-page im-page">
        {/* Page Header */}
        <header className="ak-header">
         <div className="ak-header-row">
          <div className="im-page-header-left">
            <p className="ak-eyebrow">Catalog · Products</p>
            <h1 className="ak-title">Product Management</h1>
            <p className="ak-sub">Central repository for product specifications and material catalog.</p>
          </div>
          <div className="ak-header-aside ak-actions im-page-header-right">
            <div className="im-header-filters">
              <select
                className="ak-select im-filter-select"
                value={filterCategory}
                onChange={(e) => {
                  setFilterCategory(e.target.value);
                  setCurrentPage(1);
                }}
              >
                <option value="">CATEGORY</option>
                {categories.map((c) => (
                  <option key={c.category_id} value={c.category_id}>{c.category_name}</option>
                ))}
              </select>
              <select
                className="ak-select im-filter-select"
                value={filterBrand}
                onChange={(e) => {
                  setFilterBrand(e.target.value);
                  setCurrentPage(1);
                }}
              >
                <option value="">BRAND</option>
                {brands.map((b) => (
                  <option key={b.brand_id} value={b.brand_id}>{b.brand_name}</option>
                ))}
              </select>
            </div>
            {!isViewOnly && (
              <button className="ak-btn ak-btn--primary im-btn-create" onClick={handleAdd}>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="16" height="16">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                ADD NEW PRODUCT
              </button>
            )}
          </div>
         </div>
        </header>

        {/* Stats Cards */}
        <div className="im-stats-row">
          <div className="im-stat-card">
            <div className="im-stat-icon im-stat-icon-red">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#dc2626" strokeWidth="2">
                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
              </svg>
            </div>
            <div className="im-stat-info">
              <span className="im-stat-label">Total Products</span>
              <span className="im-stat-number">{typeCounts.all}</span>
            </div>
          </div>
          <div className="im-stat-card">
            <div className="im-stat-icon im-stat-icon-blue">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" strokeWidth="2">
                <rect x="4" y="4" width="16" height="16" rx="2"></rect>
                <line x1="4" y1="12" x2="20" y2="12"></line>
              </svg>
            </div>
            <div className="im-stat-info">
              <span className="im-stat-label">Appliances</span>
              <span className="im-stat-number">{typeCounts.appliance}</span>
            </div>
          </div>
          <div className="im-stat-card">
            <div className="im-stat-icon im-stat-icon-green">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#059669" strokeWidth="2">
                <circle cx="12" cy="12" r="3"></circle>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33"></path>
              </svg>
            </div>
            <div className="im-stat-info">
              <span className="im-stat-label">Installation Materials</span>
              <span className="im-stat-number">{typeCounts.consumable}</span>
            </div>
          </div>
          <div className="im-stat-card">
            <div className="im-stat-icon im-stat-icon-orange">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" strokeWidth="2">
                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
              </svg>
            </div>
            <div className="im-stat-info">
              <span className="im-stat-label">Categories</span>
              <span className="im-stat-number">{categoryCount}</span>
            </div>
          </div>
        </div>

        {/* Type Tabs */}
        <div className="im-tabs">
          <button
            className={`im-tab-btn ${activeTab === 'all' ? 'active' : ''}`}
            onClick={() => { setActiveTab('all'); setCurrentPage(1); }}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
              <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
            </svg>
            All Products
            <span className="im-tab-count">{typeCounts.all}</span>
          </button>
          <button
            className={`im-tab-btn ${activeTab === 'appliance' ? 'active' : ''}`}
            onClick={() => { setActiveTab('appliance'); setCurrentPage(1); }}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
              <rect x="4" y="4" width="16" height="16" rx="2" />
              <line x1="4" y1="12" x2="20" y2="12" />
            </svg>
            Appliances
            <span className="im-tab-count">{typeCounts.appliance}</span>
          </button>
          <button
            className={`im-tab-btn ${activeTab === 'installation_material' ? 'active' : ''}`}
            onClick={() => { setActiveTab('installation_material'); setCurrentPage(1); }}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
              <circle cx="12" cy="12" r="3" />
              <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33" />
            </svg>
            Installation Materials
            <span className="im-tab-count">{typeCounts.consumable}</span>
          </button>
        </div>

        {/* Product Catalog Table */}
        <div className="im-table-card">
          <div className="im-table-top">
            <div className="im-table-title">
              <h3>Product Catalog</h3>
              <p>Complete list of all products and materials</p>
            </div>
          </div>

          <div className="im-search-row">
            <div className="im-search-input">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="16" height="16">
                <circle cx="11" cy="11" r="8"></circle>
                <path d="m21 21-4.35-4.35"></path>
              </svg>
              <input
                type="text"
                placeholder="Search specifications..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
              />
            </div>
          </div>

          {loading && <Loader label="Loading products" />}
          {error && <div className="im-error">Error: {error}</div>}

          <div className="im-table-wrapper">
            <table className="im-table">
              <thead>
                <tr>
                  <th>PRODUCT</th>
                  <th>TYPE</th>
                  <th>CATEGORY</th>
                  <th>DETAILS</th>
                  <th>WARRANTY</th>
                  <th>COST PRICE</th>
                  <th>UNIT PRICE</th>
                  <th title="Total on-hand quantity across all locations">STOCK</th>
                  <th>STATUS</th>
                  <th>ACTIONS</th>
                </tr>
              </thead>
              <tbody>
                {filteredItems.length === 0 ? (
                  <tr>
                    <td colSpan={10} style={{ textAlign: 'center', color: '#9ca3af', padding: '40px' }}>
                      {loading ? <Loader /> : 'No products found'}
                    </td>
                  </tr>
                ) : (
                  filteredItems.map((item) => {
                    const typeBadge = getTypeBadge(item);
                    const qty       = Number(item.quantity ?? 0);
                    const itemType  = resolveType(item);

                    // V5-aware Details cell
                    const detailsText = itemType === 'appliance'
                      ? [item.brand_name, item.capacity_rating, item.variant].filter(Boolean).join(' ') || '—'
                      : (() => {
                          const unit = item.unit_name;
                          const ppp  = item.pieces_per_package;
                          const pkg  = item.package_unit;
                          if (unit && ppp && pkg) return `${unit} | ${ppp} pcs/${pkg}`;
                          if (unit && ppp)         return `${unit} | ${ppp} pcs`;
                          return unit || '—';
                        })();

                    return (
                      <tr key={item.product_id ?? item.id}>
                        <td>
                          <div className="im-product-cell">
                            {item.model_code && (
                              <span className="im-product-code">{item.model_code}</span>
                            )}
                            <span className="im-product-name">{item.product_name || item.name}</span>
                          </div>
                        </td>
                        <td>
                          <span className={`im-type-badge ${typeBadge.className}`}>
                            {typeBadge.label}
                          </span>
                        </td>
                        <td className="im-cell-category">{item.category?.category_name || '—'}</td>
                        <td className="im-cell-details">{detailsText}</td>
                        <td className="im-cell-warranty">
                          {item.warranty_display && item.warranty_display !== 'No Warranty'
                            ? item.warranty_display
                            : '—'}
                        </td>
                        <td className="im-cell-price">₱{Number(item.cost_price ?? 0).toLocaleString()}</td>
                        <td className="im-cell-price">₱{Number(item.unit_price ?? item.price ?? 0).toLocaleString()}</td>
                        <td className="im-cell-qty">{Number.isFinite(qty) ? qty.toLocaleString() : '0'}</td>
                        <td className="im-cell-status">{item.status?.status_name || '—'}</td>
                        <td>
                          <div className="im-cell-actions">
                            <button className="im-action-btn im-action-view" onClick={() => handleView(item)} title="View">
                              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="16" height="16">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                              </svg>
                            </button>
                            {!isViewOnly && (
                              <>
                                <button className="im-action-btn im-action-edit" onClick={() => handleEdit(item)} title="Edit">
                                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="16" height="16">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                  </svg>
                                </button>
                                <ArchiveButton onArchive={() => handleArchive(item)} />
                              </>
                            )}
                          </div>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {totalPages > 1 && (() => {
            const batch = getPageBatch(currentPage, totalPages);
            return (
              <div className="im-pagination">
                <button
                  className="im-page-btn im-page-btn--jump"
                  disabled={!batch.hasPrevBatch}
                  onClick={() => setCurrentPage(batch.prevBatchPage)}
                  aria-label="Previous batch"
                  title="Previous batch"
                >
                  «
                </button>
                <button
                  className="im-page-btn"
                  disabled={currentPage === 1}
                  onClick={() => setCurrentPage(currentPage - 1)}
                >
                  Previous
                </button>
                {batch.pages.map((page) => (
                  <button
                    key={page}
                    className={`im-page-btn ${currentPage === page ? 'im-page-btn-active' : ''}`}
                    onClick={() => setCurrentPage(page)}
                  >
                    {page}
                  </button>
                ))}
                <button
                  className="im-page-btn"
                  disabled={currentPage === totalPages}
                  onClick={() => setCurrentPage(currentPage + 1)}
                >
                  Next
                </button>
                <button
                  className="im-page-btn im-page-btn--jump"
                  disabled={!batch.hasNextBatch}
                  onClick={() => setCurrentPage(batch.nextBatchPage)}
                  aria-label="Next batch"
                  title="Next batch"
                >
                  »
                </button>
              </div>
            );
          })()}
        </div>
      </div>

      {/* View Modal */}
      {viewingItem && (
        <Modal isOpen={!!viewingItem} onClose={() => setViewingItem(null)} title="Product Details">
          <div className={`im-view-details${itemPulse ? ' ak-focus-pulse' : ''}`}>
            {/* Type badge */}
            <div style={{ marginBottom: '12px' }}>
              <span className={`im-type-badge ${resolveType(viewingItem) === 'appliance' ? 'im-type-unit' : 'im-type-consumable'}`}>
                {resolveType(viewingItem) === 'appliance' ? 'APPLIANCE' : 'CONSUMABLE SUPPLY'}
              </span>
            </div>

            <div className="im-view-row">
              <div className="im-view-field">
                <label>Product name</label>
                <p>{viewingItem.product_name || viewingItem.name}</p>
              </div>
            </div>

            {/* Appliance-specific fields */}
            {resolveType(viewingItem) === 'appliance' && (
              <>
                <div className="im-view-row">
                  <div className="im-view-field">
                    <label>Model Code</label>
                    <p>{viewingItem.model_code || '—'}</p>
                  </div>
                </div>
                <div className="im-view-row">
                  <div className="im-view-field">
                    <label>Capacity Rating</label>
                    <p>{viewingItem.capacity_rating
                        ? <span className="im-capacity-badge">{viewingItem.capacity_rating}</span>
                        : '—'}</p>
                  </div>
                  <div className="im-view-field">
                    <label>Variant</label>
                    <p>{viewingItem.variant || '—'}</p>
                  </div>
                </div>
                <div className="im-view-row">
                  <div className="im-view-field">
                    <label>Warranty</label>
                    <p>{viewingItem.warranty_display && viewingItem.warranty_display !== 'No Warranty'
                        ? viewingItem.warranty_display
                        : '—'}</p>
                  </div>
                </div>
              </>
            )}

            {/* Consumable-specific fields */}
            {resolveType(viewingItem) === 'installation_material' && (
              <>
                <div className="im-view-row">
                  <div className="im-view-field">
                    <label>Unit of Measure</label>
                    <p>{viewingItem.unit_name || '—'}</p>
                  </div>
                  <div className="im-view-field">
                    <label>Pieces per Package</label>
                    <p>{viewingItem.pieces_per_package ?? '—'}{viewingItem.package_unit ? ` / ${viewingItem.package_unit}` : ''}</p>
                  </div>
                </div>
                <div className="im-view-row">
                  <div className="im-view-field">
                    <label>Warranty</label>
                    <p>{viewingItem.warranty_display && viewingItem.warranty_display !== 'No Warranty'
                        ? viewingItem.warranty_display
                        : '—'}</p>
                  </div>
                </div>
              </>
            )}

            <div className="im-view-row">
              <div className="im-view-field">
                <label>Category</label>
                <p>{viewingItem.category?.category_name || '—'}</p>
              </div>
              <div className="im-view-field">
                <label>Brand</label>
                <p>{viewingItem.brand_name || '—'}</p>
              </div>
            </div>
            <div className="im-view-row">
              <div className="im-view-field">
                <label>Unit Price</label>
                <p>₱{Number(viewingItem.unit_price || 0).toLocaleString()}</p>
              </div>
              <div className="im-view-field">
                <label>Cost Price</label>
                <p>₱{Number(viewingItem.cost_price || 0).toLocaleString()}</p>
              </div>
            </div>
            <div className="im-view-row">
              <div className="im-view-field">
                <label>Quantity (All Locations)</label>
                <p>{Number(viewingItem.quantity ?? 0).toLocaleString()}</p>
              </div>
            </div>
            <div className="im-view-row">
              <div className="im-view-field">
                <label>Barcode</label>
                <p>{viewingItem.barcode || '—'}</p>
              </div>
              <div className="im-view-field">
                <label>Status</label>
                <p>{viewingItem.status?.status_name || 'Active'}</p>
              </div>
            </div>
            {viewingItem.barcode && (
              <div className="im-view-row">
                <div className="im-view-field" style={{ gridColumn: '1 / -1' }}>
                  <label>Scannable Barcode</label>
                  <div className="im-barcode-preview">
                    <Barcode
                      value={String(viewingItem.barcode)}
                      format="CODE128"
                      width={1.6}
                      height={56}
                      margin={0}
                      fontSize={12}
                      displayValue
                    />
                  </div>
                </div>
              </div>
            )}

            <div className="im-modal-footer">
              <button type="button" className="im-modal-btn-cancel" onClick={() => setViewingItem(null)}>Close</button>
              {!isViewOnly && (
                <button type="button" className="im-modal-btn-confirm" onClick={() => { setViewingItem(null); handleEdit(viewingItem); }}>Edit</button>
              )}
            </div>
          </div>
        </Modal>
      )}

      {/* Add/Edit Product Modal */}
      <Modal isOpen={isModalOpen} onClose={() => setIsModalOpen(false)} title="">
        <div className="im-add-modal">
          {/* Modal Header */}
          <div className="im-add-modal-header">
            <div className="im-add-modal-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="20" height="20">
                <line x1="12" y1="5" x2="12" y2="19"></line>
                <line x1="5" y1="12" x2="19" y2="12"></line>
              </svg>
            </div>
            <div>
              <h2 className="im-add-modal-title">
                {editingItem ? 'EDIT PRODUCT' : 'ADD NEW PRODUCT'}
              </h2>
              <p className="im-add-modal-subtitle">MASTER CATALOG REGISTRATION</p>
            </div>
          </div>

          {/* Product-class toggle */}
          <div className="im-type-toggle">
            <button
              className={`im-type-toggle-btn ${productType === 'appliance' ? 'active' : ''}`}
              onClick={() => {
                setProductType('appliance');
                setFormData(prev => ({
                  ...prev,
                  unit_id: '', pieces_per_package: '', package_unit: '',
                }));
              }}
              type="button"
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="16" height="16">
                <rect x="4" y="4" width="16" height="16" rx="2"></rect>
                <line x1="4" y1="12" x2="20" y2="12"></line>
              </svg>
              APPLIANCE
            </button>
            <button
              className={`im-type-toggle-btn ${productType === 'installation_material' ? 'active' : ''}`}
              onClick={() => {
                setProductType('installation_material');
                setFormData(prev => ({
                  ...prev,
                  brand_id: '', model_code: '', capacity_rating: '',
                  variant: '', color: '',
                }));
              }}
              type="button"
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="16" height="16">
                <circle cx="12" cy="12" r="3"></circle>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33"></path>
              </svg>
              INSTALLATION MATERIAL
            </button>
          </div>

          {/* Form */}
          <form onSubmit={handleSubmit} className="im-add-form">
            {productType === 'appliance' ? (
              /* ── APPLIANCE FORM ──────────────────────────────────────── */
              <>
                <div className="im-form-row">
                  <div className="im-form-field">
                    <label>MODEL CODE *</label>
                    <input
                      type="text"
                      value={formData.model_code}
                      onChange={(e) => setFormData({ ...formData, model_code: e.target.value })}
                      placeholder="e.g. AMNB12AAJLH5"
                      required
                    />
                  </div>
                  <div className="im-form-field">
                    <label>CAPACITY RATING</label>
                    <select
                      value={formData.capacity_rating}
                      onChange={(e) => setFormData({ ...formData, capacity_rating: e.target.value })}
                    >
                      <option value="">Select Capacity (optional)</option>
                      {filteredCapacities.map((c) => (
                        <option key={c.capacity_id} value={c.capacity_value}>
                          {c.__archived ? `${c.capacity_value} (archived)` : c.capacity_value}
                        </option>
                      ))}
                    </select>
                    {!hasFilteredCapacities && (
                      <p className="im-barcode-hint">No capacities defined yet. Add them on the Item Lookups page.</p>
                    )}
                  </div>
                </div>
                <div className="im-form-row">
                  <div className="im-form-field">
                    <label>CATEGORY</label>
                    <div className="im-inline-field">
                      <select
                        value={formData.category_id}
                        onChange={(e) => setFormData({ ...formData, category_id: e.target.value })}
                      >
                        <option value="">Select Category</option>
                        {/* category_type is NOT NULL at the schema level and the API rule now
                            rejects anything but 'appliance'/'installation_material', so
                            !c.category_type can't fire in practice. Left in as belt-and-braces:
                            a hypothetical null still surfaces under Appliances instead of
                            vanishing from both dropdowns. */}
                        {categories.filter(c => !c.category_type || c.category_type === 'appliance').map((c) => (
                          <option key={c.category_id} value={c.category_id}>{c.category_name}</option>
                        ))}
                      </select>
                      <button
                        type="button"
                        className="im-inline-add-btn"
                        onClick={() => setIsAddCategoryOpen(true)}
                        title="Add new category"
                      >
                        +
                      </button>
                    </div>
                  </div>
                  <div className="im-form-field">
                    <label>BRAND</label>
                    <div className="im-inline-field">
                      <select
                        value={formData.brand_id}
                        onChange={(e) => setFormData({ ...formData, brand_id: e.target.value })}
                      >
                        <option value="">Select Brand</option>
                        {brands.map((b) => (
                          <option key={b.brand_id} value={b.brand_id}>{b.brand_name}</option>
                        ))}
                      </select>
                      <button
                        type="button"
                        className="im-inline-add-btn"
                        onClick={() => setIsAddBrandOpen(true)}
                        title="Add new brand"
                      >
                        +
                      </button>
                    </div>
                  </div>
                </div>
                <div className="im-form-row">
                  <div className="im-form-field">
                    <label>STATUS</label>
                    <select
                      value={formData.status_id}
                      onChange={(e) => setFormData({ ...formData, status_id: e.target.value })}
                    >
                      <option value="">Select Status</option>
                      {statuses.map((s) => (
                        <option key={s.status_id} value={s.status_id}>{s.status_name}</option>
                      ))}
                    </select>
                  </div>
                </div>
                <div className="im-form-row">
                  <div className="im-form-field">
                    <label>VARIANT</label>
                    <select
                      value={formData.variant}
                      onChange={(e) => setFormData({ ...formData, variant: e.target.value })}
                    >
                      <option value="">Select variant (optional)</option>
                      {filteredVariants.map((v) => (
                        <option key={v.variant_id} value={v.variant_name}>
                          {v.__archived ? `${v.variant_name} (archived)` : v.variant_name}
                        </option>
                      ))}
                    </select>
                    {!hasFilteredVariants && (
                      <p className="im-barcode-hint">No variants defined yet. Add them on the Item Lookups page.</p>
                    )}
                  </div>
                  <div className="im-form-field">
                    <label>COLOR</label>
                    <input
                      type="text"
                      value={formData.color}
                      onChange={(e) => setFormData({ ...formData, color: e.target.value })}
                      placeholder="e.g. White"
                    />
                  </div>
                </div>
                <div className="im-form-row">
                  <div className="im-form-field">
                    <label>WARRANTY</label>
                    <div className="im-warranty-row">
                      <input
                        type="number"
                        min="0"
                        max="99"
                        className="im-warranty-value-input"
                        value={formData.warranty_value}
                        onChange={(e) => setFormData({ ...formData, warranty_value: parseInt(e.target.value) || 0 })}
                        placeholder="0"
                      />
                      <select
                        className="im-warranty-unit-select"
                        value={formData.warranty_unit}
                        onChange={(e) => setFormData({ ...formData, warranty_unit: e.target.value })}
                        disabled={!Number(formData.warranty_value)}
                      >
                        <option value="month">Month(s)</option>
                        <option value="year">Year(s)</option>
                      </select>
                    </div>
                    <small className="im-warranty-preview">
                      {!Number(formData.warranty_value)
                        ? 'No warranty'
                        : Number(formData.warranty_value) === 1
                          ? `1 ${formData.warranty_unit === 'year' ? 'Year' : 'Month'}`
                          : `${formData.warranty_value} ${formData.warranty_unit === 'year' ? 'Years' : 'Months'}`
                      }
                    </small>
                  </div>
                </div>
                <div className="im-form-field">
                  <label>PRODUCT NAME *</label>
                  <input
                    type="text"
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    placeholder="e.g. Airking Split Type Aircon 1HP"
                    required
                  />
                </div>
                <div className="im-form-row">
                  <div className="im-form-field">
                    <label>DESCRIPTION</label>
                    <textarea
                      value={formData.description}
                      onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                      placeholder="Optional product notes"
                      rows={2}
                      style={{ width: '100%', padding: '8px 12px', borderRadius: 6, border: '1px solid #e5e7eb', resize: 'vertical' }}
                    />
                  </div>
                  <div className="im-form-field">
                    <label>BARCODE</label>
                    <div className="im-barcode-field">
                      <input
                        type="text"
                        value={formData.barcode}
                        onChange={(e) => setFormData({ ...formData, barcode: e.target.value })}
                        placeholder="Optional"
                      />
                      <button
                        type="button"
                        className="im-generate-btn"
                        onClick={handleGenerateBarcode}
                      >
                        Generate
                      </button>
                    </div>
                    {formData.barcode ? (
                      <div className="im-barcode-preview" aria-label="barcode-preview">
                        <Barcode
                          value={String(formData.barcode)}
                          format={getBarcodeFormat(formData.barcode)}
                          width={1.6}
                          height={52}
                          margin={0}
                          fontSize={11}
                          displayValue
                        />
                      </div>
                    ) : (
                      <p className="im-barcode-hint">Generate or enter a barcode to show a scannable preview.</p>
                    )}
                  </div>
                </div>
                <div className="im-form-row">
                  <div className="im-form-field">
                    <label>UNIT PRICE (₱) *</label>
                    <input
                      type="number"
                      step="0.01"
                      value={formData.unit_price}
                      onChange={(e) => setFormData({ ...formData, unit_price: e.target.value })}
                      placeholder="0.00"
                      required
                    />
                  </div>
                  <div className="im-form-field">
                    <label>COST PRICE (₱)</label>
                    <input
                      type="number"
                      step="0.01"
                      value={formData.cost_price}
                      onChange={(e) => setFormData({ ...formData, cost_price: e.target.value })}
                      placeholder="0.00"
                    />
                  </div>
                </div>
              </>
            ) : (
              /* ── CONSUMABLE SUPPLY FORM ──────────────────────────────── */
              <>
                <div className="im-form-row">
                  <div className="im-form-field">
                    <label>CATEGORY</label>
                    <div className="im-inline-field">
                      <select
                        value={formData.category_id}
                        onChange={(e) => setFormData({ ...formData, category_id: e.target.value })}
                      >
                        <option value="">Select Category</option>
                        {categories.filter(c => c.category_type === 'installation_material').map((c) => (
                          <option key={c.category_id} value={c.category_id}>{c.category_name}</option>
                        ))}
                      </select>
                      <button
                        type="button"
                        className="im-inline-add-btn"
                        onClick={() => setIsAddCategoryOpen(true)}
                        title="Add new category"
                      >
                        +
                      </button>
                    </div>
                  </div>
                  <div className="im-form-field">
                    <label>UNIT OF MEASURE</label>
                    <div className="im-inline-field">
                      <select
                        value={formData.unit_id}
                        onChange={(e) => setFormData({ ...formData, unit_id: e.target.value })}
                      >
                        <option value="">Select Unit</option>
                        {units.map((u) => (
                          <option key={u.unit_id} value={u.unit_id}>{u.unit_name}</option>
                        ))}
                      </select>
                      <button
                        type="button"
                        className="im-inline-add-btn"
                        onClick={() => setIsAddUnitOpen(true)}
                        title="Add new unit of measure"
                      >
                        +
                      </button>
                    </div>
                  </div>
                </div>
                <div className="im-form-row">
                  <div className="im-form-field">
                    <label>STATUS</label>
                    <select
                      value={formData.status_id}
                      onChange={(e) => setFormData({ ...formData, status_id: e.target.value })}
                    >
                      <option value="">Select Status</option>
                      {statuses.map((s) => (
                        <option key={s.status_id} value={s.status_id}>{s.status_name}</option>
                      ))}
                    </select>
                  </div>
                </div>
                <div className="im-form-field">
                  <label>PRODUCT NAME *</label>
                  <input
                    type="text"
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    placeholder="e.g. AC Copper Pipe 1/4 inch"
                    required
                  />
                </div>
                <div className="im-form-field">
                  <label>DESCRIPTION</label>
                  <textarea
                    value={formData.description}
                    onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                    placeholder="e.g. 1/4 inch copper tubing for AC installations"
                    rows={3}
                    style={{ width: '100%', padding: '8px 12px', borderRadius: 6, border: '1px solid #e5e7eb', resize: 'vertical' }}
                  />
                </div>
                <div className="im-form-row">
                  <div className="im-form-field">
                    <label>PIECES PER PACKAGE</label>
                    <input
                      type="number"
                      min="1"
                      value={formData.pieces_per_package}
                      onChange={(e) => setFormData({ ...formData, pieces_per_package: e.target.value })}
                      placeholder="e.g. 50"
                    />
                  </div>
                  <div className="im-form-field">
                    <label>PACKAGE / CONTAINER</label>
                    <input
                      type="text"
                      value={formData.package_unit}
                      onChange={(e) => setFormData({ ...formData, package_unit: e.target.value })}
                      placeholder="e.g. Box, Drum, Carton"
                    />
                  </div>
                </div>
                <div className="im-form-row">
                  <div className="im-form-field">
                    <label>WARRANTY</label>
                    <div className="im-warranty-row">
                      <input
                        type="number"
                        min="0"
                        max="99"
                        className="im-warranty-value-input"
                        value={formData.warranty_value}
                        onChange={(e) => setFormData({ ...formData, warranty_value: parseInt(e.target.value) || 0 })}
                        placeholder="0"
                      />
                      <select
                        className="im-warranty-unit-select"
                        value={formData.warranty_unit}
                        onChange={(e) => setFormData({ ...formData, warranty_unit: e.target.value })}
                        disabled={!Number(formData.warranty_value)}
                      >
                        <option value="month">Month(s)</option>
                        <option value="year">Year(s)</option>
                      </select>
                    </div>
                    <small className="im-warranty-preview">
                      {!Number(formData.warranty_value)
                        ? 'No warranty'
                        : Number(formData.warranty_value) === 1
                          ? `1 ${formData.warranty_unit === 'year' ? 'Year' : 'Month'}`
                          : `${formData.warranty_value} ${formData.warranty_unit === 'year' ? 'Years' : 'Months'}`
                      }
                    </small>
                  </div>
                </div>
                <div className="im-form-row">
                  <div className="im-form-field">
                    <label>BARCODE</label>
                    <div className="im-barcode-field">
                      <input
                        type="text"
                        value={formData.barcode}
                        onChange={(e) => setFormData({ ...formData, barcode: e.target.value })}
                        placeholder="Optional"
                      />
                      <button
                        type="button"
                        className="im-generate-btn"
                        onClick={handleGenerateBarcode}
                      >
                        Generate
                      </button>
                    </div>
                    {formData.barcode ? (
                      <div className="im-barcode-preview" aria-label="barcode-preview">
                        <Barcode
                          value={String(formData.barcode)}
                          format={getBarcodeFormat(formData.barcode)}
                          width={1.6}
                          height={52}
                          margin={0}
                          fontSize={11}
                          displayValue
                        />
                      </div>
                    ) : (
                      <p className="im-barcode-hint">Generate or enter a barcode to show a scannable preview.</p>
                    )}
                  </div>
                </div>
                <div className="im-form-row">
                  <div className="im-form-field">
                    <label>UNIT PRICE (₱) *</label>
                    <input
                      type="number"
                      step="0.01"
                      value={formData.unit_price}
                      onChange={(e) => setFormData({ ...formData, unit_price: e.target.value })}
                      placeholder="0.00"
                      required
                    />
                  </div>
                  <div className="im-form-field">
                    <label>COST PRICE (₱)</label>
                    <input
                      type="number"
                      step="0.01"
                      value={formData.cost_price}
                      onChange={(e) => setFormData({ ...formData, cost_price: e.target.value })}
                      placeholder="0.00"
                    />
                  </div>
                </div>
              </>
            )}

            {/* Stock Levels (both types) */}
            <div className="im-form-row" style={{ marginTop: 8, paddingTop: 12, borderTop: '1px dashed #e5e7eb' }}>
              <div className="im-form-field">
                <label>
                  RECOMMENDED STOCKS
                  {stockParamUnitHint && (
                    <span style={{ textTransform: 'none', fontWeight: 400, color: '#94a3b8' }}> — {stockParamUnitHint}</span>
                  )}
                </label>
                <input
                  type="number"
                  min="0"
                  value={formData.recommended_stocks}
                  onChange={(e) => setFormData({ ...formData, recommended_stocks: e.target.value })}
                  placeholder="e.g. 100"
                />
              </div>
              <div className="im-form-field">
                <label>
                  SAFETY STOCK
                  {stockParamUnitHint && (
                    <span style={{ textTransform: 'none', fontWeight: 400, color: '#94a3b8' }}> — {stockParamUnitHint}</span>
                  )}
                </label>
                <input
                  type="number"
                  min="0"
                  value={formData.safety_stock}
                  onChange={(e) => setFormData({ ...formData, safety_stock: e.target.value })}
                  placeholder="e.g. 20"
                />
              </div>
              <div className="im-form-field">
                <label>
                  MAX STOCK LEVEL
                  {stockParamUnitHint && (
                    <span style={{ textTransform: 'none', fontWeight: 400, color: '#94a3b8' }}> — {stockParamUnitHint}</span>
                  )}
                </label>
                <input
                  type="number"
                  min="0"
                  value={formData.max_stock_level}
                  onChange={(e) => setFormData({ ...formData, max_stock_level: e.target.value })}
                  placeholder="e.g. 500"
                />
              </div>
            </div>

            {/* Modal Actions */}
            <div className="im-modal-footer">
              <button type="button" className="im-modal-btn-cancel" onClick={() => setIsModalOpen(false)}>
                CANCEL
              </button>
              <button type="submit" className="im-modal-btn-confirm">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                  <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                </svg>
                {editingItem ? 'UPDATE PRODUCT' : 'REGISTER PRODUCT'}
              </button>
            </div>
          </form>
        </div>
      </Modal>

      {/* Quick Add Category */}
      {isAddCategoryOpen && (
        <Modal
          isOpen={isAddCategoryOpen}
          onClose={() => { setIsAddCategoryOpen(false); setNewCategoryName(''); }}
          title="Add Category"
        >
          <form onSubmit={handleCreateCategory} className="im-add-simple-form">
            <div className="im-form-field">
              <label>Category Name</label>
              <input
                type="text"
                value={newCategoryName}
                onChange={(e) => setNewCategoryName(e.target.value)}
                placeholder="e.g. Installation Materials"
                autoFocus
              />
            </div>
            <div className="im-modal-footer">
              <button
                type="button"
                className="im-modal-btn-cancel"
                onClick={() => { setIsAddCategoryOpen(false); setNewCategoryName(''); }}
              >
                CANCEL
              </button>
              <button type="submit" className="im-modal-btn-confirm">
                SAVE CATEGORY
              </button>
            </div>
          </form>
        </Modal>
      )}

      {/* Quick Add Brand */}
      {isAddBrandOpen && (
        <Modal
          isOpen={isAddBrandOpen}
          onClose={() => { setIsAddBrandOpen(false); setNewBrandName(''); }}
          title="Add Brand"
        >
          <form onSubmit={handleCreateBrand} className="im-add-simple-form">
            <div className="im-form-field">
              <label>Brand Name</label>
              <input
                type="text"
                value={newBrandName}
                onChange={(e) => setNewBrandName(e.target.value)}
                placeholder="e.g. AirKing"
                autoFocus
              />
            </div>
            <div className="im-modal-footer">
              <button
                type="button"
                className="im-modal-btn-cancel"
                onClick={() => { setIsAddBrandOpen(false); setNewBrandName(''); }}
              >
                CANCEL
              </button>
              <button type="submit" className="im-modal-btn-confirm">
                SAVE BRAND
              </button>
            </div>
          </form>
        </Modal>
      )}

      {/* Quick Add Unit of Measure */}
      {isAddUnitOpen && (
        <Modal
          isOpen={isAddUnitOpen}
          onClose={() => { setIsAddUnitOpen(false); setNewUnitName(''); setNewUnitAbbrev(''); }}
          title="Add Unit of Measure"
        >
          <form onSubmit={handleCreateUnit} className="im-add-simple-form">
            <div className="im-form-field">
              <label>Unit Name</label>
              <input
                type="text"
                value={newUnitName}
                onChange={(e) => setNewUnitName(e.target.value)}
                placeholder="e.g. Meter, Liter, Roll"
                autoFocus
              />
            </div>
            <div className="im-form-field">
              <label>Abbreviation (optional)</label>
              <input
                type="text"
                value={newUnitAbbrev}
                onChange={(e) => setNewUnitAbbrev(e.target.value)}
                placeholder="e.g. m, L, roll"
              />
            </div>
            <div className="im-modal-footer">
              <button
                type="button"
                className="im-modal-btn-cancel"
                onClick={() => { setIsAddUnitOpen(false); setNewUnitName(''); setNewUnitAbbrev(''); }}
              >
                CANCEL
              </button>
              <button type="submit" className="im-modal-btn-confirm">
                SAVE UNIT
              </button>
            </div>
          </form>
        </Modal>
      )}
    </AdminLayout>
  );
};

export default ItemManagement;
