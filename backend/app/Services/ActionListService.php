<?php

namespace App\Services;

use App\Models\ActionList;
use App\Models\ActionListAction;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Setting;
use App\Models\WebhookTemplate;

use App\Services\WebhookService;
use App\Services\EmailBroadcastService;
use App\Services\PlaceholderService;
use Illuminate\Support\Facades\Log;

class ActionListService
{
    public function __construct(
        private readonly WebhookService $webhookService,
        private readonly EmailBroadcastService $emailBroadcastService,
        private readonly PlaceholderService $placeholderService,
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
            default => throw new \InvalidArgumentException('Unknown action type: ' . $action->type)
        };
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

        $result = $this->emailBroadcastService->queueBroadcast(
            (int) $templateId,
            $scope,
            true,
            [],
            [],
            $customRecipients,
            true,
            $userRoles
        );

        Log::info('Email action queued', [
            'action_id' => $action->id,
            'template_id' => (int) $templateId,
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
            $value = (string)$value;
        }

        // Use firstOrNew + save to trigger setValueAttribute mutator for encryption
        $setting = Setting::firstOrNew(['name' => $key]);
        $setting->value = $value;
        $setting->save();

        Log::info('Setting updated: ' . $key . ' = ' . $value);
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
            $deletedCount = \App\Models\WaitlistEntry::where('status', 'pending')->delete();
            
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
}
