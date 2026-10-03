@extends('layouts.dashboard')

@section('title', 'Review Support Ticket')
@section('sidebar-border', 'border-red-500/20')
@section('sidebar-subtitle', 'System Administrator')
@section('sidebar-subtitle-color', 'text-red-500/60')
@section('accent-color', 'text-red-500')
@section('mobile-title', 'Review Ticket')

@section('sidebar-nav')
    @include('admin.partials.sidebar-nav', ['activePage' => 'support-tickets'])
@endsection

@section('dashboard-content')
@php
    $ticketStatus = $ticket['status'] ?? 'open';
    $ticketPriority = $ticket['priority'] ?? 'normal';
    $safePageUrl = !empty($ticket['page_url']) && \Illuminate\Support\Str::startsWith(strtolower($ticket['page_url']), ['https://', 'http://']);
@endphp

<div class="max-w-6xl mx-auto" data-testid="admin-support-ticket-detail">
    <a href="{{ route('admin.support-tickets.index') }}" class="inline-flex items-center text-xs font-bold uppercase text-slate-400 hover:text-white mb-6">
        <i class="fas fa-arrow-left mr-2"></i> Back to support queue
    </a>

    <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1.25fr)_minmax(20rem,0.75fr)] gap-6 items-start">
        <article class="portal-frame !p-5 md:!p-8">
            <header class="pb-6 border-b border-white/10">
                <div class="flex flex-wrap items-center gap-2 text-[9px] font-black uppercase tracking-wider">
                    <span class="px-2 py-1 rounded bg-cyan-400/10 text-cyan-300">{{ str_replace('_', ' ', $ticketStatus) }}</span>
                    <span class="px-2 py-1 rounded {{ $ticketPriority === 'urgent' ? 'bg-red-500/15 text-red-300' : ($ticketPriority === 'high' ? 'bg-orange-500/15 text-orange-300' : 'bg-white/5 text-slate-300') }}">{{ $ticketPriority }} priority</span>
                    <span class="text-slate-500">{{ $ticket['reporter_role'] ?? 'user' }} · {{ str_replace('_', ' ', $ticket['category'] ?? 'other') }}</span>
                </div>
                <h1 class="font-orbitron font-bold text-xl md:text-2xl mt-4 break-words">{{ $ticket['subject'] }}</h1>
                <p class="text-xs text-slate-400 mt-3">Reported by <strong class="text-white">{{ $ticket['reporter_name'] ?? 'Unknown user' }}</strong> <span class="break-all">{{ !empty($ticket['reporter_email']) ? '· '.$ticket['reporter_email'] : '' }}</span></p>
                <p class="text-[9px] uppercase tracking-widest text-slate-600 mt-2">Opened {{ \App\Support\AppDate::format($ticket['created_at'] ?? null, 'M j, Y g:i A') }} · Updated {{ \App\Support\AppDate::format($ticket['updated_at'] ?? null, 'M j, Y g:i A') }}</p>
            </header>

            <section class="py-7 border-b border-white/10" aria-labelledby="reported-problem-title">
                <h2 id="reported-problem-title" class="text-[10px] uppercase tracking-widest font-bold text-slate-500 mb-3">Reported problem</h2>
                <p class="text-sm leading-7 text-slate-200 whitespace-pre-wrap break-words">{{ $ticket['description'] }}</p>
            </section>

            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-6 text-xs">
                <div class="rounded-lg border border-white/5 bg-black/20 p-4">
                    <dt class="text-[8px] uppercase tracking-widest text-slate-600 mb-2">Error reference</dt>
                    <dd>
                        @if(!empty($ticket['reference_id']))
                            <a href="/admin/incidents?reference={{ urlencode($ticket['reference_id']) }}" class="font-mono text-cyan-300 hover:text-white">{{ $ticket['reference_id'] }} <i class="fas fa-arrow-up-right-from-square ml-1 text-[8px]"></i></a>
                        @else
                            <span class="text-slate-500">Not provided</span>
                        @endif
                    </dd>
                </div>
                <div class="rounded-lg border border-white/5 bg-black/20 p-4">
                    <dt class="text-[8px] uppercase tracking-widest text-slate-600 mb-2">Reported page</dt>
                    <dd class="break-all">
                        @if($safePageUrl)
                            <a href="{{ $ticket['page_url'] }}" target="_blank" rel="noopener noreferrer" class="text-cyan-300 hover:text-white">{{ $ticket['page_url'] }} <i class="fas fa-arrow-up-right-from-square ml-1 text-[8px]"></i></a>
                        @elseif(!empty($ticket['page_url']))
                            <span class="text-slate-400">{{ $ticket['page_url'] }}</span>
                        @else
                            <span class="text-slate-500">Not provided</span>
                        @endif
                    </dd>
                </div>
            </dl>

            @if(!empty($ticket['admin_response']))
                <section class="mt-6 rounded-lg border border-cyan-400/20 bg-cyan-400/5 p-5" aria-labelledby="current-response-title">
                    <h2 id="current-response-title" class="text-[10px] uppercase tracking-widest font-bold text-cyan-300 mb-3">Current requester response</h2>
                    <p class="text-sm leading-7 text-slate-200 whitespace-pre-wrap break-words">{{ $ticket['admin_response'] }}</p>
                </section>
            @endif
        </article>

        <aside class="portal-frame !p-5 md:!p-6 xl:sticky xl:top-6" aria-labelledby="update-ticket-title">
            <div class="flex items-center gap-3 mb-5">
                <span class="w-10 h-10 rounded-lg border border-red-400/30 bg-red-400/10 text-red-300 flex items-center justify-center"><i class="fas fa-headset"></i></span>
                <div>
                    <h2 id="update-ticket-title" class="font-orbitron font-bold text-sm">Update ticket</h2>
                    <p class="text-[9px] text-slate-600 uppercase tracking-widest">The requester will see this response</p>
                </div>
            </div>

            <form method="POST" action="{{ route('admin.support-tickets.update', ['id' => $ticket['id']]) }}" class="space-y-5" data-native-navigation>
                @csrf
                @method('PATCH')
                <input type="hidden" name="lock_version" value="{{ $ticket['lock_version'] ?? 1 }}">

                <label class="block">
                    <span class="block text-[9px] uppercase tracking-widest font-bold text-slate-500 mb-2">Status</span>
                    <select name="status" class="input-mobile-ultra !pl-3" required>
                        @foreach(['open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'closed' => 'Closed'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('status', $ticketStatus) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="block text-[9px] uppercase tracking-widest font-bold text-slate-500 mb-2">Priority</span>
                    <select name="priority" class="input-mobile-ultra !pl-3" required>
                        @foreach(['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('priority', $ticketPriority) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="block text-[9px] uppercase tracking-widest font-bold text-slate-500 mb-2">Response to requester</span>
                    <textarea name="admin_response" rows="9" maxlength="3000"
                              class="input-mobile-ultra !h-auto !pl-3 !py-3 resize-y"
                              placeholder="Explain what was found, what changed, or what the requester should try next.">{{ old('admin_response', $ticket['admin_response'] ?? '') }}</textarea>
                </label>

                <p class="text-[10px] leading-relaxed text-slate-500">A response is required before marking a ticket resolved or closed. Saving uses the latest ticket version to avoid overwriting another administrator’s update.</p>

                <button type="submit" class="btn-rect-primary !bg-red-600 !text-white !py-3">
                    <i class="fas fa-floppy-disk mr-2"></i> Save ticket update
                </button>
            </form>
        </aside>
    </div>
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
