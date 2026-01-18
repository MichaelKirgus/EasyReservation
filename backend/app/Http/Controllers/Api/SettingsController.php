<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SettingsUpdateRequest;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;

class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly MediaService $media,
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
            Setting::query()->updateOrCreate(
                ['name' => $name],
                ['value' => $value]
            );
        }

        $this->settings->refresh();

        return response()->json(['message' => 'Settings updated.', 'settings' => $this->settings->all()]);
    }
}
