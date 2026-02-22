<script setup>

import { ref, computed, onMounted } from 'vue'
import IconButton from './IconButton.vue'
import AdminDataTable from './AdminDataTable.vue'
import api from '../api'
import { useTranslation } from '../composables/useTranslation'

const { tr } = useTranslation()

// Tab state
const activeTab = ref('surveys')

// Survey state
const surveys = ref([])
const events = ref([])
const loadingSurveys = ref(false)

// Question state
const questions = ref([])
const loadingQuestions = ref(false)
const showDialog = ref(false)
const selectedQuestion = ref(null)

// Survey dialog state
const showSurveyDialog = ref(false)

// Preview state
const showPreviewDialog = ref(false)
const previewSurveyData = ref(null)
const previewQuestions = ref([])

// Form data for survey
const surveyFormData = ref({
  title: '',
  description: '',
  event_id: null,
  starts_at: null,
  ends_at: null,
  active: true,
})

// Form data for question
const questionFormData = ref({
  question_text: '',
  field_type: 'text_open',
  is_required: false,
  display_order: 0,
  options: [],
})

// Survey columns
const surveyColumns = computed(() => [
  { key: 'title', label: tr('title'), sortable: true },
  { key: 'event_title', label: tr('event'), sortable: false },
  { key: 'active', label: tr('status'), sortable: true, formatter: (val) => val ? tr('active') : tr('inactive') },
])

// Question columns
const questionColumns = computed(() => [
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
  await loadSurveys()
  await loadEvents()
  await loadQuestions()
})

async function loadSurveys() {
  loadingSurveys.value = true
  try {
    const response = await api.get('/admin/surveys')
    surveys.value = Array.isArray(response.data) ? response.data : []
    
    // Add event title for display
    surveys.value.forEach(survey => {
      if (survey.event_id && events.value.length > 0) {
        const event = events.value.find(e => e.id === survey.event_id)
        survey.event_title = event ? event.title : ''
      } else {
        survey.event_title = tr('no_event_linked')
      }
    })
  } catch (error) {
    console.error('Failed to load surveys:', error)
  } finally {
    loadingSurveys.value = false
  }
}

async function loadEvents() {
  try {
    const response = await api.get('/admin/events')
    events.value = Array.isArray(response.data) ? response.data : []
  } catch (error) {
    console.error('Failed to load events:', error)
  }
}

async function loadQuestions() {
  loadingQuestions.value = true
  try {
    const response = await api.get('/admin/global-questions')
    questions.value = Array.isArray(response.data) ? response.data : []
  } catch (error) {
    console.error('Failed to load questions:', error)
  } finally {
    loadingQuestions.value = false
  }
}

function createSurvey() {
  surveyFormData.value = {
    title: '',
    description: '',
    event_id: null,
    starts_at: new Date().toISOString().slice(0, 16),
    ends_at: null,
    active: true,
  }
  showSurveyDialog.value = true
}

async function saveSurvey() {
  loadingSurveys.value = true
  try {
    if (surveyFormData.value.id) {
      await api.put(`/admin/surveys/${surveyFormData.value.id}`, surveyFormData.value)
    } else {
      await api.post('/admin/surveys', surveyFormData.value)
    }
    
    showSurveyDialog.value = false
    surveyFormData.value = {
      title: '',
      description: '',
      event_id: null,
      starts_at: new Date().toISOString().slice(0, 16),
      ends_at: null,
      active: true,
    }
    await loadSurveys()
  } catch (error) {
    console.error('Failed to save survey:', error)
  } finally {
    loadingSurveys.value = false
  }
}

async function deleteSurvey(id) {
  if (!confirm(tr('really_delete'))) return
  
  loadingSurveys.value = true
  try {
    await api.delete(`/admin/surveys/${id}`)
    await loadSurveys()
  } catch (error) {
    console.error('Failed to delete survey:', error)
  } finally {
    loadingSurveys.value = false
  }
}

function editSurvey(survey) {
  surveyFormData.value = {
    ...survey,
    starts_at: survey.starts_at ? survey.starts_at.slice(0, 16) : null,
    ends_at: survey.ends_at ? survey.ends_at.slice(0, 16) : null,
  }
  showSurveyDialog.value = true
}

function closeSurveyDialog() {
  showSurveyDialog.value = false
  surveyFormData.value = {
    title: '',
    description: '',
    event_id: null,
    starts_at: null,
    ends_at: null,
    active: true,
  }
}

async function previewSurvey(survey) {
  try {
    const response = await api.get(`/admin/surveys/${survey.id}/preview`)
    console.log('Preview response:', response.data)
    previewSurveyData.value = response.data.survey
    previewQuestions.value = response.data.questions || []
    showPreviewDialog.value = true
  } catch (error) {
    console.error('Failed to load survey preview:', error)
  }
}

function closePreviewDialog() {
  showPreviewDialog.value = false
  previewSurveyData.value = null
  previewQuestions.value = []
}

// Question functions
function createQuestion() {
  questionFormData.value = {
    question_text: '',
    field_type: 'text_open',
    is_required: false,
    display_order: questions.value.length + 1,
    options: [],
  }
  showDialog.value = true
}

async function saveQuestion() {
  loadingQuestions.value = true
  try {
    if (selectedQuestion.value && selectedQuestion.value.id) {
      await api.put(`/admin/global-questions/${selectedQuestion.value.id}`, questionFormData.value)
    } else {
      await api.post('/admin/global-questions', questionFormData.value)
    }
    
    showDialog.value = false
    await loadQuestions()
  } catch (error) {
    console.error('Failed to save question:', error)
  } finally {
    loadingQuestions.value = false
  }
}

async function deleteQuestion(id) {
  if (!confirm(tr('really_delete'))) return
  
  loadingQuestions.value = true
  try {
    await api.delete(`/admin/global-questions/${id}`)
    await loadQuestions()
  } catch (error) {
    console.error('Failed to delete question:', error)
  } finally {
    loadingQuestions.value = false
  }
}

function editQuestion(question) {
  selectedQuestion.value = { ...question }
  questionFormData.value = { 
    question_text: question.question_text,
    field_type: question.field_type,
    is_required: question.is_required,
    display_order: question.display_order || 0,
    options: question.options || [],
  }
  showDialog.value = true
}

function closeQuestionDialog() {
  showDialog.value = false
  selectedQuestion.value = null
  questionFormData.value = {
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
  <div class="admin-surveys">
    <!-- Tabs -->
    <div class="tabs" style="display:flex;gap:0.5rem;border-bottom:2px solid #e5e7eb;padding-bottom:0.5rem;">
      <button
        :class="{ active: activeTab === 'surveys' }"
        @click="activeTab = 'surveys'"
        style="padding:0.5rem 1rem;border:none;background:transparent;color:#6b7280;font-weight:600;cursor:pointer;border-bottom:2px solid transparent;"
      >
        {{ tr('surveys') }}
      </button>
      <button
        :class="{ active: activeTab === 'questions' }"
        @click="activeTab = 'questions'"
        style="padding:0.5rem 1rem;border:none;background:transparent;color:#6b7280;font-weight:600;cursor:pointer;border-bottom:2px solid transparent;"
      >
        {{ tr('global_questions') }}
      </button>
    </div>

    <!-- Surveys Tab -->
    <div v-if="activeTab === 'surveys'" class="tab-content" style="display:flex;flex-direction:column;">
      <h2 style="display:flex;align-items:center;justify-content:space-between;">
        <span>{{ tr('surveys') }}</span>
        <IconButton icon="plus" :label="tr('create_survey')" class="primary" variant="success" @click="createSurvey" />
      </h2>
      
      <AdminDataTable
        :columns="surveyColumns"
        :rows="surveys"
        :loading="loadingSurveys"
      >
        <template #cell-event_title="{ value }">
          {{ value }}
        </template>
        <template #cell-active="{ row }">
          <input type="checkbox" :checked="row.active" @change="toggleActive(row)" :disabled="loadingSurveys" />
        </template>
        <template #row-actions="{ row }">
          <IconButton icon="pencil" :label="tr('edit')" class="ghost" @click.stop="editSurvey(row)" :disabled="loadingSurveys" />
          <IconButton icon="eye" :label="tr('preview')" class="ghost" @click.stop="previewSurvey(row)" :disabled="loadingSurveys" />
          <IconButton icon="trash" :label="tr('delete')" class="ghost" variant="danger" @click.stop="deleteSurvey(row.id)" :disabled="loadingSurveys" />
        </template>
      </AdminDataTable>

      <!-- Survey Dialog -->
      <div v-if="showSurveyDialog" class="modal-overlay">
        <div class="modal-dialog">
          <h3>{{ surveyFormData.id ? tr('edit_survey') : tr('create_survey') }}</h3>
          
          <form @submit.prevent="saveSurvey">
            <div class="form-group">
              <label for="title">{{ tr('title') }}</label>
              <input
                id="title"
                v-model="surveyFormData.title"
                type="text"
                required
              />
            </div>

            <div class="form-group">
              <label for="description">{{ tr('description') }}</label>
              <textarea
                id="description"
                v-model="surveyFormData.description"
                rows="3"
              ></textarea>
            </div>

            <div class="form-group">
              <label for="event_id">{{ tr('event') }}</label>
              <select id="event_id" v-model="surveyFormData.event_id">
                <option value="">{{ tr('no_event_linked') }}</option>
                <option v-for="event in events" :key="event.id" :value="event.id">
                  {{ event.title }}
                </option>
              </select>
            </div>

            <div class="form-group">
              <label for="starts_at">{{ tr('starts_at') }}</label>
              <input
                id="starts_at"
                v-model="surveyFormData.starts_at"
                type="datetime-local"
              />
            </div>

            <div class="form-group">
              <label for="ends_at">{{ tr('ends_at') }}</label>
              <input
                id="ends_at"
                v-model="surveyFormData.ends_at"
                type="datetime-local"
              />
            </div>

            <div class="form-group">
              <label for="active">{{ tr('active') }}</label>
              <input
                id="active"
                v-model="surveyFormData.active"
                type="checkbox"
              />
            </div>

            <div style="margin-top:1em; display:flex; gap:0.5em; justify-content:flex-end;">
              <IconButton icon="check" :label="tr('save')" type="submit" class="primary" variant="success" />
              <IconButton icon="close" :label="tr('cancel')" variant="danger" @click="closeSurveyDialog" />
            </div>
          </form>
        </div>
      </div>

      <!-- Preview Dialog -->
      <div v-if="showPreviewDialog" class="modal-overlay">
        <div class="modal-dialog preview-dialog">
          <h3>{{ tr('survey_preview') }}</h3>
          
          <div v-if="previewSurveyData" class="preview-content">
            <h4>{{ previewSurveyData.title }}</h4>
            <p v-if="previewSurveyData.description">{{ previewSurveyData.description }}</p>
            
            <div v-if="!previewQuestions.length" class="no-questions">
              {{ tr('no_questions_available') }}
            </div>
            
            <div v-for="question in previewQuestions" :key="question.id" class="preview-question">
              <label class="question-text">{{ question.globalQuestion?.question_text || question.question_text }}</label>
              
              <div v-if="question.field_type === 'score_1_5'" class="score-preview">
                <input type="range" min="1" max="5" value="3" disabled />
                <span>1 - 5 (Score)</span>
              </div>
              
              <div v-else-if="question.field_type === 'text_multiple_choice'" class="choice-preview">
                <label v-for="(option, index) in question.options" :key="index" class="radio-option">
                  <input type="radio" name="preview-q" :value="option" disabled />
                  {{ option }}
                </label>
              </div>
              
              <textarea
                v-else
                :placeholder="tr('your_answer')"
                disabled
                rows="2"
              ></textarea>
            </div>
          </div>
          
          <div style="margin-top:1em; display:flex; gap:0.5em; justify-content:flex-end;">
            <IconButton icon="close" :label="tr('close')" @click="closePreviewDialog" />
          </div>
        </div>
      </div>
    </div>

    <!-- Questions Tab -->
    <div v-if="activeTab === 'questions'" class="tab-content" style="display:flex;flex-direction:column;">
      <h2 style="display:flex;align-items:center;justify-content:space-between;">
        <span>{{ tr('global_questions') }}</span>
        <IconButton icon="plus" :label="tr('add_question')" class="primary" variant="success" @click="createQuestion" />
      </h2>
      
      <!-- Question Dialog -->
      <div v-if="showDialog" class="modal-overlay">
        <div class="modal-dialog">
          <h3>{{ selectedQuestion && selectedQuestion.id ? tr('edit_question') : tr('add_question') }}</h3>
          
          <form @submit.prevent="saveQuestion">
            <div class="form-group">
              <label for="question_text">{{ tr('question') }}</label>
              <textarea
                id="question_text"
                v-model="questionFormData.question_text"
                rows="2"
                required
              ></textarea>
            </div>

            <div class="form-group">
              <label for="field_type">{{ tr('type') }}</label>
              <select id="field_type" v-model="questionFormData.field_type" required>
                <option v-for="ft in fieldTypes" :key="ft.value" :value="ft.value">
                  {{ ft.label }}
                </option>
              </select>
            </div>

            <div class="form-group">
              <label for="is_required">{{ tr('required') }}</label>
              <input
                id="is_required"
                v-model="questionFormData.is_required"
                type="checkbox"
              />
            </div>

            <div style="margin-top:1em; display:flex; gap:0.5em; justify-content:flex-end;">
              <IconButton icon="check" :label="tr('save')" type="submit" class="primary" variant="success" />
              <IconButton icon="close" :label="tr('cancel')" variant="danger" @click="closeQuestionDialog" />
            </div>
          </form>
        </div>
      </div>

      <AdminDataTable
        :columns="questionColumns"
        :rows="questions"
        :loading="loadingQuestions"
        :empty-text="tr('no_questions_yet')"
      >
        <template #cell-field_type="{ value }">
          {{ getQuestionTypeLabel(value) }}
        </template>
        <template #row-actions="{ row }">
          <IconButton icon="pencil" :label="tr('edit')" class="ghost" @click.stop="editQuestion(row)" :disabled="loadingQuestions" />
          <IconButton icon="trash" :label="tr('delete')" class="ghost" variant="danger" @click.stop="deleteQuestion(row.id)" :disabled="loadingQuestions" />
        </template>
      </AdminDataTable>
    </div>
  </div>
</template>

<style scoped>
.admin-surveys {
  padding: 20px;
}

.tabs button.active { color: #2563eb; border-bottom-color: #2563eb; }

.tab-content {
  animation: fadeIn 0.2s ease;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
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
  border: 1px solid var(--border);
  border-radius: 4px;
  background: var(--surface);
  color: var(--text);
}

.preview-dialog {
  max-width: 600px;
}

.preview-content h4 {
  margin-top: 0;
  font-size: 1.2em;
  color: var(--text);
}

.preview-content p {
  color: var(--text-muted);
  margin-bottom: 20px;
}

.preview-question {
  margin-bottom: 15px;
  padding: 10px;
  background-color: var(--surface-muted);
  border-radius: 4px;
  border: 1px solid var(--border);
}

.question-text {
  font-weight: bold;
  display: block;
  margin-bottom: 8px;
  color: var(--text);
}

.score-preview {
  display: flex;
  align-items: center;
  gap: 10px;
}

.score-preview input {
  width: 200px;
}

.choice-preview label {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px;
  margin-bottom: 4px;
  border: 1px solid var(--border);
  border-radius: 4px;
  background: var(--surface);
  color: var(--text);
}

.no-questions {
  color: var(--text-muted);
  font-style: italic;
}
</style>
