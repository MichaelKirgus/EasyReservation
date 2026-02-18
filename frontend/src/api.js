import axios from 'axios';

// Erstelle eine Axios-Instanz (optional, falls du eine eigene Instanz möchtest)
const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE || '/api',
});

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

export default api;
