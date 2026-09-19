@extends('layouts.dashboard')

@section('title', $session['topic'] . ' Review')
@section('sidebar-subtitle', 'Student Game Hub')
@section('mobile-title', 'Quiz Review')

@section('sidebar-nav')
    @include('student.partials.sidebar-nav', ['activePage' => 'stats'])
@endsection

@section('dashboard-content')
<a href="{{ empty($class['archived_at']) ? '/student/classes/' . $class['id'] : '/student/dashboard?section=stats' }}" class="inline-block text-xs text-slate-400 hover:text-white font-bold uppercase mb-6">
    <i class="fas fa-arrow-left mr-2"></i> Back
</a>

<header class="portal-frame !p-6 md:!p-8 mb-7 border-l-4 border-cyan-500">
    <p class="text-[10px] text-slate-500 uppercase tracking-widest">{{ $class['class_name'] }} · Ended Quiz</p>
    <h1 class="text-2xl font-orbitron font-bold mt-2">{{ $session['topic'] }}</h1>
    @if($result)
        <div class="flex flex-wrap gap-3 mt-5 text-xs font-bold uppercase">
            <span class="px-3 py-2 rounded bg-cyan-500/10 text-cyan-400">{{ $result['correct_answers'] }} / {{ $result['total_questions'] }}</span>
            <span class="px-3 py-2 rounded {{ $result['accuracy'] >= 75 ? 'bg-green-500/10 text-green-400' : 'bg-red-500/10 text-red-400' }}">{{ $result['status'] }} · {{ $result['accuracy'] }}%</span>
        </div>
    @elseif(($eligibility['eligibility_status'] ?? '') === 'excused')
        <p class="text-slate-400 text-xs font-bold uppercase mt-5">Excused — this quiz does not affect your completion or leaderboard score</p>
    @else
        <p class="text-red-400 text-xs font-bold uppercase mt-5">Missed — no attempt recorded</p>
    @endif
</header>

<div class="space-y-5">
    @forelse($questions as $index => $question)
        <article class="portal-frame !p-6">
            <p class="text-[10px] text-cyan-400 uppercase font-bold tracking-widest mb-3">Question {{ $index + 1 }}</p>
            <h2 class="text-lg font-bold text-white mb-5">{{ $question['question'] }}</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                @foreach($question['choices'] as $choiceIndex => $choice)
                    @php $isCorrect = $choiceIndex === $question['correct_index']; @endphp
                    <div class="p-4 rounded border {{ $isCorrect ? 'border-green-500/50 bg-green-500/10 text-green-300' : 'border-white/5 bg-white/5 text-slate-400' }}">
                        <span class="font-bold mr-2">{{ chr(65 + $choiceIndex) }}.</span>{{ $choice }}
                        @if($isCorrect)<i class="fas fa-check-circle float-right mt-1"></i>@endif
                    </div>
                @endforeach
            </div>
        </article>
    @empty
        <div class="portal-frame !p-10 text-center text-slate-500 text-xs uppercase">No question snapshot is available for this quiz.</div>
    @endforelse
</div>
@endsection

@section('modals')
    @include('student.partials.logout-modal')
@endsection
