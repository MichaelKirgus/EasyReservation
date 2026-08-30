<?php

namespace Tests\Feature;

use App\Models\GlobalQuestion;
use App\Models\Survey;
use App\Models\SurveyQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SurveyApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setSetting('reservation_token_enabled', 0);
    }

    public function test_admin_can_create_survey_and_question(): void
    {
        $admin = $this->createApiUser('admin');

        $survey = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/surveys', [
                'title' => 'Customer Feedback',
                'description' => 'Tell us what you think',
                'active' => true,
            ]);
        $survey->assertCreated()->assertJsonPath('title', 'Customer Feedback');

        $question = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/surveys/'.$survey->json('id').'/questions', [
                'question_text' => 'How was your visit?',
                'field_type' => 'text_open',
                'is_required' => true,
                'display_order' => 0,
            ]);
        $question->assertCreated()->assertJsonPath('question_text', 'How was your visit?');
    }

    public function test_public_survey_can_be_loaded_and_submitted_once(): void
    {
        $survey = Survey::create([
            'title' => 'Public Survey',
            'active' => true,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);
        $question = SurveyQuestion::create([
            'survey_id' => $survey->id,
            'question_text' => 'Score?',
            'field_type' => 'score_1_5',
            'is_required' => true,
            'display_order' => 0,
            'active' => true,
        ]);
        $token = $this->validSiteToken();
        $headers = ['X-Site-Token' => $token];

        $show = $this->withHeaders($headers)
            ->getJson('/api/surveys/'.$survey->id);
        $show->assertOk()
            ->assertJsonPath('survey.title', 'Public Survey')
            ->assertJsonPath('questions.0.question_text', 'Score?');

        $submit = $this->withHeaders($headers)
            ->postJson('/api/surveys/'.$survey->id.'/submit', [
                'token' => 'respondent-1',
                'responses' => [[
                    'question_id' => $question->id,
                    'response_score' => 5,
                ]],
            ]);
        $submit->assertOk()->assertJsonPath('message', __('survey_submitted'));

        $duplicate = $this->withHeaders($headers)
            ->postJson('/api/surveys/'.$survey->id.'/submit', [
                'token' => 'respondent-1',
                'responses' => [[
                    'question_id' => $question->id,
                    'response_score' => 4,
                ]],
            ]);
        $duplicate->assertConflict();
    }

    public function test_public_survey_rejects_missing_required_response(): void
    {
        $survey = Survey::create(['title' => 'Required Survey', 'active' => true]);
        $question = SurveyQuestion::create([
            'survey_id' => $survey->id,
            'question_text' => 'Required answer',
            'field_type' => 'text_open',
            'is_required' => true,
            'display_order' => 0,
            'active' => true,
        ]);

        $response = $this->withHeaders(['X-Site-Token' => $this->validSiteToken()])
            ->postJson('/api/surveys/'.$survey->id.'/submit', [
                'token' => 'respondent-2',
                'responses' => [['question_id' => $question->id]],
            ]);

        $response->assertUnprocessable();
    }

    public function test_moderator_cannot_manage_admin_surveys(): void
    {
        $moderator = $this->createApiUser('moderator');

        $response = $this->withHeaders($this->apiHeaders($moderator))
            ->getJson('/api/admin/surveys');

        $response->assertForbidden();
    }
}
