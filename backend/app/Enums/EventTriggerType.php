<?php
namespace App\Enums;

enum EventTriggerType: string
{
    case RESERVATION_FULL = 'reservation_full';
    case RESERVATION_DISABLED = 'reservation_disabled';
    case RESERVATION_ENABLED = 'reservation_enabled';
    case RESERVATION_ADDED = 'reservation_added';
    case RESERVATION_REMOVED = 'reservation_removed';
    case RESERVATION_CANCELED = 'reservation_canceled';
    case WAITLIST_ENABLED = 'waitlist_enabled';
    case WAITLIST_DISABLED = 'waitlist_disabled';
    case WAITLIST_ENTRY_ADDED = 'waitlist_entry_added';
    case WAITLIST_ENTRY_REMOVED = 'waitlist_entry_removed';
    case APPLICATION_ERROR = 'application_error';
    case SETTING_CHANGED = 'setting_changed';
}
