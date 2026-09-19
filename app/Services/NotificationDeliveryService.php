<?php

namespace App\Services;

use App\Mail\MathVerseEventMail;
use App\Support\SafePath;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class NotificationDeliveryService
{
    private const DELIVERY_COLUMNS = 'id,notification_id,user_id,channel,event_type,recipient_email,recipient_name,title,message,action_url,data,delivery_key,status,attempts';

    private const IMMEDIATE_EMAIL_EVENTS = [
        'incident_alert',
        'teacher_application_received',
        'teacher_approved',
        'teacher_denied',
        'account_suspended',
        'account_restored',
        'quiz_retake_granted',
        'quiz_excused',
        'quiz_result_recorded',
        'removed_from_class',
    ];

    public function __construct(
        private SupabaseService $supabase,
        private AdminPushService $webPush,
    ) {}

    public function isReady(): bool
    {
        return $this->supabase->adminSelectResult(
            'notification_deliveries',
            'id',
            ['limit' => 1]
        )['error'] === null;
    }

    public function emailConfigurationIssue(): ?string
    {
        if (!app()->isProduction()) {
            return null;
        }

        $mailer = strtolower(trim((string) config('mail.default')));
        if (!$this->mailerUsesExternalTransport($mailer)) {
            return 'MathVerse event email is not connected to a real mail provider. Configure MAIL_MAILER and the matching MAIL_* settings in the production environment.';
        }

        $fromAddress = mb_strtolower(trim((string) config('mail.from.address')));
        $fromDomain = str_contains($fromAddress, '@')
            ? substr($fromAddress, strrpos($fromAddress, '@') + 1)
            : '';
        if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false
            || in_array($fromDomain, ['example.com', 'example.net', 'example.org'], true)
        ) {
            return 'MathVerse event email needs a valid production MAIL_FROM_ADDRESS.';
        }

        return null;
    }

    public function queueStandaloneEmail(
        string $eventType,
        string $recipientEmail,
        string $recipientName,
        string $title,
        string $message,
        ?string $actionUrl,
        array $data,
        string $deliveryKey,
        ?string $recipientUserId = null,
    ): bool {
        $recipientEmail = mb_strtolower(trim($recipientEmail));
        if (filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false
            || ($recipientUserId !== null && !Str::isUuid($recipientUserId))
        ) {
            return false;
        }

        $payload = [
            'channel' => 'email',
            'event_type' => $eventType,
            'recipient_email' => $recipientEmail,
            'recipient_name' => trim($recipientName),
            'title' => $title,
            'message' => $message,
            'action_url' => $this->safeActionPath($actionUrl),
            'data' => $data,
            'delivery_key' => $deliveryKey,
        ];
        if ($recipientUserId !== null) {
            $payload['user_id'] = $recipientUserId;
        }

        $queued = $this->supabase->adminInsertResult('notification_deliveries', $payload);

        if (isset($queued['data'][0]['id'])) {
            return true;
        }

        // A repeated administrator request is already safely queued when the
        // same idempotency key exists.
        return $this->supabase->adminCount('notification_deliveries', [
            'delivery_key' => $deliveryKey,
        ]) > 0;
    }

    public function ensureTeacherApprovalEmailQueued(array $profile): bool
    {
        $userId = (string) ($profile['id'] ?? '');
        $recipientEmail = (string) ($profile['email'] ?? '');
        if (!Str::isUuid($userId)
            || filter_var(mb_strtolower(trim($recipientEmail)), FILTER_VALIDATE_EMAIL) === false
        ) {
            return false;
        }

        // The database notification trigger normally creates this delivery in
        // the same request as the role transition. Verify that it did, then
        // create one explicit fallback row if the trigger is absent or stale.
        $existing = $this->supabase->adminSelectResult(
            'notification_deliveries',
            'id,status',
            [
                'user_id' => $userId,
                'channel' => 'email',
                'event_type' => 'teacher_approved',
                'order' => 'created_at.desc',
                'limit' => 1,
            ]
        );
        if ($existing['error'] === null && isset($existing['data'][0]['id'])) {
            return true;
        }

        return $this->queueTeacherApprovalEmail(
            $profile,
            'teacher-approved-fallback:' . $userId
        );
    }

    public function queueTeacherApprovalEmail(array $profile, string $deliveryKey): bool
    {
        $userId = (string) ($profile['id'] ?? '');
        $recipientName = trim(
            (string) ($profile['first_name'] ?? '') . ' ' . (string) ($profile['last_name'] ?? '')
        );

        return $this->queueStandaloneEmail(
            eventType: 'teacher_approved',
            recipientEmail: (string) ($profile['email'] ?? ''),
            recipientName: $recipientName,
            title: 'Teacher account approved',
            message: 'Your MathVerse teacher application was approved. You can now create classes and quizzes.',
            actionUrl: '/teacher/dashboard',
            data: [],
            deliveryKey: $deliveryKey,
            recipientUserId: $userId,
        );
    }

    /** @return array{sent: bool, queued: bool} */
    public function deliverTeacherApprovalEmailNow(array $profile): array
    {
        $userId = (string) ($profile['id'] ?? '');
        if (!Str::isUuid($userId)) {
            return ['sent' => false, 'queued' => false];
        }

        $queued = false;
        try {
            $queued = $this->ensureTeacherApprovalEmailQueued($profile);
            if (!$queued) {
                return ['sent' => false, 'queued' => false];
            }

            return $this->deliverMatchingNow(
                [
                    'user_id' => $userId,
                    'channel' => 'email',
                    'event_type' => 'teacher_approved',
                ],
                'teacher approval',
                true,
            );
        } catch (\Throwable $exception) {
            Log::warning('Immediate teacher approval email could not be prepared.', [
                'user_id' => $userId,
                'exception' => $exception::class,
            ]);

            return ['sent' => false, 'queued' => $queued];
        }
    }

    /** @return array{sent: bool, queued: bool} */
    public function deliverNotificationEmailNow(
        string $userId,
        string $eventType,
        ?string $dedupeKey = null,
        ?string $createdAfter = null,
    ): array {
        if (!Str::isUuid($userId)
            || !in_array($eventType, self::IMMEDIATE_EMAIL_EVENTS, true)
            || ($dedupeKey !== null && (trim($dedupeKey) === '' || strlen($dedupeKey) > 240))
        ) {
            return ['sent' => false, 'queued' => false];
        }

        try {
            $notificationFilters = [
                'user_id' => $userId,
                'type' => $eventType,
                'order' => 'created_at.desc',
                'limit' => 1,
            ];
            if ($dedupeKey !== null) {
                $notificationFilters['dedupe_key'] = $dedupeKey;
            }
            if ($createdAfter !== null) {
                $notificationFilters['created_at'] = [
                    'operator' => 'gte',
                    'value' => $createdAfter,
                ];
            }

            $notificationResult = $this->supabase->adminSelectResult(
                'notifications',
                'id',
                $notificationFilters,
            );
            $notificationId = $notificationResult['data'][0]['id'] ?? null;
            if ($notificationResult['error'] !== null
                || !is_string($notificationId)
                || !Str::isUuid($notificationId)
            ) {
                return ['sent' => false, 'queued' => false];
            }

            return $this->deliverMatchingNow(
                [
                    'notification_id' => $notificationId,
                    'user_id' => $userId,
                    'channel' => 'email',
                    'event_type' => $eventType,
                ],
                str_replace('_', ' ', $eventType),
            );
        } catch (\Throwable $exception) {
            Log::warning('An immediate notification email could not be prepared.', [
                'user_id' => $userId,
                'event_type' => $eventType,
                'exception' => $exception::class,
            ]);

            return ['sent' => false, 'queued' => false];
        }
    }

    /** @return array{sent: bool, queued: bool} */
    public function deliverStandaloneEmailNow(
        string $eventType,
        string $recipientEmail,
        string $recipientName,
        string $title,
        string $message,
        ?string $actionUrl,
        array $data,
        string $deliveryKey,
        ?string $recipientUserId = null,
    ): array {
        if (!in_array($eventType, self::IMMEDIATE_EMAIL_EVENTS, true)) {
            return ['sent' => false, 'queued' => false];
        }

        try {
            $queued = $this->queueStandaloneEmail(
                eventType: $eventType,
                recipientEmail: $recipientEmail,
                recipientName: $recipientName,
                title: $title,
                message: $message,
                actionUrl: $actionUrl,
                data: $data,
                deliveryKey: $deliveryKey,
                recipientUserId: $recipientUserId,
            );
            if (!$queued) {
                return ['sent' => false, 'queued' => false];
            }

            return $this->deliverMatchingNow(
                [
                    'delivery_key' => $deliveryKey,
                    'channel' => 'email',
                    'event_type' => $eventType,
                ],
                str_replace('_', ' ', $eventType),
                true,
            );
        } catch (\Throwable $exception) {
            Log::warning('An immediate standalone email could not be prepared.', [
                'event_type' => $eventType,
                'exception' => $exception::class,
            ]);

            return ['sent' => false, 'queued' => false];
        }
    }

    /** @return array{sent: bool, queued: bool} */
    public function deliverQuizReceiptByCapabilityNow(
        string $deliveryId,
        string $dispatchToken,
    ): array {
        if (!Str::isUuid($deliveryId) || !Str::isUuid($dispatchToken)) {
            return ['sent' => false, 'queued' => false];
        }

        return $this->deliverMatchingNow(
            [
                'id' => $deliveryId,
                'dispatch_token' => $dispatchToken,
                'channel' => 'email',
                'event_type' => 'quiz_result_recorded',
            ],
            'quiz submission receipt',
        );
    }

    /** @return array{claimed: int, sent: int, failed: int, error: string|null} */
    public function deliverPending(int $limit = 50): array
    {
        // Scheduled quiz state changes must happen even when nobody is browsing
        // the site, otherwise a "quiz available" Web Push could be delayed
        // until the next page request.
        $this->supabase->adminRpc('advance_quiz_session_schedule');
        $this->supabase->adminRpc('generate_upcoming_quiz_notifications', [
            'p_user_id' => null,
        ]);

        $workerId = (string) Str::uuid();
        $claimed = $this->supabase->adminRpcResult('claim_notification_deliveries', [
            'p_limit' => max(1, min($limit, 100)),
            'p_worker_id' => $workerId,
        ]);

        if ($claimed['error'] !== null) {
            Log::warning('MathVerse notification delivery claim failed.', [
                'status' => $claimed['status'] ?? null,
            ]);

            return [
                'claimed' => 0,
                'sent' => 0,
                'failed' => 0,
                'error' => 'Notification deliveries could not be claimed. Check the server log and database migration status.',
            ];
        }

        $sent = 0;
        $failed = 0;
        foreach ($claimed['data'] as $delivery) {
            try {
                $this->deliver($delivery);
                $this->markSent($delivery, $workerId);
                $sent++;
            } catch (\Throwable $exception) {
                $this->markFailed($delivery, $workerId, $exception::class);
                Log::warning('MathVerse notification delivery failed.', [
                    'delivery_id' => $delivery['id'] ?? null,
                    'event_type' => $delivery['event_type'] ?? null,
                    'channel' => $delivery['channel'] ?? null,
                    'attempts' => $delivery['attempts'] ?? null,
                    'exception' => $exception::class,
                ]);
                $failed++;
            }
        }

        return [
            'claimed' => count($claimed['data']),
            'sent' => $sent,
            'failed' => $failed,
            'error' => null,
        ];
    }

    /**
     * Claim and deliver exactly one outbox row without racing the scheduled
     * worker. A failed immediate attempt stays in the existing retry queue.
     *
     * @return array{sent: bool, queued: bool}
     */
    private function deliverMatchingNow(
        array $filters,
        string $eventLabel,
        bool $knownQueued = false,
    ): array {
        try {
            $deliveryResult = $this->supabase->adminSelectResult(
                'notification_deliveries',
                self::DELIVERY_COLUMNS,
                [...$filters, 'order' => 'created_at.desc', 'limit' => 1],
            );
            $delivery = $deliveryResult['data'][0] ?? null;
            if ($deliveryResult['error'] !== null || !is_array($delivery)) {
                return ['sent' => false, 'queued' => $knownQueued];
            }

            if (($delivery['status'] ?? '') === 'sent') {
                return ['sent' => true, 'queued' => true];
            }

            $attempts = (int) ($delivery['attempts'] ?? 0);
            $status = (string) ($delivery['status'] ?? '');
            if (!in_array($status, ['pending', 'failed'], true) || $attempts >= 5) {
                return [
                    'sent' => false,
                    'queued' => $status === 'sending' || $attempts < 5,
                ];
            }

            $workerId = (string) Str::uuid();
            $claimed = $this->supabase->adminUpdate('notification_deliveries', [
                'status' => 'sending',
                'attempts' => $attempts + 1,
                'locked_at' => now()->toIso8601String(),
                'locked_by' => $workerId,
                'updated_at' => now()->toIso8601String(),
            ], [
                'id' => (string) $delivery['id'],
                'status' => ['operator' => 'in', 'value' => '(pending,failed)'],
                'attempts' => $attempts,
            ]);

            if (!isset($claimed[0]['id'])) {
                $latest = $this->supabase->adminSelectResult(
                    'notification_deliveries',
                    'status,attempts',
                    ['id' => (string) $delivery['id'], 'limit' => 1],
                );
                $latestStatus = (string) ($latest['data'][0]['status'] ?? '');
                $latestAttempts = (int) ($latest['data'][0]['attempts'] ?? 5);

                return [
                    'sent' => $latestStatus === 'sent',
                    'queued' => $latestStatus === 'sending' || $latestAttempts < 5,
                ];
            }

            try {
                $this->deliver($claimed[0]);
                $this->markSent($claimed[0], $workerId);

                return ['sent' => true, 'queued' => true];
            } catch (\Throwable $exception) {
                $this->markFailed($claimed[0], $workerId, $exception::class);
                Log::warning('An immediate MathVerse delivery failed.', [
                    'delivery_id' => $claimed[0]['id'] ?? null,
                    'event_type' => $claimed[0]['event_type'] ?? null,
                    'event_label' => $eventLabel,
                    'exception' => $exception::class,
                ]);

                return [
                    'sent' => false,
                    'queued' => (int) ($claimed[0]['attempts'] ?? 5) < 5,
                ];
            }
        } catch (\Throwable $exception) {
            Log::warning('An immediate MathVerse delivery could not be claimed.', [
                'event_label' => $eventLabel,
                'exception' => $exception::class,
            ]);

            return ['sent' => false, 'queued' => $knownQueued];
        }
    }

    private function deliver(array $delivery): void
    {
        // Keep assignment and availability fan-out off SMTP even during the
        // deployment window before the matching database migrations are run.
        if (in_array($delivery['event_type'] ?? '', ['quiz_assigned', 'quiz_started'], true)) {
            $this->deliverWebPush($delivery);
            return;
        }

        if (($delivery['channel'] ?? '') === 'email') {
            $this->deliverEmail($delivery);
            return;
        }

        if (($delivery['channel'] ?? '') === 'web_push') {
            $this->deliverWebPush($delivery);
            return;
        }

        throw new \RuntimeException('The notification delivery channel is invalid.');
    }

    private function deliverWebPush(array $delivery): void
    {
        $userId = (string) ($delivery['user_id'] ?? '');
        if ($userId === '') {
            throw new \RuntimeException('The Web Push recipient is missing.');
        }

        $sent = $this->webPush->sendToUser(
            $userId,
            (string) ($delivery['title'] ?? 'MathVerse Notification'),
            (string) ($delivery['message'] ?? 'A new item needs your attention.'),
            $this->safeActionPath($delivery['action_url'] ?? null) ?? '/',
            'mathverse-' . Str::slug((string) ($delivery['event_type'] ?? 'notification'))
                . '-' . (string) ($delivery['notification_id'] ?? $delivery['id'])
        );
        if (!$sent) {
            throw new \RuntimeException('The Web Push service rejected the delivery.');
        }
    }

    private function deliverEmail(array $delivery): void
    {
        if (($configurationIssue = $this->emailConfigurationIssue()) !== null) {
            throw new \RuntimeException($configurationIssue);
        }

        $email = mb_strtolower(trim((string) ($delivery['recipient_email'] ?? '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('The email recipient is invalid.');
        }

        $presentation = $this->emailPresentation((string) ($delivery['event_type'] ?? ''));
        $actionPath = $this->safeActionPath($delivery['action_url'] ?? null);
        $baseUrl = rtrim((string) (
            app()->isProduction()
                ? config('app.canonical_url')
                : config('app.url')
        ), '/');
        $baseParts = parse_url($baseUrl);
        $baseIsValid = is_array($baseParts)
            && in_array(strtolower((string) ($baseParts['scheme'] ?? '')), ['http', 'https'], true)
            && !empty($baseParts['host'])
            && !isset($baseParts['user'])
            && !isset($baseParts['pass'])
            && !isset($baseParts['query'])
            && !isset($baseParts['fragment']);
        if ($actionPath !== null && (
            !$baseIsValid
            || (app()->isProduction() && strtolower((string) ($baseParts['scheme'] ?? '')) !== 'https')
        )) {
            throw new \RuntimeException('APP_URL must be the deployed MathVerse root URL before email delivery.');
        }
        $actionUrl = $actionPath === null ? null : $baseUrl . $actionPath;

        Mail::to($email)->send(new MathVerseEventMail(
            subjectLine: '[Math MetaVerse] ' . (string) $delivery['title'],
            recipientName: trim((string) ($delivery['recipient_name'] ?? '')),
            heading: (string) $delivery['title'],
            messageText: (string) $delivery['message'],
            actionLabel: $actionUrl === null ? null : $presentation['action_label'],
            actionUrl: $actionUrl,
            eyebrow: $presentation['eyebrow'],
            accentColor: $presentation['accent'],
            securityNote: $presentation['note'],
            details: $this->emailDetails($delivery),
        ));
    }

    /** @return array{action_label: string, eyebrow: string, accent: string, note: string} */
    private function emailPresentation(string $eventType): array
    {
        return match ($eventType) {
            'teacher_application_received' => [
                'action_label' => 'Open MathVerse',
                'eyebrow' => 'Teacher Registration',
                'accent' => '#22d3ee',
                'note' => 'An administrator will review the application. No additional submission is needed.',
            ],
            'teacher_approved' => [
                'action_label' => 'Open Teacher Dashboard',
                'eyebrow' => 'Application Decision',
                'accent' => '#22c55e',
                'note' => 'You can now sign in with the email address and password used during registration.',
            ],
            'teacher_denied' => [
                'action_label' => 'Return to MathVerse',
                'eyebrow' => 'Application Decision',
                'accent' => '#f97316',
                'note' => 'This message confirms the administrator’s decision on the submitted teacher application.',
            ],
            'account_suspended' => [
                'action_label' => 'Open MathVerse',
                'eyebrow' => 'Account Status',
                'accent' => '#ef4444',
                'note' => 'Contact a MathVerse administrator if you believe this action was made in error.',
            ],
            'account_restored' => [
                'action_label' => 'Sign In to MathVerse',
                'eyebrow' => 'Account Status',
                'accent' => '#22c55e',
                'note' => 'Your existing sign-in credentials can be used again.',
            ],
            'quiz_assigned' => [
                'action_label' => 'View Assigned Quiz',
                'eyebrow' => 'New Assignment',
                'accent' => '#a855f7',
                'note' => 'Check the availability and due times in MathVerse before beginning.',
            ],
            'quiz_started' => [
                'action_label' => 'Open Available Quiz',
                'eyebrow' => 'Quiz Available',
                'accent' => '#22c55e',
                'note' => 'The quiz is available now. Submit it before its due time, if one is set.',
            ],
            'quiz_retake_granted' => [
                'action_label' => 'Open Retake',
                'eyebrow' => 'Retake Authorized',
                'accent' => '#06b6d4',
                'note' => 'Only the teacher-authorized additional attempt is available.',
            ],
            'quiz_excused' => [
                'action_label' => 'View Class',
                'eyebrow' => 'Absence Excused',
                'accent' => '#8b5cf6',
                'note' => 'This quiz will not be counted as a missed assignment for you.',
            ],
            'quiz_result_recorded' => [
                'action_label' => 'View Quiz Result',
                'eyebrow' => 'Submission Receipt',
                'accent' => '#22d3ee',
                'note' => 'One receipt is sent for the initial attempt and for each separately teacher-authorized retake.',
            ],
            'removed_from_class' => [
                'action_label' => 'Open Student Dashboard',
                'eyebrow' => 'Class Membership',
                'accent' => '#ef4444',
                'note' => 'Contact the teacher or a MathVerse administrator if this was unexpected.',
            ],
            default => [
                'action_label' => 'Open MathVerse',
                'eyebrow' => 'Account Notification',
                'accent' => '#22d3ee',
                'note' => 'This automated message was sent by MathVerse.',
            ],
        };
    }

    /** @return array<int, array{label: string, value: string}> */
    private function emailDetails(array $delivery): array
    {
        $data = is_array($delivery['data'] ?? null) ? $delivery['data'] : [];
        $details = [];

        if (($delivery['event_type'] ?? '') === 'quiz_result_recorded') {
            $correct = isset($data['correct_answers']) ? (int) $data['correct_answers'] : null;
            $total = isset($data['total_questions']) ? (int) $data['total_questions'] : null;
            $attempt = max(1, (int) ($data['attempt_number'] ?? 1));
            if ($correct !== null && $total !== null) {
                $details[] = ['label' => 'Recorded score', 'value' => "{$correct} of {$total} correct"];
            }
            $details[] = [
                'label' => 'Attempt',
                'value' => $attempt === 1
                    ? 'Initial attempt'
                    : 'Authorized retake ' . ($attempt - 1) . " (attempt {$attempt})",
            ];
        }

        foreach ([
            'available_at' => 'Available',
            'due_at' => 'Due',
            'retake_due_at' => 'Retake due',
        ] as $key => $label) {
            if (!empty($data[$key])) {
                $details[] = ['label' => $label, 'value' => $this->formatDateTime((string) $data[$key])];
            }
        }

        if (!empty($data['reason'])
            && in_array($delivery['event_type'] ?? '', ['account_suspended', 'quiz_excused'], true)) {
            $details[] = ['label' => 'Reason', 'value' => mb_substr(trim((string) $data['reason']), 0, 500)];
        }

        return $details;
    }

    private function formatDateTime(string $value): string
    {
        return \App\Support\AppDate::format($value, 'M d, Y · h:i A T', $value);
    }

    private function markSent(array $delivery, string $workerId): void
    {
        $this->supabase->adminUpdate('notification_deliveries', [
            'status' => 'sent',
            'delivered_at' => now()->toIso8601String(),
            'last_error' => null,
            'locked_at' => null,
            'locked_by' => null,
            'updated_at' => now()->toIso8601String(),
        ], [
            'id' => $delivery['id'],
            'locked_by' => $workerId,
        ]);
    }

    private function markFailed(array $delivery, string $workerId, string $message): void
    {
        $attempts = (int) ($delivery['attempts'] ?? 1);
        $delayMinutes = match (true) {
            $attempts <= 1 => 5,
            $attempts === 2 => 15,
            $attempts === 3 => 60,
            default => 180,
        };

        $this->supabase->adminUpdate('notification_deliveries', [
            'status' => 'failed',
            'available_at' => now()->addMinutes($delayMinutes)->toIso8601String(),
            'last_error' => mb_substr($message, 0, 1000),
            'locked_at' => null,
            'locked_by' => null,
            'updated_at' => now()->toIso8601String(),
        ], [
            'id' => $delivery['id'],
            'locked_by' => $workerId,
            'attempts' => $attempts,
        ]);
    }

    private function safeActionPath(mixed $value): ?string
    {
        return SafePath::normalize($value);
    }

    /** @param array<string, bool> $visited */
    private function mailerUsesExternalTransport(string $mailer, array $visited = []): bool
    {
        if ($mailer === '' || isset($visited[$mailer])) {
            return false;
        }
        $visited[$mailer] = true;

        $configuration = config("mail.mailers.{$mailer}");
        if (!is_array($configuration)) {
            return false;
        }

        $transport = strtolower(trim((string) ($configuration['transport'] ?? $mailer)));
        if (in_array($transport, ['log', 'array'], true)) {
            return false;
        }

        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $children = $configuration['mailers'] ?? [];
            if (!is_array($children) || $children === []) {
                return false;
            }

            foreach ($children as $child) {
                if (!$this->mailerUsesExternalTransport((string) $child, $visited)) {
                    return false;
                }
            }

            return true;
        }

        if ($transport === 'smtp') {
            $url = trim((string) ($configuration['url'] ?? ''));
            $host = strtolower(trim((string) ($configuration['host'] ?? '')));

            return $url !== '' || !in_array($host, ['', '127.0.0.1', 'localhost', '::1'], true);
        }

        return in_array(
            $transport,
            ['mailgun', 'postmark', 'resend', 'sendmail', 'ses', 'ses-v2'],
            true
        );
    }
}
