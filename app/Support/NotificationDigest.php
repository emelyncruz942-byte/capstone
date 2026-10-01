<?php

namespace App\Support;

class NotificationDigest
{
    /**
     * Collapse a bounded set of recent notification rows into activity groups.
     *
     * Rows are expected in newest-first order. Notifications that point to the
     * same destination and describe the same event type share one card, while
     * the newest row remains the card users open.
     *
     * @param  array<int, array<string, mixed>>  $notifications
     * @return array<int, array<string, mixed>>
     */
    public static function group(array $notifications, int $limit = 10): array
    {
        if ($limit < 1) {
            return [];
        }

        $groups = [];
        $indexes = [];

        foreach ($notifications as $notification) {
            if (! is_array($notification)) {
                continue;
            }

            $key = self::key($notification);
            if (! array_key_exists($key, $indexes)) {
                if (count($groups) >= $limit) {
                    continue;
                }

                $notification['group_count'] = 1;
                $notification['group_unread_count'] = empty($notification['read_at']) ? 1 : 0;
                $indexes[$key] = count($groups);
                $groups[] = $notification;

                continue;
            }

            $index = $indexes[$key];
            $groups[$index]['group_count']++;
            if (empty($notification['read_at'])) {
                $groups[$index]['group_unread_count']++;
            }
        }

        return $groups;
    }

    /** @param array<string, mixed> $notification */
    private static function key(array $notification): string
    {
        $type = trim((string) ($notification['type'] ?? 'notification'));
        $title = trim((string) ($notification['title'] ?? ''));
        $actionUrl = trim((string) ($notification['action_url'] ?? ''));

        // The action path carries the class/session/report context for the
        // high-volume events, so separate quizzes and classes never merge.
        return hash('sha256', implode("\0", [$type, $title, $actionUrl]));
    }
}
