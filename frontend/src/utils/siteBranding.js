const defaultTitle = 'EasyReservation'

const apiBase = import.meta.env.VITE_API_BASE || '/api'
const mediaBaseEnv = import.meta.env.VITE_MEDIA_BASE || (() => {
  if (apiBase.startsWith('http')) return new URL(apiBase).origin
  return window.location.origin
})()

function mediaUrl(val, mediaBase = mediaBaseEnv) {
  if (!val) return ''
  if (val.startsWith('http://') || val.startsWith('https://')) return val
  return `${mediaBase}${val.startsWith('/') ? '' : '/'}${val}`
}

function updateFavicon(href, mediaBase = mediaBaseEnv) {
  if (typeof document === 'undefined') return
  const link = document.querySelector("link[rel*='icon']") || document.createElement('link')
  link.rel = 'icon'
  link.href = mediaUrl(href || '/favicon.ico', mediaBase)
  if (!link.parentNode) document.head.appendChild(link)
}

function updateTitle(title) {
  if (typeof document === 'undefined') return
  document.title = title || defaultTitle
}

function applyBackgroundImage(settings = {}, mediaBase = mediaBaseEnv) {
  if (typeof document === 'undefined') return
  const root = document.body.style
  const img = settings.reservation_page_background_image || ''
  const opacity = Math.min(100, Math.max(0, Number(settings?.reservation_background_opacity ?? 60)))
  const alpha = (100 - opacity) / 100

  if (img) {
    const url = mediaUrl(img, mediaBase)
    const value = `linear-gradient(rgba(255,255,255,${alpha}), rgba(255,255,255,${alpha})), url('${url}')`
    root.setProperty('--app-bg', value)
    root.setProperty('--app-bg-size', 'cover')
    root.setProperty('--app-bg-position', 'center')
    root.setProperty('--app-bg-repeat', 'no-repeat')
    root.setProperty('--app-bg-attachment', 'fixed')
  } else {
    root.removeProperty('--app-bg')
    root.removeProperty('--app-bg-size')
    root.removeProperty('--app-bg-position')
    root.removeProperty('--app-bg-repeat')
    root.removeProperty('--app-bg-attachment')
  }

  const cardOpacity = Math.min(100, Math.max(0, Number(settings?.reservation_card_opacity ?? 90))) / 100
  root.setProperty('--app-card-bg', `rgba(255,255,255,${cardOpacity})`)
}

export function applySiteBranding(settings = {}, opts = {}) {
  const mediaBase = opts.mediaBase || mediaBaseEnv
  const title = settings.reservation_page_title || settings.reservation_name || opts.fallbackTitle || defaultTitle
  updateTitle(title)
  if (settings.reservation_page_favicon || opts.favicon) {
    updateFavicon(settings.reservation_page_favicon || opts.favicon, mediaBase)
  }
  applyBackgroundImage(settings, mediaBase)
}

export function getMediaBaseFallback() {
  return mediaBaseEnv
}

export { applyBackgroundImage }
