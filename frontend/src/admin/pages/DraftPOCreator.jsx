import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import Modal from '../components/Modal';
import {
  itemsAPI,
  inventoryAPI,
  batchAPI,
  suppliersAPI,
  locationsAPI,
  purchaseOrdersAPI,
  statusAPI,
  unitsAPI, // ← ADD this to your services/api.js (see note at bottom of file)
} from '../services/api';
import {
  addApprovalQueueRestockRequest,
  addApprovalQueuePurchaseOrder,
  addSystemNotification,
} from '../utils/approvalNotifications';
import DateInput from '../components/DateInput';
import '../styles/dashboard_air.css';
import '../styles/po_recommendation.css';
import { exportPOToExcel, exportPOToDocument } from '../utils/poExport';

// ---------------------------------------------------------------------------
// NOTE — Add this to services/api.js if it doesn't exist yet:
//
//   export const unitsAPI = {
//     getAll: (params) => api.get('/units', { params }),
//   };
//
// Your backend should expose GET /api/units returning:
//   { data: [{ unit_id, unit_name, unit_abbreviation }, ...] }
// This maps directly to the unit_lookup table in your DB.
// ---------------------------------------------------------------------------

const isoToMDY = (s) => {
  if (!s) return '—';
  const [y, m, d] = String(s).slice(0, 10).split('-');
  return `${m}/${d}/${y}`;
};

const DraftPOCreator = () => {
  const navigate = useNavigate();
  const location = useLocation();

  const [poDate, setPoDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [expectedDeliveryDate, setExpectedDeliveryDate] = useState(() => {
    const d = new Date();
    d.setDate(d.getDate() + 3);
    return d.toISOString().slice(0, 10);
  });
  const [selectedSupplierId, setSelectedSupplierId] = useState('');
  const [paymentTerms, setPaymentTerms]             = useState('Net 30');
  const [suppliers, setSuppliers]                   = useState([]);
  const [loadingSuppliers, setLoadingSuppliers]     = useState(true);
  const [locations, setLocations]                   = useState([]);
  const [loadingLocations, setLoadingLocations]     = useState(true);
  const [selectedBranch, setSelectedBranch]         = useState('');
  const [categories, setCategories]                 = useState([]);
  const [brands, setBrands]                         = useState([]);
  const [units, setUnits]                           = useState([]);   // ← NEW: from unit_lookup
  const [loadingBatch, setLoadingBatch]             = useState(true);
  const [itemMasterOptions, setItemMasterOptions]   = useState([]);
  const [loadingItemMaster, setLoadingItemMaster]   = useState(true);
  const [lineItems, setLineItems]                   = useState([]);
  const [submitting, setSubmitting]                 = useState(false);
  const [submitError, setSubmitError]               = useState('');
  const [submitSuccess, setSubmitSuccess]           = useState(false);

  const [activeTab, setActiveTab]                   = useState('create');
  const [poStatusList, setPoStatusList]             = useState([]);
  const [loadingPOStatus, setLoadingPOStatus]       = useState(false);
  const [poStatusError, setPoStatusError]           = useState('');
  const [selectedPO, setSelectedPO]                 = useState(null);

  const [showManualItemModal, setShowManualItemModal] = useState(false);
  const [manualItemType, setManualItemType]           = useState('unit');
  const [manualItemForm, setManualItemForm]           = useState({
    category: '',
    brand: '',
    modelItemId: '',
    model: '',
    variant: '',
    unitId: '',        // ← NEW: stores selected unit_id (FK to unit_lookup)
    unitName: '',      // ← NEW: stores display name for the selected unit
    currentStock: 0,
    orderQty: 1,
    unitCost: 0,
  });
  const [addItemError, setAddItemError] = useState('');
  const [addingItem, setAddingItem]     = useState(false);

  const referenceNo = useRef((() => {
    const d   = new Date();
    const ymd = `${d.getFullYear()}${String(d.getMonth() + 1).padStart(2, '0')}${String(d.getDate()).padStart(2, '0')}`;
    const seq = String(Math.floor(Math.random() * 999) + 1).padStart(3, '0');
    return `PO-${ymd}-${seq}`;
  })()).current;

  const selectedSupplierName = useMemo(() => {
    const found = suppliers.find(
      (s) => String(s.supplier_id ?? s.id) === String(selectedSupplierId)
    );
    return found?.supplier_name ?? found?.name ?? '—';
  }, [suppliers, selectedSupplierId]);

  // ── Load locations ──────────────────────────────────────────────────────
  useEffect(() => {
    let mounted = true;
    const load = async () => {
      try {
        const locRes  = await locationsAPI.getAll({ per_page: 500, page: 1 });
        const locList = Array.isArray(locRes?.data) ? [...locRes.data] : [];
        const locTotal = locRes?.pagination?.last_page ?? 1;
        for (let p = 2; p <= locTotal; p++) {
          const more = await locationsAPI.getAll({ per_page: 500, page: p });
          locList.push(...(Array.isArray(more?.data) ? more.data : []));
        }
        if (mounted) {
          setLocations(locList);
          if (locList.length) {
            setSelectedBranch(String(locList[0].location_id ?? locList[0].id ?? ''));
          }
        }
      } catch (err) {
        console.error('Locations fetch failed:', err);
      } finally {
        if (mounted) setLoadingLocations(false);
      }
    };
    load();
    return () => { mounted = false; };
  }, []);

  // ── Load batch data, suppliers, items, inventory, AND units ─────────────
  useEffect(() => {
    let mounted = true;
    Promise.allSettled([
      batchAPI.get({ include: ['categories', 'brands'] }),
      suppliersAPI.getAll({ per_page: 200 }),
      itemsAPI.getAll({ per_page: 1000 }),
      inventoryAPI.getAll({ per_page: 1000 }),
      unitsAPI.getAll({ per_page: 200 }),   // ← NEW: fetch unit_lookup rows
    ]).then(([batchResult, suppResult, itemsResult, inventoryResult, unitsResult]) => {
      if (!mounted) return;

      if (batchResult.status === 'fulfilled') {
        const d = batchResult.value?.data || {};
        setCategories(Array.isArray(d.categories?.data) ? d.categories.data : []);
        setBrands(Array.isArray(d.brands?.data) ? d.brands.data : []);
      }

      // ── NEW: populate units from unit_lookup ──────────────────────────
      if (unitsResult.status === 'fulfilled') {
        const unitRows = Array.isArray(unitsResult.value?.data)
          ? unitsResult.value.data
          : [];
        setUnits(unitRows);
      } else {
        // Graceful fallback if /units endpoint isn't wired yet
        console.warn('unitsAPI.getAll failed — measurement dropdown will be empty:', unitsResult.reason);
      }

      if (suppResult.status === 'fulfilled') {
        const list = Array.isArray(suppResult.value?.data) ? suppResult.value.data : [];
        setSuppliers(list);
        if (list.length) setSelectedSupplierId(String(list[0].supplier_id ?? list[0].id ?? ''));
      }

      const inventoryRows =
        inventoryResult?.status === 'fulfilled' && Array.isArray(inventoryResult.value?.data)
          ? inventoryResult.value.data
          : [];

      const stockByProductId = new Map();
      inventoryRows.forEach((inv) => {
        const pid = String(inv.product_id ?? inv.product?.product_id ?? '');
        if (!pid) return;
        const q = Number(inv.quantity_on_hand ?? inv.quantity ?? 0);
        stockByProductId.set(pid, (stockByProductId.get(pid) || 0) + q);
      });

      if (itemsResult?.status === 'fulfilled') {
        const rows = Array.isArray(itemsResult.value?.data) ? itemsResult.value.data : [];
        const normalized = rows.map((item) => {
          const pid = String(item.product_id ?? item.id ?? '');
          return {
            id:          pid,
            name:        item.product_name || item.name || 'Unknown Item',
            productCode: item.product_code || item.code || '',
            productType: item.product_type || '',
            brand:       item.brand?.brand_name || item.brand_name || item.brand || '',
            brandId:     String(item.brand_id ?? item.brand?.brand_id ?? ''),
            category:    item.category?.category_name || item.category_name || item.category || '',
            categoryId:  String(item.category_id ?? item.category?.category_id ?? ''),
            unitCost:    Number(item.cost_price ?? item.unit_price ?? item.price ?? 0),
            stock:       stockByProductId.get(pid) ?? 0,
            // ── NEW: carry the unit FK data from products.unit_id → unit_lookup ──
            unitId:      String(item.unit_id ?? item.unit?.unit_id ?? ''),
            unitName:    item.unit?.unit_name || item.unit_name || '',
            unitAbbr:    item.unit?.unit_abbreviation || '',
          };
        });
        setItemMasterOptions(normalized);
      }

      // Prefill from PO Recommendation navigation state
      const recommended = Array.isArray(location?.state?.recommendedItems)
        ? location.state.recommendedItems
        : [];
      if (recommended.length) {
        setLineItems((prev) => {
          const existingProductIds = new Set(prev.map((p) => String(p.productId ?? '')));
          const next = [...prev];
          recommended.forEach((r) => {
            const pid = String(r.product_id ?? '');
            if (!pid || existingProductIds.has(pid)) return;
            const qty      = Math.max(1, parseInt(r.suggested_qty, 10) || 1);
            const unitCost = Number(r.unit_cost ?? 0);
            next.push({
              id:        `reco-${pid}-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
              productId: Number(pid),
              product:   r.product_name || r.product_code || `Product ${pid}`,
              brand:     r.brand || '—',
              category:  r.category || '—',
              type:      (r.product_type || '').toLowerCase() === 'consumable' ? 'PART' : 'APPLIANCES',
              qty,
              unitCost,
              total:     qty * unitCost,
              location:  r.location_name || 'Recommended',
              unitName:  r.unit_name || '',   // ← NEW
            });
            existingProductIds.add(pid);
          });
          return next;
        });
      }

      setLoadingBatch(false);
      setLoadingSuppliers(false);
      setLoadingItemMaster(false);
    });
    return () => { mounted = false; };
  }, [location?.state]);

  // ── PO Status tab fetch ──────────────────────────────────────────────────
  useEffect(() => {
    if (activeTab !== 'status') return;
    let mounted = true;
    setLoadingPOStatus(true);
    setPoStatusError('');
    purchaseOrdersAPI.getAll({ per_page: 100 })
      .then((res) => {
        if (!mounted) return;
        setPoStatusList(Array.isArray(res?.data) ? res.data : []);
      })
      .catch((err) => {
        if (!mounted) return;
        setPoStatusError(err?.message || 'Failed to load purchase orders.');
      })
      .finally(() => { if (mounted) setLoadingPOStatus(false); });
    return () => { mounted = false; };
  }, [activeTab]);

  // ── Line item helpers ────────────────────────────────────────────────────
  const updateLineItemQty = useCallback((id, qty) => {
    const newQty = Math.max(1, parseInt(qty) || 1);
    setLineItems((prev) =>
      prev.map((item) =>
        item.id === id
          ? { ...item, qty: newQty, total: newQty * item.unitCost }
          : item
      )
    );
  }, []);

  const removeLineItem = useCallback((id) => {
    setLineItems((prev) => prev.filter((item) => item.id !== id));
  }, []);

  const itemsSubtotal = useMemo(
    () => lineItems.reduce((s, i) => s + i.total, 0),
    [lineItems]
  );
  const appliedVat = useMemo(() => Math.round(itemsSubtotal * 0.12), [itemsSubtotal]);
  const totalEstimatedValue = useMemo(() => itemsSubtotal + appliedVat, [itemsSubtotal, appliedVat]);

  // ── Manual item modal ────────────────────────────────────────────────────
  const openManualItemModal = useCallback(() => {
    setAddItemError('');
    setManualItemType('unit');
    setManualItemForm({
      category:    '',
      brand:       '',
      modelItemId: '',
      model:       '',
      variant:     '',
      unitId:      '',    // ← NEW
      unitName:    '',    // ← NEW
      currentStock: 0,
      orderQty:    1,
      unitCost:    0,
    });
    setShowManualItemModal(true);
  }, []);

  const handleManualItemFormChange = useCallback((field, value) => {
    setManualItemForm((prev) => ({ ...prev, [field]: value }));
  }, []);

  // ── NEW: handle unit selection — stores both id and display name ─────────
  const handleUnitSelect = useCallback((unitId) => {
    const found = units.find((u) => String(u.unit_id) === String(unitId));
    setManualItemForm((prev) => ({
      ...prev,
      unitId:   String(unitId || ''),
      unitName: found ? `${found.unit_name} (${found.unit_abbreviation})` : '',
    }));
  }, [units]);

  const filteredModelOptions = useMemo(() => {
    if (manualItemType === 'unit') {
      return itemMasterOptions.filter((item) => {
        if (item.productType !== 'appliance') return false;
        if (manualItemForm.category && item.category !== manualItemForm.category) return false;
        if (manualItemForm.brand && item.brand !== manualItemForm.brand) return false;
        return true;
      });
    }
    return itemMasterOptions.filter((item) => {
      if (item.productType !== 'consumable') return false;
      if (manualItemForm.category && item.category !== manualItemForm.category) return false;
      return true;
    });
  }, [itemMasterOptions, manualItemType, manualItemForm.category, manualItemForm.brand]);

  const handleModelSelect = useCallback(
    (itemId) => {
      const selected = itemMasterOptions.find((it) => String(it.id) === String(itemId));
      setManualItemForm((prev) => ({
        ...prev,
        modelItemId:  String(itemId || ''),
        model:        selected?.name || '',
        currentStock: selected?.stock ?? 0,
        unitCost:     selected ? selected.unitCost : prev.unitCost,
        category:     selected?.category || prev.category,
        brand:        selected?.brand || prev.brand,
        // ── NEW: auto-populate unit from the selected product's unit_id ──
        unitId:       selected?.unitId || prev.unitId,
        unitName:     selected?.unitName
          ? `${selected.unitName}${selected.unitAbbr ? ` (${selected.unitAbbr})` : ''}`
          : prev.unitName,
      }));
    },
    [itemMasterOptions]
  );

  // Clear model when filters change and selected model is no longer valid
  useEffect(() => {
    if (!manualItemForm.modelItemId) return;
    const stillValid = filteredModelOptions.some(
      (it) => String(it.id) === String(manualItemForm.modelItemId)
    );
    if (!stillValid) {
      setManualItemForm((prev) => ({
        ...prev,
        modelItemId:  '',
        model:        '',
        currentStock: 0,
        unitId:       '',
        unitName:     '',
      }));
    }
  }, [manualItemType, manualItemForm.modelItemId, filteredModelOptions]);

  // ── Confirm & Add line item ──────────────────────────────────────────────
  const handleConfirmAndAdd = useCallback(async () => {
    setAddItemError('');
    const {
      category, brand, modelItemId, model, variant,
      unitId, unitName, currentStock, orderQty, unitCost,
    } = manualItemForm;

    if (!category?.trim()) {
      setAddItemError('Category is required.');
      return;
    }
    const unitPrice = parseFloat(unitCost) || 0;
    if (unitPrice < 0) {
      setAddItemError('Unit cost must be 0 or greater.');
      return;
    }

    setAddingItem(true);
    try {
      if (manualItemType === 'unit' || (manualItemType === 'part' && modelItemId)) {
        if (!modelItemId) {
          setAddItemError('Model / Name is required.');
          setAddingItem(false);
          return;
        }
        const selectedModel = itemMasterOptions.find(
          (it) => String(it.id) === String(modelItemId)
        );
        if (!selectedModel) {
          setAddItemError('Selected model was not found in Item Master.');
          setAddingItem(false);
          return;
        }
        const qty          = Math.max(1, parseInt(orderQty, 10) || 1);
        const resolvedCost = Number(unitPrice || selectedModel.unitCost || 0);
        const locationName =
          locations.find(
            (l) => String(l.location_id ?? l.id ?? '') === String(selectedBranch)
          )?.location_name || 'Manual Entry';

        // Resolve unit display: prefer manual override, then product's own unit
        const resolvedUnitName = unitName ||
          (selectedModel.unitName
            ? `${selectedModel.unitName}${selectedModel.unitAbbr ? ` (${selectedModel.unitAbbr})` : ''}`
            : '');

        setLineItems((prev) => [
          ...prev,
          {
            id:        `existing-${selectedModel.id}-${Date.now()}`,
            productId: Number(selectedModel.id),
            product:   selectedModel.name,
            brand:     selectedModel.brand || '—',
            category:  selectedModel.category || category.trim(),
            type:      manualItemType === 'unit' ? 'APPLIANCES' : 'PART',
            qty,
            unitCost:  resolvedCost,
            total:     qty * resolvedCost,
            location:  locationName,
            unitId:    unitId || selectedModel.unitId || '',      // ← NEW: FK stored
            unitName:  resolvedUnitName,                          // ← NEW: display label
          },
        ]);
        setShowManualItemModal(false);
        return;
      }

      // Part / consumable — create a new product record via API
      const name = (variant || brand || 'Custom Item').trim().substring(0, 255);
      const code = `CUST-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
      const res  = await itemsAPI.create({
        name,
        code,
        category:      category.trim(),
        brand:         brand?.trim() || null,
        description:   variant || null,
        unit_price:    unitPrice,
        unit_id:       unitId ? Number(unitId) : null,   // ← NEW: send the FK, not a string
        reorder_level: parseInt(currentStock, 10) || 0,
        status:        'Active',
      });
      const created = res?.data;
      const qty     = Math.max(1, parseInt(orderQty, 10) || 1);

      setLineItems((prev) => [
        ...prev,
        {
          id:        `manual-${Date.now()}`,
          productId: created?.id ?? created?.product_id ?? null,
          product:   created?.name ?? name,
          brand:     brand || '—',
          category:  category.trim(),
          type:      'PART',
          qty,
          unitCost:  unitPrice,
          total:     qty * unitPrice,
          location:  'Manual Entry',
          unitId:    unitId || '',       // ← NEW
          unitName:  unitName || '',     // ← NEW
        },
      ]);
      setShowManualItemModal(false);
    } catch (err) {
      setAddItemError(err?.message || 'Failed to add item. Please try again.');
    } finally {
      setAddingItem(false);
    }
  }, [manualItemForm, manualItemType, itemMasterOptions, locations, selectedBranch, units]);

  // ── Final submission ─────────────────────────────────────────────────────
  const handleFinalSubmission = useCallback(async () => {
    setSubmitError('');

    if (lineItems.length === 0) {
      setSubmitError('Add at least one item to the purchase order.');
      return;
    }
    if (!selectedSupplierId) {
      setSubmitError('Please select a supplier.');
      return;
    }
    if (!poDate) {
      setSubmitError('Please select a PO date.');
      return;
    }

    setSubmitting(true);
    try {
      let preparedBy    = 'Inventory Analyst';
      let currentUserId = null;
      try {
        const rawUser    = localStorage.getItem('user');
        const parsedUser = rawUser ? JSON.parse(rawUser) : null;
        if (parsedUser?.first_name || parsedUser?.last_name) {
          preparedBy = `${parsedUser.first_name || ''} ${parsedUser.last_name || ''}`.trim();
        } else if (parsedUser?.username) {
          preparedBy = parsedUser.username;
        }
        currentUserId = parsedUser?.user_id ?? parsedUser?.id ?? null;
      } catch (_) { /* non-fatal */ }

      let locationIdForPo = '';
      try {
        const locRes = await locationsAPI.getAll({ per_page: 500 });
        const locs   = Array.isArray(locRes?.data) ? locRes.data : [];
        const matchBranch = locs.find(
          (l) => String(l.location_id ?? l.id ?? '') === String(selectedBranch)
        );
        const wh    = locs.find((l) => String(l.location_type || '').toLowerCase() === 'warehouse');
        const first = locs[0];
        const pick  = matchBranch || wh || first;
        locationIdForPo = String(pick?.location_id ?? pick?.id ?? '');
      } catch (_) { /* location resolution failed */ }

      const poDetails = lineItems
        .map((li) => {
          const pid = li.productId != null ? Number(li.productId) : NaN;
          if (!Number.isFinite(pid) || pid <= 0) return null;
          const qty  = Math.max(1, parseInt(li.qty, 10) || 1);
          const unit = Number(li.unitCost) || 0;
          return {
            product_id:       pid,
            quantity_ordered: qty,
            unit_price:       unit,
            subtotal:         Number(li.total) || qty * unit,
          };
        })
        .filter(Boolean);

      if (poDetails.length === 0) {
        setSubmitError(
          'No line items have a valid product ID. ' +
          'Make sure all items were selected from the Item Master, not created as ad-hoc entries.'
        );
        setSubmitting(false);
        return;
      }

      if (!locationIdForPo) {
        setSubmitError(
          'Could not resolve a warehouse location for this branch. ' +
          'Check that the selected branch has a matching location in Location Management.'
        );
        setSubmitting(false);
        return;
      }

      const skippedCount = lineItems.length - poDetails.length;

      let pendingStatusId = null;
      try {
        const stRes  = await statusAPI.getAll({ category: 'purchase_order' });
        const stRows = Array.isArray(stRes?.data) ? stRes.data : [];
        pendingStatusId = stRows.find(
          (s) => String(s.status_name || '').toLowerCase().includes('pending')
        )?.status_id ?? null;
      } catch (_) { /* optional */ }

      const createBody = {
        supplier_id:            Number(selectedSupplierId),
        location_id:            Number(locationIdForPo),
        po_number:              referenceNo,
        order_date:             poDate,
        expected_delivery_date: expectedDeliveryDate || null,
        details:                poDetails,
        ...(pendingStatusId ? { status_id: pendingStatusId } : {}),
      };

      const res         = await purchaseOrdersAPI.create(createBody);
      const data        = res?.data;
      const backendPoId = data?.po_id ?? data?.id;

      if (backendPoId == null || backendPoId === '') {
        setSubmitError(
          'The purchase order was sent but the server did not return an ID. ' +
          'Check the API response and your database connection.'
        );
        setSubmitting(false);
        return;
      }

      const totalQty  = lineItems.reduce((sum, item) => sum + Number(item.qty || 0), 0);
      const topItem   = lineItems[0];
      const dateLabel = new Date().toLocaleString('en-PH', {
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit',
      });

      const restockEntry = {
        id:                    `rstk-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
        item_name:             lineItems.length === 1
          ? (topItem?.product || 'Requested Item')
          : `${lineItems.length} low-stock items`,
        destination:           locations.find(
          (l) => String(l.location_id ?? l.id ?? '') === String(selectedBranch)
        )?.location_name || 'Location',
        quantity:              totalQty || lineItems.length,
        from:                  selectedSupplierName,
        requested_by:          preparedBy,
        date:                  dateLabel,
        status:                'pending',
        draft_reference:       referenceNo,
        estimated_amount:      Number(totalEstimatedValue ?? 0),
        expected_delivery_date: expectedDeliveryDate,
      };

      const line_items_snapshot = lineItems.map((li) => ({
        product:    li.product || '—',
        product_id: li.productId ?? null,
        qty:        li.qty,
        unit_cost:  li.unitCost,
        total:      li.total,
        type:       li.type || null,
        brand:      li.brand || null,
        category:   li.category || null,
        unit_name:  li.unitName || null,   // ← NEW: included in snapshot
      }));

      addApprovalQueueRestockRequest(restockEntry);
      addApprovalQueuePurchaseOrder({
        id:                    `aq-po-${Date.now()}`,
        po_number:             referenceNo,
        supplier:              selectedSupplierName,
        amount:                Number(totalEstimatedValue ?? 0),
        prepared_by:           preparedBy,
        date:                  dateLabel,
        expected_delivery_date: expectedDeliveryDate,
        line_items_snapshot,
        status:                'pending',
        synced_to_api:         true,
        backend_po_id:         backendPoId,
        api_create_payload:    null,
      });

      addSystemNotification({
        type:        'approval',
        title:       'New Restock Request',
        description: `${referenceNo} submitted by ${preparedBy}.`,
        details:     `${restockEntry.item_name} • Qty: ${restockEntry.quantity} • Est: ₱${restockEntry.estimated_amount.toLocaleString('en-PH')}`,
        route:       '/admin/approval-queue?tab=restock-requests',
      });

      if (skippedCount > 0) {
        setSubmitError(
          `PO saved successfully, but ${skippedCount} item(s) without a product ID were excluded from the database record.`
        );
      }

      setSubmitSuccess(true);
    } catch (err) {
      const msg = err?.errors
        ? Object.values(err.errors).flat().join(' ')
        : err?.message || 'Failed to submit purchase order. Please check your connection and try again.';
      setSubmitError(msg);
    } finally {
      setSubmitting(false);
    }
  }, [
    lineItems,
    selectedSupplierId,
    selectedSupplierName,
    poDate,
    paymentTerms,
    selectedBranch,
    referenceNo,
    totalEstimatedValue,
    locations,
    expectedDeliveryDate,
  ]);

  const handleAfterSuccessClose = useCallback(() => {
    setSubmitSuccess(false);
    setLineItems([]);
    setSubmitError('');
    window.scrollTo(0, 0);
  }, []);

  const createdOnLabel = useMemo(() => {
    if (!poDate) return '—';
    const d = new Date(`${poDate}T12:00:00`);
    if (Number.isNaN(d.getTime())) return '—';
    return d
      .toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
  }, [poDate]);

  const lineTypeLabel = (type) => (type === 'PART' ? 'PART' : 'APPLIANCE');

  const getStatusConfig = (statusName) => {
    const s = String(statusName || '').toLowerCase();
    if (s.includes('complet'))                        return { label: 'COMPLETED',          color: 'completed' };
    if (s.includes('partial'))                        return { label: 'PARTIALLY RECEIVED', color: 'partial'   };
    if (s.includes('approv') || s.includes('authoriz')) return { label: 'APPROVED',         color: 'approved'  };
    if (s.includes('reject'))                         return { label: 'REJECTED',            color: 'rejected'  };
    if (s.includes('cancel'))                         return { label: 'CANCELLED',           color: 'cancelled' };
    if (s.includes('pending'))                        return { label: 'PENDING APPROVAL',    color: 'pending'   };
    return { label: String(statusName || 'DRAFT').toUpperCase(), color: 'draft' };
  };

  const getPriorityFromAmount = (total, itemCount) => {
    const n = Number(total || 0);
    if (n >= 1000000 || itemCount >= 4) return { label: 'HIGH', color: 'high' };
    if (n >= 500000  || itemCount >= 2) return { label: 'MEDIUM', color: 'medium' };
    return { label: 'LOW', color: 'low' };
  };

  // ── PO Status tab renderer ───────────────────────────────────────────────
  const renderPoStatusView = () => {
    if (loadingPOStatus) return <Loader label="Loading purchase orders" />;
    if (poStatusError)   return <div className="po-status-error-msg">{poStatusError}</div>;
    if (poStatusList.length === 0) return <div className="po-status-empty">No purchase orders found.</div>;

    return (
      <div className="po-status-list">
        {poStatusList.map((po) => {
          const statusCfg = getStatusConfig(po.status?.status_name);
          const amount    = Number(po.grand_total || po.total_amount || 0);
          const itemCount = po.details?.length ?? 0;
          const priCfg    = getPriorityFromAmount(amount, itemCount);
          const createdByName =
            po.created_by
              ? `${po.created_by?.first_name || ''} ${po.created_by?.last_name || ''}`.trim() ||
                po.created_by?.username || '—'
              : '—';
          const actorName =
            po.approved_by
              ? `${po.approved_by?.first_name || ''} ${po.approved_by?.last_name || ''}`.trim() ||
                po.approved_by?.username || 'Admin User'
              : createdByName;
          const actionDate = po.updated_at ? String(po.updated_at).slice(0, 10) : '—';
          const receivedByName =
            po.received_by && typeof po.received_by === 'object'
              ? `${po.received_by?.first_name || ''} ${po.received_by?.last_name || ''}`.trim() ||
                po.received_by?.username || '—'
              : '—';

          return (
            <div key={po.po_id} className="po-status-card">
              <div className="po-status-card-body">
                <div className="po-status-card-left">
                  <div className="po-status-card-header-row">
                    <span className="po-status-card-number">{po.po_number}</span>
                    <span className={`po-status-badge po-status-badge--${statusCfg.color}`}>
                      {statusCfg.color === 'completed' && (
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13">
                          <path d="M9 11l3 3L22 4"></path>
                          <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                        </svg>
                      )}
                      {statusCfg.color === 'partial' && (
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13">
                          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                          <polyline points="7 10 12 15 17 10"></polyline>
                          <line x1="12" y1="15" x2="12" y2="3"></line>
                        </svg>
                      )}
                      {statusCfg.color === 'pending' && (
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13">
                          <circle cx="12" cy="12" r="10"></circle>
                          <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                      )}
                      {statusCfg.color === 'approved' && (
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13">
                          <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                          <polyline points="22 4 12 14.01 9 11.01"></polyline>
                        </svg>
                      )}
                      {statusCfg.color === 'rejected' && (
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13">
                          <circle cx="12" cy="12" r="10"></circle>
                          <line x1="15" y1="9" x2="9" y2="15"></line>
                          <line x1="9" y1="9" x2="15" y2="15"></line>
                        </svg>
                      )}
                      {statusCfg.color === 'cancelled' && (
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13">
                          <polyline points="3 6 5 6 21 6"></polyline>
                          <path d="M19 6l-1 14H6L5 6"></path>
                          <path d="M9 6V4h6v2"></path>
                        </svg>
                      )}
                      {statusCfg.label}
                    </span>
                    <span className={`po-priority-badge po-priority-badge--${priCfg.color}`}>
                      {priCfg.label}
                    </span>
                  </div>
                  <div className="po-status-card-meta">
                    <div className="po-status-meta-item">
                      <span className="po-status-meta-label">SUPPLIER</span>
                      <span className="po-status-meta-value">{po.supplier?.supplier_name || '—'}</span>
                    </div>
                    <div className="po-status-meta-item">
                      <span className="po-status-meta-label">CREATED BY</span>
                      <span className="po-status-meta-value">{createdByName}</span>
                    </div>
                    <div className="po-status-meta-item">
                      <span className="po-status-meta-label">CREATED DATE</span>
                      <span className="po-status-meta-value">
                        {isoToMDY(po.order_date)}
                      </span>
                    </div>
                    <div className="po-status-meta-item">
                      <span className="po-status-meta-label">ITEMS</span>
                      <span className="po-status-meta-value">
                        {itemCount} {itemCount === 1 ? 'item' : 'items'}
                      </span>
                    </div>
                  </div>
                </div>
                <div className="po-status-card-right">
                  <span className="po-status-total-label">TOTAL AMOUNT</span>
                  <span className="po-status-total-amount">₱{amount.toLocaleString('en-PH')}</span>
                  <button
                    type="button"
                    className="po-status-view-btn"
                    onClick={() => setSelectedPO(po)}
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                      <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                    VIEW DETAILS
                  </button>
                </div>
              </div>

              {statusCfg.color === 'approved' && (
                <div className="po-status-note po-status-note--approved">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                  </svg>
                  <span><strong>Approved by {actorName}</strong> on {actionDate}</span>
                </div>
              )}
              {statusCfg.color === 'rejected' && (
                <div className="po-status-note po-status-note--rejected">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="15" y1="9" x2="9" y2="15"></line>
                    <line x1="9" y1="9" x2="15" y2="15"></line>
                  </svg>
                  <span><strong>Rejected by {actorName}</strong> on {actionDate}</span>
                </div>
              )}
              {statusCfg.color === 'cancelled' && (
                <div className="po-status-note po-status-note--cancelled">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15">
                    <polyline points="3 6 5 6 21 6"></polyline>
                    <path d="M19 6l-1 14H6L5 6"></path>
                    <path d="M9 6V4h6v2"></path>
                  </svg>
                  <span><strong>Cancelled by {actorName}</strong> on {actionDate}</span>
                </div>
              )}
              {statusCfg.color === 'completed' && (
                <div className="po-status-note po-status-note--completed">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15">
                    <path d="M9 11l3 3L22 4"></path>
                    <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                  </svg>
                  <span>
                    <strong>Fully received{receivedByName !== '—' ? ` by ${receivedByName}` : ''}</strong>
                    {' '}on {actionDate}
                  </span>
                </div>
              )}
              {statusCfg.color === 'partial' && (
                <div className="po-status-note po-status-note--partial">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="7 10 12 15 17 10"></polyline>
                    <line x1="12" y1="15" x2="12" y2="3"></line>
                  </svg>
                  <span>
                    <strong>Partially received{receivedByName !== '—' ? ` by ${receivedByName}` : ''}</strong>
                    {' '}on {actionDate} — receiving in progress
                  </span>
                </div>
              )}
            </div>
          );
        })}
      </div>
    );
  };

  // ── Render ───────────────────────────────────────────────────────────────
  return (
    <AdminLayout>
      <div className="ak-page po-recommendation-page po-draft-creator-page">
        {submitSuccess && (
          <div className="po-success-banner">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="18" height="18">
              <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
              <polyline points="22 4 12 14.01 9 11.01"></polyline>
            </svg>
            Purchase Order <strong>{referenceNo}</strong> submitted successfully!
            <button type="button" className="po-success-close" onClick={handleAfterSuccessClose}>✕</button>
          </div>
        )}

        <div className="po-creator-header-card">
          <div className="po-creator-header-grid">
            <div className="po-creator-header-main">
              <div className="po-creator-cart-wrap po-creator-cart-wrap--doc" aria-hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" width="28" height="28">
                  <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                  <polyline points="14 2 14 8 20 8"></polyline>
                  <line x1="16" y1="13" x2="8" y2="13"></line>
                  <line x1="16" y1="17" x2="8" y2="17"></line>
                  <line x1="10" y1="9" x2="8" y2="9"></line>
                </svg>
              </div>
              <div className="po-creator-header-copy">
                <h1 className="po-page-title">DRAFT PURCHASE ORDERS</h1>
                <p className="po-page-subtitle">View and manage all draft POs</p>
              </div>
            </div>
            <div className="po-creator-header-actions">
              <button
                type="button"
                className="po-btn po-btn-final-submission"
                onClick={() => {
                  if (activeTab === 'status') { setActiveTab('create'); return; }
                  handleFinalSubmission();
                }}
                disabled={submitting}
              >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="16" height="16">
                  <path d="M22 2L11 13"></path>
                  <path d="M22 2l-7 20-4-9-9-4 20-7z"></path>
                </svg>
                {submitting ? 'SUBMITTING...' : 'FINAL SUBMISSION'}
              </button>
            </div>
          </div>
        </div>

        <div className="po-tab-nav">
          <button
            type="button"
            className={`po-tab-btn${activeTab === 'create' ? ' po-tab-btn--active' : ''}`}
            onClick={() => setActiveTab('create')}
          >
            Create PO
          </button>
          <button
            type="button"
            className={`po-tab-btn${activeTab === 'status' ? ' po-tab-btn--active' : ''}`}
            onClick={() => setActiveTab('status')}
          >
            Draft PO Status
          </button>
        </div>

        {submitError && activeTab === 'create' && (
          <div className="po-submit-error">{submitError}</div>
        )}

        {activeTab === 'status' && renderPoStatusView()}

        {activeTab === 'create' && (
          <div className="po-content-layout">
            <div className="po-main-content">
              <div className="po-details-card">
                <div className="po-details-grid">
                  <div className="po-field">
                    <label className="po-field-label">REFERENCE NO.</label>
                    <div className="po-reference-badge">{referenceNo}</div>
                  </div>
                  <div className="po-field">
                    <label className="po-field-label">SUPPLIER</label>
                    <select
                      className="po-select"
                      value={selectedSupplierId}
                      onChange={(e) => setSelectedSupplierId(e.target.value)}
                      disabled={loadingSuppliers}
                    >
                      {loadingSuppliers && <option value="">Loading suppliers…</option>}
                      {!loadingSuppliers && suppliers.length === 0 && (
                        <option value="">No suppliers found</option>
                      )}
                      {suppliers.map((s) => (
                        <option key={s.supplier_id ?? s.id} value={String(s.supplier_id ?? s.id)}>
                          {s.supplier_name ?? s.name}
                        </option>
                      ))}
                    </select>
                  </div>
                  <div className="po-field">
                    <label className="po-field-label">TARGET LOCATION</label>
                    <select
                      className="po-select"
                      value={selectedBranch}
                      onChange={(e) => setSelectedBranch(e.target.value)}
                      disabled={loadingLocations}
                    >
                      {loadingLocations && <option value="">Loading locations…</option>}
                      {!loadingLocations && locations.length === 0 && (
                        <option value="">No locations found</option>
                      )}
                      {locations.map((loc) => (
                        <option
                          key={loc.location_id ?? loc.id}
                          value={String(loc.location_id ?? loc.id ?? '')}
                        >
                          {loc.location_name || loc.name}
                          {loc.location_type ? ` (${loc.location_type})` : ''}
                        </option>
                      ))}
                    </select>
                  </div>
                  <div className="po-field">
                    <label className="po-field-label">PAYMENT TERMS</label>
                    <select
                      className="po-select"
                      value={paymentTerms}
                      onChange={(e) => setPaymentTerms(e.target.value)}
                    >
                      <option>Net 30</option>
                      <option>Net 60</option>
                      <option>Net 15</option>
                      <option>COD</option>
                      <option>Upon Delivery</option>
                    </select>
                  </div>
                  <div className="po-field">
                    <label className="po-field-label">PO DATE</label>
                    <DateInput
                      className="po-input"
                      value={poDate}
                      onChange={(e) => setPoDate(e.target.value)}
                    />
                  </div>
                  <div className="po-field">
                    <label className="po-field-label">EXPECTED DELIVERY (ARRIVAL)</label>
                    <DateInput
                      className="po-input"
                      value={expectedDeliveryDate}
                      onChange={(e) => setExpectedDeliveryDate(e.target.value)}
                    />
                    <span className="po-field-hint" style={{ display: 'block', marginTop: 6, fontSize: 12, color: '#64748b' }}>
                      Receive PO scan is blocked until this date (after the PO is approved).
                    </span>
                  </div>
                </div>
              </div>

              <div className="po-line-items-card">
                <div className="po-line-items-header">
                  <div className="po-line-items-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="18" height="18">
                      <line x1="8" y1="6" x2="21" y2="6"></line>
                      <line x1="8" y1="12" x2="21" y2="12"></line>
                      <line x1="8" y1="18" x2="21" y2="18"></line>
                      <line x1="3" y1="6" x2="3.01" y2="6"></line>
                      <line x1="3" y1="12" x2="3.01" y2="12"></line>
                      <line x1="3" y1="18" x2="3.01" y2="18"></line>
                    </svg>
                    LINE ITEMS ORGANIZATION
                    <span className="po-line-items-count">{lineItems.length}</span>
                  </div>
                  <button
                    type="button"
                    className="po-btn po-btn-add-product"
                    onClick={openManualItemModal}
                  >
                    + ADD CUSTOM PRODUCT
                  </button>
                </div>

                {lineItems.length === 0 ? (
                  <div className="po-line-items-empty">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" width="40" height="40">
                      <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                      <line x1="8" y1="21" x2="16" y2="21"></line>
                      <line x1="12" y1="17" x2="12" y2="21"></line>
                    </svg>
                    <p>No line items yet.</p>
                    <span>Add products using the button above, or review PO Recommendation for suggested items.</span>
                  </div>
                ) : (
                  <div className="po-table-wrapper">
                    <table className="po-table">
                      <thead>
                        <tr>
                          <th>PRODUCT / MODEL / LOCATION</th>
                          <th>CATEGORY</th>
                          <th>TYPE</th>
                          <th>UNIT</th>   {/* ← NEW column header */}
                          <th>QTY</th>
                          <th>UNIT COST</th>
                          <th>TOTAL</th>
                          <th></th>
                        </tr>
                      </thead>
                      <tbody>
                        {lineItems.map((item) => (
                          <tr key={item.id}>
                            <td>
                              <div className="po-product-cell">
                                <strong>{item.product}</strong>
                                <div className="po-product-meta">
                                  {selectedSupplierName}
                                  <span className="po-product-meta-sep"> | </span>
                                  {item.location && item.location !== '—' ? item.location : '—'}
                                </div>
                              </div>
                            </td>
                            <td>{item.category}</td>
                            <td>
                              <span className={`po-type-badge ${item.type === 'PART' ? 'po-type-part' : 'po-type-appliance'}`}>
                                {lineTypeLabel(item.type)}
                              </span>
                            </td>
                            {/* ── NEW: unit column — shows unit_lookup name or em-dash ── */}
                            <td>
                              <span style={{ fontSize: 12, color: '#64748b' }}>
                                {item.unitName || '—'}
                              </span>
                            </td>
                            <td>
                              <input
                                className="po-qty-input"
                                type="number"
                                min="1"
                                value={item.qty}
                                onChange={(e) => updateLineItemQty(item.id, e.target.value)}
                              />
                            </td>
                            <td>₱{item.unitCost.toLocaleString()}</td>
                            <td><strong>₱{item.total.toLocaleString()}</strong></td>
                            <td>
                              <button
                                type="button"
                                className="po-remove-btn"
                                onClick={() => removeLineItem(item.id)}
                                title="Remove item"
                              >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                                  <polyline points="3 6 5 6 21 6"></polyline>
                                  <path d="M19 6l-1 14H6L5 6"></path>
                                  <path d="M10 11v6"></path>
                                  <path d="M14 11v6"></path>
                                  <path d="M9 6V4h6v2"></path>
                                </svg>
                              </button>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            </div>

            <div className="po-summary-sidebar">
              <div className="po-summary-card">
                <h3 className="po-summary-title">
                  <span className="po-summary-title-icon" aria-hidden>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="18" height="18">
                      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                      <polyline points="14 2 14 8 20 8"></polyline>
                      <line x1="16" y1="13" x2="8" y2="13"></line>
                      <line x1="16" y1="17" x2="8" y2="17"></line>
                    </svg>
                  </span>
                  COMMERCIAL SUMMARY
                </h3>
                <div className="po-summary-row">
                  <span>ITEMS SUBTOTAL</span>
                  <span className="po-summary-value">₱{itemsSubtotal.toLocaleString()}</span>
                </div>
                <div className="po-summary-row">
                  <span>APPLIED VAT (12%)</span>
                  <span className="po-summary-value po-summary-vat">₱{appliedVat.toLocaleString()}</span>
                </div>
                <div className="po-summary-row po-summary-row--logistics">
                  <span>LOGISTICS EST.</span>
                  <span className="po-summary-logistics-value">CALCULATED ON APPROVAL</span>
                </div>
                <div className="po-summary-total">
                  <span>TOTAL ESTIMATED VALUE</span>
                  <span className="po-summary-total-value">₱{totalEstimatedValue.toLocaleString()}</span>
                </div>
              </div>

              <div className="po-export-reminder">
                <span className="po-export-reminder-icon" aria-hidden>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="18" height="18">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="16" x2="12" y2="12"></line>
                    <line x1="12" y1="8" x2="12.01" y2="8"></line>
                  </svg>
                </span>
                <div className="po-export-reminder-body">
                  <h4>EXPORT REMINDER</h4>
                  <p>
                    Use the Excel or Document export buttons above to generate formal files for
                    offline archiving or physical signing.
                  </p>
                </div>
              </div>
            </div>
          </div>
        )}
      </div>

      {/* ── PO Detail Modal ──────────────────────────────────────────────── */}
      <Modal
        isOpen={!!selectedPO}
        onClose={() => setSelectedPO(null)}
        title=""
        hideHeader
        maxWidth={700}
      >
        {selectedPO && (() => {
          const d         = selectedPO;
          const statusCfg = getStatusConfig(d.status?.status_name);
          const amount    = Number(d.grand_total || d.total_amount || 0);
          const priCfg    = getPriorityFromAmount(amount, d.details?.length ?? 0);
          const createdByName = d.created_by
            ? `${d.created_by.first_name || ''} ${d.created_by.last_name || ''}`.trim() || d.created_by.username || '—'
            : '—';
          const actorName = d.approved_by
            ? `${d.approved_by.first_name || ''} ${d.approved_by.last_name || ''}`.trim() || d.approved_by.username || '—'
            : '—';
          const details = Array.isArray(d.details) ? d.details : [];

          return (
            <div className="po-detail-modal">
              <div className="po-detail-head">
                <div className="po-detail-head-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" width="26" height="26">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                    <line x1="10" y1="9" x2="8" y2="9"></line>
                  </svg>
                </div>
                <div className="po-detail-head-info">
                  <h2 className="po-detail-head-number">{d.po_number}</h2>
                  <p className="po-detail-head-sub">
                    {d.supplier?.supplier_name || '—'} &nbsp;·&nbsp; {isoToMDY(d.order_date)}
                  </p>
                </div>
                <div className="po-detail-head-badges">
                  <span className={`po-detail-status-pill po-detail-status-pill--${statusCfg.color}`}>
                    {statusCfg.label}
                  </span>
                  <span className={`po-detail-priority-pill po-detail-priority-pill--${priCfg.color}`}>
                    {priCfg.label}
                  </span>
                </div>
                <button type="button" className="po-detail-head-close" onClick={() => setSelectedPO(null)} aria-label="Close">×</button>
              </div>

              <div className={`po-detail-strip po-detail-strip--${statusCfg.color}`}>
                {statusCfg.color === 'pending'   && <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>}
                {statusCfg.color === 'approved'  && <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>}
                {statusCfg.color === 'rejected'  && <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>}
                {statusCfg.color === 'cancelled' && <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg>}
                <span>
                  <strong>{statusCfg.label}</strong>
                  {(statusCfg.color === 'approved' || statusCfg.color === 'rejected') && actorName !== '—' && ` — by ${actorName}`}
                </span>
              </div>

              <div className="po-detail-body">
                <div className="po-detail-address-grid">
                  <div className="po-detail-address-block">
                    <div className="po-detail-address-block-title">Supplier / Vendor</div>
                    <div className="po-detail-address-row">
                      <span className="po-detail-address-label">Name</span>
                      <span className="po-detail-address-value po-detail-address-value--name">{d.supplier?.supplier_name || '—'}</span>
                    </div>
                    {d.supplier?.address && (
                      <div className="po-detail-address-row">
                        <span className="po-detail-address-label">Address</span>
                        <span className="po-detail-address-value">{d.supplier.address}</span>
                      </div>
                    )}
                    {(d.supplier?.origin || d.supplier?.region) && (
                      <div className="po-detail-address-row">
                        <span className="po-detail-address-label">Origin / Region</span>
                        <span className="po-detail-address-value">{[d.supplier.origin, d.supplier.region].filter(Boolean).join(', ')}</span>
                      </div>
                    )}
                    {d.supplier?.contact_person && (
                      <div className="po-detail-address-row">
                        <span className="po-detail-address-label">Contact Person</span>
                        <span className="po-detail-address-value">{d.supplier.contact_person}</span>
                      </div>
                    )}
                    {d.supplier?.contact_number && (
                      <div className="po-detail-address-row">
                        <span className="po-detail-address-label">Phone</span>
                        <span className="po-detail-address-value">{d.supplier.contact_number}</span>
                      </div>
                    )}
                    {d.supplier?.email && (
                      <div className="po-detail-address-row">
                        <span className="po-detail-address-label">Email</span>
                        <span className="po-detail-address-value">{d.supplier.email}</span>
                      </div>
                    )}
                    {d.supplier?.tin && (
                      <div className="po-detail-address-row">
                        <span className="po-detail-address-label">TIN</span>
                        <span className="po-detail-address-value">{d.supplier.tin}</span>
                      </div>
                    )}
                  </div>
                  <div className="po-detail-address-block">
                    <div className="po-detail-address-block-title">Ship To / Deliver To</div>
                    <div className="po-detail-address-row">
                      <span className="po-detail-address-label">Branch / Location</span>
                      <span className="po-detail-address-value po-detail-address-value--name">{d.location?.location_name || '—'}</span>
                    </div>
                    {(d.location?.address || d.location?.city || d.location?.province || d.location?.region) && (
                      <div className="po-detail-address-row">
                        <span className="po-detail-address-label">Address</span>
                        <span className="po-detail-address-value">
                          {[d.location.address, d.location.city, d.location.province, d.location.region].filter(Boolean).join(', ')}
                        </span>
                      </div>
                    )}
                    {d.location?.contact_number && (
                      <div className="po-detail-address-row">
                        <span className="po-detail-address-label">Phone</span>
                        <span className="po-detail-address-value">{d.location.contact_number}</span>
                      </div>
                    )}
                    {d.location?.email && (
                      <div className="po-detail-address-row">
                        <span className="po-detail-address-label">Email</span>
                        <span className="po-detail-address-value">{d.location.email}</span>
                      </div>
                    )}
                    {d.location?.manager_name && (
                      <div className="po-detail-address-row">
                        <span className="po-detail-address-label">Manager</span>
                        <span className="po-detail-address-value">{d.location.manager_name}</span>
                      </div>
                    )}
                  </div>
                </div>

                <div className="po-detail-info-grid">
                  <div className="po-detail-info-item">
                    <span className="po-detail-info-label">CREATED BY</span>
                    <span className="po-detail-info-value">{createdByName}</span>
                  </div>
                  <div className="po-detail-info-item">
                    <span className="po-detail-info-label">ORDER DATE</span>
                    <span className="po-detail-info-value">{isoToMDY(d.order_date)}</span>
                  </div>
                  <div className="po-detail-info-item">
                    <span className="po-detail-info-label">EXPECTED DELIVERY</span>
                    <span className="po-detail-info-value">{isoToMDY(d.expected_delivery_date)}</span>
                  </div>
                  <div className="po-detail-info-item">
                    <span className="po-detail-info-label">TOTAL AMOUNT</span>
                    <span className="po-detail-info-value" style={{ color: '#dc2626', fontWeight: 800 }}>₱{amount.toLocaleString('en-PH')}</span>
                  </div>
                  {actorName !== '—' && (
                    <div className="po-detail-info-item">
                    <span className="po-detail-info-label">
                      {statusCfg.color === 'rejected' ? 'REVIEWED BY' : 'APPROVED BY'}
                    </span>
                    <span className="po-detail-info-value">
                      {actorName !== '—' ? actorName : 'Awaiting Approval'}
                    </span>
                  </div>
                  )}
                </div>

                <div className="po-detail-items-wrap">
                  <div className="po-detail-items-title-row">
                    <span className="po-detail-items-heading">LINE ITEMS</span>
                    <span className="po-detail-items-count">{details.length}</span>
                  </div>
                  {details.length === 0 ? (
                    <p className="po-detail-no-items">No items recorded for this PO.</p>
                  ) : (
                    <table className="po-detail-table">
                      <thead>
                        <tr>
                          <th>#</th>
                          <th>PRODUCT</th>
                          <th>UNIT</th>   {/* ← NEW column in detail modal */}
                          <th>QTY</th>
                          <th>UNIT PRICE</th>
                          <th>SUBTOTAL</th>
                        </tr>
                      </thead>
                      <tbody>
                        {details.map((item, idx) => {
                          const ab = item.approvedBy || item.approved_by || null;
                          const detailApprovedBy = ab
                            ? `${ab.first_name || ''} ${ab.last_name || ''}`.trim() || ab.username || '—'
                            : '—';
                          // ── NEW: resolve unit from the joined product.unit relationship ──
                          const unitDisplay = item.product?.unit?.unit_name
                            ? `${item.product.unit.unit_name} (${item.product.unit.unit_abbreviation || ''})`
                            : '—';
                          return (
                            <tr key={item.po_detail_id ?? idx}>
                              <td className="po-detail-table-idx">{idx + 1}</td>
                              <td>
                                {item.product?.product_name || item.product?.name || `Item ${idx + 1}`}
                                {ab && (
                                  <span style={{ display: 'block', fontSize: 11, color: '#15803d' }}>
                                    ✓ Approved by: {detailApprovedBy}
                                  </span>
                                )}
                              </td>
                              {/* ── NEW: unit cell ── */}
                              <td style={{ fontSize: 12, color: '#64748b' }}>{unitDisplay}</td>
                              <td>{item.quantity_ordered}</td>
                              <td>₱{Number(item.unit_price || 0).toLocaleString('en-PH')}</td>
                              <td className="po-detail-table-sub">₱{Number(item.subtotal || 0).toLocaleString('en-PH')}</td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  )}
                </div>

                <div className="po-detail-total">
                  <span className="po-detail-total-label">TOTAL ESTIMATED VALUE</span>
                  <span className="po-detail-total-value">₱{amount.toLocaleString('en-PH')}</span>
                </div>
              </div>

              {(() => {
                const poPayload = {
                  po_number:             d.po_number,
                  status:                statusCfg.label,
                  supplier: {
                    name:           d.supplier?.supplier_name,
                    address:        d.supplier?.address,
                    origin:         d.supplier?.origin,
                    region:         d.supplier?.region,
                    contact_person: d.supplier?.contact_person,
                    contact_number: d.supplier?.contact_number,
                    email:          d.supplier?.email,
                    tin:            d.supplier?.tin,
                  },
                  ship_to: {
                    name:           d.location?.location_name,
                    address:        [d.location?.address, d.location?.city, d.location?.province, d.location?.region].filter(Boolean).join(', ') || null,
                    contact_number: d.location?.contact_number,
                    email:          d.location?.email,
                    manager_name:   d.location?.manager_name,
                  },
                  prepared_by:           createdByName,
                  approved_by:           actorName !== '—' ? actorName : null,
                  order_date:            d.order_date ? String(d.order_date).slice(0, 10) : '—',
                  expected_delivery_date: d.expected_delivery_date ? String(d.expected_delivery_date).slice(0, 10) : '—',
                  items: details.map((item) => ({
                    name:       item.product?.product_name || item.product?.name || '—',
                    unit:       item.product?.unit?.unit_name || '—',   // ← NEW in export payload
                    qty:        item.quantity_ordered,
                    unit_price: item.unit_price,
                    subtotal:   item.subtotal,
                  })),
                  total_amount: amount,
                };
                return (
                  <div className="po-detail-footer">
                    <button type="button" className="po-detail-btn po-detail-btn--excel" onClick={() => exportPOToExcel(poPayload)}>
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                      EXPORT EXCEL
                    </button>
                    <button type="button" className="po-detail-btn po-detail-btn--doc" onClick={() => exportPOToDocument(poPayload)}>
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                      EXPORT DOCUMENT
                    </button>
                  </div>
                );
              })()}
            </div>
          );
        })()}
      </Modal>

      {/* ── Manual Item Modal ────────────────────────────────────────────── */}
      <Modal
        isOpen={showManualItemModal}
        onClose={() => setShowManualItemModal(false)}
        title=""
        hideHeader
        maxWidth={760}
      >
        <div className="manual-item-modal">
          <div className="manual-item-modal-header">
            <div className="manual-item-modal-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="20" height="20">
                <line x1="12" y1="5" x2="12" y2="19"></line>
                <line x1="5" y1="12" x2="19" y2="12"></line>
              </svg>
            </div>
            <div>
              <h2 className="manual-item-modal-title">MANUAL ITEM ENTRY</h2>
              <p className="manual-item-modal-subtitle">ADD CUSTOMIZED ITEMS TO PROCUREMENT</p>
            </div>
            <button
              type="button"
              className="manual-item-close"
              onClick={() => setShowManualItemModal(false)}
              aria-label="Close manual item modal"
            >
              ×
            </button>
          </div>

          <div className="manual-item-type-toggle">
            <button
              type="button"
              className={`manual-item-type-btn${manualItemType === 'unit' ? ' active' : ''}`}
              onClick={() => setManualItemType('unit')}
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15">
                <rect x="4" y="4" width="16" height="16" rx="2"></rect>
                <line x1="4" y1="12" x2="20" y2="12"></line>
              </svg>
              UNIT / APPLIANCE
            </button>
            <button
              type="button"
              className={`manual-item-type-btn${manualItemType === 'part' ? ' active' : ''}`}
              onClick={() => setManualItemType('part')}
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15">
                <circle cx="12" cy="12" r="3"></circle>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
              </svg>
              PART / CONSUMABLE
            </button>
          </div>

          <div className="manual-item-form">
            {/* Row 1: Category */}
            <div className="manual-item-form-row">
              <div className="manual-item-form-field">
                <label>CATEGORY</label>
                <select
                  value={manualItemForm.category}
                  onChange={(e) => handleManualItemFormChange('category', e.target.value)}
                  disabled={loadingBatch}
                >
                  <option value="">Select Category</option>
                  {categories.map((cat) => (
                    <option key={cat.category_id} value={cat.category_name}>
                      {cat.category_name}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            {/* Row 2: Type-specific fields */}
            {manualItemType === 'unit' ? (
              <div className="manual-item-form-row">
                <div className="manual-item-form-field">
                  <label>BRAND</label>
                  <select
                    value={manualItemForm.brand}
                    onChange={(e) => handleManualItemFormChange('brand', e.target.value)}
                    disabled={loadingBatch}
                  >
                    <option value="">Select Brand</option>
                    {brands.map((b) => (
                      <option key={b.brand_id} value={b.brand_name}>
                        {b.brand_name}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="manual-item-form-field">
                  <label>MODEL / NAME</label>
                  <select
                    value={manualItemForm.modelItemId}
                    onChange={(e) => handleModelSelect(e.target.value)}
                    disabled={loadingItemMaster}
                  >
                    <option value="">
                      {loadingItemMaster ? 'Loading model list…' : 'Select Model / Name'}
                    </option>
                    {filteredModelOptions.map((it) => (
                      <option key={it.id} value={it.id}>
                        {it.productCode ? `${it.productCode} — ${it.name}` : it.name}
                      </option>
                    ))}
                  </select>
                </div>
              </div>
            ) : (
              <div className="manual-item-form-row">
                <div className="manual-item-form-field">
                  <label>PRODUCT / SUPPLY</label>
                  <select
                    value={manualItemForm.modelItemId}
                    onChange={(e) => handleModelSelect(e.target.value)}
                    disabled={loadingItemMaster}
                  >
                    <option value="">
                      {loadingItemMaster ? 'Loading product list…' : 'Select Product / Supply'}
                    </option>
                    {filteredModelOptions.map((it) => (
                      <option key={it.id} value={it.id}>
                        {it.productCode ? `${it.productCode} — ${it.name}` : it.name}
                      </option>
                    ))}
                  </select>
                </div>

                {/*
                  ── CHANGED: was a hardcoded <select> with static strings.
                  Now dynamically populated from unit_lookup via unitsAPI.
                  The selected unit_id is stored as a FK reference, matching
                  products.unit_id → unit_lookup.unit_id in your DB schema.
                  If a product was selected above, the unit auto-fills from
                  the product's own unit_id; the user can still override it.
                */}
                <div className="manual-item-form-field">
                  <label>MEASUREMENT / UNIT</label>
                  <select
                    value={manualItemForm.unitId}
                    onChange={(e) => handleUnitSelect(e.target.value)}
                    disabled={loadingBatch || units.length === 0}
                  >
                    <option value="">
                      {loadingBatch
                        ? 'Loading units…'
                        : units.length === 0
                          ? 'No units available'
                          : 'Select Measurement / Unit'}
                    </option>
                    {units.map((u) => (
                      <option key={u.unit_id} value={String(u.unit_id)}>
                        {u.unit_name} ({u.unit_abbreviation})
                      </option>
                    ))}
                  </select>
                  {/* Show currently resolved unit name as a hint when auto-filled */}
                  {manualItemForm.unitName && (
                    <span style={{ display: 'block', marginTop: 4, fontSize: 11, color: '#64748b' }}>
                      Selected: {manualItemForm.unitName}
                    </span>
                  )}
                </div>
              </div>
            )}

            {/* Row 3: Stock / Qty / Cost */}
            <div className="manual-item-form-row manual-item-form-row-3">
              <div className="manual-item-form-field">
                <label>CURRENT STOCK</label>
                <input
                  type="number"
                  min="0"
                  value={manualItemForm.currentStock}
                  readOnly
                  disabled
                />
              </div>
              <div className="manual-item-form-field">
                <label>ORDER QTY</label>
                <input
                  type="number"
                  min="1"
                  value={manualItemForm.orderQty}
                  onChange={(e) => handleManualItemFormChange('orderQty', e.target.value)}
                />
              </div>
              <div className="manual-item-form-field">
                <label>UNIT COST (₱)</label>
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  value={manualItemForm.unitCost}
                  onChange={(e) => handleManualItemFormChange('unitCost', e.target.value)}
                />
              </div>
            </div>
          </div>

          {addItemError && (
            <div className="manual-item-error" role="alert">
              {addItemError}
            </div>
          )}

          <div className="manual-item-actions">
            <button
              type="button"
              className="manual-item-btn-cancel"
              onClick={() => setShowManualItemModal(false)}
              disabled={addingItem}
            >
              CANCEL
            </button>
            <button
              type="button"
              className="manual-item-btn-confirm"
              onClick={handleConfirmAndAdd}
              disabled={addingItem}
            >
              {addingItem ? 'Adding…' : 'CONFIRM & ADD'}
            </button>
          </div>
        </div>
      </Modal>
    </AdminLayout>
  );
};

export default DraftPOCreator;