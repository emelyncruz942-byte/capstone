@extends('layouts.dashboard')

@section('title', 'System Health')
@section('sidebar-subtitle', 'Administrator Console')
@section('mobile-title', 'System Health')
@section('accent-color', 'text-red-500')
@section('sidebar-border', 'border-red-500/20')

@section('sidebar-nav')
    @include('admin.partials.sidebar-nav')
@endsection

@section('dashboard-content')
@php
    $statusLabel = ['healthy' => 'Healthy', 'warning' => 'Warning', 'critical' => 'Critical'];
    $statusIcon = ['healthy' => 'fa-circle-check', 'warning' => 'fa-triangle-exclamation', 'critical' => 'fa-circle-xmark'];
    $healthCards = [
        ['key' => 'database', 'title' => 'Primary Data Service', 'icon' => 'fa-database', 'metric' => is_numeric($health['database']['latency_ms']) ? $health['database']['latency_ms'].' ms' : 'Offline'],
        ['key' => 'migrations', 'title' => 'SQL Migrations', 'icon' => 'fa-code-branch', 'metric' => $health['migrations']['applied'].' / '.$health['migrations']['expected']],
        ['key' => 'scheduler', 'title' => 'Scheduler Heartbeat', 'icon' => 'fa-heart-pulse', 'metric' => is_numeric($health['scheduler']['age_seconds']) ? $health['scheduler']['age_seconds'].'s ago' : 'Missing'],
        ['key' => 'deliveries', 'title' => 'Email and Browser Alerts', 'icon' => 'fa-paper-plane', 'metric' => (($health['deliveries']['counts']['email_failed'] ?? 0) + ($health['deliveries']['counts']['push_failed'] ?? 0)).' failed'],
        ['key' => 'audit', 'title' => 'Privileged Audit Outbox', 'icon' => 'fa-shield-halved', 'metric' => ($health['audit']['pending'] ?? 0).' pending'],
        ['key' => 'deployment', 'title' => 'Deployment Identity', 'icon' => 'fa-code-commit', 'metric' => $health['deployment']['commit']],
    ];
@endphp
<div class="max-w-7xl mx-auto" data-testid="system-health" data-overall-status="{{ $health['overall'] }}">
    <header class="flex flex-col lg:flex-row lg:items-end justify-between gap-5 mb-7 border-b border-white/10 pb-6">
        <div><p class="text-[9px] uppercase tracking-[0.3em] font-bold text-green-400">Deployment and delivery diagnostics</p><h2 class="font-orbitron font-black text-2xl md:text-3xl mt-2">System <span class="text-red-400">Health</span></h2><p class="text-sm text-slate-400 mt-3 max-w-2xl">Verify database migrations, scheduler activity, messages, browser alerts, delivery retries, durable audits, latency, and the deployed commit.</p></div>
        <div class="health-overall" data-status="{{ $health['overall'] }}"><i class="fas {{ $statusIcon[$health['overall']] }}"></i><div><span>Overall status</span><strong>{{ $statusLabel[$health['overall']] }}</strong></div></div>
    </header>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6 text-[10px] text-slate-500"><span><i class="fas fa-code-commit mr-2"></i>Commit <code class="text-slate-300">{{ $health['commit'] }}</code></span></div>

    <section class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 mb-7" aria-label="Health checks">
        @foreach($healthCards as $card)
            @php($check = $health[$card['key']])
            <article class="health-card portal-frame !p-5" data-status="{{ $check['status'] }}" data-check="{{ $card['key'] }}"><div class="flex justify-between gap-4"><span class="health-icon"><i class="fas {{ $card['icon'] }}"></i></span><span class="health-badge" data-status="{{ $check['status'] }}"><i class="fas {{ $statusIcon[$check['status']] }}"></i>{{ $statusLabel[$check['status']] }}</span></div><h3 class="font-orbitron font-bold text-sm mt-5">{{ $card['title'] }}</h3><strong class="health-metric">{{ $card['metric'] }}</strong><p class="text-xs text-slate-500 mt-2">{{ $check['message'] }}</p></article>
        @endforeach
    </section>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <section class="portal-frame !p-5 md:!p-6" aria-labelledby="migration-health-title"><div class="flex items-center justify-between gap-4 mb-4"><h3 id="migration-health-title" class="font-orbitron font-bold">Migration Registry</h3><span class="health-badge" data-status="{{ $health['migrations']['status'] }}">{{ $statusLabel[$health['migrations']['status']] }}</span></div>@if($health['migrations']['missing'])<p class="text-xs text-red-300 mb-3">Run these forward migrations in order:</p><ol class="health-code-list">@foreach($health['migrations']['missing'] as $migration)<li><code>{{ $migration }}</code></li>@endforeach</ol>@else<p class="health-empty"><i class="fas fa-circle-check text-green-400 mr-2"></i>No migration gap detected.</p>@endif</section>
        <section class="portal-frame !p-5 md:!p-6" aria-labelledby="delivery-health-title"><div class="flex items-center justify-between gap-4 mb-4"><h3 id="delivery-health-title" class="font-orbitron font-bold">Delivery Queue</h3><span class="health-badge" data-status="{{ $health['deliveries']['status'] }}">{{ $statusLabel[$health['deliveries']['status']] }}</span></div><div class="grid grid-cols-5 gap-2 mb-5">@foreach(['email_failed' => 'Email', 'push_failed' => 'Push', 'pending' => 'Pending', 'stuck' => 'Stuck', 'exhausted' => 'Exhausted'] as $key => $label)<div class="health-small-stat"><span>{{ $label }}</span><strong>{{ $health['deliveries']['counts'][$key] ?? 0 }}</strong></div>@endforeach</div><div class="space-y-2">@forelse($health['deliveries']['recent_failures'] as $failure)<article class="health-failure"><div><strong>{{ ucfirst(str_replace('_', ' ', $failure['event_type'])) }}</strong><p>{{ $failure['channel'] }} · attempt {{ $failure['attempts'] }}</p></div><p>{{ $failure['error'] }}</p></article>@empty<p class="health-empty"><i class="fas fa-circle-check text-green-400 mr-2"></i>No recent delivery failures.</p>@endforelse</div></section>
        <section class="portal-frame !p-5 md:!p-6" aria-labelledby="audit-health-title"><h3 id="audit-health-title" class="font-orbitron font-bold mb-4">Durable Audit Delivery</h3><div class="grid grid-cols-3 gap-3"><div class="health-small-stat"><span>Pending</span><strong>{{ $health['audit']['pending'] }}</strong></div><div class="health-small-stat"><span>Stale</span><strong>{{ $health['audit']['stale'] }}</strong></div><div class="health-small-stat"><span>Recorded failures</span><strong>{{ $health['audit']['failed'] }}</strong></div></div><p class="text-xs text-slate-500 mt-4">A stale pending row means an administrator action started but its final audit outcome was not delivered. Recorded failures are completed records for actions that safely failed.</p><a href="/admin/dashboard?section=audit&audit_category=security&audit_outcome=pending" class="btn-rect-secondary !py-2 mt-4 inline-flex justify-center">Open pending security events</a></section>
        <section class="portal-frame !p-5 md:!p-6" aria-labelledby="scheduler-health-title"><h3 id="scheduler-health-title" class="font-orbitron font-bold mb-4">Scheduler</h3><dl class="health-detail-list"><div><dt>Scheduler last seen</dt><dd>{{ \App\Support\AppDate::format($health['scheduler']['last_seen_at'], 'M j, Y g:i:s A', 'Never') }}</dd></div></dl><p class="text-[10px] text-slate-500 mt-4"><code>php artisan schedule:run</code> must run every minute for delivery-outbox retries and the system heartbeat.</p></section>
        <section class="portal-frame !p-5 md:!p-6" aria-labelledby="incident-config-title"><h3 id="incident-config-title" class="font-orbitron font-bold mb-4">Automatic Incident Alerts</h3><p class="text-xs text-slate-400">Monitoring {{ config('mathverse.incidents.enabled') ? 'enabled' : 'disabled' }} · Independent monitor token {{ strlen((string) config('mathverse.incidents.monitor_token')) >= 32 ? 'configured' : 'not configured' }} · Fallback webhook {{ config('mathverse.incidents.webhook_url') ? 'configured' : 'not configured' }}</p><p class="text-xs text-slate-400 mt-3">Independent check last seen: {{ \App\Support\AppDate::format($health['independent_monitor']['last_seen_at'] ?? null, 'M j, Y g:i:s A', 'Never') }} · {{ $health['independent_monitor']['status'] ?? 'unknown' }}</p><p class="text-xs text-slate-500 mt-3">Configure the independent observer to detect a stopped scheduler. A stopped scheduler cannot run its own checks. An alternative webhook can still notify you when application email fails.</p><a href="/admin/incidents" class="btn-rect-secondary !w-auto !px-5 !py-3 inline-flex mt-4">Open incident alerts</a></section>
    </div>
</div>
@endsection

@section('modals')
<div id="logoutModal" class="modal-overlay hidden"><div class="portal-frame !p-10 w-full max-w-xs text-center border-red-500/30"><i class="fas fa-power-off text-4xl text-red-500 mb-4"></i><h3 class="font-orbitron font-bold mb-2 uppercase">Log out?</h3><p class="text-[10px] text-slate-500 mb-8 uppercase tracking-widest">End administrator session</p><form method="POST" action="/logout">@csrf<button type="submit" class="btn-rect-primary !bg-red-600 !text-white">Confirm Logout</button></form><button type="button" data-action="closeModal" data-action-args='["logoutModal"]' class="modal-cancel">Cancel</button></div></div>
@endsection
