import { onMounted, watch } from 'vue'

export function useBackgroundImage(settings, mediaBase) {
  function mediaUrl(val) {
    if (!val) return ''
    if (val.startsWith('http://') || val.startsWith('https://')) return val
    return `${mediaBase}${val.startsWith('/') ? '' : '/'}${val}`
  }

  function applyBackgroundImage() {
    if (typeof document === 'undefined') return
    const img = settings?.reservation_page_background_image || ''
    const opacity = Math.min(100, Math.max(0, Number(settings?.reservation_background_opacity ?? 60)))
    const alpha = (100 - opacity) / 100
    if (img) {
      const url = mediaUrl(img)
      const value = `linear-gradient(rgba(255,255,255,${alpha}), rgba(255,255,255,${alpha})), url('${url}')`
      const root = document.body.style
      root.setProperty('--app-bg', value)
      root.setProperty('--app-bg-size', 'cover')
      root.setProperty('--app-bg-position', 'center')
      root.setProperty('--app-bg-repeat', 'no-repeat')
      root.setProperty('--app-bg-attachment', 'fixed')
    } else {
      const root = document.body.style
      root.removeProperty('--app-bg')
      root.removeProperty('--app-bg-size')
      root.removeProperty('--app-bg-position')
      root.removeProperty('--app-bg-repeat')
      root.removeProperty('--app-bg-attachment')
    }
    const cardOpacity = Math.min(100, Math.max(0, Number(settings?.reservation_card_opacity ?? 90))) / 100
    document.body.style.setProperty('--app-card-bg', `rgba(255,255,255,${cardOpacity})`)
  }

  onMounted(applyBackgroundImage)
  watch(() => settings.reservation_page_background_image, applyBackgroundImage)
  watch(() => settings.reservation_background_opacity, applyBackgroundImage)
  watch(() => settings.reservation_card_opacity, applyBackgroundImage)
}
