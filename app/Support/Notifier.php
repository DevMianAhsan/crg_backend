<?php

namespace App\Support;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Creates in-app notifications for staff.
 *
 * Events are sent to every other staff member (the person who did the action
 * already knows). Failures are logged and never break the request that
 * triggered them.
 */
class Notifier
{
    /** Notifies all staff except the acting user. */
    public static function staff(
        string $type,
        string $title,
        string $body,
        array $data = [],
        ?string $link = null,
        ?Request $request = null
    ): void {
        $actorId = $request?->user()?->id;
        $recipients = User::query()
            ->when($actorId, fn ($q) => $q->where('id', '!=', $actorId))
            ->pluck('id');

        self::send($recipients->all(), $type, $title, $body, $data, $link);
    }

    /**
     * Notifies the given users. With a $dedupeKey, a user who already has a
     * notification with that key is skipped (used for daily reminders).
     */
    public static function send(
        array $userIds,
        string $type,
        string $title,
        string $body,
        array $data = [],
        ?string $link = null,
        ?string $dedupeKey = null
    ): int {
        if (empty($userIds)) {
            return 0;
        }

        try {
            $now = now();
            $payload = array_map('strval', array_filter($data, fn ($v) => $v !== null));
            if ($link) {
                $payload['link'] = $link;
            }

            $rows = array_map(fn ($userId) => [
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => json_encode($payload),
                'link' => $link,
                'dedupe_key' => $dedupeKey,
                'created_at' => $now,
            ], array_values(array_unique($userIds)));

            // insertOrIgnore skips rows that hit the (user_id, dedupe_key) unique index
            return AppNotification::query()->insertOrIgnore($rows);
        } catch (\Throwable $e) {
            Log::warning('Notification failed: ' . $e->getMessage(), ['type' => $type]);

            return 0;
        }
    }
}
