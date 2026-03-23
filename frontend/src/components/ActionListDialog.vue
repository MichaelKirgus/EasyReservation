<template>
  <div class="action-list-dialog">
    <!-- Main Action List Form -->
    <label>{{ tr('admin_action_lists_field_name') }}</label>
    <input v-model="form.name" type="text" required />
    
    <label>{{ tr('admin_action_lists_field_description') }}</label>
    <textarea v-model="form.description" rows="3"></textarea>
    
    <label style="display:flex;align-items:center;">
      <input v-model="form.active" type="checkbox" />
      {{ tr('admin_action_lists_field_active') }}
    </label>
    
    <label style="display:flex;align-items:center;">
      <input v-model="form.moderation_selectable" type="checkbox" />
      {{ tr('admin_action_lists_field_moderation_selectable') }}
    </label>

    <!-- Actions Section -->
    <h3 style="margin-top:1em;">{{ tr('admin_action_lists_section_actions') }}</h3>
    
    <div class="actions-list">
      <div v-for="(action, index) in form.actions" :key="action.id || 'new-' + index" class="action-item">
        <div style="display:flex;align-items:center;gap:0.5em;">
          <IconButton icon="arrowUp" :label="tr('admin_action_lists_button_move_up')" variant="ghost" @click="moveActionUp(index)" :disabled="index === 0" />
          <IconButton icon="arrowDown" :label="tr('admin_action_lists_button_move_down')" variant="ghost" @click="moveActionDown(index)" :disabled="index === form.actions.length - 1" />
          <span style="font-weight:bold;">{{ tr('admin_action_lists_action_number', '', { number: index + 1 }) }}</span>
        </div>

        <label>{{ tr('admin_action_lists_field_type') }}</label>
        <select v-model="action.type">
          <option value="email">{{ tr('admin_action_lists_option_email') }}</option>
          <option value="webhook">{{ tr('admin_action_lists_option_webhook') }}</option>
          <option value="change_setting">{{ tr('admin_action_lists_option_change_setting') }}</option>
          <option value="remove_attendees_from_reservation_list">{{ tr('admin_action_lists_option_remove_attendees_from_reservation_list') }}</option>
          <option value="remove_attendees_from_waitlist">{{ tr('admin_action_lists_option_remove_attendees_from_waitlist') }}</option>
          <option value="remove_mail_validation_ip_rate_limits">{{ tr('admin_action_lists_option_remove_mail_validation_ip_rate_limits') }}</option>
        </select>

        <!-- Email Action Config -->
        <div v-if="action.type === 'email'">
          <label>{{ tr('admin_action_lists_field_email_template') }}</label>
          <select v-model="action.config.template_id">
            <option value="">-- {{ tr('admin_action_lists_select_template') }} --</option>
            <option v-for="tpl in emailTemplates" :key="tpl.id" :value="tpl.id">{{ tpl.name }}</option>
          </select>

          <label>{{ tr('admin_action_lists_field_recipients') }}</label>
          <div style="display:flex;flex-direction:column;gap:0.5em;">
            <label style="display:flex;align-items:center;">
              <input v-model="action.config.recipients.attendees" type="checkbox" />
              {{ tr('admin_action_lists_recipient_attendees') }}
            </label>
            <label style="display:flex;align-items:center;">
              <input v-model="action.config.recipients.waitlist" type="checkbox" />
              {{ tr('admin_action_lists_recipient_waitlist') }}
            </label>
            <label style="display:flex;align-items:center;">
              <input v-model="action.config.recipients.admins" type="checkbox" />
              {{ tr('admin_action_lists_recipient_admins') }}
            </label>
            <label style="display:flex;align-items:center;">
              <input v-model="action.config.recipients.moderators" type="checkbox" />
              {{ tr('admin_action_lists_recipient_moderators') }}
            </label>
          </div>

          <label>{{ tr('admin_action_lists_field_custom_recipients') }}</label>
          <textarea v-model="action.config.recipients.custom" rows="3" placeholder="user@example.com"></textarea>
        </div>

        <!-- Webhook Action Config -->
        <div v-if="action.type === 'webhook'">
          <label>{{ tr('admin_action_lists_field_webhook_template') }}</label>
          <select v-model="action.config.webhook_template_id">
            <option value="">-- {{ tr('admin_action_lists_select_template') }} --</option>
            <option v-for="tpl in webhookTemplates" :key="tpl.id" :value="tpl.id">{{ tpl.name }}</option>
          </select>

          <label>{{ tr('admin_action_lists_field_payload_override') }}</label>
          <textarea v-model="action.config.payload_override" rows="3" placeholder='{"key": "value"}'></textarea>
        </div>

        <!-- Change Setting Action Config -->
        <div v-if="action.type === 'change_setting'">
          <label>{{ tr('admin_action_lists_field_setting_key') }}</label>
          <select v-model="action.config.setting_key">
            <option value="">-- {{ tr('admin_action_lists_select_setting') }} --</option>
            <option v-for="key in settingKeys" :key="key">{{ key }}</option>
          </select>

          <label>{{ tr('admin_action_lists_field_setting_value') }}</label>
          <input v-model="action.config.setting_value" type="text" />
        </div>

        <!-- Remove Attendees from Reservation List Action Config -->
        <div v-if="action.type === 'remove_attendees_from_reservation_list'">
          <label style="display:flex;align-items:center;">
            <input v-model="action.config.send_notification" type="checkbox" />
            {{ tr('admin_action_lists_option_remove_attendees_send_notification') }}
          </label>
        </div>

        <!-- Remove Attendees from Waitlist Action Config -->
        <div v-if="action.type === 'remove_attendees_from_waitlist'">
          <label style="display:flex;align-items:center;">
            <input v-model="action.config.send_notification" type="checkbox" />
            {{ tr('admin_action_lists_option_remove_waitlist_send_notification') }}
          </label>
        </div>

        <!-- Remove Mail Validation IP Rate Limits Action Config -->
        <div v-if="action.type === 'remove_mail_validation_ip_rate_limits'">
          <p>{{ tr('admin_action_lists_option_remove_mail_validation_ip_rate_limits') }}</p>
          <small>{{ tr('admin_action_lists_field_setting_value') }}</small>
        </div>

        <div style="display:flex;gap:0.5em;margin-top:1em;justify-content:flex-end;">
          <IconButton icon="trash" :label="tr('admin_action_lists_button_delete_action')" class="ghost" variant="danger" @click="deleteAction(index)" />
        </div>
      </div>
      
      <div class="action-item">
        <div style="display:flex;align-items:center;gap:0.5em;">
          <IconButton icon="plus" :label="tr('admin_action_lists_button_add_action')" class="primary" variant="success" @click="addNewAction" />
        </div>
      </div>
    </div>

    <!-- Submit Buttons -->
    <div style="display:flex;gap:0.5em;margin-top:1em;justify-content:flex-end;">
      <IconButton icon="save" :label="tr('admin_action_lists_button_save')" type="submit" class="primary success" @click="submit" />
      <IconButton icon="close" :label="tr('admin_action_lists_button_cancel')" variant="ghost" type="button" @click="close" />
    </div>
  </div>
</template>

<script setup>
import { ref, watch, computed, onMounted } from 'vue'
import IconButton from './IconButton.vue'
import axios from 'axios'
import { buildAdminHeaders } from '../utils/adminApi'
import { useTranslation } from '../composables/useTranslation'

const { tr } = useTranslation()

function apiConfig() {
  return { headers: buildAdminHeaders() }
}

const props = defineProps({ actionList: Object })
const emit = defineEmits(['save', 'close'])

const form = ref({
  name: '',
  description: '',
  active: true,
  actions: [],
})

const emailTemplates = ref([])
const webhookTemplates = ref([])
const settingKeys = ref([])

onMounted(async () => {
  try {
    const tplRes = await axios.get('/api/admin/email-templates', apiConfig())
    emailTemplates.value = tplRes.data
  } catch {}
  
  try {
    const whRes = await axios.get('/api/admin/webhook-templates', apiConfig())
    webhookTemplates.value = whRes.data
  } catch {}
  
  try {
    const keysRes = await axios.get('/api/admin/settings-keys', apiConfig())
    settingKeys.value = keysRes.data
  } catch {}
})

watch(() => props.actionList, (actionList) => {
  if (actionList) {
    form.value = JSON.parse(JSON.stringify({
      ...actionList,
      actions: actionList.actions || []
    }))
  } else {
    form.value = {
      name: '',
      description: '',
      active: true,
      actions: [],
    }
  }
}, { immediate: true })

function addNewAction() {
  form.value.actions.push({
    type: 'email',
    config: {
      template_id: '',
      recipients: {
        attendees: false,
        waitlist: false,
        admins: false,
        moderators: false,
        custom: ''
      },
      webhook_template_id: '',
      payload_override: '',
      setting_key: '',
      setting_value: ''
    },
    sort_order: form.value.actions.length
  })
}

function deleteAction(index) {
  if (form.value.actions[index].id) {
    // Delete existing action via API
    loading.value = true
    axios.delete(`/api/admin/action-lists/${form.value.id}/actions/${form.value.actions[index].id}`, apiConfig())
      .then(() => {
        form.value.actions.splice(index, 1)
      })
      .finally(() => { loading.value = false })
  } else {
    // Delete new action from array
    form.value.actions.splice(index, 1)
  }
}

function moveActionUp(index) {
  if (index > 0) {
    const temp = form.value.actions[index]
    form.value.actions[index] = form.value.actions[index - 1]
    form.value.actions[index - 1] = temp
    // Update sort_order values
    form.value.actions.forEach((action, i) => action.sort_order = i)
  }
}

function moveActionDown(index) {
  if (index < form.value.actions.length - 1) {
    const temp = form.value.actions[index]
    form.value.actions[index] = form.value.actions[index + 1]
    form.value.actions[index + 1] = temp
    // Update sort_order values
    form.value.actions.forEach((action, i) => action.sort_order = i)
  }
}

function submit() {
  if (!form.value.name) {
    alert(tr('admin_action_lists_name_required'))
    return
  }

  const payload = { ...form.value }
  
  emit('save', payload)
}

function close() {
  emit('close')
}
</script>

<style scoped>
.action-list-dialog {
  background: var(--app-card-bg, var(--surface));
  color: var(--text);
  border: 1px solid var(--border-strong);
  padding: 1.25em;
  max-width: 600px;
  border-radius: 12px;
  box-shadow: 0 12px 30px var(--shadow);
}
.action-list-dialog label {
  display: block;
  margin-top: 0.5em;
}
.action-list-dialog input, .action-list-dialog textarea, .action-list-dialog select {
  width: 100%;
}
.actions-list {
  margin-top: 1em;
}
.action-item {
  background: var(--app-card-bg, var(--card));
  color: var(--text);
  border: 1px solid var(--border-strong);
  padding: 1em;
  margin-bottom: 0.5em;
  border-radius: 8px;
}
</style>
