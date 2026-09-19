@php
    $queryToastMessages = [
        'email-change-requested' => 'Email change requested. Check your new email address to confirm the change.',
    ];
    $queryToastKey = (string) request()->query('notice', '');
    $queryToastMessage = $queryToastMessages[$queryToastKey] ?? null;
    $flashToastMessage = $errors->first() ?: (session('success') ?? session('error') ?? $queryToastMessage);
    $flashToastIsError = $errors->any() || session()->has('error');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#05070d">
    <meta name="color-scheme" content="dark">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>MathVerse | {{ trim($__env->yieldContent('title', 'Academic Portal')) }}</title>

    @vite('resources/css/app.css')
    <link rel="icon" href="{{ asset('logo.png') }}" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('logo.png') }}">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="stylesheet" href="{{ asset('css/style.css') }}?v={{ filemtime(public_path('css/style.css')) }}">

    @stack('head')
</head>
<body class="app-body @yield('body-class', 'flex items-center justify-center p-4 min-h-screen')">

    {{-- Background effects used on every page --}}
    <div class="stars-container"></div>
    <div class="digital-rain"></div>
    <div class="cyber-grid"></div>
    <div id="particle-container"></div>

    @yield('content')

    {{-- Reusable image-validation alert for registration and profile forms --}}
    <div id="imageSizeModal" class="modal-overlay hidden" data-initial-open="{{ session('image_upload_error') ? 'true' : 'false' }}" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="image-size-title">
        <div class="portal-frame !p-8 w-full max-w-sm text-center border-red-500/50">
            <i class="fas fa-image text-4xl text-red-500 mb-4"></i>
            <h3 id="image-size-title" class="font-orbitron font-bold mb-2 uppercase text-white">
                Invalid <span class="text-red-500">Image</span>
            </h3>
            <p id="image-size-message" class="text-xs text-slate-400 mb-3">
                {{ session('image_upload_error') ?: 'Choose a JPEG, PNG, or WebP image up to 2 MB.' }}
            </p>
            <p id="image-size-file" class="text-[10px] font-mono text-red-400 break-all mb-8">
                Please choose another image.
            </p>
            <div class="flex flex-col gap-3">
                <button type="button" data-action="chooseAnotherAvatar"
                        class="btn-rect-primary !bg-red-600 !text-white">
                    <i class="fas fa-folder-open mr-2"></i> Choose Another Image
                </button>
                <button type="button" data-action="closeModal" data-action-args='["imageSizeModal"]'
                        class="text-[10px] font-bold uppercase text-slate-500">
                    Close
                </button>
            </div>
        </div>
    </div>

    {{-- Toast notification - available on every page --}}
    <div id="toast"
         role="{{ $flashToastIsError ? 'alert' : 'status' }}"
         aria-live="{{ $flashToastIsError ? 'assertive' : 'polite' }}"
         aria-atomic="true"
         aria-hidden="{{ $flashToastMessage ? 'false' : 'true' }}"
         data-initial-visible="{{ $flashToastMessage ? 'true' : 'false' }}"
         class="global-toast fixed z-[10000] flex items-start gap-3 rounded-lg px-4 py-3 text-sm font-bold leading-5 shadow-2xl transition-all duration-300 {{ $flashToastIsError ? 'bg-red-500 text-white' : 'bg-cyan-500 text-black' }} {{ $flashToastMessage ? 'opacity-100' : 'opacity-0 pointer-events-none' }}">
        <i class="fas {{ $flashToastIsError ? 'fa-circle-exclamation' : 'fa-circle-check' }} mt-0.5 shrink-0" data-toast-icon aria-hidden="true"></i>
        <span id="toast-msg" class="min-w-0 flex-1 break-words">{{ $flashToastMessage ?: 'Success' }}</span>
        <button type="button" class="toast-close -m-1 ml-1 min-h-8 min-w-8 rounded p-1" aria-label="Dismiss notification" data-action="hideToast">
            <i class="fas fa-xmark" aria-hidden="true"></i>
        </button>
    </div>

    <script nonce="{{ request()->attributes->get('csp_nonce') }}" src="{{ asset('js/shared.js') }}?v={{ filemtime(public_path('js/shared.js')) }}"></script>

    @stack('scripts')
</body>
</html>
