@extends('layouts.dashboard')

@section('title', 'Number Guess')
@section('sidebar-subtitle', 'Student Game Hub')
@section('mobile-title', 'Number Guess')

@section('sidebar-nav')
    @include('student.partials.sidebar-nav')
@endsection

@section('dashboard-content')
<div id="number-guess-game" data-seamless-refresh="manual" class="max-w-7xl mx-auto">
    <header class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6 border-b border-white/10 pb-5">
        <div class="flex items-center gap-4 min-w-0">
            <a href="/student/games" class="w-11 h-11 rounded-lg border border-white/10 bg-white/5 hover:border-cyan-400/40 flex items-center justify-center shrink-0" aria-label="Back to Math Arcade">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div class="min-w-0">
                <p class="text-[9px] uppercase tracking-[0.3em] font-bold text-purple-400">MathVerse Arcade</p>
                <h2 class="font-orbitron font-black text-xl md:text-3xl truncate">Number <span class="text-cyan-400">Guess</span></h2>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <div class="practice-hud-pill">
                <i class="fas fa-crown text-yellow-400"></i>
                Best <span id="number-guess-best-score">{{ $gameState['personal']['best_score'] }}</span>
            </div>
            <div class="practice-hud-pill">
                <i class="fas fa-ranking-star text-cyan-400"></i>
                Rank <span id="number-guess-personal-rank">{{ $gameState['personal']['rank'] ? '#'.$gameState['personal']['rank'] : '—' }}</span>
            </div>
        </div>
    </header>

    @if(!$gameState['configured'])
        <section class="portal-frame !p-7 border-yellow-500/40" role="status">
            <div class="flex items-start gap-4">
                <div class="w-12 h-12 rounded-xl bg-yellow-500/10 border border-yellow-500/30 text-yellow-400 flex items-center justify-center shrink-0">
                    <i class="fas fa-database"></i>
                </div>
                <div>
                    <h3 class="font-orbitron font-bold uppercase text-yellow-400">Game update required</h3>
                    <p class="text-sm text-slate-400 mt-2">{{ $gameState['message'] }}</p>
                    <p class="text-xs text-slate-500 mt-3">Install the matching Number Guess migration, then reload this page.</p>
                </div>
            </div>
        </section>
    @else
        <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_350px] gap-6">
            <main class="portal-frame number-guess-stage" aria-labelledby="number-guess-prompt">
                <div class="grid grid-cols-3 gap-3 mb-6" aria-label="Current game statistics">
                    <div class="number-guess-stat">
                        <span>Score</span>
                        <strong id="number-guess-score">{{ $gameState['session']['score'] ?? 0 }}</strong>
                    </div>
                    <div class="number-guess-stat">
                        <span>Tries</span>
                        <strong id="number-guess-tries">{{ $gameState['session']['guesses'] ?? 0 }}</strong>
                    </div>
                    <div class="number-guess-stat">
                        <span>Games</span>
                        <strong id="number-guess-games-played">{{ $gameState['personal']['games_played'] }}</strong>
                    </div>
                </div>

                <section class="number-guess-timer" aria-label="Time remaining">
                    <div class="flex items-end justify-between gap-3 mb-2">
                        <span class="text-[9px] uppercase tracking-[0.25em] text-slate-500 font-bold">Time Remaining</span>
                        <span class="font-mono text-2xl text-cyan-300"><span id="number-guess-time">{{ isset($gameState['session']) ? (int) ceil($gameState['session']['remaining_ms'] / 1000) : $gameState['rules']['starting_seconds'] }}</span>s</span>
                    </div>
                    <div class="number-guess-timer-track" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $gameState['rules']['starting_seconds'] }}" aria-valuenow="0">
                        <span id="number-guess-timer-fill"></span>
                    </div>
                </section>

                <div class="number-guess-console">
                    <div class="number-guess-orb" aria-hidden="true">
                        <i class="fas fa-question"></i>
                    </div>
                    <p id="number-guess-eyebrow" class="text-[9px] uppercase tracking-[0.3em] font-bold text-purple-400">Ready for a challenge?</p>
                    <h1 id="number-guess-prompt" class="font-orbitron font-black text-xl md:text-3xl mt-3 text-white" aria-live="polite">
                        {{ $gameState['session'] ? 'Find the hidden number' : 'Start a new game' }}
                    </h1>
                    <p id="number-guess-range" class="text-sm md:text-base text-slate-400 mt-3">
                        Enter a whole number from 1 to <strong class="text-cyan-300">{{ $gameState['session']['range_max'] ?? $gameState['rules']['starting_range_max'] }}</strong>.
                    </p>
                    <div id="number-guess-feedback" class="number-guess-feedback" data-tone="neutral" aria-live="assertive">
                        {{ $gameState['session'] ? 'Your game is still running. Keep guessing!' : 'Use the clues to narrow the range before time runs out.' }}
                    </div>
                </div>

                <form id="number-guess-form" class="mt-7" novalidate>
                    <label for="number-guess-input" class="input-label text-center">Your guess</label>
                    <div class="number-guess-input-row">
                        <button type="button" id="number-guess-decrement" class="number-guess-stepper" aria-label="Decrease guess by one">
                            <i class="fas fa-minus"></i>
                        </button>
                        <input id="number-guess-input" type="number" min="1" max="{{ $gameState['session']['range_max'] ?? $gameState['rules']['starting_range_max'] }}" step="1" inputmode="numeric" autocomplete="off" value="{{ (int) ceil((($gameState['session']['range_max'] ?? $gameState['rules']['starting_range_max']) + 1) / 2) }}" class="number-guess-input" aria-describedby="number-guess-input-error" {{ $gameState['session'] ? '' : 'disabled' }}>
                        <button type="button" id="number-guess-increment" class="number-guess-stepper" aria-label="Increase guess by one">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                    <p id="number-guess-input-error" class="hidden text-xs text-red-400 mt-3 text-center" role="alert"></p>

                    <button type="submit" id="number-guess-submit" class="btn-rect-primary mt-5" {{ $gameState['session'] ? '' : 'disabled' }}>
                        Submit Guess <i class="fas fa-bullseye ml-2"></i>
                    </button>
                </form>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                    <button type="button" id="number-guess-start" class="btn-rect-secondary">
                        <i class="fas fa-play mr-2"></i> {{ $gameState['session'] ? 'Restart Game' : 'Start Game' }}
                    </button>
                    <button type="button" id="number-guess-end" class="btn-rect-secondary text-red-300" {{ $gameState['session'] ? '' : 'disabled' }}>
                        <i class="fas fa-stop mr-2"></i> End Game
                    </button>
                </div>

                <p class="text-[10px] text-slate-600 text-center mt-5">
                    Keyboard: <kbd>↑</kbd>/<kbd>↓</kbd> changes the number and <kbd>Enter</kbd> submits it.
                </p>
            </main>

            <aside class="space-y-5">
                <section class="portal-frame !p-5 md:!p-6">
                    <div class="flex items-center justify-between gap-3 mb-5">
                        <div>
                            <p class="text-[9px] uppercase tracking-widest font-bold text-yellow-400">Hall of Numbers</p>
                            <h3 class="font-orbitron font-bold mt-1">Grade {{ $gameState['grade'] }} Leaderboard</h3>
                        </div>
                        <button type="button" id="number-guess-refresh-board" class="w-10 h-10 rounded-lg border border-white/10 text-slate-400 hover:text-cyan-300 hover:border-cyan-400/40" aria-label="Refresh leaderboard">
                            <i class="fas fa-rotate"></i>
                        </button>
                    </div>
                    <p class="text-[10px] text-slate-500 mb-4">Your grade level · Best score wins · Fewer tries breaks a tie</p>
                    <ol id="number-guess-leaderboard" class="number-guess-leaderboard" aria-live="polite">
                        @forelse($gameState['leaderboard'] as $entry)
                            <li class="number-guess-rank {{ $entry['is_current'] ? 'is-current' : '' }}" data-rank="{{ $entry['rank'] }}">
                                <span class="number-guess-rank-number">#{{ $entry['rank'] }}</span>
                                <span class="min-w-0 flex-1">
                                    <strong class="block truncate">{{ $entry['display_name'] }}{{ $entry['is_current'] ? ' (You)' : '' }}</strong>
                                    <small>Grade {{ $entry['grade_level'] }} · {{ $entry['best_guesses'] }} tries</small>
                                </span>
                                <strong class="number-guess-rank-score">{{ $entry['best_score'] }}</strong>
                            </li>
                        @empty
                            <li class="number-guess-board-empty">No scores yet. Be the first explorer on the board!</li>
                        @endforelse
                    </ol>
                </section>

                <section class="portal-frame !p-5 md:!p-6 border-purple-500/20">
                    <p class="text-[9px] uppercase tracking-widest font-bold text-purple-400"><i class="fas fa-circle-info mr-2"></i> How it works</p>
                    <ul class="mt-4 space-y-3 text-xs text-slate-400">
                        <li class="flex gap-3"><i class="fas fa-clock text-cyan-400 mt-0.5"></i><span>Begin with {{ $gameState['rules']['starting_seconds'] }} seconds.</span></li>
                        <li class="flex gap-3"><i class="fas fa-arrow-trend-up text-green-400 mt-0.5"></i><span>Each correct number adds {{ $gameState['rules']['correct_bonus_seconds'] }} seconds and expands the range by {{ $gameState['rules']['range_increase'] }}.</span></li>
                        <li class="flex gap-3"><i class="fas fa-shield-halved text-yellow-400 mt-0.5"></i><span>Scores and time are verified by the server for a fair leaderboard.</span></li>
                    </ul>
                </section>
            </aside>
        </div>

        <noscript>
            <div class="portal-frame !p-6 mt-5 border-red-500/40 text-red-300">JavaScript is required to play Number Guess.</div>
        </noscript>
    @endif
</div>

<template id="number-guess-initial-state">{!! json_encode($gameState, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</template>
@endsection

@section('modals')
    @include('student.partials.logout-modal')

    @if($gameState['configured'])
        <div id="numberGuessEndModal" class="modal-overlay hidden" aria-hidden="true">
            <div class="portal-frame !p-7 w-full max-w-sm text-center border-red-500/40">
                <i class="fas fa-stop-circle text-4xl text-red-400 mb-4"></i>
                <h3 id="number-guess-end-title" class="font-orbitron font-bold uppercase">End Current Game?</h3>
                <p id="number-guess-end-message" class="text-xs text-slate-400 mt-3 mb-6">Your verified score stays on the leaderboard, but this timer cannot be resumed.</p>
                <button type="button" id="number-guess-confirm-end" class="btn-rect-primary !bg-red-500 !text-white">End Game</button>
                <button type="button" data-action="closeModal" data-action-args='["numberGuessEndModal"]' class="modal-cancel">Cancel</button>
            </div>
        </div>

        <div id="numberGuessResultModal" class="modal-overlay hidden" aria-hidden="true">
            <div class="portal-frame !p-7 w-full max-w-sm text-center border-cyan-500/40">
                <i class="fas fa-trophy text-4xl text-yellow-400 mb-4"></i>
                <p class="text-[9px] uppercase tracking-[0.25em] font-bold text-cyan-400">Run Complete</p>
                <h3 id="number-guess-result-title" class="font-orbitron font-bold text-xl uppercase mt-2">Time's Up!</h3>
                <p id="number-guess-result-summary" class="text-sm text-slate-300 mt-4 mb-6"></p>
                <button type="button" id="number-guess-play-again" class="btn-rect-primary">Play Again</button>
                <button type="button" data-action="closeModal" data-action-args='["numberGuessResultModal"]' class="modal-cancel">Close</button>
            </div>
        </div>
    @endif
@endsection
