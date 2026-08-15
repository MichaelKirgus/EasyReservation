<template>
  <div class="trigger-form">
    <h3>{{ form.id ? tr('admin_event_triggers_button_edit') : tr('admin_event_triggers_button_new') }}</h3>

    <label>{{ tr('admin_event_triggers_column_event_type') }}</label>
    <select v-model="form.event_type" required>
      <option value="" disabled>-- {{ tr('scheduled_tasks_select_placeholder') }} --</option>
      <option v-for="eventType in eventTypes" :key="eventType.value" :value="eventType.value">
        {{ eventType.label }}
      </option>
    </select>

    <label>{{ tr('admin_event_triggers_column_action_list') }}</label>
    <select v-model.number="form.action_list_id" required>
      <option value="" disabled>-- {{ tr('scheduled_tasks_select_placeholder') }} --</option>
      <option v-for="list in actionLists" :key="list.id" :value="list.id">
        {{ list.name }}
      </option>
    </select>

    <label>{{ tr('admin_event_triggers_column_delay_seconds') }}</label>
    <input v-model.number="form.delay_seconds" type="number" min="0" />

    <label>{{ tr('admin_event_triggers_column_cooldown_seconds') }}</label>
    <input v-model.number="form.cooldown_seconds" type="number" min="0" />

    <label style="display:flex;align-items:center;gap:0.5em;">
      <input v-model="form.active" type="checkbox" />
      {{ tr('admin_event_triggers_column_active') }}
    </label>

    <div style="display:flex;justify-content:flex-end;gap:0.5em;margin-top:1em;">
      <IconButton icon="save" :label="tr('admin_action_lists_button_save')" class="primary" variant="success" @click="onSave" />
      <IconButton icon="close" :label="tr('admin_action_lists_button_cancel')" class="ghost" @click="emit('close')" />
    </div>
  </div>
</template>

<script setup>
import { ref, watch, computed } from 'vue'
import IconButton from './IconButton.vue'
import { useTranslation } from '../composables/useTranslation'

const { tr } = useTranslation()

const props = defineProps({
  trigger: Object,
  actionLists: Array
})
const emit = defineEmits(['close', 'save'])

const eventTypes = computed(() => [
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
  { value: 'application_error', label: tr('event_type_application_error') },
  { value: 'setting_changed', label: tr('event_type_setting_changed') },
  { value: 'login_succeeded', label: tr('event_type_login_succeeded') },
  { value: 'login_failed', label: tr('event_type_login_failed') },
  { value: 'logout', label: tr('event_type_logout') }
])

const form = ref({
  event_type: '',
  action_list_id: '',
  delay_seconds: 0,
  cooldown_seconds: 0,
  active: true
})

watch(() => props.trigger, (val) => {
  if (val) {
    form.value = {
      ...val
    }
  } else {
    form.value = {
      event_type: '',
      action_list_id: '',
      delay_seconds: 0,
      cooldown_seconds: 0,
      active: true
    }
  }
}, { immediate: true })

function onSave() {
  if (!form.value.event_type || !form.value.action_list_id) {
    return
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
