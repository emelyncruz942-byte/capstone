@extends('layouts.app')

@section('title', 'Page Not Found')
@section('description', 'The requested MathVerse page could not be found.')
@section('body-class', 'auth-page auth-single-page')

@section('content')
<main class="auth-single-shell status-page relative z-20 w-full max-w-lg text-center" aria-labelledby="not-found-title">
    <div class="portal-frame auth-card relative p-8 sm:p-10">

        <p class="font-orbitron text-6xl font-black text-cyan-400" aria-hidden="true">404</p>
        <p class="mt-3 text-[10px] font-bold uppercase tracking-[0.35em] text-slate-500">Navigation error</p>
        <h1 id="not-found-title" class="mt-4 font-orbitron text-2xl font-black uppercase text-white">
            Page <span class="text-cyan-400">Not Found</span>
        </h1>
        <p class="mx-auto mt-4 max-w-sm text-sm leading-6 text-slate-400">
            The address may be incorrect, or this page may have moved. Return to the MathVerse home page to continue.
        </p>
        <a href="{{ route('login') }}" class="btn-rect-primary mt-7 inline-flex w-auto items-center justify-center px-6 py-3">
            Return home
        </a>
    </div>

    @include('partials.public-footer')
</main>
@endsection
