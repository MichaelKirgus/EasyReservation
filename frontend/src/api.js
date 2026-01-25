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
  // Optional: API-Key für Admin/User
  const adminKey = localStorage.getItem('admin_api_key') || '';
  const publicApiKey = localStorage.getItem('public_api_key') || '';
  const apiKey = adminKey || publicApiKey;
  if (apiKey) {
    config.headers['X-Api-Key'] = apiKey;
  }
  return config;
}, (error) => Promise.reject(error));

export default api;
