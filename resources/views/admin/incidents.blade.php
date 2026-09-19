@extends('layouts.dashboard')
@section('title', 'Incident Alerts')
@section('sidebar-subtitle', 'Administrator Console')
@section('mobile-title', 'Incident Alerts')
@section('accent-color', 'text-red-500')
@section('sidebar-nav') @include('admin.partials.sidebar-nav') @endsection
@section('dashboard-content')
<div class="max-w-6xl mx-auto" data-testid="incident-alerts">
    <h2 class="font-orbitron text-2xl font-bold mb-3">Incident Alerts</h2>
    <p class="text-sm text-slate-400 mb-6">Operational and security warnings are checked automatically. Acknowledgement pauses reminders; recovery closes an incident automatically. A critical escalation can alert again. Activity warnings require investigation, not automatic account blocking.</p>
    @if(!$incidentsReady)<p role="alert" class="text-sm text-red-300 mb-5">Incident records could not be loaded. Check the latest database migration and data service. This does not mean there are no incidents.</p>@endif
    <form method="GET" action="/admin/incidents" class="flex flex-wrap gap-3 mb-6">
        <label class="text-xs text-slate-400">Status<select name="status" class="input-mobile-ultra !pl-3 mt-2">@foreach(['open', 'acknowledged', 'resolved', 'all'] as $option)<option value="{{ $option }}" @selected($status === $option)>{{ ucfirst($option) }}</option>@endforeach</select></label>
        <label class="text-xs text-slate-400 flex-1 min-w-0">Error reference<input name="reference" value="{{ $reference }}" maxlength="19" placeholder="MV-0123456789ABCDEF" class="input-mobile-ultra !pl-3 mt-2"></label>
        <button class="btn-rect-secondary !w-auto !px-5 self-end">Filter / Find</button>
    </form>
    @if($reference !== '')
        <section class="portal-frame !p-5 mb-6"><h3 class="font-bold">Reference {{ $reference }}</h3>
            @if($event)<p class="text-sm text-slate-300 mt-3">{{ str_replace('_', ' ', $event['kind']) }} · HTTP {{ $event['http_status'] }} · <code>{{ $event['route_name'] }}</code></p><p class="text-xs text-slate-400 mt-2">{{ \App\Support\AppDate::format($event['created_at'], 'M j, Y g:i A') }} · User {{ $event['actor_id'] ?? 'not signed in' }}</p>
            @else<p class="text-xs text-slate-400 mt-3">No retained diagnostic event matches this reference. Check the deployment logs; diagnostics are retained for 30 days.</p>@endif
        </section>
    @endif
    <div class="space-y-4">
        @forelse($items as $incident)
            <article class="portal-frame !p-5"><div class="flex flex-wrap justify-between gap-3"><h3 class="font-bold {{ $incident['severity'] === 'critical' ? 'text-red-300' : 'text-amber-300' }}">{{ ucfirst($incident['severity']) }} · {{ ucfirst(str_replace('_', ' ', $incident['signal_key'])) }}</h3><span class="text-xs text-slate-400">{{ ucfirst($incident['status']) }}</span></div>
                <p class="text-sm text-slate-300 mt-3">{{ $incident['summary'] }}</p>
                <p class="text-xs text-slate-500 mt-3">Incident {{ $incident['id'] }} · Last detected {{ \App\Support\AppDate::format($incident['last_seen_at'], 'M j, Y g:i A') }}</p>
                @if(!empty($incident['metrics']['references']))<p class="text-xs text-slate-400 mt-3">Recent errors: @foreach($incident['metrics']['references'] as $errorReference)<a href="/admin/incidents?reference={{ $errorReference }}" class="text-cyan-300 mr-3">{{ $errorReference }}</a>@endforeach</p>@endif
                @if(!empty($incident['notification_error']))<p class="text-xs text-amber-300 mt-3">{{ $incident['notification_error'] }}</p>@endif
                @if($incident['status'] === 'open')<form method="POST" action="/admin/incidents/{{ $incident['id'] }}/acknowledge" class="mt-4" data-native-navigation>@csrf<button class="btn-rect-secondary !w-auto !px-5 !py-3">Acknowledge</button></form>@endif
                <a href="/admin/dashboard?section=audit&audit_category=security" class="text-xs text-cyan-300 inline-block mt-4">Open security audit</a>
            </article>
        @empty @if($incidentsReady)<p class="portal-frame !p-8 text-center text-slate-400">No {{ $status === 'all' ? '' : $status }} incidents.</p>@endif @endforelse
    </div>
    <nav class="flex justify-between gap-4 mt-6 text-xs" aria-label="Incident pages">
        @if($page > 1)<a href="/admin/incidents?{{ http_build_query(['status' => $status, 'page' => $page - 1]) }}">Previous</a>@endif
        <span>Page {{ $page }} of {{ $pages }}</span>
        @if($page < $pages)<a href="/admin/incidents?{{ http_build_query(['status' => $status, 'page' => $page + 1]) }}">Next</a>@endif
    </nav>
</div>
@endsection
@section('modals')
<div id="logoutModal" class="modal-overlay hidden"><div class="portal-frame !p-8 w-full max-w-xs text-center"><h3 class="font-orbitron font-bold mb-5">Log out?</h3><form method="POST" action="/logout">@csrf<button class="btn-rect-primary">Confirm Logout</button></form><button type="button" data-action="closeModal" data-action-args='["logoutModal"]' class="modal-cancel">Cancel</button></div></div>
@endsection
