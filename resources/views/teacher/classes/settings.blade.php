@extends('layouts.dashboard')

@section('title', $class['class_name'] . ' Settings')
@section('sidebar-subtitle', 'Instructional Hub')
@section('mobile-title', 'Class Settings')

@section('sidebar-nav')
    @include('teacher.partials.sidebar-nav', ['activePage' => 'classes'])
@endsection

@section('dashboard-content')
@php
    $iconLabels = [
        'chalkboard' => ['Chalkboard', 'fa-chalkboard'], 'calculator' => ['Calculator', 'fa-calculator'],
        'rocket' => ['Rocket', 'fa-rocket'], 'atom' => ['Atom', 'fa-atom'],
        'shapes' => ['Shapes', 'fa-shapes'], 'gamepad' => ['Gamepad', 'fa-gamepad'],
    ];
    $patternLabels = ['grid' => 'Grid', 'stars' => 'Stars', 'circuit' => 'Circuit', 'waves' => 'Waves', 'plain' => 'Plain'];
@endphp

<div class="flex items-center justify-between gap-4 mb-8">
    <div>
        <a href="/teacher/classes/{{ $class['id'] }}" class="text-xs text-slate-400 hover:text-white font-bold uppercase">
            <i class="fas fa-arrow-left mr-2"></i> Back to Class
        </a>
        <h1 class="text-xl md:text-2xl font-orbitron font-bold uppercase mt-4">Class <span class="text-cyan-400">Settings</span></h1>
    </div>
</div>

@if(!empty($class['archived_at']))
    <div class="portal-frame !p-5 mb-7 border-l-4 border-slate-500">
        <p class="font-bold text-slate-300"><i class="fas fa-archive mr-2"></i>This class is archived</p>
        <p class="text-xs text-slate-500 mt-2">It is hidden from students, excluded from active class counts, and no longer prevents their grade changes. Quiz history is preserved.</p>
    </div>
@endif

<div class="grid grid-cols-1 xl:grid-cols-[1fr_340px] gap-7">
    <form method="POST" action="/teacher/classes/{{ $class['id'] }}/settings" class="portal-frame !p-6 md:!p-8 space-y-8">
        @csrf
        @method('PUT')

        <div>
            <h2 class="font-orbitron font-bold uppercase mb-5">Class Details</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div class="form-group">
                    <label class="input-label">Class Name</label>
                    <input type="text" name="class_name" value="{{ $class['class_name'] }}" maxlength="100"
                           class="input-mobile-ultra !pl-4" required>
                </div>
                <div class="form-group">
                    <label class="input-label">Grade Level</label>
                    <select name="grade_level" class="input-mobile-ultra !pl-4 bg-slate-900 text-white" required>
                        @for($grade = 1; $grade <= 6; $grade++)
                            <option value="{{ $grade }}" {{ (int) $class['grade_level'] === $grade ? 'selected' : '' }}>Grade {{ $grade }}</option>
                        @endfor
                    </select>
                    <p class="text-[9px] text-slate-500 mt-1">Students must match this grade.</p>
                </div>
            </div>
        </div>

        <div>
            <h2 class="font-orbitron font-bold uppercase mb-2">Theme Color</h2>
            <p class="text-xs text-slate-500 mb-5">Choose the class banner and accent color.</p>
            <div class="grid grid-cols-3 sm:grid-cols-6 gap-3">
                @foreach($themeColors as $color)
                    <label class="cursor-pointer">
                        <input type="radio" name="theme_color" value="{{ $color }}" class="peer sr-only"
                               {{ $customization['theme_color'] === $color ? 'checked' : '' }}>
                        <span class="block h-12 rounded border-2 border-transparent peer-checked:border-white peer-checked:scale-105 transition-all"
                              style="background: {{ $color }};"></span>
                    </label>
                @endforeach
            </div>
        </div>

        <div>
            <h2 class="font-orbitron font-bold uppercase mb-2">Class Icon</h2>
            <p class="text-xs text-slate-500 mb-5">Pick an icon students can recognize quickly.</p>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                @foreach($icons as $icon)
                    <label class="cursor-pointer">
                        <input type="radio" name="icon" value="{{ $icon }}" class="peer sr-only"
                               {{ $customization['icon'] === $icon ? 'checked' : '' }}>
                        <span class="flex items-center gap-3 p-4 rounded border border-white/10 bg-white/5 peer-checked:border-cyan-400 peer-checked:bg-cyan-500/10 transition-all">
                            <i class="fas {{ $iconLabels[$icon][1] }} text-cyan-400"></i>
                            <span class="text-xs font-bold">{{ $iconLabels[$icon][0] }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        </div>

        <div>
            <h2 class="font-orbitron font-bold uppercase mb-2">Banner Design</h2>
            <p class="text-xs text-slate-500 mb-5">Select a subtle background pattern.</p>
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
                @foreach($patterns as $pattern)
                    <label class="cursor-pointer">
                        <input type="radio" name="banner_pattern" value="{{ $pattern }}" class="peer sr-only"
                               {{ $customization['banner_pattern'] === $pattern ? 'checked' : '' }}>
                        <span class="block text-center p-3 rounded border border-white/10 bg-white/5 peer-checked:border-cyan-400 peer-checked:text-cyan-400 text-xs font-bold transition-all">
                            {{ $patternLabels[$pattern] }}
                        </span>
                    </label>
                @endforeach
            </div>
        </div>

        <button type="submit" class="btn-rect-primary"><i class="fas fa-save mr-2"></i> Save Class Settings</button>
    </form>

    <aside class="space-y-6">
        <div class="portal-frame !p-6 border-l-4 border-cyan-500">
            <p class="text-[10px] text-slate-500 uppercase tracking-widest font-bold">Class Join Code</p>
            <div class="flex items-center justify-between gap-3 mt-3">
                <code class="text-2xl text-cyan-400 font-black tracking-[0.2em]">{{ $class['join_code'] }}</code>
                <button type="button" data-action="copyToClipboard" data-action-args="{{ json_encode([$class['join_code']], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}" class="text-slate-400 hover:text-white"><i class="fas fa-copy"></i></button>
            </div>
            <p class="text-xs text-slate-500 mt-4">Regenerating invalidates the previous code immediately.</p>
            @if(empty($class['archived_at']))
                <form method="POST" action="/teacher/classes/{{ $class['id'] }}/regenerate-code" class="mt-5">
                    @csrf
                    <button class="btn-rect-secondary !py-3"><i class="fas fa-sync-alt mr-2"></i> Generate New Code</button>
                </form>
            @endif
        </div>

        <div class="portal-frame !p-6 border-l-4 border-slate-500">
            <h2 class="font-orbitron font-bold text-slate-300 uppercase">Class Archive</h2>
            @if(empty($class['archived_at']))
                <p class="text-xs text-slate-500 mt-3 mb-5">Archive the class without deleting students, assignments, or results.</p>
                <button type="button" data-action="openModal" data-action-args='["archiveClassModal"]' class="btn-rect-secondary !py-3"><i class="fas fa-archive mr-2"></i> Archive Class</button>
            @else
                <p class="text-xs text-slate-500 mt-3 mb-5">Restore only when all retained members match Grade {{ $class['grade_level'] }}.</p>
                <form method="POST" action="/teacher/classes/{{ $class['id'] }}/restore">@csrf
                    <button class="btn-rect-primary !py-3"><i class="fas fa-box-open mr-2"></i> Restore Class</button>
                </form>
            @endif
        </div>

        <div class="portal-frame !p-6 border-l-4 border-red-500">
            <h2 class="font-orbitron font-bold text-red-400 uppercase">Danger Zone</h2>
            <p class="text-xs text-slate-500 mt-3 mb-5">Move this class to Trash. Its members, assignments and results are preserved for restoration.</p>
            <button type="button" data-action="openModal" data-action-args='["deleteClassModal"]' class="btn-rect-secondary !py-3 !border-red-500/40 text-red-400">
                <i class="fas fa-trash-alt mr-2"></i> Move to Trash
            </button>
        </div>
    </aside>
</div>
@endsection

@section('modals')
@if(empty($class['archived_at']))
<div id="archiveClassModal" class="modal-overlay hidden">
    <div class="portal-frame !p-10 w-full max-w-sm text-center border-slate-500/50">
        <i class="fas fa-archive text-4xl text-slate-400 mb-4"></i>
        <h3 class="font-orbitron font-bold uppercase">Archive Class?</h3>
        <p class="text-xs text-slate-400 mt-3 mb-8">Open quizzes will be ended. Students will no longer see this class, but all history stays saved.</p>
        <form method="POST" action="/teacher/classes/{{ $class['id'] }}/archive">@csrf
            <button class="btn-rect-primary !bg-slate-600 !text-white">Archive Class</button>
        </form>
        <button type="button" data-action="closeModal" data-action-args='["archiveClassModal"]' class="modal-cancel mt-3">Cancel</button>
    </div>
</div>
@endif

<div id="deleteClassModal" class="modal-overlay hidden">
    <div class="portal-frame !p-10 w-full max-w-sm text-center border-red-500/50">
        <i class="fas fa-trash-alt text-4xl text-red-500 mb-4"></i>
        <h3 class="font-orbitron font-bold uppercase">Move Class to Trash?</h3>
        <p class="text-xs text-slate-400 mt-3 mb-8">{{ $class['class_name'] }} will be hidden and open quizzes will end. Its roster and history remain saved. Restore it from Trash and Recovery.</p>
        <form method="POST" action="/teacher/classes/{{ $class['id'] }}" data-native-navigation>
            @csrf
            @method('DELETE')
            <input type="hidden" name="delete_class_id" value="{{ $class['id'] }}">
            <button class="btn-rect-primary !bg-red-600 !text-white">Move to Trash</button>
        </form>
        <button type="button" data-action="closeModal" data-action-args='["deleteClassModal"]' class="modal-cancel mt-3">Cancel</button>
    </div>
</div>

@include('teacher.partials.logout-modal')
@endsection
