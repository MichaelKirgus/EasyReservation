<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FaqRequest;
use App\Models\Faq;
use Illuminate\Http\JsonResponse;

class FaqController extends Controller
{
    public function publicIndex(): JsonResponse
    {
        $faqs = Faq::query()
            ->where('is_published', true)
            ->where(function ($q) {
                $q->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'question', 'answer', 'position', 'published_at']);

        return response()->json($faqs);
    }

    public function index(): JsonResponse
    {
        $faqs = Faq::query()
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return response()->json($faqs);
    }

    public function store(FaqRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (! array_key_exists('position', $data)) {
            $data['position'] = (Faq::max('position') ?? 0) + 1;
        }

        if (($data['is_published'] ?? false) && empty($data['published_at'])) {
            $data['published_at'] = now();
        }

        $faq = Faq::create($data);

        return response()->json($faq, 201);
    }

    public function update(FaqRequest $request, Faq $faq): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('is_published', $data)) {
            $isPublished = (bool) $data['is_published'];
            if ($isPublished && empty($data['published_at']) && $faq->published_at === null) {
                $data['published_at'] = now();
            }
            if (! $isPublished && (! array_key_exists('published_at', $data))) {
                $data['published_at'] = $faq->published_at;
            }
        }

        $faq->fill($data);
        $faq->save();

        return response()->json($faq);
    }

    public function destroy(Faq $faq): JsonResponse
    {
        $faq->delete();

        return response()->json(['message' => __('faq_deleted_successfully')]);
    }
}
