<template>
  <div class="trigger-form">
    <h3>{{ trigger ? 'Trigger bearbeiten' : 'Neuer Trigger' }}</h3>
    <form @submit.prevent="onSave">
      <label>Ereignis:
        <select v-model="form.event_type" required>
          <option v-for="et in eventTypes" :key="et.value" :value="et.value">{{ et.label }}</option>
        </select>
      </label>
      <label>Aktion:
        <select v-model="form.action_type" required>
          <option value="email">E-Mail</option>
          <option value="webhook">Webhook</option>
        </select>
      </label>
      <label v-if="form.action_type === 'email'">E-Mail-Vorlage:
        <select v-model="form.template_id" required>
          <option value="">–</option>
          <option v-for="tpl in emailTemplates" :key="tpl.id" :value="tpl.id">{{ tpl.name }}</option>
        </select>
      </label>
      <div v-if="form.action_type === 'email'" style="margin:0.7em 0 0.2em;">
        <label style="display:inline-block;margin-right:1em;">
          <input type="checkbox" v-model="form.recipient_attendees" /> An alle Teilnehmer
        </label>
        <label style="display:inline-block;">
          <input type="checkbox" v-model="form.recipient_waitlist" /> An alle Warteliste
        </label>
      </div>
      <label v-if="form.action_type === 'email'">Weitere Empfänger (Komma oder Zeilenumbruch getrennt):
        <textarea v-model="form.custom_recipients" rows="2" style="width:100%" placeholder="z.B. mail1@example.com, mail2@example.com"></textarea>
      </label>
      <label v-if="form.action_type === 'webhook'">
        Webhook-Vorlage:
        <span v-if="!webhookTemplates || webhookTemplates.length === 0">Lade Vorlagen ...</span>
        <select v-else v-model="form.webhook_template_id" required>
          <option value="">–</option>
          <option v-for="tpl in webhookTemplates" :key="tpl.id" :value="tpl.id">
            {{ tpl.name }}<span v-if="tpl.description"> – {{ tpl.description }}</span>
          </option>
        </select>
      </label>
      <label v-if="form.action_type === 'webhook'">Webhook-URL (optional, überschreibt Vorlage):
        <textarea v-model="form.webhook_url" rows="3" style="width:100%"></textarea>
      </label>
      <label>Verzögerung (Sekunden):
        <input type="number" v-model.number="form.delay_seconds" min="0" required />
      </label>
      <label>Cooldown (Sekunden):
        <input type="number" v-model.number="form.cooldown_seconds" min="0" required />
      </label>
      <label>
        <input type="checkbox" v-model="form.active" /> Aktiv
      </label>
      <div style="margin-top:1em;display:flex;gap:1em;">
        <button type="submit" class="success">Speichern</button>
        <button type="button" @click="$emit('close')">Abbrechen</button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
const props = defineProps({
  trigger: Object,
  emailTemplates: Array,
  webhookTemplates: Array
})
const emit = defineEmits(['close', 'save'])

const eventTypes = [
  { value: 'reservation_full', label: 'Reservation list full' },
  { value: 'reservation_disabled', label: 'Reservation disabled' },
  { value: 'reservation_enabled', label: 'Reservation enabled' },
  { value: 'waitlist_enabled', label: 'Waitlist enabled' },
  { value: 'waitlist_disabled', label: 'Waitlist disabled' }
]


const form = ref({
  event_type: '',
  action_type: 'email',
  template_id: '',
  webhook_template_id: '',
  webhook_url: '',
  delay_seconds: 0,
  cooldown_seconds: 0,
  active: true,
  recipient_attendees: false,
  recipient_waitlist: false,
  custom_recipients: ''
})

watch(() => props.trigger, (val) => {
  if (val) {
    form.value = {
      recipient_attendees: false,
      recipient_waitlist: false,
      custom_recipients: '',
      webhook_template_id: '',
      ...val
    }
  } else {
    form.value = {
      event_type: '',
      action_type: 'email',
      template_id: '',
      webhook_template_id: '',
      webhook_url: '',
      delay_seconds: 0,
      cooldown_seconds: 0,
      active: true,
      recipient_attendees: false,
      recipient_waitlist: false,
      custom_recipients: ''
    }
  }
}, { immediate: true })

function onSave() {
  // Pflichtfeld für webhook_template_id, wenn Aktion webhook
  if (form.value.action_type === 'webhook' && !form.value.webhook_template_id) {
    alert('Bitte eine Webhook-Vorlage auswählen.');
    return;
  }
  emit('save', { ...form.value })
}
</script>

<style scoped>
.trigger-form {
  background: #fff;
  border-radius: 10px;
  padding: 2em;
  margin: 1.5em 0;
  box-shadow: 0 2px 8px #0001;
  max-width: 600px;
}
label { display:block; margin:0.7em 0 0.2em; }
</style>
