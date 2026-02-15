<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Services\SettingsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        // Configure Google2FA to allow for time window tolerance
        $this->app->singleton('pragmarx.google2fa', function () {
            $google2fa = new Google2FA();
            // Set the window to 1 (which means 1 additional time period before/after)
            // This allows codes from 30 seconds before and after to be valid
            $google2fa->setWindow(1);
            return $google2fa;
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());
            
            /** @var SettingsService $settings */
            $settings = app(SettingsService::class);
            $attempts = $settings->loginRateLimitAttempts();
            $decayMinutes = $settings->loginRateLimitDecayMinutes();

            return Limit::perMinute($attempts)->by($throttleKey)->after(function ($response) use ($attempts) {
                if ($response->status() === 429) {
                    // Add retry-after header with remaining seconds
                    $remaining = $response->headers->get('Retry-After');
                    if ($remaining) {
                        return response()->json([
                            'message' => __('auth_throttled', ['seconds' => $remaining]),
                        ], 429);
                    }
                }
            });
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }
}
