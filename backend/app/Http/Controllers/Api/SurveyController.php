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
            'field_type' => 'in:score_1_5,text_open,text_multiple_choice',
            'is_required' => 'boolean',
            'display_order' => 'integer',
            'options' => 'array',
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
            'field_type' => 'in:score_1_5,text_open,text_multiple_choice',
            'is_required' => 'boolean',
            'display_order' => 'integer',
            'options' => 'array',
            'active' => 'boolean',
        ]);

        $question->update($data);

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
            'field_type' => 'in:score_1_5,text_open,text_multiple_choice',
            'is_required' => 'boolean',
            'display_order' => 'integer',
            'options' => 'array',
        ]);

        $question = GlobalQuestion::create($data);

        return response()->json($question, 201);
    }

    // PUT /admin/global-questions/{id}
    public function updateGlobalQuestion(Request $request, GlobalQuestion $question): JsonResponse
    {
        $data = $request->validate([
            'question_text' => 'required|string',
            'field_type' => 'in:score_1_5,text_open,text_multiple_choice',
            'is_required' => 'boolean',
            'display_order' => 'integer',
            'options' => 'array',
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
}
