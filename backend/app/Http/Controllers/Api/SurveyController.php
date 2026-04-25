<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Survey;
use App\Models\GlobalQuestion;
use App\Models\SurveyQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SurveyController extends Controller
{
    // GET /admin/surveys
    public function index(): JsonResponse
    {
        $surveys = Survey::with(['event', 'questions.globalQuestion'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($surveys);
    }

    // POST /admin/surveys
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'event_id' => 'nullable|exists:events,id',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'active' => 'boolean',
        ]);

        $survey = Survey::create($data);

        return response()->json($survey, 201);
    }

    // GET /admin/surveys/{id}
    public function show(Survey $survey): JsonResponse
    {
        $survey->load(['event', 'questions.globalQuestion']);

        return response()->json($survey);
    }

    // PUT /admin/surveys/{id}
    public function update(Request $request, Survey $survey): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'event_id' => 'nullable|exists:events,id',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'active' => 'boolean',
        ]);

        $survey->update($data);

        return response()->json($survey);
    }

    // DELETE /admin/surveys/{id}
    public function destroy(Survey $survey): JsonResponse
    {
        $survey->delete();

        return response()->json(['message' => 'Survey deleted']);
    }

    // GET /admin/surveys/{id}/questions
    public function getQuestions(Survey $survey): JsonResponse
    {
        $questions = SurveyQuestion::where('survey_id', $survey->id)
            ->with('globalQuestion')
            ->orderBy('display_order')
            ->get();

        return response()->json($questions);
    }

    // POST /admin/surveys/{id}/questions
    public function addQuestion(Request $request, Survey $survey): JsonResponse
    {
        $data = $request->validate([
            'global_question_id' => 'nullable|exists:global_questions,id',
            'question_text' => 'required|string',
            'field_type' => 'in:score_1_5,text_open,text_multiple_choice,number_input',
            'is_required' => 'boolean',
            'display_order' => 'integer',
            'options' => 'array',
            'min_value' => 'nullable|integer',
            'max_value' => 'nullable|integer',
            'active' => 'boolean',
        ]);

        $question = SurveyQuestion::create([
            'survey_id' => $survey->id,
            'global_question_id' => $data['global_question_id'] ?? null,
            'question_text' => $data['question_text'],
            'field_type' => $data['field_type'] ?? 'text_open',
            'is_required' => $data['is_required'] ?? false,
            'display_order' => $data['display_order'] ?? 0,
            'options' => $data['options'] ?? null,
            'min_value' => $data['min_value'] ?? null,
            'max_value' => $data['max_value'] ?? null,
            'active' => $data['active'] ?? true,
        ]);

        return response()->json($question, 201);
    }

    // PUT /admin/surveys/{id}/questions/{questionId}
    public function updateQuestion(Request $request, Survey $survey, SurveyQuestion $question): JsonResponse
    {
        if ($question->survey_id !== $survey->id) {
            return response()->json(['message' => 'Question does not belong to this survey'], 403);
        }

        $data = $request->validate([
            'global_question_id' => 'nullable|exists:global_questions,id',
            'question_text' => 'required|string',
            'field_type' => 'in:score_1_5,text_open,text_multiple_choice,number_input',
            'is_required' => 'boolean',
            'display_order' => 'integer',
            'options' => 'array',
            'min_value' => 'nullable|integer',
            'max_value' => 'nullable|integer',
            'active' => 'boolean',
        ]);

        $question->update([
            'global_question_id' => $data['global_question_id'] ?? null,
            'question_text' => $data['question_text'],
            'field_type' => $data['field_type'],
            'is_required' => $data['is_required'] ?? false,
            'display_order' => $data['display_order'] ?? 0,
            'options' => $data['options'] ?? null,
            'min_value' => $data['min_value'] ?? null,
            'max_value' => $data['max_value'] ?? null,
            'active' => $data['active'] ?? true,
        ]);

        return response()->json($question);
    }

    // DELETE /admin/surveys/{id}/questions/{questionId}
    public function deleteQuestion(Survey $survey, SurveyQuestion $question): JsonResponse
    {
        if ($question->survey_id !== $survey->id) {
            return response()->json(['message' => 'Question does not belong to this survey'], 403);
        }

        $question->delete();

        return response()->json(['message' => 'Question deleted']);
    }

    // GET /admin/global-questions
    public function getGlobalQuestions(): JsonResponse
    {
        $questions = GlobalQuestion::orderBy('display_order')->get();

        return response()->json($questions);
    }

    // POST /admin/global-questions
    public function createGlobalQuestion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'question_text' => 'required|string',
            'field_type' => 'in:score_1_5,text_open,text_multiple_choice,number_input',
            'is_required' => 'boolean',
            'display_order' => 'integer',
            'options' => 'array',
            'min_value' => 'nullable|integer',
            'max_value' => 'nullable|integer',
        ]);

        $question = GlobalQuestion::create($data);

        return response()->json($question, 201);
    }

    // PUT /admin/global-questions/{id}
    public function updateGlobalQuestion(Request $request, GlobalQuestion $question): JsonResponse
    {
        $data = $request->validate([
            'question_text' => 'required|string',
            'field_type' => 'in:score_1_5,text_open,text_multiple_choice,number_input',
            'is_required' => 'boolean',
            'display_order' => 'integer',
            'options' => 'array',
            'min_value' => 'nullable|integer',
            'max_value' => 'nullable|integer',
        ]);

        $question->update($data);

        return response()->json($question);
    }

    // DELETE /admin/global-questions/{id}
    public function deleteGlobalQuestion(GlobalQuestion $question): JsonResponse
    {
        $question->delete();

        return response()->json(['message' => 'Question deleted']);
    }

    // GET /admin/surveys/{survey}/preview
    public function preview(Survey $survey): JsonResponse
    {
        $placeholderService = app(\App\Services\PlaceholderService::class);
        
        // Get all global questions (since questions are now defined globally)
        $questions = GlobalQuestion::orderBy('display_order')->get()
            ->map(function ($q) use ($placeholderService) {
                // Replace placeholders in question text
                $q->question_text = $placeholderService->replaceString($q->question_text);
                
                // Also replace placeholders in options for multiple choice questions
                if ($q->options && is_array($q->options)) {
                    $q->options = array_map(fn($opt) => $placeholderService->replaceString($opt), $q->options);
                }
                
                return $q;
            });

        return response()->json([
            'survey' => [
                'id' => $survey->id,
                'title' => $survey->title,
                'description' => $survey->description,
                'starts_at' => $survey->starts_at?->toIso8601String(),
                'ends_at' => $survey->ends_at?->toIso8601String(),
                'active' => $survey->active,
            ],
            'questions' => $questions,
        ]);
    }

    // GET /admin/surveys/{survey}/results
    public function results(Survey $survey): JsonResponse
    {
        $survey->load(['questions', 'responses']);

        $summary = [
            'total_responses' => $survey->responses()->where('is_responded', true)->count(),
            'response_rate' => 0,
            'avg_completion_time' => null,
        ];

        // Calculate response rate if we have estimated total potential respondents
        // For now, just show the count
        $summary['response_rate'] = $survey->responses()->where('is_responded', true)->count();

        $questionsData = $survey->questions()
            ->with('globalQuestion')
            ->orderBy('display_order')
            ->get()
            ->map(function ($question) {
                $statistics = [
                    'total_responses' => 0,
                    'distribution' => [],
                    'average_score' => null,
                    'responses' => [],
                ];

                if ($question->field_type === 'score_1_5') {
                    // Get score distribution
                    $scores = $question->responses()
                        ->where('is_responded', true)
                        ->select('response_score', \DB::raw('count(*) as count'))
                        ->groupBy('response_score')
                        ->orderBy('response_score')
                        ->get();

                    $distribution = [];
                    foreach ($scores as $score) {
                        $distribution[(string)$score->response_score] = (int)$score->count;
                    }

                    // Fill missing scores with 0
                    for ($i = 1; $i <= 5; $i++) {
                        if (!isset($distribution[$i])) {
                            $distribution[$i] = 0;
                        }
                    }

                    // Calculate average
                    $avg = $question->responses()
                        ->where('is_responded', true)
                        ->avg('response_score');

                    $statistics = [
                        'total_responses' => (int)$scores->sum('count'),
                        'distribution' => $distribution,
                        'average_score' => round($avg, 2),
                        'responses' => [],
                    ];
                } elseif ($question->field_type === 'text_multiple_choice') {
                    // Get option distribution
                    $options = json_decode($question->options, true) ?: [];
                    
                    $responseCounts = $question->responses()
                        ->where('is_responded', true)
                        ->select('response_text', \DB::raw('count(*) as count'))
                        ->groupBy('response_text')
                        ->get();

                    $distribution = [];
                    foreach ($options as $option) {
                        $count = $responseCounts->firstWhere('response_text', $option)?->count ?? 0;
                        $distribution[$option] = (int)$count;
                    }

                    // Add any responses not in options
                    foreach ($responseCounts as $rc) {
                        if (!isset($distribution[$rc->response_text])) {
                            $distribution[$rc->response_text] = (int)$rc->count;
                        }
                    }

                    $statistics = [
                        'total_responses' => (int)$responseCounts->sum('count'),
                        'distribution' => $distribution,
                        'average_score' => null,
                        'responses' => [],
                    ];
                } elseif ($question->field_type === 'number_input') {
                    // number_input - get statistics
                    $responses = $question->responses()
                        ->where('is_responded', true)
                        ->whereNotNull('response_text')
                        ->pluck('response_text')
                        ->filter(fn($v) => $v !== '')
                        ->toArray();

                    $numericResponses = array_filter($responses, fn($v) => is_numeric($v));
                    $avg = !empty($numericResponses) ? array_sum($numericResponses) / count($numericResponses) : null;

                    $statistics = [
                        'total_responses' => count($responses),
                        'distribution' => [],
                        'average_score' => $avg !== null ? round($avg, 2) : null,
                        'min_value' => !empty($numericResponses) ? round(min($numericResponses), 2) : null,
                        'max_value' => !empty($numericResponses) ? round(max($numericResponses), 2) : null,
                        'responses' => $responses,
                    ];
                } else {
                    // text_open - get all responses
                    $responses = $question->responses()
                        ->where('is_responded', true)
                        ->pluck('response_text')
                        ->filter()
                        ->toArray();

                    $statistics = [
                        'total_responses' => count($responses),
                        'distribution' => [],
                        'average_score' => null,
                        'responses' => $responses,
                    ];
                }

                return [
                    'id' => $question->id,
                    'question_text' => $question->globalQuestion?->question_text ?? $question->question_text,
                    'field_type' => $question->field_type,
                    'options' => $question->options,
                    'statistics' => $statistics,
                ];
            });

        return response()->json([
            'survey' => [
                'id' => $survey->id,
                'title' => $survey->title,
                'description' => $survey->description,
                'starts_at' => $survey->starts_at?->toIso8601String(),
                'ends_at' => $survey->ends_at?->toIso8601String(),
                'active' => $survey->active,
            ],
            'summary' => $summary,
            'questions' => $questionsData,
        ]);
    }

    // GET /admin/surveys/results/export
    public function export(Request $request): JsonResponse
    {
        $format = $request->query('format', 'csv');
        $surveyId = $request->query('survey_id');
        $includeResponses = $request->query('include_responses', 'false') === 'true';

        $query = SurveyResponse::with(['question.globalQuestion', 'survey'])
            ->where('is_responded', true);

        if ($surveyId) {
            $query->where('survey_id', $surveyId);
        }

        $responses = $query->get();

        if ($format === 'json') {
            return response()->json($responses, 200, [], JSON_PRETTY_PRINT);
        }

        // CSV export
        $csv = "Survey ID,Survey Title,Question ID,Question Text,Response Text,Response Score,Response Time\n";
        
        foreach ($responses as $response) {
            $questionText = $response->question->globalQuestion?->question_text ?? $response->question->question_text;
            $surveyTitle = $response->survey?->title ?? 'Unknown';
            
            // Escape CSV fields
            $responseText = str_replace('"', '""', $includeResponses ? ($response->response_text ?? '') : '');
            $responseScore = $response->response_score ?? '';
            $responseTime = $response->updated_at?->toIso8601String() ?? '';

            $csv .= "\"$surveyTitle\",\"$questionText\",{$response->question_id},\"$responseText\",$responseScore,$responseTime\n";
        }

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="survey_results_' . date('Y-m-d_His') . '.csv"',
        ];

        return response($csv, 200, $headers);
    }
}
