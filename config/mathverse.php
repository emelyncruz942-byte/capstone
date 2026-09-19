<?php

return [
    'deployment_commit' => env(
        'MATHVERSE_COMMIT',
        env('RENDER_GIT_COMMIT', env('RAILWAY_GIT_COMMIT_SHA', env('VERCEL_GIT_COMMIT_SHA', env('GITHUB_SHA'))))
    ),
    'health' => [
        'supabase_warning_ms' => (int) env('HEALTH_SUPABASE_WARNING_MS', 800),
        'supabase_critical_ms' => (int) env('HEALTH_SUPABASE_CRITICAL_MS', 2500),
        'scheduler_warning_seconds' => (int) env('HEALTH_SCHEDULER_WARNING_SECONDS', 180),
        'scheduler_critical_seconds' => (int) env('HEALTH_SCHEDULER_CRITICAL_SECONDS', 600),
        'audit_pending_critical_seconds' => (int) env('HEALTH_AUDIT_PENDING_CRITICAL_SECONDS', 120),
    ],
    'incidents' => [
        'enabled' => (bool) env('INCIDENT_ALERTS_ENABLED', env('APP_ENV') !== 'testing'),
        'monitor_token' => env('INCIDENT_MONITOR_TOKEN'),
        'webhook_url' => env('INCIDENT_WEBHOOK_URL'),
        'window_seconds' => (int) env('INCIDENT_WINDOW_SECONDS', 600),
        'error_threshold' => (int) env('INCIDENT_ERROR_THRESHOLD', 10),
        'failure_threshold' => (int) env('INCIDENT_FAILURE_THRESHOLD', 12),
        'admin_action_threshold' => (int) env('INCIDENT_ADMIN_ACTION_THRESHOLD', 6),
        'delivery_failure_threshold' => (int) env('INCIDENT_DELIVERY_FAILURE_THRESHOLD', 5),
        'repeat_seconds' => (int) env('INCIDENT_REPEAT_SECONDS', 3600),
    ],
];
