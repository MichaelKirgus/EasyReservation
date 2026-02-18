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

        $data = $this->translationService->getTranslations($lang);

        return response()->json($data);
    }
}
