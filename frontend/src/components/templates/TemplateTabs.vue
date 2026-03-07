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
  { value: 'attachments', label: tr('nav_tab_attachment_templates', 'Attachment Templates') },
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
  } else if (tabValue === 'attachments') {
    router.push('/moderation/templates/attachment/list')
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
/* Uses global .subtabs and .subtab styles from style.css - no local overrides needed */
</style>
