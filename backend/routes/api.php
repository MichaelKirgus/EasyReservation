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
use App\Http\Controllers\Api\ArchiveController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\SurveyController;
use App\Http\Controllers\Api\PublicSurveyController;
use App\Http\Controllers\Api\MailAccountController;
use App\Http\Controllers\Api\MailGroupController;
use App\Http\Controllers\Api\EmailBlacklistDomainController;
use App\Http\Controllers\Api\AttachmentTemplateController;
use App\Http\Controllers\Api\AttachmentUploadController;
use App\Http\Controllers\Api\IcalTemplateController;
use App\Http\Controllers\Api\ValidationRuleController;
use App\Http\Controllers\Api\ActionListController;
use App\Http\Controllers\Api\DataPortabilityController;
use App\Http\Controllers\Api\ModerationDashboardController;
use App\Http\Controllers\Api\ActionListActionController;
use Illuminate\Support\Facades\Route;

Route::options('/{any}', fn () => response()->noContent())->where('any', '.*');

// Public health check endpoint (no authentication required)
Route::get('/health', [HealthController::class, 'check']);

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
Route::get('/waitlist/undo-token/{token}', [WaitlistController::class, 'undoByToken']);

Route::middleware(['site-token'])->group(function () {
    Route::get('/public/config', [ConfigController::class, 'show']);
    Route::get('/faqs', [FaqController::class, 'publicIndex']);
    Route::get('/privacy-policy', [PrivacyPolicyController::class, 'show']);
    Route::get('/events/upcoming', [EventController::class, 'upcoming']);
    Route::get('/surveys/{survey}', [PublicSurveyController::class, 'show']);
    Route::post('/surveys/{survey}/submit', [PublicSurveyController::class, 'submit']);
    Route::get('/surveys/{survey}/check-status', [PublicSurveyController::class, 'checkStatus']);
    Route::post('/reservations', [ReservationController::class, 'store']);
    Route::post('/reservations/undo', [ReservationController::class, 'undo']);
    Route::post('/waitlist', [WaitlistController::class, 'store']);
    Route::post('/waitlist/undo', [WaitlistController::class, 'undo']);
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
    Route::get('/admin/settings/export', [SettingsController::class, 'export']);
    Route::post('/admin/settings/import', [SettingsController::class, 'import']);
    Route::get('/admin/settings/{key}', [SettingsController::class, 'show']);
    Route::get('/admin/media/images', [MediaController::class, 'images']);

    Route::get('/admin/data-portability/tables', [DataPortabilityController::class, 'tables']);
    Route::get('/admin/data-portability/files', [DataPortabilityController::class, 'files']);
    Route::post('/admin/data-portability/files/upload', [DataPortabilityController::class, 'uploadFile']);
    Route::get('/admin/data-portability/files/{file}/download', [DataPortabilityController::class, 'downloadFile'])->where('file', '[A-Za-z0-9._-]+');
    Route::get('/admin/data-portability/operations', [DataPortabilityController::class, 'index']);
    Route::get('/admin/data-portability/operations/{operation}', [DataPortabilityController::class, 'show']);
    Route::post('/admin/data-portability/backup', [DataPortabilityController::class, 'createBackup']);
    Route::post('/admin/data-portability/restore', [DataPortabilityController::class, 'createRestore']);
    Route::post('/admin/data-portability/transport', [DataPortabilityController::class, 'createTransport']);
    Route::post('/admin/data-portability/preflight-transport', [DataPortabilityController::class, 'preflightTransport']);
    Route::post('/admin/data-portability/receive-transport', [DataPortabilityController::class, 'receiveTransport']);
    Route::get('/admin/data-portability/transport-profiles', [DataPortabilityController::class, 'listTransportProfiles']);
    Route::post('/admin/data-portability/transport-profiles', [DataPortabilityController::class, 'createTransportProfile']);
    Route::put('/admin/data-portability/transport-profiles/{profile}', [DataPortabilityController::class, 'updateTransportProfile']);
    Route::delete('/admin/data-portability/transport-profiles/{profile}', [DataPortabilityController::class, 'deleteTransportProfile']);

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
    Route::post('/admin/email-templates/{emailTemplate}/clone', [EmailTemplateController::class, 'clone']);
    Route::get('/admin/email-templates/{emailTemplate}/preview', [EmailTemplateController::class, 'preview']);
    Route::post('/admin/email-broadcast', [EmailBroadcastController::class, 'send']);
    Route::get('/admin/placeholders', [PlaceholderController::class, 'index']);
    Route::get('/admin/placeholders/values', [PlaceholderController::class, 'values']);

    Route::get('/admin/event-triggers', [\App\Http\Controllers\Api\EventTriggerController::class, 'index']);
    Route::post('/admin/event-triggers', [\App\Http\Controllers\Api\EventTriggerController::class, 'store']);
    Route::put('/admin/event-triggers/{id}', [\App\Http\Controllers\Api\EventTriggerController::class, 'update']);
    Route::delete('/admin/event-triggers/{id}', [\App\Http\Controllers\Api\EventTriggerController::class, 'destroy']);
    Route::post('/admin/event-triggers/{id}/clone', [\App\Http\Controllers\Api\EventTriggerController::class, 'clone']);
    Route::patch('/admin/event-triggers/{id}/toggle-active', [\App\Http\Controllers\Api\EventTriggerController::class, 'toggleActive']);
    Route::post('/admin/event-triggers/{id}/simulate', [\App\Http\Controllers\Api\EventTriggerController::class, 'simulate']);

    // Survey routes (must be before apiResource to avoid {survey} parameter conflict)
    Route::get('/admin/surveys/{survey}/preview', [SurveyController::class, 'preview']);
    Route::post('/admin/surveys/{survey}/send', [SurveyController::class, 'send']);
    Route::get('/admin/surveys/{survey}/responses', [SurveyController::class, 'responses']);
    Route::get('/admin/surveys/{survey}/questions', [SurveyController::class, 'getQuestions']);
    Route::get('/admin/surveys/{survey}/results', [SurveyController::class, 'results']);
    Route::get('/admin/surveys/results/export', [SurveyController::class, 'export']);

    Route::apiResource('/admin/surveys', SurveyController::class)->except(['create', 'edit', 'show']);
    Route::post('/admin/surveys/{survey}/questions', [SurveyController::class, 'addQuestion']);
    Route::put('/admin/surveys/{survey}/questions/{question}', [SurveyController::class, 'updateQuestion']);
    Route::delete('/admin/surveys/{survey}/questions/{question}', [SurveyController::class, 'deleteQuestion']);

    // Global questions (shared across all surveys)
    Route::get('/admin/global-questions', [SurveyController::class, 'getGlobalQuestions']);
    Route::post('/admin/global-questions', [SurveyController::class, 'createGlobalQuestion']);
    Route::put('/admin/global-questions/{question}', [SurveyController::class, 'updateGlobalQuestion']);
    Route::delete('/admin/global-questions/{question}', [SurveyController::class, 'deleteGlobalQuestion']);

    Route::apiResource('/admin/events', EventController::class)->except(['create', 'edit', 'show']);
    Route::post('/admin/events/{event}/clone', [EventController::class, 'clone']);
    Route::apiResource('/admin/locations', LocationController::class);
    Route::post('/admin/locations/{location}/clone', [LocationController::class, 'clone']);
    Route::apiResource('/admin/scheduled-tasks', \App\Http\Controllers\Api\ScheduledTaskController::class)->except(['create', 'edit', 'show']);
    Route::post('/admin/scheduled-tasks/{id}/clone', [\App\Http\Controllers\Api\ScheduledTaskController::class, 'clone']);
    Route::patch('/admin/scheduled-tasks/{id}/activate', [\App\Http\Controllers\Api\ScheduledTaskController::class, 'activate']);

    // Action Lists routes
    Route::apiResource('/admin/action-lists', ActionListController::class)->except(['create', 'edit', 'show']);
    Route::post('/admin/action-lists/{id}/clone', [ActionListController::class, 'clone']);
    Route::get('/admin/action-lists/{id}/actions', [ActionListActionController::class, 'index']);
    Route::post('/admin/action-lists/{id}/actions', [ActionListActionController::class, 'store']);
    Route::put('/admin/action-lists/{id}/actions/{actionId}', [ActionListActionController::class, 'update']);
    Route::delete('/admin/action-lists/{id}/actions/{actionId}', [ActionListActionController::class, 'destroy']);
    Route::post('/admin/action-lists/{id}/execute', [ActionListController::class, 'execute']);
    Route::patch('/admin/scheduled-tasks/{id}/deactivate', [\App\Http\Controllers\Api\ScheduledTaskController::class, 'deactivate']);
    Route::post('/admin/scheduled-tasks/{id}/run-now', [\App\Http\Controllers\Api\ScheduledTaskController::class, 'runNow']);
    Route::post('/admin/cron/next-run', [\App\Http\Controllers\Api\ScheduledTaskController::class, 'getNextCronRun']);
    Route::get('/admin/diagnostics', [DiagnosticsController::class, 'show']);
    
    // Moderation Dashboard routes (for moderator, admin, and superadmin roles)
    Route::middleware(['role:moderator,admin,superadmin'])->group(function () {
        Route::get('/admin/moderation-dashboard/stats', [\App\Http\Controllers\Api\ModerationDashboardController::class, 'stats']);
        Route::get('/admin/moderation-dashboard/public-url', [\App\Http\Controllers\Api\ModerationDashboardController::class, 'publicUrl']);
        Route::post('/admin/moderation-dashboard/action-list/{id}/execute', [\App\Http\Controllers\Api\ModerationDashboardController::class, 'executeActionList']);
    });

    Route::post('/admin/diagnostics/flush-state', [DiagnosticsController::class, 'flushState']);

    Route::get('/admin/diagnostics/redis-keys', [DiagnosticsController::class, 'redisKeys']);
    Route::delete('/admin/diagnostics/redis-keys', [DiagnosticsController::class, 'deleteRedisKey']);
    Route::get('/admin/rate-limit-diagnostics', [\App\Http\Controllers\Api\RateLimitDiagnosticsController::class, 'index']);

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

    Route::get('/admin/validation-rules', [ValidationRuleController::class, 'index']);
    Route::get('/admin/validation-rules/config', [ValidationRuleController::class, 'getConfig']);
    Route::patch('/admin/validation-rules/config', [ValidationRuleController::class, 'updateConfig']);
    Route::post('/admin/validation-rules/reorder', [ValidationRuleController::class, 'reorder']);
    Route::get('/admin/validation-rules/available-fields', [ValidationRuleController::class, 'getAvailableFields']);
    Route::get('/admin/validation-rules/webhook-templates', [ValidationRuleController::class, 'getWebhookTemplates']);
    Route::post('/admin/validation-rules', [ValidationRuleController::class, 'store']);
    Route::get('/admin/validation-rules/{id}', [ValidationRuleController::class, 'show'])->whereNumber('id');
    Route::put('/admin/validation-rules/{id}', [ValidationRuleController::class, 'update'])->whereNumber('id');
    Route::delete('/admin/validation-rules/{id}', [ValidationRuleController::class, 'destroy'])->whereNumber('id');

    Route::get('/admin/archives', [ArchiveController::class, 'index']);
    Route::post('/admin/archives', [ArchiveController::class, 'store']);
    Route::delete('/admin/archives/{archive}', [ArchiveController::class, 'destroy']);
    Route::get('/admin/archives/{archive}/reservations', [ArchiveController::class, 'getReservations']);
    Route::get('/admin/archives/{archive}/waitlist', [ArchiveController::class, 'getWaitlistEntries']);
    Route::post('/admin/archives/{archive}/restore-reservation/{reservation}', [ArchiveController::class, 'restoreReservation']);
    Route::post('/admin/archives/{archive}/restore-reservations', [ArchiveController::class, 'restoreReservations']);
    Route::post('/admin/archives/{archive}/restore-waitlist-entry/{entry}', [ArchiveController::class, 'restoreWaitlistEntry']);
    Route::post('/admin/archives/{archive}/restore-waitlist-entries', [ArchiveController::class, 'restoreWaitlistEntries']);
    Route::get('/admin/archives/{archive}/download-csv/{type}', [ArchiveController::class, 'downloadCsv']);
    Route::post('/admin/archives/{archive}/archive-data', [ArchiveController::class, 'archiveData']);

   Route::apiResource('/admin/mail-accounts', MailAccountController::class)->parameters(['mail-accounts' => 'account']);
   Route::post('/admin/mail-accounts/{account}/test', [MailAccountController::class, 'testConnection']);

   Route::apiResource('/admin/mail-groups', MailGroupController::class)->parameters(['mail-groups' => 'group']);
   Route::post('/admin/mail-groups/{group}/add-account', [MailGroupController::class, 'addAccount']);
   Route::put('/admin/mail-groups/{group}/update-priority/{account}', [MailGroupController::class, 'updateAccountPriority']);
   Route::delete('/admin/mail-groups/{group}/remove-account/{account}', [MailGroupController::class, 'removeAccount']);
   Route::post('/admin/mail-groups/{group}/test', [MailGroupController::class, 'testConnection']);

   Route::apiResource('/admin/email-blacklist-domains', EmailBlacklistDomainController::class);

   Route::apiResource('/admin/ical-templates', IcalTemplateController::class)->except(['create', 'edit', 'show']);
   Route::post('/admin/ical-templates/{icalTemplate}/clone', [IcalTemplateController::class, 'clone']);

   // Attachment templates (custom routes must come before apiResource to avoid conflicts)
   Route::get('/admin/attachment-templates/{attachmentTemplate}/attachments', [AttachmentTemplateController::class, 'attachments']);
   Route::post('/admin/attachment-templates/{attachmentTemplate}/upload', [AttachmentUploadController::class, 'upload']);
   Route::delete('/admin/attachment-templates/{attachmentTemplate}/attachments/{attachment}', [AttachmentUploadController::class, 'destroy']);
   Route::apiResource('/admin/attachment-templates', AttachmentTemplateController::class)->except(['create', 'edit', 'show']);
   Route::get('/admin/ical-templates/{icalTemplate}/preview', [IcalTemplateController::class, 'preview']);
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

    // Moderator archive routes (if enabled via setting)
    Route::get('/moderator/archives', [ArchiveController::class, 'index']);
    Route::post('/moderator/archives', [ArchiveController::class, 'store']);
    Route::delete('/moderator/archives/{archive}', [ArchiveController::class, 'destroy']);
    Route::get('/moderator/archives/{archive}/reservations', [ArchiveController::class, 'getReservations']);
    Route::get('/moderator/archives/{archive}/waitlist', [ArchiveController::class, 'getWaitlistEntries']);

    Route::get('/moderator/email-templates', [EmailTemplateController::class, 'index']);
    Route::get('/moderator/email-templates/{emailTemplate}/preview', [EmailTemplateController::class, 'preview']);
    Route::post('/moderator/email-broadcast', [EmailBroadcastController::class, 'send']);
    Route::get('/moderator/placeholders', [PlaceholderController::class, 'index']);
    Route::get('/moderator/placeholders/values', [PlaceholderController::class, 'values']);
    Route::apiResource('/moderator/faqs', FaqController::class)->except(['create', 'edit', 'show']);
    Route::apiResource('/moderator/events', EventController::class)->except(['create', 'edit', 'show']);
    Route::apiResource('/moderator/locations', LocationController::class);

    // Moderator endpoint for fetching transport groups with accounts (read-only)
    Route::get('/moderator/mail-transport-options', [\App\Http\Controllers\Api\MailTransportOptionsController::class, 'index']);

    // Admin endpoint for fetching transport groups with accounts (read-only)
    Route::get('/admin/mail-transport-options', [\App\Http\Controllers\Api\MailTransportOptionsController::class, 'index']);
});

Route::middleware(['role:superadmin'])->group(function () {
    Route::get('/audit-logs', [\App\Http\Controllers\Api\AuditLogController::class, 'index']);
    Route::post('/audit-logs/clear', [\App\Http\Controllers\Api\AuditLogController::class, 'clear']);
});
