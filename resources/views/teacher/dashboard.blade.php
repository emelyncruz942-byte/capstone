@extends('layouts.dashboard')

@section('title', 'Teacher Hub')
@section('sidebar-subtitle', 'Instructional Hub')
@section('mobile-title', 'Teacher Hub')

@section('sidebar-nav')
    @include('teacher.partials.sidebar-nav')
@endsection

@section('dashboard-content')

{{-- ANALYTICS --}}
<section id="sec-stats" class="content-section hidden">
    <h2 class="text-xl md:text-2xl font-orbitron font-bold mb-6 uppercase border-b border-white/10 pb-2">
        Quiz <span class="text-pink-400">Analytics</span>
    </h2>

    <div id="stats-loading" class="flex flex-col items-center justify-center" style="min-height: 70vh;">
        <i class="fas fa-circle-notch fa-spin text-4xl text-cyan-400 mb-4"></i>
        <p class="text-xs uppercase tracking-widest font-orbitron text-slate-500">Fetching Analytics...</p>
    </div>

    <div id="stats-content" class="hidden">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
            <div class="portal-frame !p-5 border-l-2 border-pink-500">
                <p class="text-slate-500 text-[10px] uppercase font-bold tracking-widest">Total Attempts</p>
                <h3 class="text-2xl font-orbitron mt-1" id="stat-total-attempts">—</h3>
            </div>
            <div class="portal-frame !p-5 border-l-2 border-cyan-500">
                <p class="text-slate-500 text-[10px] uppercase font-bold tracking-widest">Avg Accuracy</p>
                <h3 class="text-2xl font-orbitron mt-1" id="stat-avg-accuracy">—</h3>
            </div>
            <div class="portal-frame !p-5 border-l-2 border-purple-500">
                <p class="text-slate-500 text-[10px] uppercase font-bold tracking-widest">Class Assignments</p>
                <h3 class="text-2xl font-orbitron mt-1" id="stat-total-quizzes">—</h3>
            </div>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="portal-frame !p-6">
                <h4 class="font-orbitron text-xs text-cyan-400 uppercase tracking-widest mb-4">
                    <i class="fas fa-chart-line mr-2"></i> Attempts Per Day (14 days)
                </h4>
                <canvas id="chart-attempts" height="200"></canvas>
            </div>
            <div class="portal-frame !p-6">
                <h4 class="font-orbitron text-xs text-pink-400 uppercase tracking-widest mb-4">
                    <i class="fas fa-chart-pie mr-2"></i> Score Distribution
                </h4>
                <canvas id="chart-distribution" height="200"></canvas>
            </div>
        </div>
        <div class="portal-frame !p-6">
            <h4 class="font-orbitron text-xs text-purple-400 uppercase tracking-widest mb-4">
                <i class="fas fa-chart-bar mr-2"></i> Average Accuracy Per Active Class
            </h4>
            <canvas id="chart-class-accuracy" height="120"></canvas>
        </div>
    </div>
</section>

{{-- OVERVIEW --}}
<section id="sec-overview" class="content-section">
    <h2 class="text-xl md:text-2xl font-orbitron font-bold mb-6 uppercase border-b border-white/10 pb-2">
        System <span class="text-cyan-400">Overview</span>
    </h2>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
        <div class="portal-frame !p-5 border-l-2 border-cyan-500 flex justify-between items-center">
            <div>
                <p class="text-slate-500 text-[10px] uppercase font-bold tracking-widest">Total Students</p>
                <h3 class="text-2xl font-orbitron mt-1">{{ $studentCount }}</h3>
            </div>
            <i class="fas fa-user-graduate text-3xl opacity-10"></i>
        </div>
        <div class="portal-frame !p-5 border-l-2 border-purple-500 flex justify-between items-center">
            <div>
                <p class="text-slate-500 text-[10px] uppercase font-bold tracking-widest">Quizzes Created</p>
                <h3 class="text-2xl font-orbitron mt-1">{{ $quizCount }}</h3>
            </div>
            <i class="fas fa-scroll text-3xl opacity-10"></i>
        </div>
        <div class="portal-frame !p-5 border-l-2 border-yellow-500 flex justify-between items-center">
            <div>
                <p class="text-slate-500 text-[10px] uppercase font-bold tracking-widest">My Classes</p>
                <h3 class="text-2xl font-orbitron mt-1">{{ count($classes) }}</h3>
            </div>
            <i class="fas fa-chalkboard text-3xl opacity-10"></i>
        </div>
    </div>

    @php $classNames = array_column($classes, 'class_name', 'id'); @endphp
    <div class="portal-frame !p-5">
        <div class="flex items-center justify-between gap-4 mb-4">
            <h4 class="font-orbitron text-xs text-cyan-400 uppercase tracking-widest">
                <i class="fas fa-history mr-2"></i> Recent Class Quiz Assignments
            </h4>
            <a href="/teacher/quizzes" class="text-[10px] text-purple-400 font-bold uppercase hover:text-white">
                Manage Quizzes
            </a>
        </div>
        <div class="space-y-1">
            @forelse($recentQuizzes as $quiz)
                @php
                    $status = $quiz['status'] ?? 'waiting';
                    $statusLabel = $status === 'completed' ? 'Ended' : ($status === 'active' ? 'Active' : 'Assigned');
                    $statusColor = $status === 'completed' ? 'text-slate-400' : ($status === 'active' ? 'text-green-400' : 'text-yellow-400');
                @endphp
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-white/5 py-3 px-2 rounded hover:bg-white/5">
                    <div>
                        <span class="font-bold text-white block sm:inline">{{ $quiz['topic'] }}</span>
                        <span class="text-[10px] text-slate-500 sm:ml-2 block sm:inline">
                            {{ $classNames[$quiz['class_id']] ?? 'Class' }} ·
                            <span class="{{ $statusColor }} font-bold uppercase">{{ $statusLabel }}</span>
                        </span>
                    </div>
                    <a href="/teacher/classes/{{ $quiz['class_id'] }}"
                       class="btn-rect-secondary !py-2 !px-4 !w-auto text-[10px] text-center">Open Class</a>
                </div>
            @empty
                <p class="text-slate-500 text-xs py-4 text-center">No class quiz assignments yet.</p>
            @endforelse
        </div>
    </div>
</section>

{{-- CLASSROOMS --}}
<section id="sec-classes" class="content-section hidden">
    <div class="flex flex-col sm:flex-row justify-between sm:items-center mb-6 gap-4">
        <div>
            <h2 class="text-xl font-orbitron font-bold uppercase">My <span class="text-yellow-400">Classrooms</span></h2>
            <p class="text-xs text-slate-500 mt-2">Open a class for quizzes, students, analytics, and reports.</p>
        </div>
        <button type="button" data-action="openModal" data-action-args='["createClassModal"]'
                class="btn-rect-primary sm:!w-auto px-6 !bg-yellow-500 !text-black">
            <i class="fas fa-plus mr-2"></i> Create Class
        </button>
    </div>

    @php
        $classIcons = [
            'chalkboard' => 'fa-chalkboard', 'calculator' => 'fa-calculator',
            'rocket' => 'fa-rocket', 'atom' => 'fa-atom',
            'shapes' => 'fa-shapes', 'gamepad' => 'fa-gamepad',
        ];
        $patternStyles = [
            'grid' => 'linear-gradient(rgba(255,255,255,.08) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.08) 1px, transparent 1px); background-size: 22px 22px;',
            'stars' => 'radial-gradient(circle at 20% 30%, rgba(255,255,255,.35) 1px, transparent 2px), radial-gradient(circle at 75% 55%, rgba(255,255,255,.25) 1px, transparent 2px); background-size: 42px 42px;',
            'circuit' => 'linear-gradient(90deg, transparent 48%, rgba(255,255,255,.12) 49%, rgba(255,255,255,.12) 51%, transparent 52%); background-size: 35px 35px;',
            'waves' => 'radial-gradient(ellipse at bottom, rgba(255,255,255,.15), transparent 65%);',
            'plain' => 'none;',
        ];
    @endphp

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-6">
        @forelse($classes as $class)
            @php
                $custom = $class['customization'];
                $color = $custom['theme_color'];
                $icon = $classIcons[$custom['icon']] ?? 'fa-chalkboard';
                $pattern = $patternStyles[$custom['banner_pattern']] ?? $patternStyles['grid'];
            @endphp
            <article class="portal-frame overflow-hidden flex flex-col min-h-[285px]" style="border-color: {{ $color }}66;">
                <div class="relative p-6 min-h-[145px] flex items-end"
                     style="background-color: {{ $color }}22; background-image: {{ $pattern }}">
                    <div class="absolute top-5 right-5 w-12 h-12 rounded-lg flex items-center justify-center text-2xl"
                         style="color: {{ $color }}; background: {{ $color }}22; border: 1px solid {{ $color }}55;">
                        <i class="fas {{ $icon }}"></i>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.2em] mb-2" style="color: {{ $color }};">
                            Grade {{ $class['grade_level'] }}
                        </p>
                        <h3 class="font-orbitron font-bold text-xl text-white">{{ $class['class_name'] }}</h3>
                    </div>
                </div>
                <div class="p-5 mt-auto grid grid-cols-2 gap-3">
                    <a href="/teacher/classes/{{ $class['id'] }}" class="btn-rect-primary !py-3 text-center">
                        <i class="fas fa-arrow-right mr-2"></i> Open Class
                    </a>
                    <a href="/teacher/classes/{{ $class['id'] }}/settings" class="btn-rect-secondary !py-3 text-center">
                        <i class="fas fa-cog mr-2"></i> Settings
                    </a>
                </div>
            </article>
        @empty
            <div class="portal-frame !p-10 text-center text-slate-500 uppercase text-xs tracking-widest sm:col-span-2 xl:col-span-3">
                No classes created yet.
            </div>
        @endforelse
    </div>

    @if(!empty($archivedClasses))
        <div class="mt-10">
            <h3 class="text-sm font-orbitron font-bold uppercase mb-4 text-slate-400"><i class="fas fa-archive mr-2"></i> Archived Classes</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
                @foreach($archivedClasses as $class)
                    <article class="portal-frame !p-5 opacity-75 border-slate-600/40">
                        <p class="text-[9px] text-slate-500 uppercase tracking-widest">Archived · Grade {{ $class['grade_level'] }}</p>
                        <h4 class="font-bold text-lg mt-2">{{ $class['class_name'] }}</h4>
                        <a href="/teacher/classes/{{ $class['id'] }}/settings" class="btn-rect-secondary !py-2 mt-4 block text-center"><i class="fas fa-cog mr-2"></i> Settings</a>
                    </article>
                @endforeach
            </div>
        </div>
    @endif
</section>

{{-- ACCOUNT SECURITY --}}
@include('partials.account-security')

{{-- PROFILE --}}
<section id="sec-profile" class="content-section hidden">
    <div class="portal-frame !p-6 md:!p-10">
        <h2 class="text-xl font-orbitron font-bold mb-10 uppercase">
            <i class="fas fa-user-circle mr-2"></i> Account <span class="text-cyan-400">Profile</span>
        </h2>
        <form method="POST" action="/teacher/profile" class="space-y-6 max-w-2xl mx-auto" enctype="multipart/form-data">
            @csrf
            <div class="form-group">
                <label class="input-label">Profile Picture</label>
                <div class="flex items-center gap-4">
                    <div class="w-16 h-16 rounded-full border-2 border-white/10 bg-white/5 overflow-hidden shrink-0" data-avatar-preview-wrap>
                        <img id="avatar-preview" data-avatar-preview src="{{ $user['avatar_url'] ?: asset('default.png') }}" class="w-full h-full object-cover">
                    </div>
                    <div class="flex-1">
                        <label for="avatar-input" class="cursor-pointer block w-full text-center border border-white/10 bg-white/5 hover:bg-white/10 rounded px-4 py-3 text-[10px] font-bold uppercase tracking-widest text-slate-400 hover:text-white">
                            <i class="fas fa-upload mr-2"></i> Choose Photo
                        </label>
                        <input type="file" id="avatar-input" name="avatar" accept="image/jpeg,image/png,image/webp" class="hidden">
                        <p class="text-[9px] text-slate-600 mt-1 text-center">JPG, PNG · 2 MB or less</p>
                    </div>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <div class="form-group">
                    <label class="input-label">First Name</label>
                    <div class="relative">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text" name="first_name" value="{{ $user['first_name'] ?? '' }}" class="input-mobile-ultra" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="input-label">Last Name</label>
                    <div class="relative">
                        <i class="fas fa-id-card input-icon"></i>
                        <input type="text" name="last_name" value="{{ $user['last_name'] ?? '' }}" class="input-mobile-ultra" required>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn-rect-primary mt-4"><i class="fas fa-save mr-2"></i> Update Profile</button>
        </form>
    </div>
</section>

@endsection

@section('modals')
<div id="createClassModal" class="modal-overlay hidden">
    <div class="portal-frame !p-8 w-full max-w-md text-left border-yellow-500/30">
        <div class="text-center mb-6">
            <i class="fas fa-chalkboard text-4xl text-yellow-400 mb-4"></i>
            <h3 class="font-orbitron font-bold uppercase text-white">Create <span class="text-yellow-400">Class</span></h3>
        </div>
        <form method="POST" action="/teacher/classes" class="space-y-5">
            @csrf
            <div class="form-group">
                <label class="input-label">Class Name</label>
                <input type="text" name="class_name" placeholder="e.g. Mathematics 6" class="input-mobile-ultra !pl-4" maxlength="100" required>
            </div>
            <div class="form-group">
                <label class="input-label">Grade Level</label>
                <select name="grade_level" class="input-mobile-ultra !pl-4 bg-slate-900 text-white" required>
                    @for($grade = 1; $grade <= 6; $grade++)
                        <option value="{{ $grade }}">Grade {{ $grade }}</option>
                    @endfor
                </select>
            </div>
            <p class="text-[10px] text-yellow-400/80">Only students with the same profile grade can join.</p>
            <button type="submit" class="btn-rect-primary !bg-yellow-500 !text-black uppercase text-xs">Create & Generate Code</button>
        </form>
        <button type="button" data-action="closeModal" data-action-args='["createClassModal"]' class="modal-cancel mt-3">Cancel</button>
    </div>
</div>

@include('teacher.partials.logout-modal')
@endsection
