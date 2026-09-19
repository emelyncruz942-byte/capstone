@extends('layouts.dashboard')

@section('title', 'Math Arcade')
@section('sidebar-subtitle', 'Student Game Hub')
@section('mobile-title', 'Math Arcade')

@section('sidebar-nav')
    @include('student.partials.sidebar-nav')
@endsection

@section('dashboard-content')
<div class="max-w-7xl mx-auto" data-testid="arcade-hub" data-configured="{{ $arcade['configured'] ? 'true' : 'false' }}">
    <header class="arcade-hub-hero portal-frame !p-6 md:!p-8 mb-6">
        <div class="relative z-10 flex flex-col lg:flex-row lg:items-center justify-between gap-6">
            <div class="max-w-3xl">
                <p class="text-[9px] uppercase tracking-[0.3em] font-bold text-pink-400">MathVerse Arcade</p>
                <h2 class="font-orbitron font-black text-2xl md:text-4xl mt-2">Think Fast. <span class="text-cyan-400">Reason Deeper.</span></h2>
                <p class="text-sm text-slate-400 mt-3 leading-relaxed">
                    Four short math game challenges with fair server-verified scores. Be the top player in your grade level.
                </p>
            </div>
            <div class="arcade-grade-chip shrink-0">
                <i class="fas fa-ranking-star text-yellow-400"></i>
                <span><small>Leaderboard division</small>Grade {{ $arcade['grade'] }}</span>
            </div>
        </div>
    </header>

    @if(!$arcade['configured'])
        <section class="portal-frame !p-5 mb-6 border-yellow-500/40" role="status">
            <div class="flex items-start gap-4">
                <div class="arcade-notice-icon"><i class="fas fa-database"></i></div>
                <div>
                    <h3 class="font-orbitron font-bold uppercase text-yellow-300">Shared games need an update</h3>
                    <p class="text-sm text-slate-400 mt-2">{{ $arcade['message'] }}</p>
                </div>
            </div>
        </section>
    @endif

    <section aria-labelledby="arcade-games-title">
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-3 mb-4">
            <div>
                <p class="text-[9px] uppercase tracking-widest font-bold text-cyan-400">Choose a mission</p>
                <h3 id="arcade-games-title" class="font-orbitron font-bold text-xl mt-1">Math Games</h3>
            </div>
            <p class="text-[10px] text-slate-500"><i class="fas fa-shield-halved text-green-400 mr-1"></i> Answers, timers, scores, and ranks are verified by the server.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
            @foreach($arcade['games'] as $game)
                <a href="{{ $game['available'] ? $game['url'] : '#' }}"
                   class="portal-frame arcade-game-card {{ !$game['available'] ? 'is-unavailable' : '' }}"
                   data-accent="{{ $game['accent'] }}"
                   data-game-key="{{ $game['key'] }}"
                   {{ !$game['available'] ? 'aria-disabled=true' : '' }}>
                    <div class="flex items-start justify-between gap-4">
                        <span class="arcade-game-icon"><i class="fas {{ $game['icon'] }}"></i></span>
                        @if($game['available'])
                            <span class="arcade-launch-icon"><i class="fas fa-arrow-up-right-from-square"></i></span>
                        @else
                            <span class="arcade-locked-label"><i class="fas fa-lock mr-1"></i> Update needed</span>
                        @endif
                    </div>
                    <p class="arcade-card-eyebrow">{{ $game['eyebrow'] }}</p>
                    <h4 class="font-orbitron font-black text-lg mt-1">{{ $game['title'] }}</h4>
                    <p class="text-xs text-slate-500 leading-relaxed mt-3 min-h-12">{{ $game['description'] }}</p>
                    <div class="arcade-mini-stats">
                        <span><small>Best</small><strong>{{ $game['best_score'] }}</strong></span>
                        <span><small>Streak</small><strong>{{ $game['best_streak'] ?: '—' }}</strong></span>
                        <span><small>Runs</small><strong>{{ $game['games_played'] }}</strong></span>
                    </div>
                </a>
            @endforeach
        </div>
    </section>

    <section class="portal-frame !p-5 md:!p-7 mt-7" aria-labelledby="arcade-badges-title">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-5">
            <div>
                <p class="text-[9px] uppercase tracking-widest font-bold text-purple-400">Shared achievements</p>
                <h3 id="arcade-badges-title" class="font-orbitron font-bold text-xl mt-1">Arcade Badges</h3>
            </div>
            <p class="max-w-xl text-[10px] leading-relaxed text-slate-500">
                Game badges and leaderboards are their own reward system. Arcade games never award Learning Hub XP or trophies.
            </p>
        </div>

        <div class="arcade-badge-grid">
            @foreach($arcade['badges'] as $badge)
                <article class="arcade-badge {{ $badge['unlocked'] ? 'is-unlocked' : '' }}" data-badge-key="{{ $badge['key'] }}">
                    <span class="arcade-badge-icon"><i class="fas {{ $badge['icon'] }}"></i></span>
                    <span class="min-w-0">
                        <strong>{{ $badge['title'] }}</strong>
                        <small>{{ $badge['description'] }}</small>
                    </span>
                    <i class="fas {{ $badge['unlocked'] ? 'fa-circle-check' : 'fa-lock' }} arcade-badge-state" aria-label="{{ $badge['unlocked'] ? 'Unlocked' : 'Locked' }}"></i>
                </article>
            @endforeach
        </div>
    </section>
</div>
@endsection

@section('modals')
    @include('student.partials.logout-modal')
@endsection
