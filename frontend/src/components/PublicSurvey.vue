<template>
  <div class="public-survey">
    <!-- Survey Header -->
    <div v-if="survey" class="survey-header">
      <h1>{{ survey.title }}</h1>
      <p v-if="survey.description" class="description">{{ survey.description }}</p>
      
      <div v-if="statusMessage" :class="['status-message', statusType]">
        {{ statusMessage }}
      </div>
    </div>

    <!-- Survey Form -->
    <form v-if="canRespond && questions.length > 0" @submit.prevent="submitSurvey" class="survey-form">
      <div v-for="question in questions" :key="question.id" class="question-block">
        <label class="question-text">
          {{ question.question_text }}
          <span v-if="question.is_required" class="required">*</span>
        </label>

        <!-- Score Question (1-5) -->
        <div v-if="question.field_type === 'score_1_5'" class="score-input">
          <input
            :id="'q' + question.id"
            v-model="responses[question.id]"
            type="range"
            min="1"
            max="5"
            step="1"
            @change="handleScoreChange(question.id, $event)"
          />
          <div class="score-labels">
            <span>1</span>
            <span>2</span>
            <span>3</span>
            <span>4</span>
            <span>5</span>
          </div>
          <div v-if="responses[question.id]" class="current-score">
            {{ responses[question.id] }} / 5
          </div>
        </div>

        <!-- Multiple Choice Question -->
        <div v-else-if="question.field_type === 'text_multiple_choice'" class="multiple-choice">
          <label
            v-for="(option, index) in question.options"
            :key="index"
            class="radio-option"
          >
            <input
              type="radio"
              :name="'q' + question.id"
              :value="option"
              v-model="responses[question.id]"
            />
            {{ option }}
          </label>
        </div>

        <!-- Open Text Question -->
        <textarea
          v-else
          :id="'q' + question.id"
          :placeholder="question.is_required ? tr('field_required') : tr('optional')"
          v-model="responses[question.id]"
          rows="3"
        ></textarea>

        <p v-if="validationErrors[question.id]" class="error-message">
          {{ validationErrors[question.id] }}
        </p>
      </div>

      <button type="submit" :disabled="isSubmitting" class="btn-submit">
        {{ isSubmitting ? tr('submitting') : tr('submit_survey') }}
      </button>
    </form>

    <!-- Already Responded / Survey Not Available -->
    <div v-else-if="!canRespond && statusMessage" class="survey-result">
      <p>{{ statusMessage }}</p>
    </div>

    <!-- No Questions -->
    <div v-else class="no-questions">
      <p>{{ tr('no_questions_available') }}</p>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '../api'
import { useTranslation } from '../composables/useTranslation'

const { tr } = useTranslation()
const route = useRoute()

const survey = ref(null)
const questions = ref([])
const responses = ref({})
const statusMessage = ref('')
const statusType = ref('info') // info, success, error
const canRespond = ref(false)
const isSubmitting = ref(false)
const validationErrors = ref({})

// Get survey ID and token from route
const surveyId = route.params.id || route.query.survey_id
const responseToken = route.query.token

onMounted(async () => {
  await loadSurvey()
})

async function loadSurvey() {
  if (!surveyId) return
  
  try {
    // First check status
    const statusResponse = await api.get(`/surveys/${surveyId}/check-status`, {
      params: { token: responseToken }
    })
    
    canRespond.value = statusResponse.data.can_respond || false
    statusMessage.value = statusResponse.data.message || ''
    statusType.value = canRespond.value ? 'info' : (statusResponse.data.already_responded ? 'success' : 'error')

    // Load survey details if we can respond or it's completed
    if (canRespond.value || statusResponse.data.already_responded) {
      const surveyResponse = await api.get(`/surveys/${surveyId}`)
      survey.value = surveyResponse.data.survey
      questions.value = surveyResponse.data.questions || []
      
      // Initialize responses with existing values if any
      questions.value.forEach(q => {
        responses.value[q.id] = ''
      })
    }
  } catch (error) {
    console.error('Failed to load survey:', error)
    statusMessage.value = tr('survey_not_found')
    statusType.value = 'error'
    canRespond.value = false
  }
}

function handleScoreChange(questionId, event) {
  responses.value[questionId] = parseInt(event.target.value, 10)
}

async function submitSurvey() {
  if (!canRespond.value || !surveyId) return
  
  // Validate required fields
  validationErrors.value = {}
  let hasError = false

  questions.value.forEach(q => {
    const response = responses.value[q.id]
    
    if (q.is_required && !response) {
      validationErrors.value[q.id] = tr('field_required')
      hasError = true
    }
  })

  if (hasError) return

  isSubmitting.value = true

  try {
    const submitData = {
      token: responseToken || '',
      responses: questions.value.map(q => ({
        question_id: q.id,
        response_text: typeof responses.value[q.id] === 'string' ? responses.value[q.id] : null,
        response_score: typeof responses.value[q.id] === 'number' ? responses.value[q.id] : null,
      })),
    }

    await api.post(`/surveys/${surveyId}/submit`, submitData)
    
    statusMessage.value = tr('thank_you_for_response')
    statusType.value = 'success'
    canRespond.value = false
  } catch (error) {
    console.error('Failed to submit survey:', error)
    
    if (error.response?.data?.message) {
      statusMessage.value = error.response.data.message
      statusType.value = 'error'
    } else {
      statusMessage.value = tr('survey_submission_failed')
      statusType.value = 'error'
    }
  } finally {
    isSubmitting.value = false
  }
}
</script>

<style scoped>
.public-survey {
  max-width: 600px;
  margin: 0 auto;
  padding: 20px;
}

.survey-header h1 {
  font-size: 1.8em;
  margin-bottom: 10px;
}

.description {
  color: var(--text-muted);
  margin-bottom: 20px;
}

.status-message {
  padding: 15px;
  border-radius: 8px;
  margin-bottom: 20px;
  text-align: center;
}

.status-message.info {
  background: var(--info-bg, #e3f2fd);
  color: var(--info-text, #1976d2);
}

.status-message.success {
  background: var(--success-bg, #e8f5e9);
  color: var(--success-text, #2e7d32);
}

.status-message.error {
  background: var(--error-bg, #ffebee);
  color: var(--error-text, #c62828);
}

.survey-form {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.question-block {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.question-text {
  font-weight: bold;
  font-size: 1.1em;
}

.required {
  color: var(--error-text, #f44336);
}

.score-input {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 5px;
}

.score-labels {
  display: flex;
  justify-content: space-between;
  width: 100%;
  max-width: 200px;
  font-size: 0.8em;
  color: var(--text-muted);
}

.current-score {
  font-weight: bold;
  color: var(--info-text, #1976d2);
}

.multiple-choice {
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.radio-option {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px;
  border: 1px solid var(--border);
  border-radius: 4px;
}

textarea {
  width: 100%;
  padding: 10px;
  border: 1px solid var(--border);
  border-radius: 4px;
  resize: vertical;
  font-family: inherit;
}

.error-message {
  color: var(--error-text, #f44336);
  font-size: 0.9em;
  margin-top: 5px;
}

.btn-submit {
  padding: 12px 24px;
  background: var(--success-bg, #4caf50);
  color: var(--success-text, #fff);
  border: 1px solid var(--success-bg, #4caf50);
  border-radius: 4px;
  font-size: 1.1em;
  cursor: pointer;
  transition: background 0.2s;
}

.btn-submit:hover:not(:disabled) {
  background: var(--success-bg-hover, #388e3c);
}

.btn-submit:disabled {
  background: var(--surface-muted, #bdbdbd);
  cursor: not-allowed;
}

.survey-result {
  text-align: center;
  padding: 40px 20px;
}

.no-questions {
  text-align: center;
  color: var(--text-muted);
  padding: 40px 20px;
}
</style>
