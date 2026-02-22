<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TranslationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TranslationController extends Controller
{
    public function __construct(
        protected TranslationService $translationService,
    ) {}

    public function show(string $lang): JsonResponse
    {
        if (! $this->translationService->localeExists($lang)) {
            throw new NotFoundHttpException('Translations not found');
        }

        // Determine whitelist based on user authentication (similar to settings whitelist)
        $whitelistFile = null;
        $user = auth()->user();
        
        if (!$user) {
            $whitelistFile = resource_path('translations-whitelists/public.json');
        } elseif (in_array($user->role, ['user', 'moderator'])) {
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
