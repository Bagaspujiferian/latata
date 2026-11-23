<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function index(Request $request): View
    {
        $user = $this->requireUser($request);
        $category = $request->string('category')->toString();
        $validCategories = ['order', 'escrow', 'chat', 'kyc', 'withdrawal', 'system'];
        if (! in_array($category, $validCategories, true)) {
            $category = '';
        }

        $query = UserNotification::where('user_id', $user->id);
        if ($category !== '') {
            $query->where('category', $category);
        }

        $notifications = $query
            ->orderByRaw('CASE WHEN read_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $unreadCount = UserNotification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        $categoryCounts = UserNotification::where('user_id', $user->id)
            ->selectRaw('category, COUNT(*) as total, SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) as unread')
            ->groupBy('category')
            ->get()
            ->keyBy('category');

        return view('notifications.index', compact(
            'user',
            'notifications',
            'category',
            'unreadCount',
            'categoryCounts',
        ));
    }

    public function markRead(Request $request, int $notification): JsonResponse|RedirectResponse
    {
        $userId = $this->requireUser($request)->id;
        $record = UserNotification::where('id', $notification)
            ->where('user_id', $userId)
            ->first();

        if (! $record) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => 'Notifikasi tidak ditemukan.'], 404);
            }
            abort(404);
        }

        $this->notificationService->markAsRead($userId, $notification);

        if ($request->expectsJson()) {
            $unread = UserNotification::where('user_id', $userId)->whereNull('read_at')->count();

            return response()->json(['ok' => true, 'unread_count' => $unread]);
        }

        if ($request->boolean('go') && $record->action_url) {
            return redirect()->to($record->action_url);
        }

        return back()->with('success', 'Notifikasi ditandai sudah dibaca.');
    }

    public function markAllRead(Request $request): JsonResponse|RedirectResponse
    {
        $count = $this->notificationService->markAllAsRead($this->requireUser($request)->id);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'marked_count' => $count, 'unread_count' => 0]);
        }

        return back()->with('success', $count > 0 ? "{$count} notifikasi ditandai sudah dibaca." : 'Semua notifikasi sudah terbaca.');
    }
}
