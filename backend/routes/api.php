<?php

use App\Http\Controllers\Api\AdminReservationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\DiagnosticsController;
use App\Http\Controllers\Api\WorkerStatsController;
use App\Http\Controllers\Api\EmailBroadcastController;
use App\Http\Controllers\Api\EmailValidationAdminController;
use App\Http\Controllers\Api\EmailValidationController;
use App\Http\Controllers\Api\EmailTemplateController;
use App\Http\Controllers\Api\FaqController;
use App\Http\Controllers\Api\FormFieldController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\TranslationController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WaitlistController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\PlaceholderController;
use App\Http\Controllers\Api\PrivacyPolicyController;
use App\Http\Controllers\Api\TwoFactorApiController;
use App\Http\Controllers\Api\LanguagesController;
use App\Http\Controllers\Api\FlagController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\SurveyController;
use App\Http\Controllers\Api\PublicSurveyController;
use Illuminate\Support\Facades\Route;

Route::options('/{any}', fn () => response()->noContent())->where('any', '.*');

// Public health check endpoint (no authentication required)
Route::get('/health', [HealthController::class, 'check']);
Route::get('/surveys/{survey}', [PublicSurveyController::class, 'show']);
Route::post('/surveys/{survey}/submit', [PublicSurveyController::class, 'submit']);
Route::get('/surveys/{survey}/check-status', [PublicSurveyController::class, 'checkStatus']);

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::middleware(['role:superadmin,admin,moderator,user'])->group(function () {
    Route::post('/self-2fa/enable', [TwoFactorApiController::class, 'enable']);
    Route::delete('/self-2fa/disable', [TwoFactorApiController::class, 'disable']);
    Route::get('/self-2fa/qr', [TwoFactorApiController::class, 'qr']);
    Route::get('/self-2fa/recovery', [TwoFactorApiController::class, 'recovery']);
    Route::post('/self-2fa/confirm', [TwoFactorApiController::class, 'confirm']);
    Route::get('/self-2fa/status', [TwoFactorApiController::class, 'status']);
});

Route::get('/translations/{lang}', [TranslationController::class, 'show']);
Route::get('/flags/{lang}.svg', [FlagController::class, 'show'])->where('lang', '[a-z]{2}');
Route::get('/languages', [LanguagesController::class, 'index']);
Route::get('/language-names', [LanguagesController::class, 'names']);
Route::get('/email-validations/{token}', [EmailValidationController::class, 'verify']);
Route::get('/reservations/undo-token/{token}', [ReservationController::class, 'undoByToken']);
Route::get('/events/upcoming', [EventController::class, 'upcoming']);
Route::get('/waitlist/undo-token/{token}', [WaitlistController::class, 'undoByToken']);

Route::middleware(['site-token'])->group(function () {
    Route::get('/public/config', [ConfigController::class, 'show']);
    Route::get('/faqs', [FaqController::class, 'publicIndex']);
    Route::get('/privacy-policy', [PrivacyPolicyController::class, 'show']);
    Route::post('/reservations', [ReservationController::class, 'store']);
    Route::post('/reservations/undo', [ReservationController::class, 'undo']);
});

Route::middleware(['role:admin,superadmin'])->group(function () {
    Route::get('/diagnostics/workers', [DiagnosticsController::class, 'workers']);
    Route::get('/admin/worker-stats', [WorkerStatsController::class, 'index']);
    Route::delete('/admin/worker-stats/cleanup', [WorkerStatsController::class, 'cleanup']);
    Route::get('/admin/audit-log/count', [AuditLogController::class, 'count']);
    Route::get('/admin/reservations', [AdminReservationController::class, 'index']);
    Route::post('/admin/reservations', [AdminReservationController::class, 'store']);
    Route::patch('/admin/reservations/{reservation}', [AdminReservationController::class, 'update']);
    Route::delete('/admin/reservations/{reservation}', [AdminReservationController::class, 'destroy']);
    Route::get('/admin/export', [AdminReservationController::class, 'export']);
    Route::get('/admin/notification-defaults', [AdminReservationController::class, 'notificationDefaults']);

    Route::post('/admin/users/{user}/2fa/enable', [\App\Http\Controllers\Api\AdminTwoFactorController::class, 'enable']);
    Route::post('/admin/users/{user}/2fa/disable', [\App\Http\Controllers\Api\AdminTwoFactorController::class, 'disable']);
    Route::post('/admin/users/{user}/2fa/reset', [\App\Http\Controllers\Api\AdminTwoFactorController::class, 'reset']);

    Route::apiResource('/admin/custom-placeholders', \App\Http\Controllers\Api\CustomPlaceholderController::class)->except(['create', 'edit', 'show']);
    Route::get('/admin/settings', [SettingsController::class, 'index']);
    Route::post('/admin/settings', [SettingsController::class, 'update']);
    Route::get('/admin/settings-keys', [SettingsController::class, 'keys']);
    Route::get('/admin/settings/{key}', [SettingsController::class, 'show']);
    Route::get('/admin/settings/export', [SettingsController::class, 'export']);
    Route::post('/admin/settings/import', [SettingsController::class, 'import']);
    Route::get('/admin/media/images', [MediaController::class, 'images']);

    Route::apiResource('/admin/form-fields', FormFieldController::class)->except(['create', 'edit', 'show']);
    Route::apiResource('/admin/faqs', FaqController::class)->except(['create', 'edit', 'show']);

    Route::get('/admin/users', [UserController::class, 'index']);
    Route::post('/admin/users', [UserController::class, 'store']);
    Route::patch('/admin/users/{user}', [UserController::class, 'update']);
    Route::delete('/admin/users/{user}', [UserController::class, 'destroy']);
    Route::post('/admin/users/{user}/rotate-token', [UserController::class, 'rotateToken']);
    Route::post('/admin/users/{user}/reset-password', [UserController::class, 'resetPassword']);

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
    Route::get('/admin/email-templates/{emailTemplate}/preview', [EmailTemplateController::class, 'preview']);
    Route::post('/admin/email-broadcast', [EmailBroadcastController::class, 'send']);
    Route::get('/admin/placeholders', [PlaceholderController::class, 'index']);
    Route::get('/admin/placeholders/values', [PlaceholderController::class, 'values']);

    Route::get('/admin/event-triggers', [\App\Http\Controllers\Api\EventTriggerController::class, 'index']);
    Route::post('/admin/event-triggers', [\App\Http\Controllers\Api\EventTriggerController::class, 'store']);
    Route::put('/admin/event-triggers/{id}', [\App\Http\Controllers\Api\EventTriggerController::class, 'update']);
    Route::delete('/admin/event-triggers/{id}', [\App\Http\Controllers\Api\EventTriggerController::class, 'destroy']);
    Route::post('/admin/event-triggers/{id}/simulate', [\App\Http\Controllers\Api\EventTriggerController::class, 'simulate']);

    Route::apiResource('/admin/surveys', SurveyController::class)->except(['create', 'edit', 'show']);
    Route::post('/admin/surveys/{survey}/send', [SurveyController::class, 'send']);
    Route::get('/admin/surveys/{survey}/responses', [SurveyController::class, 'responses']);
    Route::get('/admin/surveys/{survey}/questions', [SurveyController::class, 'getQuestions']);
    Route::post('/admin/surveys/{survey}/questions', [SurveyController::class, 'addQuestion']);
    Route::put('/admin/surveys/{survey}/questions/{question}', [SurveyController::class, 'updateQuestion']);
    Route::delete('/admin/surveys/{survey}/questions/{question}', [SurveyController::class, 'deleteQuestion']);

    // Global questions (shared across all surveys)
    Route::get('/admin/global-questions', [SurveyController::class, 'getGlobalQuestions']);
    Route::post('/admin/global-questions', [SurveyController::class, 'createGlobalQuestion']);
    Route::put('/admin/global-questions/{question}', [SurveyController::class, 'updateGlobalQuestion']);
    Route::delete('/admin/global-questions/{question}', [SurveyController::class, 'deleteGlobalQuestion']);

    Route::apiResource('/admin/events', EventController::class)->except(['create', 'edit', 'show']);
    Route::apiResource('/admin/locations', LocationController::class);
    Route::apiResource('/admin/scheduled-tasks', \App\Http\Controllers\Api\ScheduledTaskController::class)->except(['create', 'edit', 'show']);
    Route::patch('/admin/scheduled-tasks/{id}/activate', [\App\Http\Controllers\Api\ScheduledTaskController::class, 'activate']);
    Route::patch('/admin/scheduled-tasks/{id}/deactivate', [\App\Http\Controllers\Api\ScheduledTaskController::class, 'deactivate']);
    Route::post('/admin/scheduled-tasks/{id}/run-now', [\App\Http\Controllers\Api\ScheduledTaskController::class, 'runNow']);
    Route::post('/admin/cron/next-run', [\App\Http\Controllers\Api\ScheduledTaskController::class, 'getNextCronRun']);
    Route::get('/admin/diagnostics', [DiagnosticsController::class, 'show']);
    Route::get('/admin/diagnostics/redis-keys', [DiagnosticsController::class, 'redisKeys']);
    Route::delete('/admin/diagnostics/redis-keys', [DiagnosticsController::class, 'deleteRedisKey']);

    Route::get('/admin/email-validation-rate-limits', [\App\Http\Controllers\Api\EmailValidationRateLimitController::class, 'index']);
    Route::delete('/admin/email-validation-rate-limits', [\App\Http\Controllers\Api\EmailValidationRateLimitController::class, 'destroyAll']);
    Route::delete('/admin/email-validation-rate-limits/{ip}', [\App\Http\Controllers\Api\EmailValidationRateLimitController::class, 'destroy']);

    Route::get('/admin/email-validation-admin-rate-limits', [\App\Http\Controllers\Api\EmailValidationRateLimitController::class, 'adminApprovalIndex']);
    Route::delete('/admin/email-validation-admin-rate-limits', [\App\Http\Controllers\Api\EmailValidationRateLimitController::class, 'destroyAllAdminApproval']);

    Route::post('/admin/purge-all', [\App\Http\Controllers\Api\PurgeController::class, 'purgeAll']);

    Route::get('/admin/webhook-templates', [\App\Http\Controllers\Api\WebhookTemplateController::class, 'index']);
    Route::get('/admin/webhook-templates/{id}', [\App\Http\Controllers\Api\WebhookTemplateController::class, 'show']);
    Route::post('/admin/webhook-templates', [\App\Http\Controllers\Api\WebhookTemplateController::class, 'store']);
    Route::put('/admin/webhook-templates/{id}', [\App\Http\Controllers\Api\WebhookTemplateController::class, 'update']);
    Route::delete('/admin/webhook-templates/{id}', [\App\Http\Controllers\Api\WebhookTemplateController::class, 'destroy']);
    Route::post('/admin/webhook-templates/{id}/clone', [\App\Http\Controllers\Api\WebhookTemplateController::class, 'clone']);
    Route::post('/admin/webhook-templates/{id}/test', [\App\Http\Controllers\Api\WebhookTemplateController::class, 'test']);
});

Route::middleware(['role:superadmin,admin,moderator'])->group(function () {
    Route::get('/moderator/reservations', [AdminReservationController::class, 'index']);
    Route::post('/moderator/reservations', [AdminReservationController::class, 'store']);
    Route::patch('/moderator/resersvations/{reservation}', [AdminReservationController::class, 'update']);
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
    Route::delete('/moderator/email-validations/{validation}', [EmailValidationAdminController::class, 'destroy']);

    Route::get('/moderator/email-validation-rate-limits', [\App\Http\Controllers\Api\EmailValidationRateLimitController::class, 'index']);
    Route::delete('/moderator/email-validation-rate-limits', [\App\Http\Controllers\Api\EmailValidationRateLimitController::class, 'destroyAll']);
    Route::delete('/moderator/email-validation-rate-limits/{ip}', [\App\Http\Controllers\Api\EmailValidationRateLimitController::class, 'destroy']);

    Route::get('/moderator/email-validation-admin-rate-limits', [\App\Http\Controllers\Api\EmailValidationRateLimitController::class, 'adminApprovalIndex']);
    Route::delete('/moderator/email-validation-admin-rate-limits', [\App\Http\Controllers\Api\EmailValidationRateLimitController::class, 'destroyAllAdminApproval']);

    Route::get('/moderator/email-templates', [EmailTemplateController::class, 'index']);
    Route::get('/moderator/email-templates/{emailTemplate}/preview', [EmailTemplateController::class, 'preview']);
    Route::post('/moderator/email-broadcast', [EmailBroadcastController::class, 'send']);
    Route::get('/moderator/placeholders', [PlaceholderController::class, 'index']);
    Route::get('/moderator/placeholders/values', [PlaceholderController::class, 'values']);
    Route::apiResource('/moderator/faqs', FaqController::class)->except(['create', 'edit', 'show']);
    Route::apiResource('/moderator/events', EventController::class)->except(['create', 'edit', 'show']);
    Route::apiResource('/moderator/locations', LocationController::class);
});

Route::middleware(['role:superadmin'])->group(function () {
    Route::get('/audit-logs', [\App\Http\Controllers\Api\AuditLogController::class, 'index']);
    Route::post('/audit-logs/clear', [\App\Http\Controllers\Api\AuditLogController::class, 'clear']);
});
