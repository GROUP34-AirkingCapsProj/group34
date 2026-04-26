import React, { useMemo, useState, useEffect, useCallback } from 'react';
import AdminLayout from '../components/AdminLayout';
import Modal from '../components/Modal';
import { locationsAPI } from '../services/api';
import { toast } from '../utils/toast';
import '../styles/dashboard_air.css';
import '../styles/warehouse_management.css';

const WarehouseManagement = () => {
  const [warehouses, setWarehouses] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [currentPage, setCurrentPage] = useState(1);
  const [pagination, setPagination] = useState({ total: 0, last_page: 1, per_page: 12 });
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingWarehouse, setEditingWarehouse] = useState(null);
  const [viewMode, setViewMode] = useState('cards'); // 'cards' | 'list'
  const [searchTerm, setSearchTerm] = useState('');

  const [formData, setFormData] = useState({
    name: '', code: '', type: '', location: '', capacity: '', contact_number: '', manager: '', opening_date: '', status: 'Active'
  });

  const fetchData = useCallback(async (page = 1, term = '') => {
    try {
      setLoading(true);
      const res = await locationsAPI.getAll({
        page,
        per_page: pagination.per_page,
        location_type: 'warehouse',
        ...(term ? { search: term } : {}),
      });
      const normalized = Array.isArray(res.data) ? res.data.map((loc) => ({
        ...loc,
        name: loc.location_name || loc.name,
        type: loc.location_type || 'warehouse',
        location: loc.address || '',
        status: loc.status?.status_name || 'Active',
      })) : [];
      setWarehouses(normalized);
      setPagination(res.pagination || { total: 0, last_page: 1, per_page: pagination.per_page });
      setError(null);
    } catch (err) {
      setError(err.message);
      console.error('Failed to fetch data:', err);
    } finally {
      setLoading(false);
    }
  }, [pagination.per_page]);

  useEffect(() => {
    fetchData(1, '');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    fetchData(currentPage, searchTerm);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [currentPage]);

  useEffect(() => {
    const t = setTimeout(() => {
      setCurrentPage(1);
      fetchData(1, searchTerm);
    }, 350);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchTerm]);

  const handleAdd = () => {
    setEditingWarehouse(null);
    setFormData({ name: '', code: '', type: '', location: '', capacity: '', contact_number: '', manager: '', opening_date: '', status: 'Active' });
    setIsModalOpen(true);
  };

  const handleEdit = (warehouse) => {
    setEditingWarehouse(warehouse);
    setFormData({
      name: warehouse.location_name || warehouse.name || '',
      code: warehouse.code || '',
      type: warehouse.location_type || warehouse.type || 'warehouse',
      location: warehouse.address || warehouse.location || '',
      capacity: warehouse.capacity || '',
      contact_number: warehouse.contact_number || '',
      manager: warehouse.manager || '',
      opening_date: warehouse.opening_date || '',
      status: warehouse.status || 'Active'
    });
    setIsModalOpen(true);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    try {
      const payload = {
        branch_id: null,
        location_name: formData.name,
        location_type: 'warehouse',
        address: formData.location,
        city: null,
        province: null,
        region: null,
      };
      if (editingWarehouse) {
        await locationsAPI.update(editingWarehouse.id, payload);
        toast.success('Warehouse updated successfully!');
      } else {
        await locationsAPI.create(payload);
        toast.success('Warehouse added successfully!');
      }
      setIsModalOpen(false);
      fetchData(currentPage, searchTerm);
    } catch (err) {
      toast.error('Error: ' + err.message);
    }
  };

  const getUtilizationPercent = (warehouse) => {
    const used = warehouse.used || warehouse.current_stock || 0;
    const capacity = warehouse.capacity || 1;
    return Math.round((used / capacity) * 100);
  };

  const getBarColor = (percent) => {
    if (percent >= 75) return '#DC143C';
    if (percent >= 60) return '#f59e0b';
    return '#22c55e';
  };

  const totalPages = pagination.last_page || 1;

  const pageButtons = useMemo(() => {
    const last = totalPages;
    const cur = currentPage;
    const windowSize = 5;
    const half = Math.floor(windowSize / 2);
    let start = Math.max(1, cur - half);
    let end = Math.min(last, start + windowSize - 1);
    start = Math.max(1, end - windowSize + 1);
    const pages = [];
    for (let p = start; p <= end; p++) pages.push(p);
    return pages;
  }, [currentPage, totalPages]);

  return (
    <AdminLayout searchPlaceholder="Search warehouses..." onSearch={() => {}}>
      <div className="wh-page-header">
        <div className="wh-page-header-left">
          <h1>Warehouse Management</h1>
          <p>Manage warehouse records and assignments ({pagination.total || 0} total)</p>
        </div>
        <div className="wh-header-actions">
          <div className="wh-view-toggle" role="group" aria-label="View mode">
            <button
              type="button"
              className={`wh-toggle-btn ${viewMode === 'cards' ? 'active' : ''}`}
              onClick={() => setViewMode('cards')}
            >
              Cards
            </button>
            <button
              type="button"
              className={`wh-toggle-btn ${viewMode === 'list' ? 'active' : ''}`}
              onClick={() => setViewMode('list')}
            >
              List
            </button>
          </div>
          <button className="btn-create-warehouse" onClick={handleAdd}>+ Create Warehouse</button>
        </div>
      </div>

      <div className="wh-toolbar">
        <div className="wh-search">
          <input
            type="text"
            placeholder="Search warehouses (name/code)..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
          />
        </div>
      </div>

      {loading && <div className="loading-message">Loading warehouses...</div>}
      {error && <div className="error-message">Error: {error}</div>}

      {viewMode === 'cards' ? (
        <div className="wh-cards-grid">
          {warehouses.map(warehouse => {
            const percent = getUtilizationPercent(warehouse);
            const barColor = getBarColor(percent);
            const used = warehouse.used || warehouse.current_stock || 0;
            return (
              <div className="wh-card" key={warehouse.id}>
                <div className="wh-card-header">
                  <h3>{warehouse.name}</h3>
                  <span className="wh-code">{warehouse.code}</span>
                </div>
                <div className="wh-card-body">
                  <div className="wh-capacity-section">
                    <div className="wh-capacity-header">
                      <span className="wh-label">Capacity Utilization</span>
                      <span className="wh-percent">{percent}%</span>
                    </div>
                    <div className="wh-progress-bar">
                      <div className="wh-progress-fill" style={{ width: `${percent}%`, backgroundColor: barColor }}></div>
                    </div>
                    <div className="wh-capacity-info">
                      <span>{Number(used).toLocaleString()} / {Number(warehouse.capacity).toLocaleString()}</span>
                      <span>units</span>
                    </div>
                  </div>
                </div>
                <div className="wh-card-footer">
                  <span className={warehouse.status === 'Active' ? 'wh-status-active' : 'wh-status-inactive'}>{warehouse.status}</span>
                  <button className="wh-btn-edit-link" onClick={() => handleEdit(warehouse)}>Edit</button>
                </div>
              </div>
            );
          })}
        </div>
      ) : (
        <div className="wh-list-card">
          <div className="wh-table-wrapper">
            <table className="wh-table">
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Warehouse</th>
                  <th>Type</th>
                  <th>Location</th>
                  <th>Contact</th>
                  <th>Capacity</th>
                  <th>Status</th>
                  <th style={{ width: 90 }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {warehouses.length === 0 ? (
                  <tr>
                    <td colSpan={8} style={{ textAlign: 'center', color: '#9ca3af', padding: 24 }}>
                      {loading ? 'Loading...' : 'No warehouses found'}
                    </td>
                  </tr>
                ) : (
                  warehouses.map((w) => {
                    const percent = getUtilizationPercent(w);
                    const barColor = getBarColor(percent);
                    const used = w.used || w.current_stock || 0;
                    return (
                      <tr key={w.id}>
                        <td className="wh-td-muted">{w.code}</td>
                        <td>
                          <div className="wh-td-title">{w.name}</div>
                          {w.manager && <div className="wh-td-sub">{w.manager}</div>}
                        </td>
                        <td>{w.type || '—'}</td>
                        <td className="wh-td-ellipsis" title={w.location || ''}>{w.location || '—'}</td>
                        <td>{w.contact_number || '—'}</td>
                        <td>
                          <div className="wh-td-capacity">
                            <div className="wh-td-capacity-bar">
                              <div style={{ width: `${percent}%`, backgroundColor: barColor, height: '100%', borderRadius: 3 }} />
                            </div>
                            <span className="wh-td-capacity-label">{percent}% · {Number(used).toLocaleString()}/{Number(w.capacity || 0).toLocaleString()}</span>
                          </div>
                        </td>
                        <td>
                          <span className={w.status === 'Active' ? 'wh-status-active' : 'wh-status-inactive'}>
                            {w.status || 'Active'}
                          </span>
                        </td>
                        <td>
                          <button className="wh-btn-edit-link" onClick={() => handleEdit(w)}>Edit</button>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Pagination */}
      {totalPages > 1 && (
        <div className="wh-pagination">
          <button
            type="button"
            className="wh-page-btn"
            disabled={currentPage === 1 || loading}
            onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
          >
            Previous
          </button>
          {pageButtons.map((p) => (
            <button
              key={p}
              type="button"
              className={`wh-page-btn ${p === currentPage ? 'active' : ''}`}
              disabled={loading}
              onClick={() => setCurrentPage(p)}
            >
              {p}
            </button>
          ))}
          <button
            type="button"
            className="wh-page-btn"
            disabled={currentPage === totalPages || loading}
            onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
          >
            Next
          </button>
          <div className="wh-page-meta">
            {pagination.total ? `${pagination.total} total` : ''}
          </div>
        </div>
      )}

      <Modal isOpen={isModalOpen} onClose={() => setIsModalOpen(false)} title={editingWarehouse ? 'Edit Warehouse' : 'Add New Warehouse'}>
        <form onSubmit={handleSubmit}>
          <div className="form-row">
            <div className="form-group">
              <label>Warehouse Name *</label>
              <input type="text" value={formData.name} onChange={(e) => setFormData({...formData, name: e.target.value})} required />
            </div>
            <div className="form-group">
              <label>Warehouse Code *</label>
              <input type="text" value={formData.code} onChange={(e) => setFormData({...formData, code: e.target.value})} required />
            </div>
          </div>
          <div className="form-row">
            <div className="form-group">
              <label>Type *</label>
              <select value={formData.type} onChange={(e) => setFormData({...formData, type: e.target.value})} required>
                <option value="">Select Type</option>
                <option value="Main Warehouse">Main Warehouse</option>
                <option value="Storage Warehouse">Storage Warehouse</option>
                <option value="Distribution Center">Distribution Center</option>
              </select>
            </div>
          </div>
          <div className="form-group">
            <label>Location/Address *</label>
            <input type="text" value={formData.location} onChange={(e) => setFormData({...formData, location: e.target.value})} required />
          </div>
          <div className="form-row">
            <div className="form-group">
              <label>Total Capacity (units) *</label>
              <input type="number" value={formData.capacity} onChange={(e) => setFormData({...formData, capacity: e.target.value})} required />
            </div>
            <div className="form-group">
              <label>Contact Number</label>
              <input type="text" value={formData.contact_number} onChange={(e) => setFormData({...formData, contact_number: e.target.value})} />
            </div>
          </div>
          <div className="form-row">
            <div className="form-group">
              <label>Manager/Supervisor</label>
              <input type="text" value={formData.manager} onChange={(e) => setFormData({...formData, manager: e.target.value})} />
            </div>
            <div className="form-group">
              <label>Opening Date</label>
              <input type="date" value={formData.opening_date} onChange={(e) => setFormData({...formData, opening_date: e.target.value})} />
            </div>
          </div>
          <div className="form-group">
            <label>Status *</label>
            <select value={formData.status} onChange={(e) => setFormData({...formData, status: e.target.value})} required>
              <option value="Active">Active</option>
              <option value="Inactive">Inactive</option>
            </select>
          </div>
          <div className="modal-footer">
            <button type="button" className="btn-cancel" onClick={() => setIsModalOpen(false)}>Cancel</button>
            <button type="submit" className="btn-save">Save Warehouse</button>
          </div>
        </form>
      </Modal>
    </AdminLayout>
  );
};

export default WarehouseManagement;
