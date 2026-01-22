<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateRecoveryCodes;
use Laravel\Fortify\Actions\GetRecoveryCodes;
use Laravel\Fortify\Actions\GetTwoFactorQrCodeSvg;
use Illuminate\Http\JsonResponse;

class TwoFactorApiController extends Controller
{
    public function enable(Request $request)
    {
        $user = Auth::user();
        app(EnableTwoFactorAuthentication::class)($user);
        return response()->json(['enabled' => true]);
    }

    public function disable(Request $request)
    {
        $user = Auth::user();
        app(DisableTwoFactorAuthentication::class)($user);
        return response()->json(['enabled' => false]);
    }

    public function qr(Request $request)
    {
        $user = Auth::user();
        $svg = $user->twoFactorQrCodeSvg();
        $secret = $user->two_factor_secret ? decrypt($user->two_factor_secret) : null;
        return response()->json(['svg' => $svg, 'secret' => $secret]);
    }

    public function recovery(Request $request)
    {
        $user = Auth::user();
        $codes = app(GetRecoveryCodes::class)($user);
        return response()->json($codes);
    }

    public function confirm(Request $request)
    {
        $user = Auth::user();
        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string'],
        ]);
        $validator->validate();
        $result = app(ConfirmTwoFactorAuthentication::class)($user, $request->input('code'));
        if ($result) {
            return response()->json(['confirmed' => true]);
        }
        return response()->json(['confirmed' => false], 422);
    }
}
