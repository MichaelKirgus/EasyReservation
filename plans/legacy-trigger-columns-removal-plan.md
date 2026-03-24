# Legacy Trigger Columns Removal Plan

## Goal
Safely remove obsolete event-trigger schema columns that are no longer used after moving to action-list-only execution.

## Scope
Target table: event_triggers

Legacy columns to remove (after verification window):
- action_type
- template_id
- webhook_url
- recipient_attendees
- recipient_waitlist
- recipient_admins
- recipient_moderators
- custom_recipients
- webhook_template_id
- meta

## Current Safety Guards Already In Place
- Backend create/update enforces action_list_id and forces action_type=action_list.
- Trigger execution service executes action lists only.
- Trigger listing API now returns only current fields used by UI.

## Rollout Phases

### Phase 1: Stabilization (no schema changes)
Duration: 1-2 releases

1. Keep legacy columns in DB, but do not read/write them in app logic.
2. Monitor logs for any legacy field usage and trigger failures.
3. Add a one-time SQL audit in staging/prod to detect non-null legacy values:
- Count rows with legacy non-null values.
- Validate that every active trigger has action_list_id.

Suggested checks:
- SELECT COUNT(*) FROM event_triggers WHERE action_list_id IS NULL;
- SELECT COUNT(*) FROM event_triggers WHERE template_id IS NOT NULL OR webhook_template_id IS NOT NULL OR webhook_url IS NOT NULL;

Exit criteria:
- No app path depends on legacy columns.
- action_list_id is populated for all active triggers.

### Phase 2: Backfill and constraints hardening
1. Backfill missing action_list_id if any historical rows exist (manual mapping or scripted migration).
2. Add NOT NULL constraint for action_list_id once fully populated.
3. Keep legacy columns for one release after NOT NULL hardening.

Migration suggestion:
- 2026_XX_XX_000100_make_event_triggers_action_list_id_not_null.php

### Phase 3: Drop legacy columns
1. Drop only after one full release cycle post hardening.
2. Deploy in maintenance window if table is large.
3. Keep rollback migration ready.

Migration suggestion:
- 2026_XX_XX_000200_drop_legacy_columns_from_event_triggers.php

Drop order recommendation:
1. Drop foreign keys/indexes tied to webhook_template_id or template_id.
2. Drop columns listed in Scope.

### Phase 4: Cleanup
1. Remove obsolete translations/labels related to old trigger modes.
2. Remove dead tests that target legacy trigger behavior.
3. Update docs to state event triggers are action-list-only.

## Rollback Strategy
- Before Phase 3, take DB backup/snapshot.
- If issues appear after drop:
1. Restore DB snapshot (fastest, safest).
2. Or run rollback migration to re-add columns (without historical data unless restored).

## Suggested Validation Checklist (per environment)
- Create trigger with action list from admin UI.
- Edit trigger and change action list.
- Simulate trigger successfully.
- Trigger listing renders without legacy fields.
- Scheduler-triggered and real event-triggered executions still succeed.
