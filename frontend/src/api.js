import axios from 'axios';

// Erstelle eine Axios-Instanz (optional, falls du eine eigene Instanz möchtest)
const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE || '/api',
});

let sessionCheckInFlight = null;

function hasAdminSessionMarker() {
  return !!(localStorage.getItem('admin_auth_session') || sessionStorage.getItem('admin_auth_session'));
}

async function shouldForceLogout(error) {
  if (!hasAdminSessionMarker()) return false;
  const status = error?.response?.status;
  if (![401, 403].includes(status)) return false;

  const requestUrl = error?.config?.url || '';
  if (typeof requestUrl === 'string' && (requestUrl.includes('/auth/login') || requestUrl.includes('/auth/logout') || requestUrl.includes('/self-2fa/status'))) {
    return false;
  }

  if (sessionCheckInFlight) return sessionCheckInFlight;

  const base = import.meta.env.VITE_API_BASE || '/api';
  sessionCheckInFlight = axios.get(`${base}/self-2fa/status`, {
    withCredentials: true,
    headers: { Accept: 'application/json' },
  }).then(() => false).catch(() => true).finally(() => {
    sessionCheckInFlight = null;
  });

  return sessionCheckInFlight;
}

// Füge einen Request-Interceptor hinzu
api.interceptors.request.use((config) => {
  // Site-Token aus localStorage holen
  const siteToken = localStorage.getItem('site_token');
  if (siteToken) {
    config.headers['X-Site-Token'] = siteToken;
  }
  // Admin auth is handled via httpOnly cookie — no X-Api-Key header needed
  // Public API key (guest site token) is still sent explicitly
  const publicApiKey = localStorage.getItem('public_api_key') || '';
  if (publicApiKey) {
    config.headers['X-Api-Key'] = publicApiKey;
  }
  config.withCredentials = true;
  return config;
}, (error) => Promise.reject(error));

api.interceptors.response.use((response) => response, async (error) => {
  if (await shouldForceLogout(error)) {
    window.dispatchEvent(new CustomEvent('admin-session-expired', {
      detail: { status: error?.response?.status || 0 }
    }));
  }
  return Promise.reject(error);
});

export default api;
