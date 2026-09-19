@extends('layouts.dashboard')

@section('title', 'Practice Arena')
@section('sidebar-subtitle', 'Student Game Hub')
@section('mobile-title', 'Practice Arena')

@section('sidebar-nav')
    @include('student.partials.sidebar-nav')
@endsection

@section('dashboard-content')
<div id="practice-arena" data-seamless-refresh="manual" class="max-w-6xl mx-auto" style="--practice-accent: {{ $practiceState['question']['color'] }};">
    <header class="flex flex-wrap items-center justify-between gap-4 mb-5 border-b border-white/10 pb-4">
        <div class="flex items-center gap-4 min-w-0">
            <a href="/student/learning-hub" class="w-11 h-11 rounded-lg border border-white/10 bg-white/5 hover:border-cyan-400/40 flex items-center justify-center shrink-0" aria-label="Exit Practice Arena">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div class="min-w-0">
                <p id="practice-mode" class="text-[9px] uppercase tracking-[0.3em] font-bold text-purple-400">{{ $practiceState['mode_label'] }}</p>
                <h2 id="practice-world" class="font-orbitron font-black text-lg md:text-2xl truncate">{{ $practiceState['question']['world'] }}</h2>
            </div>
        </div>
        <div class="flex items-center gap-2 md:gap-3">
            <div class="practice-hud-pill">
                <i class="fas fa-bolt text-yellow-400"></i>
                <span id="practice-xp">{{ number_format($practiceState['profile']['xp']) }}</span> XP
            </div>
            <div class="practice-hud-pill">
                <i class="fas fa-fire text-orange-400"></i>
                <span id="practice-combo">{{ $practiceState['session']['current_combo'] }}</span>x
            </div>
            <div class="practice-hud-pill hidden sm:flex">
                <i class="fas fa-star text-cyan-400"></i>
                Lv. <span id="practice-level">{{ $practiceState['profile']['level'] }}</span>
            </div>
        </div>
    </header>

    <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_270px] gap-5">
        <main class="portal-frame !p-5 md:!p-9 practice-question-frame" aria-labelledby="practice-prompt">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-7">
                <div class="flex items-center gap-3">
                    <div id="practice-icon-wrap" class="w-11 h-11 rounded-lg border flex items-center justify-center">
                        <i id="practice-icon" class="fas {{ $practiceState['question']['icon'] }}"></i>
                    </div>
                    <div>
                        <p id="practice-competency" class="font-bold text-sm text-white">{{ $practiceState['question']['competency_title'] }}</p>
                        <p class="text-[9px] uppercase tracking-widest text-slate-500">
                            Difficulty <span id="practice-difficulty">{{ $practiceState['question']['difficulty'] }}</span>/5
                        </p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-[9px] uppercase tracking-widest text-slate-500">Mission <span id="practice-mission">{{ $practiceState['question']['mission'] }}</span></p>
                    <p class="font-mono text-cyan-400 text-sm">Problem <span id="practice-position">{{ $practiceState['question']['mission_position'] }}</span>/5</p>
                </div>
            </div>

            <div id="practice-progress" class="grid grid-cols-5 gap-2 mb-8" aria-label="Mission progress"></div>

            <div class="min-h-40 flex flex-col justify-center">
                <p class="text-[10px] uppercase tracking-[0.3em] text-slate-600 font-bold mb-4">Solve the challenge</p>
                <h1 id="practice-prompt" class="font-orbitron text-xl md:text-3xl font-black leading-relaxed text-white whitespace-pre-line" aria-live="polite">
                    {{ $practiceState['question']['prompt'] }}
                </h1>
            </div>

            <form id="practice-answer-form" class="mt-8" novalidate>
                <div id="practice-answer-area"></div>
                <p id="practice-answer-error" class="hidden text-xs text-red-400 mt-3" role="alert"></p>
                <div class="flex flex-col sm:flex-row gap-3 mt-6">
                    <button type="button" id="practice-hint-button" class="btn-rect-secondary !w-auto sm:min-w-40">
                        <i class="fas fa-lightbulb mr-2 text-yellow-400"></i> Use a Hint
                    </button>
                    <button type="submit" id="practice-submit-button" class="btn-rect-primary flex-1">
                        Check Answer <i class="fas fa-arrow-right ml-2"></i>
                    </button>
                    <button type="button" id="practice-next-button" class="btn-rect-primary flex-1 hidden">
                        Next Problem <i class="fas fa-forward ml-2"></i>
                    </button>
                </div>
            </form>

            <div id="practice-hints" class="hidden mt-6 rounded-lg border border-yellow-500/20 bg-yellow-500/5 p-5" aria-live="polite">
                <p class="text-[9px] uppercase font-bold tracking-widest text-yellow-400 mb-3"><i class="fas fa-lightbulb mr-2"></i> Mission Hints</p>
                <ol id="practice-hint-list" class="space-y-2 text-sm text-slate-300 list-decimal list-inside"></ol>
            </div>

            <section id="practice-feedback" class="hidden mt-7 rounded-xl border p-6" aria-live="assertive">
                <div class="flex items-start gap-4">
                    <div id="practice-feedback-icon" class="w-12 h-12 rounded-full flex items-center justify-center shrink-0">
                        <i class="fas fa-check"></i>
                    </div>
                    <div class="min-w-0">
                        <h3 id="practice-feedback-title" class="font-orbitron font-black text-lg"></h3>
                        <p id="practice-feedback-answer" class="text-sm font-bold mt-2"></p>
                        <p id="practice-feedback-explanation" class="text-sm text-slate-300 mt-3 leading-relaxed"></p>
                        <div class="flex flex-wrap gap-2 mt-4">
                            <span id="practice-xp-reward" class="rounded bg-white/5 px-3 py-1.5 text-[10px] font-bold"></span>
                            <span id="practice-mastery-reward" class="rounded bg-white/5 px-3 py-1.5 text-[10px] font-bold"></span>
                            <span id="practice-combo-reward" class="rounded bg-white/5 px-3 py-1.5 text-[10px] font-bold"></span>
                        </div>
                    </div>
                </div>
                <div id="practice-checkpoint" class="hidden mt-5 pt-5 border-t border-white/10">
                    <p class="font-orbitron font-bold text-purple-300"><i class="fas fa-flag-checkered mr-2"></i> Mission checkpoint reached</p>
                    <p id="practice-checkpoint-summary" class="text-xs text-slate-400 mt-2"></p>
                </div>
            </section>
        </main>

        <aside class="space-y-4">
            <section class="portal-frame !p-5">
                <p class="text-[9px] uppercase tracking-widest font-bold text-cyan-400">Adaptive Status</p>
                <div class="mt-5 space-y-4">
                    <div>
                        <div class="flex justify-between text-[10px] mb-2">
                            <span class="text-slate-500">Current mastery</span>
                            <span id="practice-mastery-label" class="font-mono text-white">Adapting</span>
                        </div>
                        <div class="h-2 bg-white/5 rounded-full overflow-hidden">
                            <div id="practice-mastery-bar" class="h-full bg-cyan-400 rounded-full transition-all duration-500" style="width: 0%"></div>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded-lg bg-white/5 p-3 text-center">
                            <p class="text-[8px] uppercase text-slate-600">Solved</p>
                            <p id="practice-solved" class="font-orbitron font-bold mt-1">{{ $practiceState['session']['questions_answered'] }}</p>
                        </div>
                        <div class="rounded-lg bg-white/5 p-3 text-center">
                            <p class="text-[8px] uppercase text-slate-600">Correct</p>
                            <p id="practice-correct" class="font-orbitron font-bold mt-1">{{ $practiceState['session']['correct_answers'] }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="portal-frame !p-5 border-yellow-500/20">
                <div class="flex items-center gap-3">
                    <i class="fas fa-brain text-yellow-400"></i>
                    <p class="font-bold text-sm">
                        {{ $practiceState['session']['mode'] === 'focus' ? 'Focused on your chosen topic' : 'MathVerse is choosing for you' }}
                    </p>
                </div>
                <p class="text-[10px] text-slate-500 mt-3 leading-relaxed">
                    @if($practiceState['session']['mode'] === 'focus')
                        Every problem stays within {{ $practiceState['question']['competency_title'] }}. Difficulty still adapts to your answers.
                    @else
                        Correct streaks unlock harder problems. Mistakes activate hints, easier examples, and scheduled review.
                    @endif
                </p>
            </section>

            <section id="practice-break-card" class="portal-frame !p-5 border-green-500/20 hidden">
                <p class="text-[9px] uppercase tracking-widest font-bold text-green-400"><i class="fas fa-mug-hot mr-2"></i> Healthy checkpoint</p>
                <p class="text-xs text-slate-400 mt-3">You have completed a strong practice session. Your progress is saved if you want a short break.</p>
                <a href="/student/learning-hub" class="text-[10px] uppercase font-bold text-green-400 inline-block mt-4">Save and rest</a>
            </section>
        </aside>
    </div>

    <noscript>
        <div class="portal-frame !p-6 mt-5 border-red-500/40 text-red-300">
            JavaScript is required for the adaptive Practice Arena.
        </div>
    </noscript>
</div>

<template id="practice-initial-state">{!! json_encode($practiceState, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</template>
@endsection

@section('modals')
    @include('student.partials.logout-modal')
@endsection
