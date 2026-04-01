<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Str;

class AdminTwoFactorController extends Controller
{
    public function enable(User $user)
    {
        if (! $this->canManageUsers()) {
            return response()->json(['message' => __('not_authorized')], 403);
        }
        $user->forceFill([
            'two_factor_secret' => encrypt(app('pragmarx.google2fa')->generateSecretKey()),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => encrypt(json_encode(collect(range(1, 8))->map(fn () => Str::random(10))->all())),
        ])->save();
        return response()->json(['message' => __('two_factor_enabled_for_user')]);
    }

    public function disable(User $user)
    {
        if (! $this->canManageUsers()) {
            return response()->json(['message' => __('not_authorized')], 403);
        }
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();
        return response()->json(['message' => __('two_factor_disabled_for_user')]);
    }

    public function reset(User $user)
    {
        if (! $this->canManageUsers()) {
            return response()->json(['message' => __('not_authorized')], 403);
        }
        $user->forceFill([
            'two_factor_secret' => encrypt(app('pragmarx.google2fa')->generateSecretKey()),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => encrypt(json_encode(collect(range(1, 8))->map(fn () => Str::random(10))->all())),
        ])->save();
        return response()->json(['message' => __('two_factor_reset_for_user')]);
    }

    protected function canManageUsers(): bool
    {
        $role = request()->user()?->role;

        return in_array($role, ['admin', 'superadmin'], true);
    }
}
