@extends('layouts.dashboard')

@section('title', 'Help and Support')
@section('sidebar-subtitle', ($user['role'] ?? '') === 'teacher' ? 'Teacher Portal' : 'Student Game Hub')
@section('mobile-title', 'Help and Support')

@section('sidebar-nav')
    @if(($user['role'] ?? '') === 'teacher')
        @include('teacher.partials.sidebar-nav', ['activePage' => 'support'])
    @else
        @include('student.partials.sidebar-nav', ['activePage' => 'support'])
    @endif
@endsection

@section('dashboard-content')
@php
    $categoryOptions = [
        'bug' => 'Bug or broken feature',
        'error' => 'Error message',
        'account' => 'Account or profile',
        'quiz' => 'Quiz or score',
        'vr' => 'VR game',
        'accessibility' => 'Accessibility',
        'other' => 'Other',
    ];
    $statusOptions = [
        'all' => 'All tickets',
        'open' => 'Open',
        'in_progress' => 'In progress',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ];
    $statusStyles = [
        'open' => 'border-cyan-400/30 bg-cyan-400/10 text-cyan-300',
        'in_progress' => 'border-amber-400/30 bg-amber-400/10 text-amber-300',
        'resolved' => 'border-green-400/30 bg-green-400/10 text-green-300',
        'closed' => 'border-slate-400/30 bg-slate-400/10 text-slate-300',
    ];
    $referenceQuery = request()->query('reference_id');
    $referencePrefill = is_string($referenceQuery) ? $referenceQuery : '';
@endphp

<div class="max-w-7xl mx-auto" data-testid="requester-support-tickets">
    <header class="flex flex-col lg:flex-row lg:items-end justify-between gap-5 mb-8 border-b border-white/10 pb-6">
        <div>
            <p class="text-[9px] uppercase tracking-[0.3em] font-bold text-orange-400">MathVerse assistance</p>
            <h1 class="font-orbitron font-black text-2xl md:text-3xl mt-2">Help &amp; <span class="text-cyan-400">Support</span></h1>
            <p class="text-sm text-slate-400 mt-3 max-w-2xl">Report an error or broken feature and track the response from an administrator. Never include your password or access token.</p>
        </div>
        <div class="flex items-center gap-3 text-[10px] uppercase tracking-widest text-slate-500">
            <i class="fas fa-shield-halved text-green-400"></i>
            <span>Visible only to you and administrators</span>
        </div>
    </header>

    <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.35fr)] gap-6 items-start">
        <section class="portal-frame !p-5 md:!p-7 xl:sticky xl:top-6" aria-labelledby="new-ticket-title">
            <div class="flex items-start gap-4 mb-6">
                <span class="w-11 h-11 rounded-lg border border-orange-400/30 bg-orange-400/10 text-orange-300 flex items-center justify-center shrink-0">
                    <i class="fas fa-bug"></i>
                </span>
                <div>
                    <h2 id="new-ticket-title" class="font-orbitron font-bold text-base">Report a problem</h2>
                    <p class="text-xs text-slate-500 mt-1">Include what you expected, what happened, and the steps that caused it.</p>
                </div>
            </div>

            <form method="POST" action="{{ route('support-tickets.store') }}" class="space-y-5" data-native-navigation>
                @csrf
                <label class="block">
                    <span class="block text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-2">Problem type</span>
                    <select name="category" class="input-mobile-ultra !pl-3" required>
                        <option value="">Choose a category</option>
                        @foreach($categoryOptions as $value => $label)
                            <option value="{{ $value }}" @selected(old('category') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="block text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-2">Short summary</span>
                    <input type="text" name="subject" value="{{ old('subject') }}" minlength="5" maxlength="160" required
                           autocomplete="off" class="input-mobile-ultra !pl-3"
                           placeholder="Example: My quiz score did not update">
                </label>

                <label class="block">
                    <span class="block text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-2">What happened?</span>
                    <textarea name="description" rows="7" minlength="20" maxlength="5000" required
                              class="input-mobile-ultra !h-auto !pl-3 !py-3 resize-y"
                              placeholder="Tell us what you clicked, what you saw, and what should have happened instead.">{{ old('description') }}</textarea>
                </label>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <label class="block">
                        <span class="block text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-2">Page address <span class="normal-case text-slate-600">(optional)</span></span>
                        <input type="url" name="page_url" value="{{ old('page_url') }}" maxlength="2048"
                               class="input-mobile-ultra !pl-3" placeholder="https://mathmetaverse.space/...">
                    </label>
                    <label class="block">
                        <span class="block text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-2">Error reference <span class="normal-case text-slate-600">(optional)</span></span>
                        <input type="text" name="reference_id" value="{{ old('reference_id', $referencePrefill) }}"
                               maxlength="19" pattern="MV-[0-9A-Fa-f]{16}" class="input-mobile-ultra !pl-3 font-mono uppercase"
                               placeholder="MV-0123456789ABCDEF">
                    </label>
                </div>

                <p class="text-[10px] leading-relaxed text-slate-500">
                    Error references appear on the “Request unavailable” page. Adding one helps the administrator find the matching diagnostic event.
                </p>

                <button type="submit" class="btn-rect-primary !py-3">
                    <i class="fas fa-paper-plane mr-2"></i> Submit support ticket
                </button>
            </form>
        </section>

        <section aria-labelledby="my-tickets-title">
            <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 mb-5">
                <div>
                    <h2 id="my-tickets-title" class="font-orbitron font-bold text-lg">My tickets</h2>
                    <p class="text-xs text-slate-500 mt-1">Only tickets submitted from your account are shown here.</p>
                </div>
                <form method="GET" action="{{ route('support-tickets.index') }}" class="w-full sm:w-auto flex flex-col sm:flex-row gap-2">
                    <label for="support-ticket-status" class="sr-only">Filter tickets by status</label>
                    <select id="support-ticket-status" name="status" class="input-mobile-ultra !pl-3 sm:!w-48">
                        @foreach($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <button class="btn-rect-secondary !w-auto !py-2 !px-4">Filter</button>
                </form>
            </div>

            @if(!$ticketsReady)
                <div role="alert" class="portal-frame !p-5 mb-4 border-red-500/40 text-sm text-red-200">
                    <i class="fas fa-triangle-exclamation mr-2"></i>
                    Support tickets could not be loaded. Your existing reports are still stored; please refresh in a moment.
                </div>
            @endif

            <div class="space-y-4">
                @forelse($tickets as $ticket)
                    @php($ticketStatus = $ticket['status'] ?? 'open')
                    <a href="{{ route('support-tickets.show', ['id' => $ticket['id']]) }}"
                       class="portal-frame !p-5 block hover:border-cyan-400/40 transition-colors group">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center rounded border px-2 py-1 text-[9px] font-black uppercase tracking-wider {{ $statusStyles[$ticketStatus] ?? $statusStyles['open'] }}">
                                        {{ str_replace('_', ' ', $ticketStatus) }}
                                    </span>
                                    <span class="text-[9px] uppercase tracking-widest text-slate-500">{{ $categoryOptions[$ticket['category']] ?? ucfirst($ticket['category'] ?? 'Other') }}</span>
                                </div>
                                <h3 class="font-bold text-white mt-3 break-words group-hover:text-cyan-300">{{ $ticket['subject'] }}</h3>
                                <p class="text-xs text-slate-500 mt-2 line-clamp-2 break-words">{{ $ticket['description'] }}</p>
                            </div>
                            <i class="fas fa-chevron-right text-slate-600 group-hover:text-cyan-300 mt-1" aria-hidden="true"></i>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 mt-4 pt-4 border-t border-white/5 text-[9px] uppercase tracking-wider text-slate-600">
                            <span>Created {{ \App\Support\AppDate::format($ticket['created_at'] ?? null, 'M j, Y g:i A') }}</span>
                            <span>Updated {{ \App\Support\AppDate::format($ticket['updated_at'] ?? null, 'M j, Y g:i A') }}</span>
                            @if(!empty($ticket['reference_id']))
                                <span class="font-mono text-slate-400">{{ $ticket['reference_id'] }}</span>
                            @endif
                        </div>
                    </a>
                @empty
                    @if($ticketsReady)
                        <div class="portal-frame !p-10 text-center">
                            <i class="fas fa-inbox text-4xl text-slate-700 mb-4"></i>
                            <p class="font-bold text-slate-300">No {{ $status === 'all' ? '' : str_replace('_', ' ', $status) }} tickets yet.</p>
                            <p class="text-xs text-slate-600 mt-2">Use the form to report a bug or request help.</p>
                        </div>
                    @endif
                @endforelse
            </div>

            @if($totalPages > 1)
                <nav class="flex items-center justify-between gap-4 mt-6 text-xs" aria-label="Support ticket pages">
                    @if($page > 1)
                        <a href="{{ route('support-tickets.index', ['status' => $status, 'page' => $page - 1]) }}" class="btn-rect-secondary !w-auto !py-2 !px-4">Previous</a>
                    @else
                        <span></span>
                    @endif
                    <span class="text-[10px] uppercase tracking-widest text-slate-500">Page {{ $page }} of {{ $totalPages }} · {{ number_format($total) }} total</span>
                    @if($page < $totalPages)
                        <a href="{{ route('support-tickets.index', ['status' => $status, 'page' => $page + 1]) }}" class="btn-rect-secondary !w-auto !py-2 !px-4">Next</a>
                    @endif
                </nav>
            @endif
        </section>
    </div>
</div>
@endsection

@section('modals')
    @if(($user['role'] ?? '') === 'teacher')
        @include('teacher.partials.logout-modal')
    @else
        @include('student.partials.logout-modal')
    @endif
@endsection
