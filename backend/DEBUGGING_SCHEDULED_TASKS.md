# Debugging Scheduled Tasks

## Overview

This document explains how to debug issues with scheduled tasks in the EasyReservation system.

## Common Issues and Solutions

### 1. Webhook Execution Fails Silently

**Problem:** When a webhook task is executed, no error message appears in the UI or logs.

**Root Cause:** The [`WebhookService::send()`](app/Services/WebhookService.php:8) method previously caught exceptions without logging them.

**Solution:** The service now includes:
- URL validation before sending
- Detailed logging of payload and headers
- Error messages with stack traces

### 2. Manual Task Execution Doesn't Work

**Problem:** Running a task manually via the "Run Now" button doesn't execute it.

**Root Cause:** The [`ScheduledTaskController::runNow()`](app/Http/Controllers/Api/ScheduledTaskController.php:86) method dispatches to a queue job, but errors were not properly returned.

**Solution:** The controller now:
- Logs the task execution start
- Returns detailed error messages with stack traces
- Returns success response with task details

### 3. Tasks Not Executed Automatically

**Problem:** Scheduled tasks don't run at their scheduled time.

**Root Cause:** 
- The scheduler might not be running (`php artisan schedule:run`)
- Queue worker might not be processing jobs
- Task might have `executed = true` from a previous run

**Solution:** Check:
1. Laravel scheduler is running: `php artisan schedule:work`
2. Queue worker is running: `php artisan queue:work`
3. Task status in database

## Debugging Steps

### Step 1: Enable Debug Logging

Set the log level to debug in `.env`:

```bash
LOG_CHANNEL=stack
LOG_LEVEL=debug
```

### Step 2: Check Laravel Logs

View logs for task execution:

```bash
# View all scheduled task related logs
tail -f storage/logs/laravel.log | grep "ScheduledTask"

# View webhook related logs
tail -f storage/logs/laravel.log | grep "Webhook"
```

### Step 3: Run Task Manually with Debug Output

Execute the command directly to see output:

```bash
php artisan scheduled-tasks:run
```

### Step 4: Check Queue Status

Verify queue jobs are being processed:

```bash
# List failed jobs
php artisan queue:failed

# Retry a failed job
php artisan queue:retry <id>
```

## Log Messages Reference

### ScheduledTaskService

| Message | Description |
|---------|-------------|
| `ScheduledTaskService: Starting runDueTasks` | Start of task check |
| `ScheduledTaskService: Found X due tasks to execute` | Number of tasks found |
| `ScheduledTaskService: Executing task {id}` | Task execution started |

### ExecuteScheduledTaskJob

| Message | Description |
|---------|-------------|
| `ExecuteScheduledTaskJob: Starting job for task ID {id}` | Job received |
| `ExecuteScheduledTaskJob: Found task {id}, executing` | Task found and executing |
| `ExecuteScheduledTaskJob: Task {id} completed successfully` | Task finished |

### WebhookService

| Message | Description |
|---------|-------------|
| `WebhookService: Sending webhook to URL {url}` | Webhook request started |
| `Webhook payload:` | Debug log of payload (DEBUG level) |
| `Webhook sent successfully to {url} (Status: {code})` | Success message |

### ScheduledTaskController

| Message | Description |
|---------|-------------|
| `ScheduledTaskController: runNow called for task ID {id}` | Manual execution requested |
| `ScheduledTaskController: Dispatching ExecuteScheduledTaskJob for task {id}` | Job dispatched to queue |

## Testing Webhooks

Use the test endpoint to verify webhook configuration:

```bash
curl -X POST http://your-domain.com/api/admin/webhook-templates/{id}/test \
  -H "Authorization: Bearer {token}"
```

The response will include:
- Success status
- URL that was called
- Payload sent
- Headers sent

## Database Checks

### Check Task Status

```sql
SELECT id, type, run_at, executed, active, options 
FROM scheduled_tasks 
ORDER BY run_at DESC;
```

### Check Job Logs

```sql
SELECT * FROM job_logs ORDER BY created_at DESC LIMIT 10;
```

## Troubleshooting Checklist

- [ ] Laravel scheduler is running (`php artisan schedule:work`)
- [ ] Queue worker is running (`php artisan queue:work`)
- [ ] Log level is set to debug in `.env`
- [ ] Check `storage/logs/laravel.log` for errors
- [ ] Verify webhook URL is accessible from server
- [ ] Test webhook manually via API endpoint
- [ ] Check task has `active = true` and `executed = false`
