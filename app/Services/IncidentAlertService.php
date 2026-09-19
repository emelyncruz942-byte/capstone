<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IncidentAlertService
{
    public function __construct(
        private SupabaseService $supabase,
        private SystemHealthService $health,
        private NotificationDeliveryService $delivery,
    ) {}

    public function check(): array
    {
        $stats = ['checked' => 0, 'active' => 0, 'notified' => 0, 'failed' => 0, 'enabled' => (bool) config('mathverse.incidents.enabled')];
        if (!$stats['enabled']) {
            return $stats;
        }
        $health = $this->health->snapshot();
        $result = $this->supabase->adminRpcResult('incident_signal_counts', [
            'p_window_seconds' => max(60, min(3600, (int) config('mathverse.incidents.window_seconds', 600))),
        ]);
        $counts = $result['error'] === null ? ($result['data'][0] ?? null) : null;
        $signals = [
            'scheduler' => [$health['scheduler']['status'] !== 'healthy', $health['scheduler']['status'] === 'critical',
                $health['scheduler']['message'], ['age_seconds' => $health['scheduler']['age_seconds']]],
            'stale_privileged_audits' => [$health['audit']['status'] === 'critical', true,
                'Privileged audit records are stale or could not be verified. Inspect pending security events.', ['stale' => $health['audit']['stale']]],
            'database' => [$health['database']['status'] === 'critical', true,
                $health['database']['message'], ['latency_ms' => $health['database']['latency_ms']]],
            'signal_pipeline' => [!is_array($counts), true,
                'Incident/security signals could not be checked. Verify the alert SQL migration and primary data service.', []],
        ];
        if (is_array($counts)) {
            $errors = (int) ($counts['errors'] ?? 0);
            $failures = (int) ($counts['repeated_failures'] ?? 0);
            $actions = (int) ($counts['admin_actions'] ?? 0);
            $actionFailures = (int) ($counts['action_failures'] ?? 0);
            $deliveryFailures = (int) ($counts['delivery_failures'] ?? 0);
            $stuck = (int) ($counts['stuck_deliveries'] ?? 0);
            $signals += [
                'error_spike' => [$errors >= $this->threshold('error_threshold', 10), true,
                    'Server errors exceeded the configured monitoring threshold.', ['count' => $errors, 'references' => $counts['recent_references'] ?? []]],
                'action_failure_spike' => [$actionFailures >= $this->threshold('failure_threshold', 12), false,
                    'Repeated form/action failures require investigation.', ['count' => $actionFailures]],
                'repeated_security_failures' => [$failures >= $this->threshold('failure_threshold', 12), true,
                    'Repeated sign-in, access-denied or throttled requests were detected for one account or network.', ['count' => $failures]],
                'administrator_activity' => [$actions >= $this->threshold('admin_action_threshold', 6), false,
                    'An administrator performed unusually many high-impact actions. Review the security audit; this is a warning, not proof of compromise.', ['count' => $actions]],
                'delivery_failures' => [$deliveryFailures >= $this->threshold('delivery_failure_threshold', 5) || $stuck > 0, $stuck > 0,
                    'Email/push delivery failures have accumulated or a delivery lease is stuck.', ['failed' => $deliveryFailures, 'stuck' => $stuck]],
            ];
        }
        foreach ($signals as $key => [$active, $critical, $summary, $metrics]) {
            $stats['checked']++;
            $stats['active'] += $active ? 1 : 0;
            try {
                $sync = $this->supabase->adminRpcResult('sync_incident_signal', [
                    'p_key' => $key, 'p_active' => $active, 'p_severity' => $critical ? 'critical' : 'warning',
                    'p_summary' => $summary, 'p_metrics' => (object) $metrics,
                    'p_repeat_seconds' => max(300, min(86400, (int) config('mathverse.incidents.repeat_seconds', 3600))),
                ]);
                if ($sync['error'] !== null) {
                    throw new \RuntimeException('Incident state could not be saved.');
                }
                $incident = $sync['data'][0] ?? [];
                if (!($incident['notify'] ?? false)) {
                    continue;
                }
                $sent = $this->notify($incident);
                $stats[$sent ? 'notified' : 'failed']++;
            } catch (\Throwable $exception) {
                Log::error('Incident monitoring could not finish a signal.', ['signal' => $key, 'exception' => $exception::class]);
                $stats['failed']++;
            }
        }
        // Separate monitor heartbeat: the scheduler must never impersonate an
        // independent observer, even though both use this same check service.
        return $stats;
    }

    private function notify(array $incident): bool
    {
        $channels = (array) ($incident['delivered_channels'] ?? []);
        $errors = [];
        $id = (string) $incident['id'];
        $generation = (int) $incident['notification_generation'];
        $title = 'MathVerse '.ucfirst($incident['severity']).' incident';
        $message = $incident['summary'].' Incident: '.$id;
        if (!($channels['bell'] ?? false)) {
            $bell = $this->supabase->adminRpcResult('notify_incident_admins', [
                'p_id' => $id, 'p_generation' => $generation,
            ]);
            if ($bell['error'] === null && ($bell['data'][0]['saved'] ?? false)) {
                $channels['bell'] = true;
            } else {
                $errors[] = 'Administrator bell alerts could not be saved.';
            }
        }
        $recipients = $this->supabase->adminSelectResult('profiles', 'id,email,first_name,last_name', [
            'role' => 'admin', 'suspended_at' => ['operator' => 'is', 'value' => 'null'],
            'deactivated_at' => ['operator' => 'is', 'value' => 'null'], 'limit' => 100,
        ]);
        if ($recipients['error'] !== null || $recipients['data'] === []) {
            $errors[] = 'Active administrator recipients could not be verified.';
        } else {
            foreach ($recipients['data'] as $recipient) {
                $channel = 'email:'.$recipient['id'];
                if ($channels[$channel] ?? false) {
                    continue;
                }
                // Durable, idempotent outbox, dispatched immediately by THIS
                // process. It does not require Laravel Queue or the scheduler.
                $sent = $this->delivery->deliverStandaloneEmailNow(
                    eventType: 'incident_alert', recipientEmail: (string) ($recipient['email'] ?? ''),
                    recipientName: trim(($recipient['first_name'] ?? '').' '.($recipient['last_name'] ?? '')),
                    title: $title, message: mb_substr($message, 0, 600), actionUrl: '/admin/incidents',
                    data: ['incident_id' => $id], deliveryKey: 'incident-email:'.$id.':'.$generation.':'.$recipient['id'],
                    recipientUserId: $recipient['id'],
                );
                if ($sent['sent']) {
                    $channels[$channel] = true;
                } else {
                    $errors[] = 'An administrator email has not been accepted; its durable delivery will be retried.';
                }
            }
        }
        $webhook = trim((string) config('mathverse.incidents.webhook_url', ''));
        if ($webhook !== '' && !($channels['webhook'] ?? false)) {
            try {
                $parts = parse_url($webhook);
                if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
                    || isset($parts['user']) || isset($parts['pass'])) {
                    throw new \RuntimeException('Invalid webhook configuration.');
                }
                $response = Http::connectTimeout(3)->timeout(10)->withOptions(['allow_redirects' => false])
                    ->post($webhook, ['text' => $title.': '.$message, 'content' => $title.': '.$message,
                        'incident_id' => $id, 'severity' => $incident['severity'], 'signal' => $incident['signal_key']]);
                if (!$response->successful()) {
                    throw new \RuntimeException('Webhook did not accept the alert.');
                }
                $channels['webhook'] = true;
            } catch (\Throwable) {
                // Webhook URLs often contain credentials: never log the URL or
                // exception message, and never fall back to an unsafe redirect.
                $errors[] = 'The independent incident webhook did not accept the alert.';
            }
        }
        $complete = $errors === [];
        $result = $this->supabase->adminRpcResult('finish_incident_notification', [
            'p_id' => $id, 'p_lease' => $incident['lease'], 'p_channels' => (object) $channels,
            'p_complete' => $complete, 'p_error' => $complete ? null : implode(' ', array_unique($errors)),
        ]);
        return $complete && $result['error'] === null && ($result['data'][0]['completed'] ?? false);
    }

    private function threshold(string $key, int $default): int
    {
        return max(1, (int) config('mathverse.incidents.'.$key, $default));
    }
}
