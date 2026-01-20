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
                return response()->json(['message' => 'Invalid value for '.$name], 422);
            }
            if (! $this->media->isAllowedSetting($name, (string) ($value ?? ''))) {
                return response()->json([
                    'message' => 'Invalid media selection for '.$name,
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
            Setting::query()->updateOrCreate(
                ['name' => $name],
                ['value' => $value]
            );
        }

        $this->settings->refresh();

        // Trigger-Logik für reservation_enabled/reservation_disabled
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

        return response()->json(['message' => 'Settings updated.', 'settings' => $this->settings->all()]);
    }

    // Gibt den Wert einer einzelnen Einstellung zurück
    public function show($key): JsonResponse
    {
        $setting = Setting::query()->where('name', $key)->first();
        if (!$setting) {
            return response()->json(['error' => 'Not found'], 404);
        }
        return response()->json(['value' => $setting->value]);
    }
}
