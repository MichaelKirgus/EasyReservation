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
  { value: 'application_error', label: tr('event_type_application_error') }
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
