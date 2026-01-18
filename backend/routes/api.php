<?php

use App\Http\Controllers\Api\AdminReservationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\DiagnosticsController;
use App\Http\Controllers\Api\EmailBroadcastController;
use App\Http\Controllers\Api\EmailValidationAdminController;
use App\Http\Controllers\Api\EmailValidationController;
use App\Http\Controllers\Api\EmailTemplateController;
use App\Http\Controllers\Api\FaqController;
use App\Http\Controllers\Api\FormFieldController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\TranslationController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WaitlistController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\PlaceholderController;
use App\Http\Controllers\Api\PrivacyPolicyController;
use Illuminate\Support\Facades\Route;

Route::options('/{any}', fn () => response()->noContent())->where('any', '.*');

Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/translations/{lang}', [TranslationController::class, 'show']);
Route::get('/email-validations/{token}', [EmailValidationController::class, 'verify']);
Route::get('/reservations/undo-token/{token}', [ReservationController::class, 'undoByToken']);
Route::get('/events/upcoming', [EventController::class, 'upcoming']);
Route::get('/waitlist/undo-token/{token}', [WaitlistController::class, 'undoByToken']);

Route::middleware(['site-token'])->group(function () {
    Route::get('/public/config', [ConfigController::class, 'show']);
    Route::get('/faqs', [FaqController::class, 'publicIndex']);
    Route::post('/reservations', [ReservationController::class, 'store']);
    Route::post('/reservations/undo', [ReservationController::class, 'undo']);
});

Route::middleware(['role:admin'])->group(function () {
    Route::get('/admin/reservations', [AdminReservationController::class, 'index']);
    Route::post('/admin/reservations', [AdminReservationController::class, 'store']);
    Route::patch('/admin/reservations/{reservation}', [AdminReservationController::class, 'update']);
    Route::delete('/admin/reservations/{reservation}', [AdminReservationController::class, 'destroy']);
    Route::get('/admin/export', [AdminReservationController::class, 'export']);
    Route::get('/admin/notification-defaults', [AdminReservationController::class, 'notificationDefaults']);

    Route::get('/admin/settings', [SettingsController::class, 'index']);
    Route::post('/admin/settings', [SettingsController::class, 'update']);
    Route::get('/admin/media/images', [MediaController::class, 'images']);

    Route::apiResource('/admin/form-fields', FormFieldController::class)->except(['create', 'edit', 'show']);
    Route::apiResource('/admin/faqs', FaqController::class)->except(['create', 'edit', 'show']);

    Route::get('/admin/users', [UserController::class, 'index']);
    Route::post('/admin/users', [UserController::class, 'store']);
    Route::patch('/admin/users/{user}', [UserController::class, 'update']);
    Route::delete('/admin/users/{user}', [UserController::class, 'destroy']);
    Route::post('/admin/users/{user}/rotate-token', [UserController::class, 'rotateToken']);

    Route::get('/admin/waitlist', [WaitlistController::class, 'index']);
    Route::post('/admin/waitlist', [WaitlistController::class, 'store']);
    Route::post('/admin/waitlist/{entry}/promote', [WaitlistController::class, 'promote']);
    Route::patch('/admin/waitlist/{entry}', [WaitlistController::class, 'update']);
    Route::get('/admin/waitlist/export', [WaitlistController::class, 'export']);
    Route::delete('/admin/waitlist/{entry}', [WaitlistController::class, 'destroy']);

    Route::get('/admin/email-validations', [EmailValidationAdminController::class, 'index']);
    Route::post('/admin/email-validations/{validation}/approve', [EmailValidationAdminController::class, 'approve']);
    Route::post('/admin/email-validations/{validation}/resend', [EmailValidationAdminController::class, 'resend']);
    Route::delete('/admin/email-validations/{validation}', [EmailValidationAdminController::class, 'destroy']);

    Route::get('/admin/email-templates', [EmailTemplateController::class, 'index']);
    Route::post('/admin/email-templates', [EmailTemplateController::class, 'store']);
    Route::patch('/admin/email-templates/{emailTemplate}', [EmailTemplateController::class, 'update']);
    Route::delete('/admin/email-templates/{emailTemplate}', [EmailTemplateController::class, 'destroy']);
    Route::post('/admin/email-broadcast', [EmailBroadcastController::class, 'send']);
    Route::get('/admin/placeholders', [PlaceholderController::class, 'index']);

    Route::apiResource('/admin/events', EventController::class)->except(['create', 'edit', 'show']);
    Route::get('/admin/diagnostics', [DiagnosticsController::class, 'show']);

    Route::get('/admin/email-validation-rate-limits', [\App\Http\Controllers\Api\EmailValidationRateLimitController::class, 'index']);
    Route::delete('/admin/email-validation-rate-limits', [\App\Http\Controllers\Api\EmailValidationRateLimitController::class, 'destroyAll']);
    Route::delete('/admin/email-validation-rate-limits/{ip}', [\App\Http\Controllers\Api\EmailValidationRateLimitController::class, 'destroy']);
});

Route::middleware(['role:admin,moderator'])->group(function () {
    Route::get('/moderator/reservations', [AdminReservationController::class, 'index']);
    Route::post('/moderator/reservations', [AdminReservationController::class, 'store']);
    Route::patch('/moderator/reservations/{reservation}', [AdminReservationController::class, 'update']);
    Route::delete('/moderator/reservations/{reservation}', [AdminReservationController::class, 'destroy']);
    Route::get('/moderator/export', [AdminReservationController::class, 'export']);
    Route::get('/moderator/notification-defaults', [AdminReservationController::class, 'notificationDefaults']);

    Route::get('/moderator/waitlist', [WaitlistController::class, 'index']);
    Route::post('/moderator/waitlist', [WaitlistController::class, 'store']);
    Route::post('/moderator/waitlist/{entry}/promote', [WaitlistController::class, 'promote']);
    Route::patch('/moderator/waitlist/{entry}', [WaitlistController::class, 'update']);
    Route::get('/moderator/waitlist/export', [WaitlistController::class, 'export']);
    Route::delete('/moderator/waitlist/{entry}', [WaitlistController::class, 'destroy']);

    Route::get('/moderator/email-validations', [EmailValidationAdminController::class, 'index']);

    Route::get('/moderator/email-validation-rate-limits', [\App\Http\Controllers\Api\EmailValidationRateLimitController::class, 'index']);

    Route::get('/moderator/email-templates', [EmailTemplateController::class, 'index']);
    Route::post('/moderator/email-broadcast', [EmailBroadcastController::class, 'send']);
    Route::get('/moderator/placeholders', [PlaceholderController::class, 'index']);
    Route::apiResource('/moderator/faqs', FaqController::class)->except(['create', 'edit', 'show']);
    Route::apiResource('/moderator/events', EventController::class)->except(['create', 'edit', 'show']);
});

Route::get('/privacy-policy', [PrivacyPolicyController::class, 'show']);
