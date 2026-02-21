<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use Illuminate\Http\JsonResponse;

class LocationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Location::orderBy('name')->get());
    }

    public function store(\Illuminate\Http\Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'url', 'max:1024'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'active' => ['required', 'boolean'],
            'public_transport' => ['nullable', 'string', 'max:1024'],
            'notes' => ['nullable', 'string'],
            'capacity_override' => ['nullable', 'integer', 'min:0'],
        ]);

        $location = Location::create($validated);
        return response()->json($location, 201);
    }

    public function update(\Illuminate\Http\Request $request, Location $location): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'url', 'max:1024'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'active' => ['required', 'boolean'],
            'public_transport' => ['nullable', 'string', 'max:1024'],
            'notes' => ['nullable', 'string'],
            'capacity_override' => ['nullable', 'integer', 'min:0'],
        ]);

        $location->update($validated);
        return response()->json($location);
    }

    public function destroy(Location $location): JsonResponse
    {
        $location->delete();
        return response()->json(['message' => __('location_deleted')]);
    }
}
