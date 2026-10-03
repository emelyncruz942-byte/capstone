@extends('layouts.dashboard')

@section('title', 'Support Ticket')
@section('sidebar-subtitle', ($user['role'] ?? '') === 'teacher' ? 'Teacher Portal' : 'Student Game Hub')
@section('mobile-title', 'Support Ticket')

@section('sidebar-nav')
    @if(($user['role'] ?? '') === 'teacher')
        @include('teacher.partials.sidebar-nav', ['activePage' => 'support'])
    @else
        @include('student.partials.sidebar-nav', ['activePage' => 'support'])
    @endif
@endsection

@section('dashboard-content')
@php
    $statusStyles = [
        'open' => 'border-cyan-400/30 bg-cyan-400/10 text-cyan-300',
        'in_progress' => 'border-amber-400/30 bg-amber-400/10 text-amber-300',
        'resolved' => 'border-green-400/30 bg-green-400/10 text-green-300',
        'closed' => 'border-slate-400/30 bg-slate-400/10 text-slate-300',
    ];
    $priorityStyles = [
        'low' => 'text-slate-400',
        'normal' => 'text-cyan-300',
        'high' => 'text-orange-300',
        'urgent' => 'text-red-300',
    ];
    $ticketStatus = $ticket['status'] ?? 'open';
    $ticketPriority = $ticket['priority'] ?? 'normal';
@endphp

<div class="max-w-5xl mx-auto" data-testid="requester-support-ticket-detail">
    <a href="{{ route('support-tickets.index') }}" class="inline-flex items-center text-xs font-bold uppercase text-slate-400 hover:text-white mb-6">
        <i class="fas fa-arrow-left mr-2"></i> Back to support
    </a>

    <article class="portal-frame !p-5 md:!p-8">
        <header class="flex flex-col md:flex-row md:items-start justify-between gap-5 pb-6 border-b border-white/10">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex rounded border px-2 py-1 text-[9px] font-black uppercase tracking-wider {{ $statusStyles[$ticketStatus] ?? $statusStyles['open'] }}">
                        {{ str_replace('_', ' ', $ticketStatus) }}
                    </span>
                    <span class="text-[9px] uppercase tracking-widest {{ $priorityStyles[$ticketPriority] ?? $priorityStyles['normal'] }}">{{ $ticketPriority }} priority</span>
                    <span class="text-[9px] uppercase tracking-widest text-slate-600">{{ str_replace('_', ' ', $ticket['category'] ?? 'other') }}</span>
                </div>
                <h1 class="font-orbitron font-bold text-xl md:text-2xl mt-4 break-words">{{ $ticket['subject'] }}</h1>
                <p class="text-[10px] text-slate-500 uppercase tracking-wider mt-3">Opened {{ \App\Support\AppDate::format($ticket['created_at'] ?? null, 'M j, Y g:i A') }}</p>
            </div>
            @if(!empty($ticket['reference_id']))
                <div class="rounded-lg border border-white/10 bg-black/30 px-4 py-3 shrink-0">
                    <span class="block text-[8px] uppercase tracking-widest text-slate-600">Error reference</span>
                    <code class="text-xs text-cyan-300 mt-1 block">{{ $ticket['reference_id'] }}</code>
                </div>
            @endif
        </header>

        <section class="py-7" aria-labelledby="ticket-description-title">
            <h2 id="ticket-description-title" class="text-[10px] uppercase tracking-widest font-bold text-slate-500 mb-3">Your report</h2>
            <p class="text-sm leading-7 text-slate-200 whitespace-pre-wrap break-words">{{ $ticket['description'] }}</p>
            @if(!empty($ticket['page_url']))
                <div class="mt-5 rounded-lg border border-white/5 bg-black/20 p-4">
                    <span class="block text-[8px] uppercase tracking-widest text-slate-600 mb-1">Reported page</span>
                    <span class="text-xs text-slate-400 break-all">{{ $ticket['page_url'] }}</span>
                </div>
            @endif
        </section>

        <section class="rounded-xl border {{ !empty($ticket['admin_response']) ? 'border-cyan-400/25 bg-cyan-400/5' : 'border-white/10 bg-white/[0.02]' }} p-5 md:p-6" aria-labelledby="admin-response-title">
            <div class="flex items-center gap-3 mb-3">
                <span class="w-9 h-9 rounded-full bg-cyan-400/10 text-cyan-300 flex items-center justify-center"><i class="fas fa-headset"></i></span>
                <div>
                    <h2 id="admin-response-title" class="font-bold text-sm">Administrator response</h2>
                    <p class="text-[9px] uppercase tracking-widest text-slate-600">Updated {{ \App\Support\AppDate::format($ticket['updated_at'] ?? null, 'M j, Y g:i A') }}</p>
                </div>
            </div>
            @if(!empty($ticket['admin_response']))
                <p class="text-sm leading-7 text-slate-200 whitespace-pre-wrap break-words">{{ $ticket['admin_response'] }}</p>
            @else
                <p class="text-sm text-slate-500">An administrator has not responded yet. You can return to this page later to check for updates.</p>
            @endif
        </section>

        @if(in_array($ticketStatus, ['resolved', 'closed'], true))
            <p class="text-xs text-green-300 mt-5"><i class="fas fa-circle-check mr-2"></i>This ticket was {{ $ticketStatus }}{{ !empty($ticket['resolved_at']) ? ' on '.\App\Support\AppDate::format($ticket['resolved_at'], 'M j, Y g:i A') : '' }}.</p>
        @endif
    </article>
</div>
@endsection

@section('modals')
    @if(($user['role'] ?? '') === 'teacher')
        @include('teacher.partials.logout-modal')
    @else
        @include('student.partials.logout-modal')
    @endif
@endsection
