@php
    $profileName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'User';
    $roleDashboard = match ($user['role'] ?? 'student') {
        'teacher' => '/teacher/dashboard',
        'admin' => '/admin/dashboard',
        default => '/student/dashboard',
    };
@endphp

<div class="profile-menu-root relative shrink-0" data-profile-root>
    <button type="button" data-profile-toggle aria-haspopup="menu" aria-expanded="false"
            aria-label="Open profile menu"
            class="profile-menu-toggle w-11 h-11 rounded-full border border-white/15 bg-black/70 hover:border-cyan-400/60 transition-colors overflow-hidden flex items-center justify-center">
        <img src="{{ $user['avatar_url'] ?: asset('default.png') }}" data-current-user-avatar
             alt="{{ $profileName }} profile image" width="44" height="44"
             class="w-full h-full object-cover">
    </button>

    <div data-profile-menu role="menu" aria-hidden="true"
         class="profile-menu absolute right-0 top-full mt-3 w-64 rounded-lg border border-white/10 bg-slate-950/95 backdrop-blur-xl shadow-2xl overflow-hidden z-[210] scale-95 opacity-0 pointer-events-none">
        <div class="flex items-center gap-3 px-4 py-4 border-b border-white/10 bg-white/[0.025]">
            <img src="{{ $user['avatar_url'] ?: asset('default.png') }}" data-current-user-avatar
                 alt="" width="40" height="40"
                 class="w-10 h-10 rounded-full object-cover border border-white/10 shrink-0">
            <div class="min-w-0 text-left">
                <p class="text-xs font-bold text-white truncate">{{ $profileName }}</p>
                <p class="text-[9px] text-slate-500 uppercase tracking-wider">{{ ucwords($user['role'] ?? 'student') }}</p>
            </div>
        </div>

        <a href="{{ $roleDashboard }}?section=profile" role="menuitem"
           class="block w-full text-left px-4 py-3 text-xs hover:bg-white/5">
            <i class="fas fa-user mr-2 text-cyan-400"></i> Profile
        </a>
        <a href="{{ $roleDashboard }}?section=security" role="menuitem"
           class="block w-full text-left px-4 py-3 text-xs hover:bg-white/5">
            <i class="fas fa-shield-halved mr-2 text-cyan-400"></i> Account Security
        </a>
        <button type="button" role="menuitem" data-action="openModal" data-action-args='["logoutModal"]'
                class="w-full text-left px-4 py-3 text-xs text-red-400 hover:bg-red-500/10 border-t border-white/5">
            <i class="fas fa-power-off mr-2"></i> Logout
        </button>
    </div>
</div>
