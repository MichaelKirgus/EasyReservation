<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTranslation } from '../../composables/useTranslation'

const props = defineProps({
  activeTab: { type: String, default: 'email' }
})

const emit = defineEmits(['update:activeTab'])

const route = useRoute()
const router = useRouter()
const { tr } = useTranslation()

const tabs = computed(() => [
  { value: 'send', label: tr('nav_tab_email_send', 'Email Send') },
  { value: 'email', label: tr('nav_tab_email_templates', 'Email Templates') },
  { value: 'ical', label: tr('nav_tab_ical_templates', 'iCal Templates') },
])

function switchTab(tabValue) {
  emit('update:activeTab', tabValue)
  
  // Update route based on active tab
  if (tabValue === 'send') {
    router.push('/moderation/templates/email/send')
  } else if (tabValue === 'email') {
    router.push('/moderation/templates/email/list')
  } else if (tabValue === 'ical') {
    router.push('/moderation/templates/ical/list')
  }
}
</script>

<template>
  <div class="subtabs">
    <button
      v-for="tab in tabs"
      :key="tab.value"
      :class="['subtab', { active: activeTab === tab.value }]"
      @click="switchTab(tab.value)"
    >
      {{ tab.label }}
    </button>
  </div>
</template>

<style scoped>
.subtabs { display: flex; gap: 0.35rem; margin: 0.25rem 0; }
.subtab { padding: 0.45rem 0.7rem; border: 1px solid var(--border); background: var(--surface-muted); border-radius: 6px; cursor: pointer; color: var(--text); }
.subtab.active { background: var(--primary); color: var(--primary-contrast); border-color: var(--primary); }
</style>
