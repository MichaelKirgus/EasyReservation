<template>
  <div class="dialog-bg">
    <div class="dialog">
      <h3>{{ trigger ? 'Edit Trigger' : 'New Trigger' }}</h3>
      <form @submit.prevent="onSave">
        <label>Event:
          <select v-model="form.event_type" required>
            <option v-for="et in eventTypes" :key="et.value" :value="et.value">{{ et.label }}</option>
          </select>
        </label>
        <label>Action:
          <select v-model="form.action_type" required>
            <option value="email">E-Mail</option>
            <option value="webhook">Webhook</option>
          </select>
        </label>
        <label v-if="form.action_type === 'email'">E-Mail Template:
          <select v-model="form.template_id" required>
            <option value="">–</option>
            <option v-for="tpl in emailTemplates" :key="tpl.id" :value="tpl.id">{{ tpl.name }}</option>
          </select>
        </label>
        <div v-if="form.action_type === 'email'" style="margin:0.7em 0 0.2em;">
          <label style="display:inline-block;margin-right:1em;">
            <input type="checkbox" v-model="form.recipient_attendees" /> To all attendees
          </label>
          <label style="display:inline-block;">
            <input type="checkbox" v-model="form.recipient_waitlist" /> To all waitlist
          </label>
        </div>
        <label v-if="form.action_type === 'email'">Additional recipients (comma or line separated):
          <textarea v-model="form.custom_recipients" rows="2" style="width:100%" placeholder="e.g. mail1@example.com, mail2@example.com"></textarea>
        </label>
        <label v-if="form.action_type === 'webhook'">Webhook URL:
          <textarea v-model="form.webhook_url" rows="3" style="width:100%" required></textarea>
        </label>
        <label>Delay (seconds):
          <input type="number" v-model.number="form.delay_seconds" min="0" required />
        </label>
        <label>Cooldown (seconds):
          <input type="number" v-model.number="form.cooldown_seconds" min="0" required />
        </label>
        <label>
          <input type="checkbox" v-model="form.active" /> Active
        </label>
        <div style="margin-top:1em;display:flex;gap:1em;">
          <button type="submit" class="success">Save</button>
          <button type="button" @click="$emit('close')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, computed } from 'vue'
const props = defineProps({
  trigger: Object,
  emailTemplates: Array
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
      ...val
    }
  } else {
    form.value = {
      event_type: '',
      action_type: 'email',
      template_id: '',
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
  emit('save', { ...form.value })
}
</script>

<style scoped>
.dialog-bg {
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0,0,0,0.2);
  z-index: 1000;
}
.dialog {
  background: #fff;
  padding: 2em;
  border-radius: 8px;
  max-width: 400px;
  margin: 5vh auto;
  box-shadow: 0 2px 16px rgba(0,0,0,0.15);
}
label { display:block; margin:0.7em 0 0.2em; }
</style>
