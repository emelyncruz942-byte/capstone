@extends('layouts.dashboard')

@section('title', $class['class_name'])
@section('sidebar-subtitle', 'Student Game Hub')
@section('mobile-title', $class['class_name'])

@section('sidebar-nav')
    @include('student.partials.sidebar-nav', ['activePage' => 'class'])
@endsection

@section('dashboard-content')
@php
    $iconMap = [
        'chalkboard' => 'fa-chalkboard', 'calculator' => 'fa-calculator',
        'rocket' => 'fa-rocket', 'atom' => 'fa-atom',
        'shapes' => 'fa-shapes', 'gamepad' => 'fa-gamepad',
    ];
    $patternStyles = [
        'grid' => 'linear-gradient(rgba(255,255,255,.08) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.08) 1px, transparent 1px); background-size: 24px 24px;',
        'stars' => 'radial-gradient(circle at 20% 30%, rgba(255,255,255,.35) 1px, transparent 2px), radial-gradient(circle at 75% 55%, rgba(255,255,255,.25) 1px, transparent 2px); background-size: 44px 44px;',
        'circuit' => 'linear-gradient(90deg, transparent 48%, rgba(255,255,255,.12) 49%, rgba(255,255,255,.12) 51%, transparent 52%); background-size: 38px 38px;',
        'waves' => 'radial-gradient(ellipse at bottom, rgba(255,255,255,.16), transparent 65%);',
        'plain' => 'none;',
    ];
    $themeColor = $customization['theme_color'];
    $classIcon = $iconMap[$customization['icon']] ?? 'fa-chalkboard';
    $patternStyle = $patternStyles[$customization['banner_pattern']] ?? $patternStyles['grid'];
@endphp

<a href="/student/dashboard?section=class" class="inline-block text-xs text-slate-400 hover:text-white font-bold uppercase mb-5">
    <i class="fas fa-arrow-left mr-2"></i> My Classes
</a>

<header class="portal-frame overflow-hidden mb-8" style="border-color: {{ $themeColor }}66;">
    <div class="relative p-6 md:p-10 min-h-[190px] flex items-end"
         style="background-color: {{ $themeColor }}22; background-image: {{ $patternStyle }}">
        <div class="absolute top-7 right-7 w-16 h-16 rounded-xl flex items-center justify-center text-3xl"
             style="color: {{ $themeColor }}; background: {{ $themeColor }}22; border: 1px solid {{ $themeColor }}66;">
            <i class="fas {{ $classIcon }}"></i>
        </div>
        <div>
            <p class="text-[10px] uppercase tracking-[0.25em] font-bold mb-2" style="color: {{ $themeColor }};">Grade {{ $class['grade_level'] }}</p>
            <h1 class="text-2xl md:text-4xl font-orbitron font-black text-white pr-20">{{ $class['class_name'] }}</h1>
        </div>
    </div>
</header>

<section class="mb-10">
    <div class="mb-5">
        <h2 class="text-lg font-orbitron font-bold uppercase">Assigned & <span class="text-green-400">Active Quizzes</span></h2>
        <p class="text-xs text-slate-500 mt-1">Enter the VR code in the game. The code is shown only for your class quizzes.</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        @forelse($openSessions as $session)
            @php
                $isActive = ($session['status'] ?? 'waiting') === 'active';
                $alreadyTaken = !empty($session['result']);
                $isScheduled = !empty($session['available_at']) && now()->lt(\Carbon\Carbon::parse($session['available_at'], 'UTC'));
                $canAttempt = $isActive && !$isScheduled && ($session['remaining_attempts'] ?? 0) > 0;
            @endphp
            <article class="portal-frame !p-6 border-l-4 {{ $isActive ? 'border-green-500' : 'border-yellow-500' }}">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <span class="text-[9px] font-black uppercase tracking-widest px-2 py-1 rounded {{ $isActive ? 'bg-green-500/10 text-green-400' : 'bg-yellow-500/10 text-yellow-400' }}">
                            {{ $isScheduled ? 'Scheduled' : ($isActive ? (($session['retake_mode'] ?? false) ? 'Retake Active' : 'Active Now') : 'Assigned') }}
                        </span>
                        <h3 class="font-bold text-xl text-white mt-4">{{ $session['topic'] }}</h3>
                        <p class="text-[10px] text-slate-500 mt-1">{{ $session['time_limit'] }} seconds per question</p>
                        @if(!empty($session['available_at']))
                            <p class="text-[9px] text-slate-500 mt-2">Available {{ \App\Support\AppDate::format($session['available_at'], 'M d, Y h:i A') }}</p>
                        @endif
                        @if(!empty($session['effective_due_at']))
                            <p class="text-[9px] text-orange-400 mt-1">{{ !empty($session['eligibility']['retake_due_at']) ? 'Your retake is due' : 'Due' }} {{ \App\Support\AppDate::format($session['effective_due_at'], 'M d, Y h:i A') }}</p>
                        @endif
                    </div>
                    <i class="fas fa-vr-cardboard text-2xl {{ $isActive ? 'text-green-400' : 'text-yellow-400' }} opacity-70"></i>
                </div>
                @if($alreadyTaken && !$canAttempt)
                    <div class="mt-6 p-4 rounded bg-green-500/10 border border-green-500/30">
                        <p class="text-xs text-green-400 font-bold uppercase"><i class="fas fa-check-circle mr-2"></i>Attempt recorded</p>
                        <p class="text-[10px] text-slate-400 mt-1">No additional teacher-authorized attempt is currently available.</p>
                    </div>
                @elseif($isScheduled)
                    <div class="mt-6 p-4 rounded bg-yellow-500/10 border border-yellow-500/30 text-xs text-yellow-300">
                        The VR code will be available at the scheduled time.
                    </div>
                @elseif(!$isActive)
                    <div class="mt-6 p-4 rounded bg-slate-500/10 border border-white/10 text-xs text-slate-400">
                        Waiting for your teacher to start this quiz.
                    </div>
                @else
                <div class="mt-6 p-4 rounded bg-black/40 border border-cyan-500/20 flex items-center justify-between gap-4">
                    <div>
                        <p class="text-[9px] text-slate-500 uppercase tracking-widest">VR Quiz Code</p>
                        <code class="text-3xl text-cyan-400 font-black tracking-[0.22em]">{{ $session['room_code'] }}</code>
                    </div>
                    <button type="button" data-action="copyToClipboard" data-action-args="{{ json_encode([$session['room_code']], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}"
                            class="w-11 h-11 rounded bg-cyan-500/10 border border-cyan-500/30 text-cyan-400 hover:bg-cyan-500 hover:text-black transition-all"
                            title="Copy VR code"><i class="fas fa-copy"></i></button>
                </div>
                @endif
            </article>
        @empty
            <div class="portal-frame !p-10 text-center text-slate-500 text-xs uppercase tracking-widest lg:col-span-2">
                Your teacher has not assigned an active quiz yet.
            </div>
        @endforelse
    </div>
</section>

<section class="mb-10">
    <h2 class="text-lg font-orbitron font-bold uppercase mb-5">My Class <span class="text-cyan-400">Analytics</span></h2>
    <div class="grid grid-cols-2 lg:grid-cols-7 gap-4">
        <div class="portal-frame !p-5 border-l-2 border-purple-500">
            <p class="text-[10px] text-slate-500 uppercase tracking-widest font-bold">Quizzes Taken</p>
            <p class="text-2xl font-orbitron mt-1">{{ $analytics['attempts'] }}</p>
        </div>
        <div class="portal-frame !p-5 border-l-2 border-cyan-500">
            <p class="text-[10px] text-slate-500 uppercase tracking-widest font-bold">Average Accuracy</p>
            <p class="text-2xl font-orbitron mt-1">{{ $analytics['average'] }}%</p>
        </div>
        <div class="portal-frame !p-5 border-l-2 border-green-500">
            <p class="text-[10px] text-slate-500 uppercase tracking-widest font-bold">Best Accuracy</p>
            <p class="text-2xl font-orbitron mt-1">{{ $analytics['best'] }}%</p>
        </div>
        <div class="portal-frame !p-5 border-l-2 border-red-500">
            <p class="text-[10px] text-slate-500 uppercase tracking-widest font-bold">Missed</p>
            <p class="text-2xl font-orbitron mt-1">{{ $analytics['missed'] }}</p>
        </div>
        <div class="portal-frame !p-5 border-l-2 border-slate-500">
            <p class="text-[10px] text-slate-500 uppercase tracking-widest font-bold">Excused</p>
            <p class="text-2xl font-orbitron mt-1">{{ $analytics['excused'] }}</p>
        </div>
        <div class="portal-frame !p-5 border-l-2 border-emerald-500">
            <p class="text-[10px] text-slate-500 uppercase tracking-widest font-bold">Passed</p>
            <p class="text-2xl font-orbitron mt-1">{{ $analytics['passed'] }}</p>
        </div>
        <div class="portal-frame !p-5 border-l-2 border-orange-500">
            <p class="text-[10px] text-slate-500 uppercase tracking-widest font-bold">Failed</p>
            <p class="text-2xl font-orbitron mt-1">{{ $analytics['failed'] }}</p>
        </div>
    </div>
</section>

<section class="mb-10">
    <h2 class="text-lg font-orbitron font-bold uppercase mb-5">Class <span class="text-yellow-400">Leaderboard</span></h2>
    <div class="portal-frame !p-5 overflow-x-auto">
        <table class="w-full min-w-[520px] text-left">
            <thead class="text-[10px] text-slate-500 uppercase border-b border-white/10"><tr><th class="pb-4">Rank</th><th class="pb-4">Student</th><th class="pb-4">Completed</th><th class="pb-4">Completion</th><th class="pb-4">Rank Accuracy</th></tr></thead>
            <tbody class="text-sm">
                @forelse($leaderboard as $row)
                    <tr class="border-b border-white/5 {{ $row['student_id'] === $user['id'] ? 'bg-cyan-500/5' : '' }}">
                        <td class="py-4 font-mono text-yellow-400">#{{ $row['rank'] }}</td>
                        <td class="py-4 font-bold">{{ $row['name'] }} {{ $row['student_id'] === $user['id'] ? '(You)' : '' }}</td>
                        <td class="py-4 text-slate-400">{{ $row['quizzes'] }}/{{ $row['eligible'] }}</td>
                        <td class="py-4 text-blue-400">{{ $row['completion_rate'] }}%</td>
                        <td class="py-4 {{ $row['average'] >= 75 ? 'text-green-400' : 'text-red-400' }} font-bold">{{ $row['average'] }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-8 text-center text-slate-500 text-xs uppercase">No ended quiz assignments yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section>
    <h2 class="text-lg font-orbitron font-bold uppercase mb-5">Past <span class="text-slate-400">Quizzes</span></h2>
    <div class="space-y-4">
        @forelse($endedSessions as $session)
            @php
                $result = $session['result'];
                $accuracy = $result['accuracy'] ?? null;
                $accuracyColor = $accuracy === null ? 'text-slate-500' : ($accuracy >= 75 ? 'text-green-400' : 'text-red-400');
            @endphp
            <article class="portal-frame !p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <p class="text-[9px] text-slate-600 uppercase tracking-widest">Ended · Quiz Code {{ $session['room_code'] }}</p>
                    <h3 class="font-bold text-lg text-white mt-1">{{ $session['topic'] }}</h3>
                    <p class="text-[10px] text-slate-500 mt-1">{{ $session['time_limit'] }} seconds per question</p>
                </div>
                <div class="flex items-center gap-4">
                @if(($session['eligibility']['eligibility_status'] ?? '') === 'excused')
                    <p class="text-xs text-slate-400 uppercase font-bold">Excused</p>
                @elseif($result)
                    <div class="text-left sm:text-right">
                        <p class="font-bold text-cyan-400">{{ $result['correct_answers'] }} / {{ $result['total_questions'] }}</p>
                        <p class="text-sm font-black {{ $accuracyColor }}">{{ $accuracy >= 75 ? 'Passed' : 'Failed' }} · {{ $accuracy }}%</p>
                        <p class="text-[9px] text-slate-600 mt-1">{{ \App\Support\AppDate::format($result['created_at'], 'M d, Y') }}</p>
                    </div>
                @else
                    <p class="text-xs text-red-400 uppercase font-bold">Missed</p>
                @endif
                    <a href="/student/classes/{{ $class['id'] }}/quizzes/{{ $session['id'] }}/review" class="btn-rect-secondary !py-2 !px-3 !w-auto text-[9px]">Review</a>
                </div>
            </article>
        @empty
            <div class="portal-frame !p-10 text-center text-slate-500 text-xs uppercase tracking-widest">No past quizzes yet.</div>
        @endforelse
    </div>
</section>
@endsection

@section('modals')
    @include('student.partials.logout-modal')
@endsection
