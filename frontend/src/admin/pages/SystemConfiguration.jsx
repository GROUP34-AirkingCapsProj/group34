import React, { useState, useEffect, useCallback } from 'react';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import UserManagementPanel from '../components/UserManagementPanel';
import { toast } from '../utils/toast';
import { getApiBaseUrl, getToken, systemSettingsAPI, companySettingsAPI, accountPolicyAPI, setSessionTimeoutMinutes, passwordResetAPI } from '../services/api';
import LabelPrinterPanel from '../components/LabelPrinterPanel';
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

const PrinterIcon = () => (
  <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
    <path d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/>
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

const BuildingIcon = () => (
  <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
    <path d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z"/>
  </svg>
);

// NOTE: User management lives in the shared <UserManagementPanel/>, which fetches its
// own users/roles/locations. This page only fetches /api/users for the Manual Password
// Reset dropdown.

const PolicyToggle = ({ id, label, desc, checked, onChange }) => (
  <div className="sc-checkbox-row sc-checkbox-row--accent">
    <span className="sc-checkbox-icon sc-checkbox-icon--indigo">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-9 14l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
    </span>
    <input type="checkbox" id={id} checked={checked} onChange={onChange} />
    <label htmlFor={id}>
      <span className="sc-checkbox-label">{label}</span>
      <span className="sc-checkbox-desc">{desc}</span>
    </label>
  </div>
);

const TABS = [
  { id: 'security', label: 'Security', icon: <LockIcon /> },
  { id: 'accounts', label: 'User Accounts', icon: <PersonIcon /> },
  { id: 'users', label: 'User Management', icon: <PersonAddIcon />, badge: 2 },
  { id: 'company', label: 'Company Settings', icon: <BuildingIcon /> },
  { id: 'printers', label: 'Barcode Printers', icon: <PrinterIcon /> },
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

function formatResetCodeDate(iso) {
  if (!iso) return '—';
  try {
    return new Date(iso).toLocaleString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  } catch {
    return iso;
  }
}

const SystemConfiguration = () => {
  const [activeTab, setActiveTab] = useState('security');
  const [settings, setSettings] = useState(DEFAULT_SETTINGS);
  const [policy, setPolicy] = useState({ avatar: true, email: true, phone: true, address: true });
  const [policyLoading, setPolicyLoading] = useState(true);
  const [policySaving, setPolicySaving] = useState(false);

  // API data states — `users` feeds the Manual Password Reset dropdown
  const [users, setUsers] = useState([]);
  const [loading, setLoading] = useState(false);

  const [activeCodes, setActiveCodes]      = useState([]);
  const [activeCodesLoading, setActiveCodesLoading] = useState(false);
  const [manualUser, setManualUser]        = useState('');

  // Company settings state
  const [companyForm, setCompanyForm] = useState({ company_name: '', company_address: '', company_email: '', company_phone: '', pdf_paper_size: 'a4' });
  const [companySaving, setCompanySaving] = useState(false);
  const [companyLoading, setCompanyLoading] = useState(false);

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

  // Fetch users for the Manual Password Reset dropdown
  const fetchData = async () => {
    setLoading(true);
    const token = getToken();

    if (!token) {
      setLoading(false);
      return;
    }

    try {
      const headers = {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
      };

      const usersRes = await fetch(`${getApiBaseUrl()}/users?per_page=100`, { headers });
      const usersData = usersRes.ok ? await usersRes.json() : { data: [] };
      setUsers(usersData.data || []);
    } catch (err) {
      console.error('Failed to fetch users:', err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    const loadPolicy = async () => {
      setPolicyLoading(true);
      try {
        const p = await accountPolicyAPI.get();
        setPolicy({ avatar: !!p.avatar, email: !!p.email, phone: !!p.phone, address: !!p.address });
      } catch {
        // keep permissive defaults if unavailable
      } finally {
        setPolicyLoading(false);
      }
    };
    loadPolicy();

    // Fetch API data
    fetchData();

    const loadCompanySettings = async () => {
      setCompanyLoading(true);
      try {
        const data = await companySettingsAPI.get();
        setCompanyForm({
          company_name:    data.company_name    || '',
          company_address: data.company_address || '',
          company_email:   data.company_email   || '',
          company_phone:   data.company_phone   || '',
          pdf_paper_size:  data.pdf_paper_size  || 'a4',
        });
      } catch {
        // Keep empty defaults
      } finally {
        setCompanyLoading(false);
      }
    };
    loadCompanySettings();

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

  const togglePolicy = (field) =>
    setPolicy((prev) => ({ ...prev, [field]: !prev[field] }));

  const handlePolicySave = async () => {
    setPolicySaving(true);
    try {
      const saved = await accountPolicyAPI.update(policy);
      setPolicy({ avatar: !!saved.avatar, email: !!saved.email, phone: !!saved.phone, address: !!saved.address });
      toast.success('Account policy saved');
    } catch (err) {
      toast.error("Couldn't save account policy", err.message);
    } finally {
      setPolicySaving(false);
    }
  };

  const handleCompanyChange = (field, value) =>
    setCompanyForm(prev => ({ ...prev, [field]: value }));

  const handleCompanySave = async () => {
    if (!companyForm.company_name.trim()) {
      toast.error('Company name is required.');
      return;
    }
    setCompanySaving(true);
    try {
      await companySettingsAPI.update(companyForm);
      toast.success('Company settings saved successfully!');
    } catch (err) {
      toast.error(err.message || 'Failed to save company settings.');
    } finally {
      setCompanySaving(false);
    }
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

  return (
    <AdminLayout>
      <div className="ak-page sc-content">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">System · Settings</p>
              <h1 className="ak-title">System Settings</h1>
              <p className="ak-sub">Configure security, branding, inventory rules, and system preferences.</p>
            </div>
          </div>
        </header>

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
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-9 14l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                  </span>
                  <input type="checkbox" id="twoFactorAuth" checked={settings.twoFactorAuth} onChange={() => handleToggle('twoFactorAuth')} />
                  <label htmlFor="twoFactorAuth">
                    <span className="sc-checkbox-label">Require a login code for non-admin users</span>
                    <span className="sc-checkbox-desc">
                      Sends a one-time code to the user&apos;s recovery email at login.
                      Administrators always receive one and are not affected by this
                      setting.
                    </span>
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

        {activeTab === 'accounts' && (
          <div className="sc-tab-content">
            <div className="sc-card">
              <div className="sc-card-header">
                <h3>User Account Editing</h3>
                <p>Choose what non-admin users can change on their own Account Settings page. Admins always manage their own account.</p>
              </div>
              <div className="sc-card-body">
                {policyLoading ? (
                  <Loader label="Loading account policy" />
                ) : (
                  <>
                    <PolicyToggle id="policy-avatar" label="Profile picture"
                      desc="Upload or remove their own profile photo."
                      checked={policy.avatar} onChange={() => togglePolicy('avatar')} />
                    <PolicyToggle id="policy-email" label="Email address"
                      desc="Change the part of their sign-in email before @airking.com."
                      checked={policy.email} onChange={() => togglePolicy('email')} />
                    <PolicyToggle id="policy-phone" label="Phone number"
                      desc="Edit their contact number."
                      checked={policy.phone} onChange={() => togglePolicy('phone')} />
                    <PolicyToggle id="policy-address" label="Address"
                      desc="Edit their address."
                      checked={policy.address} onChange={() => togglePolicy('address')} />
                  </>
                )}
              </div>
            </div>
          </div>
        )}

        {activeTab === 'users' && (
          <div className="sc-tab-content">
            {/* User Management (shared panel) */}
            <UserManagementPanel />

            {/* Active Reset Codes */}
            <div className="sc-card">
              <div className="sc-card-header">
                <h3>Active Reset Codes</h3>
                <p>View and manage active password reset codes</p>
              </div>
              <div className="sc-card-body">
                {activeCodesLoading && (
                  <Loader label="Loading reset codes" />
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

        {activeTab === 'company' && (
          <div className="sc-tab-content">
            <div className="sc-card">
              <div className="sc-card-header">
                <h3>Company Information</h3>
                <p>This information appears on the header of all exported Purchase Order documents.</p>
              </div>
              <div className="sc-card-body">
                {companyLoading ? (
                  <Loader label="Loading company settings" />
                ) : (
                  <>
                    <div className="sc-field-row">
                      <div className="sc-field">
                        <label>Company Name</label>
                        <input
                          type="text"
                          value={companyForm.company_name}
                          onChange={e => handleCompanyChange('company_name', e.target.value)}
                          placeholder="e.g. Airking Air Conditioning"
                        />
                        <span className="sc-field-hint">Shown bold and large at the top of the PO document.</span>
                      </div>
                      <div className="sc-field">
                        <label>Company Email</label>
                        <div className="sc-input-with-icon">
                          <span className="sc-input-icon"><MailIcon /></span>
                          <input
                            type="email"
                            value={companyForm.company_email}
                            onChange={e => handleCompanyChange('company_email', e.target.value)}
                            placeholder="e.g. cdoairking@gmail.com"
                          />
                        </div>
                      </div>
                    </div>
                    <div className="sc-field-row">
                      <div className="sc-field">
                        <label>Company Phone</label>
                        <div className="sc-input-with-icon">
                          <span className="sc-input-icon"><PhoneIcon /></span>
                          <input
                            type="tel"
                            value={companyForm.company_phone}
                            onChange={e => handleCompanyChange('company_phone', e.target.value)}
                            placeholder="e.g. 0934324564"
                          />
                        </div>
                      </div>
                      <div className="sc-field">
                        <label>Company Address</label>
                        <div className="sc-input-with-icon">
                          <span className="sc-input-icon"><MapPinIcon /></span>
                          <input
                            type="text"
                            value={companyForm.company_address}
                            onChange={e => handleCompanyChange('company_address', e.target.value)}
                            placeholder="e.g. CDO Warehouse A, Cagayan de Oro"
                          />
                        </div>
                      </div>
                    </div>
                    <div className="sc-field-row">
                      <div className="sc-field">
                        <label>Default PDF Paper Size</label>
                        <select
                          value={companyForm.pdf_paper_size}
                          onChange={e => handleCompanyChange('pdf_paper_size', e.target.value)}
                        >
                          <option value="a4">A4</option>
                          <option value="letter">Letter</option>
                          <option value="legal">Legal</option>
                          <option value="a5">A5</option>
                        </select>
                        <span className="sc-field-hint">Used by automatic PO and GRN PDF downloads.</span>
                      </div>
                      <div className="sc-field" />
                    </div>
                  </>
                )}
              </div>
            </div>

            {/* PO Header Preview */}
            <div className="sc-card">
              <div className="sc-card-header">
                <h3>PO Document Header Preview</h3>
                <p>Live preview of how the company header will appear on exported Purchase Orders.</p>
              </div>
              <div className="sc-card-body">
                <div style={{ fontFamily: "'Times New Roman', serif", background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 8, padding: '20px 24px' }}>
                  <div style={{ textAlign: 'center', borderBottom: '2px solid #1e293b', paddingBottom: 12, marginBottom: 12 }}>
                    <div style={{ fontSize: 20, fontWeight: 900, textTransform: 'uppercase', marginBottom: 4 }}>
                      {companyForm.company_name || 'COMPANY NAME'}
                    </div>
                    {companyForm.company_address && (
                      <div style={{ fontSize: 12, color: '#475569' }}>{companyForm.company_address}</div>
                    )}
                    {companyForm.company_email && (
                      <div style={{ fontSize: 12, color: '#475569' }}>{companyForm.company_email}</div>
                    )}
                    {companyForm.company_phone && (
                      <div style={{ fontSize: 12, color: '#475569' }}>{companyForm.company_phone}</div>
                    )}
                  </div>
                  <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
                    <div style={{ fontSize: 12, color: '#64748b', fontStyle: 'italic' }}>PURCHASE ORDER #PO-XXXX</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        )}

        {activeTab === 'printers' && (
          <div className="sc-tab-content">
            <LabelPrinterPanel />
          </div>
        )}

        {(activeTab === 'security' || activeTab === 'accounts' || activeTab === 'company') && (
          <div className="sc-footer">
            <button className="sc-btn-reset" onClick={handleResetDefaults} style={{ visibility: (activeTab === 'company' || activeTab === 'accounts') ? 'hidden' : 'visible' }}>Reset to Defaults</button>
            <button
              className="sc-btn-save"
              disabled={(activeTab === 'company' && companySaving) || (activeTab === 'accounts' && policySaving)}
              onClick={
                activeTab === 'security' ? handleSave :
                activeTab === 'company' ? handleCompanySave :
                activeTab === 'accounts' ? handlePolicySave :
                () => toast.success('User settings saved!')
              }
            >
              <SaveIcon />
              {activeTab === 'security' ? 'Save Security Settings' :
               activeTab === 'company' ? (companySaving ? 'Saving…' : 'Save Company Settings') :
               activeTab === 'accounts' ? (policySaving ? 'Saving…' : 'Save Account Policy') :
               'Save User Settings'}
            </button>
          </div>
        )}
      </div>
    </AdminLayout>
  );
};

export default SystemConfiguration;
