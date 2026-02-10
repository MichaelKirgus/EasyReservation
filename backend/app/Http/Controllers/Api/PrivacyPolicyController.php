<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use App\Services\PlaceholderService;
use Illuminate\Http\JsonResponse;

class PrivacyPolicyController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly PlaceholderService $placeholders,
    ) {}

    public function show(): JsonResponse
    {
        $enabled = (int) $this->settings->get('privacy_policy_enabled', 0) === 1;
        $text = $this->settings->get('privacy_policy_text', '');
        if (!$enabled || !$text) {
            return response()->json(['message' => __('privacy_policy_not_available')], 404);
        }
        $text = $this->placeholders->replaceString($text);
        return response()->json(['text' => $text]);
    }
}
