<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Survey;
use App\Models\GlobalQuestion;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use App\Services\PlaceholderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PublicSurveyController extends Controller
{
    // GET /surveys/{surveyId}
    public function show(Survey $survey)
    {
        if (!$survey->active) {
            return response()->json(['message' => __('survey_not_active')], 403);
        }

        $placeholderService = app(\App\Services\PlaceholderService::class);

        // Get survey questions, or create them from global questions if none exist
        $questions = SurveyQuestion::where('survey_id', $survey->id)
            ->with(['globalQuestion' => function ($query) {
                $query->select('id', 'question_text', 'field_type', 'is_required', 'display_order', 'options');
            }])
            ->orderBy('display_order')
            ->get();

        // If no survey questions exist, create them from global questions
        if ($questions->isEmpty()) {
            $globalQuestions = GlobalQuestion::orderBy('display_order')->get();
            
            foreach ($globalQuestions as $globalQuestion) {
                SurveyQuestion::create([
                    'survey_id' => $survey->id,
                    'global_question_id' => $globalQuestion->id,
                    'question_text' => $globalQuestion->question_text,
                    'field_type' => $globalQuestion->field_type,
                    'is_required' => $globalQuestion->is_required,
                    'display_order' => $globalQuestion->display_order,
                    'options' => $globalQuestion->options,
                    'active' => true,
                ]);
            }

            // Reload questions with global question relationship
            $questions = SurveyQuestion::where('survey_id', $survey->id)
                ->with(['globalQuestion' => function ($query) {
                    $query->select('id', 'question_text', 'field_type', 'is_required', 'display_order', 'options');
                }])
                ->orderBy('display_order')
                ->get();
        }

        // Apply placeholder replacement to questions
        $questions = $questions->map(function ($q) use ($placeholderService) {
            // Use global question text if available and survey question text is null
            if ($q->globalQuestion && $q->question_text === null) {
                $q->question_text = $q->globalQuestion->question_text;
            }
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
            ],
            'questions' => $questions,
        ]);
    }

    // POST /surveys/{surveyId}/submit
    public function submit(Request $request, Survey $survey)
    {
        $data = $request->validate([
            'token' => 'required|string',
            'responses' => 'required|array',
            'responses.*.question_id' => 'required|exists:survey_questions,id',
            'responses.*.response_text' => 'nullable|string|max:10000',
            'responses.*.response_score' => 'nullable|integer|min:1|max:5',
        ]);

        // Check if survey is active
        if (!$survey->active) {
            return response()->json(['message' => __('survey_not_active')], 403);
        }

        // Check time window
        $now = now();
        if ($survey->starts_at && $now->lt($survey->starts_at)) {
            return response()->json(['message' => __('survey_not_started')], 403);
        }
        if ($survey->ends_at && $now->gt($survey->ends_at)) {
            return response()->json(['message' => __('survey_ended')], 403);
        }

        // Check if user has already responded
        if (SurveyResponse::hasResponded($survey->id, $data['token'])) {
            return response()->json(['message' => __('already_responded')], 409);
        }

        DB::beginTransaction();
        try {
            foreach ($data['responses'] as $response) {
                // Validate required fields
                $question = SurveyQuestion::find($response['question_id']);
                
                if (!$question || $question->survey_id !== $survey->id) {
                    return response()->json([
                        'message' => __('question_not_found'),
                    ], 422);
                }

                if ($question->is_required && 
                    empty($response['response_text']) && 
                    empty($response['response_score'])) {
                    return response()->json([
                        'message' => __('field_required', ['field' => $question->question_text]),
                    ], 422);
                }

                // Validate score range
                if (isset($response['response_score']) && 
                    ($response['response_score'] < 1 || $response['response_score'] > 5)) {
                    return response()->json([
                        'message' => __('score_must_be_1_to_5'),
                    ], 422);
                }

                // Create or update response
                SurveyResponse::updateOrCreate(
                    [
                        'survey_id' => $survey->id,
                        'question_id' => $response['question_id'],
                        'responder_token' => $data['token'],
                    ],
                    [
                        'response_text' => $response['response_text'] ?? null,
                        'response_score' => $response['response_score'] ?? null,
                        'is_responded' => true,
                    ]
                );
            }

            DB::commit();

            // Build submission message with placeholder replacement
            $submissionMessage = $this->buildSubmissionMessage($survey);

            return response()->json([
                'message' => __('survey_submitted'),
                'submission_message' => $submissionMessage,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Survey submission failed', [
                'survey_id' => $survey->id,
                'token' => $data['token'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => __('survey_submission_failed'),
            ], 500);
        }
    }

    // GET /surveys/{surveyId}/check-status
    public function checkStatus(Survey $survey, Request $request)
    {
        $token = $request->query('token');

        if (!$token) {
            return response()->json([
                'can_respond' => true,
                'message' => __('enter_survey'),
            ]);
        }

        // Check if already responded
        if (SurveyResponse::hasResponded($survey->id, $token)) {
            $alreadyRespondedMessage = $this->buildAlreadyRespondedMessage($survey);
            return response()->json([
                'can_respond' => false,
                'already_responded' => true,
                'message' => __('thank_you_for_response'),
                'already_responded_message' => $alreadyRespondedMessage,
            ]);
        }

        // Check time window
        $now = now();
        if ($survey->starts_at && $now->lt($survey->starts_at)) {
            return response()->json([
                'can_respond' => false,
                'message' => __('survey_not_started'),
            ]);
        }
        if ($survey->ends_at && $now->gt($survey->ends_at)) {
            return response()->json([
                'can_respond' => false,
                'message' => __('survey_ended'),
            ]);
        }

        return response()->json([
            'can_respond' => true,
            'message' => __('ready_to_respond'),
        ]);
    }

    /**
     * Build the submission message with placeholder replacement.
     */
    private function buildSubmissionMessage(Survey $survey): string
    {
        $message = $survey->submission_message;

        // Use default message if no custom message is set
        if (empty($message)) {
            $settingsService = app(SettingsService::class);
            $defaultMessage = $settingsService->surveyDefaultSubmissionMessage();
            $message = $defaultMessage ?: __('survey_default_submission_message');
        }

        // Use PlaceholderService to replace placeholders
        $placeholderService = app(PlaceholderService::class);
        $placeholderService->setSurvey($survey);
        return $placeholderService->replaceString($message);
    }

    /**
     * Build the already-responded message with placeholder replacement.
     */
    private function buildAlreadyRespondedMessage(Survey $survey): string
    {
        $message = $survey->already_responded_message;

        // Use default message if no custom message is set
        if (empty($message)) {
            $settingsService = app(SettingsService::class);
            $defaultMessage = $settingsService->surveyDefaultAlreadyRespondedMessage();
            $message = $defaultMessage ?: __('survey_default_already_responded_message');
        }

        // Use PlaceholderService to replace placeholders
        $placeholderService = app(PlaceholderService::class);
        $placeholderService->setSurvey($survey);
        return $placeholderService->replaceString($message);
    }
}
