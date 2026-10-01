<?php

namespace Tests\Unit;

use App\Support\NotificationDigest;
use PHPUnit\Framework\TestCase;

class NotificationDigestTest extends TestCase
{
    public function test_it_groups_bursts_for_the_same_event_destination(): void
    {
        $rows = [
            $this->notification('newest', '/teacher/classes/class-a/quizzes/session-a/results', null),
            $this->notification('older', '/teacher/classes/class-a/quizzes/session-a/results', '2026-09-30T02:00:00Z'),
            $this->notification('other-quiz', '/teacher/classes/class-a/quizzes/session-b/results', null),
        ];

        $groups = NotificationDigest::group($rows);

        $this->assertCount(2, $groups);
        $this->assertSame('newest', $groups[0]['id']);
        $this->assertSame(2, $groups[0]['group_count']);
        $this->assertSame(1, $groups[0]['group_unread_count']);
        $this->assertSame('other-quiz', $groups[1]['id']);
    }

    public function test_it_keeps_the_digest_card_count_bounded(): void
    {
        $rows = [];
        for ($index = 0; $index < 20; $index++) {
            $rows[] = $this->notification((string) $index, '/notifications/'.$index, null);
        }

        $this->assertCount(10, NotificationDigest::group($rows, 10));
        $this->assertSame([], NotificationDigest::group($rows, 0));
    }

    /** @return array<string, mixed> */
    private function notification(string $id, string $actionUrl, ?string $readAt): array
    {
        return [
            'id' => $id,
            'type' => 'quiz_submitted',
            'title' => 'Student submitted a quiz',
            'message' => 'A student completed a quiz.',
            'action_url' => $actionUrl,
            'read_at' => $readAt,
        ];
    }
}
