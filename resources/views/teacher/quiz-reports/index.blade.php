@extends('layouts.dashboard')

@section('title', 'Reported Quizzes')
@section('sidebar-subtitle', 'Instructional Hub')
@section('mobile-title', 'Reported Quizzes')

@section('sidebar-nav')
    @include('teacher.partials.sidebar-nav', ['activePage' => 'quiz-reports'])
@endsection

@section('dashboard-content')
<div class="max-w-7xl mx-auto">
    <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-5 mb-7">
        <div class="max-w-2xl">
            <p class="text-[9px] text-orange-400 uppercase font-black tracking-[0.25em]">Content Review</p>
            <h1 class="text-xl md:text-2xl font-orbitron font-bold uppercase mt-2">
                Reported <span class="text-orange-400">Quizzes</span>
            </h1>
            <p class="text-xs text-slate-500 mt-2 leading-relaxed">Review issues raised about quizzes you created, make corrections, and keep the administrator informed.</p>
        </div>
        <a href="/teacher/quizzes" class="btn-rect-secondary !py-3 !px-5 text-center lg:!w-auto lg:shrink-0">
            <i class="fas fa-vr-cardboard mr-2"></i> My Quizzes
        </a>
    </div>

    <nav class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-7" aria-label="Reported quiz status">
        @foreach([
            'active' => ['label' => 'Awaiting Admin', 'icon' => 'fa-flag', 'active' => 'border-orange-500/60 bg-orange-500/10'],
            'reviewed' => ['label' => 'Reviewed', 'icon' => 'fa-check-circle', 'active' => 'border-green-500/60 bg-green-500/10'],
            'dismissed' => ['label' => 'Dismissed', 'icon' => 'fa-ban', 'active' => 'border-slate-500/60 bg-white/5'],
        ] as $tabStatus => $tab)
            <a href="/teacher/quiz-reports?status={{ $tabStatus }}"
               @if($status === $tabStatus) aria-current="page" @endif
               class="portal-frame !p-4 flex items-center justify-between gap-4 transition-colors {{ $status === $tabStatus ? $tab['active'] : 'hover:border-white/20 hover:bg-white/[0.03]' }}">
                <span class="flex min-w-0 items-center gap-3 text-[10px] md:text-xs font-bold uppercase">
                    <i class="fas {{ $tab['icon'] }} shrink-0 {{ $tabStatus === 'active' ? 'text-orange-400' : ($tabStatus === 'reviewed' ? 'text-green-400' : 'text-slate-400') }}"></i>
                    <span class="truncate">{{ $tab['label'] }}</span>
                </span>
                <span class="min-w-8 h-8 px-2 rounded bg-black/30 flex items-center justify-center font-orbitron text-xs text-white">
                    {{ $reportCounts[$tabStatus] }}
                </span>
            </a>
        @endforeach
    </nav>

    <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_18rem] gap-7 items-start">
        <section class="min-w-0" aria-label="{{ ucfirst($status) }} quiz reports">
            <div class="flex items-center justify-between gap-4 mb-4">
                <p class="text-[10px] text-slate-500 uppercase tracking-widest">
                    {{ number_format($total) }} {{ Str::plural('report', $total) }}
                </p>
                @if($status === 'active' && $total > 0)
                    <p class="text-[9px] text-orange-300 uppercase font-bold text-right">Oldest reports first</p>
                @endif
            </div>

            <div class="space-y-4">
                @forelse($reports as $report)
                    <article class="portal-frame !p-5 md:!p-6 group hover:border-orange-500/30 transition-colors">
                        <div class="grid grid-cols-1 lg:grid-cols-[3rem_minmax(0,1fr)_10.5rem] gap-4 lg:gap-5 lg:items-start">
                            <div class="hidden lg:flex w-12 h-12 rounded border border-orange-500/20 bg-orange-500/10 items-center justify-center">
                                <i class="fas fa-flag text-orange-400"></i>
                            </div>

                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2 text-[9px] uppercase font-black tracking-wider">
                                    <span class="text-red-400">{{ str_replace('_', ' ', $report['reason'] ?? 'other') }}</span>
                                    @if(!empty($report['revised_at']))
                                        <span class="px-2 py-1 rounded bg-cyan-500/15 text-cyan-300">
                                            <i class="fas fa-pen-to-square mr-1"></i> Marked revised
                                        </span>
                                    @elseif(($report['status'] ?? 'pending') === 'pending')
                                        <span class="px-2 py-1 rounded bg-orange-500/15 text-orange-300">Revision not confirmed</span>
                                    @endif
                                    <span class="text-slate-600">{{ ucfirst($report['status'] ?? 'pending') }}</span>
                                </div>

                                <h2 class="text-base md:text-lg font-bold text-white mt-3 break-words">{{ $report['quiz_topic_display'] }}</h2>
                                <p class="text-[10px] text-slate-500 mt-1 uppercase">
                                    @if($report['quiz_grade_display']) Grade {{ $report['quiz_grade_display'] }} @endif
                                    @if(!$report['quiz_available']) · <span class="text-red-400">Quiz unavailable</span> @endif
                                </p>

                                @if(!empty($report['question_label']))
                                    <p class="text-[10px] text-cyan-300 mt-3 break-words">{{ $report['question_label'] }}</p>
                                @endif
                                <p class="text-xs text-slate-300 mt-3 leading-relaxed break-words">{{ Str::limit($report['details'] ?: 'No additional details were provided.', 220) }}</p>
                            </div>

                            <div class="flex flex-col gap-3 lg:border-l lg:border-white/10 lg:pl-5">
                                <p class="text-[9px] text-slate-600 uppercase leading-relaxed">
                                    Reported<br class="hidden lg:block">
                                    <span class="text-slate-400">{{ \App\Support\AppDate::format($report['created_at'], 'M j, Y · g:i A') }}</span>
                                </p>
                                @if(!empty($report['revised_at']))
                                    <p class="text-[9px] text-slate-600 uppercase leading-relaxed">
                                        Revised<br class="hidden lg:block">
                                        <span class="text-slate-400">{{ \App\Support\AppDate::format($report['revised_at'], 'M j, Y · g:i A') }}</span>
                                    </p>
                                @endif
                                <a href="/teacher/quiz-reports/{{ $report['id'] }}"
                                   class="btn-rect-primary !bg-orange-600 !text-white !py-2 !px-3 text-center w-full mt-auto">
                                    <i class="fas fa-folder-open mr-2"></i> View Details
                                </a>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="portal-frame !p-10 md:!p-14 text-center">
                        <i class="fas {{ $status === 'active' ? 'fa-check-circle text-green-400' : 'fa-inbox text-slate-700' }} text-4xl mb-4"></i>
                        <p class="text-slate-300 font-bold">No {{ $status }} reports on your quizzes.</p>
                        <p class="text-xs text-slate-600 mt-2">{{ $status === 'active' ? 'There are no quiz reports awaiting administrator review.' : 'Handled reports will appear here.' }}</p>
                    </div>
                @endforelse
            </div>

            @if($totalPages > 1)
                <nav class="flex flex-wrap items-center justify-center gap-4 mt-10" aria-label="Reported quiz pages">
                    @if($page > 1)
                        <a href="/teacher/quiz-reports?{{ http_build_query(['status' => $status, 'page' => $page - 1]) }}"
                           class="btn-rect-secondary !py-2 !px-5 !w-auto"><i class="fas fa-chevron-left mr-2"></i> Previous</a>
                    @endif
                    <span class="text-[10px] text-slate-500 uppercase tracking-widest">Page {{ $page }} of {{ $totalPages }}</span>
                    @if($page < $totalPages)
                        <a href="/teacher/quiz-reports?{{ http_build_query(['status' => $status, 'page' => $page + 1]) }}"
                           class="btn-rect-secondary !py-2 !px-5 !w-auto">Next <i class="fas fa-chevron-right ml-2"></i></a>
                    @endif
                </nav>
            @endif
        </section>

        <aside class="hidden xl:block sticky top-8 space-y-4" aria-label="Report workflow">
            <section class="portal-frame !p-5 border-l-4 border-orange-500">
                <p class="text-[9px] text-orange-400 uppercase font-black tracking-widest">Your next step</p>
                <h2 class="font-orbitron text-sm font-bold uppercase mt-3">Correct, then confirm</h2>
                <p class="text-xs text-slate-400 mt-3 leading-relaxed">Open a report, correct the affected quiz, then mark it revised. The report stays visible until an administrator reviews it.</p>
            </section>
            <section class="portal-frame !p-5">
                <p class="text-[9px] text-slate-500 uppercase font-black tracking-widest mb-4">Report overview</p>
                <dl class="space-y-3">
                    <div class="flex items-center justify-between gap-4 border-b border-white/5 pb-3">
                        <dt class="text-xs text-slate-400">Awaiting admin</dt>
                        <dd class="font-orbitron text-sm text-orange-300">{{ number_format($reportCounts['active']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 border-b border-white/5 pb-3">
                        <dt class="text-xs text-slate-400">Reviewed</dt>
                        <dd class="font-orbitron text-sm text-green-300">{{ number_format($reportCounts['reviewed']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-xs text-slate-400">Dismissed</dt>
                        <dd class="font-orbitron text-sm text-slate-300">{{ number_format($reportCounts['dismissed']) }}</dd>
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
