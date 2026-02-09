<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PlaceholderService;
use Illuminate\Http\JsonResponse;

class PlaceholderController extends Controller
{
    public function __construct(private readonly PlaceholderService $placeholders)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->placeholders->tokens());
    }

    public function values(): JsonResponse
    {
        return response()->json($this->placeholders->replacements());
    }
}
