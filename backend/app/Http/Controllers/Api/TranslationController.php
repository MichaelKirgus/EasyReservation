<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TranslationController extends Controller
{
    public function __construct(
        protected TranslationService $translationService,
    ) {}

    public function show(Request $request, string $lang): JsonResponse
    {
        if (! $this->translationService->localeExists($lang)) {
            throw new NotFoundHttpException('Translations not found');
        }

        // Determine whitelist based on user authentication
        // Check for authenticated user via api_session cookie (similar to EnsureSiteToken middleware)
        $whitelistFile = null;
        
        // Get user from api_session cookie
        $user = null;
        $apiKey = $request->cookie('api_session');
        if ($apiKey) {
            // Try to find user by matching the token
            $user = User::query()->where('active', true)
                ->whereNotNull('api_token')
                ->get()
                ->first(function($u) use ($apiKey) {
                    if (empty($u->api_token)) return false;
                    if ($u->api_token_is_hashed) {
                        return Hash::check($apiKey, $u->api_token);
                    }
                    return hash_equals((string)$u->api_token, (string)$apiKey);
                });
        }
        
        if (!$user) {
            // No authenticated user - use public whitelist
            $whitelistFile = resource_path('translations-whitelists/public.json');
        } elseif (in_array($user->role, ['user', 'moderator'])) {
            // User or moderator role - use user whitelist
            $whitelistFile = resource_path('translations-whitelists/user.json');
        }
        // Admin/Superadmin: No whitelist - all translations

        $whitelist = null;
        if ($whitelistFile && file_exists($whitelistFile)) {
            $whitelist = json_decode(file_get_contents($whitelistFile), true);
        }

        $data = $this->translationService->getTranslations($lang, $whitelist);

        return response()->json($data);
    }
}
