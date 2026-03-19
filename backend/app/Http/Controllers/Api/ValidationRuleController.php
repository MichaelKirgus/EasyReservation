<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FormField;
use App\Models\Setting;
use App\Models\ValidationRule;
use App\Models\WebhookTemplate;
use App\Services\SettingsService;
use Illuminate\Http\Request;

class ValidationRuleController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {
    }
    public function index()
    {
        $rules = ValidationRule::with('webhookTemplate')
            ->orderBy('sort_order')
            ->get()
            ->map(function ($rule) {
                return [
                    'id' => $rule->id,
                    'name' => $rule->name,
                    'description' => $rule->description,
                    'active' => $rule->active,
                    'field_key' => $rule->field_key,
                    'condition_type' => $rule->condition_type,
                    'condition_operator' => $rule->condition_operator,
                    'condition_value' => $rule->condition_value,
                    'error_message' => $rule->error_message,
                    'webhook_template_id' => $rule->webhook_template_id,
                    'webhook_template_name' => $rule->webhookTemplate?->name,
                    'sort_order' => $rule->sort_order,
                    'created_at' => $rule->created_at,
                    'updated_at' => $rule->updated_at,
                ];
            });

        return response()->json($rules);
    }

    public function show($id)
    {
        $rule = ValidationRule::with('webhookTemplate')->findOrFail($id);
        return response()->json($rule);
    }

    public function store(Request $request)
    {
        $data = $this->validateRule($request);
        
        // Set default sort_order to append at end
        $data['sort_order'] = ValidationRule::max('sort_order') + 1 ?? 0;
        
        $rule = ValidationRule::create($data);
        
        return response()->json([
            'id' => $rule->id,
            'name' => $rule->name,
            'description' => $rule->description,
            'active' => $rule->active,
            'field_key' => $rule->field_key,
            'condition_type' => $rule->condition_type,
            'condition_operator' => $rule->condition_operator,
            'condition_value' => $rule->condition_value,
            'error_message' => $rule->error_message,
            'webhook_template_id' => $rule->webhook_template_id,
            'webhook_template_name' => $rule->webhookTemplate?->name,
            'sort_order' => $rule->sort_order,
            'created_at' => $rule->created_at,
            'updated_at' => $rule->updated_at,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $rule = ValidationRule::findOrFail($id);
        $data = $this->validateRule($request);
        
        $rule->update($data);
        
        return response()->json([
            'id' => $rule->id,
            'name' => $rule->name,
            'description' => $rule->description,
            'active' => $rule->active,
            'field_key' => $rule->field_key,
            'condition_type' => $rule->condition_type,
            'condition_operator' => $rule->condition_operator,
            'condition_value' => $rule->condition_value,
            'error_message' => $rule->error_message,
            'webhook_template_id' => $rule->webhook_template_id,
            'webhook_template_name' => $rule->webhookTemplate?->name,
            'sort_order' => $rule->sort_order,
            'created_at' => $rule->created_at,
            'updated_at' => $rule->updated_at,
        ]);
    }

    public function destroy($id)
    {
        $rule = ValidationRule::findOrFail($id);
        $rule->delete();
        
        return response()->json(['success' => true, 'message' => 'Validation rule deleted successfully']);
    }

    /**
     * Get current configuration (mode, enabled status)
     */
    public function getConfig()
    {
        return response()->json([
            'enabled' => (int) $this->settings->get('validation_rules_enabled', 1) === 1,
            'mode' => (string) $this->settings->get('validation_rules_mode', 'blacklist'),
        ]);
    }

    /**
     * Update configuration (mode, enabled status)
     */
    public function updateConfig(Request $request)
    {
        $request->validate([
            'enabled' => 'required|boolean',
            'mode' => 'required|in:whitelist,blacklist',
        ]);

        $enabled = $request->boolean('enabled') ? '1' : '0';
        $mode = (string) $request->input('mode');

        $enabledSetting = Setting::firstOrNew(['name' => 'validation_rules_enabled']);
        $enabledSetting->value = $enabled;
        $enabledSetting->save();

        $modeSetting = Setting::firstOrNew(['name' => 'validation_rules_mode']);
        $modeSetting->value = $mode;
        $modeSetting->save();

        $this->settings->refresh();

        return response()->json([
            'enabled' => (int) $this->settings->get('validation_rules_enabled', 1) === 1,
            'mode' => (string) $this->settings->get('validation_rules_mode', 'blacklist'),
        ]);
    }

    /**
     * Reorder rules via drag-and-drop
     */
    public function reorder(Request $request)
    {
        $request->validate([
            'rules' => 'required|array',
            'rules.*.id' => 'required|integer|exists:validation_rules,id',
            'rules.*.sort_order' => 'required|integer',
        ]);

        foreach ($request->input('rules') as $item) {
            ValidationRule::where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json(['success' => true, 'message' => 'Rules reordered successfully']);
    }

    /**
     * Get available fields for validation
     */
    public function getAvailableFields()
    {
        $fields = [
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'email', 'label' => 'Email'],
        ];

        // Add custom form fields
        $customFields = FormField::where('active', true)
            ->get()
            ->map(fn ($field) => ['key' => $field->key, 'label' => $field->label])
            ->toArray();

        return response()->json(array_merge($fields, $customFields));
    }

    /**
     * Get available webhook templates
     */
    public function getWebhookTemplates()
    {
        $templates = WebhookTemplate::all(['id', 'name', 'url']);
        return response()->json($templates);
    }

    /**
     * Validate rule request data
     */
    private function validateRule(Request $request): array
    {
        // Frontend number inputs may submit JSON numbers; normalize to string for consistent rule evaluation.
        $rawConditionValue = $request->input('condition_value');
        if (is_int($rawConditionValue) || is_float($rawConditionValue)) {
            $request->merge(['condition_value' => (string) $rawConditionValue]);
        }

        // Empty strings are converted to null by middleware. Email format rules do not need a value,
        // but the database column is non-null, so persist an empty string for consistency.
        if ($request->input('condition_type') === 'email_format' && $rawConditionValue === null) {
            $request->merge(['condition_value' => '']);
        }

        // Get available field keys
        $availableFields = ['name', 'email'];
        $customFieldKeys = FormField::where('active', true)->pluck('key')->toArray();
        $availableFields = array_merge($availableFields, $customFieldKeys);

        // Get allowed operators per condition type
        $operatorsByType = [
            'length' => ['<', '>', '=', '<=', '>='],
            'regex' => ['match', 'not_match'],
            'unicode_category' => ['contains', 'not_contains'],
            'contains' => ['contains', 'not_contains'],
            'email_format' => ['valid', 'invalid'],
        ];

        $conditionType = $request->input('condition_type');
        $allowedOperators = $operatorsByType[$conditionType] ?? [];

        return $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'active' => 'required|boolean',
            'field_key' => ['required', 'string', 'in:' . implode(',', $availableFields)],
            'condition_type' => 'required|in:length,regex,unicode_category,contains,email_format',
            'condition_operator' => ['required', 'string', 'in:' . implode(',', $allowedOperators)],
            'condition_value' => $conditionType === 'email_format' ? 'nullable|string' : 'required|string',
            'error_message' => 'nullable|string|max:500',
            'webhook_template_id' => 'nullable|integer|exists:webhook_templates,id',
        ]);
    }
}
