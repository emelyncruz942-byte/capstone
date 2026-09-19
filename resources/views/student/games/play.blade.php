@extends('layouts.dashboard')

@section('title', $gameState['game']['title'])
@section('sidebar-subtitle', 'Student Game Hub')
@section('mobile-title', $gameState['game']['title'])

@section('sidebar-nav')
    @include('student.partials.sidebar-nav')
@endsection

@section('dashboard-content')
<div id="math-arcade-game"
     class="max-w-7xl mx-auto"
     data-seamless-refresh="manual"
     data-game-key="{{ $gameState['game']['key'] }}"
     data-accent="{{ $gameState['game']['accent'] }}"
     data-configured="{{ $gameState['configured'] ? 'true' : 'false' }}"
     data-testid="arcade-game">
    <header class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6 border-b border-white/10 pb-5">
        <div class="flex items-center gap-4 min-w-0">
            <a href="/student/games" class="w-11 h-11 rounded-lg border border-white/10 bg-white/5 hover:border-cyan-400/40 flex items-center justify-center shrink-0" aria-label="Back to Math Arcade">
                <i class="fas fa-arrow-left"></i>
            </a>
            <span class="arcade-game-icon shrink-0"><i class="fas {{ $gameState['game']['icon'] }}"></i></span>
            <div class="min-w-0">
                <p class="text-[9px] uppercase tracking-[0.3em] font-bold text-purple-400">{{ $gameState['game']['eyebrow'] }}</p>
                <h2 class="font-orbitron font-black text-xl md:text-3xl truncate">{{ $gameState['game']['title'] }}</h2>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <div class="practice-hud-pill">
                <i class="fas fa-crown text-yellow-400"></i>
                Best <span id="arcade-best-score">{{ $gameState['personal']['best_score'] }}</span>
            </div>
            <div class="practice-hud-pill">
                <i class="fas fa-ranking-star text-cyan-400"></i>
                Rank <span id="arcade-personal-rank">{{ $gameState['personal']['rank'] ? '#'.$gameState['personal']['rank'] : '—' }}</span>
            </div>
        </div>
    </header>

    @if(!$gameState['configured'])
        <section class="portal-frame !p-7 border-yellow-500/40" role="status">
            <div class="flex items-start gap-4">
                <div class="arcade-notice-icon"><i class="fas fa-database"></i></div>
                <div>
                    <h3 class="font-orbitron font-bold uppercase text-yellow-300">Game update required</h3>
                    <p class="text-sm text-slate-400 mt-2">{{ $gameState['message'] }}</p>
                    <a href="/student/games" class="inline-block text-xs uppercase font-bold text-cyan-300 mt-4">Return to Arcade</a>
                </div>
            </div>
        </section>
    @else
        <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_350px] gap-6">
            <main class="portal-frame arcade-play-stage" aria-labelledby="arcade-prompt">
                <div class="grid grid-cols-3 gap-3 mb-6" aria-label="Current game statistics">
                    <div class="number-guess-stat"><span>Score</span><strong id="arcade-score">{{ $gameState['session']['score'] ?? 0 }}</strong></div>
                    <div class="number-guess-stat"><span>Answers</span><strong id="arcade-answers">{{ $gameState['session']['answers'] ?? 0 }}</strong></div>
                    <div class="number-guess-stat"><span>Streak</span><strong id="arcade-streak">{{ $gameState['session']['streak'] ?? 0 }}</strong></div>
                </div>

                <section class="number-guess-timer" aria-label="Time remaining">
                    <div class="flex items-end justify-between gap-3 mb-2">
                        <span class="text-[9px] uppercase tracking-[0.25em] text-slate-500 font-bold">Time Remaining</span>
                        <span class="font-mono text-2xl text-cyan-300"><span id="arcade-time">{{ isset($gameState['session']) ? (int) ceil($gameState['session']['remaining_ms'] / 1000) : $gameState['rules']['starting_seconds'] }}</span>s</span>
                    </div>
                    <div class="number-guess-timer-track" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $gameState['rules']['starting_seconds'] }}" aria-valuenow="0">
                        <span id="arcade-timer-fill"></span>
                    </div>
                </section>

                <div class="arcade-question-console">
                    <p id="arcade-sequence" class="text-[9px] uppercase tracking-[0.3em] font-bold text-purple-400">
                        {{ $gameState['session'] ? 'Question '.$gameState['session']['sequence'] : 'Ready for launch?' }}
                    </p>
                    <h1 id="arcade-prompt" class="arcade-question-prompt" aria-live="polite">
                        {{ $gameState['session']['challenge']['prompt'] ?? 'Start a new 60-second run' }}
                    </h1>
                    <p class="text-xs text-slate-500 mt-3">One point for each correct answer. A wrong answer resets only the streak.</p>
                    <div id="arcade-feedback" class="number-guess-feedback" data-tone="neutral" aria-live="assertive">
                        {{ $gameState['session'] ? 'Your verified run is active. Keep going!' : 'Every run rotates through varied question types.' }}
                    </div>
                </div>

                <form id="arcade-form" class="mt-7" novalidate>
                    <label id="arcade-input-label" for="arcade-input" class="input-label text-center">Your answer</label>
                    <input id="arcade-input" type="text" inputmode="numeric" autocomplete="off" maxlength="40"
                           class="arcade-answer-input" aria-describedby="arcade-input-error"
                           {{ $gameState['session'] ? '' : 'disabled' }}>
                    <div id="arcade-choice-options" class="arcade-choice-options hidden" role="group" aria-labelledby="arcade-input-label"></div>
                    <p id="arcade-input-error" class="hidden text-xs text-red-400 mt-3 text-center" role="alert"></p>
                    <button type="submit" id="arcade-submit" class="btn-rect-primary mt-5" {{ $gameState['session'] ? '' : 'disabled' }}>
                        Lock Answer <i class="fas fa-bolt ml-2"></i>
                    </button>
                </form>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                    <button type="button" id="arcade-start" class="btn-rect-secondary">
                        <i class="fas {{ $gameState['session'] ? 'fa-rotate-right' : 'fa-play' }} mr-2"></i>
                        {{ $gameState['session'] ? 'Restart Run' : 'Start Run' }}
                    </button>
                    <button type="button" id="arcade-end" class="btn-rect-secondary text-red-300" {{ $gameState['session'] ? '' : 'disabled' }}>
                        <i class="fas fa-stop mr-2"></i> End Run
                    </button>
                </div>
            </main>

            <aside class="space-y-5">
                <section class="portal-frame !p-5 md:!p-6">
                    <div class="flex items-center justify-between gap-3 mb-5">
                        <div>
                            <p class="text-[9px] uppercase tracking-widest font-bold text-yellow-400">Game leaderboard</p>
                            <h3 class="font-orbitron font-bold mt-1">Grade {{ $gameState['grade'] }} Rankings</h3>
                        </div>
                        <button type="button" id="arcade-refresh-board" class="w-10 h-10 rounded-lg border border-white/10 text-slate-400 hover:text-cyan-300 hover:border-cyan-400/40" aria-label="Refresh leaderboard">
                            <i class="fas fa-rotate"></i>
                        </button>
                    </div>
                    <p class="text-[10px] text-slate-500 mb-4">Top {{ $gameState['rules']['leaderboard_limit'] }} plus your rank · Best score wins · Streak breaks ties</p>
                    <ol id="arcade-leaderboard" class="number-guess-leaderboard" aria-live="polite">
                        @forelse($gameState['leaderboard'] as $entry)
                            <li class="number-guess-rank {{ $entry['is_current'] ? 'is-current' : '' }}" data-rank="{{ $entry['rank'] }}">
                                <span class="number-guess-rank-number">#{{ $entry['rank'] }}</span>
                                <span class="min-w-0 flex-1">
                                    <strong class="block truncate">{{ $entry['display_name'] }}{{ $entry['is_current'] ? ' (You)' : '' }}</strong>
                                    <small>Streak {{ $entry['best_streak'] }} · {{ $entry['games_played'] }} runs</small>
                                </span>
                                <strong class="number-guess-rank-score">{{ $entry['best_score'] }}</strong>
                            </li>
                        @empty
                            <li class="number-guess-board-empty">No scores yet. Complete the first run!</li>
                        @endforelse
                    </ol>
                </section>

                <section class="portal-frame !p-5 md:!p-6 border-purple-500/20">
                    <p class="text-[9px] uppercase tracking-widest font-bold text-purple-400"><i class="fas fa-circle-info mr-2"></i> Mission notes</p>
                    <ul class="mt-4 space-y-3 text-xs text-slate-400">
                        <li class="flex gap-3"><i class="fas fa-clock text-cyan-400 mt-0.5"></i><span>You have {{ $gameState['rules']['starting_seconds'] }} server-timed seconds.</span></li>
                        <li class="flex gap-3"><i class="fas fa-shuffle text-green-400 mt-0.5"></i><span>Question families rotate, while values adapt to Grade {{ $gameState['grade'] }}.</span></li>
                        <li class="flex gap-3"><i class="fas fa-shield-halved text-yellow-400 mt-0.5"></i><span>The browser never receives an answer until that question is submitted.</span></li>
                    </ul>
                </section>
            </aside>
        </div>

        <noscript><div class="portal-frame !p-6 mt-5 border-red-500/40 text-red-300">JavaScript is required to play this arcade game.</div></noscript>
    @endif
</div>

<template id="math-arcade-initial-state">{!! json_encode($gameState, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</template>
@endsection

@section('modals')
    @include('student.partials.logout-modal')

    @if($gameState['configured'])
        <div id="arcadeResultModal" class="modal-overlay hidden" aria-hidden="true">
            <div class="portal-frame !p-7 w-full max-w-sm text-center border-cyan-500/40">
                <i class="fas fa-trophy text-4xl text-yellow-400 mb-4"></i>
                <p class="text-[9px] uppercase tracking-[0.25em] font-bold text-cyan-400">Run Complete</p>
                <h3 id="arcade-result-title" class="font-orbitron font-bold text-xl uppercase mt-2">Time's Up!</h3>
                <p id="arcade-result-summary" class="text-sm text-slate-300 mt-4 mb-6"></p>
                <button type="button" id="arcade-play-again" class="btn-rect-primary">Play Again</button>
                <button type="button" data-action="closeModal" data-action-args='["arcadeResultModal"]' class="modal-cancel">Close</button>
            </div>
        </div>
    @endif
@endsection
