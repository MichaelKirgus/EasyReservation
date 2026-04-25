<script setup>

import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import IconButton from './IconButton.vue'
import AdminDataTable from './AdminDataTable.vue'
import api from '../api'
import { useTranslation } from '../composables/useTranslation'
import { Chart, BarController, PieController, CategoryScale, LinearScale, Tooltip, Legend, ArcElement, LineElement, PointElement } from 'chart.js'

const { tr } = useTranslation()
const routePrefix = ref(localStorage.getItem('admin_route_prefix') || 'admin')

function withPrefix(path) {
  return `/${routePrefix.value}/${path}`
}

// Register Chart.js components
Chart.register(BarController, PieController, CategoryScale, LinearScale, Tooltip, Legend, ArcElement, LineElement, PointElement)

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

// Results tab state
const activeResultsTab = ref('overview') // 'overview' or 'detail'
const selectedSurveyForResults = ref(null)
const surveyResults = ref(null)
const loadingResults = ref(false)
let resultsChart = ref(null)

// Form data for survey
const surveyFormData = ref({
  title: '',
  description: '',
  submission_message: '',
  already_responded_message: '',
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
  min_value: null,
  max_value: null,
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
  { value: 'number_input', label: tr('number_input') },
]

onMounted(async () => {
  await loadSurveys()
  await loadEvents()
  await loadQuestions()
})

onUnmounted(() => {
  destroyResultsChart()
})

async function loadSurveys() {
  loadingSurveys.value = true
  try {
    const response = await api.get(withPrefix('surveys'))
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
    const response = await api.get(withPrefix('events'))
    events.value = Array.isArray(response.data) ? response.data : []
  } catch (error) {
    console.error('Failed to load events:', error)
  }
}

async function loadQuestions() {
  loadingQuestions.value = true
  try {
    const response = await api.get(withPrefix('global-questions'))
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
    submission_message: '',
    already_responded_message: '',
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
      await api.put(withPrefix(`surveys/${surveyFormData.value.id}`), surveyFormData.value)
    } else {
      await api.post(withPrefix('surveys'), surveyFormData.value)
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
    await api.delete(withPrefix(`surveys/${id}`))
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
    submission_message: '',
    already_responded_message: '',
    event_id: null,
    starts_at: null,
    ends_at: null,
    active: true,
  }
}

async function previewSurvey(survey) {
  try {
    const response = await api.get(withPrefix(`surveys/${survey.id}/preview`))
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
    min_value: null,
    max_value: null,
  }
  showDialog.value = true
}

async function saveQuestion() {
  loadingQuestions.value = true
  try {
    if (selectedQuestion.value && selectedQuestion.value.id) {
      await api.put(withPrefix(`global-questions/${selectedQuestion.value.id}`), questionFormData.value)
    } else {
      await api.post(withPrefix('global-questions'), questionFormData.value)
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
    await api.delete(withPrefix(`global-questions/${id}`))
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
    min_value: question.min_value ?? null,
    max_value: question.max_value ?? null,
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
    min_value: null,
    max_value: null,
  }
}

function addOption() {
  questionFormData.value.options.push('')
}

function removeOption(index) {
  questionFormData.value.options.splice(index, 1)
}

function getQuestionTypeLabel(type) {
  const labels = {
    score_1_5: tr('score_1_to_5'),
    text_open: tr('open_text'),
    text_multiple_choice: tr('multiple_choice'),
    number_input: tr('number_input'),
  }
  return labels[type] || type
}

// Results tab functions
async function loadSurveyResults(surveyId) {
  if (!surveyId) return
  
  loadingResults.value = true
  try {
    const response = await api.get(withPrefix(`surveys/${surveyId}/results`))
    surveyResults.value = response.data
    
    // Create chart after data is loaded
    await nextTick()
    createResultsChart()
    
    // Create individual charts for each question
    await nextTick()
    createQuestionCharts()
  } catch (error) {
    console.error('Failed to load survey results:', error)
  } finally {
    loadingResults.value = false
  }
}

function createQuestionCharts() {
  if (!surveyResults.value?.questions?.length) return
  
  surveyResults.value.questions.forEach(q => {
    if (q.field_type === 'score_1_5' && q.statistics?.distribution) {
      const canvasId = `score-chart-${q.id}`
      const canvas = document.getElementById(canvasId)
      
      if (!canvas) return

      const labels = ['1', '2', '3', '4', '5']
      const data = [0, 0, 0, 0, 0]
      
      // Fill in actual scores
      for (let i = 1; i <= 5; i++) {
        if (q.statistics.distribution[i]) {
          data[i - 1] = q.statistics.distribution[i]
        }
      }

      const ctx = canvas.getContext('2d')
      
      new Chart(ctx, {
        type: 'bar',
        data: {
          labels: labels,
          datasets: [{
            label: tr('responses'),
            data: data,
            backgroundColor: '#3b82f6',
            borderColor: '#2563eb',
            borderWidth: 1
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1 } }
          },
          plugins: { legend: { display: false } }
        }
      })
    }
  })
}

function createResultsChart() {
  if (!surveyResults.value || !surveyResults.value.questions?.length) return
  
  // Destroy existing chart if any
  if (resultsChart.value) {
    resultsChart.value.destroy()
  }

  const canvas = document.getElementById('survey-results-chart')
  if (!canvas) return

  // Aggregate data from all questions for the main chart
  const questionData = surveyResults.value.questions.filter(q =>
    q.field_type === 'score_1_5' || q.field_type === 'text_multiple_choice'
  )

  if (questionData.length === 0) {
    // No chartable data
    return
  }

  // Create a combined dataset for the main chart
  const labels = []
  const data = []

  questionData.forEach(q => {
    if (q.field_type === 'score_1_5' && q.statistics?.distribution) {
      Object.entries(q.statistics.distribution).forEach(([score, count]) => {
        labels.push(`${q.question_text} (${tr('score')} ${score})`)
        data.push(count)
      })
    } else if (q.field_type === 'text_multiple_choice' && q.statistics?.distribution) {
      Object.entries(q.statistics.distribution).forEach(([option, count]) => {
        labels.push(`${q.question_text}: ${option}`)
        data.push(count)
      })
    }
  })

  const ctx = canvas.getContext('2d')
  
  resultsChart.value = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [{
        label: tr('responses'),
        data: data,
        backgroundColor: '#3b82f6',
        borderColor: '#2563eb',
        borderWidth: 1
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: {
          beginAtZero: true,
          ticks: { stepSize: 1 }
        }
      },
      plugins: {
        legend: { display: false }
      }
    }
  })
}

function destroyResultsChart() {
  if (resultsChart.value) {
    resultsChart.value.destroy()
    resultsChart.value = null
  }
}

function exportToCSV() {
  if (!selectedSurveyForResults.value) return
  
  const surveyId = selectedSurveyForResults.value.id
  window.open(`/api${withPrefix(`surveys/results/export`)}?survey_id=${surveyId}&format=csv&include_responses=true`, '_blank')
}

function exportToJSON() {
  if (!selectedSurveyForResults.value) return
  
  const surveyId = selectedSurveyForResults.value.id
  window.open(`/api${withPrefix(`surveys/results/export`)}?survey_id=${surveyId}&format=json&include_responses=true`, '_blank')
}

function selectSurveyForResults(survey) {
  selectedSurveyForResults.value = survey
  activeResultsTab.value = 'detail'
  loadSurveyResults(survey.id)
}

</script>

<template>
  <div class="admin-surveys">
    <!-- Tabs -->
    <div class="admin-tabs">
      <button
        class="admin-tab-button"
        :class="{ active: activeTab === 'surveys' }"
        @click="activeTab = 'surveys'"
      >
        {{ tr('surveys') }}
      </button>
      <button
        class="admin-tab-button"
        :class="{ active: activeTab === 'questions' }"
        @click="activeTab = 'questions'"
      >
        {{ tr('global_questions') }}
      </button>
      <button
        class="admin-tab-button"
        :class="{ active: activeTab === 'results' }"
        @click="activeTab = 'results'"
      >
        {{ tr('survey_results') }}
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
              <label for="submission_message">{{ tr('submission_message') }}</label>
              <textarea
                id="submission_message"
                v-model="surveyFormData.submission_message"
                rows="4"
                :placeholder="tr('submission_message_placeholder')"
                :title="tr('submission_message_help')"
              ></textarea>
              <p class="field-help">{{ tr('submission_message_help') }}</p>
            </div>

            <div class="form-group">
              <label for="already_responded_message">{{ tr('already_responded_message') }}</label>
              <textarea
                id="already_responded_message"
                v-model="surveyFormData.already_responded_message"
                rows="4"
                :placeholder="tr('already_responded_message_placeholder')"
                :title="tr('already_responded_message_help')"
              ></textarea>
              <p class="field-help">{{ tr('already_responded_message_help') }}</p>
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

            <div v-if="questionFormData.field_type === 'text_multiple_choice'" class="form-group">
              <label>{{ tr('options') }}</label>
              <div v-for="(option, index) in questionFormData.options" :key="index" style="display:flex;gap:0.5em;margin-bottom:0.5em;align-items:center;">
                <input
                  :id="'option-' + index"
                  v-model="questionFormData.options[index]"
                  type="text"
                  :placeholder="tr('option') + ' ' + (index + 1)"
                  style="flex:1;"
                />
                <IconButton icon="trash" :label="tr('remove')" class="ghost" variant="danger" @click="removeOption(index)" />
              </div>
              <IconButton icon="plus" :label="tr('add_option')" variant="info" @click="addOption" />
              <p class="field-help">{{ tr('multiple_choice_help') }}</p>
            </div>

            <div v-if="questionFormData.field_type === 'number_input'" class="form-group">
              <label>{{ tr('number_range') }}</label>
              <div style="display:flex;gap:1em;align-items:center;">
                <div style="flex:1;">
                  <label for="min_value" style="font-size:0.85em;color:var(--text-muted);">{{ tr('minimum') }}</label>
                  <input
                    id="min_value"
                    v-model.number="questionFormData.min_value"
                    type="number"
                    style="width:100%;margin-top:0.25em;"
                  />
                </div>
                <div style="flex:1;">
                  <label for="max_value" style="font-size:0.85em;color:var(--text-muted);">{{ tr('maximum') }}</label>
                  <input
                    id="max_value"
                    v-model.number="questionFormData.max_value"
                    type="number"
                    style="width:100%;margin-top:0.25em;"
                  />
                </div>
              </div>
              <p class="field-help">{{ tr('number_input_help') }}</p>
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

    <!-- Results Tab -->
    <div v-if="activeTab === 'results'" class="tab-content" style="display:flex;flex-direction:column;">
      <h2 style="display:flex;align-items:center;justify-content:space-between;">
        <span>{{ tr('survey_results') }}</span>
      </h2>

      <!-- Survey Selection -->
      <div style="margin-bottom:1rem;">
        <label style="font-weight:bold;display:block;margin-bottom:0.5rem;">{{ tr('select_survey') }}</label>
        <select
          v-model="selectedSurveyForResults"
          @change="selectSurveyForResults(selectedSurveyForResults)"
          class="results-select-dropdown"
        >
          <option value="">{{ tr('select_survey_first') }}</option>
          <option v-for="survey in surveys" :key="survey.id" :value="survey">
            {{ survey.title }} ({{ survey.event_title ? survey.event_title : tr('no_event_linked') }})
          </option>
        </select>
      </div>

      <!-- Results Content -->
      <div v-if="selectedSurveyForResults && activeResultsTab === 'detail'" class="results-tab-content">
        <!-- Header with Export Buttons -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
          <h2>{{ tr('survey_results') }}</h2>
          <div style="display:flex;gap:0.5rem;">
            <IconButton
              icon="download"
              :label="tr('export_csv')"
              class="primary"
              variant="success"
              @click="exportToCSV"
            />
            <IconButton
              icon="download"
              :label="tr('export_json')"
              class="primary"
              variant="info"
              @click="exportToJSON"
            />
          </div>
        </div>

        <!-- Summary Cards -->
        <div class="results-summary-cards">
          <div class="results-card">
            <div class="results-card-label">{{ tr('total_responses') }}</div>
            <div class="results-card-value">{{ surveyResults?.summary?.total_responses || 0 }}</div>
          </div>
          <div class="results-card">
            <div class="results-card-label">{{ tr('response_rate') }}</div>
            <div class="results-card-value">{{ surveyResults?.summary?.total_responses ? '100%' : '0%' }}</div>
          </div>
        </div>

        <!-- Chart Container -->
        <div class="results-chart-container">
          <h3>{{ tr('response_distribution') }}</h3>
          <canvas id="survey-results-chart" height="300"></canvas>
        </div>

        <!-- Question Results -->
        <div v-if="surveyResults?.questions?.length">
          <h3>{{ tr('question_results') }}</h3>
          
          <div v-for="(question, index) in surveyResults.questions" :key="question.id" class="results-question-section">
            <h4 class="results-question-title">{{ index + 1 }}. {{ question.question_text }}</h4>
            
            <!-- Score Question -->
            <div v-if="question.field_type === 'score_1_5'">
              <div class="results-statistics">
                <div>
                  <div class="results-statistic-label">{{ tr('average_score') }}</div>
                  <div class="results-statistic-value">{{ question.statistics?.average_score || 'N/A' }} / 5</div>
                </div>
                <div>
                  <div class="results-statistic-label">{{ tr('total_responses') }}</div>
                  <div class="results-statistic-value">{{ question.statistics?.total_responses || 0 }}</div>
                </div>
              </div>
              
              <!-- Score Distribution Chart -->
              <div v-if="question.statistics?.distribution" class="results-distribution-bar-container">
                <canvas :id="'score-chart-' + question.id" height="200"></canvas>
              </div>
            </div>

            <!-- Multiple Choice Question -->
            <div v-else-if="question.field_type === 'text_multiple_choice'">
              <div style="margin-bottom:1rem;">
                <div class="results-statistic-label">{{ tr('total_responses') }}</div>
                <div class="results-statistic-value">{{ question.statistics?.total_responses || 0 }}</div>
              </div>

              <!-- Option Distribution -->
              <div v-if="question.statistics?.distribution" class="results-distribution-bar-container">
                <h4>{{ tr('option_distribution') }}</h4>
                
                <div v-for="(count, option) in question.statistics.distribution" :key="option" class="results-distribution-label">
                  <span class="results-distribution-option">{{ option }}</span>
                  <div class="results-distribution-bar">
                    <div
                      class="results-distribution-fill"
                      :style="{ width: question.statistics.total_responses > 0 ? (count / question.statistics.total_responses * 100) + '%' : '0%' }"
                    ></div>
                  </div>
                  <span class="results-distribution-count">{{ count }}</span>
                </div>
              </div>
            </div>

            <!-- Open Text Question -->
            <div v-else-if="question.field_type === 'text_open'">
              <div style="margin-bottom:1rem;">
                <div class="results-statistic-label">{{ tr('total_responses') }}</div>
                <div class="results-statistic-value">{{ question.statistics?.total_responses || 0 }}</div>
              </div>

              <!-- Sample Responses -->
              <div v-if="question.statistics?.responses?.length" class="results-sample-responses">
                <h4>{{ tr('sample_responses') }}</h4>
                
                <ul style="list-style-position:inside;margin:0;">
                  <li v-for="(response, idx) in question.statistics.responses.slice(0, 5)" :key="idx" style="margin-bottom:0.5rem;">
                    {{ response }}
                  </li>
                </ul>

                <div v-if="question.statistics.responses.length > 5" style="color:#6b7280;font-size:0.875rem;margin-top:0.5rem;">
                  +{{ question.statistics.responses.length - 5 }} more responses
                </div>
              </div>
            </div>
          </div>
        </div>

        <div v-else style="color:#6b7280;font-style:italic;text-align:center;padding:2rem;">
          {{ tr('no_responses_yet') }}
        </div>
      </div>

      <!-- Overview State -->
      <div v-if="selectedSurveyForResults && activeResultsTab === 'overview'">
        <p style="color:#6b7280;">{{ tr('select_survey_for_details') }}</p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.admin-surveys {
  padding: 20px;
}

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

.field-help {
  font-size: 0.85em;
  color: var(--text-muted);
  margin-top: 4px;
  margin-bottom: 0;
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

/* Results Tab Styles */
.results-tab-content {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.results-summary-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 1rem;
}

.results-card {
  background: var(--app-card-bg, var(--surface));
  padding: 1rem;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
  color: var(--text);
}

.results-card-label {
  font-size: 0.875rem;
  color: var(--text-muted);
}

.results-card-value {
  font-size: 1.5rem;
  font-weight: bold;
  color: var(--text);
}

.results-chart-container {
  background: var(--app-card-bg, var(--surface));
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
  padding: 1rem;
}

.results-question-section {
  background: var(--app-card-bg, var(--surface));
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
  padding: 1rem;
  margin-bottom: 1rem;
}

.results-question-title {
  font-weight: bold;
  color: var(--text);
  margin-top: 0;
  margin-bottom: 1rem;
}

.results-statistics {
  display: flex;
  gap: 2rem;
  margin-bottom: 1rem;
}

.results-statistic-label {
  font-size: 0.875rem;
  color: var(--text-muted);
}

.results-statistic-value {
  font-size: 1.25rem;
  font-weight: bold;
  color: var(--text);
}

.results-distribution-bar-container {
  background: var(--surface-muted);
  border-radius: 6px;
  padding: 1rem;
  margin-top: 0.75rem;
}

.results-distribution-label {
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-bottom: 0.5rem;
}

.results-distribution-option {
  flex: 0 0 120px;
  font-weight: 500;
  color: var(--text);
}

.results-distribution-bar {
  flex: 1;
  background: var(--border);
  border-radius: 4px;
  overflow: hidden;
  height: 24px;
}

.results-distribution-fill {
  background: var(--primary);
  height: 100%;
  transition: width 0.3s ease;
}

.results-distribution-count {
  flex: 0 0 50px;
  font-weight: bold;
  color: var(--text);
}

.results-sample-responses {
  background: var(--surface-muted);
  border-radius: 6px;
  padding: 1rem;
}

.results-no-responses {
  color: var(--text-muted);
  font-style: italic;
  text-align: center;
  padding: 2rem;
}

/* Results Tab Select Dropdown */
.results-select-dropdown {
  width: 100%;
  padding: 0.75rem;
  border: 1px solid var(--border);
  border-radius: 6px;
  background: var(--surface);
  color: var(--text);
}

/* Results Tab H3 Headings */
.results-tab-content h3 {
  margin-top: 0;
  margin-bottom: 1rem;
  color: var(--text);
}
</style>
