<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomPlaceholder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;

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
            'type' => 'required|string|in:generic,secret',
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
            'type' => 'required|string|in:generic,secret',
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
