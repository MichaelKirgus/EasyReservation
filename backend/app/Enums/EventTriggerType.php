<?php
namespace App\Enums;

enum EventTriggerType: string
{
    case RESERVATION_FULL = 'reservation_full';
    case RESERVATION_DISABLED = 'reservation_disabled';
    case RESERVATION_ENABLED = 'reservation_enabled';
    case WAITLIST_ENABLED = 'waitlist_enabled';
    case WAITLIST_DISABLED = 'waitlist_disabled';
}
