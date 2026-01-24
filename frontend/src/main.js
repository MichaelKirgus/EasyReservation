import { createApp } from 'vue'
import './style.css'
import App from './App.vue'
import router from './router'

const url = new URL(window.location.href)
const urlParams = new URLSearchParams(window.location.search)
const siteToken = urlParams.get('t')
if (siteToken) {
  localStorage.setItem('site_token', siteToken)
  urlParams.delete('t')
  window.history.replaceState({}, '', url.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '') + url.hash)
  window.location.reload()
}

createApp(App).use(router).mount('#app')
