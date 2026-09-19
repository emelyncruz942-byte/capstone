<?php

namespace App\Http\Controllers;

use App\Services\SupabaseService;
use App\Support\SafePath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private SupabaseService $supabase) {}

    public function snapshot(Request $request): JsonResponse
    {
        if ($request->attributes->get('dashboard_chrome_fresh') === false) {
            return response()->json([
                'message' => 'Notifications are temporarily unavailable.',
            ], 503);
        }

        return response()->json([
            'html' => view('partials.notifications')->render(),
        ]);
    }

    public function read(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $user = session('supabase_user');
        $notification = $this->supabase->adminSelect(
            'notifications',
            'id,action_url,read_at',
            ['id' => $id, 'user_id' => $user['id']]
        )[0] ?? null;

        if (!$notification) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'That notification is no longer available.',
                ], 404);
            }

            return back()->with('error', 'That notification is no longer available.');
        }

        if (empty($notification['read_at'])) {
            $this->supabase->adminUpdate(
                'notifications',
                ['read_at' => now()->toIso8601String()],
                ['id' => $id, 'user_id' => $user['id']]
            );
        }

        $actionUrl = SafePath::normalize($notification['action_url'] ?? null);
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Notification marked as read.',
                'action_url' => $request->boolean('follow') ? $actionUrl : null,
            ]);
        }

        if ($request->boolean('follow') && $actionUrl !== null) {
            return redirect($actionUrl);
        }

        return back();
    }

    public function readAll(Request $request): JsonResponse|RedirectResponse
    {
        $user = session('supabase_user');
        $this->supabase->adminUpdate(
            'notifications',
            ['read_at' => now()->toIso8601String()],
            [
                'user_id' => $user['id'],
                'read_at' => ['operator' => 'is', 'value' => 'null'],
            ]
        );

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'All notifications marked as read.',
            ]);
        }

        return back()->with('success', 'All notifications marked as read.');
    }
}
