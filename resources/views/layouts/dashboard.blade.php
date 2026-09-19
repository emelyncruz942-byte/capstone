@extends('layouts.app')

@section('body-class', 'flex min-h-screen text-white bg-black')

@section('content')

<div id="mathverse-navigation-progress" aria-hidden="true"></div>

<button type="button" id="sidebar-overlay" class="fixed inset-0 bg-black/80 z-40 hidden md:hidden" data-action="toggleSidebar" data-action-args='[false]' aria-label="Close navigation"></button>

<aside id="sidebar" data-dashboard-role="{{ $user['role'] ?? '' }}" aria-label="Primary navigation" class="dashboard-sidebar fixed inset-y-0 left-0 w-64 border-r @yield('sidebar-border', 'border-white/10') backdrop-blur-xl bg-black/60 flex flex-col p-6 z-50 transform -translate-x-full transition-transform duration-300 md:sticky md:top-0 md:h-screen md:translate-x-0">
    <div class="mb-10 text-center">
        <h1 class="font-orbitron text-xl font-black tracking-tighter text-white">
            MATH<span class="@yield('accent-color', 'text-cyan-400')">VERSE</span>
        </h1>
        <p class="text-[9px] @yield('sidebar-subtitle-color', 'text-slate-500') tracking-[0.3em] uppercase font-bold">
            @yield('sidebar-subtitle')
        </p>
    </div>

    <nav class="flex-1 space-y-2 overflow-y-auto">
        @yield('sidebar-nav')
    </nav>

</aside>

<main id="main-content" class="app-shell-main flex-1 min-w-0 w-full p-4 md:p-8 z-20 relative">

    {{-- Mobile header --}}
    <div class="mobile-dashboard-header md:hidden relative z-[300] overflow-visible flex items-center justify-between gap-3 mb-8 p-4 rounded-xl border border-white/10 bg-black/80 backdrop-blur-xl shadow-2xl @yield('mobile-border', '')">
        <button type="button" data-action="toggleSidebar" data-sidebar-toggle aria-controls="sidebar" aria-expanded="false" aria-label="Open navigation" class="min-w-11 min-h-11 text-2xl @yield('accent-color', 'text-cyan-400')">
            <i class="fas fa-bars"></i>
        </button>
        <h1 id="dashboard-mobile-title" class="min-w-0 truncate font-orbitron font-bold text-xs tracking-widest uppercase">
            {{ trim($__env->yieldContent('mobile-title')) }}
        </h1>
        <div class="flex items-center gap-2 shrink-0">
            @include('partials.notifications')
            @include('partials.profile-menu')
        </div>
    </div>

    <div class="desktop-notification-bar hidden md:flex items-center justify-end gap-3 mb-4">
        @include('partials.notifications')
        @include('partials.profile-menu')
    </div>

    <div id="dashboard-content"
         data-dashboard-role="{{ $user['role'] ?? '' }}"
         data-dashboard-chrome-fresh="{{ ($dashboardChromeFresh ?? true) ? 'true' : 'false' }}">
        @yield('dashboard-content')
    </div>

</main>

{{-- All modals injected per-dashboard --}}
<div id="dashboard-modals">
    @yield('modals')
</div>

@endsection

@push('scripts')
<script nonce="{{ request()->attributes->get('csp_nonce') }}" src="{{ asset('js/dashboard.js') }}?v={{ filemtime(public_path('js/dashboard.js')) }}"></script>
<script nonce="{{ request()->attributes->get('csp_nonce') }}" src="{{ asset('js/notifications.js') }}?v={{ filemtime(public_path('js/notifications.js')) }}"></script>
<script nonce="{{ request()->attributes->get('csp_nonce') }}" src="{{ asset('js/admin-push.js') }}?v={{ filemtime(public_path('js/admin-push.js')) }}"></script>
@if(in_array($user['role'] ?? '', ['teacher', 'admin'], true))
    @vite('resources/js/chart.js')
    <script nonce="{{ request()->attributes->get('csp_nonce') }}" src="{{ asset('js/charts.js') }}?v={{ filemtime(public_path('js/charts.js')) }}"></script>
    <script nonce="{{ request()->attributes->get('csp_nonce') }}" src="{{ asset('js/teacher-quizzes.js') }}?v={{ filemtime(public_path('js/teacher-quizzes.js')) }}"></script>
@endif
@if(($user['role'] ?? '') === 'admin')
    <script nonce="{{ request()->attributes->get('csp_nonce') }}" src="{{ asset('js/admin.js') }}?v={{ filemtime(public_path('js/admin.js')) }}"></script>
@elseif(($user['role'] ?? '') === 'teacher')
    <script nonce="{{ request()->attributes->get('csp_nonce') }}" src="{{ asset('js/teacher.js') }}?v={{ filemtime(public_path('js/teacher.js')) }}"></script>
    <script nonce="{{ request()->attributes->get('csp_nonce') }}" src="{{ asset('js/shared-quiz-review.js') }}?v={{ filemtime(public_path('js/shared-quiz-review.js')) }}"></script>
    <script nonce="{{ request()->attributes->get('csp_nonce') }}" src="{{ asset('js/teacher-classroom.js') }}?v={{ filemtime(public_path('js/teacher-classroom.js')) }}"></script>
@elseif(($user['role'] ?? '') === 'student')
    <script nonce="{{ request()->attributes->get('csp_nonce') }}" src="{{ asset('js/learning-hub.js') }}?v={{ filemtime(public_path('js/learning-hub.js')) }}"></script>
    <script nonce="{{ request()->attributes->get('csp_nonce') }}" src="{{ asset('js/number-guess.js') }}?v={{ filemtime(public_path('js/number-guess.js')) }}"></script>
    <script nonce="{{ request()->attributes->get('csp_nonce') }}" src="{{ asset('js/math-arcade.js') }}?v={{ filemtime(public_path('js/math-arcade.js')) }}"></script>
@endif
<script nonce="{{ request()->attributes->get('csp_nonce') }}" src="{{ asset('js/seamless-navigation.js') }}?v={{ filemtime(public_path('js/seamless-navigation.js')) }}"></script>
@endpush
