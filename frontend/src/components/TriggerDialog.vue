<template>
  <div class="trigger-form">
    <h3>{{ trigger ? tr('trigger_dialog_title_edit') : tr('trigger_dialog_title_new') }}</h3>
    <form @submit.prevent="onSave">
      <label>{{ tr('trigger_dialog_label_event') }}
        <select v-model="form.event_type" required>
          <option v-for="et in eventTypes" :key="et.value" :value="et.value">{{ et.label }}</option>
        </select>
      </label>
      <label>{{ tr('trigger_dialog_label_action') }}
        <select v-model="form.action_type" required>
          <option :value="'email'">{{ tr('trigger_dialog_option_email') }}</option>
          <option :value="'webhook'">{{ tr('trigger_dialog_option_webhook') }}</option>
        </select>
      </label>
      <label v-if="form.action_type === 'email'">{{ tr('trigger_dialog_label_email_template') }}
        <select v-model="form.template_id" required>
          <option value="">–</option>
          <option v-for="tpl in emailTemplates" :key="tpl.id" :value="tpl.id">{{ tpl.name }}</option>
        </select>
      </label>
      <div v-if="form.action_type === 'email'" style="margin:0.7em 0 0.2em;">
        <label style="display:inline-block;margin-right:1em;">
          <input type="checkbox" v-model="form.recipient_attendees" /> {{ tr('trigger_dialog_option_recipient_attendees') }}
        </label>
        <label style="display:inline-block;">
          <input type="checkbox" v-model="form.recipient_waitlist" /> {{ tr('trigger_dialog_option_recipient_waitlist') }}
        </label>
      </div>
      <div v-if="form.action_type === 'email'" style="margin:0.7em 0 0.2em;">
        <label style="display:inline-block;margin-right:1em;">
          <input type="checkbox" v-model="form.recipient_admins" /> {{ tr('trigger_dialog_option_recipient_admins') }}
        </label>
        <label style="display:inline-block;">
          <input type="checkbox" v-model="form.recipient_moderators" /> {{ tr('trigger_dialog_option_recipient_moderators') }}
        </label>
      </div>
      <label v-if="form.action_type === 'email'">{{ tr('trigger_dialog_label_custom_recipients') }}
        <textarea v-model="form.custom_recipients" rows="2" style="width:100%" placeholder="z.B. mail1@example.com, mail2@example.com"></textarea>
      </label>
      <label v-if="form.action_type === 'webhook'">
        {{ tr('trigger_dialog_label_webhook_template') }}
        <span v-if="!webhookTemplates || webhookTemplates.length === 0">{{ tr('trigger_dialog_loading_templates') }}</span>
        <select v-else v-model="form.webhook_template_id" required>
          <option value="">–</option>
          <option v-for="tpl in webhookTemplates" :key="tpl.id" :value="tpl.id">
            {{ tpl.name }}<span v-if="tpl.description"> – {{ tpl.description }}</span>
          </option>
        </select>
      </label>
      <label v-if="form.action_type === 'webhook'">{{ tr('trigger_dialog_label_webhook_url') }}
        <textarea v-model="form.webhook_url" rows="3" style="width:100%"></textarea>
      </label>
      <label>{{ tr('trigger_dialog_label_delay_seconds') }}
        <input type="number" v-model.number="form.delay_seconds" min="0" required />
      </label>
      <label>{{ tr('trigger_dialog_label_cooldown_seconds') }}
        <input type="number" v-model.number="form.cooldown_seconds" min="0" required />
      </label>
      <label>
        <input type="checkbox" v-model="form.active" /> {{ tr('trigger_dialog_label_active') }}
      </label>
      <div style="margin-top:1em;display:flex;gap:0.5em;justify-content:flex-end;">
        <IconButton icon="check" :label="tr('trigger_dialog_button_save')" type="submit" />
        <IconButton icon="close" :label="tr('trigger_dialog_button_cancel')" variant="danger" type="button" @click="$emit('close')" />
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import IconButton from './IconButton.vue'
import { useTranslation } from '../composables/useTranslation'

const { tr } = useTranslation()

const props = defineProps({
  trigger: Object,
  emailTemplates: Array,
  webhookTemplates: Array
})
const emit = defineEmits(['close', 'save'])

const eventTypes = [
  { value: 'reservation_full', label: tr('event_type_reservation_full') },
  { value: 'reservation_disabled', label: tr('event_type_reservation_disabled') },
  { value: 'reservation_enabled', label: tr('event_type_reservation_enabled') },
  { value: 'reservation_added', label: tr('event_type_reservation_added') },
  { value: 'reservation_removed', label: tr('event_type_reservation_removed') },
  { value: 'reservation_canceled', label: tr('event_type_reservation_canceled') },
  { value: 'waitlist_enabled', label: tr('event_type_waitlist_enabled') },
  { value: 'waitlist_disabled', label: tr('event_type_waitlist_disabled') },
  { value: 'waitlist_entry_added', label: tr('event_type_waitlist_entry_added') },
  { value: 'waitlist_entry_removed', label: tr('event_type_waitlist_entry_removed') },
  { value: 'application_error', label: tr('event_type_application_error') }
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
  recipient_admins: false,
  recipient_moderators: false,
  custom_recipients: ''
})

watch(() => props.trigger, (val) => {
  if (val) {
    form.value = {
      recipient_attendees: false,
      recipient_waitlist: false,
      recipient_admins: false,
      recipient_moderators: false,
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
      recipient_admins: false,
      recipient_moderators: false,
      custom_recipients: ''
    }
  }
}, { immediate: true })

function onSave() {
  // Pflichtfeld für webhook_template_id, wenn Aktion webhook
  if (form.value.action_type === 'webhook' && !form.value.webhook_template_id) {
    alert(tr('trigger_dialog_please_select_webhook_template'));
    return;
  }
  emit('save', { ...form.value })
}
</script>

<style scoped>
.trigger-form {
  background: var(--app-card-bg, var(--surface));
  color: var(--text);
  border-radius: 12px;
  padding: 2em;
  margin: 1.5em 0;
  border: 1px solid var(--border-strong);
  box-shadow: 0 12px 30px var(--shadow);
  max-width: 600px;
}
label { display:block; margin:0.7em 0 0.2em; }
</style>
