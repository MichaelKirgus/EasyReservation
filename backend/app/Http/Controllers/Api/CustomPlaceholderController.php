<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomPlaceholder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class CustomPlaceholderController extends Controller
{
    public function index()
    {
        return CustomPlaceholder::all();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'key' => 'required|string|unique:custom_placeholders,key',
            'value' => 'required|string',
            'description' => 'nullable|string',
        ]);
        $data['created_by'] = Auth::id();
        $placeholder = CustomPlaceholder::create($data);
        return response()->json($placeholder, 201);
    }

    public function update(Request $request, CustomPlaceholder $customPlaceholder)
    {
        $data = $request->validate([
            'key' => 'required|string|unique:custom_placeholders,key,' . $customPlaceholder->id,
            'value' => 'required|string',
            'description' => 'nullable|string',
        ]);
        $customPlaceholder->update($data);
        return response()->json($customPlaceholder);
    }

    public function destroy(CustomPlaceholder $customPlaceholder)
    {
        $customPlaceholder->delete();
        return response()->noContent();
    }
}
