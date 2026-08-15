<?php

namespace App\Services;

use App\Models\ActionList;
use App\Models\ActionListAction;
use App\Models\DataPortabilityOperation;
use App\Models\DataPortabilityTransportProfile;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Setting;
use App\Models\WaitlistEntry;
use App\Models\WebhookTemplate;
use App\Models\Survey;
use App\Jobs\RunDatabaseBackupJob;
use App\Jobs\RunDatabaseTransportJob;

use App\Services\WebhookService;
use App\Services\EmailBroadcastService;
use App\Services\PlaceholderService;
use App\Services\ArchiveService;
use App\Services\DataPortabilityTableService;
use Illuminate\Support\Facades\Log;

class ActionListService
{
    public function __construct(
        private readonly WebhookService $webhookService,
        private readonly EmailBroadcastService $emailBroadcastService,
        private readonly PlaceholderService $placeholderService,
        private readonly ArchiveService $archiveService,
        private readonly DataPortabilityTableService $dataPortabilityTableService,
    ) {}

    /**
     * Execute an action list with the given context.
     */
    public function executeActionList(int $actionListId, array $context = [], ?array $actionIds = null): void
    {
        \Log::info('ActionListService: Starting execution for action list ID ' . $actionListId);

        $actionList = ActionList::find($actionListId);
        
        if (!$actionList) {
            \Log::error('ActionListService: Action list with ID ' . $actionListId . ' not found');
            throw new \InvalidArgumentException('Action list with ID ' . $actionListId . ' not found');
        }

        if (!$actionList->active) {
            \Log::warning('ActionListService: Action list is inactive: ' . $actionListId);
            return;
        }

        // Apply context placeholders
        $this->applyContextPlaceholders($context);

        $actions = $actionList->actions;

        if (is_array($actionIds) && count($actionIds) > 0) {
            $selectedActionIds = array_map('intval', $actionIds);
            $actions = $actions
                ->whereIn('id', $selectedActionIds)
                ->sortBy('sort_order')
                ->values();

            \Log::info('ActionListService: Executing selected actions only', [
                'action_list_id' => $actionListId,
                'action_ids' => $selectedActionIds,
                'selected_count' => $actions->count(),
            ]);
        }

        $actions = $actions
            ->filter(fn (ActionListAction $action) => (bool) $action->enabled)
            ->values();

        if ($actions->isEmpty()) {
            \Log::warning('ActionListService: No actions available for execution', [
                'action_list_id' => $actionListId,
                'requested_action_ids' => $actionIds,
            ]);
            return;
        }

        foreach ($actions as $action) {
            try {
                \Log::info('ActionListService: Executing action ' . $action->type . ' (ID: ' . $action->id . ')');
                $this->executeAction($action, $context);
                \Log::info('ActionListService: Action executed successfully: ' . $action->type . ' (ID: ' . $action->id . ')');
            } catch (\Throwable $e) {
                \Log::error('ActionListService: Action execution failed: ' . $action->type . ' (ID: ' . $action->id . '): ' . $e->getMessage());
                \Log::error('Stack trace:', ['trace' => $e->getTraceAsString()]);
                // Continue with next action even if one fails
            }
        }

        \Log::info('ActionListService: Action list execution completed: ' . $actionListId);
    }

    /**
     * Apply context placeholders to the placeholder service.
     */
    private function applyContextPlaceholders(array $context): void
    {
        if (isset($context['event']) && $context['event'] instanceof \App\Models\Event) {
            $this->placeholderService->setEvent($context['event']);
        }
        
        if (isset($context['reservation']) && $context['reservation'] instanceof Reservation) {
            $this->placeholderService->setReservation($context['reservation']);
        }
        
        if (isset($context['user']) && $context['user'] instanceof User) {
            $this->placeholderService->setUser($context['user']);
        }
    }

    /**
     * Execute a single action based on its type.
     */
    private function executeAction(ActionListAction $action, array $context): void
    {
        match ($action->type) {
            'email' => $this->executeEmailAction($action, $context),
            'webhook' => $this->executeWebhookAction($action, $context),
            'change_setting' => $this->executeChangeSettingAction($action, $context),
            'remove_attendees_from_reservation_list' => $this->executeRemoveAttendeesFromReservationListAction($action, $context),
            'remove_attendees_from_waitlist' => $this->executeRemoveAttendeesFromWaitlistAction($action, $context),
            'remove_mail_validation_ip_rate_limits' => $this->executeRemoveMailValidationIpRateLimitsAction($action, $context),
            'archive_reservation_and_waiting_list' => $this->executeArchiveReservationAndWaitingListAction($action, $context),
            'wait_n_seconds' => $this->executeWaitNSecondsAction($action, $context),
            'change_default_guest_token' => $this->executeChangeDefaultGuestTokenAction($action, $context),
            'data_portability_backup' => $this->executeDataPortabilityBackupAction($action, $context),
            'data_portability_transport' => $this->executeDataPortabilityTransportAction($action, $context),
            default => throw new \InvalidArgumentException('Unknown action type: ' . $action->type)
        };
    }

    private function executeDataPortabilityBackupAction(ActionListAction $action, array $context): void
    {
        $config = is_array($action->config) ? $action->config : [];
        $tables = $this->resolveDataPortabilityTables($config['selected_tables'] ?? null);
        $filename = trim((string) ($config['filename'] ?? ''));

        $options = [];
        if ($filename !== '') {
            $options['custom_filename'] = $filename;
        }

        $operation = DataPortabilityOperation::create([
            'type' => 'backup',
            'status' => 'queued',
            'requested_by_user_id' => $this->resolveRequestedByUserId($context),
            'selected_tables' => $tables,
            'options' => $options,
        ]);

        RunDatabaseBackupJob::dispatch($operation->id)
            ->onQueue(config('data-portability.queue'));

        Log::info('Data portability backup action queued', [
            'action_id' => $action->id,
            'operation_id' => $operation->id,
            'selected_tables_count' => count($tables),
            'custom_filename' => $filename !== '' ? $filename : null,
        ]);
    }

    private function executeDataPortabilityTransportAction(ActionListAction $action, array $context): void
    {
        $config = is_array($action->config) ? $action->config : [];
        $transportProfileId = isset($config['transport_profile_id']) ? (int) $config['transport_profile_id'] : 0;
        $restoreMode = (string) ($config['restore_mode'] ?? config('data-portability.restore.default_mode', 'truncate_insert'));

        if (! in_array($restoreMode, ['truncate_insert', 'upsert'], true)) {
            $restoreMode = (string) config('data-portability.restore.default_mode', 'truncate_insert');
        }

        if ($transportProfileId <= 0) {
            throw new \InvalidArgumentException('Transport profile is required for data portability transport action.');
        }

        $profileExists = DataPortabilityTransportProfile::query()
            ->where('id', $transportProfileId)
            ->where('is_active', true)
            ->exists();

        if (! $profileExists) {
            throw new \InvalidArgumentException('Configured transport profile is missing or inactive.');
        }

        $tables = $this->resolveDataPortabilityTables($config['selected_tables'] ?? null);

        $operation = DataPortabilityOperation::create([
            'type' => 'transport',
            'status' => 'queued',
            'requested_by_user_id' => $this->resolveRequestedByUserId($context),
            'selected_tables' => $tables,
            'restore_mode' => $restoreMode,
            'options' => [
                'transport_profile_id' => $transportProfileId,
                'delivery_mode' => 'direct_payload',
            ],
        ]);

        RunDatabaseTransportJob::dispatch($operation->id)
            ->onQueue(config('data-portability.queue'));

        Log::info('Data portability transport action queued', [
            'action_id' => $action->id,
            'operation_id' => $operation->id,
            'transport_profile_id' => $transportProfileId,
            'selected_tables_count' => count($tables),
            'restore_mode' => $restoreMode,
        ]);
    }

    /**
     * @param mixed $selectedTables
     * @return array<int, string>
     */
    private function resolveDataPortabilityTables(mixed $selectedTables): array
    {
        $selected = null;
        if (is_array($selectedTables)) {
            $selected = array_values(array_map('strval', $selectedTables));
        }

        return $this->dataPortabilityTableService->resolveSelectedTables($selected);
    }

    private function resolveRequestedByUserId(array $context): ?int
    {
        if (isset($context['user']) && $context['user'] instanceof User) {
            return (int) $context['user']->id;
        }

        return null;
    }

    /**
     * Execute an email action.
     */
    private function executeEmailAction(ActionListAction $action, array $context): void
    {
        $config = $action->config;
        // Prefer template_id (UI/admin), but keep webhook_template_id as legacy fallback.
        $templateId = $config['template_id'] ?? $config['webhook_template_id'] ?? null;
      

        if (!$templateId) {
            Log::warning('Email action without template_id/webhook_template_id', [
                'action_id' => $action->id,
                'config_keys' => array_keys($config),
            ]);
            return;
        }

        $sendToAttendees = (bool) ($config['recipients']['attendees'] ?? false);
        $sendToWaitlist = (bool) ($config['recipients']['waitlist'] ?? false);
        $sendToAdmins = (bool) ($config['recipients']['admins'] ?? false);
        $sendToModerators = (bool) ($config['recipients']['moderators'] ?? false);

        // Build recipient scope for EmailBroadcastService.
        $scope = 'selection';
        if ($sendToAttendees && $sendToWaitlist) {
            $scope = 'both';
        } elseif ($sendToAttendees) {
            $scope = 'reservations';
        } elseif ($sendToWaitlist) {
            $scope = 'waitlist';
        }

        $userRoles = [];
        if ($sendToAdmins) {
            $userRoles[] = 'admin';
        }
        if ($sendToModerators) {
            $userRoles[] = 'moderator';
        }

        $customRecipients = [];
        if (!empty($config['recipients']['custom'])) {
            $customEmails = array_filter(array_map('trim', explode(',', (string) $config['recipients']['custom'])));
            foreach ($customEmails as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $customRecipients[] = ['email' => $email];
                }
            }
        }

        if (
            !$sendToAttendees
            && !$sendToWaitlist
            && !$sendToAdmins
            && !$sendToModerators
            && empty($customRecipients)
        ) {
            Log::warning('Email action has no recipients configured', ['action_id' => $action->id]);
            return;
        }

        // Handle survey creation or selection
        $surveyId = null;
        $surveyMode = $config['survey_mode'] ?? 'none';
        
        if ($surveyMode === 'create') {
            try {
                $survey = $this->createDynamicSurvey($config, $context);
                if ($survey) {
                    $surveyId = $survey->id;
                    Log::info('Survey created dynamically for email action', [
                        'action_id' => $action->id,
                        'survey_id' => $surveyId,
                        'survey_title' => $survey->title,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('Failed to create dynamic survey for email action', [
                    'action_id' => $action->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } elseif ($surveyMode === 'select' && !empty($config['survey_id'])) {
            $surveyId = (int) $config['survey_id'];
            Log::info('Using existing survey for email action', [
                'action_id' => $action->id,
                'survey_id' => $surveyId,
            ]);
        }

        $result = $this->emailBroadcastService->queueBroadcast(
            (int) $templateId,
            $scope,
            true,
            [],
            [],
            $customRecipients,
            true,
            $userRoles,
            null,
            $surveyId
        );

        Log::info('Email action queued', [
            'action_id' => $action->id,
            'template_id' => (int) $templateId,
            'survey_id' => $surveyId,
            'queued' => $result['queued'] ?? 0,
            'skipped_no_email' => $result['skipped_no_email'] ?? 0,
            'duplicates_removed' => $result['duplicates_removed'] ?? 0,
        ]);
    }

    /**
     * Execute a webhook action.
     */
    private function executeWebhookAction(ActionListAction $action, array $context): void
    {
        $config = $action->config;
        $webhookTemplateId = $config['webhook_template_id'] ?? null;

        if (!$webhookTemplateId) {
            Log::warning('Webhook action without webhook_template_id');
            return;
        }

        // Get template URL
        $template = WebhookTemplate::find($webhookTemplateId);
        
        if (!$template) {
            Log::warning('Webhook template not found: ' . $webhookTemplateId);
            throw new \RuntimeException('Webhook template with ID ' . $webhookTemplateId . ' not found');
        }

        // Build payload - use template's payload_template if no override is set
        $templatePayload = json_decode($template->payload_template ?? '{}', true) ?: [];
        $payload = !empty($config['payload_override']) ? $config['payload_override'] : $templatePayload;
        
        // Add context data to payload if not already present
        if (isset($context['event']) && !isset($payload['event'])) {
            $payload['event'] = [
                'id' => $context['event']->id,
                'title' => $context['event']->title,
                'start_at' => $context['event']->start_at?->toIso8601String(),
                'end_at' => $context['event']->end_at?->toIso8601String(),
            ];
        }

        if (isset($context['reservation']) && !isset($payload['reservation'])) {
            $payload['reservation'] = [
                'id' => $context['reservation']->id,
                'name' => $context['reservation']->display_name,
                'email' => $context['reservation']->email,
                'created_at' => $context['reservation']->created_at?->toIso8601String(),
            ];
        }

        if (isset($context['user']) && !isset($payload['user'])) {
            $payload['user'] = [
                'id' => $context['user']->id,
                'name' => $context['user']->name,
                'email' => $context['user']->email,
            ];
        }

        // Resolve placeholders in URL and payload
        $replacements = $this->placeholderService->replacements();
        $url = strtr($template->url ?? '', $replacements);

        // If payload is a string, resolve placeholders; if array, JSON encode then decode after replacement
        if (is_string($payload)) {
            $payload = json_decode(strtr($payload, $replacements), true) ?: [];
        } elseif (is_array($payload)) {
            $payload = json_decode(strtr(json_encode($payload), $replacements), true) ?: $payload;
        }

        // Send webhook
        $this->webhookService->send($url, $payload);
        Log::info('Webhook action sent to: ' . $url);
    }

    /**
     * Execute a change setting action.
     */
    private function executeChangeSettingAction(ActionListAction $action, array $context): void
    {
        $config = $action->config;
        $key = $config['setting_key'] ?? null;
        $value = $config['setting_value'] ?? null;

        if (!$key) {
            Log::warning('Change setting action without setting_key');
            return;
        }

        // Normalize value types
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_null($value)) {
            $value = '';
        } else {
            $value = $this->placeholderService->replaceString((string) $value);
        }

        // Use firstOrNew + save to trigger setValueAttribute mutator for encryption
        $setting = Setting::firstOrNew(['name' => $key]);
        $setting->value = $value;
        $setting->save();

        // Ensure subsequent reads via SettingsService do not return stale cached values.
        app(\App\Services\SettingsService::class)->refresh();

        Log::info('Setting updated: ' . $key . ' = ' . $value);
    }

    private function executeArchiveReservationAndWaitingListAction(ActionListAction $action, array $context): void
    {
        $config = $action->config;
        $archiveNameTemplate = trim((string) ($config['archive_name'] ?? ''));
        $archiveDescriptionTemplate = isset($config['archive_description']) ? (string) $config['archive_description'] : null;
        $storeEmails = array_key_exists('store_emails', $config)
            ? (bool) $config['store_emails']
            : null;
        $clearReservations = (bool) ($config['clear_reservations'] ?? false);
        $clearWaitlist = (bool) ($config['clear_waitlist'] ?? false);

        if ($archiveNameTemplate === '') {
            Log::warning('Archive action without archive_name', ['action_id' => $action->id]);
            return;
        }

        $archiveName = trim($this->placeholderService->replaceString($archiveNameTemplate));
        $archiveDescription = $archiveDescriptionTemplate !== null
            ? $this->placeholderService->replaceString($archiveDescriptionTemplate)
            : null;

        if ($archiveName === '') {
            Log::warning('Archive action resolved to empty archive name', [
                'action_id' => $action->id,
                'archive_name_template' => $archiveNameTemplate,
            ]);
            return;
        }

        $archive = $this->archiveService->createArchive($archiveName, $archiveDescription, $storeEmails);
        $this->archiveService->archiveData($archive, $storeEmails);

        $deletedReservations = $clearReservations ? Reservation::query()->delete() : 0;
        $deletedWaitlistEntries = $clearWaitlist ? WaitlistEntry::query()->delete() : 0;

        Log::info('Archived reservation and waitlist entries', [
            'action_id' => $action->id,
            'archive_id' => $archive->id,
            'archive_name' => $archive->name,
            'store_emails' => $storeEmails,
            'cleared_reservations' => $clearReservations,
            'cleared_waitlist' => $clearWaitlist,
            'deleted_reservations' => $deletedReservations,
            'deleted_waitlist_entries' => $deletedWaitlistEntries,
        ]);
    }

    private function executeWaitNSecondsAction(ActionListAction $action, array $context): void
    {
        $seconds = (int) ($action->config['seconds'] ?? 0);
        $seconds = max(0, min(3600, $seconds));

        if ($seconds === 0) {
            Log::info('Wait action skipped because seconds is 0', ['action_id' => $action->id]);
            return;
        }

        Log::info('Wait action started', ['action_id' => $action->id, 'seconds' => $seconds]);
        sleep($seconds);
        Log::info('Wait action completed', ['action_id' => $action->id, 'seconds' => $seconds]);
    }

    private function executeChangeDefaultGuestTokenAction(ActionListAction $action, array $context): void
    {
        $config = $action->config;
        $useRandomToken = (bool) ($config['use_random_token'] ?? false);

        $token = '';
        if ($useRandomToken) {
            $randomLength = (int) ($config['random_length'] ?? 8);
            $token = $this->generateRandomLowercaseAlnumToken($randomLength);
        } else {
            $tokenTemplate = (string) ($config['token_value'] ?? '');
            $token = trim($this->placeholderService->replaceString($tokenTemplate));
        }

        if ($token === '') {
            Log::warning('Change default guest token action resolved to empty token', ['action_id' => $action->id]);
            return;
        }

        $targetGuestUser = $this->resolveDefaultGuestUser();
        if (!$targetGuestUser) {
            Log::warning('No active guest user available for default guest token update', ['action_id' => $action->id]);
            return;
        }

        $targetGuestUser->api_token = $token;
        $targetGuestUser->api_token_is_hashed = false;
        $targetGuestUser->save();

        Log::info('Default guest token updated', [
            'action_id' => $action->id,
            'guest_user_id' => $targetGuestUser->id,
            'token_length' => strlen($token),
            'used_random_token' => $useRandomToken,
        ]);
    }

    private function resolveDefaultGuestUser(): ?User
    {
        $selectedUserId = app(\App\Services\SettingsService::class)->get('site_guest_user_id');

        if ($selectedUserId) {
            $selectedUser = User::query()
                ->where('id', (int) $selectedUserId)
                ->where('role', 'guest')
                ->where('active', true)
                ->first();

            if ($selectedUser) {
                return $selectedUser;
            }
        }

        return User::query()
            ->where('role', 'guest')
            ->where('active', true)
            ->orderByDesc('id')
            ->first();
    }

    private function generateRandomLowercaseAlnumToken(int $length): string
    {
        $normalizedLength = max(1, min(8, $length));
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $maxIndex = strlen($alphabet) - 1;

        $token = '';
        for ($i = 0; $i < $normalizedLength; $i++) {
            $token .= $alphabet[random_int(0, $maxIndex)];
        }

        return $token;
    }

    private function executeRemoveAttendeesFromReservationListAction(ActionListAction $action, array $context): void
    {
        $config = $action->config;
        $sendNotification = $config['send_notification'] ?? true;

        // Get all reservations for the current event (if context has event)
        if (isset($context['event']) && $context['event'] instanceof \App\Models\Event) {
            $event = $context['event'];
            
            // Delete all reservations for this event
            $deletedCount = \App\Models\Reservation::where('event_id', $event->id)->delete();
            
            Log::info('Removed ' . $deletedCount . ' attendees from reservation list for event ID: ' . $event->id);
            
            if ($sendNotification) {
                // Send notification to all removed attendees
                // This would require collecting emails before deletion
                // For now, we just log that notification would be sent
                Log::info('Notification would be sent to ' . $deletedCount . ' attendees');
            }
        } else {
            Log::warning('Remove attendees from reservation list action without event context');
        }
    }

    private function executeRemoveAttendeesFromWaitlistAction(ActionListAction $action, array $context): void
    {
        $config = $action->config;
        $sendNotification = $config['send_notification'] ?? true;

        // Get all pending waitlist entries (if context has event)
        if (isset($context['event']) && $context['event'] instanceof \App\Models\Event) {
            $event = $context['event'];
            
            // Delete all pending waitlist entries for this event
            $deletedCount = WaitlistEntry::where('status', 'pending')->delete();
            
            Log::info('Removed ' . $deletedCount . ' attendees from waitlist');
            
            if ($sendNotification) {
                // Send notification to all removed waitlist entries
                Log::info('Notification would be sent to ' . $deletedCount . ' waitlist entries');
            }
        } else {
            Log::warning('Remove attendees from waitlist action without event context');
        }
    }

    private function executeRemoveMailValidationIpRateLimitsAction(ActionListAction $action, array $context): void
    {
        // Use RateLimitCacheService to delete all email validation rate limit keys
        $rateLimitCache = app(\App\Services\RateLimitCacheService::class);
        
        // Delete all keys matching 'email_validation_rate:' prefix
        $deletedCount = $rateLimitCache->forgetByPrefix('email_validation_rate:');
        
        Log::info('Removed ' . $deletedCount . ' mail validation IP rate limits');
    }

    /**
     * Create a survey dynamically based on config template.
     * Supports placeholder substitution in title and description.
     *
     * @param array $config The action config containing survey_title_template, survey_description_template, etc.
     * @param array $context The execution context (event, reservation, user, etc.)
     * @return Survey|null The created survey or null if creation fails
     */
    private function createDynamicSurvey(array $config, array $context): ?Survey
    {
        $titleTemplate = trim((string) ($config['survey_title_template'] ?? ''));
        $descriptionTemplate = isset($config['survey_description_template']) 
            ? trim((string) $config['survey_description_template']) 
            : '';

        if ($titleTemplate === '') {
            Log::warning('Survey creation requires survey_title_template');
            return null;
        }

        // Replace placeholders in title and description
        $title = $this->placeholderService->replaceString($titleTemplate);
        $description = $descriptionTemplate !== ''
            ? $this->placeholderService->replaceString($descriptionTemplate)
            : '';

        // Use event_id from context if available
        $eventId = isset($context['event']) && $context['event'] instanceof \App\Models\Event
            ? $context['event']->id
            : null;

        // Create the survey with basic configuration
        $survey = Survey::create([
            'title' => $title,
            'description' => $description,
            'event_id' => $eventId,
            'active' => true,
            'response_token_type' => 'anonymous',
            'max_responses_per_user' => 1,
        ]);

        Log::info('Dynamic survey created', [
            'survey_id' => $survey->id,
            'survey_title' => $survey->title,
            'event_id' => $eventId,
        ]);

        return $survey;
    }
}
