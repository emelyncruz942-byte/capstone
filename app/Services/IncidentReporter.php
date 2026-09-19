<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class IncidentReporter
{
    public function __construct(private SupabaseService $supabase) {}

    public function reference(Request $request): string
    {
        if (!$request->attributes->has('incident_reference')) {
            $request->attributes->set('incident_reference', 'MV-'.strtoupper(bin2hex(random_bytes(8))));
        }
        return $request->attributes->get('incident_reference');
    }

    public function capture(Request $request, string $kind, int $status): string
    {
        $reference = $this->reference($request);
        if ($request->attributes->get('incident_captured') || !config('mathverse.incidents.enabled')) {
            return $reference;
        }
        $request->attributes->set('incident_captured', true);
        $user = $request->hasSession() ? $request->session()->get('supabase_user', []) : [];
        $actorId = is_array($user) && Str::isUuid((string) ($user['id'] ?? '')) ? $user['id'] : null;
        $emailValue = $request->isMethod('POST') ? $request->input('email') : null;
        $email = is_string($emailValue) ? mb_strtolower(trim(mb_substr($emailValue, 0, 254))) : '';
        $network = (string) $request->ip();
        $key = (string) config('app.key');
        $payload = [
            'reference_id' => $reference, 'kind' => $kind, 'actor_id' => $actorId,
            'subject_hash' => hash_hmac('sha256', 'subject:'.($actorId ?? ($email !== '' ? $email : $network)), $key),
            'network_hash' => hash_hmac('sha256', 'network:'.$network, $key),
            // Route patterns only: never query strings, credentials or IDs.
            'route_name' => mb_substr($request->route()?->uri() ?? 'unmatched', 0, 200),
            'http_status' => max(200, min(599, $status)),
        ];
        Log::warning('MathVerse request failed.', array_intersect_key($payload, array_flip([
            'reference_id', 'kind', 'actor_id', 'route_name', 'http_status',
        ])));
        // Unauthenticated failure traffic must not produce unlimited database
        // writes. References remain searchable in restricted deployment logs.
        $networkBudget = 'incident-events:'.$payload['network_hash'];
        $globalBudget = 'incident-events:global';
        try {
            if (RateLimiter::tooManyAttempts($networkBudget, 60) || RateLimiter::tooManyAttempts($globalBudget, 500)) {
                return $reference;
            }
            RateLimiter::hit($networkBudget, 60);
            RateLimiter::hit($globalBudget, 60);
            $result = $this->supabase->adminInsertResult('incident_events', $payload);
            if ($result['error'] !== null) {
                Log::error('Incident event storage is unavailable.', ['reference_id' => $reference]);
            }
        } catch (\Throwable) {
            // Diagnostics must not convert a handled error into another failure.
            Log::error('Incident event storage is unavailable.', ['reference_id' => $reference]);
        }
        return $reference;
    }
}
