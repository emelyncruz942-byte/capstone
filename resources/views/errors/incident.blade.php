@extends('layouts.app')
@section('title', 'Request unavailable')
@section('content')
@php
    $incidentUser = request()->hasSession() ? request()->session()->get('supabase_user') : null;
    $canOpenSupportTicket = is_array($incidentUser)
        && in_array($incidentUser['role'] ?? null, ['student', 'teacher'], true);
@endphp
<main class="max-w-xl mx-auto p-8 text-center text-white">
    <h1 class="font-orbitron text-xl mb-4">Request unavailable</h1>
    <p class="text-slate-300">{{ $message }}</p>
    <p class="text-sm text-slate-400 mt-4">Share this reference with an administrator: <code>{{ $reference }}</code></p>
    <div class="flex flex-col sm:flex-row justify-center gap-3 mt-6">
        @if($canOpenSupportTicket)
            <a href="/support-tickets?reference_id={{ urlencode($reference) }}" class="btn-rect-primary inline-flex !w-auto !px-5">
                <i class="fas fa-life-ring mr-2"></i> Report this problem
            </a>
        @endif
        <a href="/" class="btn-rect-secondary inline-flex !w-auto !px-5">Return to sign in</a>
    </div>
</main>
@endsection
