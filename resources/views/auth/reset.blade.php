@extends('layouts.app')

@section('title', 'Reset Password')
@section('body-class', 'auth-page auth-single-page')

@section('content')
<main class="auth-single-shell w-full max-w-sm z-20 transition-all duration-500" id="main-content">

    <div class="text-center mb-8">
        <a href="{{ route('login') }}" class="public-brand" aria-label="MathVerse home">
            <img src="{{ asset('logo-180.png') }}" width="40" height="40" alt="" aria-hidden="true">
            <span>Math<span class="public-brand-accent">Verse</span></span>
        </a>
    </div>

    <div class="portal-frame auth-card relative">

        <div class="p-6">

            {{-- PASSWORD RECOVERY PANEL --}}
            <div id="regMod">
                <div class="auth-panel-heading">
                    <span class="auth-panel-icon" aria-hidden="true"><i class="fas fa-key"></i></span>
                    <h1>Choose a new password</h1>
                    <p>Use a strong password to keep your account safe.</p>
                </div>

                <form id="resetForm" method="POST" action="/update-password" class="space-y-4"
                      data-has-recovery-token="{{ session()->has('password_recovery_token') ? 'true' : 'false' }}">
                    @csrf

                    <input type="hidden" id="token" name="token" value="">
                    <input type="hidden" id="token-type" name="token_type" value="token_hash">

                    <div class="form-group">
                        <label for="rPass" class="input-label">New Password</label>
                        <div class="relative">
                            <i class="fas fa-key input-icon"></i>
                            <input type="password" id="rPass" name="password" minlength="8" maxlength="128"
                                   pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}"
                                   title="Use 8 or more characters with uppercase, lowercase, a number, and a symbol."
                                   autocomplete="new-password" placeholder="Enter new password" required
                                   class="input-mobile-ultra pr-12">
                            <button type="button" data-action="tglPass" data-action-args='["rPass","rIco"]'
                                    aria-label="Show or hide new password"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 w-10 h-8 flex items-center justify-center text-slate-500">
                                <i id="rIco" class="fas fa-eye-slash"></i>
                            </button>
                        </div>
                        <p class="text-[9px] text-slate-500 mt-2 leading-4">8+ characters with uppercase, lowercase, a number, and a symbol.</p>
                    </div>

                    <div class="form-group">
                        <label for="rcPass" class="input-label">Confirm New Password</label>
                        <div class="relative">
                            <i class="fas fa-shield-alt input-icon"></i>
                            <input type="password" id="rcPass" name="password_confirmation" minlength="8" maxlength="128"
                                   pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}"
                                   title="Use 8 or more characters with uppercase, lowercase, a number, and a symbol." autocomplete="new-password"
                                   placeholder="Re-type password" required class="input-mobile-ultra pr-12">
                            <button type="button" data-action="tglPass" data-action-args='["rcPass","rcIco"]'
                                    aria-label="Show or hide password confirmation"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 w-10 h-8 flex items-center justify-center text-slate-500">
                                <i id="rcIco" class="fas fa-eye-slash"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-mobile-ultra mt-2">Change Password</button>
                </form>

                <button type="button" data-action="navigate" data-destination="/"
                        class="mt-6 w-full text-slate-500 text-[9px] font-bold uppercase tracking-widest">
                    Return to sign in
                </button>
            </div>

        </div>
    </div>

    @include('partials.public-footer')
</main>

@endsection

@push('scripts')
<script defer nonce="{{ request()->attributes->get('csp_nonce') }}" src="{{ asset('js/password-reset.js') }}?v={{ filemtime(public_path('js/password-reset.js')) }}"></script>
@endpush
