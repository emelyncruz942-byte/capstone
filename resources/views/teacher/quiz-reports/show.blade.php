@extends('layouts.dashboard')

@section('title', 'Reported Quiz Details')
@section('sidebar-subtitle', 'Instructional Hub')
@section('mobile-title', 'Report Details')

@section('sidebar-nav')
    @include('teacher.partials.sidebar-nav', ['activePage' => 'quiz-reports'])
@endsection

@section('dashboard-content')
<div class="flex flex-wrap items-center justify-between gap-4 mb-7">
    <a href="/teacher/quiz-reports?status={{ ($report['status'] ?? 'pending') === 'pending' ? 'active' : $report['status'] }}"
       class="text-xs text-slate-400 hover:text-white font-bold uppercase">
        <i class="fas fa-arrow-left mr-2"></i> Back to Reported Quizzes
    </a>
    <span class="text-[9px] text-slate-600 uppercase">
        Reported {{ \App\Support\AppDate::format($report['created_at'], 'M j, Y · g:i A') }}
    </span>
</div>

<header class="portal-frame !p-6 md:!p-8 mb-7 border-l-4 {{ ($report['status'] ?? 'pending') === 'pending' ? 'border-orange-500' : (($report['status'] ?? '') === 'reviewed' ? 'border-green-500' : 'border-slate-500') }}">
    <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-5">
        <div>
            <div class="flex flex-wrap items-center gap-2 text-[9px] uppercase font-black tracking-widest">
                <span class="px-2 py-1 rounded {{ ($report['status'] ?? 'pending') === 'pending' ? 'bg-orange-500/15 text-orange-300' : (($report['status'] ?? '') === 'reviewed' ? 'bg-green-500/15 text-green-300' : 'bg-white/5 text-slate-400') }}">
                    {{ ($report['status'] ?? 'pending') === 'pending' ? 'Awaiting admin review' : ucfirst($report['status']) }}
                </span>
                @if(!empty($report['revised_at']))
                    <span class="px-2 py-1 rounded bg-cyan-500/15 text-cyan-300">
                        <i class="fas fa-pen-to-square mr-1"></i> Marked revised
                    </span>
                @else
                    <span class="px-2 py-1 rounded bg-red-500/10 text-red-300">Not marked revised</span>
                @endif
                <span class="text-red-400">{{ str_replace('_', ' ', $report['reason'] ?? 'other') }}</span>
            </div>
            <h1 class="text-2xl font-orbitron font-bold mt-3">{{ $report['quiz_topic_display'] }}</h1>
            <p class="text-[10px] text-slate-500 mt-2 uppercase">
                @if($report['quiz_grade_display']) Grade {{ $report['quiz_grade_display'] }} @endif
                @if(!$report['quiz_available']) · Quiz is no longer available @endif
            </p>
        </div>
    </div>
</header>

<div class="grid grid-cols-1 xl:grid-cols-[1fr_340px] gap-7">
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
                <p class="text-[9px] text-slate-500 uppercase font-black tracking-widest">Report Details</p>
                <p class="text-sm text-slate-200 mt-3 leading-relaxed">{{ $report['details'] ?: 'No additional details were provided.' }}</p>
            </div>
        </section>

        @if(!empty($report['revised_at']))
            <section class="portal-frame !p-6 border-cyan-500/20">
                <p class="text-[9px] text-cyan-400 uppercase font-black tracking-widest">Teacher Revision</p>
                <p class="text-sm text-white mt-2">You marked this quiz as revised.</p>
                <p class="text-[9px] text-slate-600 mt-2 uppercase">{{ \App\Support\AppDate::format($report['revised_at'], 'M j, Y · g:i A') }}</p>
                @if(($report['status'] ?? 'pending') === 'pending')
                    <p class="text-xs text-orange-300 mt-3">The report is still open and must be reviewed by an administrator.</p>
                @endif
            </section>
        @endif

        @if(!empty($report['reviewed_at']))
            <section class="portal-frame !p-6 border-white/10">
                <p class="text-[9px] text-slate-500 uppercase font-black tracking-widest">Administrator Outcome</p>
                <p class="text-sm text-white mt-2">Marked {{ $report['status'] }} by {{ $report['reviewer_name'] ?: 'an administrator' }}.</p>
                <p class="text-[9px] text-slate-600 mt-2 uppercase">{{ \App\Support\AppDate::format($report['reviewed_at'], 'M j, Y · g:i A') }}</p>
            </section>
        @endif
    </div>

    <aside class="space-y-4">
        <section class="portal-frame !p-5">
            <h2 class="text-[10px] text-slate-400 uppercase font-black tracking-widest mb-4">Quiz Actions</h2>
            <div class="flex flex-col gap-3">
                @if($report['quiz_available'])
                    <a href="/teacher/quizzes?{{ http_build_query(['search' => $report['quiz_topic_display']]) }}"
                       class="btn-rect-secondary !py-2 !px-3 text-center">
                        <i class="fas fa-edit mr-2"></i> Find Quiz to Edit
                    </a>
                    <a href="/teacher/quizzes/{{ $report['quiz_id'] }}/versions"
                       class="btn-rect-secondary !py-2 !px-3 text-center">
                        <i class="fas fa-history mr-2"></i> Version History
                    </a>
                @else
                    <a href="/teacher/trash" class="btn-rect-secondary !py-2 !px-3 text-center">
                        <i class="fas fa-trash-restore mr-2"></i> Check Trash
                    </a>
                @endif
            </div>
        </section>

        @if(($report['status'] ?? 'pending') === 'pending')
            <section class="portal-frame !p-5 {{ empty($report['revised_at']) ? 'border-cyan-500/30' : 'border-orange-500/20' }}">
                <h2 class="text-[10px] text-cyan-300 uppercase font-black tracking-widest">Revision Status</h2>
                @if(!empty($report['revised_at']))
                    <p class="text-xs text-slate-300 mt-4 leading-relaxed">Revision confirmed. This report remains in the administrator's active review queue.</p>
                @elseif(!$report['quiz_available'])
                    <p class="text-xs text-slate-400 mt-4 leading-relaxed">Restore the quiz from Trash before marking it revised. The report remains open for administrator review.</p>
                @else
                    <p class="text-xs text-slate-400 my-4 leading-relaxed">After correcting the quiz, mark it revised. This does not close or remove the report; an administrator must still review it.</p>
                    <form method="POST" action="/teacher/quiz-reports/{{ $report['id'] }}/revised">
                        @csrf
                        <button class="btn-rect-primary !bg-cyan-600 !text-white !py-2">
                            <i class="fas fa-check mr-2"></i> Mark as Revised
                        </button>
                    </form>
                @endif
            </section>
        @endif
    </aside>
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
