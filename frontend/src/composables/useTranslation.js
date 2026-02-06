import { ref, computed } from 'vue'
import api from '../api'

// Global translation state
const translations = ref({})
const currentLang = ref('de')
const loading = ref(false)
const error = ref(null)

/**
 * Fetch translations for a specific language
 * @param {string} lang - Language code (e.g., 'en', 'de')
 * @returns {Promise<Object>} Translations object
 */
async function fetchTranslations(lang) {
  if (!lang) {
    throw new Error('Language code is required')
  }

  loading.value = true
  error.value = null

  try {
    const response = await api.get(`/translations/${lang}`)
    translations.value = response.data || {}
    currentLang.value = lang
    return response.data
  } catch (err) {
    console.error(`Failed to fetch translations for language ${lang}:`, err)
    error.value = err
    // Return empty object on failure, but keep previous translations if they exist
    return translations.value
  } finally {
    loading.value = false
  }
}

/**
 * Get translated text for a key
 * @param {string} key - Translation key
 * @param {string} fallback - Fallback text if key not found
 * @returns {string} Translated text or fallback
 */
function tr(key, fallback = '') {
  if (!key) return fallback
  
  // Return translation if exists, otherwise fallback to provided fallback or key itself
  return translations.value[key] || fallback || key
}

/**
 * Get current language code
 * @returns {string} Current language code
 */
function getLang() {
  return currentLang.value
}

/**
 * Set current language and fetch translations
 * @param {string} lang - Language code to switch to
 * @returns {Promise<void>}
 */
async function setLang(lang) {
  if (lang === currentLang.value) return
  
  await fetchTranslations(lang)
}

/**
 * Check if translation exists for a key
 * @param {string} key - Translation key
 * @returns {boolean} Whether translation exists
 */
function hasTranslation(key) {
  return translations.value.hasOwnProperty(key)
}

// Computed properties for reactivity
const isLoading = computed(() => loading.value)
const currentLanguage = computed(() => currentLang.value)
const translationError = computed(() => error.value)

export function useTranslation() {
  return {
    // State
    translations,
    currentLang,
    loading,
    error,
    
    // Methods
    fetchTranslations,
    tr,
    getLang,
    setLang,
    hasTranslation,
    
    // Computed
    isLoading,
    currentLanguage,
    translationError
  }
}