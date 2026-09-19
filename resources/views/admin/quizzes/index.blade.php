@extends('layouts.dashboard')

@section('title', 'Admin VR Quiz Bees')
@section('sidebar-border', 'border-red-500/20')
@section('sidebar-subtitle', 'System Administrator')
@section('sidebar-subtitle-color', 'text-red-500/60')
@section('accent-color', 'text-red-500')
@section('mobile-title', 'My Quizzes')

@section('sidebar-nav')
    @include('admin.partials.sidebar-nav', ['activePage' => 'quizzes'])
@endsection

@section('dashboard-content')
<div id="quiz-list-container">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 mb-8">
        <div>
            <h1 class="text-xl md:text-2xl font-orbitron font-bold uppercase">My VR Quiz <span class="text-purple-400">Bees</span></h1>
            <p class="text-xs text-slate-500 mt-2">Create and manage admin-authored quizzes.</p>
        </div>
        <div class="flex flex-col sm:flex-row gap-3">
            <a href="/admin/quiz-library" class="btn-rect-secondary !py-3 !px-5 text-center sm:!w-auto">
                <i class="fas fa-book-open mr-2"></i> Shared Library
            </a>
            <button type="button" data-action="loadQuizBuilder" class="btn-rect-primary !py-3 sm:!w-auto px-6 !bg-red-600 !text-white">
                <i class="fas fa-plus mr-2"></i> Create Quiz
            </button>
        </div>
    </div>

    <form method="GET" action="/admin/quizzes" class="portal-frame !p-5 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-[1fr_190px_auto] gap-4 items-end">
            <div class="form-group">
                <label class="input-label">Search Topic Keywords</label>
                <div class="relative">
                    <i class="fas fa-search input-icon"></i>
                    <input type="search" name="search" value="{{ $search }}" maxlength="80"
                           placeholder="e.g. fractions, geometry, multiplication" class="input-mobile-ultra">
                </div>
            </div>
            <div class="form-group">
                <label class="input-label">Grade Level</label>
                <select name="grade" class="input-mobile-ultra !pl-4 bg-slate-900 text-white">
                    <option value="">All Grades</option>
                    @for($g = 1; $g <= 6; $g++)
                        <option value="{{ $g }}" {{ $grade === $g ? 'selected' : '' }}>Grade {{ $g }}</option>
                    @endfor
                </select>
            </div>
            <button class="btn-rect-primary !bg-red-600 !text-white !py-3 !px-5"><i class="fas fa-search mr-2"></i> Search</button>
        </div>
    </form>

    <div class="flex justify-between items-center gap-4 mb-5">
        <p class="text-[10px] text-slate-500 uppercase tracking-widest">
            {{ count($quizzes) }} {{ Str::plural('quiz', count($quizzes)) }} found
        </p>
        @if($search !== '' || $grade !== null)
            <a href="/admin/quizzes" class="text-[10px] text-purple-400 uppercase font-bold hover:text-white">Clear Filters</a>
        @endif
    </div>

    <div class="space-y-4">
        @forelse($quizzes as $quiz)
            <article class="portal-frame !p-5 flex flex-col lg:flex-row lg:items-center justify-between gap-5">
                <div class="min-w-0">
                    <h2 class="font-bold text-lg text-white truncate">{{ $quiz['topic'] }}</h2>
                    <div class="flex flex-wrap gap-2 mt-2 text-[10px] font-bold uppercase tracking-widest">
                        <span class="text-purple-300 bg-purple-500/10 px-2 py-1 rounded">Grade {{ $quiz['grade_level'] }}</span>
                        <span class="text-slate-400 bg-white/5 px-2 py-1 rounded">{{ $quiz['question_count'] }} Questions</span>
                        <span class="{{ ($quiz['visibility'] ?? 'shared') === 'shared' ? 'text-blue-300 bg-blue-500/10' : 'text-slate-400 bg-white/5' }} px-2 py-1 rounded">{{ ucfirst($quiz['visibility'] ?? 'shared') }}</span>
                        <span class="text-yellow-300 bg-yellow-500/10 px-2 py-1 rounded">v{{ $quiz['version'] ?? 1 }}</span>
                        <span class="text-slate-500 px-1 py-1">{{ \App\Support\AppDate::format($quiz['created_at'], 'M d, Y') }}</span>
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-2 w-full lg:w-auto">
                    <button type="button" data-action="loadQuizBuilder" data-action-args="{{ json_encode([$quiz['id']], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}"
                            class="btn-rect-secondary !py-2 !px-4 !w-auto !border-purple-500/30 hover:!text-purple-400">
                        <i class="fas fa-edit mr-1"></i> Edit
                    </button>
                    <a href="/admin/quizzes/{{ $quiz['id'] }}/versions"
                       class="btn-rect-secondary !py-2 !px-4 !w-auto !border-cyan-500/30 hover:!text-cyan-400 text-center">
                        <i class="fas fa-history mr-1"></i> History
                    </a>
                    <button type="button" data-action="openDeleteQuizModal" data-action-args="{{ json_encode([$quiz['id'], $quiz['topic']], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}"
                            class="btn-rect-secondary !py-2 !px-4 !w-auto !border-red-500/30 text-red-400">
                        <i class="fas fa-trash-alt mr-1"></i> Delete
                    </button>
                </div>
            </article>
        @empty
            <div class="portal-frame !p-10 text-center text-slate-500 text-xs uppercase tracking-widest">
                {{ $search !== '' || $grade !== null ? 'No quizzes match these filters.' : 'You have not created an admin quiz yet.' }}
            </div>
        @endforelse
    </div>
</div>

<div id="quiz-editor-container" class="hidden">
    <div class="portal-frame !p-6 md:!p-8 relative">
        <button type="button" data-action="toggleQuizView" data-action-args='["list"]' class="absolute top-5 right-5 text-slate-500 hover:text-white"><i class="fas fa-times-circle text-xl"></i></button>
        <h2 id="builder-title" class="text-xl font-orbitron font-bold mb-2 uppercase">Create <span class="text-purple-400">Quiz</span></h2>
        <p class="text-xs text-slate-500 mb-8">Choose private storage or share the quiz with teachers through the library.</p>
        <form id="quiz-form" method="POST" action="/admin/quizzes"
              data-quiz-base-path="/admin/quizzes"
              data-editing-quiz-id="{{ old('editing_quiz_id') }}"
              data-auto-open-builder="{{ $errors->any() ? 'true' : 'false' }}">
            @csrf
            <span id="method-field"></span>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-8">
                <div class="form-group">
                    <label class="input-label">Quiz Topic</label>
                    <input type="text" name="topic" id="q-topic" value="{{ old('topic') }}" maxlength="150" class="input-mobile-ultra !pl-4" required>
                </div>
                <div class="form-group">
                    <label class="input-label">Library Visibility</label>
                    <select name="visibility" id="q-visibility" class="input-mobile-ultra !pl-4 bg-slate-900 text-white" required>
                        <option value="shared" @selected(old('visibility', 'shared') === 'shared')>Shared with teachers</option>
                        <option value="private" @selected(old('visibility') === 'private')>Private</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="input-label">Grade Level</label>
                    <select name="grade_level" id="q-grade" class="input-mobile-ultra !pl-4 bg-slate-900 text-white" required>
                        @for($g = 1; $g <= 6; $g++)<option value="{{ $g }}" @selected((int) old('grade_level', 1) === $g)>Grade {{ $g }}</option>@endfor
                    </select>
                </div>
            </div>
            <div id="questions-builder" class="space-y-6"></div>
            <div class="mt-8 flex flex-col sm:flex-row gap-4">
                <button type="button" data-action="addNewQuestion" class="btn-rect-secondary flex-1"><i class="fas fa-plus mr-2"></i> Add Question</button>
                <button type="submit" id="save-quiz-btn" class="btn-rect-primary flex-1 !bg-red-600 !text-white"><i class="fas fa-save mr-2"></i> Save Quiz</button>
            </div>
        </form>
        @if($errors->any())
            <template data-quiz-question-state>{!! json_encode(old('questions', []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</template>
        @endif
    </div>
</div>
@endsection

@section('modals')
<div id="deleteQuizModal" class="modal-overlay hidden">
    <div class="portal-frame !p-10 w-full max-w-sm text-center border-red-500/50">
        <i class="fas fa-trash-alt text-4xl text-red-500 mb-4"></i>
        <h3 class="font-orbitron font-bold uppercase">Move Your Quiz to Trash?</h3>
        <p id="delete-quiz-topic" class="text-xs text-slate-400 my-4"></p>
        <p class="text-[10px] text-slate-500 mb-8">Questions, versions, assignments and results are preserved. Restore this quiz from Trash and Recovery.</p>
        <form id="deleteQuizForm" method="POST">@csrf @method('DELETE')
            <input type="hidden" name="return_to" value="quizzes">
            <input type="hidden" name="search" value="{{ $search }}">
            <input type="hidden" name="grade" value="{{ $grade ?? '' }}">
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
