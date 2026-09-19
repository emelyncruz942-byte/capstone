@php
    $notificationRows = $notifications ?? [];
    $notificationUnread = $unreadNotificationCount ?? 0;
    $notificationIcons = [
        'teacher_verification' => ['fa-user-shield', 'text-orange-400'],
        'teacher_application_received' => ['fa-file-circle-check', 'text-cyan-400'],
        'teacher_approved' => ['fa-circle-check', 'text-green-400'],
        'account_suspended' => ['fa-lock', 'text-red-400'],
        'account_restored' => ['fa-unlock', 'text-green-400'],
        'student_joined_class' => ['fa-user-plus', 'text-cyan-400'],
        'class_joined' => ['fa-school', 'text-green-400'],
        'removed_from_class' => ['fa-user-minus', 'text-red-400'],
        'class_archived' => ['fa-box-archive', 'text-slate-400'],
        'class_restored' => ['fa-rotate-left', 'text-green-400'],
        'quiz_assigned' => ['fa-clipboard-question', 'text-purple-400'],
        'quiz_started' => ['fa-play', 'text-green-400'],
        'quiz_ended' => ['fa-flag-checkered', 'text-slate-300'],
        'quiz_starts_soon' => ['fa-clock', 'text-yellow-400'],
        'quiz_due_soon' => ['fa-hourglass-half', 'text-orange-400'],
        'quiz_retake_granted' => ['fa-rotate-right', 'text-cyan-400'],
        'quiz_excused' => ['fa-user-check', 'text-violet-400'],
        'quiz_result_recorded' => ['fa-chart-simple', 'text-cyan-400'],
        'quiz_submitted' => ['fa-file-circle-check', 'text-green-400'],
        'shared_quiz_used' => ['fa-share-nodes', 'text-blue-400'],
        'quiz_report_submitted' => ['fa-flag', 'text-red-400'],
        'quiz_reported' => ['fa-triangle-exclamation', 'text-orange-400'],
        'quiz_report_resolved' => ['fa-shield-halved', 'text-green-400'],
        'quiz_verification_changed' => ['fa-circle-check', 'text-blue-400'],
        'password_changed' => ['fa-key', 'text-green-400'],
        'email_changed' => ['fa-envelope-circle-check', 'text-green-400'],
    ];
@endphp

<div class="notification-root relative shrink-0" data-notification-root>
    <button type="button" data-notification-toggle aria-expanded="false" aria-haspopup="dialog" aria-label="Open notifications"
            class="notification-toggle relative w-11 h-11 rounded border border-white/10 bg-black/70 hover:border-cyan-400/50 hover:bg-white/5 transition-colors flex items-center justify-center">
        <i class="fas fa-bell text-slate-300"></i>
        @if($notificationUnread > 0)
            <span class="absolute -top-2 -right-2 min-w-5 h-5 px-1 rounded-full bg-red-500 text-white text-[9px] font-black flex items-center justify-center border-2 border-black">
                {{ $notificationUnread > 99 ? '99+' : $notificationUnread }}
            </span>
        @endif
    </button>

    <div data-notification-menu role="dialog" aria-label="Notifications" aria-hidden="true"
         class="notification-menu hidden absolute right-0 mt-3 w-[min(24rem,calc(100vw-2rem))] max-h-[70vh] overflow-hidden rounded-lg border border-white/10 bg-slate-950/95 backdrop-blur-xl shadow-2xl z-[200]">
        <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-white/10">
            <div>
                <p class="font-orbitron text-xs font-bold uppercase">Notifications</p>
                <p class="text-[9px] text-slate-500 mt-1">{{ $notificationUnread }} unread</p>
            </div>
            @if($notificationUnread > 0)
                <form method="POST" action="/notifications/read-all">
                    @csrf
                    <button type="submit" class="text-[9px] font-bold uppercase text-cyan-400 hover:text-white">Mark all read</button>
                </form>
            @endif
        </div>

        <div class="notification-list max-h-[58vh] overflow-y-auto overscroll-contain">
            @forelse($notificationRows as $notification)
                @php
                    [$notificationIcon, $notificationColor] = $notificationIcons[$notification['type'] ?? '']
                        ?? ['fa-bell', 'text-cyan-400'];
                    $isUnread = empty($notification['read_at']);
                @endphp
                <form method="POST" action="/notifications/{{ $notification['id'] }}/read" class="border-b border-white/5 last:border-0">
                    @csrf
                    <input type="hidden" name="follow" value="1">
                    <button type="submit"
                            class="w-full text-left px-4 py-4 flex gap-3 hover:bg-white/5 transition-colors {{ $isUnread ? 'bg-cyan-400/[0.04]' : '' }}">
                        <span class="w-9 h-9 rounded bg-white/5 border border-white/10 flex items-center justify-center shrink-0 {{ $notificationColor }}">
                            <i class="fas {{ $notificationIcon }}"></i>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-start gap-2">
                                <span class="min-w-0 break-words text-xs font-bold text-white leading-5">{{ $notification['title'] }}</span>
                                @if($isUnread)<span class="w-2 h-2 mt-1 rounded-full bg-cyan-400 shrink-0"></span>@endif
                            </span>
                            <span class="block break-words text-[10px] text-slate-400 leading-4 mt-1">{{ $notification['message'] }}</span>
                            <span class="block text-[9px] text-slate-600 mt-2">
                                {{ \App\Support\AppDate::relative($notification['created_at']) }}
                            </span>
                        </span>
                    </button>
                </form>
            @empty
                <div class="px-6 py-10 text-center">
                    <i class="far fa-bell-slash text-2xl text-slate-600 mb-3"></i>
                    <p class="text-[10px] uppercase tracking-widest text-slate-500">No notifications yet</p>
                </div>
            @endforelse
        </div>
    </div>
</div>
