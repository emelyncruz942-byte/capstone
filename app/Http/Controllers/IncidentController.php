<?php

namespace App\Http\Controllers;

use App\Services\IncidentAlertService;
use App\Services\SupabaseService;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    public function __construct(private SupabaseService $supabase, private IncidentAlertService $alerts) {}

    public function index(Request $request)
    {
        $user = session('supabase_user');
        $status = (string) $request->query('status', 'open');
        $status = in_array($status, ['open', 'acknowledged', 'resolved', 'all'], true) ? $status : 'open';
        $page = max(1, min(100000, (int) $request->query('page', 1)));
        $filters = ['order' => 'last_seen_at.desc,id.asc'];
        if ($status !== 'all') {
            $filters['status'] = $status;
        }
        $incidents = $this->supabase->adminSelectPage('system_incidents', '*', $filters, 25, ($page - 1) * 25);
        $incidentsReady = ($incidents['error'] ?? null) === null;
        $pages = max(1, (int) ceil($incidents['total'] / 25));
        $items = $incidents['data'];
        $reference = strtoupper(trim((string) $request->query('reference', '')));
        $event = null;
        if (preg_match('/^MV-[A-F0-9]{16}$/', $reference)) {
            $event = $this->supabase->adminSelect('incident_events', 'reference_id,kind,actor_id,route_name,http_status,created_at', ['reference_id' => $reference])[0] ?? null;
        }
        $activePage = 'incidents';
        return view('admin.incidents', compact('user', 'items', 'status', 'page', 'pages', 'reference', 'event', 'activePage', 'incidentsReady'));
    }

    public function acknowledge(string $id)
    {
        $result = $this->supabase->adminRpcResult('acknowledge_system_incident', [
            'p_actor_id' => session('supabase_user.id'), 'p_id' => $id,
        ]);
        return redirect('/admin/incidents')->with($result['error'] === null ? 'success' : 'error', $result['error'] === null
            ? 'Incident acknowledged. Repeated alerts are paused until recovery or a critical escalation.'
            : 'The incident could not be acknowledged.');
    }

    public function monitor(Request $request)
    {
        $token = (string) config('mathverse.incidents.monitor_token');
        $provided = (string) $request->bearerToken();
        // Fail closed when unconfigured. Never take the token from a URL.
        abort_unless(strlen($token) >= 32 && hash_equals($token, $provided), 401);
        $stats = $this->alerts->check();
        $recorded = $this->supabase->adminUpsert('system_heartbeats', [
            'component' => 'independent_incident_monitor', 'status' => $stats['failed'] > 0 ? 'failed' : 'ok',
            'details' => ['enabled' => $stats['enabled']], 'checked_at' => now()->utc()->toIso8601String(),
        ], 'component');
        if (($recorded[0]['component'] ?? null) !== 'independent_incident_monitor') {
            $stats['failed']++;
        }
        return response()->json($stats, !$stats['enabled'] || $stats['failed'] > 0 ? 503 : 200)
            ->header('Cache-Control', 'no-store, private');
    }
}
