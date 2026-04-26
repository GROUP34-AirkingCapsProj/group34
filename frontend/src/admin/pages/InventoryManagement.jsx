import React, { useState, useEffect, useCallback, useRef } from 'react';
import AdminLayout from '../components/AdminLayout';
import Modal from '../components/Modal';
import { inventoryAPI, locationsAPI, categoriesAPI, batchAPI } from '../services/api';
import '../styles/dashboard_air.css';
import '../styles/inventory_management.css';

const InventoryManagement = () => {
  const [inventory, setInventory] = useState([]);
  const [warehouses, setWarehouses] = useState([]);
  const [categories, setCategories] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [currentPage, setCurrentPage] = useState(1);
  const [pagination, setPagination] = useState({ total: 0, last_page: 1 });
  const [viewingItem, setViewingItem] = useState(null);
  const [editingItem, setEditingItem] = useState(null);
  const [searchTerm, setSearchTerm] = useState('');
  const rowsPerPage = 10;
  const debounceRef = useRef(null);

  useEffect(() => {
    const fetchLookups = async () => {
      try {
        // Use batch API to fetch locations and categories in a single request
        const batchRes = await batchAPI.get({ include: ['locations', 'categories'] });
        const batchData = batchRes?.data || {};
        setWarehouses(Array.isArray(batchData.locations?.data) ? batchData.locations.data : []);
        setCategories(Array.isArray(batchData.categories?.data) ? batchData.categories.data : []);
      } catch (err) {
        console.error('Batch fetch failed, falling back to individual requests:', err);
        // Fallback to individual requests
        try {
          const [whRes, catRes] = await Promise.all([
            locationsAPI.getAll({ per_page: 200 }),
            categoriesAPI.getAll(),
          ]);
          setWarehouses(Array.isArray(whRes.data) ? whRes.data : []);
          setCategories(Array.isArray(catRes?.data) ? catRes.data : []);
        } catch (fallbackErr) {
          console.error('Fallback fetch also failed:', fallbackErr);
        }
      }
    };
    fetchLookups();
  }, []);

  const fetchInventory = useCallback(async (page = 1) => {
    try {
      setLoading(true);
      const params = { page, per_page: rowsPerPage };
      if (searchTerm) params.search = searchTerm;
      const res = await inventoryAPI.getAll(params);
      setInventory(Array.isArray(res.data) ? res.data : []);
      setPagination(res.pagination || { total: 0, last_page: 1 });
      setError(null);
    } catch (err) {
      setError(err.message);
      console.error('Failed to fetch inventory:', err);
    } finally {
      setLoading(false);
    }
  }, [searchTerm, rowsPerPage]);

  useEffect(() => {
    fetchInventory(currentPage);
  }, [currentPage, fetchInventory]);

  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => {
      setCurrentPage(1);
    }, 400);
    return () => clearTimeout(debounceRef.current);
  }, [searchTerm]);

  const getItemName = (item) => item.product?.product_name || item.item?.name || item.item_name || 'N/A';
  const getItemCode = (item) => item.product?.product_code || item.item?.code || item.sku || '';
  const getItemQty = (item) => item.quantity || item.quantity_on_hand || 0;
  const getItemReorder = (item) => item.reorder_level || item.item?.reorder_level || 5;
  const getItemSafety = (item) => Math.round(getItemReorder(item) * 0.25) || 3;
  const getItemMax = (item) => (getItemReorder(item) * 4) || 100;
  const getBranchName = (item) => item.location?.location_name || item.branch?.name || item.warehouse_location || 'N/A';
  const getBranchCity = (item) => {
    const name = getBranchName(item).toLowerCase();
    if (name.includes('davao')) return 'DAVAO CITY';
    if (name.includes('zamboanga')) return 'ZAMBOANGA CITY';
    if (name.includes('cagayan') || name.includes('cdo')) return 'CAGAYAN DE ORO CITY';
    return 'CDO CITY';
  };

  const getItemStatus = (item) => {
    const qty = getItemQty(item);
    const reorder = getItemReorder(item);
    if (qty <= 0) return { label: 'OUT OF STOCK', className: 'inv-badge-outofstock' };
    if (qty <= reorder * 0.5) return { label: 'CRITICAL LOW', className: 'inv-badge-critical' };
    if (qty <= reorder) return { label: 'REORDER POINT', className: 'inv-badge-reorder' };
    if (qty >= getItemMax(item)) return { label: 'OVERSTOCK RISK', className: 'inv-badge-overstock' };
    return { label: 'OPTIMAL LEVEL', className: 'inv-badge-optimal' };
  };

  const totalItems = pagination.total || 0;
  const totalPages = pagination.last_page || 1;
  const criticalCount = inventory.filter(i => getItemQty(i) <= getItemReorder(i) * 0.5 && getItemQty(i) > 0).length;
  const reorderCount = inventory.filter(i => {
    const qty = getItemQty(i);
    const reorder = getItemReorder(i);
    return qty > reorder * 0.5 && qty <= reorder;
  }).length;
  const overstockCount = inventory.filter(i => getItemQty(i) >= getItemMax(i)).length;

  return (
    <AdminLayout>
      <div className="inv-page">
        {/* Page Header */}
        <div className="inv-page-header">
          <div>
            <h1 className="inv-page-title">Inventory Monitoring</h1>
            <p className="inv-page-subtitle">Real-time inventory levels and safety stock tracking across all Mindanao locations</p>
          </div>
        </div>

        {/* Search + Export */}
        <div className="inv-search-row">
          <div className="inv-search-input">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="16" height="16">
              <circle cx="11" cy="11" r="8"></circle>
              <path d="m21 21-4.35-4.35"></path>
            </svg>
            <input
              type="text"
              placeholder="Search by name, SKU, or barcode..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
            />
          </div>
          <button className="inv-btn-export">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
              <polyline points="7 10 12 15 17 10"></polyline>
              <line x1="12" y1="15" x2="12" y2="3"></line>
            </svg>
            EXPORT
          </button>
        </div>

        {/* Stats Cards */}
        <div className="inv-stats-row">
          <div className="inv-stat-card">
            <div className="inv-stat-icon inv-stat-icon-green">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#059669" strokeWidth="2">
                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
              </svg>
            </div>
            <div className="inv-stat-info">
              <span className="inv-stat-label">TOTAL SKUS</span>
              <span className="inv-stat-number">{totalItems}</span>
            </div>
          </div>
          <div className="inv-stat-card">
            <div className="inv-stat-icon inv-stat-icon-red">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#dc2626" strokeWidth="2">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                <line x1="12" y1="9" x2="12" y2="13"></line>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
              </svg>
            </div>
            <div className="inv-stat-info">
              <span className="inv-stat-label">CRITICAL STOCK</span>
              <span className="inv-stat-number inv-stat-red">{criticalCount}</span>
            </div>
          </div>
          <div className="inv-stat-card">
            <div className="inv-stat-icon inv-stat-icon-orange">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" strokeWidth="2">
                <polyline points="23 4 23 10 17 10"></polyline>
                <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
              </svg>
            </div>
            <div className="inv-stat-info">
              <span className="inv-stat-label">REORDER LEVEL</span>
              <span className="inv-stat-number">{reorderCount}</span>
            </div>
          </div>
          <div className="inv-stat-card">
            <div className="inv-stat-icon inv-stat-icon-emerald">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10b981" strokeWidth="2">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                <polyline points="22 4 12 14.01 9 11.01"></polyline>
              </svg>
            </div>
            <div className="inv-stat-info">
              <span className="inv-stat-label">OVERSTOCK RISK</span>
              <span className="inv-stat-number">{overstockCount}</span>
            </div>
          </div>
        </div>

        {loading && <div className="inv-loading">Loading inventory...</div>}
        {error && <div className="inv-error">Error: {error}</div>}

        {/* Inventory Console Table */}
        <div className="inv-table-card">
          <div className="inv-table-top">
            <div>
              <h3 className="inv-table-title">Inventory Monitoring Console</h3>
              <p className="inv-table-subtitle">Monitoring {totalItems} SKUs across branches</p>
            </div>
          </div>

          <div className="inv-table-wrapper">
            <table className="inv-table">
              <thead>
                <tr>
                  <th>PRODUCT & BRANCH</th>
                  <th>CURRENT</th>
                  <th>SAFETY</th>
                  <th>REORDER</th>
                  <th>MAXIMUM</th>
                  <th>STATUS</th>
                  <th>ACTIONS</th>
                </tr>
              </thead>
              <tbody>
                {inventory.length === 0 ? (
                  <tr>
                    <td colSpan="7" style={{ textAlign: 'center', color: '#9ca3af', padding: '40px' }}>
                      {loading ? 'Loading...' : 'No items found'}
                    </td>
                  </tr>
                ) : (
                  inventory.map((item) => {
                    const status = getItemStatus(item);
                    return (
                      <tr key={item.id}>
                        <td>
                          <div className="inv-product-cell">
                            <span className="inv-product-name">{getItemName(item)}</span>
                            <div className="inv-product-meta">
                              <span className="inv-product-branch">{getBranchCity(item)}</span>
                              <span className="inv-product-code">{getItemCode(item)}</span>
                            </div>
                          </div>
                        </td>
                        <td className="inv-cell-qty"><strong>{getItemQty(item)}</strong></td>
                        <td className="inv-cell-num">{getItemSafety(item)}</td>
                        <td className="inv-cell-num">{getItemReorder(item)}</td>
                        <td className="inv-cell-num">{getItemMax(item)}</td>
                        <td>
                          <span className={`inv-status-badge ${status.className}`}>
                            {status.label}
                          </span>
                        </td>
                        <td>
                          <div className="inv-cell-actions">
                            <button className="inv-action-link" onClick={() => setViewingItem(item)}>View</button>
                            <button className="inv-action-link inv-action-edit" onClick={() => setEditingItem(item)}>Edit</button>
                          </div>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>

          {totalPages > 1 && (
            <div className="inv-pagination">
              <button className="inv-page-btn" disabled={currentPage === 1} onClick={() => setCurrentPage(currentPage - 1)}>Previous</button>
              {Array.from({ length: totalPages }, (_, i) => i + 1).map((page) => (
                <button key={page} className={`inv-page-btn ${currentPage === page ? 'inv-page-btn-active' : ''}`} onClick={() => setCurrentPage(page)}>{page}</button>
              ))}
              <button className="inv-page-btn" disabled={currentPage === totalPages} onClick={() => setCurrentPage(currentPage + 1)}>Next</button>
            </div>
          )}
        </div>
      </div>

      {/* View Modal */}
      {viewingItem && (
        <Modal isOpen={!!viewingItem} onClose={() => setViewingItem(null)} title="Inventory Details">
          <div className="inv-view-details">
            <div className="inv-view-row">
              <div className="inv-view-field"><label>Item Name</label><p>{getItemName(viewingItem)}</p></div>
              <div className="inv-view-field"><label>SKU/Code</label><p>{getItemCode(viewingItem)}</p></div>
            </div>
            <div className="inv-view-row">
              <div className="inv-view-field"><label>Branch</label><p>{getBranchName(viewingItem)}</p></div>
              <div className="inv-view-field"><label>Current Qty</label><p>{getItemQty(viewingItem)}</p></div>
            </div>
            <div className="inv-view-row">
              <div className="inv-view-field"><label>Reorder Level</label><p>{getItemReorder(viewingItem)}</p></div>
              <div className="inv-view-field"><label>Status</label><p><span className={`inv-status-badge ${getItemStatus(viewingItem).className}`}>{getItemStatus(viewingItem).label}</span></p></div>
            </div>
            <div className="inv-modal-footer">
              <button className="inv-modal-btn-cancel" onClick={() => setViewingItem(null)}>Close</button>
            </div>
          </div>
        </Modal>
      )}

      {/* Edit placeholder */}
      {editingItem && (
        <Modal isOpen={!!editingItem} onClose={() => setEditingItem(null)} title="Edit Inventory">
          <div className="inv-view-details">
            <p style={{ color: '#6b7280', fontSize: 13 }}>Edit functionality for <strong>{getItemName(editingItem)}</strong> coming soon.</p>
            <div className="inv-modal-footer">
              <button className="inv-modal-btn-cancel" onClick={() => setEditingItem(null)}>Close</button>
            </div>
          </div>
        </Modal>
      )}
    </AdminLayout>
  );
};

export default InventoryManagement;
