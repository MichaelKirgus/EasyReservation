<script setup>

import { ref, computed, onMounted } from 'vue'
import IconButton from './IconButton.vue'
import AdminDataTable from './AdminDataTable.vue'
import api from '../api'
import { useTranslation } from '../composables/useTranslation'

const { tr } = useTranslation()

const props = defineProps({
  surveyId: {
    type: Number,
    required: true
  }
})

const questions = ref([])
const loading = ref(false)
const showDialog = ref(false)
const selectedQuestion = ref(null)

// Form data
const formData = ref({
  question_text: '',
  field_type: 'text_open',
  is_required: false,
  display_order: 0,
  options: [],
})

const columns = computed(() => [
  { key: 'id', label: tr('admin_surveys_column_id'), sortable: true },
  { key: 'question_text', label: tr('question'), sortable: false },
  { key: 'field_type', label: tr('type'), sortable: true, formatter: (val) => getQuestionTypeLabel(val) },
  { key: 'is_required', label: tr('required'), sortable: true, formatter: (val) => val ? tr('yes') : tr('no') },
])

const fieldTypes = [
  { value: 'score_1_5', label: tr('score_1_to_5') },
  { value: 'text_open', label: tr('open_text') },
  { value: 'text_multiple_choice', label: tr('multiple_choice') },
]

onMounted(async () => {
  await loadQuestions()
})

async function loadQuestions() {
  loading.value = true
  try {
    const response = await api.get(`/admin/surveys/${props.surveyId}`)
    if (response.data && response.data.questions) {
      questions.value = response.data.questions
    }
  } catch (error) {
    console.error('Failed to load questions:', error)
  } finally {
    loading.value = false
  }
}

function createQuestion() {
  formData.value = {
    question_text: '',
    field_type: 'text_open',
    is_required: false,
    display_order: questions.value.length + 1,
    options: [],
  }
  showDialog.value = true
}

async function saveQuestion() {
  loading.value = true
  try {
    if (selectedQuestion.value && selectedQuestion.value.id) {
      await api.put(`/admin/surveys/${props.surveyId}/questions/${selectedQuestion.value.id}`, formData.value)
    } else {
      await api.post(`/admin/surveys/${props.surveyId}/questions`, formData.value)
    }
    
    showDialog.value = false
    await loadQuestions()
  } catch (error) {
    console.error('Failed to save question:', error)
  } finally {
    loading.value = false
  }
}

async function deleteQuestion(id) {
  if (!confirm(tr('really_delete'))) return
  
  loading.value = true
  try {
    await api.delete(`/admin/surveys/${props.surveyId}/questions/${id}`)
    await loadQuestions()
  } catch (error) {
    console.error('Failed to delete question:', error)
  } finally {
    loading.value = false
  }
}

function editQuestion(question) {
  selectedQuestion.value = { ...question }
  formData.value = { 
    question_text: question.question_text,
    field_type: question.field_type,
    is_required: question.is_required,
    display_order: question.display_order || 0,
    options: question.options || [],
  }
  showDialog.value = true
}

function closeDialog() {
  showDialog.value = false
  selectedQuestion.value = null
  formData.value = {
    question_text: '',
    field_type: 'text_open',
    is_required: false,
    display_order: 0,
    options: [],
  }
}

function getQuestionTypeLabel(type) {
  const labels = {
    score_1_5: tr('score_1_to_5'),
    text_open: tr('open_text'),
    text_multiple_choice: tr('multiple_choice'),
  }
  return labels[type] || type
}

</script>

<template>
  <div class="survey-question-manager">
    <h3 style="display:flex;align-items:center;justify-content:space-between;">
      <span>{{ tr('survey_questions') }}</span>
      <IconButton icon="plus" :label="tr('add_question')" class="primary" variant="success" @click="createQuestion" />
    </h3>
    
    <AdminDataTable
      v-if="questions.length > 0"
      :columns="columns"
      :rows="questions"
      :loading="loading"
    >
      <template #cell-field_type="{ value }">
        {{ getQuestionTypeLabel(value) }}
      </template>
      <template #row-actions="{ row }">
        <IconButton icon="pencil" :label="tr('edit')" class="ghost" @click.stop="editQuestion(row)" :disabled="loading" />
        <IconButton icon="trash" :label="tr('delete')" class="ghost" variant="danger" @click.stop="deleteQuestion(row.id)" :disabled="loading" />
      </template>
    </AdminDataTable>

    <p v-else>{{ tr('no_questions_yet') }}</p>

    <!-- Question Dialog -->
    <div v-if="showDialog" class="modal-overlay">
      <div class="modal-dialog">
        <h3>{{ selectedQuestion && selectedQuestion.id ? tr('edit_question') : tr('add_question') }}</h3>
        
        <form @submit.prevent="saveQuestion">
          <div class="form-group">
            <label for="question_text">{{ tr('question') }}</label>
            <textarea
              id="question_text"
              v-model="formData.question_text"
              rows="2"
              required
            ></textarea>
          </div>

          <div class="form-group">
            <label for="field_type">{{ tr('type') }}</label>
            <select id="field_type" v-model="formData.field_type" required>
              <option v-for="ft in fieldTypes" :key="ft.value" :value="ft.value">
                {{ ft.label }}
              </option>
            </select>
          </div>

          <div class="form-group">
            <label for="is_required">{{ tr('required') }}</label>
            <input
              id="is_required"
              v-model="formData.is_required"
              type="checkbox"
            />
          </div>

          <div style="margin-top:1em; display:flex; gap:0.5em; justify-content:flex-end;">
            <IconButton icon="check" :label="tr('save')" type="submit" class="primary" variant="success" />
            <IconButton icon="close" :label="tr('cancel')" variant="danger" @click="closeDialog" />
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
.survey-question-manager {
  padding: 20px;
}

.modal-dialog {
  border: none;
  border-radius: 8px;
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
  max-width: 500px;
  width: 90%;
}

.form-group {
  margin-bottom: 15px;
}

.form-group label {
  display: block;
  margin-bottom: 5px;
  font-weight: bold;
}

.form-group input,
.form-group textarea,
.form-group select {
  width: 100%;
  padding: 8px;
  border: 1px solid #ddd;
  border-radius: 4px;
}
</style>
