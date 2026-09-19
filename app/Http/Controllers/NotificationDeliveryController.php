<?php

namespace App\Http\Controllers;

use App\Services\NotificationDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationDeliveryController extends Controller
{
    public function dispatchQuizReceipt(
        Request $request,
        NotificationDeliveryService $notificationDelivery,
    ): JsonResponse {
        $validated = $request->validate([
            'delivery_id' => ['required', 'uuid'],
            'dispatch_token' => ['required', 'uuid'],
        ]);

        // The token is an unguessable, row-scoped capability stored only in
        // the protected outbox. Always return the same response so this route
        // cannot be used to discover delivery records or their state.
        $notificationDelivery->deliverQuizReceiptByCapabilityNow(
            $validated['delivery_id'],
            $validated['dispatch_token'],
        );

        return response()
            ->json(['accepted' => true], 202)
            ->header('Cache-Control', 'no-store');
    }
}
