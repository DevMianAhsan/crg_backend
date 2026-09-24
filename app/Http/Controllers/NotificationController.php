<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\FcmToken;
use App\Support\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in user's notification inbox and push-token registry.
 * Response shapes match lib/types/notifications.ts in crg-portal.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'unreadOnly' => ['nullable', 'in:true,false,1,0'],
            'type' => ['nullable', 'string', 'max:60'],
        ]);
        $userId = $request->user()->id;
        $limit = (int) ($data['limit'] ?? 20);

        $query = AppNotification::where('user_id', $userId)
            ->when(in_array($data['unreadOnly'] ?? 'false', ['true', '1'], true), fn ($q) => $q->whereNull('read_at'))
            ->when(!empty($data['type']), fn ($q) => $q->where('type', $data['type']))
            ->latest('created_at')
            ->latest('id');

        $page = $query->paginate($limit, ['*'], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (AppNotification $n) => $this->present($n))->values(),
            'unreadCount' => $this->unread($userId),
            'pagination' => [
                'page' => $page->currentPage(),
                'limit' => $page->perPage(),
                'total' => $page->total(),
                'totalPages' => max(1, $page->lastPage()),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'unreadCount' => $this->unread($request->user()->id),
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = AppNotification::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();
        abort_unless($notification, 404);

        if (!$notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json(['success' => true, 'data' => $this->present($notification)]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $updated = AppNotification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['success' => true, 'updated' => $updated]);
    }

    /** POST /auth/fcm-token — registers (or re-assigns) this device's push token */
    public function registerToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fcmToken' => ['required', 'string', 'max:512'],
            'platform' => ['nullable', 'in:WEB,ANDROID,IOS'],
        ]);

        FcmToken::updateOrCreate(
            ['token' => $data['fcmToken']],
            ['user_id' => $request->user()->id, 'platform' => $data['platform'] ?? 'WEB']
        );

        return response()->json(['success' => true, 'message' => 'Device registered.']);
    }

    /** DELETE /notifications/token — unregisters this device (on logout) */
    public function removeToken(Request $request): JsonResponse
    {
        $data = $request->validate(['fcmToken' => ['required', 'string', 'max:512']]);

        FcmToken::where('token', $data['fcmToken'])
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['success' => true, 'message' => 'Device removed.']);
    }

    public function tokens(Request $request): JsonResponse
    {
        $tokens = FcmToken::where('user_id', $request->user()->id)->latest()->get();
        $ready = $this->pushReady();

        return response()->json([
            'success' => true,
            'message' => $ready
                ? 'Push notifications are configured.'
                : 'Push delivery is not configured on the server yet; notifications appear in the app only.',
            'fcmReady' => $ready,
            'count' => $tokens->count(),
            'tokens' => $tokens->map(fn (FcmToken $t) => [
                'id' => (string) $t->id,
                'platform' => $t->platform,
                'fcmToken' => strlen($t->token) > 16
                    ? substr($t->token, 0, 10) . '…' . substr($t->token, -4)
                    : '…',
                'createdAt' => $t->created_at?->toIso8601String(),
                'updatedAt' => $t->updated_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /** POST /notifications/test — puts a test notification in your own inbox */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:500'],
        ]);

        Notifier::send([$request->user()->id], 'TEST', $data['title'], $data['body'], [], '/dashboard/notifications');

        return response()->json([
            'success' => true,
            'message' => $this->pushReady()
                ? 'Test notification sent.'
                : 'Test notification added to your inbox. Push delivery is not configured on the server.',
        ]);
    }

    private function unread(int $userId): int
    {
        return AppNotification::where('user_id', $userId)->whereNull('read_at')->count();
    }

    /** Push needs Firebase service-account credentials on the server */
    private function pushReady(): bool
    {
        $path = env('FIREBASE_CREDENTIALS');

        return is_string($path) && $path !== '' && is_file($path);
    }

    private function present(AppNotification $n): array
    {
        return [
            'id' => (string) $n->id,
            'type' => $n->type,
            'title' => $n->title,
            'body' => $n->body,
            'data' => (object) ($n->data ?? []),
            'link' => $n->link,
            'isRead' => $n->read_at !== null,
            // Display string, e.g. "04-Aug-2026, 05:12 pm"
            'datetime' => $n->created_at?->format('d-M-Y, h:i a'),
            'createdAt' => $n->created_at?->toIso8601String(),
        ];
    }
}
