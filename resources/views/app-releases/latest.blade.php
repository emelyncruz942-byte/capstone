@extends('layouts.dashboard')

@php
    $activePage = 'app-download';
@endphp

@section('title', 'Game Download')
@section('sidebar-subtitle', ($user['role'] ?? '') === 'teacher' ? 'Instructional Hub' : 'Student Game Hub')
@section('mobile-title', 'Game Download')

@section('sidebar-nav')
    @if(($user['role'] ?? '') === 'teacher')
        @include('teacher.partials.sidebar-nav')
    @else
        @include('student.partials.sidebar-nav')
    @endif
@endsection

@section('dashboard-content')
<section class="max-w-4xl mx-auto">
    <div class="mb-7 border-b border-white/10 pb-5">
        <p class="text-[10px] font-bold uppercase tracking-[0.3em] text-cyan-400">MathVerse Mobile</p>
        <h2 class="mt-2 text-2xl md:text-3xl font-orbitron font-black uppercase">
            Download the <span class="text-cyan-400">Game</span>
        </h2>
        <p class="mt-3 max-w-2xl text-sm text-slate-400">
            Only the current approved Android and iOS builds are shown here, so everyone uses the same quiz experience.
        </p>
    </div>

    @if(!$releasesReady)
        <div class="portal-frame !p-7 border-amber-500/40 text-center">
            <i class="fas fa-triangle-exclamation text-3xl text-amber-400 mb-4"></i>
            <h3 class="font-orbitron font-bold uppercase">Download temporarily unavailable</h3>
            <p class="mt-2 text-sm text-slate-400">Please try again later or contact an administrator.</p>
        </div>
    @elseif(empty($releases))
        <div class="portal-frame !p-7 text-center">
            <i class="fas fa-mobile-screen text-4xl text-slate-600 mb-4"></i>
            <h3 class="font-orbitron font-bold uppercase">No mobile build published yet</h3>
            <p class="mt-2 text-sm text-slate-400">An administrator will publish the first approved build here.</p>
        </div>
    @else
        <div class="grid grid-cols-1 {{ count($releases) > 1 ? 'md:grid-cols-2' : '' }} gap-6">
            @foreach($releases as $release)
                @php
                    $platform = strtolower((string) ($release['platform'] ?? 'android'));
                    $sizeBytes = (int) ($release['size_bytes'] ?? $release['expected_size_bytes'] ?? 0);
                    $sizeLabel = $sizeBytes > 0 ? number_format($sizeBytes / 1048576, 1) . ' MB' : 'Size unavailable';
                    $isIos = $platform === 'ios';
                @endphp
                <article class="portal-frame !p-7 border-cyan-500/40">
                    <div class="flex items-start gap-5">
                        <div class="w-16 h-16 rounded-xl border border-cyan-400/30 bg-cyan-400/10 flex items-center justify-center text-cyan-300 shrink-0">
                            <i class="fab {{ $isIos ? 'fa-apple' : 'fa-android' }} text-3xl" aria-hidden="true"></i>
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-orbitron text-xl font-black">Version {{ $release['version'] }}</h3>
                                <span class="rounded-full border border-green-400/40 bg-green-400/10 px-3 py-1 text-[9px] font-black uppercase tracking-wider text-green-300">Current</span>
                            </div>
                            <p class="mt-2 text-xs font-bold uppercase tracking-wider text-slate-400">
                                {{ $isIos ? 'iOS · IPA' : 'Android · APK' }} &nbsp;•&nbsp; {{ $sizeLabel }}
                            </p>
                            @if(!empty($release['release_notes']))
                                <p class="mt-4 whitespace-pre-line text-sm leading-6 text-slate-300">{{ $release['release_notes'] }}</p>
                            @endif
                        </div>
                    </div>

                    <a href="{{ route('app-downloads.download', ['releaseId' => $release['id']]) }}"
                       download="{{ $release['download_name'] ?? '' }}"
                       data-native-navigation
                       class="btn-rect-primary mt-6 flex items-center justify-center px-6 text-center">
                        <i class="fas fa-download mr-2" aria-hidden="true"></i>
                        Download {{ $isIos ? 'for iOS' : 'for Android' }}
                    </a>

                    <div class="mt-5 rounded-lg border border-white/10 bg-black/30 p-4 text-xs leading-5 text-slate-400">
                        @if($isIos)
                            The IPA must be installed through your school's approved iOS distribution method, such as TestFlight or device management.
                        @else
                            Android may ask you to allow installation from your browser or file manager before opening the APK.
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</section>
@endsection
