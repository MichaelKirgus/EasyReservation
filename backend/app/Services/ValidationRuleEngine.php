<?php

namespace App\Services;

use App\Models\ValidationRule;
use Illuminate\Support\Facades\Log;

class ValidationRuleEngine
{
    public function __construct(
        private readonly PlaceholderService $placeholderService,
        private readonly SettingsService $settings,
    ) {
    }

    public function evaluate(array $flatData, array $context = []): ValidationResult
    {
        Log::debug('[ValidationRuleEngine] Starting evaluation', [
            'fields' => array_keys($flatData),
        ]);

        // Set context placeholders if provided
        if (!empty($context)) {
            $this->placeholderService->setContextPlaceholders($context);
        }

        // Check if rule engine is globally enabled
        $isEnabled = (int) $this->settings->get('validation_rules_enabled', 1) === 1;
        if (!$isEnabled) {
            Log::debug('[ValidationRuleEngine] Rule engine is disabled, skipping validation');
            return ValidationResult::success();
        }

        $mode = (string) $this->settings->get('validation_rules_mode', 'blacklist');
        Log::debug('[ValidationRuleEngine] Mode', ['mode' => $mode]);

        $rules = ValidationRule::where('active', true)
            ->orderBy('sort_order')
            ->get();

        Log::debug('[ValidationRuleEngine] Loaded rules', [
            'total_rules' => $rules->count(),
        ]);

        foreach ($rules as $rule) {
            $input = $flatData[$rule->field_key] ?? null;
            
            Log::debug('[ValidationRuleEngine] Processing rule', [
                'rule_id' => $rule->id,
                'rule_name' => $rule->name,
                'field_key' => $rule->field_key,
                'condition_type' => $rule->condition_type,
                'condition_operator' => $rule->condition_operator,
                'input_value_length' => $input ? mb_strlen((string) $input) : 0,
            ]);

            if ($input === null) {
                Log::debug('[ValidationRuleEngine] Field not present in data, skipping rule', [
                    'rule_id' => $rule->id,
                ]);
                continue;
            }

            $conditionMet = $this->evaluateCondition($rule, (string) $input);
            
            Log::debug('[ValidationRuleEngine] Condition evaluated', [
                'rule_id' => $rule->id,
                'condition_met' => $conditionMet,
            ]);

            // Determine if rule triggers (causes rejection)
            // Blacklist mode: rule triggers when condition is met (forbidden content found)
            // Whitelist mode: rule triggers when condition is NOT met (requirement not met)
            $ruleTriggered = ($mode === 'blacklist') ? $conditionMet : !$conditionMet;

            if ($ruleTriggered) {
                Log::warning('[ValidationRuleEngine] Rule triggered', [
                    'rule_id' => $rule->id,
                    'rule_name' => $rule->name,
                    'field_key' => $rule->field_key,
                    'mode' => $mode,
                ]);

                // Trigger webhook if configured
                if ($rule->webhook_template_id) {
                    Log::info('[ValidationRuleEngine] Dispatching webhook', [
                        'rule_id' => $rule->id,
                        'webhook_template_id' => $rule->webhook_template_id,
                    ]);
                    
                    try {
                        app(WebhookService::class)
                            ->sendTemplate($rule->webhook_template_id, [
                                'triggered_by_rule_id' => $rule->id,
                                'triggered_by_rule_name' => $rule->name,
                                'field_key' => $rule->field_key,
                                'validation_mode' => $mode,
                            ]);
                    } catch (\Exception $e) {
                        Log::error('[ValidationRuleEngine] Webhook dispatch failed', [
                            'rule_id' => $rule->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $errorMessage = $rule->error_message ?? __('validation_rule_failed');
                Log::warning('[ValidationRuleEngine] Validation rejected with message', [
                    'rule_id' => $rule->id,
                    'message' => $errorMessage,
                ]);

                return ValidationResult::failure($errorMessage);
            }
        }

        Log::debug('[ValidationRuleEngine] All rules passed, validation successful');
        return ValidationResult::success();
    }

    private function evaluateCondition(ValidationRule $rule, string $input): bool
    {
        try {
            return match ($rule->condition_type) {
                'length' => $this->evaluateLength($input, $rule->condition_operator, $rule->condition_value),
                'regex' => $this->evaluateRegex($input, $rule->condition_operator, $rule->condition_value),
                'unicode_category' => $this->evaluateUnicodeCategory($input, $rule->condition_operator, $rule->condition_value),
                'contains' => $this->evaluateContains($input, $rule->condition_operator, $rule->condition_value),
                    'email_format'  => $this->evaluateEmailFormat($input, $rule->condition_operator),
                    default         => false,
            };
        } catch (\Exception $e) {
            Log::error('[ValidationRuleEngine] Condition evaluation error', [
                'rule_id' => $rule->id,
                'condition_type' => $rule->condition_type,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function evaluateLength(string $input, string $operator, string $value): bool
    {
        $length = mb_strlen($input);
        $threshold = (int) $value;
        
        return match ($operator) {
            '<' => $length < $threshold,
            '>' => $length > $threshold,
            '=' => $length === $threshold,
            '<=' => $length <= $threshold,
            '>=' => $length >= $threshold,
            default => false,
        };
    }

    private function evaluateRegex(string $input, string $operator, string $pattern): bool
    {
        $matches = @preg_match($pattern, $input);
        if ($matches === false) {
            throw new \InvalidArgumentException("Invalid regex pattern: {$pattern}");
        }
        
        return match ($operator) {
            'match' => $matches === 1,
            'not_match' => $matches !== 1,
            default => false,
        };
    }

    private function evaluateUnicodeCategory(string $input, string $operator, string $category): bool
    {
        $pattern = '/\p{' . preg_quote($category, '/') . '}/u';
        $matches = @preg_match($pattern, $input);
        if ($matches === false) {
            throw new \InvalidArgumentException("Invalid unicode category: {$category}");
        }
        
        return match ($operator) {
            'contains' => $matches === 1,
            'not_contains' => $matches !== 1,
            default => false,
        };
    }

    private function evaluateContains(string $input, string $operator, string $needle): bool
    {
        // Resolve placeholders in the needle
        $resolvedNeedle = $this->placeholderService->replaceString($needle);

        $result = str_contains(
            mb_strtolower($input),
            mb_strtolower($resolvedNeedle)
        );
        
        return match ($operator) {
            'contains' => $result,
            'not_contains' => !$result,
            default => false,
        };
    }

        private function evaluateEmailFormat(string $input, string $operator): bool
        {
            $isValid = filter_var($input, FILTER_VALIDATE_EMAIL) !== false;

            return match ($operator) {
                'valid'   => $isValid,
                'invalid' => !$isValid,
                default   => false,
            };
        }
    }
