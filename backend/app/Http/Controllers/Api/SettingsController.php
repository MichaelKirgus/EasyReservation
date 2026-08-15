<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SettingsUpdateRequest;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\MediaService;
use App\Services\EventTriggerService;
use Illuminate\Http\JsonResponse;

class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly MediaService $media,
        private readonly EventTriggerService $eventTriggers,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->settings->all());
    }
    public function keys(): \Illuminate\Http\JsonResponse
    {
        $keys = Setting::query()->pluck('name')->unique()->values();
        return response()->json($keys);
    }

    public function update(SettingsUpdateRequest $request): JsonResponse
    {
        $settings = $request->validated('settings');
        $oldSettings = $this->settings->all();

        foreach ($settings as $name => $value) {
            if (is_array($value)) {
                return response()->json(['message' => __('settings_invalid_value_for', ['name' => $name])], 422);
            }
            if (! $this->media->isAllowedSetting($name, (string) ($value ?? ''))) {
                return response()->json([
                    'message' => __('settings_invalid_media_selection_for', ['name' => $name]),
                ], 422);
            }
        }

        foreach ($settings as $name => $value) {
            // Wert immer als String speichern (Bool zu 1/0, sonst String)
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif (is_null($value)) {
                $value = '';
            } else {
                $value = (string)$value;
            }
            // Use Eloquent model directly to ensure mutators are called for encryption handling
            $setting = Setting::firstOrNew(['name' => $name]);
            $setting->value = $value;  // This triggers setValueAttribute() which handles encryption
            $setting->save();
        }

        $this->settings->refresh();

        // Trigger-logic for reservation_enabled/reservation_disabled
        if (array_key_exists('reservation_enabled', $settings)) {
            $old = (int)($oldSettings['reservation_enabled'] ?? 0);
            $new = (int)$settings['reservation_enabled'];
            if ($old !== $new) {
                if ($new === 1) {
                    $this->eventTriggers->handle('reservation_enabled');
                } else {
                    $this->eventTriggers->handle('reservation_disabled');
                }
            }
        }

        $this->fireSettingChangedTrigger($oldSettings, $settings);

        return response()->json(['message' => __('admin_setting_change_success'), 'settings' => $this->settings->all()]);
    }

    /**
     * Export all settings as JSON
     */
    public function export(): JsonResponse
    {
        $settings = $this->settings->all();
        
        // Convert boolean strings back to actual booleans for cleaner export
        foreach ($settings as $key => $value) {
            if ($value === '1') {
                $settings[$key] = true;
            } elseif ($value === '0') {
                $settings[$key] = false;
            }
        }
        
        return response()->json($settings, 200)
            ->header('Content-Type', 'application/json')
            ->header('Content-Disposition', 'attachment; filename="settings-export.json"');
    }

    /**
     * Import settings from JSON
     */
    public function import(\Illuminate\Http\Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settings' => 'required|array',
            'settings.*' => 'nullable|string|json'
        ]);

        $importedSettings = $validated['settings'];
        $oldSettings = $this->settings->all();

        // Validate and process each setting
        foreach ($importedSettings as $name => $value) {
            // Skip if not in whitelist (only allow known settings)
            $settingExists = Setting::query()->where('name', $name)->exists();
            if (!$settingExists) {
                continue; // Skip unknown settings
            }

            // Convert boolean values to string for storage
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif (is_null($value)) {
                $value = '';
            } else {
                $value = (string)$value;
            }

            $setting = Setting::firstOrNew(['name' => $name]);
            $setting->value = $value;
            $setting->save();
        }

        $this->settings->refresh();

        // Trigger event triggers if reservation_enabled changed
        if (array_key_exists('reservation_enabled', $importedSettings)) {
            $old = (int)($oldSettings['reservation_enabled'] ?? 0);
            $new = (int)$importedSettings['reservation_enabled'];
            if ($old !== $new) {
                if ($new === 1) {
                    $this->eventTriggers->handle('reservation_enabled');
                } else {
                    $this->eventTriggers->handle('reservation_disabled');
                }
            }
        }

        $this->fireSettingChangedTrigger($oldSettings, $importedSettings);

        return response()->json(['message' => __('admin_setting_change_success'), 'settings' => $this->settings->all()]);
    }

    /**
     * Fire the generic setting_changed trigger for any settings whose value actually changed.
     */
    private function fireSettingChangedTrigger(array $oldSettings, array $submittedSettings): void
    {
        $changed = [];
        foreach ($submittedSettings as $name => $value) {
            $normalizedNew = is_bool($value) ? ($value ? '1' : '0') : (string) ($value ?? '');
            $normalizedOld = (string) ($oldSettings[$name] ?? '');
            if ($normalizedOld !== $normalizedNew) {
                $changed[$name] = ['old' => $normalizedOld, 'new' => $normalizedNew];
            }
        }

        if (empty($changed)) {
            return;
        }

        $user = auth()->user();
        $changedBy = $user ? trim(($user->name ?? '') . ' (' . ($user->role ?? '') . ')') : '';
        $summary = collect($changed)
            ->map(fn ($diff, $name) => $name . ': ' . $diff['old'] . ' -> ' . $diff['new'])
            ->implode(', ');

        $this->eventTriggers->handle('setting_changed', [
            'changed_settings' => $summary,
            'changed_by' => $changedBy,
            'user' => $user,
        ]);
    }

    // Gibt den Wert einer einzelnen Einstellung zurück
    public function show($key): JsonResponse
    {
        $setting = Setting::query()->where('name', $key)->first();
        if (!$setting) {
            return response()->json(['error' => __('not_found')], 404);
        }
        return response()->json(['value' => $setting->value]);
    }
}
