<?php

namespace App\Services;

use App\Jobs\SendUserNotificationJob;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NotificationService
{
    public function notifyAfterCommit(
        int $userId,
        string $category,
        string $title,
        string $body,
        ?string $actionUrl = null,
        ?string $refType = null,
        ?int $refId = null,
    ): void {
        DB::afterCommit(static function () use ($userId, $category, $title, $body, $actionUrl, $refType, $refId): void {
            SendUserNotificationJob::dispatch(
                $userId,
                $category,
                $title,
                $body,
                $actionUrl,
                $refType,
                $refId,
            )->afterCommit();
        });
    }

    public function notify(
        int $userId,
        string $category,
        string $title,
        string $body,
        ?string $actionUrl = null,
        ?string $refType = null,
        ?int $refId = null,
    ): UserNotification {
        return UserNotification::create([
            'user_id' => $userId,
            'category' => $category,
            'title' => mb_substr(trim($title), 0, 140),
            'body' => mb_substr(trim($body), 0, 255),
            'action_url' => $actionUrl ? mb_substr($actionUrl, 0, 255) : null,
            'reference_type' => $refType ? mb_substr($refType, 0, 60) : null,
            'reference_id' => $refId,
            'read_at' => null,
            'created_at' => now(),
        ]);
    }

    public function notifyAdmins(
        string $category,
        string $title,
        string $body,
        ?string $actionUrl = null,
        ?string $refType = null,
        ?int $refId = null,
    ): void {
        $adminIds = User::where('role', 'admin')->where('is_active', true)->pluck('id');
        foreach ($adminIds as $adminId) {
            $this->notify(
                userId: (int) $adminId,
                category: $category,
                title: $title,
                body: $body,
                actionUrl: $actionUrl,
                refType: $refType,
                refId: $refId,
            );
        }
    }

    /**
     * @return array{unread_count: int, items: Collection<int, UserNotification>}
     */
    public function getBellSummary(int $userId, int $limit = 8): array
    {
        $unreadCount = UserNotification::where('user_id', $userId)
            ->whereNull('read_at')
            ->count();

        $items = UserNotification::where('user_id', $userId)
            ->orderByRaw('CASE WHEN read_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return [
            'unread_count' => $unreadCount,
            'items' => $items,
        ];
    }

    public function markAsRead(int $userId, int $notificationId): bool
    {
        $updated = UserNotification::where('id', $notificationId)
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        if ($updated > 0) {
            return true;
        }

        return UserNotification::where('id', $notificationId)
            ->where('user_id', $userId)
            ->exists();
    }

    public function markAllAsRead(int $userId): int
    {
        return UserNotification::where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
