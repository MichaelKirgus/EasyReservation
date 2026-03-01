<template>
  <div>
    <div class="admin-tabs">
      <button :class="['admin-tab-button', { active: activeTab === 'tasks' } ]" @click="activeTab = 'tasks'">{{ tr('scheduled_tasks_title') }}</button>
      <button :class="['admin-tab-button', { active: activeTab === 'triggers' } ]" @click="activeTab = 'triggers'">{{ tr('admin_event_triggers_title') }}</button>
      <button :class="['admin-tab-button', { active: activeTab === 'webhook-templates' } ]" @click="activeTab = 'webhook-templates'">{{ tr('admin_webhook_templates_title') }}</button>
    </div>
    <div v-if="activeTab==='tasks'">
      <AdminScheduledTasksTasks />
    </div>
    <div v-else-if="activeTab==='triggers'">
      <AdminEventTriggers />
    </div>
    <div v-else>
      <AdminWebhookTemplates />
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import AdminScheduledTasksTasks from './AdminScheduledTasksTasks.vue'
import AdminEventTriggers from './AdminEventTriggers.vue'
import AdminWebhookTemplates from './AdminWebhookTemplates.vue'
import { useTranslation } from '../composables/useTranslation'

const { tr } = useTranslation()
const activeTab = ref('tasks')
</script>

<style scoped>
</style>
