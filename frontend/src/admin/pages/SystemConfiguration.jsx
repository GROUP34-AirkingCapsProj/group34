import React, { useState, useEffect, useRef, useCallback } from 'react';
import AdminLayout from '../components/AdminLayout';
import { toast } from '../utils/toast';
import { getApiBaseUrl, getToken, systemSettingsAPI, setSessionTimeoutMinutes, passwordResetAPI } from '../services/api';
import '../styles/system_configuration.css';

const LockIcon = () => (
  <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
    <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
  </svg>
);

const PersonIcon = () => (
  <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
    <path d="M12 12c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm0 2c-3.33 0-10 1.67-10 5v2h20v-2c0-3.33-6.67-5-10-5z"/>
  </svg>
);

const PersonAddIcon = () => (
  <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
    <path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
  </svg>
);

const ClockIcon = () => (
  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
    <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
  </svg>
);

const ShieldIcon = () => (
  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
  </svg>
);

const KeyIcon = () => (
  <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
    <path d="M12.65 10C11.83 7.67 9.61 6 7 6c-3.31 0-6 2.69-6 6s2.69 6 6 6c2.61 0 4.83-1.67 5.65-4H17v4h4v-4h2v-4H12.65zM7 14c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2z"/>
  </svg>
);

const InfoIcon = () => (
  <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/>
  </svg>
);

const SaveIcon = () => (
  <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
    <path d="M17 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V7l-4-4zm-5 16c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3zm3-10H5V5h10v4z"/>
  </svg>
);

const CameraIcon = () => (
  <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor">
    <path d="M12 15.2c1.768 0 3.2-1.432 3.2-3.2S13.768 8.8 12 8.8 8.8 10.232 8.8 12s1.432 3.2 3.2 3.2zM9 2L7.17 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2h-3.17L15 2H9zm3 15c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5z"/>
  </svg>
);

const AvatarPersonIcon = () => (
  <svg width="44" height="44" viewBox="0 0 24 24" fill="currentColor">
    <path d="M12 12c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm0 2c-3.33 0-10 1.67-10 5v2h20v-2c0-3.33-6.67-5-10-5z"/>
  </svg>
);

const MailIcon = () => (
  <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
    <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
  </svg>
);

const PhoneIcon = () => (
  <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
    <path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/>
  </svg>
);

const MapPinIcon = () => (
  <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
    <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
  </svg>
);

const CalendarIcon = () => (
  <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
    <path d="M20 3h-1V1h-2v2H7V1H5v2H4c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 18H4V8h16v13z"/>
  </svg>
);

const BriefcaseIcon = () => (
  <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
    <path d="M20 6h-4V4c0-1.11-.89-2-2-2h-4c-1.11 0-2 .89-2 2v2H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-6 0h-4V4h4v2z"/>
  </svg>
);

const CheckCircleIcon = () => (
  <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
  </svg>
);

const XCircleIcon = () => (
  <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
    <path d="M12 2C6.47 2 2 6.47 2 12s4.47 10 10 10 10-4.47 10-10S17.53 2 12 2zm5 13.59L15.59 17 12 13.41 8.41 17 7 15.59 10.59 12 7 8.41 8.41 7 12 10.59 15.59 7 17 8.41 13.41 12 17 15.59z"/>
  </svg>
);

const CopyIcon = () => (
  <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor">
    <path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/>
  </svg>
);

const KeyOutlineIcon = () => (
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <circle cx="7.5" cy="15.5" r="5.5"/>
    <path d="M21 2L13 10M18 5l2 2M11 13l2 2"/>
  </svg>
);

// NOTE: Fake data has been removed. Data is now fetched from the API.
// - Users are fetched from /api/users
// - Roles are fetched from /api/roles
// - Locations are fetched from /api/locations

const TABS = [
  { id: 'security', label: 'Security', icon: <LockIcon /> },
  { id: 'profile', label: 'Profile Settings', icon: <PersonIcon /> },
  { id: 'users', label: 'User Management', icon: <PersonAddIcon />, badge: 2 },
];

const DEFAULT_SETTINGS = {
  minPasswordLength: '8',
  passwordExpiry: '90',
  sessionTimeout: '30',
  maxLoginAttempts: '5',
  twoFactorAuth: true,
  resetCodeExpiry: '24',
  allowSelfServiceReset: true,
};

const DEFAULT_PROFILE = {
  fullName: 'Admin User',
  email: 'admin@airking.com',
  phone: '+63 912 345 6789',
  address: 'Cagayan de Oro City, Philippines',
  dateJoined: 'January 15, 2024',
  position: 'System Administrator',
};

function formatResetCodeDate(iso) {
  if (!iso) return '—';
  try {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
  } catch {
    return iso;
  }
}

const SystemConfiguration = () => {
  const [activeTab, setActiveTab] = useState('security');
  const [settings, setSettings] = useState(DEFAULT_SETTINGS);
  const [profile, setProfile] = useState(DEFAULT_PROFILE);
  const fileInputRef = useRef(null);

  // API data states
  const [users, setUsers] = useState([]);
  const [roles, setRoles] = useState([]);
  const [locations, setLocations] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const [userRoleFilter, setUserRoleFilter] = useState('');
  const [userLocFilter, setUserLocFilter]  = useState('');
  const [resetRequests, setResetRequests]  = useState([]);
  const [activeCodes, setActiveCodes]      = useState([]);
  const [activeCodesLoading, setActiveCodesLoading] = useState(false);
  const [manualUser, setManualUser]        = useState('');

  const fetchActiveResetCodes = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    setActiveCodesLoading(true);
    try {
      const res = await passwordResetAPI.listActive();
      const rows = Array.isArray(res?.data) ? res.data : [];
      setActiveCodes(
        rows.map((r) => ({
          id: r.id,
          name: r.name || 'User',
          email: r.email || '',
          code: r.code || '—',
          generated: formatResetCodeDate(r.generated_at),
          expires: formatResetCodeDate(r.expires_at),
        }))
      );
    } catch {
      setActiveCodes([]);
    } finally {
      setActiveCodesLoading(false);
    }
  }, []);

  // Fetch data from API
  const fetchData = async () => {
    setLoading(true);
    setError(null);
    const token = getToken();
    
    if (!token) {
      setError('Authentication required. Please log in.');
      setLoading(false);
      return;
    }

    try {
      const headers = {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
      };

      // Fetch users, roles, and locations in parallel
      const [usersRes, rolesRes, locationsRes] = await Promise.all([
        fetch(`${getApiBaseUrl()}/users?per_page=100`, { headers }),
        fetch(`${getApiBaseUrl()}/roles`, { headers }),
        fetch(`${getApiBaseUrl()}/locations?per_page=100`, { headers }),
      ]);

      const usersData = usersRes.ok ? await usersRes.json() : { data: [] };
      const rolesData = rolesRes.ok ? await rolesRes.json() : { data: [] };
      const locationsData = locationsRes.ok ? await locationsRes.json() : { data: [] };

      setUsers(usersData.data || []);
      setRoles(rolesData.data || rolesData || []);
      setLocations(locationsData.data || locationsData || []);
    } catch (err) {
      console.error('Failed to fetch data:', err);
      setError('Failed to load data from server');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    // Load user profile from localStorage
    const stored = localStorage.getItem('user');
    if (stored) {
      try {
        const user = JSON.parse(stored);
        setProfile(prev => ({
          ...prev,
          fullName: [user.first_name, user.last_name].filter(Boolean).join(' ') || user.name || prev.fullName,
          email: user.email || prev.email,
          phone: user.phone || prev.phone,
          address: user.address || prev.address,
          position: user.role?.role_name || prev.position,
        }));
      } catch {}
    }

    // Fetch API data
    fetchData();

    const loadSessionTimeout = async () => {
      const token = getToken();
      if (!token) return;
      try {
        const data = await systemSettingsAPI.getSession();
        if (data?.session_timeout_minutes != null) {
          setSettings((prev) => ({
            ...prev,
            sessionTimeout: String(data.session_timeout_minutes),
            ...(data.max_login_attempts != null
              ? { maxLoginAttempts: String(data.max_login_attempts) }
              : {}),
            ...(data.two_factor_auth_enabled !== undefined
              ? { twoFactorAuth: Boolean(data.two_factor_auth_enabled) }
              : {}),
          }));
          setSessionTimeoutMinutes(data.session_timeout_minutes);
        }
      } catch {
        // Keep defaults if endpoint unavailable
      }
    };
    loadSessionTimeout();
  }, []);

  useEffect(() => {
    if (activeTab !== 'users') return;
    fetchActiveResetCodes();
  }, [activeTab, fetchActiveResetCodes]);

  const handleChange = (field, value) => {
    setSettings(prev => ({ ...prev, [field]: value }));
  };

  const handleToggle = (field) => {
    setSettings(prev => ({ ...prev, [field]: !prev[field] }));
  };

  const handleResetDefaults = () => {
    setSettings(DEFAULT_SETTINGS);
  };

  const handleSave = async () => {
    const token = getToken();
    if (!token) {
      toast.error('Authentication required. Please log in.');
      return;
    }
    const minutes = parseInt(settings.sessionTimeout, 10);
    if (!Number.isFinite(minutes) || minutes < 1 || minutes > 10080) {
      toast.error('Session timeout must be between 1 and 10080 minutes.');
      return;
    }
    const maxAttempts = parseInt(settings.maxLoginAttempts, 10);
    if (!Number.isFinite(maxAttempts) || maxAttempts < 3 || maxAttempts > 50) {
      toast.error('Max login attempts must be between 3 and 50.');
      return;
    }
    try {
      await systemSettingsAPI.updateSession(minutes, maxAttempts, settings.twoFactorAuth);
      setSessionTimeoutMinutes(minutes);
      toast.success('Settings saved successfully!');
    } catch (err) {
      toast.error(err.message || 'Failed to save session settings.');
    }
  };

  const handleProfileChange = (field, value) => {
    setProfile(prev => ({ ...prev, [field]: value }));
  };

  const handleProfileSave = () => {
    toast.success('Profile updated successfully!');
  };

  const handleGenerateCode = async (req) => {
    if (!req?.user_id) {
      toast.error('Invalid reset request.');
      return;
    }
    try {
      await passwordResetAPI.issue(Number(req.user_id));
      setResetRequests((prev) => prev.filter((r) => r.id !== req.id));
      toast.success(`Reset code generated for ${req.name || 'user'}.`);
      await fetchActiveResetCodes();
    } catch (err) {
      toast.error(err.message || 'Failed to issue reset code.');
    }
  };

  const handleDenyRequest = (id) => {
    setResetRequests(prev => prev.filter(r => r.id !== id));
    toast.success('Request denied.');
  };

  const handleRevoke = async (id) => {
    try {
      await passwordResetAPI.revoke(id);
      toast.success('Reset code revoked.');
      await fetchActiveResetCodes();
    } catch (err) {
      toast.error(err.message || 'Failed to revoke code.');
    }
  };

  const handleCopyCode = (code) => {
    navigator.clipboard?.writeText(code).then(() => toast.success('Code copied!'));
  };

  const handleManualGenerate = async () => {
    if (!manualUser) {
      toast.error('Please select a user.');
      return;
    }
    const token = getToken();
    if (!token) {
      toast.error('Authentication required.');
      return;
    }
    const u = users.find((x) => String(x.user_id) === String(manualUser));
    const label = u
      ? [u.first_name, u.last_name].filter(Boolean).join(' ') || u.username || u.email || 'User'
      : manualUser;
    try {
      const data = await passwordResetAPI.issue(Number(manualUser));
      const plain = data?.code;
      if (plain) {
        let copied = false;
        if (navigator.clipboard?.writeText) {
          try {
            await navigator.clipboard.writeText(plain);
            copied = true;
          } catch {
            copied = false;
          }
        }
        if (copied) {
          toast.success(`Code copied for ${label}. Valid 24 hours.`);
        } else {
          toast.warning(`Code for ${label}: ${plain} (valid 24h)`);
        }
      } else {
        toast.success(`Reset code generated for ${label}.`);
      }
      setManualUser('');
      await fetchActiveResetCodes();
    } catch (err) {
      toast.error(err.message || 'Failed to issue reset code.');
    }
  };

  const manualResetUserOptions = [...users].sort((a, b) => {
    const na = [a.first_name, a.last_name].filter(Boolean).join(' ') || a.username || a.email || '';
    const nb = [b.first_name, b.last_name].filter(Boolean).join(' ') || b.username || b.email || '';
    return na.localeCompare(nb, undefined, { sensitivity: 'base' });
  });

// Filter users based on selected role and location
  const filteredUsers = users.filter(u => {
    const userRole = u.role?.role_name || '';
    const userLocation = u.location?.location_name || '';
    const matchesRole = !userRoleFilter || userRole === userRoleFilter;
    const matchesLocation = !userLocFilter || userLocation === userLocFilter;
    return matchesRole && matchesLocation;
  });

  // Build role options for filter dropdown
  const roleOptions = roles.map(r => r.role_name).filter(Boolean);
  const locationOptions = locations.map(l => l.location_name).filter(Boolean);

  return (
    <AdminLayout>
      <div className="sc-content">
        <div className="sc-page-header">
          <h1>System Settings</h1>
          <p>Configure security, branding, inventory rules, and system preferences</p>
        </div>

        <div className="sc-tabs-bar">
          {TABS.map(tab => (
            <button
              key={tab.id}
              className={`sc-tab ${activeTab === tab.id ? 'active' : ''}`}
              onClick={() => setActiveTab(tab.id)}
            >
              <span className="sc-tab-icon">{tab.icon}</span>
              {tab.label}
              {tab.badge && <span className="sc-tab-badge">{tab.badge}</span>}
            </button>
          ))}
        </div>

        {activeTab === 'security' && (
          <div className="sc-tab-content">
            <div className="sc-card">
              <div className="sc-card-header">
                <h3>Password Policy</h3>
                <p>Configure password requirements and complexity rules</p>
              </div>
              <div className="sc-card-body">
                <div className="sc-field-row">
                  <div className="sc-field">
                    <label>Minimum Password Length</label>
                    <input
                      type="number"
                      value={settings.minPasswordLength}
                      onChange={(e) => handleChange('minPasswordLength', e.target.value)}
                    />
                  </div>
                  <div className="sc-field">
                    <label>Password Expiry (days)</label>
                    <input
                      type="number"
                      value={settings.passwordExpiry}
                      onChange={(e) => handleChange('passwordExpiry', e.target.value)}
                    />
                  </div>
                </div>
              </div>
            </div>

            <div className="sc-card">
              <div className="sc-card-header">
                <h3>Session &amp; Authentication</h3>
                <p>Configure session timeout and login security</p>
              </div>
              <div className="sc-card-body">
                <div className="sc-field-row">
                  <div className="sc-field">
                    <label><ClockIcon /> Session Timeout (minutes)</label>
                    <input
                      type="number"
                      value={settings.sessionTimeout}
                      onChange={(e) => handleChange('sessionTimeout', e.target.value)}
                    />
                    <span className="sc-field-hint">Auto-logout after inactivity period</span>
                  </div>
                  <div className="sc-field">
                    <label><ShieldIcon /> Max Login Attempts</label>
                    <input
                      type="number"
                      value={settings.maxLoginAttempts}
                      onChange={(e) => handleChange('maxLoginAttempts', e.target.value)}
                    />
                    <span className="sc-field-hint">Lock account after failed attempts</span>
                  </div>
                </div>

                <div className="sc-checkbox-row sc-checkbox-row--accent">
                  <span className="sc-checkbox-icon sc-checkbox-icon--indigo">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-9 14l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                  </span>
                  <input type="checkbox" id="twoFactorAuth" checked={settings.twoFactorAuth} onChange={() => handleToggle('twoFactorAuth')} />
                  <label htmlFor="twoFactorAuth">
                    <span className="sc-checkbox-label">Enable Two-Factor Authentication (2FA)</span>
                    <span className="sc-checkbox-desc">Require a one-time code sent to the user&apos;s email at login</span>
                  </label>
                </div>
              </div>
            </div>

            <div className="sc-card">
              <div className="sc-card-header">
                <h3>Password Reset Configuration</h3>
                <p>Configure password reset security settings</p>
              </div>
              <div className="sc-card-body">
                <div className="sc-field sc-field--half">
                  <label><ClockIcon /> Reset Code Expiry (minutes)</label>
                  <input
                    type="number"
                    value={settings.resetCodeExpiry}
                    onChange={(e) => handleChange('resetCodeExpiry', e.target.value)}
                  />
                  <span className="sc-field-hint">How long reset codes remain valid</span>
                </div>

                <div className="sc-checkbox-row sc-checkbox-row--accent">
                  <span className="sc-checkbox-icon sc-checkbox-icon--amber">
                    <KeyIcon />
                  </span>
                  <input type="checkbox" id="allowSelfServiceReset" checked={settings.allowSelfServiceReset} onChange={() => handleToggle('allowSelfServiceReset')} />
                  <label htmlFor="allowSelfServiceReset">
                    <span className="sc-checkbox-label">Allow Self-Service Password Reset</span>
                    <span className="sc-checkbox-desc">Users can request password reset; admin must approve and generate codes</span>
                  </label>
                </div>

                <div className="sc-info-box">
                  <div className="sc-info-header">
                    <span className="sc-info-icon"><InfoIcon /></span>
                    <span className="sc-info-title">How Password Reset Works</span>
                  </div>
                  <ul className="sc-info-list">
                    <li>Users submit password reset requests</li>
                    <li>Admin reviews and approves requests in User Management tab</li>
                    <li>Admin generates unique reset code for approved requests</li>
                    <li>Admin shares the code with the user securely</li>
                    <li>User enters the code to reset their password</li>
                    <li>Codes expire after the configured time period</li>
                  </ul>
                </div>
              </div>
            </div>
          </div>
        )}

        {activeTab === 'profile' && (
          <div className="sc-tab-content">
            <div className="sc-card">
              <div className="sc-card-header">
                <h3>Profile Picture</h3>
                <p>Upload your profile picture</p>
              </div>
              <div className="sc-card-body">
                <div className="sc-profile-picture-row">
                  <div className="sc-avatar-wrapper">
                    <div className="sc-avatar">
                      <AvatarPersonIcon />
                    </div>
                    <button
                      className="sc-avatar-badge"
                      onClick={() => fileInputRef.current?.click()}
                    >
                      <CameraIcon />
                    </button>
                    <input
                      ref={fileInputRef}
                      type="file"
                      accept="image/jpeg,image/png,image/gif"
                      style={{ display: 'none' }}
                    />
                  </div>
                  <div className="sc-upload-info">
                    <span className="sc-upload-title">Upload a new profile picture</span>
                    <span className="sc-upload-hint">JPG, PNG or GIF. Max size 2MB.</span>
                    <button
                      className="sc-choose-file-btn"
                      onClick={() => fileInputRef.current?.click()}
                    >
                      Choose File
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <div className="sc-card">
              <div className="sc-card-header">
                <h3>Personal Details</h3>
                <p>Update your personal information</p>
              </div>
              <div className="sc-card-body">
                <div className="sc-field-row">
                  <div className="sc-field">
                    <label>Full Name</label>
                    <input
                      type="text"
                      value={profile.fullName}
                      onChange={(e) => handleProfileChange('fullName', e.target.value)}
                    />
                  </div>
                  <div className="sc-field">
                    <label>Email Address</label>
                    <div className="sc-input-with-icon">
                      <span className="sc-input-icon"><MailIcon /></span>
                      <input
                        type="email"
                        value={profile.email}
                        onChange={(e) => handleProfileChange('email', e.target.value)}
                      />
                    </div>
                  </div>
                </div>
                <div className="sc-field-row">
                  <div className="sc-field">
                    <label>Phone Number</label>
                    <div className="sc-input-with-icon">
                      <span className="sc-input-icon"><PhoneIcon /></span>
                      <input
                        type="tel"
                        value={profile.phone}
                        onChange={(e) => handleProfileChange('phone', e.target.value)}
                      />
                    </div>
                  </div>
                  <div className="sc-field">
                    <label>Address</label>
                    <div className="sc-input-with-icon">
                      <span className="sc-input-icon"><MapPinIcon /></span>
                      <input
                        type="text"
                        value={profile.address}
                        onChange={(e) => handleProfileChange('address', e.target.value)}
                      />
                    </div>
                  </div>
                </div>
                <div className="sc-field-row">
                  <div className="sc-field">
                    <label>Date Joined</label>
                    <div className="sc-input-with-icon">
                      <span className="sc-input-icon"><CalendarIcon /></span>
                      <input type="text" value={profile.dateJoined} readOnly />
                    </div>
                  </div>
                  <div className="sc-field">
                    <label>Position</label>
                    <div className="sc-input-with-icon">
                      <span className="sc-input-icon"><BriefcaseIcon /></span>
                      <input type="text" value={profile.position} readOnly />
                    </div>
                  </div>
                </div>
                <div className="sc-profile-save-row">
                  <button className="sc-btn-save" onClick={handleProfileSave}>
                    <SaveIcon /> Save Changes
                  </button>
                </div>
              </div>
            </div>
          </div>
        )}

        {activeTab === 'users' && (
          <div className="sc-tab-content">
            {/* User Accounts */}
            <div className="sc-card">
              <div className="sc-card-header">
                <h3>User Accounts</h3>
                <p>Manage user roles and permissions</p>
              </div>
              <div className="sc-card-body">
{loading && <p className="sc-placeholder">Loading users...</p>}
                {error && <p className="sc-placeholder" style={{ color: 'red' }}>{error}</p>}
                {!loading && !error && (
                  <>
                <div className="sc-um-filter-row">
                  <span className="sc-um-filter-label">Filter by:</span>
                  <select className="sc-um-select" value={userRoleFilter} onChange={e => setUserRoleFilter(e.target.value)}>
                    <option value="">All Roles</option>
                    {roleOptions.map(r => <option key={r} value={r}>{r}</option>)}
                  </select>
                  <select className="sc-um-select" value={userLocFilter} onChange={e => setUserLocFilter(e.target.value)}>
                    <option value="">All Locations</option>
                    {locationOptions.map(l => <option key={l} value={l}>{l}</option>)}
                  </select>
                </div>
                <table className="sc-um-table">
                  <thead>
                    <tr>
                      <th className="sc-um-th">EMPLOYEE ID</th>
                      <th className="sc-um-th">NAME</th>
                      <th className="sc-um-th">ROLE</th>
                      <th className="sc-um-th">LOCATION</th>
                      <th className="sc-um-th">STATUS</th>
                      <th className="sc-um-th sc-um-th--right">ACTIONS</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filteredUsers.map(u => (
                      <tr key={u.user_id} className="sc-um-tr">
                        <td className="sc-um-td sc-um-td--id">{u.username || u.user_id}</td>
                        <td className="sc-um-td sc-um-td--name">{[u.first_name, u.last_name].filter(Boolean).join(' ') || 'N/A'}</td>
                        <td className="sc-um-td">{u.role?.role_name || 'N/A'}</td>
                        <td className="sc-um-td">{u.location?.location_name || 'N/A'}</td>
                        <td className="sc-um-td">
                          <span className={`sc-um-status-badge sc-um-status-badge--${u.status?.status_name?.toLowerCase() === 'active' ? 'active' : 'inactive'}`}>{u.status?.status_name || 'N/A'}</span>
                        </td>
                        <td className="sc-um-td sc-um-td--actions">
                          <button className="sc-um-btn-edit">Edit</button>
                          <button className="sc-um-btn-deactivate">Deactivate</button>
                        </td>
                      </tr>
                    ))}
                    {filteredUsers.length === 0 && (
                      <tr>
                        <td colSpan="6" className="sc-um-td" style={{ textAlign: 'center' }}>No users found.</td>
                      </tr>
                    )}
                  </tbody>
                </table>
                  </>
                )}
              </div>
            </div>

            {/* Password Reset Requests */}
            <div className="sc-card">
              <div className="sc-card-header">
                <h3>Password Reset Requests</h3>
                <p>Manage user password reset requests</p>
              </div>
              <div className="sc-card-body">
                {resetRequests.length === 0 && (
                  <p className="sc-placeholder" style={{ padding: '16px 0' }}>No pending reset requests.</p>
                )}
                {resetRequests.map(req => (
                  <div key={req.id} className="sc-um-request-item">
                    <div className="sc-um-request-avatar">
                      <AvatarPersonIcon />
                    </div>
                    <div className="sc-um-request-info">
                      <span className="sc-um-request-name">{req.name}</span>
                      <span className="sc-um-request-email">{req.email}</span>
                      <span className="sc-um-request-time">Requested: {req.requested}</span>
                    </div>
                    <div className="sc-um-request-actions">
                      <button type="button" className="sc-um-btn-generate" onClick={() => handleGenerateCode(req)}>
                        <CheckCircleIcon /> Generate Code
                      </button>
                      <button type="button" className="sc-um-btn-deny" onClick={() => handleDenyRequest(req.id)}>
                        <XCircleIcon /> Deny
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            </div>

            {/* Active Reset Codes */}
            <div className="sc-card">
              <div className="sc-card-header">
                <h3>Active Reset Codes</h3>
                <p>View and manage active password reset codes</p>
              </div>
              <div className="sc-card-body">
                {activeCodesLoading && (
                  <p className="sc-placeholder" style={{ padding: '16px 0' }}>Loading reset codes…</p>
                )}
                {!activeCodesLoading && activeCodes.length === 0 && (
                  <p className="sc-placeholder" style={{ padding: '16px 0' }}>No active reset codes.</p>
                )}
                {!activeCodesLoading && activeCodes.map(c => (
                  <div key={c.id} className="sc-um-code-item">
                    <div className="sc-um-code-left">
                      <span className="sc-um-code-name">{c.name}</span>
                      <span className="sc-um-code-email">{c.email}</span>
                      <div className="sc-um-code-row">
                        <span className="sc-um-code-icon"><KeyOutlineIcon /></span>
                        <span className="sc-um-code-value">{c.code}</span>
                        <button className="sc-um-code-copy" onClick={() => handleCopyCode(c.code)}>
                          <CopyIcon />
                        </button>
                      </div>
                      <div className="sc-um-code-meta">
                        <span><strong>Generated</strong><br />{c.generated}</span>
                        <span><strong>Expires</strong><br />{c.expires}</span>
                      </div>
                    </div>
                    <button type="button" className="sc-um-btn-revoke" onClick={() => handleRevoke(c.id)}>
                      <XCircleIcon /> Revoke
                    </button>
                  </div>
                ))}
              </div>
            </div>

            {/* Manual Password Reset */}
            <div className="sc-card">
              <div className="sc-card-header">
                <h3>Manual Password Reset</h3>
                <p>Generate reset code for any user</p>
              </div>
              <div className="sc-card-body">
                <p className="sc-um-manual-desc">
                  Select a user and generate a password reset code manually. The code will be valid for 24 hours.
                </p>
                <label className="sc-um-manual-label" htmlFor="sc-manual-reset-user">Select User</label>
                <div className="sc-um-manual-row">
                  <select
                    id="sc-manual-reset-user"
                    className="sc-um-manual-input sc-um-manual-select"
                    value={manualUser}
                    onChange={(e) => setManualUser(e.target.value)}
                    disabled={loading || users.length === 0}
                  >
                    <option value="">{loading ? 'Loading users…' : users.length === 0 ? 'No users available' : 'Select a user…'}</option>
                    {manualResetUserOptions.map((u) => {
                      const name = [u.first_name, u.last_name].filter(Boolean).join(' ') || u.username || 'User';
                      const suffix = u.email ? ` — ${u.email}` : '';
                      return (
                        <option key={u.user_id} value={String(u.user_id)}>
                          {name}{suffix}
                        </option>
                      );
                    })}
                  </select>
                  <button type="button" className="sc-um-btn-generate-code" onClick={handleManualGenerate}>
                    <KeyOutlineIcon /> Generate Reset Code
                  </button>
                </div>
              </div>
            </div>
          </div>
        )}

        {(activeTab === 'security' || activeTab === 'users') && (
          <div className="sc-footer">
            <button className="sc-btn-reset" onClick={handleResetDefaults}>Reset to Defaults</button>
            <button className="sc-btn-save" onClick={activeTab === 'security' ? handleSave : () => toast.success('User settings saved!')}>
              <SaveIcon /> {activeTab === 'security' ? 'Save Security Settings' : 'Save User Settings'}
            </button>
          </div>
        )}
      </div>
    </AdminLayout>
  );
};

export default SystemConfiguration;
