@extends('layouts.dashboard')

@section('title', 'Quiz Report Review')
@section('sidebar-border', 'border-red-500/20')
@section('sidebar-subtitle', 'System Administrator')
@section('sidebar-subtitle-color', 'text-red-500/60')
@section('accent-color', 'text-red-500')
@section('mobile-title', 'Report Review')

@section('sidebar-nav')
    @include('admin.partials.sidebar-nav', ['activePage' => 'quiz-reports'])
@endsection

@section('dashboard-content')
<div class="flex flex-wrap items-center justify-between gap-4 mb-7">
    <a href="/admin/quiz-reports?status={{ ($report['status'] ?? 'pending') === 'pending' ? 'active' : $report['status'] }}"
       class="text-xs text-slate-400 hover:text-white font-bold uppercase">
        <i class="fas fa-arrow-left mr-2"></i> Back to Quiz Reports
    </a>
    @if($nextReport)
        <a href="/admin/quiz-reports/{{ $nextReport['id'] }}" class="text-[10px] text-red-300 uppercase font-bold">
            Next Active Report <i class="fas fa-chevron-right ml-2"></i>
        </a>
    @endif
</div>

<header class="portal-frame !p-6 md:!p-8 mb-7 border-l-4 {{ ($report['status'] ?? 'pending') === 'pending' ? 'border-red-500' : (($report['status'] ?? '') === 'reviewed' ? 'border-green-500' : 'border-slate-500') }}">
    <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-5">
        <div>
            <div class="flex flex-wrap items-center gap-2 text-[9px] uppercase font-black tracking-widest">
                <span class="px-2 py-1 rounded {{ ($report['status'] ?? 'pending') === 'pending' ? 'bg-red-500/15 text-red-300' : (($report['status'] ?? '') === 'reviewed' ? 'bg-green-500/15 text-green-300' : 'bg-white/5 text-slate-400') }}">
                    {{ ($report['status'] ?? 'pending') === 'pending' ? 'Active' : ucfirst($report['status']) }}
                </span>
                @if(($report['quiz_creator_id_display'] ?? null) === ($user['id'] ?? null))
                    <span class="px-2 py-1 rounded bg-purple-500/15 text-purple-300">Your Admin Quiz</span>
                @endif
                <span class="text-red-400">{{ str_replace('_', ' ', $report['reason'] ?? 'other') }}</span>
            </div>
            <h1 class="text-2xl font-orbitron font-bold mt-3">{{ $report['quiz_topic_display'] }}</h1>
            <p class="text-[10px] text-slate-500 mt-2 uppercase">
                @if($report['quiz_grade_display']) Grade {{ $report['quiz_grade_display'] }} · @endif
                Quiz by {{ $report['quiz_creator_name'] }} · Reported by {{ $report['reporter_name'] }}
            </p>
        </div>
        <p class="text-[9px] text-slate-600 uppercase shrink-0">
            {{ \App\Support\AppDate::format($report['created_at'], 'M j, Y · g:i A') }}
        </p>
    </div>
</header>

<div class="grid grid-cols-1 xl:grid-cols-[1fr_320px] gap-7">
    <div class="space-y-7">
        <section class="portal-frame !p-6 md:!p-8">
            <h2 class="font-orbitron font-bold uppercase">Reported <span class="text-red-400">Issue</span></h2>
            @if(!empty($report['question_text_display']))
                <div class="rounded border border-cyan-500/20 bg-cyan-500/5 p-4 mt-5">
                    <p class="text-[9px] text-cyan-400 uppercase font-black tracking-widest">Affected Question</p>
                    <p class="text-sm text-white mt-2 leading-relaxed">{{ $report['question_text_display'] }}</p>
                </div>
            @else
                <p class="text-[10px] text-cyan-400 uppercase font-bold mt-5">Whole quiz / not question-specific</p>
            @endif
            <div class="rounded border border-white/10 bg-black/30 p-5 mt-4">
                <p class="text-[9px] text-slate-500 uppercase font-black tracking-widest">Reporter Details</p>
                <p class="text-sm text-slate-200 mt-3 leading-relaxed">{{ $report['details'] ?: 'No additional details provided.' }}</p>
            </div>
        </section>

        @if(!empty($report['reviewed_at']))
            <section class="portal-frame !p-6 border-white/10">
                <p class="text-[9px] text-slate-500 uppercase font-black tracking-widest">Moderation Outcome</p>
                <p class="text-sm text-white mt-2">Marked {{ $report['status'] }} by {{ $report['reviewer_name'] ?: 'an administrator' }}.</p>
                <p class="text-[9px] text-slate-600 mt-2 uppercase">{{ \App\Support\AppDate::format($report['reviewed_at'], 'M j, Y · g:i A') }}</p>
            </section>
        @endif
    </div>

    <aside class="space-y-4">
        <section class="portal-frame !p-5">
            <h2 class="text-[10px] text-slate-400 uppercase font-black tracking-widest mb-4">Quiz Actions</h2>
            @if($report['quiz_available'])
                <div class="flex flex-col gap-4">
                    <a href="/admin/quiz-library/{{ $report['quiz_id'] }}/review?{{ http_build_query(['return_to' => 'reports', 'report_id' => $report['id']]) }}#admin-review-quiz-form"
                       class="btn-rect-primary !bg-red-600 !text-white !py-3 !w-full block text-center">
                        <i class="fas fa-edit mr-2"></i> {{ ($report['quiz_creator_id_display'] ?? null) === ($user['id'] ?? null) ? 'Review and Edit My Quiz' : 'Review and Edit Quiz' }}
                    </a>
                    <a href="/admin/quizzes/{{ $report['quiz_id'] }}/versions"
                       class="btn-rect-secondary !py-3 !w-full block text-center">
                        <i class="fas fa-history mr-2"></i> Version History
                    </a>
                    <button type="button" data-action="openModal" data-action-args='["reportDeleteQuizModal"]'
                            class="btn-rect-secondary !py-3 !w-full text-red-400 !border-red-500/30">
                        <i class="fas fa-trash-alt mr-2"></i> Delete Quiz
                    </button>
                </div>
            @else
                <div class="rounded border border-red-500/20 bg-red-500/5 p-4 text-center">
                    <i class="fas fa-trash-alt text-red-400 mb-2"></i>
                    <p class="text-xs text-slate-400">This quiz has already been deleted.</p>
                </div>
            @endif
        </section>

        @if(($report['status'] ?? 'pending') === 'pending')
            <section class="portal-frame !p-5 border-red-500/20">
                <h2 class="text-[10px] text-red-300 uppercase font-black tracking-widest mb-2">Resolve Report</h2>
                <p class="text-xs text-slate-500 mb-4">After either action, MathVerse opens the next active report automatically.</p>
                <div class="space-y-3">
                    <button type="button" data-action="openModal" data-action-args='["markReportReviewedModal"]'
                            class="btn-rect-primary !bg-green-600 !text-white !py-3">
                        <i class="fas fa-check-circle mr-2"></i> Mark Reviewed
                    </button>
                    <button type="button" data-action="openModal" data-action-args='["dismissReportModal"]'
                            class="btn-rect-secondary !py-3 text-slate-300">
                        <i class="fas fa-ban mr-2"></i> Dismiss Report
                    </button>
                </div>
            </section>
        @endif
    </aside>
</div>
@endsection

@section('modals')
@if(($report['status'] ?? 'pending') === 'pending')
    <div id="markReportReviewedModal" class="modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="mark-reviewed-title">
        <div class="portal-frame !p-9 w-full max-w-sm text-center border-green-500/40">
            <i class="fas fa-check-circle text-4xl text-green-400 mb-4"></i>
            <h3 id="mark-reviewed-title" class="font-orbitron font-bold uppercase">Mark Report Reviewed?</h3>
            <p class="text-xs text-slate-400 my-5">Use this after checking the report or correcting the quiz. The next active report will open.</p>
            <form method="POST" action="/admin/quiz-reports/{{ $report['id'] }}/resolve">
                @csrf
                <input type="hidden" name="status" value="reviewed">
                <button class="btn-rect-primary !bg-green-600 !text-white">Mark Reviewed and Continue</button>
            </form>
            <button type="button" data-action="closeModal" data-action-args='["markReportReviewedModal"]' class="modal-cancel mt-3">Cancel</button>
        </div>
    </div>

    <div id="dismissReportModal" class="modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="dismiss-report-title">
        <div class="portal-frame !p-9 w-full max-w-sm text-center border-slate-500/40">
            <i class="fas fa-ban text-4xl text-slate-400 mb-4"></i>
            <h3 id="dismiss-report-title" class="font-orbitron font-bold uppercase">Dismiss This Report?</h3>
            <p class="text-xs text-slate-400 my-5">The report is retained under Dismissed and the quiz is not changed.</p>
            <form method="POST" action="/admin/quiz-reports/{{ $report['id'] }}/resolve">
                @csrf
                <input type="hidden" name="status" value="dismissed">
                <button class="btn-rect-primary !bg-slate-600 !text-white">Dismiss and Continue</button>
            </form>
            <button type="button" data-action="closeModal" data-action-args='["dismissReportModal"]' class="modal-cancel mt-3">Cancel</button>
        </div>
    </div>
@endif

@if($report['quiz_available'])
    <div id="reportDeleteQuizModal" class="modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="report-delete-title">
        <div class="portal-frame !p-10 w-full max-w-sm text-center border-red-500/50">
            <i class="fas fa-trash-alt text-4xl text-red-500 mb-4"></i>
            <h3 id="report-delete-title" class="font-orbitron font-bold uppercase">Move Reported Quiz to Trash?</h3>
            <p class="text-xs text-slate-300 my-4">“{{ $report['quiz_topic_display'] }}” will be removed from the shared library.</p>
            <p class="text-[10px] text-slate-500 mb-8">Report history, questions, versions and class results are preserved. Only an administrator can restore this removal.</p>
            <form method="POST" action="/admin/quizzes/{{ $report['quiz_id'] }}">
                @csrf
                @method('DELETE')
                <input type="hidden" name="return_to" value="reports">
                <input type="hidden" name="report_id" value="{{ $report['id'] }}">
                <button class="btn-rect-primary !bg-red-600 !text-white">Delete Quiz and Continue</button>
            </form>
            <button type="button" data-action="closeModal" data-action-args='["reportDeleteQuizModal"]' class="modal-cancel mt-3">Cancel</button>
        </div>
    </div>
@endif

<div id="logoutModal" class="modal-overlay hidden">
    <div class="portal-frame !p-10 w-full max-w-xs text-center border-red-500/30">
        <i class="fas fa-power-off text-4xl text-red-500 mb-4"></i>
        <h3 class="font-orbitron font-bold mb-6 uppercase">End Admin Session?</h3>
        <form method="POST" action="/logout">@csrf<button class="btn-rect-primary !bg-red-600 !text-white">Confirm Logout</button></form>
        <button type="button" data-action="closeModal" data-action-args='["logoutModal"]' class="modal-cancel mt-3">Cancel</button>
    </div>
</div>
@endsection
