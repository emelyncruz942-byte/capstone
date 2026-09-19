@extends('layouts.dashboard')

@section('title', 'Shared Quiz Library')
@section('sidebar-subtitle', 'Instructional Hub')
@section('mobile-title', 'Quiz Library')

@section('sidebar-nav')
    @include('teacher.partials.sidebar-nav', ['activePage' => 'library'])
@endsection

@section('dashboard-content')
<div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 mb-8">
    <div>
        <h2 class="text-xl md:text-2xl font-orbitron font-bold uppercase">
            Shared Quiz <span class="text-blue-400">Library</span>
        </h2>
        <p class="text-xs text-slate-500 mt-2">Search reusable quizzes created by other MathVerse teachers.</p>
    </div>
    <a href="/teacher/quizzes{{ $preferredClassId ? '?class_id=' . $preferredClassId : '' }}"
       class="btn-rect-secondary !py-3 !px-5 text-center lg:!w-auto">
        <i class="fas fa-arrow-left mr-2"></i> My Quizzes
    </a>
</div>

<form method="GET" action="/teacher/quiz-library" class="portal-frame !p-5 mb-8">
    @if($preferredClassId)
        <input type="hidden" name="class_id" value="{{ $preferredClassId }}">
    @endif
    <div class="grid grid-cols-1 md:grid-cols-[1fr_190px_auto_auto_auto] gap-4 items-end">
        <div class="form-group">
            <label class="input-label">Search Topic Keywords</label>
            <div class="relative">
                <i class="fas fa-search input-icon"></i>
                <input type="search" name="search" value="{{ $search }}" maxlength="80"
                       placeholder="e.g. fractions, geometry, multiplication"
                       class="input-mobile-ultra">
            </div>
        </div>
        <div class="form-group">
            <label class="input-label">Grade Level</label>
            <select name="grade" class="input-mobile-ultra !pl-4 bg-slate-900 text-white">
                <option value="">All Grades</option>
                @for($itemGrade = 1; $itemGrade <= 6; $itemGrade++)
                    <option value="{{ $itemGrade }}" {{ $grade === $itemGrade ? 'selected' : '' }}>Grade {{ $itemGrade }}</option>
                @endfor
            </select>
        </div>
        <label class="flex items-center gap-3 min-h-[48px] px-4 rounded border border-white/10 bg-black/20 cursor-pointer">
            <input type="checkbox" name="bookmarked" value="1" class="w-4 h-4 accent-blue-500" {{ $bookmarkedOnly ? 'checked' : '' }}>
            <span class="text-[10px] text-slate-300 uppercase font-bold whitespace-nowrap">Bookmarked only</span>
        </label>
        <label class="flex items-center gap-3 min-h-[48px] px-4 rounded border border-white/10 bg-black/20 cursor-pointer">
            <input type="checkbox" name="verified" value="1" class="w-4 h-4 accent-green-500" {{ $verifiedOnly ? 'checked' : '' }}>
            <span class="text-[10px] text-slate-300 uppercase font-bold whitespace-nowrap">Verified only</span>
        </label>
        <button type="submit" class="btn-rect-primary !py-3 md:mb-0">
            <i class="fas fa-search mr-2"></i> Search
        </button>
    </div>
</form>

<div class="flex justify-between items-center gap-4 mb-5">
    <p class="text-[10px] text-slate-500 uppercase tracking-widest">
        {{ number_format($total) }} {{ Str::plural('quiz', $total) }} found
    </p>
    @if($search !== '' || $grade !== null || $bookmarkedOnly || $verifiedOnly)
        <a href="/teacher/quiz-library{{ $preferredClassId ? '?class_id=' . $preferredClassId : '' }}"
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
                    <h3 class="font-orbitron font-bold uppercase">Grade {{ $groupGrade }}</h3>
                    <p class="text-[10px] text-slate-500">{{ count($quizzesByGrade[$groupGrade]) }} on this page</p>
                </div>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                @foreach($quizzesByGrade[$groupGrade] as $quiz)
                    <article class="portal-frame !p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4 hover:border-blue-500/50 transition-colors">
                        <div class="min-w-0">
                            <h4 class="font-bold text-lg text-white truncate">{{ $quiz['topic'] }}</h4>
                            <p class="text-[10px] text-slate-500 mt-1">
                                By {{ $quiz['creator_name'] }} · {{ $quiz['question_count'] }} questions
                            </p>
                            <div class="flex flex-wrap items-center gap-2 mt-3 text-[9px] uppercase font-bold">
                                @if(!empty($quiz['verified_at']))
                                    <span class="text-green-400"><i class="fas fa-check-circle mr-1"></i>Verified</span>
                                @endif
                                <span class="text-yellow-400"><i class="fas fa-star mr-1"></i>{{ number_format((float) ($quiz['rating_average'] ?? 0), 1) }} ({{ (int) ($quiz['rating_count'] ?? 0) }})</span>
                                <span class="text-cyan-400"><i class="fas fa-users mr-1"></i>{{ (int) ($quiz['usage_count'] ?? 0) }} class uses</span>
                                <span class="text-slate-500">v{{ (int) ($quiz['version'] ?? 1) }}</span>
                            </div>
                            <p class="text-[9px] text-slate-600 mt-2 uppercase tracking-widest">
                                Updated {{ \App\Support\AppDate::format($quiz['updated_at'] ?? $quiz['created_at'], 'M d, Y') }}
                            </p>
                        </div>
                        <div class="flex gap-2 shrink-0">
                            <form method="POST" action="/teacher/quiz-library/{{ $quiz['id'] }}/bookmark">
                                @csrf
                                <button type="submit" class="btn-rect-secondary !py-2 !px-3 !w-auto {{ $quiz['bookmarked'] ? 'text-yellow-400 !border-yellow-500/40' : '' }}"
                                        aria-label="{{ $quiz['bookmarked'] ? 'Remove bookmark' : 'Bookmark quiz' }}" title="{{ $quiz['bookmarked'] ? 'Remove bookmark' : 'Bookmark quiz' }}">
                                    <i class="{{ $quiz['bookmarked'] ? 'fas' : 'far' }} fa-bookmark"></i>
                                </button>
                            </form>
                            <a href="/teacher/quiz-library/{{ $quiz['id'] }}/review{{ $preferredClassId ? '?class_id=' . $preferredClassId : '' }}"
                               class="btn-rect-primary !py-2 !px-4 sm:!w-auto text-center">
                                <i class="fas fa-eye mr-2"></i> Review
                            </a>
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
        <p class="text-slate-400 font-bold">No matching quizzes found.</p>
        <p class="text-xs text-slate-600 mt-2">Try a different topic keyword or grade.</p>
    </div>
@endif

@if($totalPages > 1)
    @php
        $baseQuery = array_filter([
            'search' => $search ?: null,
            'grade' => $grade,
            'class_id' => $preferredClassId,
            'bookmarked' => $bookmarkedOnly ? 1 : null,
            'verified' => $verifiedOnly ? 1 : null,
        ], fn($value) => $value !== null && $value !== '');
    @endphp
    <nav class="flex items-center justify-center gap-4 mt-10" aria-label="Quiz library pages">
        @if($page > 1)
            <a href="/teacher/quiz-library?{{ http_build_query($baseQuery + ['page' => $page - 1]) }}"
               class="btn-rect-secondary !py-2 !px-5 !w-auto"><i class="fas fa-chevron-left mr-2"></i> Previous</a>
        @endif
        <span class="text-[10px] text-slate-500 uppercase tracking-widest">Page {{ $page }} of {{ $totalPages }}</span>
        @if($page < $totalPages)
            <a href="/teacher/quiz-library?{{ http_build_query($baseQuery + ['page' => $page + 1]) }}"
               class="btn-rect-secondary !py-2 !px-5 !w-auto">Next <i class="fas fa-chevron-right ml-2"></i></a>
        @endif
    </nav>
@endif
@endsection

@section('modals')
    @include('teacher.partials.logout-modal')
@endsection
