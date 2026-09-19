@extends('layouts.dashboard')

@section('title', 'Learning Hub')
@section('sidebar-subtitle', 'Student Game Hub')
@section('mobile-title', 'Learning Hub')

@section('sidebar-nav')
    @include('student.partials.sidebar-nav')
@endsection

@section('dashboard-content')
@php
    $activeSession = $hub['active_session'];
    $activeMode = $activeSession['mode'] ?? 'adventure';
    $activeQuery = ['mode' => $activeMode];
    if ($activeMode === 'focus' && !empty($activeSession['focus_competency_key'])) {
        $activeQuery['topic'] = $activeSession['focus_competency_key'];
    }
    $activePracticeUrl = '/student/learning-hub/practice?'.http_build_query($activeQuery);
    $activeLabel = match ($activeMode) {
        'daily' => 'Continue Daily Quest',
        'review' => 'Continue Skill Rescue',
        'focus' => 'Continue Topic Focus',
        default => 'Continue Adventure',
    };
    $heroTopic = $hub['recommended'];
    if ($activeMode === 'focus' && !empty($activeSession['focus_competency_key'])) {
        foreach ($hub['skills'] as $skill) {
            if ($skill['key'] === $activeSession['focus_competency_key']) {
                $heroTopic = $skill;
                break;
            }
        }
    }
@endphp
<div class="max-w-7xl mx-auto" data-testid="student-learning-hub" data-configured="{{ $hub['configured'] ? 'true' : 'false' }}">
    <header class="flex flex-col lg:flex-row lg:items-end justify-between gap-5 mb-7 border-b border-white/10 pb-5">
        <div>
            <p class="text-[10px] font-bold uppercase tracking-[0.35em] text-purple-400 mb-2">Autonomous Learning System</p>
            <h2 class="text-2xl md:text-4xl font-orbitron font-black uppercase">
                MathVerse <span class="text-cyan-400">Adventure</span>
            </h2>
            <p class="text-sm text-slate-400 mt-3 max-w-2xl">
                MathVerse chooses your next challenge, adapts to every answer, and helps you recover from mistakes automatically.
            </p>
        </div>
        <div class="flex items-center gap-3 text-right">
            <div>
                <p class="text-[9px] uppercase tracking-widest text-slate-500">Current Rank</p>
                <p class="font-orbitron font-black text-lg text-cyan-400">Level {{ $hub['level'] }}</p>
            </div>
            <div class="w-12 h-12 rounded-full border border-cyan-400/40 bg-cyan-400/10 flex items-center justify-center text-cyan-400">
                <i class="fas fa-user-astronaut text-xl"></i>
            </div>
        </div>
    </header>

    @if(!$hub['configured'])
        <div class="portal-frame !p-6 mb-7 border-l-4 border-yellow-500">
            <div class="flex items-start gap-4">
                <i class="fas fa-triangle-exclamation text-yellow-400 text-xl mt-1"></i>
                <div>
                    <h3 class="font-orbitron font-bold uppercase text-yellow-400">Learning Hub update required</h3>
                    <p class="text-xs text-slate-400 mt-2">
                        Install the curriculum topic-focus database update before students begin practicing.
                    </p>
                </div>
            </div>
        </div>
    @endif

    <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-7" aria-label="Learning progress">
        <div class="portal-frame !p-5 border-b-2 border-cyan-500">
            <p class="text-[9px] uppercase font-bold tracking-widest text-slate-500">Overall Mastery</p>
            <p class="text-2xl font-orbitron font-black mt-2">{{ $hub['average_mastery'] }}%</p>
            <div class="h-1.5 bg-white/5 rounded-full overflow-hidden mt-3">
                <div class="h-full bg-cyan-400 rounded-full" style="width: {{ $hub['average_mastery'] }}%"></div>
            </div>
        </div>
        <div class="portal-frame !p-5 border-b-2 border-orange-500">
            <p class="text-[9px] uppercase font-bold tracking-widest text-slate-500">Practice Streak</p>
            <p class="text-2xl font-orbitron font-black mt-2">{{ $hub['streak'] }} <span class="text-sm text-orange-400">days</span></p>
            <p class="text-[9px] text-slate-500 mt-3">A rest day will not erase earned mastery.</p>
        </div>
        <div class="portal-frame !p-5 border-b-2 border-purple-500">
            <p class="text-[9px] uppercase font-bold tracking-widest text-slate-500">Skills Mastered</p>
            <p class="text-2xl font-orbitron font-black mt-2">{{ $hub['mastered_count'] }}<span class="text-sm text-purple-400">/{{ count($hub['skills']) }}</span></p>
            <p class="text-[9px] text-slate-500 mt-3">Grade {{ $hub['grade'] }} learning path</p>
        </div>
        <div class="portal-frame !p-5 border-b-2 border-yellow-500">
            <p class="text-[9px] uppercase font-bold tracking-widest text-slate-500">Experience</p>
            <p class="text-2xl font-orbitron font-black mt-2">{{ number_format($hub['xp']) }} <span class="text-sm text-yellow-400">XP</span></p>
            <div class="h-1.5 bg-white/5 rounded-full overflow-hidden mt-3">
                <div class="h-full bg-yellow-400 rounded-full" style="width: {{ round(($hub['xp_in_level'] / $hub['xp_per_level']) * 100) }}%"></div>
            </div>
        </div>
    </section>

    <section class="grid grid-cols-1 xl:grid-cols-[1.4fr_0.8fr] gap-6 mb-7">
        <article class="portal-frame !p-7 md:!p-9 relative overflow-hidden learning-hero-card">
            <div class="absolute -right-12 -top-12 w-52 h-52 rounded-full bg-cyan-500/10 blur-3xl pointer-events-none"></div>
            <div class="relative z-10 max-w-2xl">
                <div class="inline-flex items-center gap-2 rounded-full border border-cyan-400/20 bg-cyan-400/5 px-3 py-1.5 text-[9px] uppercase font-bold tracking-widest text-cyan-400 mb-5">
                    <i class="fas fa-wand-magic-sparkles"></i> {{ $activeMode === 'focus' ? 'Active Topic Focus' : 'Recommended Next' }}
                </div>
                <p class="text-xs text-slate-500 uppercase tracking-widest">{{ $heroTopic['world'] }}</p>
                <h3 class="font-orbitron text-2xl md:text-3xl font-black mt-2 text-white">
                    {{ $heroTopic['title'] }}
                </h3>
                <div class="flex flex-wrap items-center gap-3 mt-4 text-xs">
                    <span class="px-3 py-1.5 rounded bg-white/5 text-slate-300">{{ $heroTopic['status'] }}</span>
                    <span class="px-3 py-1.5 rounded bg-white/5 text-slate-300">Difficulty {{ $heroTopic['difficulty'] }}/5</span>
                    <span class="px-3 py-1.5 rounded bg-white/5 text-slate-300">{{ $heroTopic['mastery'] }}% mastery</span>
                </div>
                <p class="text-sm text-slate-400 mt-5 mb-7">
                    {{ $activeMode === 'focus'
                        ? 'Continue your saved topic. Every new problem stays within this curriculum topic while difficulty adapts.'
                        : 'Your next five-question mission is selected from your current level, unfinished skills, and scheduled reviews.' }}
                </p>
                @if($hub['configured'])
                    <a href="{{ $activePracticeUrl }}"
                       class="btn-rect-primary !w-auto inline-flex items-center justify-center px-8 py-4">
                        <i class="fas fa-play mr-2"></i>
                        {{ $activeSession ? $activeLabel : 'Begin Adventure' }}
                    </a>
                @else
                    <button type="button" disabled class="btn-rect-primary !w-auto px-8 py-4 opacity-40 cursor-not-allowed">
                        Adventure Offline
                    </button>
                @endif
            </div>
        </article>
        <div class="grid grid-rows-2 gap-4">
            <a href="{{ $hub['configured'] ? '/student/learning-hub/practice?mode=adventure' : '#' }}" class="portal-frame !p-6 learning-mode-card border-cyan-500/30 {{ !$hub['configured'] ? 'pointer-events-none opacity-50' : '' }}">
                <i class="fas fa-infinity text-2xl text-cyan-400"></i>
                <h4 class="font-orbitron font-bold mt-4">Endless Adventure</h4>
                <p class="text-xs text-slate-500 mt-2">A continuous adaptive path through every Grade {{ $hub['grade'] }} skill.</p>
            </a>
            <a href="{{ $hub['configured'] ? '/student/learning-hub/practice?mode=review' : '#' }}" class="portal-frame !p-6 learning-mode-card border-orange-500/30 {{ !$hub['configured'] ? 'pointer-events-none opacity-50' : '' }}">
                <i class="fas fa-screwdriver-wrench text-2xl text-orange-400"></i>
                <h4 class="font-orbitron font-bold mt-4">Weak Skill Rescue</h4>
                <p class="text-xs text-slate-500 mt-2">Automatically repairs the skills with your lowest current mastery.</p>
            </a>
        </div>
    </section>

    <section aria-labelledby="curriculum-map-title">
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-3 mb-5">
            <div>
                <p class="text-[9px] uppercase tracking-[0.3em] font-bold text-slate-500">Grade {{ $hub['grade'] }} curriculum map</p>
                <h3 id="curriculum-map-title" class="font-orbitron font-bold uppercase text-lg mt-1">All Topics</h3>
            </div>
            <p class="text-xs text-slate-500 max-w-xl md:text-right">
                Choose any topic to practice only that topic. Endless Adventure still moves through the complete Grade {{ $hub['grade'] }} curriculum automatically.
            </p>
        </div>

        <div class="space-y-7">
            @foreach($hub['terms'] as $term)
                @php
                    $termMastered = count(array_filter($term['skills'], fn ($skill) => $skill['mastery'] >= 90));
                @endphp
                <section class="learning-term-section" aria-labelledby="term-{{ $loop->iteration }}-title">
                    <div class="flex items-center justify-between gap-4 mb-4 pb-3 border-b border-white/10">
                        <div class="flex items-center gap-3">
                            <span class="learning-term-number">{{ $loop->iteration }}</span>
                            <div>
                                <p class="text-[9px] uppercase tracking-widest text-slate-600">Curriculum sequence</p>
                                <h4 id="term-{{ $loop->iteration }}-title" class="font-orbitron font-bold text-white">{{ $term['label'] }}</h4>
                            </div>
                        </div>
                        <span class="text-[10px] font-mono text-slate-500">{{ $termMastered }}/{{ count($term['skills']) }} mastered</span>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                        @foreach($term['skills'] as $skill)
                            @php
                                $focusUrl = '/student/learning-hub/practice?'.http_build_query([
                                    'mode' => 'focus',
                                    'topic' => $skill['key'],
                                ]);
                                $isActiveFocus = $activeMode === 'focus'
                                    && ($activeSession['focus_competency_key'] ?? null) === $skill['key'];
                            @endphp
                            <a href="{{ $hub['configured'] ? $focusUrl : '#' }}"
                               class="portal-frame learning-topic-card !p-5 {{ !$hub['configured'] ? 'pointer-events-none opacity-50' : '' }}"
                               style="--topic-color: {{ $skill['color'] }}; border-color: {{ $skill['color'] }}55;"
                               aria-label="{{ $isActiveFocus ? 'Continue' : 'Practice' }} {{ $skill['title'] }} only">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="w-11 h-11 rounded-lg flex items-center justify-center shrink-0" style="color: {{ $skill['color'] }}; background: {{ $skill['color'] }}18; border: 1px solid {{ $skill['color'] }}44;">
                                        <i class="fas {{ $skill['icon'] }}"></i>
                                    </div>
                                    <div class="text-right">
                                        <span class="text-[9px] uppercase font-bold tracking-wider text-slate-500">Difficulty {{ $skill['difficulty'] }}</span>
                                        @if($isActiveFocus)
                                            <p class="text-[9px] uppercase font-bold tracking-wider text-green-400 mt-1">In progress</p>
                                        @endif
                                    </div>
                                </div>
                                <p class="text-[9px] uppercase tracking-widest mt-5" style="color: {{ $skill['color'] }};">{{ $skill['strand'] }}</p>
                                <h5 class="font-bold text-white mt-1 text-lg leading-tight">{{ $skill['title'] }}</h5>
                                <p class="text-xs text-slate-500 mt-3 leading-relaxed min-h-12">{{ $skill['summary'] }}</p>
                                <div class="flex justify-between text-[10px] mt-5 mb-2">
                                    <span class="text-slate-500">{{ $skill['status'] }}</span>
                                    <span class="font-mono text-white">{{ $skill['mastery'] }}%</span>
                                </div>
                                <div class="h-1.5 bg-white/5 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full" style="width: {{ $skill['mastery'] }}%; background: {{ $skill['color'] }};"></div>
                                </div>
                                <div class="flex items-center justify-between gap-3 mt-4 pt-3 border-t border-white/5">
                                    <span class="text-[9px] text-slate-600">
                                        {{ $skill['attempts'] > 0 ? $skill['attempts'].' attempts · '.$skill['accuracy'].'% accuracy' : 'Not started' }}
                                    </span>
                                    <span class="text-[9px] uppercase font-bold tracking-wider" style="color: {{ $skill['color'] }};">
                                        {{ $isActiveFocus ? 'Continue' : 'Focus' }} <i class="fas fa-arrow-right ml-1"></i>
                                    </span>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    </section>

    <div class="mt-7 text-center text-[10px] text-slate-600 uppercase tracking-widest">
        <i class="fas fa-heart-pulse mr-2 text-cyan-500"></i>
        Progress saves after every answer. MathVerse will suggest a rest after a healthy practice session.
    </div>
</div>
@endsection

@section('modals')
    @include('student.partials.logout-modal')
@endsection
