# Survey Results Visualization & Export Feature

## Overview
Add a new "Results" tab to AdminSurveys.vue that displays survey responses with visual charts and export functionality.

## Technical Architecture

### Backend Changes

#### 1. New API Endpoints

##### `GET /admin/surveys/{id}/results`
Returns aggregated statistics for a specific survey.

**Response:**
```json
{
  "survey": {
    "id": 1,
    "title": "Event Feedback",
    "description": "...",
    "starts_at": "...",
    "ends_at": "..."
  },
  "summary": {
    "total_responses": 42,
    "response_rate": 0.75,
    "avg_completion_time": 120
  },
  "questions": [
    {
      "id": 1,
      "question_text": "How was the event?",
      "field_type": "score_1_5",
      "statistics": {
        "total_responses": 42,
        "average_score": 4.2,
        "distribution": {
          "1": 2,
          "2": 3,
          "3": 5,
          "4": 15,
          "5": 17
        }
      },
      "chart_data": {
        "labels": ["1", "2", "3", "4", "5"],
        "data": [2, 3, 5, 15, 17]
      }
    },
    {
      "id": 2,
      "question_text": "What did you like most?",
      "field_type": "text_open",
      "statistics": {
        "total_responses": 40,
        "responses": ["Great venue", "Good food", ...]
      }
    },
    {
      "id": 3,
      "question_text": "Would you recommend us?",
      "field_type": "text_multiple_choice",
      "options": ["Yes", "No", "Maybe"],
      "statistics": {
        "total_responses": 42,
        "distribution": {
          "Yes": 35,
          "No": 5,
          "Maybe": 2
        }
      },
      "chart_data": {
        "labels": ["Yes", "No", "Maybe"],
        "data": [35, 5, 2]
      }
    }
  ]
}
```

##### `GET /admin/surveys/results/export`
Export all survey results to CSV or JSON.

**Query Parameters:**
- `format` (optional): `csv` or `json`, default: `csv`
- `survey_id` (optional): Filter by specific survey
- `include_responses` (optional): Include raw response text

#### 2. SurveyController.php Updates

Add methods:
```php
public function results(Survey $survey): JsonResponse
public function export(Request $request): Response
```

### Frontend Changes

#### 1. AdminSurveys.vue Updates

**New State:**
```javascript
const activeTab = ref('surveys') // 'surveys', 'questions', 'results'
const selectedSurveyForResults = ref(null)
const surveyResults = ref(null)
const loadingResults = ref(false)
```

**New Components:**
- `SurveyResultsOverview.vue` - List of surveys with response counts
- `SurveyResultsDetail.vue` - Detailed statistics for a specific survey

**Chart.js Integration:**
```javascript
import { Chart, BarController, PieController, LineController, CategoryScale, LinearScale, Tooltip, Legend } from 'chart.js'
```

#### 2. Results Tab UI Structure

```
┌─────────────────────────────────────────────┐
│ [Surveys] [Questions] [Results]             │
├─────────────────────────────────────────────┤
│                                             │
│ ┌───────────────────────────────────────┐   │
│ │ Survey Selection (Dropdown/Select)    │   │
│ └───────────────────────────────────────┘   │
│                                             │
│ ┌───────────────────────────────────────┐   │
│ │ Summary Cards:                        │   │
│ │ • Total Responses: 42                 │   │
│ │ • Response Rate: 75%                  │   │
│ │ • Avg Completion Time: 120s           │   │
│ └───────────────────────────────────────┘   │
│                                             │
│ ┌───────────────────────────────────────┐   │
│ │ Chart Container (Bar/Pie/Line)        │   │
│ └───────────────────────────────────────┘   │
│                                             │
│ ┌───────────────────────────────────────┐   │
│ │ Export Buttons: [CSV] [JSON]          │   │
│ └───────────────────────────────────────┘   │
│                                             │
│ ┌───────────────────────────────────────┐   │
│ │ Question Results (Accordion/List)     │   │
│ └───────────────────────────────────────┘   │
└─────────────────────────────────────────────┘
```

#### 3. Export Functionality

**CSV Export:**
```javascript
function exportToCSV(data, filename) {
  // Convert to CSV format
  // Download file
}
```

**JSON Export:**
```javascript
function exportToJSON(data, filename) {
  // Stringify data
  // Download file
}
```

## Implementation Steps

1. **Add Chart.js dependency** to frontend/package.json
2. **Create backend API endpoints** for results and export
3. **Update Survey model** relationships if needed
4. **Create Results tab UI** in AdminSurveys.vue
5. **Implement chart visualization** components
6. **Add export functionality**
7. **Test with sample data**

## Dependencies to Add

```json
{
  "dependencies": {
    "chart.js": "^4.4.0"
  }
}
```

## File Structure Changes

```
frontend/src/components/
├── AdminSurveys.vue (modified)
└── SurveyResultsDetail.vue (new)

backend/app/Http/Controllers/Api/
└── SurveyController.php (modified)

backend/routes/api.php
└── Add new survey results routes
```
