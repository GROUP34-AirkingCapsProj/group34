import React, { useMemo, useState, useEffect, useCallback } from 'react';
import AdminLayout from '../components/AdminLayout';
import Modal from '../components/Modal';
import ArchiveButton from '../components/ArchiveButton';
import { locationsAPI, statusAPI } from '../services/api';
import { toast } from '../utils/toast';
import '../styles/dashboard_air.css';
import '../styles/branch_management.css';
import '../styles/archive_management.css';
import DateInput from '../components/DateInput';

const REGION_CITIES = {
  'NCR': [
    'Caloocan', 'Las Piñas', 'Makati', 'Malabon', 'Mandaluyong', 'Manila',
    'Marikina', 'Muntinlupa', 'Navotas', 'Parañaque', 'Pasay', 'Pasig',
    'Pateros', 'Quezon City', 'San Juan', 'Taguig', 'Valenzuela',
  ],
  'CAR': ['Baguio City', 'Bangued', 'Bontoc', 'Kabugao', 'La Trinidad', 'Lagawe', 'Tabuk'],
  'Region I': ['Alaminos', 'Batac', 'Candon', 'Dagupan', 'Laoag', 'San Carlos', 'San Fernando', 'Urdaneta', 'Vigan'],
  'Region II': ['Bayombong', 'Cauayan', 'Ilagan', 'Santiago', 'Tuguegarao'],
  'Region III': ['Angeles', 'Balanga', 'Cabanatuan', 'Gapan', 'Malolos', 'Meycauayan', 'Muñoz', 'Olongapo', 'Palayan', 'San Fernando', 'San Jose', 'San Jose del Monte', 'Tarlac City'],
  'Region IV-A': ['Antipolo', 'Bacoor', 'Batangas City', 'Calamba', 'Cavite City', 'Dasmariñas', 'General Trias', 'Imus', 'Lipa', 'Lucena', 'San Pedro', 'Santa Rosa', 'Tagaytay', 'Tayabas', 'Trece Martires'],
  'Region IV-B': ['Boac', 'Calapan', 'Mamburao', 'Puerto Princesa', 'Romblon'],
  'Region V': ['Iriga', 'Legazpi', 'Ligao', 'Masbate City', 'Naga', 'Sorsogon City', 'Tabaco'],
  'Region VI': ['Bacolod', 'Cadiz', 'Escalante', 'Iloilo City', 'Kalibo', 'La Carlota', 'Roxas', 'Sagay', 'San Carlos', 'San Jose de Buenavista', 'Silay', 'Talisay', 'Victorias'],
  'Region VII': ['Bais', 'Bayawan', 'Bogo', 'Canlaon', 'Carcar', 'Cebu City', 'Danao', 'Dumaguete', 'Guihulngan', 'Lapu-Lapu', 'Mandaue', 'Naga', 'Tagbilaran', 'Talisay', 'Tanjay', 'Toledo'],
  'Region VIII': ['Baybay', 'Borongan', 'Calbayog', 'Catarman', 'Catbalogan', 'Maasin', 'Ormoc', 'Tacloban'],
  'Region IX': ['Dapitan', 'Dipolog', 'Isabela', 'Pagadian', 'Zamboanga City'],
  'Region X': ['Cagayan de Oro', 'El Salvador', 'Gingoog', 'Iligan', 'Malaybalay', 'Oroquieta', 'Ozamiz', 'Tangub', 'Valencia'],
  'Region XI': ['Davao City', 'Digos', 'Island Garden City of Samal', 'Mati', 'Panabo', 'Tagum'],
  'Region XII': ['Cotabato City', 'General Santos', 'Kidapawan', 'Koronadal', 'Tacurong'],
  'Region XIII': ['Bayugan', 'Bislig', 'Butuan', 'Cabadbaran', 'Surigao City', 'Tandag'],
  'NIR': ['Bacolod', 'Bais', 'Bayawan', 'Cadiz', 'Canlaon', 'Dumaguete', 'Escalante', 'Guihulngan', 'La Carlota', 'Sagay', 'San Carlos', 'Silay', 'Talisay', 'Tanjay', 'Victorias'],
  'BARMM': ['Cotabato City', 'Isabela', 'Lamitan', 'Marawi'],
};

const LOCATION_TYPES = [
  { value: 'warehouse', label: 'Warehouse' },
  { value: 'showroom', label: 'Showroom' },
  { value: 'branch_office', label: 'Branch Office' },
];

const getDisplayName = (loc) => loc.location_name || loc.name || '(Unnamed)';
const getStatus = (loc) => loc.status?.status_name || (loc.status_id ? `Status ${loc.status_id}` : 'Active');

const LocationManagement = () => {
  const [locations, setLocations] = useState([]);
  const [allLocations, setAllLocations] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingLocation, setEditingLocation] = useState(null);
  const [viewMode, setViewMode] = useState('cards');
  const [searchTerm, setSearchTerm] = useState('');
  const [typeFilter, setTypeFilter] = useState('');
  const [currentPage, setCurrentPage] = useState(1);
  const [pagination, setPagination] = useState({ total: 0, last_page: 1, per_page: 8 });
  const [statusIds, setStatusIds] = useState({ active: null, inactive: null });

  const emptyForm = {
    location_code: '',
    location_name: '',
    location_type: 'warehouse',
    parent_location_id: '',
    region: '',
    city: '',
    province: '',
    address: '',
    contact_number: '',
    email: '',
    manager_name: '',
    capacity: '',
    opening_date: '',
  };
  const [formData, setFormData] = useState(emptyForm);

  const fetchLocations = async (page = 1, term = searchTerm, type = typeFilter) => {
    try {
      setLoading(true);
      const params = {
        page,
        per_page: pagination.per_page,
        ...(term ? { search: term } : {}),
        ...(type ? { location_type: type } : {}),
      };
      const res = await locationsAPI.getAll(params);
      setLocations(Array.isArray(res?.data) ? res.data : []);
      setPagination(res?.pagination || { total: 0, last_page: 1, per_page: pagination.per_page });
      setError(null);
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  const fetchAllLocations = useCallback(async () => {
    try {
      const res = await locationsAPI.getAll({ per_page: 500 });
      setAllLocations(Array.isArray(res?.data) ? res.data : []);
    } catch (_) {
      setAllLocations([]);
    }
  }, []);

  useEffect(() => {
    fetchLocations(1, '', '');
    fetchAllLocations();
    statusAPI.getAll().then((res) => {
      const list = Array.isArray(res?.data) ? res.data : [];
      const active = list.find((s) => s.status_name?.toLowerCase() === 'active');
      const inactive = list.find((s) => s.status_name?.toLowerCase() === 'inactive');
      setStatusIds({ active: active?.status_id ?? null, inactive: inactive?.status_id ?? null });
    }).catch(() => {});
  }, []); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { fetchLocations(currentPage, searchTerm, typeFilter); }, [currentPage]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    const t = setTimeout(() => { setCurrentPage(1); fetchLocations(1, searchTerm, typeFilter); }, 350);
    return () => clearTimeout(t);
  }, [searchTerm, typeFilter]); // eslint-disable-line react-hooks/exhaustive-deps

  const handleRegionChange = (e) =>
    setFormData((prev) => ({ ...prev, region: e.target.value, city: '' }));

  const handleAdd = () => {
    setEditingLocation(null);
    setFormData(emptyForm);
    setIsModalOpen(true);
  };

  const handleEdit = (loc) => {
    setEditingLocation(loc);
    setFormData({
      location_code: loc.location_code || '',
      location_name: loc.location_name || loc.name || '',
      location_type: loc.location_type || 'warehouse',
      parent_location_id: loc.parent_location_id || '',
      region: loc.region || '',
      city: loc.city || '',
      province: loc.province || '',
      address: loc.address || '',
      contact_number: loc.contact_number || '',
      email: loc.email || '',
      manager_name: loc.manager_name || '',
      capacity: loc.capacity ?? '',
      opening_date: loc.opening_date || '',
    });
    setIsModalOpen(true);
  };

  const isLocActive = (loc) => (loc.status?.status_name || 'Active').toLowerCase() === 'active';
  const getStatusClass = (loc) => isLocActive(loc) ? 'status-active' : 'status-inactive';

  const handleArchive = async (loc) => {
    try {
      await locationsAPI.delete(loc.location_id ?? loc.id);
      toast.success('Location archived.');
      fetchLocations(currentPage, searchTerm, typeFilter);
    } catch (err) { toast.error(err.message || 'Failed to archive.'); }
  };

  const handleToggleStatus = async (loc) => {
    const active = isLocActive(loc);
    const newId = active ? statusIds.inactive : statusIds.active;
    if (!newId) { toast.error('Status options not loaded. Try refreshing.'); return; }
    try {
      await locationsAPI.update(loc.location_id ?? loc.id, { status_id: newId });
      toast.success(`Location ${active ? 'deactivated' : 'activated'} successfully!`);
      fetchLocations(currentPage, searchTerm, typeFilter);
    } catch (err) {
      toast.error('Error: ' + err.message);
    }
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    try {
      const payload = {
        ...formData,
        parent_location_id: formData.parent_location_id ? Number(formData.parent_location_id) : null,
        capacity: formData.capacity ? Number(formData.capacity) : null,
      };
      if (editingLocation) {
        await locationsAPI.update(editingLocation.location_id ?? editingLocation.id, payload);
        toast.success('Location updated successfully!');
      } else {
        await locationsAPI.create(payload);
        toast.success('Location added successfully!');
      }
      setIsModalOpen(false);
      fetchLocations(currentPage, searchTerm, typeFilter);
    } catch (err) {
      toast.error('Error: ' + err.message);
    }
  };

  const totalPages = pagination.last_page || 1;
  const pageButtons = useMemo(() => {
    const half = 2;
    let start = Math.max(1, currentPage - half);
    let end = Math.min(totalPages, start + 4);
    start = Math.max(1, end - 4);
    const pages = [];
    for (let p = start; p <= end; p++) pages.push(p);
    return pages;
  }, [currentPage, totalPages]);

  return (
    <AdminLayout>
     <div className="ak-page">
      <header className="ak-header">
        <div className="ak-header-row">
          <div className="branch-page-header-left">
            <p className="ak-eyebrow">Network · Locations</p>
            <h1 className="ak-title">Location Management</h1>
            <p className="ak-sub">Manage all physical locations (warehouses, showrooms, branch offices).</p>
          </div>
          <div className="ak-header-aside ak-actions branch-header-actions">
            <div className="branch-view-toggle" role="group" aria-label="View mode">
              <button type="button" className={`branch-toggle-btn ${viewMode === 'cards' ? 'active' : ''}`} onClick={() => setViewMode('cards')}>Cards</button>
              <button type="button" className={`branch-toggle-btn ${viewMode === 'list' ? 'active' : ''}`} onClick={() => setViewMode('list')}>List</button>
            </div>
            <button className="ak-btn ak-btn--primary btn-create-branch" onClick={handleAdd}>+ Add Location</button>
          </div>
        </div>
      </header>

      <div className="branch-toolbar">
        <div className="branch-search">
          <input
            type="text"
            placeholder="Search locations..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
          />
        </div>
        <div className="branch-search">
          <select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)}>
            <option value="">All Types</option>
            {LOCATION_TYPES.map((t) => (
              <option key={t.value} value={t.value}>{t.label}</option>
            ))}
          </select>
        </div>
      </div>

      {loading && <div className="loading-message">Loading locations...</div>}
      {error && <div className="error-message">Error: {error}</div>}

      {viewMode === 'cards' ? (
        <div className="branch-cards-grid">
          {locations.map((loc) => (
            <div className="branch-card" key={loc.location_id ?? loc.id}>
              <div className="branch-card-header">
                <h3>{getDisplayName(loc)}</h3>
                <span className="branch-code">{loc.location_code || '—'}</span>
              </div>
              <div className="branch-card-body">
                <div className="branch-detail">
                  <span className="branch-label">Type</span>
                  <span className="branch-value">{loc.location_type?.replace('_', ' ') || '—'}</span>
                </div>
                {(loc.region || loc.city) && (
                  <div className="branch-detail">
                    <span className="branch-label">City / Region</span>
                    <span className="branch-value">{[loc.city, loc.region].filter(Boolean).join(', ')}</span>
                  </div>
                )}
                <div className="branch-detail">
                  <span className="branch-label">Address</span>
                  <span className="branch-value">{loc.address || '—'}</span>
                </div>
                {loc.manager_name && (
                  <div className="branch-detail">
                    <span className="branch-label">Manager</span>
                    <span className="branch-value">{loc.manager_name}</span>
                  </div>
                )}
                {loc.capacity != null && (
                  <div className="branch-detail">
                    <span className="branch-label">Capacity</span>
                    <span className="branch-value">{Number(loc.capacity).toLocaleString()}</span>
                  </div>
                )}
              </div>
              <div className="branch-card-footer">
                <span className={getStatusClass(loc)}>{getStatus(loc)}</span>
                <div className="branch-card-actions">
                  <button className="btn-edit-link" onClick={() => handleEdit(loc)}>Edit</button>
                  <button
                    className={isLocActive(loc) ? 'btn-deactivate-link' : 'btn-activate-link'}
                    onClick={() => handleToggleStatus(loc)}
                  >
                    {isLocActive(loc) ? 'Deactivate' : 'Activate'}
                  </button>
                  <ArchiveButton onArchive={() => handleArchive(loc)} />
                </div>
              </div>
            </div>
          ))}
          {!loading && locations.length === 0 && (
            <p style={{ color: '#9ca3af', gridColumn: '1/-1', textAlign: 'center', padding: 24 }}>No locations found.</p>
          )}
        </div>
      ) : (
        <div className="branch-list-card">
          <div className="branch-table-wrapper">
            <table className="branch-table">
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Name</th>
                  <th>Type</th>
                  <th>City</th>
                  <th>Region</th>
                  <th>Address</th>
                  <th>Manager</th>
                  <th>Status</th>
                  <th style={{ width: 160 }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {locations.length === 0 ? (
                  <tr><td colSpan={9} style={{ textAlign: 'center', color: '#9ca3af', padding: 24 }}>{loading ? 'Loading...' : 'No locations found'}</td></tr>
                ) : (
                  locations.map((loc) => (
                    <tr key={loc.location_id ?? loc.id}>
                      <td className="branch-td-muted">{loc.location_code || '—'}</td>
                      <td><div className="branch-td-title">{getDisplayName(loc)}</div></td>
                      <td className="branch-td-muted">{loc.location_type?.replace('_', ' ') || '—'}</td>
                      <td>{loc.city || '—'}</td>
                      <td>{loc.region || '—'}</td>
                      <td className="branch-td-ellipsis" title={loc.address || ''}>{loc.address || '—'}</td>
                      <td>{loc.manager_name || '—'}</td>
                      <td><span className={getStatusClass(loc)}>{getStatus(loc)}</span></td>
                      <td>
                        <div className="branch-actions-cell">
                          <button className="btn-edit-link" onClick={() => handleEdit(loc)}>Edit</button>
                          <button
                            className={isLocActive(loc) ? 'btn-deactivate-link' : 'btn-activate-link'}
                            onClick={() => handleToggleStatus(loc)}
                          >
                            {isLocActive(loc) ? 'Deactivate' : 'Activate'}
                          </button>
                          <ArchiveButton onArchive={() => handleArchive(loc)} />
                        </div>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {totalPages > 1 && (
        <div className="branch-pagination">
          <button type="button" className="branch-page-btn" disabled={currentPage === 1 || loading} onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}>Previous</button>
          {pageButtons.map((p) => (
            <button key={p} type="button" className={`branch-page-btn ${p === currentPage ? 'active' : ''}`} disabled={loading} onClick={() => setCurrentPage(p)}>{p}</button>
          ))}
          <button type="button" className="branch-page-btn" disabled={currentPage === totalPages || loading} onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}>Next</button>
          <div className="branch-page-meta">{pagination.total ? `${pagination.total} total` : ''}</div>
        </div>
      )}

      <Modal isOpen={isModalOpen} onClose={() => setIsModalOpen(false)} title={editingLocation ? 'Edit Location' : 'Add New Location'} closeOnOverlayClick={false}>
        <form onSubmit={handleSubmit} className="branch-register-form">
          <section className="form-section" aria-labelledby="loc-id-heading">
            <h3 id="loc-id-heading" className="form-section-title">Location details</h3>
            <div className="form-row">
              <div className="form-group">
                <label htmlFor="loc-code">Location code *</label>
                <input id="loc-code" type="text" value={formData.location_code} onChange={(e) => setFormData({ ...formData, location_code: e.target.value })} placeholder="e.g. WH-CDO-001" required autoFocus={!editingLocation} />
              </div>
              <div className="form-group">
                <label htmlFor="loc-name">Location name *</label>
                <input id="loc-name" type="text" value={formData.location_name} onChange={(e) => setFormData({ ...formData, location_name: e.target.value })} placeholder="e.g. CDO Main Showroom" required />
              </div>
            </div>
            <div className="form-row">
              <div className="form-group">
                <label htmlFor="loc-type">Location type *</label>
                <select id="loc-type" value={formData.location_type} onChange={(e) => setFormData({ ...formData, location_type: e.target.value })} required>
                  {LOCATION_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                </select>
              </div>
              <div className="form-group">
                <label htmlFor="loc-parent">Parent location</label>
                <select id="loc-parent" value={formData.parent_location_id} onChange={(e) => setFormData({ ...formData, parent_location_id: e.target.value })}>
                  <option value="">None (top-level)</option>
                  {allLocations
                    .filter((l) => (l.location_id ?? l.id) !== (editingLocation?.location_id ?? editingLocation?.id))
                    .map((l) => (
                      <option key={l.location_id ?? l.id} value={l.location_id ?? l.id}>{getDisplayName(l)}</option>
                    ))}
                </select>
              </div>
            </div>
            <div className="form-row">
              <div className="form-group">
                <label htmlFor="loc-region">Region *</label>
                <select id="loc-region" value={formData.region} onChange={handleRegionChange} required>
                  <option value="">Select region</option>
                  {Object.keys(REGION_CITIES).map((r) => <option key={r} value={r}>{r}</option>)}
                </select>
              </div>
              <div className="form-group">
                <label htmlFor="loc-city">City *</label>
                <select id="loc-city" value={formData.city} onChange={(e) => setFormData({ ...formData, city: e.target.value })} required disabled={!formData.region}>
                  <option value="">{formData.region ? 'Select city' : 'Select a region first'}</option>
                  {(REGION_CITIES[formData.region] || []).map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
              </div>
            </div>
            <div className="form-row">
              <div className="form-group">
                <label htmlFor="loc-province">Province</label>
                <input id="loc-province" type="text" value={formData.province} onChange={(e) => setFormData({ ...formData, province: e.target.value })} placeholder="e.g. Misamis Oriental" />
              </div>
              <div className="form-group">
                <label htmlFor="loc-address">Full address *</label>
                <input id="loc-address" type="text" value={formData.address} onChange={(e) => setFormData({ ...formData, address: e.target.value })} placeholder="Street, barangay, city, province" required />
              </div>
            </div>
            <div className="form-row">
              <div className="form-group">
                <label htmlFor="loc-contact">Contact number</label>
                <input id="loc-contact" type="text" value={formData.contact_number} onChange={(e) => setFormData({ ...formData, contact_number: e.target.value })} placeholder="09xx-xxx-xxxx" />
              </div>
              <div className="form-group">
                <label htmlFor="loc-email">Email</label>
                <input id="loc-email" type="email" value={formData.email} onChange={(e) => setFormData({ ...formData, email: e.target.value })} placeholder="location@airking.com" />
              </div>
            </div>
            <div className="form-row">
              <div className="form-group">
                <label htmlFor="loc-manager">Manager / Supervisor</label>
                <input id="loc-manager" type="text" value={formData.manager_name} onChange={(e) => setFormData({ ...formData, manager_name: e.target.value })} placeholder="e.g. Juan Dela Cruz" />
              </div>
              <div className="form-group">
                <label htmlFor="loc-capacity">Capacity (units)</label>
                <input id="loc-capacity" type="number" min="0" value={formData.capacity} onChange={(e) => setFormData({ ...formData, capacity: e.target.value })} placeholder="e.g. 5000" />
              </div>
            </div>
            <div className="form-row">
              <div className="form-group">
                <label htmlFor="loc-opening">Opening date</label>
                <DateInput id="loc-opening" value={formData.opening_date} onChange={(e) => setFormData({ ...formData, opening_date: e.target.value })} />
              </div>
            </div>
          </section>
          <div className="modal-footer">
            <button type="button" className="btn-cancel" onClick={() => setIsModalOpen(false)}>Cancel</button>
            <button type="submit" className="btn-save">{editingLocation ? 'Save changes' : 'Add location'}</button>
          </div>
        </form>
      </Modal>
     </div>
    </AdminLayout>
  );
};

export default LocationManagement;
