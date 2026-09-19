@extends('layouts.dashboard')

@section('title', 'Student Learning Hub Stats')
@section('sidebar-subtitle', 'Instructional Hub')
@section('mobile-title', 'Solo Learning Stats')

@section('sidebar-nav')
    @include('teacher.partials.sidebar-nav')
@endsection

@section('dashboard-content')
@php
    $student = $analytics['student'] ?? [];
    $summary = $analytics['summary'] ?? [];
    $backQuery = array_filter([
        'class_id' => $analytics['selected_class_id'] ?: null,
        'days' => (int) $analytics['period_days'],
    ]);
    $backUrl = '/teacher/learning-hub';
    if ($backQuery !== []) $backUrl .= '?' . http_build_query($backQuery);
@endphp

<div class="max-w-7xl mx-auto" data-testid="teacher-learning-hub-student" data-configured="{{ $analytics['configured'] ? 'true' : 'false' }}">
    <header class="flex flex-col xl:flex-row xl:items-end justify-between gap-5 mb-7 border-b border-white/10 pb-6">
        <div>
            <a class="solo-back-link" href="{{ $backUrl }}"><i class="fas fa-arrow-left"></i> All Learning Hub insights</a>
            <p class="text-[9px] uppercase tracking-[0.3em] font-bold text-purple-300 mt-4">Individual learner evidence</p>
            <h2 class="font-orbitron font-black text-2xl md:text-3xl mt-2">{{ $student['name'] ?: 'Student' }} <span class="text-cyan-400">Solo Stats</span></h2>
            <p class="text-sm text-slate-400 mt-3 max-w-2xl">Monitor this learner’s mastery, accuracy, topic progress, hint use, improvement, and practice consistency without exposing another student’s data.</p>
        </div>
        <form method="GET" action="/teacher/learning-hub/students/{{ $student['id'] }}" class="grid grid-cols-[minmax(0,1fr)_auto] gap-2 w-full xl:w-auto" data-seamless-form>
            @if($analytics['selected_class_id'])
                <input type="hidden" name="class_id" value="{{ $analytics['selected_class_id'] }}">
            @endif
            <label class="sr-only" for="solo-learning-days-filter">Period</label>
            <select id="solo-learning-days-filter" name="days" class="input-field !py-2.5 !text-xs">
                @foreach([7, 30, 90, 180] as $days)
                    <option value="{{ $days }}" @selected((int) $analytics['period_days'] === $days)>{{ $days }} days</option>
                @endforeach
            </select>
            <button class="btn-rect-primary !w-auto !px-4" type="submit">Apply</button>
        </form>
    </header>

    @if(!$analytics['configured'])
        <section class="portal-frame !p-5 mb-6 border-yellow-500/40" role="status">
            <p class="text-sm text-yellow-300"><i class="fas fa-triangle-exclamation mr-2"></i>{{ $analytics['message'] }}</p>
        </section>
    @else
        @if($analytics['timezone'] !== \App\Support\AppDate::timezone())
            <section class="portal-frame !p-5 mb-6 border-yellow-500/40" role="status">
                <p class="text-sm text-yellow-300">Solo daily totals still use {{ $analytics['timezone'] }}. Apply the individual Learning Hub insights SQL update.</p>
            </section>
        @endif

        <section class="portal-frame !p-5 md:!p-6 mb-6 solo-profile-card" aria-labelledby="solo-profile-title">
            <div>
                <p class="analytics-kicker text-cyan-300">Student profile</p>
                <h3 id="solo-profile-title" class="font-orbitron font-bold mt-1">{{ $student['name'] ?: 'Student' }}</h3>
                <p class="text-xs text-slate-400 mt-2">Grade {{ $student['grade_level'] ?? '—' }} · {{ (int) $analytics['period_days'] }}-day monitoring period</p>
            </div>
            <div class="solo-class-list" aria-label="Enrolled classes in this view">
                @forelse($analytics['classes'] as $class)
                    <span class="solo-class-chip">{{ $class['name'] }} · Grade {{ $class['grade_level'] }}</span>
                @empty
                    <span class="text-xs text-slate-500">No active class</span>
                @endforelse
            </div>
        </section>

        <section class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6" aria-label="Individual Learning Hub summary">
            <article class="portal-frame !p-4 border-l-2 border-cyan-500"><p class="analytics-kicker">Average mastery</p><strong class="analytics-value">{{ number_format((float) ($summary['average_mastery'] ?? 0), 1) }}%</strong><small>{{ (int) ($summary['mastered_topics'] ?? 0) }} of {{ (int) ($summary['practised_topics'] ?? 0) }} topics mastered</small></article>
            <article class="portal-frame !p-4 border-l-2 border-green-500"><p class="analytics-kicker">Accuracy</p><strong class="analytics-value">{{ number_format((float) ($summary['accuracy'] ?? 0), 1) }}%</strong><small>{{ (int) ($summary['correct'] ?? 0) }} correct of {{ (int) ($summary['answers'] ?? 0) }} answers</small></article>
            <article class="portal-frame !p-4 border-l-2 border-purple-500"><p class="analytics-kicker">Hint usage</p><strong class="analytics-value">{{ number_format((float) ($summary['hint_usage_rate'] ?? 0), 1) }}%</strong><small>{{ (int) ($summary['hints_used'] ?? 0) }} hints revealed</small></article>
            <article class="portal-frame !p-4 border-l-2 border-yellow-500"><p class="analytics-kicker">Improvement</p><strong class="analytics-value {{ (float) ($summary['improvement'] ?? 0) >= 0 ? 'text-green-300' : 'text-red-300' }}">{{ (float) ($summary['improvement'] ?? 0) > 0 ? '+' : '' }}{{ number_format((float) ($summary['improvement'] ?? 0), 1) }}</strong><small>{{ (int) ($summary['active_days'] ?? 0) }} active Philippine calendar days</small></article>
        </section>

        <section class="portal-frame !p-5 md:!p-6 mb-6" aria-labelledby="solo-topic-title">
            <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-2 mb-5">
                <div><p class="analytics-kicker text-red-300">Individual curriculum evidence</p><h3 id="solo-topic-title" class="font-orbitron font-bold mt-1">Topic Mastery and Activity</h3></div>
                <p class="text-[10px] text-slate-500">Lowest mastery first</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left analytics-table">
                    <thead><tr><th>Topic</th><th>Mastery</th><th>Period accuracy</th><th>Period answers</th><th>Lifetime attempts</th><th>Hints</th><th>Change</th><th>Last practice</th></tr></thead>
                    <tbody>
                        @forelse($analytics['topics'] as $topic)
                            <tr>
                                <td><strong>{{ $topic['topic_title'] }}</strong><small>Grade {{ $topic['grade_level'] ?? $student['grade_level'] }}</small></td>
                                <td>{{ number_format((float) ($topic['mastery'] ?? 0), 1) }}%</td>
                                <td>{{ number_format((float) ($topic['period_accuracy'] ?? 0), 1) }}%</td>
                                <td>{{ (int) ($topic['period_answers'] ?? 0) }}</td>
                                <td>{{ (int) ($topic['lifetime_attempts'] ?? 0) }}</td>
                                <td>{{ (int) ($topic['period_hints'] ?? 0) }}</td>
                                <td class="{{ (float) ($topic['improvement'] ?? 0) >= 0 ? 'text-green-300' : 'text-red-300' }}">{{ (float) ($topic['improvement'] ?? 0) > 0 ? '+' : '' }}{{ number_format((float) ($topic['improvement'] ?? 0), 1) }}</td>
                                <td>{{ !empty($topic['last_practiced_at']) ? \App\Support\AppDate::relative($topic['last_practiced_at']) : 'Not active' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="!py-10 text-center text-slate-500">This student has not practised a Learning Hub topic yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <section class="portal-frame !p-5 md:!p-6" aria-labelledby="solo-daily-title">
                <div class="flex items-center justify-between gap-3 mb-5"><div><p class="analytics-kicker text-cyan-300">Practice consistency</p><h3 id="solo-daily-title" class="font-orbitron font-bold mt-1">Daily Activity</h3></div><i class="fas fa-chart-line text-cyan-400"></i></div>
                @php
                    $dailyAnswerCounts = array_map(fn($day) => (int) ($day['answers'] ?? 0), $analytics['daily_activity']);
                    $maxDailyAnswers = max([1, ...$dailyAnswerCounts]);
                @endphp
                <p class="text-[10px] text-slate-500 mb-3">Calendar days in {{ $analytics['timezone'] }}.</p>
                <div class="analytics-days">
                    @forelse($analytics['daily_activity'] as $day)
                        @php $answers = (int) ($day['answers'] ?? 0); @endphp
                        <div class="analytics-day" title="{{ $answers }} answers with {{ number_format((float) ($day['accuracy'] ?? 0), 1) }}% accuracy on {{ $day['activity_date'] }}"><span class="analytics-day-bar" style="height: {{ max(6, round(($answers / $maxDailyAnswers) * 100)) }}%"></span><small>{{ \App\Support\AppDate::format($day['activity_date'], 'M j') }}</small></div>
                    @empty
                        <p class="analytics-empty">No answered practice questions in this period.</p>
                    @endforelse
                </div>
            </section>

            <section class="portal-frame !p-5 md:!p-6" aria-labelledby="solo-recent-title">
                <div class="flex items-center justify-between gap-3 mb-5"><div><p class="analytics-kicker text-purple-300">Latest attempts</p><h3 id="solo-recent-title" class="font-orbitron font-bold mt-1">Recent Practice</h3></div><i class="fas fa-clock-rotate-left text-purple-400"></i></div>
                <div class="space-y-3">
                    @forelse($analytics['recent_activity'] as $activity)
                        <article class="analytics-compact-row"><div class="min-w-0"><strong class="truncate block">{{ $activity['topic_title'] }}</strong><p>Difficulty {{ (int) ($activity['difficulty'] ?? 1) }} · {{ (int) ($activity['hints_used'] ?? 0) }} hints</p></div><div class="text-right shrink-0"><span class="{{ $activity['is_correct'] ? 'text-green-300' : 'text-red-300' }}">{{ $activity['is_correct'] ? 'Correct' : 'Review' }}</span><p>{{ \App\Support\AppDate::relative($activity['answered_at']) }}</p></div></article>
                    @empty
                        <p class="analytics-empty">No recent practice activity.</p>
                    @endforelse
                </div>
            </section>
        </div>
    @endif
</div>
@endsection

@section('modals')
    @include('teacher.partials.logout-modal')
@endsection

