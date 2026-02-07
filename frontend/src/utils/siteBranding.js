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

function clampOpacity(primary, fallback, defaultValue) {
  const raw = primary ?? fallback ?? defaultValue
  const num = Math.min(100, Math.max(0, Number(raw)))
  return Number.isFinite(num) ? num : defaultValue
}

function clampBrightness(val, defaultValue = 100) {
  const num = Math.min(200, Math.max(0, Number(val ?? defaultValue)))
  return Number.isFinite(num) ? num : defaultValue
}

function toRgba(color, alpha = 1) {
  const a = Math.min(1, Math.max(0, Number(alpha) || 0))
  if (!color || typeof color !== 'string') return ''
  const trimmed = color.trim()
  const hexMatch = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(trimmed)
  if (hexMatch) {
    let hex = hexMatch[1]
    if (hex.length === 3) hex = hex.split('').map(ch => ch + ch).join('')
    const int = parseInt(hex, 16)
    const r = (int >> 16) & 255
    const g = (int >> 8) & 255
    const b = int & 255
    return `rgba(${r},${g},${b},${a})`
  }
  const rgbMatch = /^rgba?\(([^)]+)\)$/i.exec(trimmed)
  if (rgbMatch) {
    const parts = rgbMatch[1].split(',').map(p => p.trim())
    const [r, g, b] = parts
    return `rgba(${r},${g},${b},${a})`
  }
  return a === 1 ? trimmed : trimmed
}

function brightnessOverlay(brightness, isLightTheme) {
  const delta = brightness - 100
  if (delta === 0) return ''
  const amount = Math.min(1, Math.abs(delta) / 100)
  const overlayColor = delta < 0 ? '0,0,0' : (isLightTheme ? '255,255,255' : '226,232,240')
  return `linear-gradient(rgba(${overlayColor},${amount}), rgba(${overlayColor},${amount}))`
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
  const root = document.documentElement.style
  const img = settings.reservation_page_background_image || ''
  const bgOpacityLight = clampOpacity(settings?.reservation_background_opacity_light, settings?.reservation_background_opacity, 60)
  const bgOpacityDark = clampOpacity(settings?.reservation_background_opacity_dark, settings?.reservation_background_opacity, 60)
  const lightAlpha = (100 - bgOpacityLight) / 100
  const darkAlpha = (100 - bgOpacityDark) / 100

  const brightnessLight = clampBrightness(settings?.reservation_background_brightness_light, 100)
  const brightnessDark = clampBrightness(settings?.reservation_background_brightness_dark, 100)

  const lightBrightnessOverlay = brightnessOverlay(brightnessLight, true)
  const darkBrightnessOverlay = brightnessOverlay(brightnessDark, false)

  const cardOpacityLight = clampOpacity(settings?.reservation_card_opacity_light, settings?.reservation_card_opacity, 90) / 100
  const cardOpacityDark = clampOpacity(settings?.reservation_card_opacity_dark, settings?.reservation_card_opacity, 90) / 100

  const lightCardColor = settings.reservation_card_color_light || '#ffffff'
  const darkCardColor = settings.reservation_card_color_dark || '#1e293b'
  const lightTextColor = settings.reservation_text_color_light || '#0f172a'
  const darkTextColor = settings.reservation_text_color_dark || '#e2e8f0'

  // Update theme-aware card/text colors with per-theme opacity
  root.setProperty('--card-light', toRgba(lightCardColor, cardOpacityLight))
  root.setProperty('--card-dark', toRgba(darkCardColor, cardOpacityDark))
  root.setProperty('--app-card-bg-light', toRgba(lightCardColor, cardOpacityLight))
  root.setProperty('--app-card-bg-dark', toRgba(darkCardColor, cardOpacityDark))
  root.setProperty('--text-light', lightTextColor)
  root.setProperty('--text-dark', darkTextColor)

  if (img) {
    const url = mediaUrl(img, mediaBase)
    const lightLayers = [
      `linear-gradient(rgba(255,255,255,${lightAlpha}), rgba(255,255,255,${lightAlpha}))`,
      lightBrightnessOverlay,
      `url('${url}')`,
    ].filter(Boolean)
    const darkLayers = [
      `linear-gradient(rgba(15,23,42,${darkAlpha}), rgba(15,23,42,${darkAlpha}))`,
      darkBrightnessOverlay,
      `url('${url}')`,
    ].filter(Boolean)
    root.setProperty('--app-bg-light', lightLayers.join(', '))
    root.setProperty('--app-bg-dark', darkLayers.join(', '))
    root.setProperty('--app-bg-size', 'cover')
    root.setProperty('--app-bg-position', 'center')
    root.setProperty('--app-bg-repeat', 'no-repeat')
    root.setProperty('--app-bg-attachment', 'fixed')
  } else {
    root.removeProperty('--app-bg-light')
    root.removeProperty('--app-bg-dark')
    root.removeProperty('--app-bg-size')
    root.removeProperty('--app-bg-position')
    root.removeProperty('--app-bg-repeat')
    root.removeProperty('--app-bg-attachment')
  }
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
