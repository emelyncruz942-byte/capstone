@extends('layouts.dashboard')

@section('title', $quiz['topic'] . ' Review')
@section('sidebar-subtitle', 'Instructional Hub')
@section('mobile-title', 'Quiz Review')

@section('sidebar-nav')
    @include('teacher.partials.sidebar-nav', ['activePage' => 'library'])
@endsection

@section('dashboard-content')
<button type="button" data-action="openModal" data-action-args='["cancelSharedQuizModal"]'
   class="inline-block text-xs text-slate-400 hover:text-white font-bold uppercase mb-6">
    <i class="fas fa-arrow-left mr-2"></i> Back to Shared Library
</button>

<header class="portal-frame !p-6 md:!p-8 mb-7 border-l-4 border-blue-500">
    <p class="text-[10px] text-blue-400 uppercase tracking-widest font-bold">Shared by {{ $creatorName }}</p>
    <h1 class="text-2xl font-orbitron font-bold mt-2">Review and assign this quiz</h1>
    <p class="text-xs text-slate-400 mt-3">Adjust the questions for this assignment and select one or more matching classes. The shared original and every selected class grade stay unchanged.</p>
    <div class="flex flex-wrap items-center gap-3 mt-5 text-[10px] uppercase font-bold">
        @if(!empty($quiz['verified_at']))
            <span class="px-3 py-2 rounded bg-green-500/10 text-green-400"><i class="fas fa-check-circle mr-1"></i>Admin Verified</span>
        @endif
        <span class="px-3 py-2 rounded bg-yellow-500/10 text-yellow-400"><i class="fas fa-star mr-1"></i>{{ number_format((float) ($quiz['rating_average'] ?? 0), 1) }} from {{ (int) ($quiz['rating_count'] ?? 0) }}</span>
        <span class="px-3 py-2 rounded bg-cyan-500/10 text-cyan-400"><i class="fas fa-users mr-1"></i>{{ (int) ($quiz['usage_count'] ?? 0) }} class uses</span>
        <span class="px-3 py-2 rounded bg-white/5 text-slate-400">Version {{ (int) ($quiz['version'] ?? 1) }}</span>
    </div>
    <div class="flex flex-col lg:flex-row gap-3 mt-5">
        <form method="POST" action="/teacher/quiz-library/{{ $quiz['id'] }}/bookmark">
            @csrf
            <button type="submit" class="btn-rect-secondary !py-2 !px-4 lg:!w-auto {{ $isBookmarked ? 'text-yellow-400 !border-yellow-500/40' : '' }}">
                <i class="{{ $isBookmarked ? 'fas' : 'far' }} fa-bookmark mr-2"></i>{{ $isBookmarked ? 'Remove Bookmark' : 'Bookmark' }}
            </button>
        </form>
        <form method="POST" action="/teacher/quiz-library/{{ $quiz['id'] }}/rating" class="flex gap-2">
            @csrf
            <label for="quiz-rating" class="sr-only">Your rating</label>
            <select id="quiz-rating" name="rating" required class="input-field !py-2 !px-3 min-w-[140px]">
                <option value="">Rate quiz</option>
                @for($rating = 5; $rating >= 1; $rating--)
                    <option value="{{ $rating }}" {{ (int) $userRating === $rating ? 'selected' : '' }}>{{ $rating }} {{ Str::plural('star', $rating) }}</option>
                @endfor
            </select>
            <button type="submit" class="btn-rect-secondary !py-2 !px-4 !w-auto">Save Rating</button>
        </form>
        <button type="button" data-action="openModal" data-action-args='["reportSharedQuizModal"]' class="btn-rect-secondary !py-2 !px-4 lg:!w-auto text-red-400 !border-red-500/30">
            <i class="fas fa-flag mr-2"></i>Report an Issue
        </button>
    </div>
</header>

@php
    $selectedClassIds = old('class_ids', $preferredClassId ? [$preferredClassId] : []);
    $initialQuestions = old('questions', $questionsForForm);
@endphp

<form id="shared-quiz-assignment-form" method="POST"
      action="/teacher/quiz-library/{{ $quiz['id'] }}/assign"
      data-quiz-grade="{{ (int) $quiz['grade_level'] }}"
      data-seamless-refresh="manual" class="space-y-7">
    @csrf

    <section class="portal-frame !p-6 md:!p-8">
        <h2 class="font-orbitron font-bold uppercase mb-6">Assignment <span class="text-purple-400">Content</span></h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div class="form-group">
                <label class="input-label">Quiz Topic</label>
                <div class="relative">
                    <i class="fas fa-tag input-icon"></i>
                    <input type="text" name="topic" id="q-topic" maxlength="150"
                           value="{{ old('topic', $quiz['topic']) }}" class="input-mobile-ultra" required>
                </div>
            </div>
            <div class="rounded border border-purple-500/20 bg-purple-500/5 p-4 self-end">
                <p class="text-[9px] text-purple-300 uppercase font-black tracking-widest">Original Quiz Grade</p>
                <p class="font-orbitron text-lg font-black text-white mt-1">Grade {{ $quiz['grade_level'] }}</p>
                <p class="text-[10px] text-slate-500 mt-2">This grade was chosen by the quiz creator and is used for every assignment.</p>
            </div>
        </div>
    </section>

    <section class="portal-frame !p-6 md:!p-8">
        <div class="mb-6">
            <h2 class="font-orbitron font-bold uppercase">Questions</h2>
            <p class="text-[10px] text-slate-500 mt-1">Changes apply only to the new class assignments.</p>
        </div>
        <div id="questions-builder" class="space-y-6"></div>
        <div class="mt-6 flex justify-end">
            <button type="button" data-action="addNewQuestion"
                    class="btn-rect-secondary !py-2 !px-4 sm:!w-auto">
                <i class="fas fa-plus mr-2"></i> Add Question
            </button>
        </div>
    </section>

    <section class="portal-frame !p-6 md:!p-8">
        <div class="mb-6">
            <h2 class="font-orbitron font-bold uppercase">Assign to <span class="text-yellow-400">Classes</span></h2>
            <p class="text-[10px] text-slate-500 mt-1">Only active Grade {{ $quiz['grade_level'] }} classes can receive this quiz. Assigning never changes class settings.</p>
        </div>

        <div id="review-class-list" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            @foreach($classes as $class)
                <label data-class-option data-grade="{{ $class['grade_level'] }}" data-class-name="{{ $class['class_name'] }}"
                       class="flex items-center gap-3 p-4 rounded border border-white/10 bg-black/30 cursor-pointer hover:border-yellow-500/40 transition-colors">
                    <input type="checkbox" name="class_ids[]" value="{{ $class['id'] }}"
                           {{ in_array($class['id'], $selectedClassIds, true) ? 'checked' : '' }}
                           class="w-4 h-4 accent-yellow-500">
                    <span class="min-w-0">
                        <span class="block text-sm font-bold text-white truncate">{{ $class['class_name'] }}</span>
                        <span class="block text-[10px] text-slate-500 uppercase">Grade {{ $class['grade_level'] }}</span>
                    </span>
                </label>
            @endforeach
        </div>
        <p id="review-no-matching-class" class="hidden text-xs text-yellow-400 text-center py-6">
            You do not have an active Grade {{ $quiz['grade_level'] }} class. Create a matching class before assigning.
        </p>

        <div id="review-time-limit" class="form-group mt-6 max-w-sm transition-opacity">
            <label class="input-label">Time Limit Per Question</label>
            <div class="relative">
                <i class="fas fa-stopwatch input-icon"></i>
                <input type="number" id="review-time-limit-input" name="time_limit" value="{{ old('time_limit', 20) }}"
                       min="5" max="300" class="input-mobile-ultra" required>
            </div>
            <p class="text-[9px] text-slate-500 mt-1">5–300 seconds for every selected class.</p>
        </div>
        <div id="review-schedule" class="space-y-5 mt-6 transition-opacity max-w-xl">
            <div class="form-group">
                <label class="input-label">Start Date <span class="text-slate-600">(Optional)</span></label>
                <input type="datetime-local" name="available_at" value="{{ old('available_at') }}"
                       class="input-mobile-ultra !pl-4">
                <p class="text-[9px] text-slate-500 mt-2">If set, the assignment starts automatically at this time. If blank, start it manually from the classroom.</p>
            </div>
            <div class="form-group">
                <label class="input-label">Due Date <span class="text-slate-600">(Optional)</span></label>
                <input type="datetime-local" name="due_at" value="{{ old('due_at') }}"
                       class="input-mobile-ultra !pl-4">
                <p class="text-[9px] text-slate-500 mt-2">If set, the assignment ends automatically at this time. If blank, end it manually from the classroom.</p>
            </div>
        </div>
    </section>

    <div class="flex flex-col sm:flex-row justify-end gap-3">
        <button type="button" data-action="openModal" data-action-args='["cancelSharedQuizModal"]'
                class="btn-rect-secondary !py-3 !px-6 sm:!w-auto text-center">Cancel</button>
        <button type="submit" id="shared-assign-submit"
                class="btn-rect-primary !py-3 !px-6 sm:!w-auto">
            <i class="fas fa-paper-plane mr-2"></i><span id="shared-assign-label">Assign to Classes</span>
        </button>
    </div>
</form>
<template data-quiz-question-state>{!! json_encode($initialQuestions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</template>
@endsection

@section('modals')
    <div id="reportSharedQuizModal" class="modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="report-shared-quiz-title">
        <div class="portal-frame !p-8 w-full max-w-md text-left border-red-500/40">
            <div class="flex justify-between items-start gap-4 mb-6">
                <div>
                    <h3 id="report-shared-quiz-title" class="font-orbitron font-bold uppercase">Report Quiz Issue</h3>
                    <p class="text-xs text-slate-400 mt-2">An administrator will review your report.</p>
                </div>
                <button type="button" data-action="closeModal" data-action-args='["reportSharedQuizModal"]' aria-label="Close" class="text-slate-500 hover:text-white"><i class="fas fa-times"></i></button>
            </div>
            <form method="POST" action="/teacher/quiz-library/{{ $quiz['id'] }}/report" class="space-y-5">
                @csrf
                <div>
                    <label for="report-reason" class="input-label">Issue Type</label>
                    <select id="report-reason" name="reason" required class="input-field w-full">
                        <option value="incorrect_answer">Incorrect answer</option>
                        <option value="unclear_question">Unclear question</option>
                        <option value="inappropriate">Inappropriate content</option>
                        <option value="duplicate">Duplicate quiz</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div>
                    <label for="report-question" class="input-label">Affected Question <span class="text-slate-600">(Optional)</span></label>
                    <select id="report-question" name="question_id" class="input-field w-full">
                        <option value="">Whole quiz / not question-specific</option>
                        @foreach($reportQuestions as $reportQuestion)
                            <option value="{{ $reportQuestion['id'] }}">Question {{ $reportQuestion['position'] }} — {{ Str::limit($reportQuestion['question'], 65) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="report-details" class="input-label">Details</label>
                    <textarea id="report-details" name="details" rows="4" maxlength="1000" class="input-field w-full" placeholder="Include the question number and what appears incorrect."></textarea>
                </div>
                <div class="modal-action-stack">
                    <button type="submit" class="btn-rect-primary !bg-red-600 !text-white">Submit Report</button>
                    <button type="button" data-action="closeModal" data-action-args='["reportSharedQuizModal"]' class="modal-cancel">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="cancelSharedQuizModal" class="modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="cancel-shared-quiz-title">
        <div class="portal-frame !p-8 w-full max-w-sm text-center border-red-500/40">
            <i class="fas fa-exclamation-triangle text-4xl text-red-400 mb-4"></i>
            <h3 id="cancel-shared-quiz-title" class="font-orbitron font-bold uppercase text-white">Discard Changes?</h3>
            <p class="text-xs text-slate-400 my-5">Your assignment edits have not been saved.</p>
            <div class="flex flex-col gap-3">
                <a href="/teacher/quiz-library{{ $preferredClassId ? '?class_id=' . $preferredClassId : '' }}"
                   class="btn-rect-primary !bg-red-600 !text-white text-center">
                    <i class="fas fa-trash-alt mr-2"></i> Discard and Leave
                </a>
                <button type="button" data-action="closeModal" data-action-args='["cancelSharedQuizModal"]'
                        class="btn-rect-secondary">Keep Editing</button>
            </div>
        </div>
    </div>

    <div id="confirmSharedQuizModal" class="modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="confirm-shared-quiz-title">
        <div class="portal-frame !p-8 w-full max-w-md text-center border-purple-500/40">
            <i class="fas fa-clipboard-check text-4xl text-purple-400 mb-4"></i>
            <h3 id="confirm-shared-quiz-title" class="font-orbitron font-bold uppercase text-white">Confirm Class Assignment</h3>
            <p id="confirm-shared-quiz-summary" class="text-xs text-slate-300 mt-4"></p>

            <div class="bg-black/40 border border-white/10 rounded p-4 my-5 text-left">
                <p id="confirm-shared-quiz-meta" class="text-[10px] text-purple-300 uppercase font-bold tracking-wider"></p>
                <div id="confirm-shared-quiz-classes" class="hidden mt-4">
                    <p class="text-[9px] text-slate-500 uppercase font-bold mb-2">Selected Classes</p>
                    <ul id="confirm-shared-quiz-class-list" class="space-y-2 text-xs text-white"></ul>
                </div>
            </div>

            <div class="flex flex-col gap-3">
                <button type="button" id="confirm-shared-quiz-submit"
                        class="btn-rect-primary !bg-purple-600 !text-white">
                    <i class="fas fa-check-circle mr-2"></i><span id="confirm-shared-quiz-button-label">Assign to Classes</span>
                </button>
                <button type="button" data-action="closeModal" data-action-args='["confirmSharedQuizModal"]'
                        class="btn-rect-secondary">Review Again</button>
            </div>
        </div>
    </div>

    @include('teacher.partials.logout-modal')
@endsection
