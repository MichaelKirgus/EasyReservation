<?php

/**
 * Lightweight health check script for worker and scheduler containers.
 *
 * Served by PHP's built-in web server on port 8081. This avoids bootstrapping
 * the full Laravel application for every health probe.
 *
 * Checks:
 *  1. The heartbeat file exists and was updated within the last 3 minutes.
 *  2. The main process (queue:work or schedule:work) is still running.
 */

header('Content-Type: application/json');

$heartbeatFile = '/tmp/health_heartbeat';
$maxAge = 180; // seconds – heartbeat cron runs every 60s, allow 3 minutes of slack

$checks = [];
$healthy = true;

// --- Heartbeat file freshness ---
if (file_exists($heartbeatFile)) {
    $age = time() - filemtime($heartbeatFile);
    if ($age <= $maxAge) {
        $checks['heartbeat'] = ['status' => 'ok', 'age_seconds' => $age];
    } else {
        $checks['heartbeat'] = ['status' => 'stale', 'age_seconds' => $age, 'max_age' => $maxAge];
        $healthy = false;
    }
} else {
    // Allow a grace period on first startup (heartbeat file not yet created)
    $checks['heartbeat'] = ['status' => 'pending', 'message' => 'Heartbeat file not yet created'];
    // Don't mark unhealthy during initial startup grace
}

// --- Main process check ---
$role = getenv('CONTAINER_ROLE') ?: 'unknown';

if ($role === 'worker') {
    $processCheck = trim(shell_exec('pgrep -f "queue:work" 2>/dev/null') ?? '');
    $checks['process'] = $processCheck
        ? ['status' => 'ok', 'role' => 'worker']
        : ['status' => 'error', 'role' => 'worker', 'message' => 'queue:work process not found'];
    if (!$processCheck) {
        $healthy = false;
    }
} elseif ($role === 'scheduler') {
    $processCheck = trim(shell_exec('pgrep -f "schedule:work" 2>/dev/null') ?? '');
    $checks['process'] = $processCheck
        ? ['status' => 'ok', 'role' => 'scheduler']
        : ['status' => 'error', 'role' => 'scheduler', 'message' => 'schedule:work process not found'];
    if (!$processCheck) {
        $healthy = false;
    }
} else {
    $checks['process'] = ['status' => 'unknown', 'message' => 'CONTAINER_ROLE not set'];
}

// --- Cron check ---
$cronCheck = trim(shell_exec('pgrep crond 2>/dev/null') ?? '');
$checks['cron'] = $cronCheck
    ? ['status' => 'ok']
    : ['status' => 'error', 'message' => 'crond not running'];
if (!$cronCheck) {
    $healthy = false;
}

$status = $healthy ? 'healthy' : 'unhealthy';
http_response_code($healthy ? 200 : 503);

echo json_encode([
    'status' => $status,
    'service' => $role,
    'timestamp' => gmdate('c'),
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
