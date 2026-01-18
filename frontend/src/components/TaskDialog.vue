<template>
  <div class="task-dialog">
    <h3>{{ task && task.id ? 'Aufgabe bearbeiten' : 'Neue Aufgabe' }}</h3>
    <form @submit.prevent="submit">
      <div>
        <label>Typ:
          <span class="help-inline">Wählen Sie die Art der geplanten Aufgabe.</span>
        </label>
        <select v-model="form.type" required>
          <option value="">Bitte wählen…</option>
          <option value="email_broadcast">E-Mail an Teilnehmer</option>
          <option value="close_reservation">Reservierungsliste schließen</option>
          <!-- Weitere Typen hier ergänzen -->
        </select>
      </div>
      <div>
        <label>Ausführungszeit (run_at):</label>
        <input v-model="form.run_at" type="datetime-local" />
      </div>
      <div>
        <label>Event:</label>
        <select v-model="form.reference_id">
          <option :value="null">Alle Events (auch zukünftige)</option>
          <option v-for="ev in events" :key="ev.id" :value="ev.id">
            {{ ev.title }} (ID: {{ ev.id }}, {{ ev.start_at ? (new Date(ev.start_at)).toLocaleString() : '' }})
          </option>
        </select>
      </div>
      <div>
        <label>Relativ zu (z.B. start_at):
          <span class="help-inline">Optional: Feld des Referenzobjekts, z.B. <code>start_at</code> für Event-Startzeit.</span>
        </label>
        <input v-model="form.relative_to" placeholder="start_at" />
      </div>
      <div>
        <label>Offset (Minuten):
          <span class="help-inline">Optional: Zeitverschiebung in Minuten (z.B. <code>-180</code> für 3 Stunden vor dem Ereignis).</span>
        </label>
        <input v-model.number="form.relative_offset_minutes" type="number" />
      </div>
      <div v-if="form.type === 'email_broadcast'">
        <label>E-Mail-Template-ID:</label>
        <input v-model.number="emailTemplateId" type="number" required />
      </div>
      <div>
        <label>Bereits ausgeführt:</label>
        <input v-model="form.executed" type="checkbox" />
      </div>
      <div style="margin-top:1em; display:flex; gap:0.5em; justify-content:flex-end;">
        <IconButton icon="save" label="Speichern" class="primary" type="submit" />
        <IconButton icon="close" label="Abbrechen" class="ghost" type="button" @click="$emit('close')" />
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, watch, computed, onMounted } from 'vue'
import IconButton from './IconButton.vue'
import axios from 'axios'
const props = defineProps({ task: Object })
const emit = defineEmits(['save', 'close'])

const form = ref({
  type: '',
  run_at: '',
  reference_type: 'event',
  reference_id: null,
  relative_to: '',
  relative_offset_minutes: null,
  options: {},
  executed: false,
})

const events = ref([])

onMounted(async () => {
  try {
    const res = await axios.get('/api/admin/events')
    events.value = res.data
  } catch {}
})

watch(() => props.task, (task) => {
  if (task) {
    form.value = { ...task, options: task.options || {}, reference_type: task.reference_type || 'event' }
  } else {
    form.value = {
      type: '',
      run_at: '',
      reference_type: 'event',
      reference_id: null,
      relative_to: '',
      relative_offset_minutes: null,
      options: {},
      executed: false,
    }
  }
}, { immediate: true })

function submit() {
  // Dynamisch options bauen je nach Typ
  const payload = { ...form.value }
  if (form.value.type === 'email_broadcast') {
    payload.options = { ...payload.options, template_id: emailTemplateId.value }
  }
  emit('save', payload)
}
</script>

<style scoped>
.task-dialog {
  background: #fff;
  border: 1px solid #ccc;
  padding: 1em;
  max-width: 400px;
}
.task-dialog label {
  display: block;
  margin-top: 0.5em;
}
.task-dialog input, .task-dialog textarea {
  width: 100%;
}
button.primary {
  background: #2563eb;
  color: #fff;
  border: 1px solid #2563eb;
  border-radius: 6px;
  padding: 0.5em 1.1em;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.15s;
}
button.primary:hover {
  background: #1d4ed8;
}
button.ghost {
  background: #eef2ff;
  color: #1d4ed8;
  border: 1px solid #c7d2fe;
  border-radius: 6px;
  padding: 0.5em 1.1em;
  font-weight: 600;
  cursor: pointer;
}
span.help-inline {
  color: #64748b;
  font-size: 0.95em;
  margin-left: 0.5em;
}
</style>
