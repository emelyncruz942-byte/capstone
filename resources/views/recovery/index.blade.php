@extends('layouts.dashboard')
@section('title', 'Trash and Recovery')
@section('sidebar-subtitle', $admin ? 'Administrator Console' : 'Teacher Console')
@section('mobile-title', 'Trash and Recovery')
@section('accent-color', $admin ? 'text-red-500' : 'text-cyan-400')
@section('sidebar-nav')
    @include($admin ? 'admin.partials.sidebar-nav' : 'teacher.partials.sidebar-nav')
@endsection
@section('dashboard-content')
<div class="max-w-6xl mx-auto" data-testid="recovery-trash">
    <h2 class="font-orbitron font-bold text-2xl mb-3">Trash and Recovery</h2>
    <p class="text-sm text-slate-400 mb-6">Classes and quizzes keep their original records until restored. Classes return as archived so old quizzes cannot restart. Nothing in Trash is automatically purged.</p>
    @if(!$trashReady)<p role="alert" class="text-sm text-red-300 mb-5">Trash could not be loaded. Check the latest database migration and data service. No records were changed.</p>@endif
    <nav class="flex flex-wrap gap-3 mb-6" aria-label="Trash categories">
        @foreach(($admin ? ['class' => 'Classes', 'quiz' => 'Quizzes', 'account' => 'Accounts'] : ['class' => 'Classes', 'quiz' => 'Quizzes']) as $key => $label)
            <a href="/{{ $user['role'] }}/trash?type={{ $key }}" class="btn-rect-secondary !w-auto !px-5 !py-3 {{ $type === $key ? 'text-cyan-400' : '' }}" @if($type === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <form method="GET" action="/{{ $user['role'] }}/trash" class="flex flex-wrap gap-3 mb-6">
        <input type="hidden" name="type" value="{{ $type }}">
        <input name="search" value="{{ $search }}" maxlength="80" aria-label="Search Trash" placeholder="{{ $type === 'account' ? 'Search account email' : 'Search name or topic' }}" class="input-mobile-ultra !pl-3 flex-1 min-w-0">
        <button class="btn-rect-secondary !w-auto !px-5">Search</button>
    </form>
    @if($type === 'account')
        <p class="text-xs text-amber-300 mb-5">Deactivation blocks access and preserves data. Reactivation preserves any earlier suspension. Permanent deletion is separate, requires seven days of deactivation, and can permanently remove linked student records. Accounts that own classes or quizzes cannot be purged.</p>
    @endif
    <div class="space-y-4">
        @forelse($items as $item)
            <article class="portal-frame !p-5">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="min-w-0"><h3 class="font-bold break-words">{{ $type === 'class' ? $item['class_name'] : ($type === 'quiz' ? $item['topic'] : trim(($item['first_name'] ?? '').' '.($item['last_name'] ?? ''))) }}</h3>
                        @if($type === 'account')<p class="text-xs text-slate-400 break-all">{{ $item['email'] }} · {{ str_replace('_', ' ', $item['role']) }}</p>@endif
                        <p class="text-xs text-slate-500 mt-2">{{ $type === 'account' ? 'Deactivated' : 'Moved to Trash' }} {{ \App\Support\AppDate::format($item[$type === 'account' ? 'deactivated_at' : 'deleted_at'], 'M j, Y g:i A') }}</p>
                    </div>
                    @if($type === 'account' && !empty($item['purge_intent_id']))
                        <p class="text-xs text-red-300">Permanent deletion pending. Inspect the privileged audit before taking another action.</p>
                    @elseif(!$admin && ($item['deleted_by'] ?? null) !== $user['id'])
                        <p class="text-xs text-amber-300">Administrator restoration required.</p>
                    @else
                        <form method="POST" action="/{{ $user['role'] }}/trash/{{ $type }}/{{ $item['id'] }}/restore" data-native-navigation>
                            @csrf<button class="btn-rect-primary !w-auto !px-5 !py-3 shrink-0">{{ $type === 'account' ? 'Reactivate' : 'Restore' }}</button>
                        </form>
                    @endif
                </div>
                @if($type === 'account' && empty($item['purge_intent_id']))
                    <details class="mt-4 text-xs text-slate-400"><summary class="cursor-pointer text-red-300">Permanent deletion — cannot be undone</summary>
                        <p class="mt-3">Only continue after reviewing your backups and the linked records. Type DELETE to confirm.</p>
                        <form method="POST" action="/admin/trash/account/{{ $item['id'] }}" data-native-navigation class="flex flex-wrap gap-3 mt-3">
                            @csrf @method('DELETE')<input type="hidden" name="delete_account_id" value="{{ $item['id'] }}">
                            <input name="confirmation" required pattern="DELETE" autocomplete="off" aria-label="Type DELETE to confirm permanent account deletion" class="input-mobile-ultra !w-36 !pl-3" placeholder="DELETE">
                            <button class="btn-rect-secondary !w-auto !px-4 text-red-300">Permanently Delete</button>
                        </form>
                    </details>
                @endif
            </article>
        @empty
            @if($trashReady)<p class="portal-frame !p-8 text-slate-400 text-center">No matching records in Trash.</p>@endif
        @endforelse
    </div>
    <nav class="flex justify-between gap-4 mt-6 text-xs" aria-label="Trash pages">
        @if($page > 1)<a href="/{{ $user['role'] }}/trash?{{ http_build_query(['type' => $type, 'search' => $search, 'page' => $page - 1]) }}">Previous</a>@endif
        <span>Page {{ $page }} of {{ $pages }}</span>
        @if($page < $pages)<a href="/{{ $user['role'] }}/trash?{{ http_build_query(['type' => $type, 'search' => $search, 'page' => $page + 1]) }}">Next</a>@endif
    </nav>
</div>
@endsection
@section('modals')
<div id="logoutModal" class="modal-overlay hidden"><div class="portal-frame !p-8 w-full max-w-xs text-center"><h3 class="font-orbitron font-bold mb-5">Log out?</h3><form method="POST" action="/logout">@csrf<button class="btn-rect-primary">Confirm Logout</button></form><button type="button" data-action="closeModal" data-action-args='["logoutModal"]' class="modal-cancel">Cancel</button></div></div>
@endsection
