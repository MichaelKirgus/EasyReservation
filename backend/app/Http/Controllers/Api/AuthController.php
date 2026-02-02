<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:255'], // name oder email
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->where('active', true)
            ->whereIn('role', ['admin', 'moderator', 'user', 'guest', 'superadmin'])
            ->where(function ($q) use ($data) {
                $q->where('email', $data['identifier'])->orWhere('name', $data['identifier']);
            })
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => __('auth_invalid_credentials')], 403);
        }

        $plainToken = Str::random(40);
        if ($user->api_token_is_hashed) {
            $user->api_token = Hash::make($plainToken);
        } else {
            $user->api_token = $plainToken;
        }
        $user->save();

        return response()->json([
            'api_token' => $plainToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }
}
