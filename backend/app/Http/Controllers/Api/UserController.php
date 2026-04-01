<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->orderBy('id')
            ->get()
            ->map(function ($u) {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $u->role,
                    'active' => (bool) $u->active,
                    'api_token_is_hashed' => (bool) $u->api_token_is_hashed,
                    'api_token' => $u->api_token_is_hashed ? null : $u->api_token,
                    'two_factor_secret' => !is_null($u->two_factor_secret),
                    'created_at' => $u->created_at,
                ];
            });

        return response()->json($users);
    }

    public function store(UserRequest $request): JsonResponse
    {
        $data = $request->validated();

        $plainToken = $data['api_token'] ?? Str::random(40);
        $isHashed = $data['api_token_is_hashed'] ?? false;

        $user = new User();
        $user->fill($data);
        $user->password = $data['password'] ?? Str::random(16);
        $user->api_token = $isHashed ? Hash::make($plainToken) : $plainToken;
        $user->api_token_is_hashed = $isHashed;
        $user->save();

        return response()->json([
            'user' => $user->only(['id', 'name', 'email', 'role', 'active', 'api_token_is_hashed', 'created_at']),
            'api_token' => $isHashed ? $plainToken : $user->api_token,
        ], 201);
    }

    public function update(UserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        $newRole = $data['role'] ?? $user->role;
        $newActive = array_key_exists('active', $data) ? (bool) $data['active'] : (bool) $user->active;

        if ($user->role === 'admin' && $user->active) {
            if (! ($newRole === 'admin' && $newActive)) {
                $this->assertAnotherAdminExists($user);
            }
        }

        if (isset($data['password'])) {
            $user->password = $data['password'];
        }

        if (array_key_exists('api_token', $data)) {
            $isHashed = $data['api_token_is_hashed'] ?? false;
            $user->api_token = $isHashed ? Hash::make($data['api_token']) : $data['api_token'];
            $user->api_token_is_hashed = $isHashed;
        }

        $user->fill(collect($data)->except(['password', 'api_token', 'api_token_is_hashed'])->toArray());
        $user->save();

        return response()->json($user->only(['id', 'name', 'email', 'role', 'active', 'api_token_is_hashed', 'created_at']));
    }

    public function destroy(User $user): JsonResponse
    {
        if ($user->role === 'admin' && $user->active) {
            $this->assertAnotherAdminExists($user);
        }

        $user->delete();

        return response()->json(['message' => __('user_deleted_successfully')]);
    }

    public function rotateToken(Request $request, User $user): JsonResponse
    {
        $newToken = Str::random(40);
        $isHashed = (bool) ($request->boolean('hash_token') ?? false);

        $user->api_token = $isHashed ? Hash::make($newToken) : $newToken;
        $user->api_token_is_hashed = $isHashed;
        $user->save();

        return response()->json([
            'user' => $user->only(['id', 'name', 'email', 'role', 'active', 'api_token_is_hashed', 'created_at']),
            'api_token' => $isHashed ? $newToken : $user->api_token,
        ]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        // Only admins and superadmins may reset another user's password.
        if (! in_array($request->user()?->role, ['admin', 'superadmin'], true)) {
            return response()->json(['message' => __('forbidden_access')], 403);
        }
        $request->validate([
            'password' => ['required', 'string', 'min:6'],
        ]);
        $user->password = $request->input('password');
        $user->save();
        return response()->json(['message' => __('admin_setting_change_success')]);
    }

    protected function assertAnotherAdminExists(User $exclude): void
    {
        $hasOtherAdmin = User::query()
            ->where('role', 'admin')
            ->where('active', true)
            ->where('id', '!=', $exclude->id)
            ->exists();

        if (! $hasOtherAdmin) {
            abort(response()->json(['message' => __('admin_operation_requires_at_least_one_admin')], 422));
        }
    }
}
