<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SurveyQuestion>
 */
class SurveyQuestionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'survey_id' => \App\Models\Survey::factory(),
            'question_text' => fake()->question(),
            'field_type' => 'text_open',
            'is_required' => false,
            'display_order' => 0,
            'options' => null,
        ];
    }

    /**
     * Question belonging to a specific survey.
     */
    public function forSurvey(\App\Models\Survey $survey): static
    {
        return $this->state(fn (array $attributes) => [
            'survey_id' => $survey->id,
        ]);
    }

    /**
     * 1-5 score question.
     */
    public function score(): static
    {
        return $this->state(fn (array $attributes) => [
            'field_type' => 'score_1_5',
        ]);
    }

    /**
     * Multiple choice question with options.
     */
    public function multipleChoice(array $options = ['A', 'B', 'C']): static
    {
        return $this->state(fn (array $attributes) => [
            'field_type' => 'text_multiple_choice',
            'options' => $options,
        ]);
    }

    /**
     * Required question.
     */
    public function required(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_required' => true,
        ]);
    }
}
