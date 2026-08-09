// Resolve at request time (not module load) so the same bundle works on desktop and on mobile
// when opened via LAN IP. REACT_APP_API_URL often points at 127.0.0.1 — on a phone that is wrong.
const trimSlash = (u) => String(u || '').replace(/\/+$/, '');
const rawEnvApi = process.env.REACT_APP_API_URL && String(process.env.REACT_APP_API_URL).trim();
const publicApiOverride = process.env.REACT_APP_API_PUBLIC_URL && String(process.env.REACT_APP_API_PUBLIC_URL).trim();
const apiPort = String(process.env.REACT_APP_API_PORT || '80').replace(/^:+/, '');

function isLoopbackHost(h) {
  return (
    !h ||
    h === 'localhost' ||
    h === '127.0.0.1' ||
    h === '[::1]' ||
    h === '::1'
  );
}

/** Private IPv4 ranges + typical dev LAN hostnames (not public sites like *.vercel.app). */
function isPrivateLanHost(h) {
  if (!h || isLoopbackHost(h)) return false;
  if (/^192\.168\.\d{1,3}\.\d{1,3}$/i.test(h)) return true;
  if (/^10\.\d{1,3}\.\d{1,3}\.\d{1,3}$/i.test(h)) return true;
  if (/^172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3}$/i.test(h)) return true;
  if (/\.local$/i.test(h)) return true;
  // http://DESKTOP-XYZ:3000 — single-label Windows hostname on LAN
  if (!h.includes('.')) return true;
  return false;
}

export const getApiBaseUrl = () => {
  if (typeof window === 'undefined') {
    return trimSlash(publicApiOverride || rawEnvApi || `http://127.0.0.1:${apiPort}/api`);
  }

  if (publicApiOverride) {
    return trimSlash(publicApiOverride);
  }

  const h = window.location.hostname;

  if (isLoopbackHost(h)) {
    if (rawEnvApi) return trimSlash(rawEnvApi);
    return `http://127.0.0.1:${apiPort}/api`;
  }

  if (isPrivateLanHost(h)) {
    const envPointsToLoopback =
      !rawEnvApi || /127\.0\.0\.1|localhost|\[::1\]|::1/i.test(rawEnvApi);
    if (!envPointsToLoopback) {
      return trimSlash(rawEnvApi);
    }
    // Follow the PAGE's protocol rather than hardcoding http:. An https:// page
    // calling an http:// API is mixed content and the browser blocks it, which
    // is what happens on a phone using the HTTPS vhost (needed for the camera:
    // getUserMedia requires a secure context and localhost is exempt, LAN IPs
    // are not). Following the page means one build serves both ports.
    // REACT_APP_API_HTTPS still forces https: for an API that is HTTPS-only.
    const proto = String(process.env.REACT_APP_API_HTTPS || '').toLowerCase() === 'true'
      ? 'https:'
      : (window.location.protocol === 'https:' ? 'https:' : 'http:');
    const portSuffix = (apiPort === '80' || apiPort === '') ? '' : `:${apiPort}`;
    return trimSlash(`${proto}//${h}${portSuffix}/api`);
  }

  if (rawEnvApi) return trimSlash(rawEnvApi);
  return `http://127.0.0.1:${apiPort}/api`;
};

/**
 * Resolve a backend-relative asset path (e.g. "/storage/avatars/x.png") to an
 * absolute URL against the API origin computed at runtime. The backend returns
 * avatar paths host-agnostically so the same value works on localhost, LAN IP,
 * and mobile. Absolute URLs (legacy/external) are returned unchanged.
 */
export const resolveStorageUrl = (path) => {
  if (!path) return path;
  if (/^https?:\/\//i.test(path)) return path;
  const origin = getApiBaseUrl().replace(/\/api\/?$/, '');
  return `${origin}/${String(path).replace(/^\/+/, '')}`;
};

const TOKEN_KEY = 'token';

/**
 * Fetch a binary file (PDF/CSV) from the API using the Bearer token in the
 * Authorization header — NOT in the URL — then open it in a new tab (for
 * viewing/printing) or download it. Keeping the token out of the URL avoids the
 * browser "insecure" credential-in-URL warning. Throws on non-2xx.
 */
export const openApiFile = async (
  path,
  {
    download = false,
    preview = false,
    previewWindow: suppliedPreviewWindow = null,
    filename = 'download',
  } = {},
) => {
  // Reserve the preview tab during the user's click. Opening it after the
  // authenticated fetch finishes is commonly blocked as an unsolicited popup.
  const previewWindow = preview
    ? (suppliedPreviewWindow && !suppliedPreviewWindow.closed
      ? suppliedPreviewWindow
      : window.open('', '_blank'))
    : null;
  const token = localStorage.getItem(TOKEN_KEY);
  const url = `${getApiBaseUrl()}${path.startsWith('/') ? path : `/${path}`}`;
  const res = await fetch(url, {
    headers: {
      Accept: '*/*',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });
  if (!res.ok) {
    if (previewWindow) previewWindow.close();
    let msg = `Failed to load file (${res.status})`;
    try { const j = await res.json(); if (j?.message) msg = j.message; } catch (_) { /* not JSON */ }
    throw new Error(msg);
  }
  const blob = await res.blob();
  const objUrl = URL.createObjectURL(blob);
  if (download) {
    const a = document.createElement('a');
    a.href = objUrl;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    if (previewWindow) previewWindow.location.href = objUrl;
    setTimeout(() => URL.revokeObjectURL(objUrl), 10000);
  } else {
    // Reuse the tab reserved synchronously above so browsers do not block the
    // PDF after the authenticated request finishes.
    const w = previewWindow || window.open(objUrl, '_blank');
    if (previewWindow) previewWindow.location.href = objUrl;
    if (!w) {
      // Popup blocked — fall back to a download so the user still gets the file.
      const a = document.createElement('a');
      a.href = objUrl;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
    }
    setTimeout(() => URL.revokeObjectURL(objUrl), 60000);
  }
};

/** Synced from login and system settings; drives client idle logout. */
export const SESSION_TIMEOUT_MINUTES_KEY = 'session_timeout_minutes';

/** Fired when the stored timeout changes so the idle timer can reschedule immediately. */
export const SESSION_TIMEOUT_CHANGED_EVENT = 'airking:session-timeout-changed';

export const getSessionTimeoutMinutes = () => {
  const raw = localStorage.getItem(SESSION_TIMEOUT_MINUTES_KEY);
  const n = parseInt(raw, 10);
  if (Number.isFinite(n) && n >= 1) return n;
  return 30;
};

export const setSessionTimeoutMinutes = (minutes) => {
  if (minutes == null || !Number.isFinite(Number(minutes))) return;
  const n = Math.max(1, Math.min(10080, Math.floor(Number(minutes))));
  localStorage.setItem(SESSION_TIMEOUT_MINUTES_KEY, String(n));
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent(SESSION_TIMEOUT_CHANGED_EVENT));
  }
};

/**
 * Check if the backend API is reachable (no auth required). Use for connection troubleshooting.
 * @returns {Promise<{ ok: boolean, message?: string }>}
 */
export const checkApiHealth = async () => {
  try {
    const res = await fetch(getApiBaseUrl(), {
      method: 'GET',
      headers: { Accept: 'application/json' },
      mode: 'cors',
    });
    const text = await res.text();
    let data = {};
    try {
      data = text ? JSON.parse(text) : {};
    } catch (_) {
      data = { message: text || `Invalid response (${res.status})` };
    }
    if (res.ok && (data.message != null || data.version != null)) {
      return { ok: true, message: data.message };
    }
    return { ok: false, message: data.message || `Server returned ${res.status}` };
  } catch (e) {
    const msg = e.message === 'Failed to fetch'
      ? 'Cannot reach the server. From back-end run: composer run serve-lan (or php artisan serve --host=0.0.0.0) so phones on Wi‑Fi can use port 8000.'
      : e.message;
    return { ok: false, message: msg };
  }
};

/* ======================
   TOKEN HELPERS
====================== */
export const getToken = () => localStorage.getItem(TOKEN_KEY);
export const setToken = (token) => localStorage.setItem(TOKEN_KEY, token);
export const removeToken = () => {
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(SESSION_TIMEOUT_MINUTES_KEY);
};

/* ======================
   API REQUEST HELPER
====================== */
const apiRequest = async (endpoint, options = {}) => {
  const token = getToken();

  const headers = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    ...(options.headers || {}),
  };

  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  const base = getApiBaseUrl();
  const url = endpoint.startsWith('/') ? `${base}${endpoint}` : `${base}/${endpoint}`;
  const method = options.method || 'GET';
  console.log(`[API] ${method} ${url}`, options.body ? { body: options.body } : '');
  let response;
  try {
    response = await fetch(url, {
      ...options,
      headers,
    });
  } catch (networkError) {
    const msg =
      networkError.message === 'Failed to fetch'
        ? 'Cannot reach the server. From back-end run: composer run serve-lan (or php artisan serve --host=0.0.0.0) for mobile on the same Wi‑Fi.'
        : networkError.message;
    throw new Error(msg);
  }

  let data;
  try {
    const text = await response.text();
    if (!text || text.trim() === '') {
      data = { message: response.status === 404 ? 'Not found.' : 'Server error.' };
    } else {
      data = JSON.parse(text);
    }
  } catch (_) {
    const msg =
      response.status === 404
        ? 'Endpoint not found (404). Check the API URL and route.'
        : response.status >= 500
          ? `Server error (${response.status}). Try again later.`
          : `Server returned ${response.status} with non-JSON response.`;
    throw new Error(msg);
  }

  console.log(`[API] ${method} ${url} → ${response.status}`, response.ok ? 'OK' : { message: data.message, errors: data.errors });

  if (!response.ok) {
    // Token expired or missing — clear storage and bounce to login
    if (response.status === 401) {
      removeToken();
      if (typeof window !== 'undefined' && !window.location.pathname.includes('/login')) {
        window.location.href = '/admin/login';
      }
    }
    const err = new Error(data.message || 'API request failed');
    err.status = response.status;
    err.errors = data.errors || null;
    // Preserve the full parsed body so callers can read structured payloads that
    // arrive on a non-2xx status (e.g. the issuance/transfer scan endpoints return
    // HTTP 422 with a `scan_result` verdict). Purely additive — existing callers
    // only read message/status/errors.
    err.data = data;
    if (data.login_attempts_remaining !== undefined && data.login_attempts_remaining !== null) {
      err.login_attempts_remaining = data.login_attempts_remaining;
    }
    throw err;
  }

  return data;
};

/* Upload helper: sends FormData; never sets Content-Type so the browser can
   add the multipart boundary. Mirrors apiRequest's auth + error handling. */
const uploadRequest = async (endpoint, formData, method = 'POST') => {
  const token = getToken();
  const headers = { Accept: 'application/json' };
  if (token) headers.Authorization = `Bearer ${token}`;

  const base = getApiBaseUrl();
  const url = endpoint.startsWith('/') ? `${base}${endpoint}` : `${base}/${endpoint}`;

  const response = await fetch(url, { method, headers, body: formData });

  let data;
  try {
    const text = await response.text();
    data = text ? JSON.parse(text) : { message: 'Server error.' };
  } catch (_) {
    throw new Error(`Server returned ${response.status} with non-JSON response.`);
  }

  if (!response.ok) {
    // Token expired or missing — clear storage and bounce to login
    if (response.status === 401) {
      removeToken();
      if (typeof window !== 'undefined' && !window.location.pathname.includes('/login')) {
        window.location.href = '/admin/login';
      }
    }
    const err = new Error(data.message || 'Request failed');
    err.errors = data.errors || null;
    err.status = response.status;
    throw err;
  }
  return data;
};

/* ======================
   AUTH API FIXED
====================== */
export const authAPI = {
  /** Math CAPTCHA challenge (no token). Call before login. */
  getLoginChallenge: async () => {
    return apiRequest('/login/challenge');
  },

  /**
   * @param {string} email
   * @param {string} password
   * @param {{ captcha_token: string, captcha_answer: string }} captchaPayload
   */
  login: async (email, password, captchaPayload) => {
    const body = {
      email,
      password,
      captcha_token: captchaPayload?.captcha_token ?? '',
      captcha_answer: String(captchaPayload?.captcha_answer ?? ''),
    };
    const data = await apiRequest('/login', {
      method: 'POST',
      body: JSON.stringify(body),
    });

    if (data.token) setToken(data.token);
    if (data.user) localStorage.setItem('user', JSON.stringify(data.user));
    if (data.session_timeout_minutes != null) {
      setSessionTimeoutMinutes(data.session_timeout_minutes);
    }
    return data;
  },

  /**
   * @param {string} email
   * @param {string|number} otp
   */
  verifyOtp: async (email, otp) => {
    const data = await apiRequest('/verify-otp', {
      method: 'POST',
      body: JSON.stringify({
        email: String(email || '').trim(),
        otp: String(otp ?? '').replace(/\s/g, ''),
      }),
    });
    if (data.token) setToken(data.token);
    if (data.user) localStorage.setItem('user', JSON.stringify(data.user));
    if (data.session_timeout_minutes != null) {
      setSessionTimeoutMinutes(data.session_timeout_minutes);
    }
    return data;
  },

  logout: async () => {
    await apiRequest('/logout', { method: 'POST' });
    removeToken();
  },

  forgotPassword: async (email) => {
    return apiRequest('/forgot-password', {
      method: 'POST',
      body: JSON.stringify({ email }),
    });
  },

  getUser: async () => apiRequest('/user'),
};

/** Admin-issued code (auth) + public reset completion (no auth). */
export const passwordResetAPI = {
  issue: async (userId) =>
    apiRequest('/password-reset/issue', {
      method: 'POST',
      body: JSON.stringify({ user_id: userId }),
    }),
  listActive: async () => apiRequest('/password-reset/active'),
  revoke: async (id) =>
    apiRequest(`/password-reset/active/${id}/revoke`, {
      method: 'POST',
    }),
  complete: async ({ email, code, password, password_confirmation }) =>
    apiRequest('/password-reset/complete', {
      method: 'POST',
      body: JSON.stringify({
        email,
        code: String(code || '').trim(),
        password,
        password_confirmation,
      }),
    }),
};

/* ======================
   ANALYTICS DASHBOARD API
   Server-computed per-tab analytics. Replaces the page's old client-side
   computation over /api/batch, which truncated at 500 inventory rows and
   could not reach incident data at all.
====================== */
export const analyticsAPI = {
  _qs: (params = {}) => {
    const entries = Object.entries(params).filter(([, v]) => v !== '' && v != null);
    const qs = new URLSearchParams(entries).toString();
    return qs ? `?${qs}` : '';
  },
  getStockLevel: async (params = {}) => apiRequest(`/analytics/stock-level${analyticsAPI._qs(params)}`),
  getMovement: async (params = {}) => apiRequest(`/analytics/movement${analyticsAPI._qs(params)}`),
  getPredictions: async (params = {}) => apiRequest(`/analytics/predictions${analyticsAPI._qs(params)}`),
  getProfitLoss: async (params = {}) => apiRequest(`/analytics/profit-loss${analyticsAPI._qs(params)}`),
  getLocationPerformance: async (params = {}) => apiRequest(`/analytics/location-performance${analyticsAPI._qs(params)}`),
  getFilters: async () => apiRequest('/analytics/filters'),
};

/* ======================
   BATCH API (single request for multiple resources)
====================== */
export const batchAPI = {
  /**
   * @param {{ include: string[] }} params - e.g. { include: ['users', 'roles', 'branches'] }
   * @returns {Promise<{ data: Record<string, { success: boolean, data: any, pagination?: any }> }>}
   */
  get: async (params = {}) => {
    const include = Array.isArray(params.include) ? params.include : (params.include ? [params.include] : []);
    const query = include.length ? `?include=${include.join(',')}` : '';
    const res = await apiRequest(`/batch${query}`);
    return res;
  },
};

/* ======================
   LAYOUT SUMMARY API (header badges in one call)
====================== */
export const layoutAPI = {
  getSummary: async () => apiRequest('/layout-summary'),
};

/* ======================
   DASHBOARD API
====================== */
export const dashboardAPI = {
  get: async () => apiRequest('/dashboard'),
  getStats: async () => apiRequest('/dashboard/stats'),
  getLowStock: async () => apiRequest('/dashboard/low-stock'),
  getRecentMovements: async () => apiRequest('/dashboard/recent-movements'),
  getStockMovementTrends: async () => apiRequest('/dashboard/stock-movement-trends'),
  getPendingApprovals: async () => apiRequest('/dashboard/pending-approvals'),
  getCharts: async (params = {}) => {
    const qs = new URLSearchParams(Object.entries(params).filter(([, v]) => v !== '' && v != null)).toString();
    return apiRequest(`/dashboard/charts${qs ? '?' + qs : ''}`);
  },
  getFilterOptions: async () => apiRequest('/dashboard/filter-options'),
};

/* ======================
   ALERTS API (Mark All Read)
====================== */
export const alertsAPI = {
  getSettings: async () => apiRequest('/alerts/settings'),
  getUnreadCount: async () => apiRequest('/alerts/settings'),
  markAllRead: async () => apiRequest('/alerts/mark-all-read', { method: 'POST' }),
};

/** Session timeout from system_settings (GET any auth user; PUT admin only). */
export const systemSettingsAPI = {
  getSession: async () => apiRequest('/system/session-settings'),
  /**
   * @param {number} sessionTimeoutMinutes
   * @param {number} maxLoginAttempts
   * @param {boolean} twoFactorAuthEnabled
   */
  updateSession: async (sessionTimeoutMinutes, maxLoginAttempts, twoFactorAuthEnabled) =>
    apiRequest('/system/session-settings', {
      method: 'PUT',
      body: JSON.stringify({
        session_timeout_minutes: sessionTimeoutMinutes,
        max_login_attempts: maxLoginAttempts,
        two_factor_auth_enabled: Boolean(twoFactorAuthEnabled),
      }),
    }),
};

/** Account self-service policy (admin only): which fields users may edit. */
export const accountPolicyAPI = {
  get: async () => apiRequest('/system/account-policy'),
  update: async (policy) =>
    apiRequest('/system/account-policy', { method: 'PUT', body: JSON.stringify(policy) }),
};

/** Archive configuration settings (admin only). */
export const archiveSettingsAPI = {
  get: async () => apiRequest('/archive-settings'),
  updateModule: async (module, data) =>
    apiRequest(`/archive-settings/module/${module}`, {
      method: 'PUT',
      body: JSON.stringify(data),
    }),
  updateSchedule: async (data) =>
    apiRequest('/archive-settings/schedule', {
      method: 'PUT',
      body: JSON.stringify(data),
    }),
  preview: async () =>
    apiRequest('/archive-settings/preview', { method: 'POST' }),
  runNow: async () =>
    apiRequest('/archive-settings/run-now', { method: 'POST' }),
  runCleanup: async () =>
    apiRequest('/archive-settings/run-cleanup', { method: 'POST' }),
  getLogs: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/archive-settings/logs?${query}` : '/archive-settings/logs');
  },
};

/** Company letterhead settings (GET any auth user; PUT admin only). */
export const companySettingsAPI = {
  get: async () => apiRequest('/system/company-settings'),
  update: async (data) =>
    apiRequest('/system/company-settings', {
      method: 'PUT',
      body: JSON.stringify(data),
    }),
};

/* ======================
   ACCOUNT (SELF-SERVICE) API
====================== */
export const accountAPI = {
  getProfile: async () => apiRequest('/me/profile'),
  updateProfile: async (data) =>
    apiRequest('/me/profile', { method: 'PUT', body: JSON.stringify(data) }),
  uploadAvatar: async (file) => {
    const fd = new FormData();
    fd.append('avatar', file);
    return uploadRequest('/me/avatar', fd, 'POST');
  },
  deleteAvatar: async () => apiRequest('/me/avatar', { method: 'DELETE' }),
  updateEmail: async (data) =>
    apiRequest('/me/email', { method: 'PUT', body: JSON.stringify(data) }),
  updatePassword: async (data) =>
    apiRequest('/me/password', { method: 'PUT', body: JSON.stringify(data) }),
};

/* ======================
   AUDIT API
====================== */
export const auditAPI = {
  getTrail: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/audit-trail?${query}` : '/audit-trail');
  },
  getTrailV2: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/audit-trail/v2?${query}` : '/audit-trail/v2');
  },
  getFilterOptions: async () => apiRequest('/audit-trail/v2/filter-options'),
  getEntry: async (id) => apiRequest(`/audit-trail/v2/${id}`),
  getLog: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/audit-log?${query}` : '/audit-log');
  },
};

/* ======================
   LOCATIONS API (ERD — replaces branches & warehouses)
====================== */
export const locationsAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/locations?${query}` : '/locations');
  },
  getById: async (id) => apiRequest(`/locations/${id}`),
  create: async (data) =>
    apiRequest('/locations', { method: 'POST', body: JSON.stringify(data) }),
  update: async (id, data) =>
    apiRequest(`/locations/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  delete: async (id) =>
    apiRequest(`/locations/${id}`, { method: 'DELETE' }),
  getArchived: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/locations/archived?${query}` : '/locations/archived');
  },
  restore: async (id) =>
    apiRequest(`/locations/${id}/restore`, { method: 'POST' }),
};

/* Backward-compat: branches & warehouses are now location_type filters on locations */
export const branchesAPI = {
  getAll: async (params = {}) =>
    locationsAPI.getAll({ ...params, location_type: 'branch_office' }),
  getById: async (id) => locationsAPI.getById(id),
};

export const warehousesAPI = {
  getAll: async (params = {}) =>
    locationsAPI.getAll({ ...params, location_type: 'warehouse' }),
  getById: async (id) => locationsAPI.getById(id),
};

/* ======================
   ITEMS API
====================== */
export const itemsAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/items?${query}` : '/items');
  },
  getById: async (id) => apiRequest(`/items/${id}`),
  create: async (data) =>
    apiRequest('/items', {
      method: 'POST',
      body: JSON.stringify(data),
    }),
  update: async (id, data) =>
    apiRequest(`/items/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data),
    }),
  delete: async (id) =>
    apiRequest(`/items/${id}`, { method: 'DELETE' }),
};

/* ======================
   INVENTORY API
====================== */
export const inventoryAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/inventory?${query}` : '/inventory');
  },
  getById: async (id) => apiRequest(`/inventory/${id}`),
  getBatches: async (id) => apiRequest(`/inventory/${id}/batches`),
  /** All batches with remaining stock, scoped server-side to the user's location. Powers the Warehouse Map tab. */
  getWarehouseBatches: async () => apiRequest('/inventory-batches'),
  /** Edit a batch's physical zone label only (never touches quantities). */
  updateBatchZone: async (batchId, zoneLabel) =>
    apiRequest(`/inventory-batches/${batchId}`, {
      method: 'PATCH',
      body: JSON.stringify({ zone_label: zoneLabel }),
    }),
  /** PO + warehouse inventory groups (Inventory Monitoring Console). */
  getPoGroups: async () => apiRequest('/po-inventory-groups'),
  /** One PO + warehouse group's 3-tab detail. */
  getPoGroupDetail: async (poId, warehouseId) =>
    apiRequest(`/po-inventory-groups/${poId}?location_id=${warehouseId}`),
  /** Rename the Location (zone label) for a whole PO + warehouse group. */
  updatePoGroupLocation: async (poId, warehouseId, zoneLabel) =>
    apiRequest(`/po-inventory-groups/${poId}`, {
      method: 'PATCH',
      body: JSON.stringify({ location_id: warehouseId, zone_label: zoneLabel }),
    }),
  create: async (data) =>
    apiRequest('/inventory', {
      method: 'POST',
      body: JSON.stringify(data),
    }),
  update: async (id, data) =>
    apiRequest(`/inventory/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data),
    }),
  delete: async (id) =>
    apiRequest(`/inventory/${id}`, { method: 'DELETE' }),
  /** Exact match on `barcode` or `product_code` (InventoryController::scanBarcode). */
  scanBarcode: async (barcode, locationId) =>
    apiRequest('/inventory/scan-barcode', {
      method: 'POST',
      body: JSON.stringify({
        barcode: String(barcode || '').trim(),
        ...(locationId != null && String(locationId) !== ''
          ? { location_id: locationId }
          : {}),
      }),
    }),
  scanTransaction: async (data) =>
    apiRequest('/inventory/scan-transaction', {
      method: 'POST',
      body: JSON.stringify(data),
    }),
  getHistory: async (inventoryId) =>
    apiRequest(`/inventory/${inventoryId}/history`),
};

/* ======================
   CUSTOMERS API (POS)
====================== */
export const customersAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/customers?${query}` : '/customers');
  },
  search: async (q) => {
    const query = new URLSearchParams({ q }).toString();
    return apiRequest(`/customers/search?${query}`);
  },
  getById: async (id) => apiRequest(`/customers/${id}`),
  getHistory: async (id) => apiRequest(`/customers/${id}/history`),
  create: async (data) =>
    apiRequest('/customers', {
      method: 'POST',
      body: JSON.stringify(data),
    }),
  update: async (id, data) =>
    apiRequest(`/customers/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data),
    }),
  delete: async (id) =>
    apiRequest(`/customers/${id}`, { method: 'DELETE' }),
  restore: async (id) =>
    apiRequest(`/customers/${id}/restore`, { method: 'POST' }),
  getArchived: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/customers/archived?${query}` : '/customers/archived');
  },
  exportCsv: (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return `/customers/export${query ? '?' + query : ''}`;
  },
};

/* ======================
   SALES API (ERD — replaces transactions)
====================== */
export const salesAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/sales?${query}` : '/sales');
  },
  getById: async (id) => apiRequest(`/sales/${id}`),
  create: async (data) =>
    apiRequest('/sales', { method: 'POST', body: JSON.stringify(data) }),
  update: async (id, data) =>
    apiRequest(`/sales/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  delete: async (id) =>
    apiRequest(`/sales/${id}`, { method: 'DELETE' }),
};

/* Legacy alias → /sales */
export const transactionsAPI = salesAPI;

/* ======================
   PURCHASE ORDERS API
====================== */
export const purchaseOrdersAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/purchase-orders?${query}` : '/purchase-orders');
  },
  getById: async (id) => apiRequest(`/purchase-orders/${id}`),
  create: async (data) =>
    apiRequest('/purchase-orders', { method: 'POST', body: JSON.stringify(data) }),
  update: async (id, data) =>
    apiRequest(`/purchase-orders/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  delete: async (id) =>
    apiRequest(`/purchase-orders/${id}`, { method: 'DELETE' }),
  getArchived: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/purchase-orders/archived?${query}` : '/purchase-orders/archived');
  },
  restore: async (id) =>
    apiRequest(`/purchase-orders/${id}/restore`, { method: 'POST' }),

  verify: async (id, note) =>
    apiRequest(`/purchase-orders/${id}/verify`, { method: 'PATCH', body: JSON.stringify({ note: note ?? null }) }),

  reject: async (id, reason) =>
    apiRequest(`/purchase-orders/${id}/reject`, { method: 'PATCH', body: JSON.stringify({ reason }) }),

  /**
   * Products a given supplier carries (via supplier_product pivot).
   * Response: array of { product_id, product_code, product_name, product_type,
   *                      is_serialized, cost_price, unit_price, brand_name,
   *                      capacity_rating, variant, unit_name, unit_abbreviation,
   *                      pieces_per_package, package_unit }
   */
  getProductsBySupplier: async (supplierId) =>
    apiRequest(`/purchase-orders/products-by-supplier/${supplierId}`),

  /** Curated lifecycle timeline for one PO (milestones with expandable changes). */
  history: async (id) => apiRequest(`/purchase-orders/${id}/history`),
};

/* ======================
   RESTOCK ANALYTICS API
====================== */
export const restockAPI = {
  getRecommendations: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/restock/recommendations?${query}` : '/restock/recommendations');
  },
  recalculate: async () =>
    apiRequest('/restock/recalculate', { method: 'POST' }),
  getSuggestions: async (status = 'pending') =>
    apiRequest(`/restock/suggestions?status=${status}`),
  applySuggestion: async (id) =>
    apiRequest(`/restock/suggestions/${id}/apply`, { method: 'POST' }),
  dismissSuggestion: async (id) =>
    apiRequest(`/restock/suggestions/${id}/dismiss`, { method: 'POST' }),
};

/* ======================
   RECEIVINGS API
====================== */
export const receivingsAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/receivings?${query}` : '/receivings');
  },
  getById: async (id) => apiRequest(`/receivings/${id}`),
  create: async (data) =>
    apiRequest('/receivings', { method: 'POST', body: JSON.stringify(data) }),
  update: async (id, data) =>
    apiRequest(`/receivings/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  delete: async (id) =>
    apiRequest(`/receivings/${id}`, { method: 'DELETE' }),

  /** Barcode-scan workflow — record one serial per call */
  poScan: async (data) =>
    apiRequest('/receivings/po-scan', { method: 'POST', body: JSON.stringify(data) }),

  /** Per-product progress for a PO: ordered vs scanned, all serial numbers */
  poScanStatus: async (poId) =>
    apiRequest(`/receivings/po-scan-status/${poId}`),

  /** Persistent receiving log, grouped by PO (not one row per scan). */
  poLog: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(`/receivings/po-log${query ? `?${query}` : ''}`);
  },
};

/* ======================
   SERIALS API
====================== */
export const serialsAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/serials?${query}` : '/serials');
  },
  getById: async (id) => apiRequest(`/serials/${id}`),
  create: async (data) =>
    apiRequest('/serials', { method: 'POST', body: JSON.stringify(data) }),
  update: async (id, data) =>
    apiRequest(`/serials/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  delete: async (id) =>
    apiRequest(`/serials/${id}`, { method: 'DELETE' }),
  scan: async (serialNumber) =>
    apiRequest('/serials/scan', { method: 'POST', body: JSON.stringify({ serial_number: serialNumber }) }),
};

/* ======================
   ISSUANCES API
====================== */
export const issuancesAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/issuances?${query}` : '/issuances');
  },
  getById: async (id) => apiRequest(`/issuances/${id}`),
  cancel: async (id) =>
    apiRequest(`/issuances/${id}/cancel`, { method: 'POST' }),
  create: async (data) =>
    apiRequest('/issuances', { method: 'POST', body: JSON.stringify(data) }),
  update: async (id, data) =>
    apiRequest(`/issuances/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  delete: async (id) =>
    apiRequest(`/issuances/${id}`, { method: 'DELETE' }),
  getArchived: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/issuances/archived?${query}` : '/issuances/archived');
  },
  restore: async (id) =>
    apiRequest(`/issuances/${id}/restore`, { method: 'POST' }),
};

/* ======================
   ADJUSTMENTS API
====================== */
export const adjustmentsAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/adjustments?${query}` : '/adjustments');
  },
  getById: async (id) => apiRequest(`/adjustments/${id}`),
  create: async (data) =>
    apiRequest('/adjustments', { method: 'POST', body: JSON.stringify(data) }),
  update: async (id, data) =>
    apiRequest(`/adjustments/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  delete: async (id) =>
    apiRequest(`/adjustments/${id}`, { method: 'DELETE' }),
  getArchived: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/adjustments/archived?${query}` : '/adjustments/archived');
  },
  restore: async (id) =>
    apiRequest(`/adjustments/${id}/restore`, { method: 'POST' }),
};

/* ======================
   TRANSFERS API
====================== */
export const transfersAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/transfers?${query}` : '/transfers');
  },
  getById: async (id) => apiRequest(`/transfers/${id}`),
  create: async (data) =>
    apiRequest('/transfers', { method: 'POST', body: JSON.stringify(data) }),
  update: async (id, data) =>
    apiRequest(`/transfers/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  receive: async (id, data) =>
    apiRequest(`/transfers/${id}/receive`, { method: 'POST', body: JSON.stringify(data ?? {}) }),
  delete: async (id) =>
    apiRequest(`/transfers/${id}`, { method: 'DELETE' }),
  getArchived: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/transfers/archived?${query}` : '/transfers/archived');
  },
  restore: async (id) =>
    apiRequest(`/transfers/${id}/restore`, { method: 'POST' }),

  /* ── Unified Transfers worklist workflow ──────────────────────────────
     verify/reject (IA), dispatch scan (source), receive scan (destination),
     and receipt. Body shapes are documented alongside each method. */

  /** IA verifies a Pending transfer. body: { from_location_id?, to_location_id?, lines:[{transfer_detail_id, quantity_approved}] } */
  verify: async (id, data) =>
    apiRequest(`/transfers/${id}/verify`, { method: 'POST', body: JSON.stringify(data ?? {}) }),

  /** IA rejects a Pending transfer. body: { rejection_reason } */
  reject: async (id, data) =>
    apiRequest(`/transfers/${id}/reject`, { method: 'PATCH', body: JSON.stringify(data ?? {}) }),

  /** Live per-product available stock at each candidate source warehouse. */
  sourceAvailability: async (id) => apiRequest(`/transfers/${id}/source-availability`),

  /** Requester (or admin/IA) edits a still-Pending request. body: { notes?, products:[{product_id, quantity_requested}] } */
  updateRequest: async (id, data) =>
    apiRequest(`/transfers/${id}/request`, { method: 'PATCH', body: JSON.stringify(data ?? {}) }),

  /** Requester (or admin/IA) withdraws a still-Pending request (soft-deletes it). */
  cancelRequest: async (id) =>
    apiRequest(`/transfers/${id}/cancel`, { method: 'PATCH' }),

  /** FIFO pick suggestions per line for an Approved transfer. */
  pickList: async (id) => apiRequest(`/transfers/${id}/pick-list`),

  /** Live per-line dispatch scan progress + history. */
  scanStatus: async (id) => apiRequest(`/transfers/${id}/scan-status`),

  /** Record one dispatch scan. body: { barcode, transfer_detail_id?, quantity? } */
  scan: async (id, data) =>
    apiRequest(`/transfers/${id}/scan`, { method: 'POST', body: JSON.stringify(data ?? {}) }),

  removeScan: async (id, scanId) =>
    apiRequest(`/transfers/${id}/scan/${scanId}`, { method: 'DELETE' }),

  confirmDispatch: async (id) =>
    apiRequest(`/transfers/${id}/confirm-dispatch`, { method: 'POST' }),

  /** Record one receive scan. body: { barcode, transfer_detail_id?, quantity? } */
  receiveScan: async (id, data) =>
    apiRequest(`/transfers/${id}/receive-scan`, { method: 'POST', body: JSON.stringify(data ?? {}) }),

  removeReceiveScan: async (id, scanId) =>
    apiRequest(`/transfers/${id}/receive-scan/${scanId}`, { method: 'DELETE' }),

  /** Confirm receipt (-> Received). body: {} (backend infers receive location from user). */
  receiveConfirm: async (id, data) =>
    apiRequest(`/transfers/${id}/receive-confirm`, { method: 'POST', body: JSON.stringify(data ?? {}) }),

  receipt: async (id) => apiRequest(`/transfers/${id}/receipt`),

  receiptPdfUrl: (id, token) =>
    `${getApiBaseUrl()}/transfers/${id}/receipt/pdf${token ? `?token=${encodeURIComponent(token)}` : ''}`,
};

/* ======================
   IST API
====================== */
export const istAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/ist?${query}` : '/ist');
  },
  getById:         async (id)         => apiRequest(`/ist/${id}`),
  prepare:         async (requestId)  => apiRequest(`/ist/prepare/${requestId}`),
  create:          async (data)       => apiRequest('/ist', { method: 'POST', body: JSON.stringify(data) }),
  scanStatus:      async (id)         => apiRequest(`/ist/${id}/scan-status`),
  scan:            async (id, data)   => apiRequest(`/ist/${id}/scan`, { method: 'POST', body: JSON.stringify(data) }),
  removeScan:      async (id, scanId) => apiRequest(`/ist/${id}/scan/${scanId}`, { method: 'DELETE' }),
  confirmDispatch: async (id)         => apiRequest(`/ist/${id}/confirm-dispatch`, { method: 'POST' }),
  receiveForm:       async (id)         => apiRequest(`/ist/${id}/receive`),
  receive:           async (id, data)   => apiRequest(`/ist/${id}/receive`, { method: 'POST', body: JSON.stringify(data) }),
  receiveScan:       async (id, data)   => apiRequest(`/ist/${id}/receive-scan`, { method: 'POST', body: JSON.stringify(data) }),
  removeReceiveScan: async (id, scanId) => apiRequest(`/ist/${id}/receive-scan/${scanId}`, { method: 'DELETE' }),
  receipt:           async (id)         => apiRequest(`/ist/${id}/receipt`),
  receiptPdfUrl:     (id, paperSize = null) => {
    const token = getToken();
    const params = new URLSearchParams();
    if (token) params.set('token', token);
    if (paperSize) params.set('paper_size', paperSize);
    const query = params.toString();
    return `${getApiBaseUrl()}/transfers/${id}/receipt/pdf${query ? `?${query}` : ''}`;
  },
};

/* ======================
   TRANSFER REQUESTS API
====================== */
export const transferRequestsAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/transfer-requests?${query}` : '/transfer-requests');
  },
  getById: async (id) => apiRequest(`/transfer-requests/${id}`),
  create: async (data) =>
    apiRequest('/transfer-requests', { method: 'POST', body: JSON.stringify(data) }),
  approve: async (id, data = {}) =>
    apiRequest(`/transfer-requests/${id}/approve`, { method: 'PATCH', body: JSON.stringify(data) }),
  reject: async (id, data) =>
    apiRequest(`/transfer-requests/${id}/reject`, { method: 'PATCH', body: JSON.stringify(data) }),
};

/* ======================
   PURCHASE RETURNS API
====================== */
export const purchaseReturnsAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/purchase-returns?${query}` : '/purchase-returns');
  },
  getById: async (id) => apiRequest(`/purchase-returns/${id}`),
  create: async (data) =>
    apiRequest('/purchase-returns', { method: 'POST', body: JSON.stringify(data) }),
  update: async (id, data) =>
    apiRequest(`/purchase-returns/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  delete: async (id) =>
    apiRequest(`/purchase-returns/${id}`, { method: 'DELETE' }),
};

/* ======================
   DELIVERY RECEIPTS API
====================== */
export const deliveryReceiptsAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/delivery-receipts?${query}` : '/delivery-receipts');
  },
  getById: async (id) => apiRequest(`/delivery-receipts/${id}`),
  create: async (data) =>
    apiRequest('/delivery-receipts', { method: 'POST', body: JSON.stringify(data) }),
  update: async (id, data) =>
    apiRequest(`/delivery-receipts/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  delete: async (id) =>
    apiRequest(`/delivery-receipts/${id}`, { method: 'DELETE' }),
};

/* ======================
   WARRANTY CLAIMS API
====================== */
export const warrantyClaimsAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/warranty-claims?${query}` : '/warranty-claims');
  },
  getById: async (id) => apiRequest(`/warranty-claims/${id}`),
  init: async (data) =>
    apiRequest('/warranty-claims/init', { method: 'POST', body: JSON.stringify(data) }),
  addCustomOrderLine: async (id, productId, quantityInPieces) =>
    apiRequest(`/warranty-claims/${id}/order/custom`, {
      method: 'POST', body: JSON.stringify({ product_id: productId, quantity_in_pieces: quantityInPieces }),
    }),
  addSetOrder: async (id, setId, quantity) =>
    apiRequest(`/warranty-claims/${id}/order/set`, {
      method: 'POST', body: JSON.stringify({ set_id: setId, quantity }),
    }),
  removeOrderLine: async (id, detailId) =>
    apiRequest(`/warranty-claims/${id}/order/${detailId}`, { method: 'DELETE' }),
  addItem: async (id, data) =>
    apiRequest(`/warranty-claims/${id}/item`, { method: 'POST', body: JSON.stringify(data) }),
  removeItem: async (id, detailId) =>
    apiRequest(`/warranty-claims/${id}/item/${detailId}`, { method: 'DELETE' }),
  complete: async (id) =>
    apiRequest(`/warranty-claims/${id}/complete`, { method: 'POST' }),
  slipUrl: (id) => `/warranty-claims/${id}/slip`,
  receiptPdfPath: (id, preview = false) =>
    `/warranty-claims/${id}/receipt/pdf${preview ? '?preview=1' : ''}`,
};

/* ======================
   WARRANTIES API (Phase 2 — real warranty_records)
====================== */
export const warrantiesAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/warranties?${query}` : '/warranties');
  },
  getById: async (id) => apiRequest(`/warranties/${id}`),
  lookupBySerial: async (serial) =>
    apiRequest(`/warranties/lookup/${encodeURIComponent(serial)}`),
  markClaimed: async (id, data = {}) =>
    apiRequest(`/warranties/${id}/claim`, {
      method: 'PUT',
      body: JSON.stringify(data),
    }),
  exportCsv: (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return `/warranties/export${query ? '?' + query : ''}`;
  },
};

/* ======================
   ISSUANCE SCAN API (Phase 3 — barcode-driven issuance)
====================== */
export const issuanceScanAPI = {
  initSession: async (data) =>
    apiRequest('/issuance-scan/init', {
      method: 'POST',
      body: JSON.stringify(data),
    }),
  scanItem: async (data) =>
    apiRequest('/issuance-scan/scan', {
      method: 'POST',
      body: JSON.stringify(data),
    }),
  removeItem: async (detailId) =>
    apiRequest(`/issuance-scan/item/${detailId}`, { method: 'DELETE' }),
  getSession: async (issuanceId) =>
    apiRequest(`/issuance-scan/session/${issuanceId}`),
  activeDraft: async () => apiRequest('/issuance-scan/active-draft'),
  complete: async (issuanceId) =>
    apiRequest(`/issuance-scan/complete/${issuanceId}`, { method: 'POST' }),
  revertToDraft: async (issuanceId) =>
    apiRequest(`/issuance-scan/${issuanceId}/revert-to-draft`, { method: 'POST' }),
  revertToDraftUrl: (issuanceId) => {
    const base = getApiBaseUrl();
    const token = getToken();
    return `${base}/issuance-scan/${issuanceId}/revert-to-draft-beacon${token ? '?token=' + token : ''}`;
  },
  drPdfUrl: (issuanceId, preview = false, paperSize = null) => {
    const base = getApiBaseUrl();
    const token = getToken();
    const params = new URLSearchParams();
    if (token) params.set('token', token);
    if (preview) params.set('preview', '1');
    if (paperSize) params.set('paper_size', paperSize);
    const query = params.toString();
    return `${base}/issuance-scan/delivery-receipt/${issuanceId}/pdf${query ? '?' + query : ''}`;
  },
};

/* ======================
   ISSUANCE ORDER API (Order → Quote wizard: draft line management)
====================== */
export const issuanceOrderAPI = {
  /** Priced quote + grouped lines for a draft/pending issuance. GET /issuances/{id}/quote */
  quote: async (id) => apiRequest(`/issuances/${id}/quote`),
  /** Add a BOQ set to the order. payload: { set_id, set_quantity } */
  addSet: async (id, payload) =>
    apiRequest(`/issuances/${id}/lines/set`, { method: 'POST', body: JSON.stringify(payload) }),
  /** Add a loose custom line. payload: { product_id, quantity } */
  addCustom: async (id, payload) =>
    apiRequest(`/issuances/${id}/lines/custom`, { method: 'POST', body: JSON.stringify(payload) }),
  /** Change a line's quantity. payload: { quantity } */
  updateLine: async (id, lineId, payload) =>
    apiRequest(`/issuances/${id}/lines/${lineId}`, { method: 'PUT', body: JSON.stringify(payload) }),
  /** Remove a line from the order. */
  removeLine: async (id, lineId) =>
    apiRequest(`/issuances/${id}/lines/${lineId}`, { method: 'DELETE' }),
  /** Confirm the quote → transitions Draft to Pending. payload: { accept_shortages? } */
  confirmQuote: async (id, payload = {}) =>
    apiRequest(`/issuances/${id}/confirm-quote`, { method: 'POST', body: JSON.stringify(payload) }),
};

/* ======================
   CATEGORIES API
====================== */
export const categoriesAPI = {
  getAll: async () => apiRequest('/categories'),
  getPerformance: async () => apiRequest('/categories/performance'),
  getArchived: async () => apiRequest('/categories/archived'),
  create: async (data) =>
    apiRequest('/categories', { method: 'POST', body: JSON.stringify(data) }),
  update: async (id, data) =>
    apiRequest(`/categories/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  delete: async (id) => apiRequest(`/categories/${id}`, { method: 'DELETE' }),
  restore: async (id) => apiRequest(`/categories/${id}/restore`, { method: 'POST' }),
  forceDelete: async (id) => apiRequest(`/categories/${id}/force`, { method: 'DELETE' }),
};

/* ======================
   UNITS API
====================== */
export const unitsAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/units?${query}` : '/units');
  },
  getById: async (id) => apiRequest(`/units/${id}`),
  create: async (data) =>
    apiRequest('/units', { method: 'POST', body: JSON.stringify(data) }),
  update: async (id, data) =>
    apiRequest(`/units/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  delete: async (id) => apiRequest(`/units/${id}`, { method: 'DELETE' }),
  getArchived: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/units/archived?${query}` : '/units/archived');
  },
  restore: async (id) =>
    apiRequest(`/units/${id}/restore`, { method: 'POST' }),
  forceDelete: async (id) => apiRequest(`/units/${id}/force`, { method: 'DELETE' }),
};

/* ======================
   BRANDS API
====================== */
export const brandsAPI = {
  getAll: async () => apiRequest('/brands'),
  getArchived: async () => apiRequest('/brands/archived'),
  create: async (data) =>
    apiRequest('/brands', { method: 'POST', body: JSON.stringify(data) }),
  update: async (id, data) =>
    apiRequest(`/brands/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  delete: async (id) => apiRequest(`/brands/${id}`, { method: 'DELETE' }),
  restore: async (id) => apiRequest(`/brands/${id}/restore`, { method: 'POST' }),
  forceDelete: async (id) => apiRequest(`/brands/${id}/force`, { method: 'DELETE' }),
};

/* ======================
   CAPACITIES API
====================== */
export const capacitiesAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/capacities?${query}` : '/capacities');
  },
  getArchived: async () => apiRequest('/capacities/archived'),
  create: async (data) =>
    apiRequest('/capacities', { method: 'POST', body: JSON.stringify(data) }),
  update: async (id, data) =>
    apiRequest(`/capacities/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  delete: async (id) => apiRequest(`/capacities/${id}`, { method: 'DELETE' }),
  restore: async (id) => apiRequest(`/capacities/${id}/restore`, { method: 'POST' }),
  forceDelete: async (id) => apiRequest(`/capacities/${id}/force`, { method: 'DELETE' }),
  merge: async (id, targetId) =>
    apiRequest(`/capacities/${id}/merge`, { method: 'POST', body: JSON.stringify({ target_id: targetId }) }),
};

/* ======================
   VARIANTS API
====================== */
export const variantsAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/variants?${query}` : '/variants');
  },
  getArchived: async () => apiRequest('/variants/archived'),
  create: async (data) =>
    apiRequest('/variants', { method: 'POST', body: JSON.stringify(data) }),
  update: async (id, data) =>
    apiRequest(`/variants/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  delete: async (id) => apiRequest(`/variants/${id}`, { method: 'DELETE' }),
  restore: async (id) => apiRequest(`/variants/${id}/restore`, { method: 'POST' }),
  forceDelete: async (id) => apiRequest(`/variants/${id}/force`, { method: 'DELETE' }),
  merge: async (id, targetId) =>
    apiRequest(`/variants/${id}/merge`, { method: 'POST', body: JSON.stringify({ target_id: targetId }) }),
};

/* ======================
   STATUS LOOKUP API
====================== */
export const statusAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/status-lookup?${query}` : '/status-lookup');
  },
  getById: async (id) => apiRequest(`/status-lookup/${id}`),
  create: async (data) =>
    apiRequest('/status-lookup', { method: 'POST', body: JSON.stringify(data) }),
  update: async (id, data) =>
    apiRequest(`/status-lookup/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  delete: async (id) => apiRequest(`/status-lookup/${id}`, { method: 'DELETE' }),
};

/* ======================
   TRANSACTION TYPE LOOKUP API
====================== */
export const transactionTypesAPI = {
  getAll: async () => apiRequest('/transaction-type-lookup'),
};

/* ======================
   INVENTORY SCAN EVENTS API
====================== */
export const scanEventsAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/scan-events?${query}` : '/scan-events');
  },
};

/* ======================
   SUPPLIERS API
====================== */
export const suppliersAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/suppliers?${query}` : '/suppliers');
  },
  getById: async (id) => apiRequest(`/suppliers/${id}`),
  create: async (data) =>
    apiRequest('/suppliers', { method: 'POST', body: JSON.stringify(data) }),
  update: async (id, data) =>
    apiRequest(`/suppliers/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  delete: async (id) => apiRequest(`/suppliers/${id}`, { method: 'DELETE' }),
  getArchived: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/suppliers/archived?${query}` : '/suppliers/archived');
  },
  restore: async (id) =>
    apiRequest(`/suppliers/${id}/restore`, { method: 'POST' }),
  getProducts: async (id) => apiRequest(`/suppliers/${id}/products`),
  addProduct: async (id, data) =>
    apiRequest(`/suppliers/${id}/products`, { method: 'POST', body: JSON.stringify(data) }),
  updateProduct: async (id, supplierProdId, data) =>
    apiRequest(`/suppliers/${id}/products/${supplierProdId}`, { method: 'PUT', body: JSON.stringify(data) }),
  removeProduct: async (id, productId) =>
    apiRequest(`/suppliers/${id}/products/${productId}`, { method: 'DELETE' }),
};

/* ======================
   PERMISSIONS API
====================== */
export const permissionsAPI = {
  catalog: async () => apiRequest('/permissions'),
  getMine: async () => apiRequest('/me/permissions'),
  getRoleDefaults: async () => apiRequest('/role-permissions'),
  updateRoleDefaults: async (changes) =>
    apiRequest('/role-permissions', { method: 'PUT', body: JSON.stringify({ changes }) }),
  getUserPermissions: async (userId) => apiRequest(`/users/${userId}/permissions`),
  updateUserPermissions: async (userId, reason, changes) =>
    apiRequest(`/users/${userId}/permissions`, { method: 'PUT', body: JSON.stringify({ reason, changes }) }),
  getAuditLogs: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/permission-audit-logs?${query}` : '/permission-audit-logs');
  },
};

/* ======================
   ROLES API
====================== */
export const rolesAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/roles?${query}` : '/roles');
  },
  getById: async (id) => apiRequest(`/roles/${id}`),
  create: async (data) =>
    apiRequest('/roles', { method: 'POST', body: JSON.stringify(data) }),
  update: async (id, data) =>
    apiRequest(`/roles/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  delete: async (id) => apiRequest(`/roles/${id}`, { method: 'DELETE' }),
};

/* ======================
   PRODUCTS API (ERD)
====================== */
export const productsAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/products?${query}` : '/products');
  },
  getById: async (id) => apiRequest(`/products/${id}`),
  create: async (data) =>
    apiRequest('/products', {
      method: 'POST',
      body: JSON.stringify(data),
    }),
  update: async (id, data) =>
    apiRequest(`/products/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data),
    }),
  delete: async (id) =>
    apiRequest(`/products/${id}`, { method: 'DELETE' }),
  getArchived: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/products/archived?${query}` : '/products/archived');
  },
  restore: async (id) =>
    apiRequest(`/products/${id}/restore`, { method: 'POST' }),
};

/* ======================
   PRODUCT SETS API (BOQ templates)
====================== */
export const productSetsAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/product-sets?${query}` : '/product-sets');
  },
  getById: async (id) => apiRequest(`/product-sets/${id}`),
  create: async (data) =>
    apiRequest('/product-sets', { method: 'POST', body: JSON.stringify(data) }),
  update: async (id, data) =>
    apiRequest(`/product-sets/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  archive: async (id) =>
    apiRequest(`/product-sets/${id}`, { method: 'DELETE' }),
  /** Ranked set suggestions for a chosen appliance product. */
  suggest: async (productId) =>
    apiRequest(`/product-sets/suggest?product_id=${productId}`),
  /** Soft-deleted sets only. */
  getArchived: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/product-sets/archived?${query}` : '/product-sets/archived');
  },
  /** Refused with 422 if a live set has since taken the same set_code. */
  restore: async (id) =>
    apiRequest(`/product-sets/${id}/restore`, { method: 'POST' }),
};

/* ======================
   INCIDENT REPORTS API
====================== */
export const incidentReportsAPI = {
  list: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/incident-reports?${query}` : '/incident-reports');
  },
  /**
   * Resolve a scanned or typed barcode to the product an incident is about.
   * Returns { data: { matched_by, product, serial, location_quantity, batches } }.
   *
   * `matched_by` distinguishes how it was found:
   *   'serial'            — an in-stock, issuable unit
   *   'serial_any_state'  — a unit found outside the in-stock path (damaged,
   *                         issued, written off). `serial.in_stock` is false.
   *   'product_barcode'   — a product barcode; `serial` is null
   *   'receiving_barcode' — the label applied at PO receiving; `serial` is null,
   *                         but this IS a successful product match, not a miss
   *
   * Throws on 404 (no match) — the caller keeps the manual picker visible.
   */
  lookup: async ({ barcode, locationId }) => {
    const q = new URLSearchParams({ barcode, location_id: String(locationId) }).toString();
    return apiRequest(`/incident-reports/lookup?${q}`);
  },
  /**
   * File a new incident report (POST /incident-reports). Payload keys the store
   * endpoint validates: source_type ('issuance'|'transfer'|'standalone'),
   * source_id (required unless standalone), related_issuance_id?, location_id,
   * product_id + quantity (required unless reason is 'other'), batch_id?,
   * serial_id?, expected_zone?, actual_zone?, occurred_at?, photo_path?,
   * reason (not_found|theft|damaged|delivery_damage|wrong_type|zone_mismatch|
   * customer_return|other), narrative.
   */
  file: async (payload) =>
    apiRequest('/incident-reports', { method: 'POST', body: JSON.stringify(payload) }),
  /**
   * Upload an evidence photo first, then submit the returned path as
   * `photo_path` when filing. Returns { data: { path } }.
   */
  uploadPhoto: async (file) => {
    const form = new FormData();
    form.append('photo', file);
    return uploadRequest('/incident-reports/photo', form);
  },
  /**
   * Evidence photo as a Blob. Photos are on a private disk behind
   * `permission:incident_reports,read`, so a plain <img src> would 401 — the
   * browser sends no Authorization header. Same approach the GRN PDF downloads
   * use (see openApiFile): fetch with the Bearer header, hand the caller a blob.
   */
  photoBlob: async (id) => {
    const token = getToken();
    const res = await fetch(`${getApiBaseUrl()}/incident-reports/${id}/photo`, {
      headers: {
        Accept: '*/*',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
    });
    if (!res.ok) {
      let msg = `Failed to load photo (${res.status})`;
      try {
        const j = await res.json();
        if (j?.message) msg = j.message;
      } catch (_) { /* not JSON */ }
      const err = new Error(msg);
      err.status = res.status;
      throw err;
    }
    return res.blob();
  },
  resolve: async (id, payload) =>
    apiRequest(`/incident-reports/${id}/resolve`, { method: 'POST', body: JSON.stringify(payload) }),
};

/* ======================
   INVENTORY BATCHES API
   GET /inventory-batches returns every batch with remaining stock, already
   scoped server-side to the caller's location (centralized users see all).
   Flat array under `data` — not paginated, and query params are ignored.
====================== */
export const inventoryBatchesAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/inventory-batches?${query}` : '/inventory-batches');
  },
};

/* ======================
   PICKING API (shared FIFO pick plan for issuance + transfer dispatch)
====================== */
export const pickingAPI = {
  /** FIFO pick plan for an issuance order. GET /issuances/{id}/pick-plan */
  issuancePlan: async (id) => apiRequest(`/issuances/${id}/pick-plan`),
  /** FIFO pick list for a transfer (IST) dispatch. GET /transfers/{id}/pick-list */
  transferPlan: async (id) => apiRequest(`/transfers/${id}/pick-list`),
};

/* ======================
   USERS API
====================== */
export const usersAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/users?${query}` : '/users');
  },
  getById: async (id) => apiRequest(`/users/${id}`),
  create: async (data) =>
    apiRequest('/users', {
      method: 'POST',
      body: JSON.stringify(data),
    }),
  update: async (id, data) =>
    apiRequest(`/users/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data),
    }),
  updateStatus: async (id, statusId) =>
    apiRequest(`/users/${id}/status`, {
      method: 'PUT',
      body: JSON.stringify({ status_id: statusId }),
    }),
  delete: async (id) =>
    apiRequest(`/users/${id}`, { method: 'DELETE' }),
  getArchived: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/users/archived?${query}` : '/users/archived');
  },
  restore: async (id) =>
    apiRequest(`/users/${id}/restore`, { method: 'POST' }),
};

/* ======================
   PROFIT LOSS API
====================== */
export const profitLossAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/profit-loss?${query}` : '/profit-loss');
  },
  getById: async (id) => apiRequest(`/profit-loss/${id}`),
  create: async (data) =>
    apiRequest('/profit-loss', { method: 'POST', body: JSON.stringify(data) }),
  update: async (id, data) =>
    apiRequest(`/profit-loss/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  delete: async (id) => apiRequest(`/profit-loss/${id}`, { method: 'DELETE' }),
};

/* ======================
   CONSUMABLE SUPPLY + PENDING PRODUCT REGISTRATION
====================== */
export const consumableSupplyAPI = {
  getCatalog: async () => apiRequest('/consumable-supply/catalog'),
  /** Same values validated on POST /pending-products (consumable supply_type). */
  getSupplyTypes: async () => apiRequest('/supply-types'),
};

export const pendingProductsAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/pending-products?${query}` : '/pending-products');
  },
  store: async (data) =>
    apiRequest('/pending-products', { method: 'POST', body: JSON.stringify(data) }),
  approve: async (id, data = {}) =>
    apiRequest(`/pending-products/${id}/approve`, {
      method: 'POST',
      body: JSON.stringify(data),
    }),
};

/* ======================
   PURCHASE REQUESTS API  (PR → PO workflow)
====================== */
export const purchaseRequestsAPI = {
  getLowStock: async (locationId) =>
    apiRequest(`/purchase-requests/low-stock?${new URLSearchParams({ location_id: locationId })}`),
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/purchase-requests?${query}` : '/purchase-requests');
  },

  getById: async (id) => apiRequest(`/purchase-requests/${id}`),

  /** Curated lifecycle timeline for one PR (milestones with expandable changes). */
  history: async (id) => apiRequest(`/purchase-requests/${id}/history`),

  create: async (data) =>
    apiRequest('/purchase-requests', { method: 'POST', body: JSON.stringify(data) }),

  update: async (id, data) =>
    apiRequest(`/purchase-requests/${id}`, { method: 'PUT', body: JSON.stringify(data) }),

  delete: async (id) =>
    apiRequest(`/purchase-requests/${id}`, { method: 'DELETE' }),

  getPending: async () => apiRequest('/purchase-requests/pending/list'),

  approve: async (id) =>
    apiRequest(`/purchase-requests/${id}/approve`, {
      method: 'PATCH',
      body: JSON.stringify({}),
    }),

  reject: async (id, data) =>
    apiRequest(`/purchase-requests/${id}/reject`, {
      method: 'PATCH',
      body: JSON.stringify(data),
    }),

  getConvertForm: async (id) => apiRequest(`/purchase-requests/${id}/convert`),

  convertToPO: async (id, data) =>
    apiRequest(`/purchase-requests/${id}/convert`, {
      method: 'POST',
      body: JSON.stringify(data),
    }),

  getArchived: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/purchase-requests/archived?${query}` : '/purchase-requests/archived');
  },

  restore: async (id) =>
    apiRequest(`/purchase-requests/${id}/restore`, { method: 'POST' }),

  /**
   * Smart reorder check — returns open PR lines (not yet fully ordered)
   * for a given product across all pending/approved PRs.
   * Response: array of { pr_id, pr_number, status, quantity_requested,
   *                      quantity_ordered, quantity_remaining, needed_by_date }
   */
  getOpenLinesForProduct: async (productId) => {
    const query = new URLSearchParams({
      product_id: productId,
      open_only:  1,
      per_page:   50,
    }).toString();
    return apiRequest(`/purchase-requests?${query}`);
  },
};

/* ======================
   BUDGET ALLOCATIONS API
====================== */
export const budgetAllocationsAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/budget-allocations?${query}` : '/budget-allocations');
  },

  getById: async (id) => apiRequest(`/budget-allocations/${id}`),

  /**
   * Returns { budget_id, total_budget, committed, spent, available }
   * Use this to show remaining headroom on the PR create form.
   */
  getAvailable: async (budgetId) =>
    apiRequest(`/budget-allocations/${budgetId}/available`),

  /**
   * Immutable ledger for a budget — every commit/release/spend/adjustment row
   * with linked PR/PO numbers. Paginated.
   */
  getTransactions: async (budgetId, params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(`/budget-allocations/${budgetId}/transactions${query ? `?${query}` : ''}`);
  },

  /**
   * Find the most relevant budget for a location.
   * Tries active period first; if none, returns the most recently started budget.
   * Does NOT pass active=1 so a budget just created for a future/past period still appears.
   */
  getForLocation: async (locationId) => {
    const query = new URLSearchParams({
      location_id: locationId,
      per_page:    5,
    }).toString();
    return apiRequest(`/budget-allocations?${query}`);
  },

  create: async (data) =>
    apiRequest('/budget-allocations', { method: 'POST', body: JSON.stringify(data) }),

  update: async (id, data) =>
    apiRequest(`/budget-allocations/${id}`, { method: 'PUT', body: JSON.stringify(data) }),

  delete: async (id) =>
    apiRequest(`/budget-allocations/${id}`, { method: 'DELETE' }),

  getArchived: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/budget-allocations/archived?${query}` : '/budget-allocations/archived');
  },

  restore: async (id) =>
    apiRequest(`/budget-allocations/${id}/restore`, { method: 'POST' }),

  verify: async (id, note) =>
    apiRequest(`/budget-allocations/${id}/verify`, { method: 'PATCH', body: JSON.stringify({ note: note ?? null }) }),

  reject: async (id, reason) =>
    apiRequest(`/budget-allocations/${id}/reject`, { method: 'PATCH', body: JSON.stringify({ reason }) }),
};

/* ======================
   NAV BUNDLES API
====================== */
export const navBundlesAPI = {
  list: async () => apiRequest('/nav-bundles'),
};

/* ======================
   BARCODE SCAN API
====================== */
export const barcodeScanAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/barcode-scans?${query}` : '/barcode-scans');
  },
  create: async (data) =>
    apiRequest('/barcode-scans', { method: 'POST', body: JSON.stringify(data) }),
  /** Smart lookup: FOUND | NOT_FOUND | PENDING_CONSUMABLE */
  lookup: async (barcode) =>
    apiRequest('/barcode/scan', {
      method: 'POST',
      body: JSON.stringify({ barcode: String(barcode || '').trim() }),
    }),
};

/* ======================
   BARCODE ADJUSTMENT API
====================== */
export const barcodeAdjustmentAPI = {
  /** GET /adjustments/receiving-history/{po_id} */
  getReceivingHistory: async (poId) =>
    apiRequest(`/adjustments/receiving-history/${poId}`),

  /** POST /adjustments/preview */
  preview: async (data) =>
    apiRequest('/adjustments/preview', { method: 'POST', body: JSON.stringify(data) }),

  /** POST /adjustments/submit */
  submit: async (data) =>
    apiRequest('/adjustments/submit', { method: 'POST', body: JSON.stringify(data) }),

  /** GET /adjustments/lookup-barcode?barcode=XXX */
  lookupBarcode: async (barcode) =>
    apiRequest(`/adjustments/lookup-barcode?barcode=${encodeURIComponent(barcode)}`),

  /** GET /adjustments/correction-history */
  getCorrectionHistory: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/adjustments/correction-history?${query}` : '/adjustments/correction-history');
  },

  /** GET /adjustments/corrections/{correction_id} */
  getCorrection: async (correctionId) =>
    apiRequest(`/adjustments/corrections/${correctionId}`),

  /** GET /compliance/logs */
  getComplianceLogs: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/compliance/logs?${query}` : '/compliance/logs');
  },
};
