@extends('layouts.dashboard')

@section('title', 'Learning Hub Insights')
@section('sidebar-subtitle', 'Instructional Hub')
@section('mobile-title', 'Learning Hub Insights')

@section('sidebar-nav')
    @include('teacher.partials.sidebar-nav')
@endsection

@section('dashboard-content')
<div class="max-w-7xl mx-auto" data-testid="teacher-learning-hub" data-configured="{{ $analytics['configured'] ? 'true' : 'false' }}">
    <header class="flex flex-col xl:flex-row xl:items-end justify-between gap-5 mb-7 border-b border-white/10 pb-6">
        <div>
            <p class="text-[9px] uppercase tracking-[0.3em] font-bold text-green-400">Adaptive practice evidence</p>
            <h2 class="font-orbitron font-black text-2xl md:text-3xl mt-2">Learning Hub <span class="text-cyan-400">Insights</span></h2>
            <p class="text-sm text-slate-400 mt-3 max-w-2xl">See class mastery, weak curriculum topics, practice activity, hint use, and improvement without opening individual student accounts.</p>
        </div>
        <form method="GET" action="/teacher/learning-hub" class="grid grid-cols-[minmax(0,1fr)_auto_auto] gap-2 w-full xl:w-auto" data-seamless-form>
            <label class="sr-only" for="learning-class-filter">Class</label>
            <select id="learning-class-filter" name="class_id" class="input-field !py-2.5 !text-xs min-w-0">
                <option value="">All active classes</option>
                @foreach($analytics['available_classes'] as $class)
                    <option value="{{ $class['id'] ?? '' }}" @selected($analytics['selected_class_id'] === ($class['id'] ?? null))>{{ $class['name'] ?? 'Class' }} · Grade {{ $class['grade_level'] ?? '—' }}</option>
                @endforeach
            </select>
            <label class="sr-only" for="learning-days-filter">Period</label>
            <select id="learning-days-filter" name="days" class="input-field !py-2.5 !text-xs">
                @foreach([7, 30, 90, 180] as $days)<option value="{{ $days }}" @selected((int) $analytics['period_days'] === $days)>{{ $days }} days</option>@endforeach
            </select>
            <button class="btn-rect-primary !w-auto !px-4" type="submit">Apply</button>
        </form>
    </header>

    @if(!$analytics['configured'])
        <section class="portal-frame !p-5 mb-6 border-yellow-500/40" role="status"><p class="text-sm text-yellow-300"><i class="fas fa-triangle-exclamation mr-2"></i>{{ $analytics['message'] }}</p></section>
    @elseif($analytics['timezone'] !== \App\Support\AppDate::timezone())
        <section class="portal-frame !p-5 mb-6 border-yellow-500/40" role="status"><p class="text-sm text-yellow-300">Daily practice totals still use {{ $analytics['timezone'] }}. Apply the timezone and insights SQL update to group them by Philippine calendar day.</p></section>
    @endif

    @php
        $summary = $analytics['summary'];
    @endphp
    <section class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6" aria-label="Learning Hub summary">
        <article class="portal-frame !p-4 border-l-2 border-cyan-500"><p class="analytics-kicker">Class mastery</p><strong class="analytics-value">{{ number_format((float) ($summary['average_mastery'] ?? 0), 1) }}%</strong><small>{{ (int) ($summary['active_students'] ?? 0) }} of {{ (int) ($summary['students'] ?? 0) }} active</small></article>
        <article class="portal-frame !p-4 border-l-2 border-green-500"><p class="analytics-kicker">Practice accuracy</p><strong class="analytics-value">{{ number_format((float) ($summary['accuracy'] ?? 0), 1) }}%</strong><small>{{ number_format((int) ($summary['answers'] ?? 0)) }} answers</small></article>
        <article class="portal-frame !p-4 border-l-2 border-purple-500"><p class="analytics-kicker">Hint usage</p><strong class="analytics-value">{{ number_format((float) ($summary['hint_usage_rate'] ?? 0), 1) }}%</strong><small>{{ number_format((int) ($summary['hints_used'] ?? 0)) }} hints revealed</small></article>
        <article class="portal-frame !p-4 border-l-2 border-yellow-500"><p class="analytics-kicker">Improvement</p><strong class="analytics-value {{ (float) ($summary['improvement'] ?? 0) >= 0 ? 'text-green-300' : 'text-red-300' }}">{{ (float) ($summary['improvement'] ?? 0) > 0 ? '+' : '' }}{{ number_format((float) ($summary['improvement'] ?? 0), 1) }}</strong><small>mastery points in period</small></article>
    </section>

    <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1.25fr)_minmax(320px,.75fr)] gap-6 mb-6">
        <section class="portal-frame !p-5 md:!p-6" aria-labelledby="weak-topics-title">
            <div class="flex items-center justify-between gap-3 mb-5"><div><p class="analytics-kicker text-red-300">Needs instruction</p><h3 id="weak-topics-title" class="font-orbitron font-bold mt-1">Weak Topics</h3></div><i class="fas fa-triangle-exclamation text-red-400"></i></div>
            <div class="space-y-3">
                @forelse($analytics['weak_topics'] as $topic)
                    <article class="analytics-topic-row"><span class="analytics-topic-icon"><i class="fas {{ $topic['topic_icon'] }}"></i></span><div class="min-w-0 flex-1"><div class="flex justify-between gap-3"><strong class="truncate">{{ $topic['topic_title'] }}</strong><span class="font-mono text-red-300">{{ number_format((float) ($topic['mastery'] ?? 0), 1) }}%</span></div><div class="analytics-meter"><span style="width: {{ max(0, min(100, (float) ($topic['mastery'] ?? 0))) }}%"></span></div><p>{{ (int) ($topic['students'] ?? 0) }} students · {{ (int) ($topic['attempts'] ?? 0) }} attempts · {{ (int) ($topic['hints_used'] ?? 0) }} hints</p></div></article>
                @empty
                    <p class="analytics-empty">No weak topic is visible for this selection yet.</p>
                @endforelse
            </div>
        </section>

        <section class="portal-frame !p-5 md:!p-6" aria-labelledby="recent-practice-title"><h3 id="recent-practice-title" class="font-orbitron font-bold mb-4">Recent Practice</h3><div class="space-y-3">@forelse($analytics['recent_activity'] as $activity)<article class="analytics-compact-row"><div class="min-w-0"><strong class="truncate block">{{ $activity['student_name'] ?: 'Student' }}</strong><p class="truncate">{{ $activity['topic_title'] }}</p></div><div class="text-right shrink-0"><span class="{{ $activity['is_correct'] ? 'text-green-300' : 'text-red-300' }}">{{ $activity['is_correct'] ? 'Correct' : 'Review' }}</span><p>{{ \App\Support\AppDate::relative($activity['answered_at']) }}</p></div></article>@empty<p class="analytics-empty">No recent practice activity.</p>@endforelse</div></section>
    </div>

    <section class="portal-frame !p-5 md:!p-6 mb-6" aria-labelledby="student-mastery-title">
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-2 mb-5"><div><p class="analytics-kicker text-purple-300">Up to 200 enrolled learners</p><h3 id="student-mastery-title" class="font-orbitron font-bold mt-1">Student Mastery and Activity</h3></div><p class="text-[10px] text-slate-500">Sorted by most recent practice</p></div>
        <div class="overflow-x-auto"><table class="w-full text-left analytics-table"><thead><tr><th>Student</th><th>Class</th><th>Mastery</th><th>Accuracy</th><th>Answers</th><th>Hints</th><th>Change</th><th>Last practice</th></tr></thead><tbody>
            @forelse($analytics['students'] as $student)
                <tr><td><strong>{{ $student['name'] ?: 'Student' }}</strong><small>Grade {{ $student['grade_level'] }}</small></td><td>{{ $student['classes'] ?: '—' }}</td><td>{{ number_format((float) ($student['mastery'] ?? 0), 1) }}%</td><td>{{ number_format((float) ($student['accuracy'] ?? 0), 1) }}%</td><td>{{ (int) ($student['answers'] ?? 0) }}</td><td>{{ (int) ($student['hints_used'] ?? 0) }} <small>({{ number_format((float) ($student['hint_usage_rate'] ?? 0), 1) }}%)</small></td><td class="{{ (float) ($student['improvement'] ?? 0) >= 0 ? 'text-green-300' : 'text-red-300' }}">{{ (float) ($student['improvement'] ?? 0) > 0 ? '+' : '' }}{{ number_format((float) ($student['improvement'] ?? 0), 1) }}</td><td>{{ !empty($student['last_practiced_at']) ? \App\Support\AppDate::relative($student['last_practiced_at']) : 'Not active' }}</td></tr>
            @empty
                <tr><td colspan="8" class="!py-10 text-center text-slate-500">No enrolled student practice data is available for this selection.</td></tr>
            @endforelse
        </tbody></table></div>
    </section>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <section class="portal-frame !p-5 md:!p-6" aria-labelledby="class-comparison-title"><h3 id="class-comparison-title" class="font-orbitron font-bold mb-4">Class Comparison</h3><div class="space-y-3">@forelse($analytics['classes'] as $class)<article class="analytics-compact-row"><div><strong>{{ $class['class_name'] }}</strong><p>Grade {{ $class['grade_level'] }} · {{ $class['active_students'] }}/{{ $class['students'] }} active</p></div><div class="text-right"><strong>{{ number_format((float) ($class['mastery'] ?? 0), 1) }}%</strong><p>{{ (int) ($class['answers'] ?? 0) }} answers</p></div></article>@empty<p class="analytics-empty">No active classes found.</p>@endforelse</div></section>
        <section class="portal-frame !p-5 md:!p-6" aria-labelledby="daily-activity-title">
            <div class="flex items-center justify-between gap-3 mb-5"><div><p class="analytics-kicker text-cyan-300">Practice volume</p><h3 id="daily-activity-title" class="font-orbitron font-bold mt-1">Daily Activity</h3></div><i class="fas fa-chart-line text-cyan-400"></i></div>
            @php
                $dailyAnswerCounts = array_map(fn($day) => (int) ($day['answers'] ?? 0), $analytics['daily_activity']);
                $maxDailyAnswers = max([1, ...$dailyAnswerCounts]);
            @endphp
            <p class="text-[10px] text-slate-500 mb-3">Calendar days in {{ $analytics['timezone'] }}.</p>
            <div class="analytics-days">
                @forelse($analytics['daily_activity'] as $day)
                    @php
                        $answers = (int) ($day['answers'] ?? 0);
                    @endphp
                    <div class="analytics-day" title="{{ $answers }} answers on {{ $day['activity_date'] }}"><span class="analytics-day-bar" style="height: {{ max(6, round(($answers / $maxDailyAnswers) * 100)) }}%"></span><small>{{ \App\Support\AppDate::format($day['activity_date'], 'M j') }}</small></div>
                @empty
                    <p class="analytics-empty">No answered practice questions in this period.</p>
                @endforelse
            </div>
        </section>
    </div>
</div>
@endsection

@section('modals')
    @include('teacher.partials.logout-modal')
@endsection
