@extends('layouts.dashboard')

@section('title', 'Reported Quiz Details')
@section('sidebar-subtitle', 'Instructional Hub')
@section('mobile-title', 'Report Details')

@section('sidebar-nav')
    @include('teacher.partials.sidebar-nav', ['activePage' => 'quiz-reports'])
@endsection

@section('dashboard-content')
@php
    $reportStatus = $report['status'] ?? 'pending';
    $reportIsPending = $reportStatus === 'pending';
    $reportIsRevised = !empty($report['revised_at']);
@endphp

<div class="max-w-7xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <a href="/teacher/quiz-reports?status={{ $reportIsPending ? 'active' : $reportStatus }}"
           class="text-xs text-slate-400 hover:text-white font-bold uppercase">
            <i class="fas fa-arrow-left mr-2"></i> Back to Reported Quizzes
        </a>
        <span class="text-[9px] text-slate-600 uppercase sm:text-right">
            Reported {{ \App\Support\AppDate::format($report['created_at'], 'M j, Y · g:i A') }}
        </span>
    </div>

    <header class="portal-frame !p-6 md:!p-8 mb-7 border-l-4 {{ $reportIsPending ? 'border-orange-500' : ($reportStatus === 'reviewed' ? 'border-green-500' : 'border-slate-500') }}">
        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center gap-6">
            <div class="min-w-0">
                <p class="text-[9px] text-orange-400 uppercase font-black tracking-[0.25em]">Quiz report</p>
                <h1 class="text-xl md:text-2xl font-orbitron font-bold mt-3 break-words">{{ $report['quiz_topic_display'] }}</h1>
                <p class="text-[10px] text-slate-500 mt-2 uppercase">
                    @if($report['quiz_grade_display']) Grade {{ $report['quiz_grade_display'] }} @endif
                    @if(!$report['quiz_available']) · <span class="text-red-400">Quiz is no longer available</span> @endif
                </p>
            </div>
            <div class="flex flex-wrap lg:justify-end items-center gap-2 text-[9px] uppercase font-black tracking-widest lg:max-w-md">
                <span class="px-2 py-1 rounded {{ $reportIsPending ? 'bg-orange-500/15 text-orange-300' : ($reportStatus === 'reviewed' ? 'bg-green-500/15 text-green-300' : 'bg-white/5 text-slate-400') }}">
                    {{ $reportIsPending ? 'Awaiting admin review' : ucfirst($reportStatus) }}
                </span>
                @if($reportIsRevised)
                    <span class="px-2 py-1 rounded bg-cyan-500/15 text-cyan-300">
                        <i class="fas fa-pen-to-square mr-1"></i> Marked revised
                    </span>
                @else
                    <span class="px-2 py-1 rounded bg-red-500/10 text-red-300">Not marked revised</span>
                @endif
                <span class="px-2 py-1 text-red-400">{{ str_replace('_', ' ', $report['reason'] ?? 'other') }}</span>
            </div>
        </div>
    </header>

    <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_21rem] gap-7 items-start">
        <div class="min-w-0 space-y-6">
            <section class="portal-frame !p-6 md:!p-8">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded border border-red-500/20 bg-red-500/10 flex items-center justify-center shrink-0">
                        <i class="fas fa-triangle-exclamation text-red-400"></i>
                    </div>
                    <div>
                        <p class="text-[9px] text-slate-500 uppercase font-black tracking-widest">Submitted report</p>
                        <h2 class="font-orbitron font-bold uppercase mt-1">Reported <span class="text-red-400">Issue</span></h2>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-6">
                    <div class="rounded border border-cyan-500/20 bg-cyan-500/5 p-5 min-w-0">
                        <p class="text-[9px] text-cyan-400 uppercase font-black tracking-widest">Affected Question</p>
                        @if(!empty($report['question_text_display']))
                            <p class="text-sm text-white mt-3 leading-relaxed break-words">{{ $report['question_text_display'] }}</p>
                        @else
                            <p class="text-sm text-slate-300 mt-3 leading-relaxed">Whole quiz / not question-specific</p>
                        @endif
                    </div>
                    <div class="rounded border border-white/10 bg-black/30 p-5 min-w-0">
                        <p class="text-[9px] text-slate-500 uppercase font-black tracking-widest">Report Details</p>
                        <p class="text-sm text-slate-200 mt-3 leading-relaxed break-words">{{ $report['details'] ?: 'No additional details were provided.' }}</p>
                    </div>
                </div>
            </section>

            @if($reportIsRevised)
                <section class="portal-frame !p-6 border-l-4 border-cyan-500">
                    <div class="flex items-start gap-4">
                        <i class="fas fa-circle-check text-cyan-400 mt-1"></i>
                        <div>
                            <p class="text-[9px] text-cyan-400 uppercase font-black tracking-widest">Teacher Revision</p>
                            <p class="text-sm text-white mt-2">You marked this quiz as revised.</p>
                            <p class="text-[9px] text-slate-600 mt-2 uppercase">{{ \App\Support\AppDate::format($report['revised_at'], 'M j, Y · g:i A') }}</p>
                            @if($reportIsPending)
                                <p class="text-xs text-orange-300 mt-3">The report is still open and must be reviewed by an administrator.</p>
                            @endif
                        </div>
                    </div>
                </section>
            @endif

            @if(!empty($report['reviewed_at']))
                <section class="portal-frame !p-6 border-l-4 {{ $reportStatus === 'reviewed' ? 'border-green-500' : 'border-slate-500' }}">
                    <div class="flex items-start gap-4">
                        <i class="fas fa-shield-halved text-slate-400 mt-1"></i>
                        <div>
                            <p class="text-[9px] text-slate-500 uppercase font-black tracking-widest">Administrator Outcome</p>
                            <p class="text-sm text-white mt-2">Marked {{ $reportStatus }} by {{ $report['reviewer_name'] ?: 'an administrator' }}.</p>
                            <p class="text-[9px] text-slate-600 mt-2 uppercase">{{ \App\Support\AppDate::format($report['reviewed_at'], 'M j, Y · g:i A') }}</p>
                        </div>
                    </div>
                </section>
            @endif
        </div>

        <aside class="space-y-4 xl:sticky xl:top-8" aria-label="Quiz report actions">
            @if($reportIsPending)
                <section class="portal-frame !p-5 {{ !$reportIsRevised ? 'border-cyan-500/30' : 'border-orange-500/20' }}">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-[10px] text-cyan-300 uppercase font-black tracking-widest">Revision Status</h2>
                        <i class="fas {{ $reportIsRevised ? 'fa-circle-check text-cyan-400' : 'fa-clock text-orange-400' }}"></i>
                    </div>
                    @if($reportIsRevised)
                        <p class="text-xs text-slate-300 mt-4 leading-relaxed">Revision confirmed. This report remains in the administrator's active review queue.</p>
                    @elseif(!$report['quiz_available'])
                        <p class="text-xs text-slate-400 mt-4 leading-relaxed">Restore the quiz from Trash before marking it revised. The report remains open for administrator review.</p>
                    @else
                        <p class="text-xs text-slate-400 my-4 leading-relaxed">After correcting the quiz, mark it revised. This does not close or remove the report; an administrator must still review it.</p>
                        <form method="POST" action="/teacher/quiz-reports/{{ $report['id'] }}/revised">
                            @csrf
                            <button class="btn-rect-primary !bg-cyan-600 !text-white !py-3">
                                <i class="fas fa-check mr-2"></i> Mark as Revised
                            </button>
                        </form>
                    @endif
                </section>
            @endif

            <section class="portal-frame !p-5">
                <h2 class="text-[10px] text-slate-400 uppercase font-black tracking-widest mb-4">Quiz Actions</h2>
                <div class="flex flex-col gap-3">
                    @if($report['quiz_available'])
                        <a href="/teacher/quizzes?{{ http_build_query(['search' => $report['quiz_topic_display']]) }}"
                           class="btn-rect-primary !bg-orange-600 !text-white !py-3 !px-3 text-center">
                            <i class="fas fa-edit mr-2"></i> Find Quiz to Edit
                        </a>
                        <a href="/teacher/quizzes/{{ $report['quiz_id'] }}/versions"
                           class="btn-rect-secondary !py-3 !px-3 text-center">
                            <i class="fas fa-history mr-2"></i> Version History
                        </a>
                    @else
                        <a href="/teacher/trash" class="btn-rect-secondary !py-3 !px-3 text-center">
                            <i class="fas fa-trash-restore mr-2"></i> Check Trash
                        </a>
                    @endif
                </div>
            </section>

            <section class="portal-frame !p-5">
                <h2 class="text-[10px] text-slate-500 uppercase font-black tracking-widest mb-4">Report Record</h2>
                <dl class="space-y-3 text-xs">
                    <div class="flex items-start justify-between gap-4 border-b border-white/5 pb-3">
                        <dt class="text-slate-500">Submitted</dt>
                        <dd class="text-slate-300 text-right">{{ \App\Support\AppDate::format($report['created_at'], 'M j, Y') }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-4 border-b border-white/5 pb-3">
                        <dt class="text-slate-500">Teacher revision</dt>
                        <dd class="{{ $reportIsRevised ? 'text-cyan-300' : 'text-orange-300' }} text-right">{{ $reportIsRevised ? 'Confirmed' : 'Not confirmed' }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-4">
                        <dt class="text-slate-500">Admin review</dt>
                        <dd class="{{ $reportIsPending ? 'text-orange-300' : 'text-green-300' }} text-right">{{ $reportIsPending ? 'Pending' : ucfirst($reportStatus) }}</dd>
                    </div>
                </dl>
            </section>
        </aside>
    </div>
</div>
@endsection

@section('modals')
<div id="logoutModal" class="modal-overlay hidden">
    <div class="portal-frame !p-10 w-full max-w-xs text-center border-red-500/30">
        <i class="fas fa-sign-out-alt text-4xl text-red-500 mb-4"></i>
        <h3 class="font-orbitron font-bold mb-6 uppercase">End Session?</h3>
        <form method="POST" action="/logout">@csrf<button class="btn-rect-primary !bg-red-600 !text-white">Confirm Logout</button></form>
        <button type="button" data-action="closeModal" data-action-args='["logoutModal"]' class="modal-cancel mt-3">Cancel</button>
    </div>
</div>
@endsection
