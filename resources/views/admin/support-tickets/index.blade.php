@extends('layouts.dashboard')

@section('title', 'Support Tickets')
@section('sidebar-border', 'border-red-500/20')
@section('sidebar-subtitle', 'System Administrator')
@section('sidebar-subtitle-color', 'text-red-500/60')
@section('accent-color', 'text-red-500')
@section('mobile-title', 'Support Tickets')

@section('sidebar-nav')
    @include('admin.partials.sidebar-nav', ['activePage' => 'support-tickets'])
@endsection

@section('dashboard-content')
@php
    $statusOptions = ['all' => 'All statuses', 'open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'closed' => 'Closed'];
    $priorityOptions = ['all' => 'All priorities', 'urgent' => 'Urgent', 'high' => 'High', 'normal' => 'Normal', 'low' => 'Low'];
    $statusStyles = [
        'open' => 'border-cyan-400/30 bg-cyan-400/10 text-cyan-300',
        'in_progress' => 'border-amber-400/30 bg-amber-400/10 text-amber-300',
        'resolved' => 'border-green-400/30 bg-green-400/10 text-green-300',
        'closed' => 'border-slate-400/30 bg-slate-400/10 text-slate-300',
    ];
    $priorityStyles = ['low' => 'text-slate-400', 'normal' => 'text-cyan-300', 'high' => 'text-orange-300', 'urgent' => 'text-red-300'];
@endphp

<div class="max-w-7xl mx-auto" data-testid="admin-support-tickets">
    <header class="flex flex-col lg:flex-row lg:items-end justify-between gap-5 mb-7 border-b border-white/10 pb-6">
        <div>
            <p class="text-[9px] uppercase tracking-[0.3em] font-bold text-orange-400">Student and teacher assistance</p>
            <h1 class="font-orbitron font-black text-2xl md:text-3xl mt-2">Support <span class="text-red-400">Tickets</span></h1>
            <p class="text-sm text-slate-400 mt-3 max-w-2xl">Review user-reported bugs and errors, record a response, and keep the requester informed through one tracked case.</p>
        </div>
        <a href="/admin/incidents" class="btn-rect-secondary !w-auto !py-3 !px-5">
            <i class="fas fa-triangle-exclamation mr-2"></i> Incident diagnostics
        </a>
    </header>

    <form method="GET" action="{{ route('admin.support-tickets.index') }}" class="portal-frame !p-4 md:!p-5 mb-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] gap-4 items-end">
        <label>
            <span class="block text-[9px] uppercase tracking-widest font-bold text-slate-500 mb-2">Status</span>
            <select name="status" class="input-mobile-ultra !pl-3">
                @foreach($statusOptions as $value => $label)<option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>@endforeach
            </select>
        </label>
        <label>
            <span class="block text-[9px] uppercase tracking-widest font-bold text-slate-500 mb-2">Priority</span>
            <select name="priority" class="input-mobile-ultra !pl-3">
                @foreach($priorityOptions as $value => $label)<option value="{{ $value }}" @selected($priority === $value)>{{ $label }}</option>@endforeach
            </select>
        </label>
        <button class="btn-rect-primary !bg-red-600 !text-white !w-auto !px-6 !py-3"><i class="fas fa-filter mr-2"></i> Apply filters</button>
    </form>

    <div class="flex items-center justify-between gap-4 mb-5 text-[10px] uppercase tracking-widest text-slate-500">
        <span>{{ number_format($total) }} {{ \Illuminate\Support\Str::plural('ticket', $total) }}</span>
        @if($status === 'open')<span class="text-cyan-300">Oldest open tickets first</span>@endif
    </div>

    @if(!$ticketsReady)
        <div role="alert" class="portal-frame !p-5 mb-4 border-red-500/40 text-sm text-red-200">
            <i class="fas fa-triangle-exclamation mr-2"></i>
            The support queue could not be loaded. Check the database migration and data service before assuming the queue is empty.
        </div>
    @endif

    <div class="space-y-4">
        @forelse($tickets as $ticket)
            @php
                $ticketStatus = $ticket['status'] ?? 'open';
                $ticketPriority = $ticket['priority'] ?? 'normal';
            @endphp
            <article class="portal-frame !p-5 md:!p-6 {{ $ticketPriority === 'urgent' ? 'border-red-500/50' : '' }}">
                <div class="flex flex-col xl:flex-row xl:items-start justify-between gap-5">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex rounded border px-2 py-1 text-[9px] font-black uppercase tracking-wider {{ $statusStyles[$ticketStatus] ?? $statusStyles['open'] }}">{{ str_replace('_', ' ', $ticketStatus) }}</span>
                            <span class="text-[9px] font-black uppercase tracking-wider {{ $priorityStyles[$ticketPriority] ?? $priorityStyles['normal'] }}">{{ $ticketPriority }} priority</span>
                            <span class="text-[9px] uppercase tracking-widest text-slate-600">{{ $ticket['reporter_role'] ?? 'user' }} · {{ str_replace('_', ' ', $ticket['category'] ?? 'other') }}</span>
                        </div>
                        <h2 class="text-base md:text-lg font-bold mt-3 break-words">{{ $ticket['subject'] }}</h2>
                        <p class="text-xs text-slate-400 mt-2">Reported by <span class="text-white font-bold">{{ $ticket['reporter_name'] ?? 'Unknown user' }}</span> <span class="break-all">{{ !empty($ticket['reporter_email']) ? '· '.$ticket['reporter_email'] : '' }}</span></p>
                        <p class="text-xs text-slate-500 mt-3 line-clamp-2 break-words">{{ $ticket['description'] }}</p>
                        <div class="flex flex-wrap gap-x-4 gap-y-2 mt-4 text-[9px] uppercase tracking-wider text-slate-600">
                            <span>Created {{ \App\Support\AppDate::format($ticket['created_at'] ?? null, 'M j, Y g:i A') }}</span>
                            <span>Updated {{ \App\Support\AppDate::format($ticket['updated_at'] ?? null, 'M j, Y g:i A') }}</span>
                            @if(!empty($ticket['reference_id']))<code class="text-cyan-400">{{ $ticket['reference_id'] }}</code>@endif
                        </div>
                    </div>
                    <a href="{{ route('admin.support-tickets.show', ['id' => $ticket['id']]) }}" class="btn-rect-primary !bg-red-600 !text-white !w-full xl:!w-auto !py-3 !px-5 text-center shrink-0">
                        <i class="fas fa-folder-open mr-2"></i> Review ticket
                    </a>
                </div>
            </article>
        @empty
            @if($ticketsReady)
                <div class="portal-frame !p-12 text-center">
                    <i class="fas fa-circle-check text-4xl text-green-400 mb-4"></i>
                    <p class="font-bold text-slate-300">No tickets match these filters.</p>
                    <p class="text-xs text-slate-600 mt-2">Change the status or priority filter to inspect other cases.</p>
                </div>
            @endif
        @endforelse
    </div>

    @if($totalPages > 1)
        <nav class="flex items-center justify-center gap-4 mt-8" aria-label="Support queue pages">
            @if($page > 1)
                <a href="{{ route('admin.support-tickets.index', ['status' => $status, 'priority' => $priority, 'page' => $page - 1]) }}" class="btn-rect-secondary !w-auto !py-2 !px-5">Previous</a>
            @endif
            <span class="text-[10px] uppercase tracking-widest text-slate-500">Page {{ $page }} of {{ $totalPages }}</span>
            @if($page < $totalPages)
                <a href="{{ route('admin.support-tickets.index', ['status' => $status, 'priority' => $priority, 'page' => $page + 1]) }}" class="btn-rect-secondary !w-auto !py-2 !px-5">Next</a>
            @endif
        </nav>
    @endif
</div>
@endsection

@section('modals')
<div id="logoutModal" class="modal-overlay hidden">
    <div class="portal-frame !p-10 w-full max-w-xs text-center border-red-500/30">
        <i class="fas fa-power-off text-4xl text-red-500 mb-4"></i>
        <h3 class="font-orbitron font-bold mb-6 uppercase">End Admin Session?</h3>
        <form method="POST" action="/logout">@csrf<button class="btn-rect-primary !bg-red-600 !text-white">Confirm Logout</button></form>
        <button type="button" data-action="closeModal" data-action-args='["logoutModal"]' class="modal-cancel mt-3">Cancel</button>
    </div>
</div>
@endsection
