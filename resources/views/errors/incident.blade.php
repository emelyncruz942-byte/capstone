@extends('layouts.app')
@section('title', 'Request unavailable')
@section('content')
<main class="max-w-xl mx-auto p-8 text-center text-white">
    <h1 class="font-orbitron text-xl mb-4">Request unavailable</h1>
    <p class="text-slate-300">{{ $message }}</p>
    <p class="text-sm text-slate-400 mt-4">Share this reference with an administrator: <code>{{ $reference }}</code></p>
    <a href="/" class="btn-rect-secondary inline-flex mt-6">Return to sign in</a>
</main>
@endsection
