<template>
  <div>
    <div class="tabs">
      <button :class="['tab', { active: activeTab === 'tasks' } ]" @click="activeTab = 'tasks'">Geplante Aufgaben</button>
      <button :class="['tab', { active: activeTab === 'triggers' } ]" @click="activeTab = 'triggers'">Ereignis-Trigger</button>
      <button :class="['tab', { active: activeTab === 'webhook-templates' } ]" @click="activeTab = 'webhook-templates'">Webhook-Vorlagen</button>
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

const activeTab = ref('tasks')
</script>

<style scoped>
.tabs {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}
.tab {
  padding: 0.5rem 0.75rem;
  border: 1px solid #d1d5db;
  background: #f8fafc;
  border-radius: 8px;
  cursor: pointer;
  color: #0f172a;
  font-weight: 600;
}
.tab.active {
  background: #2563eb;
  color: #fff;
  border-color: #1d4ed8;
}
</style>
