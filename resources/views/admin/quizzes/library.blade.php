@extends('layouts.dashboard')

@section('title', 'Shared Quiz Library')
@section('sidebar-border', 'border-red-500/20')
@section('sidebar-subtitle', 'System Administrator')
@section('sidebar-subtitle-color', 'text-red-500/60')
@section('accent-color', 'text-red-500')
@section('mobile-title', 'Quiz Library')

@section('sidebar-nav')
    @include('admin.partials.sidebar-nav', ['activePage' => 'library'])
@endsection

@section('dashboard-content')
<div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 mb-8">
    <div>
        <h1 class="text-xl md:text-2xl font-orbitron font-bold uppercase">
            Shared Quiz <span class="text-blue-400">Library</span>
        </h1>
        <p class="text-xs text-slate-500 mt-2">Review, edit, verify, or delete quizzes shared by teachers.</p>
    </div>
    <a href="/admin/quizzes" class="btn-rect-secondary !py-3 !px-5 text-center lg:!w-auto">
        <i class="fas fa-arrow-left mr-2"></i> My Quizzes
    </a>
</div>

<form method="GET" action="/admin/quiz-library" class="portal-frame !p-5 mb-8">
    <div class="grid grid-cols-1 md:grid-cols-[1fr_190px_auto_auto] gap-4 items-end">
        <div class="form-group">
            <label class="input-label">Search Topic Keywords</label>
            <div class="relative">
                <i class="fas fa-search input-icon"></i>
                <input type="search" name="search" value="{{ $search }}" maxlength="80"
                       placeholder="e.g. fractions, geometry, multiplication"
                       class="input-mobile-ultra">
            </div>
        </div>
        <label class="flex items-center gap-3 min-h-[48px] px-4 rounded border border-white/10 bg-black/20 cursor-pointer">
            <input type="checkbox" name="verified" value="1" class="w-4 h-4 accent-green-500" {{ $verifiedOnly ? 'checked' : '' }}>
            <span class="text-[10px] text-slate-300 uppercase font-bold whitespace-nowrap">Verified only</span>
        </label>
        <div class="form-group">
            <label class="input-label">Grade Level</label>
            <select name="grade" class="input-mobile-ultra !pl-4 bg-slate-900 text-white">
                <option value="">All Grades</option>
                @for($itemGrade = 1; $itemGrade <= 6; $itemGrade++)
                    <option value="{{ $itemGrade }}" {{ $grade === $itemGrade ? 'selected' : '' }}>Grade {{ $itemGrade }}</option>
                @endfor
            </select>
        </div>
        <button type="submit" class="btn-rect-primary !bg-red-600 !text-white !py-3">
            <i class="fas fa-search mr-2"></i> Search
        </button>
    </div>
</form>

<div class="flex justify-between items-center gap-4 mb-5">
    <p class="text-[10px] text-slate-500 uppercase tracking-widest">
        {{ number_format($total) }} {{ Str::plural('quiz', $total) }} found
    </p>
    @if($search !== '' || $grade !== null || $verifiedOnly)
        <a href="/admin/quiz-library"
           class="text-[10px] text-blue-400 uppercase font-bold hover:text-white">Clear Filters</a>
    @endif
</div>

@php $visibleCount = 0; @endphp
@for($groupGrade = 1; $groupGrade <= 6; $groupGrade++)
    @if(!empty($quizzesByGrade[$groupGrade]))
        @php $visibleCount += count($quizzesByGrade[$groupGrade]); @endphp
        <section class="mb-10">
            <div class="flex items-center gap-3 mb-4">
                <span class="w-10 h-10 rounded bg-blue-500/10 border border-blue-500/20 flex items-center justify-center font-orbitron font-bold text-blue-400">
                    {{ $groupGrade }}
                </span>
                <div>
                    <h2 class="font-orbitron font-bold uppercase">Grade {{ $groupGrade }}</h2>
                    <p class="text-[10px] text-slate-500">{{ count($quizzesByGrade[$groupGrade]) }} on this page</p>
                </div>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                @foreach($quizzesByGrade[$groupGrade] as $quiz)
                    <article class="portal-frame !p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4 hover:border-blue-500/50 transition-colors">
                        <div class="min-w-0">
                            <h3 class="font-bold text-lg text-white truncate">{{ $quiz['topic'] }}</h3>
                            <p class="text-[10px] text-slate-500 mt-1">
                                By {{ $quiz['creator_name'] }} · {{ $quiz['question_count'] }} questions
                            </p>
                            <div class="flex flex-wrap items-center gap-2 mt-3 text-[9px] uppercase font-bold">
                                @if(!empty($quiz['verified_at']))
                                    <span class="text-green-400"><i class="fas fa-check-circle mr-1"></i>Verified</span>
                                @else
                                    <span class="text-slate-500"><i class="far fa-circle mr-1"></i>Unverified</span>
                                @endif
                                <span class="text-yellow-400"><i class="fas fa-star mr-1"></i>{{ number_format((float) ($quiz['rating_average'] ?? 0), 1) }} ({{ (int) ($quiz['rating_count'] ?? 0) }})</span>
                                <span class="text-cyan-400"><i class="fas fa-users mr-1"></i>{{ (int) ($quiz['usage_count'] ?? 0) }} class uses</span>
                                <span class="text-slate-500">v{{ (int) ($quiz['version'] ?? 1) }}</span>
                            </div>
                            <p class="text-[9px] text-slate-600 mt-2 uppercase tracking-widest">
                                Updated {{ \App\Support\AppDate::format($quiz['updated_at'] ?? $quiz['created_at'], 'M d, Y') }}
                            </p>
                        </div>
                        <div class="grid grid-cols-2 gap-2 w-full sm:w-auto shrink-0">
                            <a href="/admin/quiz-library/{{ $quiz['id'] }}/review"
                               class="btn-rect-secondary !py-2 !px-4 !w-auto text-center !border-blue-500/30 hover:!text-blue-400">
                                <i class="fas fa-eye mr-2"></i> Review
                            </a>
                            <button type="button" data-action="openDeleteQuizModal" data-action-args="{{ json_encode([$quiz['id'], $quiz['topic']], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}"
                                    class="btn-rect-secondary !py-2 !px-4 !w-auto !border-red-500/30 text-red-400">
                                <i class="fas fa-trash-alt mr-2"></i> Delete
                            </button>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif
@endfor

@if($visibleCount === 0)
    <div class="portal-frame !p-12 text-center">
        <i class="fas fa-search text-4xl text-slate-700 mb-4"></i>
        <p class="text-slate-400 font-bold">No matching shared quizzes found.</p>
        <p class="text-xs text-slate-600 mt-2">Try a different topic keyword or grade.</p>
    </div>
@endif

@if($totalPages > 1)
    @php
        $baseQuery = array_filter([
            'search' => $search ?: null,
            'grade' => $grade,
            'verified' => $verifiedOnly ? 1 : null,
        ], fn($value) => $value !== null && $value !== '');
    @endphp
    <nav class="flex items-center justify-center gap-4 mt-10" aria-label="Admin quiz library pages">
        @if($page > 1)
            <a href="/admin/quiz-library?{{ http_build_query($baseQuery + ['page' => $page - 1]) }}"
               class="btn-rect-secondary !py-2 !px-5 !w-auto"><i class="fas fa-chevron-left mr-2"></i> Previous</a>
        @endif
        <span class="text-[10px] text-slate-500 uppercase tracking-widest">Page {{ $page }} of {{ $totalPages }}</span>
        @if($page < $totalPages)
            <a href="/admin/quiz-library?{{ http_build_query($baseQuery + ['page' => $page + 1]) }}"
               class="btn-rect-secondary !py-2 !px-5 !w-auto">Next <i class="fas fa-chevron-right ml-2"></i></a>
        @endif
    </nav>
@endif
@endsection

@section('modals')
<div id="deleteQuizModal" class="modal-overlay hidden">
    <div class="portal-frame !p-10 w-full max-w-sm text-center border-red-500/50">
        <i class="fas fa-trash-alt text-4xl text-red-500 mb-4"></i>
        <h3 class="font-orbitron font-bold uppercase">Move Shared Quiz to Trash?</h3>
        <p id="delete-quiz-topic" class="text-xs text-slate-400 my-4"></p>
        <p class="text-[10px] text-slate-500 mb-8">Questions, versions, assignments and results are preserved. Only an administrator can restore this removal.</p>
        <form id="deleteQuizForm" method="POST">
            @csrf
            @method('DELETE')
            <input type="hidden" name="return_to" value="library">
            <input type="hidden" name="search" value="{{ $search }}">
            <input type="hidden" name="grade" value="{{ $grade ?? '' }}">
            <input type="hidden" name="page" value="{{ $page }}">
            <input type="hidden" name="verified" value="{{ $verifiedOnly ? 1 : '' }}">
            <button class="btn-rect-primary !bg-red-600 !text-white">Delete Quiz</button>
        </form>
        <button type="button" data-action="closeModal" data-action-args='["deleteQuizModal"]' class="modal-cancel mt-3">Cancel</button>
    </div>
</div>

<div id="logoutModal" class="modal-overlay hidden">
    <div class="portal-frame !p-10 w-full max-w-xs text-center border-red-500/30">
        <i class="fas fa-power-off text-4xl text-red-500 mb-4"></i>
        <h3 class="font-orbitron font-bold mb-6 uppercase">End Admin Session?</h3>
        <form method="POST" action="/logout">@csrf<button class="btn-rect-primary !bg-red-600 !text-white">Confirm Logout</button></form>
        <button type="button" data-action="closeModal" data-action-args='["logoutModal"]' class="modal-cancel mt-3">Cancel</button>
    </div>
</div>
@endsection
