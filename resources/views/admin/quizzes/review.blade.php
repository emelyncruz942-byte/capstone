@extends('layouts.dashboard')

@section('title', $quiz['topic'] . ' Review')
@section('sidebar-border', 'border-red-500/20')
@section('sidebar-subtitle', 'System Administrator')
@section('sidebar-subtitle-color', 'text-red-500/60')
@section('accent-color', 'text-red-500')
@section('mobile-title', 'Quiz Review')

@section('sidebar-nav')
    @include('admin.partials.sidebar-nav', ['activePage' => $reportContext ? 'quiz-reports' : 'library'])
@endsection

@section('dashboard-content')
@php
    $backUrl = $reportContext
        ? '/admin/quiz-reports/' . $reportContext['id']
        : ($isOwnQuiz ? '/admin/quizzes' : '/admin/quiz-library');
    $formAction = $isOwnQuiz
        ? '/admin/quizzes/' . $quiz['id']
        : '/admin/quiz-library/' . $quiz['id'];
    $reportContextIsActive = $reportContext
        && ($reportContext['status'] ?? 'pending') === 'pending';
@endphp
<a href="{{ $backUrl }}"
   class="inline-block text-xs text-slate-400 hover:text-white font-bold uppercase mb-6">
    <i class="fas fa-arrow-left mr-2"></i> {{ $reportContext ? 'Back to Quiz Report' : 'Back to Shared Library' }}
</a>

<header class="portal-frame !p-6 md:!p-8 mb-7 border-l-4 border-blue-500">
    <p class="text-[10px] text-blue-400 uppercase tracking-widest font-bold">Administrator review and editing</p>
    <h1 class="text-2xl font-orbitron font-bold mt-2">{{ $quiz['topic'] }}</h1>
    <div class="flex flex-wrap gap-3 mt-5 text-[10px] font-bold uppercase tracking-widest">
        <span class="px-3 py-2 rounded bg-blue-500/10 text-blue-300">Grade {{ $quiz['grade_level'] }}</span>
        <span class="px-3 py-2 rounded bg-white/5 text-slate-400">{{ count($questions) }} Questions</span>
        <span class="px-3 py-2 text-slate-500">By {{ $creatorName }}</span>
        <span class="px-3 py-2 rounded bg-yellow-500/10 text-yellow-400"><i class="fas fa-star mr-1"></i>{{ number_format((float) ($quiz['rating_average'] ?? 0), 1) }} ({{ (int) ($quiz['rating_count'] ?? 0) }})</span>
        <span class="px-3 py-2 rounded bg-cyan-500/10 text-cyan-400">{{ (int) ($quiz['usage_count'] ?? 0) }} class uses</span>
        <span class="px-3 py-2 rounded bg-white/5 text-slate-400">v{{ (int) ($quiz['version'] ?? 1) }}</span>
    </div>
    <div class="flex flex-col sm:flex-row gap-3 mt-5">
        @if($isOwnQuiz)
            <span class="btn-rect-secondary !py-2 !px-4 md:!w-auto text-green-400 !border-green-500/40 text-center">
                <i class="fas fa-check-circle mr-2"></i>Admin Verified
            </span>
        @else
            <form method="POST" action="/admin/quiz-library/{{ $quiz['id'] }}/verify">
                @csrf
                <button type="submit" class="btn-rect-secondary !py-2 !px-4 md:!w-auto {{ !empty($quiz['verified_at']) ? 'text-green-400 !border-green-500/40' : '' }}">
                    <i class="fas fa-check-circle mr-2"></i>{{ !empty($quiz['verified_at']) ? 'Remove Verification' : 'Mark as Verified' }}
                </button>
            </form>
        @endif
        <a href="/admin/quizzes/{{ $quiz['id'] }}/versions"
           class="btn-rect-secondary !py-2 !px-4 md:!w-auto text-center">
            <i class="fas fa-history mr-2"></i>Version History
        </a>
    </div>
</header>

@if($reportContext)
    <section class="portal-frame !p-5 md:!p-6 mb-7 border-red-500/30 bg-red-500/5">
        <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
            <div>
                <p class="text-[9px] text-red-400 uppercase font-black tracking-widest">
                    Editing from {{ $reportContextIsActive ? 'active' : ($reportContext['status'] ?? 'handled') }} quiz report
                </p>
                <h2 class="font-bold text-white mt-2">{{ ucwords(str_replace('_', ' ', $reportContext['reason'] ?? 'other')) }}</h2>
                @if(!empty($reportContext['question_label']))
                    <p class="text-[10px] text-cyan-300 mt-2">{{ $reportContext['question_label'] }}</p>
                @endif
                <p class="text-xs text-slate-300 mt-3">{{ $reportContext['details'] ?: 'No additional details provided.' }}</p>
            </div>
            <span class="text-[9px] text-slate-500 uppercase shrink-0">By {{ $reportContext['reporter_name'] }}</span>
        </div>
        <p class="text-[10px] text-slate-500 mt-4">
            {{ $reportContextIsActive
                ? 'Saving this edit marks only this report as reviewed and opens the next active report. Other reports stay in the queue.'
                : 'Saving updates the quiz, while this already-handled report keeps its current status.' }}
        </p>
    </section>
@endif

@php $initialQuestions = old('questions', $questionsForForm); @endphp
<form id="admin-review-quiz-form" method="POST" action="{{ $formAction }}"
      data-seamless-refresh="manual" class="space-y-7">
    @csrf
    @method('PUT')
    <input type="hidden" name="visibility" value="{{ $isOwnQuiz ? ($quiz['visibility'] ?? 'shared') : 'shared' }}">
    @if($reportContext)
        <input type="hidden" name="return_to" value="reports">
        <input type="hidden" name="report_id" value="{{ $reportContext['id'] }}">
    @endif

    <section class="portal-frame !p-6 md:!p-8">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div class="form-group">
                <label class="input-label">Quiz Topic</label>
                <div class="relative">
                    <i class="fas fa-tag input-icon"></i>
                    <input id="q-topic" type="text" name="topic" maxlength="150"
                           value="{{ old('topic', $quiz['topic']) }}" class="input-mobile-ultra" required>
                </div>
            </div>
            <div class="form-group">
                <label class="input-label">Grade Level</label>
                <select id="q-grade" name="grade_level" class="input-mobile-ultra !pl-4 bg-slate-900 text-white" required>
                    @for($gradeOption = 1; $gradeOption <= 6; $gradeOption++)
                        <option value="{{ $gradeOption }}" {{ (int) old('grade_level', $quiz['grade_level']) === $gradeOption ? 'selected' : '' }}>Grade {{ $gradeOption }}</option>
                    @endfor
                </select>
            </div>
        </div>
    </section>

    <section class="portal-frame !p-6 md:!p-8">
        <div class="mb-6">
            <h2 class="font-orbitron font-bold uppercase">Edit <span class="text-purple-400">Questions</span></h2>
            <p class="text-[10px] text-slate-500 mt-1">Existing class assignments keep their original question snapshots.</p>
        </div>
        <div id="questions-builder" class="space-y-6"></div>
        <div class="mt-6 flex justify-end">
            <button type="button" data-action="addNewQuestion" class="btn-rect-secondary !py-2 !px-4 sm:!w-auto">
                <i class="fas fa-plus mr-2"></i>Add Question
            </button>
        </div>
    </section>

    <div class="flex flex-col sm:flex-row justify-end gap-3">
        <a href="{{ $backUrl }}" class="btn-rect-secondary !py-3 !px-6 sm:!w-auto text-center">Cancel</a>
        <button type="button" data-action="openModal" data-action-args='["confirmAdminReviewEditModal"]' class="btn-rect-primary !bg-red-600 !text-white !py-3 !px-6 sm:!w-auto">
            <i class="fas fa-save mr-2"></i>Save Quiz Changes
        </button>
    </div>
</form>
<template data-quiz-question-state>{!! json_encode($initialQuestions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</template>
@endsection

@section('modals')
<div id="confirmAdminReviewEditModal" class="modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="confirm-admin-edit-title">
    <div class="portal-frame !p-9 w-full max-w-sm text-center border-red-500/40">
        <i class="fas fa-edit text-4xl text-red-400 mb-4"></i>
        <h3 id="confirm-admin-edit-title" class="font-orbitron font-bold uppercase">Update Reported Quiz?</h3>
        <p class="text-xs text-slate-400 my-5">This updates {{ $isOwnQuiz ? 'your admin quiz' : 'the teacher quiz' }} and creates a restorable version. Existing classroom assignments are unchanged.</p>
        <button type="button" data-action="closeModalAndSubmit" data-action-args='["confirmAdminReviewEditModal","admin-review-quiz-form"]' class="btn-rect-primary !bg-red-600 !text-white">Save Changes</button>
        <button type="button" data-action="closeModal" data-action-args='["confirmAdminReviewEditModal"]' class="text-[10px] font-bold mt-4 uppercase text-slate-500">Review Again</button>
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
