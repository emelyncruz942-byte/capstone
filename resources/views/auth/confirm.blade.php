@extends('layouts.app')

@section('title', 'Confirm Email')
@section('body-class', 'auth-page auth-single-page')

@section('content')
<main class="auth-single-shell w-full max-w-sm z-20" aria-labelledby="confirmation-title">
    <div class="text-center mb-8"><a href="{{ route('login') }}" class="public-brand" aria-label="MathVerse home"><img src="{{ asset('logo-180.png') }}" width="40" height="40" alt="" aria-hidden="true"><span>Math<span class="public-brand-accent">Verse</span></span></a></div>
    <div class="portal-frame auth-card relative p-8 text-center">
        <i class="fas fa-envelope-circle-check text-4xl text-cyan-400 mb-5" aria-hidden="true"></i>
        <h1 id="confirmation-title" class="font-orbitron text-xl font-black uppercase text-white">
            Confirming <span class="text-cyan-400">Email</span>
        </h1>
        <p id="confirmation-status" class="mt-4 text-xs leading-5 text-slate-400">
            Verifying your secure MathVerse link…
        </p>

        <form id="confirmation-form" method="POST" action="/auth/confirm" class="hidden">
            @csrf
            <input type="hidden" id="confirmation-token" name="token_hash" value="">
            <input type="hidden" id="confirmation-type" name="type" value="">
        </form>

        <a id="confirmation-return" href="/"
           class="hidden mt-6 text-[10px] font-bold uppercase tracking-widest text-cyan-400">
            Return to Login
        </a>
    </div>

    @include('partials.public-footer')
</main>
@endsection

@push('scripts')
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
    document.addEventListener('DOMContentLoaded', () => {
        const params = new URLSearchParams(window.location.hash.replace(/^#/, ''));
        const queryParams = new URLSearchParams(window.location.search);
        const token = params.get('token_hash');
        const type = params.get('type');
        const hasQueryCredential = ['token', 'token_hash', 'access_token', 'refresh_token', 'code']
            .some(parameter => queryParams.has(parameter));
        const form = document.getElementById('confirmation-form');
        const status = document.getElementById('confirmation-status');
        const returnLink = document.getElementById('confirmation-return');

        const cleanUrl = new URL(window.location.href);
        cleanUrl.hash = '';
        window.history.replaceState(window.history.state, document.title, cleanUrl.pathname);

        if (hasQueryCredential || !token || !['email', 'email_change'].includes(type)) {
            status.textContent = 'This email confirmation link is incomplete or invalid.';
            returnLink.classList.remove('hidden');
            showToast('This email confirmation link is incomplete or invalid.', true);
            return;
        }

        document.getElementById('confirmation-token').value = token;
        document.getElementById('confirmation-type').value = type;
        form.requestSubmit();
    }, { once: true });
</script>
@endpush
