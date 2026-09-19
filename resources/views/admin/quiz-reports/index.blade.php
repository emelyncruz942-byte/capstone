@extends('layouts.dashboard')

@section('title', 'Quiz Reports')
@section('sidebar-border', 'border-red-500/20')
@section('sidebar-subtitle', 'System Administrator')
@section('sidebar-subtitle-color', 'text-red-500/60')
@section('accent-color', 'text-red-500')
@section('mobile-title', 'Quiz Reports')

@section('sidebar-nav')
    @include('admin.partials.sidebar-nav', ['activePage' => 'quiz-reports'])
@endsection

@section('dashboard-content')
<div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 mb-8">
    <div>
        <p class="text-[9px] text-red-400 uppercase font-black tracking-[0.25em]">Moderation Queue</p>
        <h1 class="text-xl md:text-2xl font-orbitron font-bold uppercase mt-2">
            Quiz <span class="text-red-400">Reports</span>
        </h1>
        <p class="text-xs text-slate-500 mt-2">Handle reported quizzes without mixing moderation controls into the quiz editor.</p>
    </div>
    <a href="/admin/quiz-library" class="btn-rect-secondary !py-3 !px-5 text-center lg:!w-auto">
        <i class="fas fa-book-open mr-2"></i> Quiz Library
    </a>
</div>

<nav class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-8" aria-label="Quiz report status">
    @foreach([
        'active' => ['label' => 'Active', 'icon' => 'fa-flag', 'color' => 'red'],
        'reviewed' => ['label' => 'Reviewed', 'icon' => 'fa-check-circle', 'color' => 'green'],
        'dismissed' => ['label' => 'Dismissed', 'icon' => 'fa-ban', 'color' => 'slate'],
    ] as $tabStatus => $tab)
        <a href="/admin/quiz-reports?status={{ $tabStatus }}"
           class="portal-frame !p-4 flex items-center justify-between gap-4 transition-colors {{ $status === $tabStatus ? 'border-' . $tab['color'] . '-500/60 bg-' . $tab['color'] . '-500/10' : 'hover:border-white/20' }}">
            <span class="flex items-center gap-3 text-xs font-bold uppercase">
                <i class="fas {{ $tab['icon'] }} text-{{ $tab['color'] }}-400"></i>
                {{ $tab['label'] }}
            </span>
            <span class="min-w-8 h-8 px-2 rounded bg-black/30 flex items-center justify-center font-orbitron text-xs text-white">
                {{ $reportCounts[$tabStatus] }}
            </span>
        </a>
    @endforeach
</nav>

<div class="flex items-center justify-between gap-4 mb-5">
    <p class="text-[10px] text-slate-500 uppercase tracking-widest">
        {{ number_format($total) }} {{ Str::plural($status === 'active' ? 'active report' : $status . ' report', $total) }}
    </p>
    @if($status === 'active' && $total > 0)
        <p class="text-[9px] text-red-300 uppercase font-bold">Oldest reports appear first</p>
    @endif
</div>

<div class="space-y-4">
    @forelse($reports as $index => $report)
        <article class="portal-frame !p-5 md:!p-6 {{ $status === 'active' && $index === 0 ? 'border-red-500/50' : '' }}">
            <div class="flex flex-col xl:flex-row xl:items-start justify-between gap-5">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2 text-[9px] uppercase font-black tracking-wider">
                        @if($status === 'active' && $index === 0)
                            <span class="px-2 py-1 rounded bg-red-500/15 text-red-300">Next to process</span>
                        @endif
                        @if(($report['quiz_creator_id_display'] ?? null) === ($user['id'] ?? null))
                            <span class="px-2 py-1 rounded bg-purple-500/15 text-purple-300">Your Admin Quiz</span>
                        @endif
                        <span class="text-red-400">{{ str_replace('_', ' ', $report['reason'] ?? 'other') }}</span>
                        <span class="text-slate-600">Reported by {{ $report['reporter_name'] }}</span>
                    </div>

                    <h2 class="text-lg font-bold text-white mt-3 truncate">{{ $report['quiz_topic_display'] }}</h2>
                    <p class="text-[10px] text-slate-500 mt-1 uppercase">
                        @if($report['quiz_grade_display']) Grade {{ $report['quiz_grade_display'] }} · @endif
                        By {{ $report['quiz_creator_name'] }}
                        @if(!$report['quiz_available']) · <span class="text-red-400">Quiz deleted</span> @endif
                    </p>

                    @if(!empty($report['question_label']))
                        <p class="text-[10px] text-cyan-300 mt-3">{{ $report['question_label'] }}</p>
                    @endif
                    <p class="text-xs text-slate-300 mt-3 leading-relaxed">{{ $report['details'] ?: 'No additional details provided.' }}</p>
                    <p class="text-[9px] text-slate-600 mt-3 uppercase">
                        Submitted {{ \App\Support\AppDate::format($report['created_at'], 'M j, Y · g:i A') }}
                        @if(!empty($report['reviewed_at']))
                            · Handled {{ \App\Support\AppDate::format($report['reviewed_at'], 'M j, Y · g:i A') }}
                            @if($report['reviewer_name']) by {{ $report['reviewer_name'] }} @endif
                        @endif
                    </p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 xl:grid-cols-1 gap-2 w-full xl:w-48 shrink-0">
                    <a href="/admin/quiz-reports/{{ $report['id'] }}"
                       class="btn-rect-primary !bg-red-600 !text-white !py-2 !px-3 text-center">
                        <i class="fas fa-folder-open mr-2"></i> Open Report
                    </a>
                    @if($report['quiz_available'])
                        <a href="/admin/quiz-library/{{ $report['quiz_id'] }}/review?{{ http_build_query(['return_to' => 'reports', 'report_id' => $report['id']]) }}#admin-review-quiz-form"
                           class="btn-rect-secondary !py-2 !px-3 text-center">
                            <i class="fas fa-edit mr-2"></i> {{ ($report['quiz_creator_id_display'] ?? null) === ($user['id'] ?? null) ? 'Edit My Quiz' : 'Edit Quiz' }}
                        </a>
                        <button type="button"
                                data-action="openReportDeleteModal" data-action-args="{{ json_encode([$report['quiz_id'], $report['quiz_topic_display'], $report['id']], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}"
                                class="btn-rect-secondary !py-2 !px-3 text-red-400 !border-red-500/30">
                            <i class="fas fa-trash-alt mr-2"></i> Delete Quiz
                        </button>
                    @endif
                </div>
            </div>
        </article>
    @empty
        <div class="portal-frame !p-12 text-center">
            <i class="fas {{ $status === 'active' ? 'fa-check-circle text-green-400' : 'fa-inbox text-slate-700' }} text-4xl mb-4"></i>
            <p class="text-slate-300 font-bold">No {{ $status }} quiz reports.</p>
            <p class="text-xs text-slate-600 mt-2">{{ $status === 'active' ? 'The moderation queue is clear.' : 'Handled reports will appear here.' }}</p>
        </div>
    @endforelse
</div>

@if($totalPages > 1)
    <nav class="flex items-center justify-center gap-4 mt-10" aria-label="Quiz report pages">
        @if($page > 1)
            <a href="/admin/quiz-reports?{{ http_build_query(['status' => $status, 'page' => $page - 1]) }}"
               class="btn-rect-secondary !py-2 !px-5 !w-auto"><i class="fas fa-chevron-left mr-2"></i> Previous</a>
        @endif
        <span class="text-[10px] text-slate-500 uppercase tracking-widest">Page {{ $page }} of {{ $totalPages }}</span>
        @if($page < $totalPages)
            <a href="/admin/quiz-reports?{{ http_build_query(['status' => $status, 'page' => $page + 1]) }}"
               class="btn-rect-secondary !py-2 !px-5 !w-auto">Next <i class="fas fa-chevron-right ml-2"></i></a>
        @endif
    </nav>
@endif
@endsection

@section('modals')
<div id="reportDeleteQuizModal" class="modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="report-delete-title">
    <div class="portal-frame !p-10 w-full max-w-sm text-center border-red-500/50">
        <i class="fas fa-trash-alt text-4xl text-red-500 mb-4"></i>
        <h3 id="report-delete-title" class="font-orbitron font-bold uppercase">Delete Reported Quiz?</h3>
        <p id="report-delete-topic" class="text-xs text-slate-300 my-4"></p>
        <p class="text-[10px] text-slate-500 mb-8">The report history is preserved, while existing class assignments and results remain available.</p>
        <form id="reportDeleteQuizForm" method="POST">
            @csrf
            @method('DELETE')
            <input type="hidden" name="return_to" value="reports">
            <input type="hidden" id="report-delete-report-id" name="report_id">
            <button class="btn-rect-primary !bg-red-600 !text-white">Delete Quiz and Continue</button>
        </form>
        <button type="button" data-action="closeModal" data-action-args='["reportDeleteQuizModal"]' class="modal-cancel mt-3">Cancel</button>
    </div>
</div>

<div id="logoutModal" class="modal-overlay hidden">
    <div class="portal-frame !p-10 w-full max-w-xs text-center border-red-500/30">
        <i class="fas fa-power-off text-4xl text-red-500 mb-4"></i>
        <h3 class="font-orbitron font-bold mb-6 uppercase">End Admin Session?</h3>
        <form method="POST" action="/logout">@csrf<button class="btn-rect-primary !bg-red-600 !text-white">Confirm Logout</button></form>
        <button type="button" data-action="closeModal" data-action-args='["logoutModal"]' class="modal-cancel mt-3">Cancel</button>
    </div>
</div>
@endsection
