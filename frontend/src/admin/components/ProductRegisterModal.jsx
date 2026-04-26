import React, { useState, useEffect, useCallback, useMemo } from 'react';
import Modal from './Modal';
import {
  consumableSupplyAPI,
  pendingProductsAPI,
  categoriesAPI,
  brandsAPI,
  unitsAPI,
  locationsAPI,
  batchAPI,
} from '../services/api';
import { toast } from '../utils/toast';
import { useAuth } from '../context/AuthContext';
import { getRoleKey, ROLES } from '../utils/roles';
import '../styles/item_management.css';

/** Matches backend ProductController / Item Management appliance capacities. */
const APPLIANCE_CAPACITY_RATINGS = ['1HP', '1.5HP', '2HP', '2.5HP', '3HP', '5HP'];

/** Used when GET /consumable-supply/catalog fails or returns empty (e.g. backend route missing). */
const DEFAULT_SUPPLY_TYPES = [
  'Refrigerant',
  'Copper / tubing',
  'Electrical',
  'Insulation',
  'Fasteners & hardware',
  'Installation supplies',
  'Lubricants & chemicals',
  'Other',
];

const DEFAULT_PACKAGING_UNITS = [
  { key: 'piece', label: 'Piece / Each' },
  { key: 'box', label: 'Box' },
  { key: 'roll', label: 'Roll' },
  { key: 'kg', label: 'Kilogram' },
  { key: 'meter', label: 'Meter' },
  { key: 'set', label: 'Set' },
  { key: 'bottle', label: 'Bottle' },
  { key: 'carton', label: 'Carton' },
  { key: 'bundle', label: 'Bundle' },
  { key: 'pair', label: 'Pair' },
];

const emptyConsumable = (barcode) => ({
  barcode: barcode || '',
  category_id: '',
  brand_id: '',
  supply_type: '',
  packaging_unit: '',
  quantity_per_package: '',
  opening_location_id: '',
  unit_price: '',
  cost_price: '',
});

const emptyAppliance = (barcode) => ({
  barcode: barcode || '',
  product_name: '',
  product_code: '',
  capacity_rating: '',
  variant: '',
  category_id: '',
  brand_id: '',
  unit_id: '',
  warranty_period_months: '',
  initial_location_id: '',
  unit_price: '',
  cost_price: '',
});

/**
 * Submit consumable or appliance registration for approval (pending_products).
 * Used from Inventory Operation when a barcode is not in the catalog.
 */
export default function ProductRegisterModal({
  isOpen,
  onClose,
  editingItem = null,
  initialBarcode = '',
  lockBarcode = false,
  showPricingFields = false,
  onSuccess,
}) {
  const { user: authUser } = useAuth();
  const user = authUser || {};
  const roleKey = getRoleKey(user);
  const isWarehousePersonnel = roleKey === ROLES.WAREHOUSE_PERSONNEL;
  const showApplianceModelCode = roleKey === ROLES.ADMIN || roleKey === ROLES.BRANCH_MANAGER;
  const showApplianceWarranty = !isWarehousePersonnel;

  const [kind, setKind] = useState('consumable');
  const [catalog, setCatalog] = useState(null);
  const [serverSupplyTypes, setServerSupplyTypes] = useState(null);
  const [supplyTypesLoading, setSupplyTypesLoading] = useState(false);
  const [categories, setCategories] = useState([]);
  const [brands, setBrands] = useState([]);
  const [units, setUnits] = useState([]);
  const [locations, setLocations] = useState([]);
  const [submitting, setSubmitting] = useState(false);
  const [con, setCon] = useState(() => emptyConsumable(initialBarcode));
  const [app, setApp] = useState(() => emptyAppliance(initialBarcode));

  const loadLookups = useCallback(() => {
    locationsAPI.getAll({ per_page: 500 }).then((r) => {
      setLocations(Array.isArray(r?.data) ? r.data : []);
    }).catch(() => setLocations([]));

    batchAPI.get({ include: ['categories', 'brands', 'units'] }).then((res) => {
      const d = res?.data || {};
      setCategories(Array.isArray(d.categories?.data) ? d.categories.data : []);
      setBrands(Array.isArray(d.brands?.data) ? d.brands.data : []);
      setUnits(Array.isArray(d.units?.data) ? d.units.data : []);
    }).catch(async () => {
      try {
        const [c, b, u] = await Promise.all([
          categoriesAPI.getAll(),
          brandsAPI.getAll(),
          unitsAPI.getAll(),
        ]);
        setCategories(Array.isArray(c?.data) ? c.data : []);
        setBrands(Array.isArray(b?.data) ? b.data : []);
        setUnits(Array.isArray(u?.data) ? u.data : []);
      } catch {
        /* ignore */
      }
    });
  }, []);

  useEffect(() => {
    if (!isOpen) return;
    const base = emptyConsumable(initialBarcode);
    setCon({
      ...base,
      supply_type: '',
      packaging_unit: DEFAULT_PACKAGING_UNITS[0]?.key ?? '',
    });
    setApp(emptyAppliance(initialBarcode));
    setKind('consumable');
    setServerSupplyTypes(null);
    loadLookups();
    setSupplyTypesLoading(true);
    Promise.all([
      consumableSupplyAPI.getSupplyTypes().catch(() => null),
      consumableSupplyAPI.getCatalog().catch(() => null),
    ])
      .then(([stRes, catRes]) => {
        const list = Array.isArray(stRes?.data) ? stRes.data : [];
        setServerSupplyTypes(list.length ? list : null);
        setCatalog(catRes?.data || null);
        if (!list.length && !catRes?.data?.supply_types?.length) {
          toast.error('Could not load supply types. Using built-in list.');
        }
      })
      .finally(() => setSupplyTypesLoading(false));
  }, [isOpen, initialBarcode, loadLookups]);

  /** Prefer GET /supply-types, then catalog, then built-in (must match backend validation). */
  const supplyTypes = useMemo(() => {
    if (serverSupplyTypes && serverSupplyTypes.length > 0) return serverSupplyTypes;
    const st = catalog?.supply_types;
    return Array.isArray(st) && st.length > 0 ? st : DEFAULT_SUPPLY_TYPES;
  }, [serverSupplyTypes, catalog]);

  const packagingUnits = useMemo(() => {
    const pu = catalog?.packaging_units;
    return Array.isArray(pu) && pu.length > 0 ? pu : DEFAULT_PACKAGING_UNITS;
  }, [catalog]);

  /** When supply types or packaging list is ready, keep values if valid; else snap to first option. */
  useEffect(() => {
    if (!isOpen || supplyTypesLoading) return;
    if (!supplyTypes.length) return;
    setCon((c) => {
      const stOk = c.supply_type && supplyTypes.includes(c.supply_type);
      const puOk = packagingUnits.some((u) => {
        const k = typeof u === 'object' && u ? u.key ?? u.value : u;
        return String(k) === String(c.packaging_unit);
      });
      return {
        ...c,
        supply_type: stOk ? c.supply_type : supplyTypes[0],
        packaging_unit: puOk ? c.packaging_unit : String(packagingUnits[0]?.key ?? packagingUnits[0] ?? ''),
      };
    });
  }, [isOpen, supplyTypesLoading, supplyTypes, packagingUnits]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (editingItem) {
      toast.info('Edit existing products in Item Management.');
      return;
    }
    setSubmitting(true);
    try {
      if (kind === 'consumable') {
        if (!con.barcode.trim()) {
          toast.error('Barcode is required.');
          return;
        }
        if (!con.opening_location_id) {
          toast.error('Select a stock location (1 unit will be recorded there).');
          return;
        }
        const supplyType = String(con.supply_type || '').trim();
        if (!supplyType || !supplyTypes.includes(supplyType)) {
          toast.error('Select a supply type from the list.');
          return;
        }
        if (!con.packaging_unit) {
          toast.error('Select a packaging unit.');
          return;
        }
        const body = {
          barcode: con.barcode.trim(),
          category_id: con.category_id ? Number(con.category_id) : null,
          brand_id: con.brand_id ? Number(con.brand_id) : null,
          supply_type: supplyType,
          packaging_unit: con.packaging_unit,
          quantity_per_package: con.quantity_per_package !== '' ? Number(con.quantity_per_package) : null,
          opening_location_id: Number(con.opening_location_id),
        };
        if (showPricingFields && con.unit_price !== '') body.unit_price = Number(con.unit_price);
        if (showPricingFields && con.cost_price !== '') body.cost_price = Number(con.cost_price);
        await pendingProductsAPI.store(body);
      } else {
        if (!app.barcode.trim()) {
          toast.error('Barcode is required.');
          return;
        }
        if (!app.product_name.trim() || !app.category_id || !app.brand_id || !app.unit_id || !app.initial_location_id) {
          toast.error('Product name, category, brand, unit, and location are required.');
          return;
        }
        const body = {
          registration_kind: 'appliance',
          barcode: app.barcode.trim(),
          appliance: {
            product_name: app.product_name.trim(),
            product_code: showApplianceModelCode && app.product_code.trim() ? app.product_code.trim() : null,
            capacity_rating: app.capacity_rating.trim() || null,
            variant: app.variant.trim() || null,
            category_id: Number(app.category_id),
            brand_id: Number(app.brand_id),
            unit_id: Number(app.unit_id),
            warranty_period_months:
              showApplianceWarranty && app.warranty_period_months !== ''
                ? Number(app.warranty_period_months)
                : null,
            initial_location_id: Number(app.initial_location_id),
          },
        };
        if (showPricingFields && app.unit_price !== '') body.unit_price = Number(app.unit_price);
        if (showPricingFields && app.cost_price !== '') body.cost_price = Number(app.cost_price);
        await pendingProductsAPI.store(body);
      }
      onSuccess?.();
    } catch (err) {
      toast.error(err.message || 'Registration failed.');
    } finally {
      setSubmitting(false);
    }
  };

  if (!isOpen) return null;

  if (editingItem) {
    return (
      <Modal isOpen onClose={onClose} title="Register product">
        <p style={{ color: '#374151', marginBottom: 16 }}>Use Item Management to edit catalog products.</p>
        <div className="im-modal-footer">
          <button type="button" className="im-modal-btn-cancel" onClick={onClose}>Close</button>
        </div>
      </Modal>
    );
  }

  return (
    <Modal isOpen onClose={onClose} title="Register product (pending approval)" maxWidth={560}>
      <form onSubmit={handleSubmit} className="im-add-form">
        <div style={{ display: 'flex', gap: 8, marginBottom: 14 }}>
          <button
            type="button"
            onClick={() => setKind('consumable')}
            style={{
              flex: 1,
              padding: '8px 12px',
              borderRadius: 8,
              border: kind === 'consumable' ? '2px solid #dc2626' : '1px solid #e5e7eb',
              background: kind === 'consumable' ? '#fef2f2' : '#fff',
              fontWeight: 600,
              fontSize: 13,
              cursor: 'pointer',
            }}
          >
            Consumable
          </button>
          <button
            type="button"
            onClick={() => setKind('appliance')}
            style={{
              flex: 1,
              padding: '8px 12px',
              borderRadius: 8,
              border: kind === 'appliance' ? '2px solid #dc2626' : '1px solid #e5e7eb',
              background: kind === 'appliance' ? '#fef2f2' : '#fff',
              fontWeight: 600,
              fontSize: 13,
              cursor: 'pointer',
            }}
          >
            Appliance
          </button>
        </div>

        {kind === 'consumable' ? (
          <>
            <div className="im-form-field">
              <label>BARCODE *</label>
              <input
                type="text"
                value={con.barcode}
                readOnly={lockBarcode}
                onChange={(e) => setCon((c) => ({ ...c, barcode: e.target.value }))}
                required
              />
            </div>
            <div className="im-form-row">
              <div className="im-form-field">
                <label>SUPPLY TYPE *</label>
                <select
                  value={con.supply_type && supplyTypes.includes(con.supply_type) ? con.supply_type : ''}
                  onChange={(e) => setCon((c) => ({ ...c, supply_type: e.target.value }))}
                  required
                  disabled={supplyTypesLoading || supplyTypes.length === 0}
                >
                  <option value="">{supplyTypesLoading ? 'Loading…' : 'Select supply type'}</option>
                  {supplyTypes.map((s) => (
                    <option key={s} value={s}>{s}</option>
                  ))}
                </select>
              </div>
              <div className="im-form-field">
                <label>PACKAGING UNIT *</label>
                <select
                  value={con.packaging_unit}
                  onChange={(e) => setCon((c) => ({ ...c, packaging_unit: e.target.value }))}
                  required
                >
                  <option value="">Select</option>
                  {packagingUnits.map((u) => {
                    const key = u.key ?? u;
                    const label = u.label ?? u.key ?? String(u);
                    return <option key={key} value={key}>{label}</option>;
                  })}
                </select>
              </div>
            </div>
            {!isWarehousePersonnel && (
              <div className="im-form-row">
                <div className="im-form-field">
                  <label>CATEGORY</label>
                  <select
                    value={con.category_id}
                    onChange={(e) => setCon((c) => ({ ...c, category_id: e.target.value }))}
                  >
                    <option value="">—</option>
                    {categories.map((c) => (
                      <option key={c.category_id} value={c.category_id}>{c.category_name}</option>
                    ))}
                  </select>
                </div>
                <div className="im-form-field">
                  <label>BRAND</label>
                  <select
                    value={con.brand_id}
                    onChange={(e) => setCon((c) => ({ ...c, brand_id: e.target.value }))}
                  >
                    <option value="">—</option>
                    {brands.map((b) => (
                      <option key={b.brand_id} value={b.brand_id}>{b.brand_name}</option>
                    ))}
                  </select>
                </div>
              </div>
            )}
            <div className="im-form-field">
              <label>QTY PER PACKAGE</label>
              <input
                type="number"
                min="0"
                step="0.01"
                value={con.quantity_per_package}
                onChange={(e) => setCon((c) => ({ ...c, quantity_per_package: e.target.value }))}
                placeholder="Optional"
              />
            </div>
            <div className="im-form-field">
              <label>STOCK LOCATION * (1 unit on approval)</label>
              <select
                value={con.opening_location_id}
                onChange={(e) => setCon((c) => ({ ...c, opening_location_id: e.target.value }))}
                required
              >
                <option value="">Select location</option>
                {locations.map((l) => (
                  <option key={l.id ?? l.location_id} value={l.id ?? l.location_id}>
                    {l.location_name || l.name}
                  </option>
                ))}
              </select>
            </div>
            {showPricingFields && (
              <div className="im-form-row">
                <div className="im-form-field">
                  <label>UNIT PRICE (₱)</label>
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    value={con.unit_price}
                    onChange={(e) => setCon((c) => ({ ...c, unit_price: e.target.value }))}
                  />
                </div>
                <div className="im-form-field">
                  <label>COST PRICE (₱)</label>
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    value={con.cost_price}
                    onChange={(e) => setCon((c) => ({ ...c, cost_price: e.target.value }))}
                  />
                </div>
              </div>
            )}
          </>
        ) : (
          <>
            <div className="im-form-field">
              <label>BARCODE *</label>
              <input
                type="text"
                value={app.barcode}
                readOnly={lockBarcode}
                onChange={(e) => setApp((a) => ({ ...a, barcode: e.target.value }))}
                required
              />
            </div>
            <div className="im-form-field">
              <label>PRODUCT NAME *</label>
              <input
                type="text"
                value={app.product_name}
                onChange={(e) => setApp((a) => ({ ...a, product_name: e.target.value }))}
                required
              />
            </div>
            {showApplianceModelCode ? (
              <div className="im-form-row">
                <div className="im-form-field">
                  <label>MODEL / PRODUCT CODE</label>
                  <input
                    type="text"
                    value={app.product_code}
                    onChange={(e) => setApp((a) => ({ ...a, product_code: e.target.value }))}
                    placeholder="e.g. AS-20-PRO"
                  />
                </div>
                <div className="im-form-field">
                  <label>CAPACITY</label>
                  <input
                    type="text"
                    value={app.capacity_rating}
                    onChange={(e) => setApp((a) => ({ ...a, capacity_rating: e.target.value }))}
                    placeholder="e.g. 1HP"
                  />
                </div>
              </div>
            ) : (
              <div className="im-form-field">
                <label>CAPACITY</label>
                {!showApplianceModelCode ? (
                  <select
                    value={app.capacity_rating}
                    onChange={(e) => setApp((a) => ({ ...a, capacity_rating: e.target.value }))}
                  >
                    <option value="">Select capacity (optional)</option>
                    {APPLIANCE_CAPACITY_RATINGS.map((r) => (
                      <option key={r} value={r}>{r}</option>
                    ))}
                  </select>
                ) : (
                  <input
                    type="text"
                    value={app.capacity_rating}
                    onChange={(e) => setApp((a) => ({ ...a, capacity_rating: e.target.value }))}
                    placeholder="e.g. 1HP"
                  />
                )}
              </div>
            )}
            <div className="im-form-field">
              <label>VARIANT</label>
              <input
                type="text"
                value={app.variant}
                onChange={(e) => setApp((a) => ({ ...a, variant: e.target.value }))}
              />
            </div>
            <div className="im-form-row">
              <div className="im-form-field">
                <label>CATEGORY *</label>
                <select
                  value={app.category_id}
                  onChange={(e) => setApp((a) => ({ ...a, category_id: e.target.value }))}
                  required
                >
                  <option value="">Select</option>
                  {categories.map((c) => (
                    <option key={c.category_id} value={c.category_id}>{c.category_name}</option>
                  ))}
                </select>
              </div>
              <div className="im-form-field">
                <label>BRAND *</label>
                <select
                  value={app.brand_id}
                  onChange={(e) => setApp((a) => ({ ...a, brand_id: e.target.value }))}
                  required
                >
                  <option value="">Select</option>
                  {brands.map((b) => (
                    <option key={b.brand_id} value={b.brand_id}>{b.brand_name}</option>
                  ))}
                </select>
              </div>
            </div>
            <div className={showApplianceWarranty ? 'im-form-row' : 'im-form-field'}>
              <div className="im-form-field">
                <label>UNIT *</label>
                <select
                  value={app.unit_id}
                  onChange={(e) => setApp((a) => ({ ...a, unit_id: e.target.value }))}
                  required
                >
                  <option value="">Select</option>
                  {units.map((u) => (
                    <option key={u.unit_id} value={u.unit_id}>{u.unit_name}</option>
                  ))}
                </select>
              </div>
              {showApplianceWarranty && (
                <div className="im-form-field">
                  <label>WARRANTY (MONTHS)</label>
                  <input
                    type="number"
                    min="0"
                    value={app.warranty_period_months}
                    onChange={(e) => setApp((a) => ({ ...a, warranty_period_months: e.target.value }))}
                  />
                </div>
              )}
            </div>
            <div className="im-form-field">
              <label>STOCK LOCATION * (1 unit on approval)</label>
              <select
                value={app.initial_location_id}
                onChange={(e) => setApp((a) => ({ ...a, initial_location_id: e.target.value }))}
                required
              >
                <option value="">Select location</option>
                {locations.map((l) => (
                  <option key={l.id ?? l.location_id} value={l.id ?? l.location_id}>
                    {l.location_name || l.name}
                  </option>
                ))}
              </select>
            </div>
            {showPricingFields && (
              <div className="im-form-row">
                <div className="im-form-field">
                  <label>UNIT PRICE (₱)</label>
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    value={app.unit_price}
                    onChange={(e) => setApp((a) => ({ ...a, unit_price: e.target.value }))}
                  />
                </div>
                <div className="im-form-field">
                  <label>COST PRICE (₱)</label>
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    value={app.cost_price}
                    onChange={(e) => setApp((a) => ({ ...a, cost_price: e.target.value }))}
                  />
                </div>
              </div>
            )}
          </>
        )}

        <p style={{ fontSize: 12, color: '#6b7280', marginTop: 8 }}>
          Submissions go to the approval queue. An admin or branch manager must approve before the item appears in Item Master.
        </p>

        <div className="im-modal-footer">
          <button type="button" className="im-modal-btn-cancel" onClick={onClose} disabled={submitting}>
            Cancel
          </button>
          <button
            type="submit"
            className="im-modal-btn-confirm"
            disabled={
              submitting ||
              (kind === 'consumable' && (supplyTypesLoading || supplyTypes.length === 0))
            }
          >
            {submitting ? 'Submitting…' : 'Submit for approval'}
          </button>
        </div>
      </form>
    </Modal>
  );
}
