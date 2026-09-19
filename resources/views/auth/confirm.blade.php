@extends('layouts.app')

@section('title', 'Confirm Email')

@section('content')
<main class="w-full max-w-sm z-20" aria-labelledby="confirmation-title">
    <div class="portal-frame relative p-8 text-center">
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
</main>
@endsection

@push('scripts')
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
    (() => {
        const params = new URLSearchParams(window.location.hash.replace(/^#/, ''));
        const queryParams = new URLSearchParams(window.location.search);
        const token = params.get('token_hash') || queryParams.get('token_hash');
        const type = params.get('type') || queryParams.get('type');
        const form = document.getElementById('confirmation-form');
        const status = document.getElementById('confirmation-status');
        const returnLink = document.getElementById('confirmation-return');

        const cleanUrl = new URL(window.location.href);
        cleanUrl.hash = '';
        window.history.replaceState(window.history.state, document.title, cleanUrl.pathname);

        if (!token || !['email', 'email_change'].includes(type)) {
            status.textContent = 'This email confirmation link is incomplete or invalid.';
            returnLink.classList.remove('hidden');
            showToast('This email confirmation link is incomplete or invalid.', true);
            return;
        }

        document.getElementById('confirmation-token').value = token;
        document.getElementById('confirmation-type').value = type;
        form.submit();
    })();
</script>
@endpush
