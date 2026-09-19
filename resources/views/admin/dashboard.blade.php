@extends('layouts.dashboard')

@section('title', 'Admin Control')
@section('sidebar-border', 'border-red-500/20')
@section('sidebar-subtitle', 'System Administrator')
@section('sidebar-subtitle-color', 'text-red-500/60')
@section('accent-color', 'text-red-500')
@section('mobile-title', 'Admin_Panel')
@section('logout-btn-class', '!border-red-500/30 !text-red-500')

@section('sidebar-nav')
    @include('admin.partials.sidebar-nav')
@endsection

@section('dashboard-content')

{{-- STATS --}}
<section id="sec-stats" class="content-section hidden">
    <h2 class="text-xl md:text-2xl font-orbitron font-bold mb-6 uppercase border-b border-red-500/20 pb-2">
        Platform <span class="text-red-500">Analytics</span>
    </h2>

    <div id="stats-loading" class="flex flex-col items-center justify-center" style="min-height: 80vh;">
        <i class="fas fa-circle-notch fa-spin text-4xl text-red-500 mb-4"></i>
        <p class="text-xs uppercase tracking-widest font-orbitron text-slate-500">Fetching Analytics...</p>
    </div>

    <div id="stats-content" class="hidden">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
            <div class="portal-frame !p-5 border-l-2 border-red-500">
                <p class="text-slate-500 text-[10px] uppercase font-bold tracking-widest">Total Attempts</p>
                <h3 class="text-2xl font-orbitron mt-1" id="stat-total-attempts">—</h3>
            </div>
            <div class="portal-frame !p-5 border-l-2 border-cyan-500">
                <p class="text-slate-500 text-[10px] uppercase font-bold tracking-widest">Avg Accuracy</p>
                <h3 class="text-2xl font-orbitron mt-1" id="stat-avg-accuracy">—</h3>
            </div>
            <div class="portal-frame !p-5 border-l-2 border-purple-500">
                <p class="text-slate-500 text-[10px] uppercase font-bold tracking-widest">Total Users</p>
                <h3 class="text-2xl font-orbitron mt-1" id="stat-total-users">—</h3>
            </div>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="portal-frame !p-6">
                <h4 class="font-orbitron text-xs text-cyan-400 uppercase tracking-widest mb-4">
                    <i class="fas fa-chart-line mr-2"></i> Quiz Attempts (14 days)
                </h4>
                <canvas id="chart-attempts" height="200"></canvas>
            </div>
            <div class="portal-frame !p-6">
                <h4 class="font-orbitron text-xs text-green-400 uppercase tracking-widest mb-4">
                    <i class="fas fa-user-plus mr-2"></i> New Registrations (14 days)
                </h4>
                <canvas id="chart-registrations" height="200"></canvas>
            </div>
            <div class="portal-frame !p-6">
                <h4 class="font-orbitron text-xs text-orange-400 uppercase tracking-widest mb-4">
                    <i class="fas fa-users mr-2"></i> User Role Breakdown
                </h4>
                <canvas id="chart-roles" height="200"></canvas>
            </div>
            <div class="portal-frame !p-6">
                <h4 class="font-orbitron text-xs text-pink-400 uppercase tracking-widest mb-4">
                    <i class="fas fa-chart-pie mr-2"></i> Score Distribution
                </h4>
                <canvas id="chart-distribution" height="200"></canvas>
            </div>
        </div>
    </div>
</section>

{{-- OVERVIEW --}}
<section id="sec-overview" class="content-section">
    <h2 class="text-xl md:text-2xl font-orbitron font-bold mb-6 uppercase border-b border-red-500/20 pb-2 text-white">
        System <span class="text-red-500">Metrics</span>
    </h2>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="portal-frame !p-5 border-l-2 border-red-500 flex justify-between items-center">
            <div>
                <p class="text-slate-500 text-[9px] uppercase font-bold tracking-widest">Total Users</p>
                <h3 class="text-2xl font-orbitron mt-1">{{ $totalUsers }}</h3>
            </div>
            <i class="fas fa-users text-3xl opacity-10"></i>
        </div>
        <div class="portal-frame !p-5 border-l-2 border-cyan-500 flex justify-between items-center">
            <div>
                <p class="text-slate-500 text-[9px] uppercase font-bold tracking-widest">Teachers</p>
                <h3 class="text-2xl font-orbitron mt-1">{{ $totalTeachers }}</h3>
            </div>
            <i class="fas fa-chalkboard-teacher text-3xl opacity-10"></i>
        </div>
        <div class="portal-frame !p-5 border-l-2 border-orange-500 flex justify-between items-center">
            <div>
                <p class="text-slate-500 text-[9px] uppercase font-bold tracking-widest">Students</p>
                <h3 class="text-2xl font-orbitron mt-1">{{ $totalStudents }}</h3>
            </div>
            <i class="fas fa-user-graduate text-3xl opacity-10"></i>
        </div>
        <div class="portal-frame !p-5 border-l-2 border-purple-500 flex justify-between items-center">
            <div>
                <p class="text-slate-500 text-[9px] uppercase font-bold tracking-widest">Total Quizzes</p>
                <h3 class="text-2xl font-orbitron mt-1 text-purple-400">{{ $totalQuizzes }}</h3>
            </div>
            <i class="fas fa-book-open text-3xl opacity-10"></i>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-8">
        <a href="/admin/dashboard?section=role-verify"
           class="portal-frame !p-5 border-orange-500/30 flex items-center justify-between gap-4 hover:border-orange-400 transition-colors">
            <div>
                <p class="text-[9px] text-orange-300 uppercase font-bold tracking-widest">Pending Teacher Verification</p>
                <p class="text-xs text-slate-400 mt-2">Review newly registered teacher accounts.</p>
            </div>
            <span class="w-12 h-12 rounded bg-orange-500/15 text-orange-400 flex items-center justify-center font-orbitron text-xl font-black shrink-0">
                {{ $adminPendingTeacherCount ?? count($pendingTeachers) }}
            </span>
        </a>
        <a href="/admin/quiz-reports?status=active"
           class="portal-frame !p-5 border-red-500/30 flex items-center justify-between gap-4 hover:border-red-400 transition-colors">
            <div>
                <p class="text-[9px] text-red-300 uppercase font-bold tracking-widest">Active Quiz Reports</p>
                <p class="text-xs text-slate-400 mt-2">Open reported quizzes requiring moderation.</p>
            </div>
            <span class="w-12 h-12 rounded bg-red-500/15 text-red-400 flex items-center justify-center font-orbitron text-xl font-black shrink-0">
                {{ $pendingReportCount }}
            </span>
        </a>
        <div class="portal-frame !p-5 border-yellow-500/30 flex flex-col justify-between gap-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-[9px] text-yellow-300 uppercase font-bold tracking-widest">Browser Alerts</p>
                    <p id="admin-push-status" data-push-status class="text-xs text-slate-400 mt-2">Checking this device…</p>
                </div>
                <i class="fas fa-bell text-2xl text-yellow-400 opacity-70"></i>
            </div>
            <button type="button" id="admin-push-toggle"
                    data-push-toggle data-subscription-url="/push-subscription"
                    data-vapid-key="{{ config('services.web_push.public_key') }}"
                    class="btn-rect-secondary !py-2 !px-3 !w-auto text-yellow-300 !border-yellow-500/30 text-[10px]">
                Enable Browser Alerts
            </button>
        </div>
    </div>

    <div class="portal-frame !p-6 border-red-500/10">
        <div class="flex items-center justify-between gap-4 mb-6">
            <h4 class="font-orbitron text-xs text-red-500 uppercase tracking-widest"><i class="fas fa-clipboard-list mr-2"></i> Recent Audit Events</h4>
            <a href="/admin/dashboard?section=audit" class="text-[9px] text-cyan-400 uppercase font-bold">View All</a>
        </div>
        <div class="space-y-3 font-mono text-[10px] text-slate-400">
            @forelse(array_slice($auditLogs, 0, 5) as $log)
                <p><span class="text-red-400">[{{ strtoupper($log['action']) }}]</span> {{ $log['actor_name'] }} · {{ \App\Support\AppDate::relative($log['created_at']) }}</p>
            @empty
                <p>No sensitive actions have been recorded yet.</p>
            @endforelse
        </div>
    </div>
</section>

{{-- STUDENT REGISTRY --}}
<section id="sec-students" class="content-section hidden">
    <div class="portal-frame !p-6 md:!p-8">
        <div class="flex flex-col xl:flex-row justify-between xl:items-end mb-6 gap-4">
            <div>
                <h2 class="text-xl font-orbitron font-bold uppercase">Student <span class="text-cyan-400">Registry</span></h2>
                <p class="text-[10px] text-slate-500 mt-2">{{ number_format($studentTotal) }} matching students · 25 per page</p>
            </div>
            <form method="GET" action="/admin/dashboard" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-[220px_180px_160px_auto] gap-3 w-full xl:w-auto">
                <input type="hidden" name="section" value="students">
                <input type="search" name="student_search" value="{{ $studentSearch }}" maxlength="80" placeholder="Name or email" class="input-mobile-ultra !py-2 !pl-4">
                <select name="student_grade" class="input-mobile-ultra !py-2 !pl-4 bg-slate-900 text-white">
                    <option value="">All grade levels</option>
                    @for($g = 1; $g <= 6; $g++)
                        <option value="{{ $g }}" {{ $selectedGrade === $g ? 'selected' : '' }}>Grade {{ $g }}</option>
                    @endfor
                </select>
                <select name="student_sort" class="input-mobile-ultra !py-2 !pl-4 bg-slate-900 text-white">
                    <option value="name_asc" {{ $studentSort === 'name_asc' ? 'selected' : '' }}>Name A–Z</option>
                    <option value="name_desc" {{ $studentSort === 'name_desc' ? 'selected' : '' }}>Name Z–A</option>
                    <option value="newest" {{ $studentSort === 'newest' ? 'selected' : '' }}>Newest</option>
                    <option value="oldest" {{ $studentSort === 'oldest' ? 'selected' : '' }}>Oldest</option>
                </select>
                <button type="submit" class="btn-rect-primary !py-2 !px-4 !text-[10px]">Apply</button>
            </form>
        </div>
        @if($studentSearch !== '' || $selectedGrade !== 0 || $studentSort !== 'name_asc')
            <div class="flex justify-end mb-4"><a href="/admin/dashboard?section=students" class="text-[10px] text-cyan-400 uppercase font-bold">Clear Filters</a></div>
        @endif
        <div class="overflow-x-auto">
            <table class="w-full text-left min-w-[850px]">
                <thead class="text-slate-500 text-[10px] uppercase border-b border-white/5">
                    <tr><th class="pb-4">Email</th><th class="pb-4">Full Name</th><th class="pb-4">Grade Level</th><th class="pb-4">Status</th><th class="pb-4 text-right">Actions</th></tr>
                </thead>
                <tbody class="text-sm font-rajdhani text-white">
                    @forelse($students as $p)
                        @php $studentName = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')); @endphp
                        <tr class="border-b border-white/5 hover:bg-white/5 {{ !empty($p['suspended_at']) ? 'opacity-70 bg-red-500/5' : '' }}">
                            <td class="py-4 font-mono text-cyan-400">{{ $p['email'] ?? substr($p['id'],0,8) }}</td>
                            <td class="py-4">{{ $p['last_name'] ?? '—' }}, {{ $p['first_name'] ?? '—' }}</td>
                            <td class="py-4 text-cyan-400">Grade {{ $p['grade_level'] ?? 'N/A' }}</td>
                            <td class="py-4">
                                <span class="text-[9px] uppercase font-bold {{ !empty($p['suspended_at']) ? 'text-red-400' : 'text-green-400' }}">{{ !empty($p['suspended_at']) ? 'Suspended' : 'Active' }}</span>
                                @if(!empty($p['suspension_reason']))<p class="text-[9px] text-slate-500 mt-1 max-w-[220px] truncate" title="{{ $p['suspension_reason'] }}">{{ $p['suspension_reason'] }}</p>@endif
                            </td>
                            <td class="py-4 text-right">
                                @if(!empty($p['suspended_at']))
                                    <form method="POST" action="/admin/user/{{ $p['id'] }}/restore" class="inline">@csrf<input type="hidden" name="return_section" value="students"><button class="text-green-400 hover:text-white text-[10px] font-bold uppercase mr-4"><i class="fas fa-undo mr-1"></i> Restore</button></form>
                                @else
                                    <button type="button" data-action="confirmSuspend" data-action-args="{{ json_encode([$p['id'], $studentName, 'students'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}" class="text-yellow-400 hover:text-white text-[10px] font-bold uppercase mr-4"><i class="fas fa-pause-circle mr-1"></i> Suspend</button>
                                @endif
                                <button type="button" data-action="confirmDelete" data-action-args="{{ json_encode([$p['id'], $studentName], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}" class="text-red-500 hover:text-white text-[10px] font-bold uppercase"><i class="fas fa-user-slash mr-1"></i> Deactivate</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-8 text-center text-slate-500 text-xs uppercase">No students match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @php $studentQuery = array_filter(['section' => 'students', 'student_search' => $studentSearch ?: null, 'student_grade' => $selectedGrade ?: null, 'student_sort' => $studentSort !== 'name_asc' ? $studentSort : null]); @endphp
        @if($studentPages > 1)
            <nav class="flex items-center justify-center gap-4 mt-6" aria-label="Student registry pages">
                @if($studentPage > 1)<a class="btn-rect-secondary !py-2 !px-4 !w-auto" href="/admin/dashboard?{{ http_build_query($studentQuery + ['student_page' => $studentPage - 1]) }}">Previous</a>@endif
                <span class="text-[10px] text-slate-500 uppercase">Page {{ $studentPage }} of {{ $studentPages }}</span>
                @if($studentPage < $studentPages)<a class="btn-rect-secondary !py-2 !px-4 !w-auto" href="/admin/dashboard?{{ http_build_query($studentQuery + ['student_page' => $studentPage + 1]) }}">Next</a>@endif
            </nav>
        @endif
    </div>
</section>

{{-- TEACHER REGISTRY --}}
<section id="sec-teachers" class="content-section hidden">
    <div class="portal-frame !p-6 md:!p-8">
        <div class="flex flex-col xl:flex-row justify-between xl:items-end mb-6 gap-4">
            <div><h2 class="text-xl font-orbitron font-bold uppercase">Teacher <span class="text-blue-400">Registry</span></h2><p class="text-[10px] text-slate-500 mt-2">{{ number_format($teacherTotal) }} matching teachers · 25 per page</p></div>
            <form method="GET" action="/admin/dashboard" class="grid grid-cols-1 sm:grid-cols-[220px_160px_auto] gap-3 w-full xl:w-auto">
                <input type="hidden" name="section" value="teachers">
                <input type="search" name="teacher_search" value="{{ $teacherSearch }}" maxlength="80" placeholder="Name or email" class="input-mobile-ultra !py-2 !pl-4">
                <select name="teacher_sort" class="input-mobile-ultra !py-2 !pl-4 bg-slate-900 text-white">
                    <option value="name_asc" {{ $teacherSort === 'name_asc' ? 'selected' : '' }}>Name A–Z</option>
                    <option value="name_desc" {{ $teacherSort === 'name_desc' ? 'selected' : '' }}>Name Z–A</option>
                    <option value="newest" {{ $teacherSort === 'newest' ? 'selected' : '' }}>Newest</option>
                    <option value="oldest" {{ $teacherSort === 'oldest' ? 'selected' : '' }}>Oldest</option>
                </select>
                <button type="submit" class="btn-rect-primary !py-2 !px-4 !text-[10px]">Apply</button>
            </form>
        </div>
        @if($teacherSearch !== '' || $teacherSort !== 'name_asc')<div class="flex justify-end mb-4"><a href="/admin/dashboard?section=teachers" class="text-[10px] text-blue-400 uppercase font-bold">Clear Filters</a></div>@endif
        @if($eventEmailIssue)
            <div class="mb-5 rounded border border-red-500/30 bg-red-500/10 px-4 py-3 text-xs text-red-200">
                <i class="fas fa-envelope-circle-xmark mr-2 text-red-400"></i>
                <span class="font-bold">Teacher emails unavailable.</span> {{ $eventEmailIssue }}
            </div>
        @endif
        <div class="overflow-x-auto">
            <table class="w-full text-left min-w-[780px]">
                <thead class="text-slate-500 text-[10px] uppercase border-b border-white/5">
                    <tr><th class="pb-4">Email</th><th class="pb-4">Full Name</th><th class="pb-4">Joined</th><th class="pb-4">Status</th><th class="pb-4 text-right">Actions</th></tr>
                </thead>
                <tbody class="text-sm font-rajdhani text-white">
                    @forelse($teachers as $p)
                        @php $teacherName = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')); @endphp
                        <tr class="border-b border-white/5 hover:bg-white/5 {{ !empty($p['suspended_at']) ? 'opacity-70 bg-red-500/5' : '' }}">
                            <td class="py-4 font-mono text-blue-400">{{ $p['email'] ?? substr($p['id'],0,8) }}</td>
                            <td class="py-4">{{ $p['last_name'] ?? '—' }}, {{ $p['first_name'] ?? '—' }}</td>
                            <td class="py-4 text-slate-400">{{ isset($p['created_at']) ? \App\Support\AppDate::format($p['created_at'], 'M d, Y') : 'N/A' }}</td>
                            <td class="py-4"><span class="text-[9px] uppercase font-bold {{ !empty($p['suspended_at']) ? 'text-red-400' : 'text-green-400' }}">{{ !empty($p['suspended_at']) ? 'Suspended' : 'Active' }}</span>@if(!empty($p['suspension_reason']))<p class="text-[9px] text-slate-500 mt-1 max-w-[220px] truncate" title="{{ $p['suspension_reason'] }}">{{ $p['suspension_reason'] }}</p>@endif</td>
                            <td class="py-4 text-right">
                                @if(!empty($p['suspended_at']))
                                    <form method="POST" action="/admin/user/{{ $p['id'] }}/restore" class="inline">@csrf<input type="hidden" name="return_section" value="teachers"><button class="text-green-400 hover:text-white text-[10px] font-bold uppercase mr-4"><i class="fas fa-undo mr-1"></i> Restore</button></form>
                                @else
                                    <button type="button" data-action="confirmSuspend" data-action-args="{{ json_encode([$p['id'], $teacherName, 'teachers'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}" class="text-yellow-400 hover:text-white text-[10px] font-bold uppercase mr-4"><i class="fas fa-pause-circle mr-1"></i> Suspend</button>
                                @endif
                                <button type="button" data-action="confirmDelete" data-action-args="{{ json_encode([$p['id'], $teacherName], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}" class="text-red-500 hover:text-white text-[10px] font-bold uppercase"><i class="fas fa-user-slash mr-1"></i> Deactivate</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-8 text-center text-slate-500 text-xs uppercase">No teachers match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @php $teacherQuery = array_filter(['section' => 'teachers', 'teacher_search' => $teacherSearch ?: null, 'teacher_sort' => $teacherSort !== 'name_asc' ? $teacherSort : null]); @endphp
        @if($teacherPages > 1)
            <nav class="flex items-center justify-center gap-4 mt-6" aria-label="Teacher registry pages">
                @if($teacherPage > 1)<a class="btn-rect-secondary !py-2 !px-4 !w-auto" href="/admin/dashboard?{{ http_build_query($teacherQuery + ['teacher_page' => $teacherPage - 1]) }}">Previous</a>@endif
                <span class="text-[10px] text-slate-500 uppercase">Page {{ $teacherPage }} of {{ $teacherPages }}</span>
                @if($teacherPage < $teacherPages)<a class="btn-rect-secondary !py-2 !px-4 !w-auto" href="/admin/dashboard?{{ http_build_query($teacherQuery + ['teacher_page' => $teacherPage + 1]) }}">Next</a>@endif
            </nav>
        @endif
    </div>
</section>

{{-- AUDIT LOG --}}
<section id="sec-audit" class="content-section hidden">
    <div class="portal-frame !p-6 md:!p-8">
        <div class="flex items-center justify-between gap-4 mb-5">
            <div><h2 class="text-xl font-orbitron font-bold uppercase">Accountability <span class="text-red-400">Audit Log</span></h2><p class="text-[10px] text-slate-500 mt-2">Security events are separated from high-volume navigation activity. Privileged records move from pending to their final outcome atomically.</p></div>
            <span class="text-[10px] text-slate-500 uppercase">{{ number_format($auditTotal) }} events</span>
        </div>
        @if(!$auditSearchReady)
            <div class="mb-5 rounded border border-yellow-500/30 bg-yellow-500/10 px-4 py-3 text-xs text-yellow-200"><i class="fas fa-triangle-exclamation mr-2"></i>Advanced audit search is unavailable until the platform insights migration is installed. Showing the legacy event list.</div>
        @endif
        <form method="GET" action="/admin/dashboard" class="audit-filter-grid mb-6" data-seamless-form>
            <input type="hidden" name="section" value="audit">
            <label><span>Name, email, target, or details</span><input type="search" name="audit_search" value="{{ $auditFilters['search'] }}" maxlength="80" placeholder="Search audit records" class="input-mobile-ultra !py-2 !pl-3"></label>
            <label><span>Event stream</span><select name="audit_category" class="input-mobile-ultra !py-2 !pl-3 bg-slate-900"><option value="all" @selected($auditFilters['category'] === '')>All streams</option><option value="security" @selected($auditFilters['category'] === 'security')>Security</option><option value="activity" @selected($auditFilters['category'] === 'activity')>Page activity</option></select></label>
            <label><span>Actor role</span><select name="audit_actor_role" class="input-mobile-ultra !py-2 !pl-3 bg-slate-900"><option value="">All roles</option>@foreach(['admin' => 'Admin', 'teacher' => 'Teacher', 'student' => 'Student', 'system' => 'System'] as $value => $label)<option value="{{ $value }}" @selected($auditFilters['actor_role'] === $value)>{{ $label }}</option>@endforeach</select></label>
            <label><span>Outcome</span><select name="audit_outcome" class="input-mobile-ultra !py-2 !pl-3 bg-slate-900"><option value="">All outcomes</option>@foreach(['pending', 'succeeded', 'failed'] as $value)<option value="{{ $value }}" @selected($auditFilters['outcome'] === $value)>{{ ucfirst($value) }}</option>@endforeach</select></label>
            <label><span>Exact action</span><input type="text" name="audit_action" value="{{ $auditFilters['action'] }}" maxlength="100" placeholder="user.suspended" class="input-mobile-ultra !py-2 !pl-3"></label>
            <label><span>From</span><input type="date" name="audit_from" value="{{ $auditFilters['from'] }}" class="input-mobile-ultra !py-2 !pl-3"></label>
            <label><span>To</span><input type="date" name="audit_to" value="{{ $auditFilters['to'] }}" class="input-mobile-ultra !py-2 !pl-3"></label>
            <div class="flex gap-2 items-end"><button type="submit" class="btn-rect-primary !py-2 !px-4 !w-auto">Filter</button><a href="/admin/dashboard?section=audit&audit_category=security" class="btn-rect-secondary !py-2 !px-4 !w-auto">Reset</a></div>
        </form>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[980px] text-left">
                <thead class="text-[10px] text-slate-500 uppercase border-b border-white/10"><tr><th class="pb-4">Date</th><th class="pb-4">Actor</th><th class="pb-4">Action</th><th class="pb-4">Signal</th><th class="pb-4">Target</th><th class="pb-4">Details</th></tr></thead>
                <tbody class="text-sm">
                    @forelse($auditLogs as $log)
                        @php $metadata = is_string($log['metadata'] ?? null) ? (json_decode($log['metadata'], true) ?: []) : ($log['metadata'] ?? []); @endphp
                        <tr class="border-b border-white/5 align-top">
                            <td class="py-4 text-slate-500 text-xs whitespace-nowrap">{{ \App\Support\AppDate::format($log['created_at'], 'M j, Y g:i A') }}</td>
                            <td class="py-4"><span class="font-bold">{{ $log['actor_name'] }}</span><p class="text-[9px] text-slate-500 uppercase">{{ $log['actor_role'] ?? 'system' }}</p></td>
                            <td class="py-4 text-red-300 font-mono text-xs">{{ $log['action'] }}<p class="text-[9px] text-slate-600 uppercase mt-1">{{ $log['event_category'] ?? 'security' }}</p></td>
                            <td class="py-4"><span class="audit-signal" data-outcome="{{ $log['outcome'] ?? 'succeeded' }}">{{ $log['outcome'] ?? 'succeeded' }}</span><p class="text-[9px] uppercase mt-1 text-slate-500">{{ $log['severity'] ?? 'info' }}</p></td>
                            <td class="py-4 text-slate-400 text-xs">{{ $log['target_type'] ?? '—' }}<p class="font-mono text-[9px] text-slate-600">{{ $log['target_id'] ?? '' }}</p></td>
                            <td class="py-4 text-xs text-slate-400 max-w-sm">{{ !empty($metadata) ? collect($metadata)->map(fn($value, $key) => $key . ': ' . (is_scalar($value) || $value === null ? ($value ?? 'null') : json_encode($value)))->implode(' · ') : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-slate-500 text-xs uppercase">No audit events match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @php
            $auditQuery = array_filter([
                'section' => 'audit', 'audit_search' => $auditFilters['search'],
                'audit_category' => $auditFilters['category'] ?: 'all', 'audit_actor_role' => $auditFilters['actor_role'],
                'audit_action' => $auditFilters['action'], 'audit_outcome' => $auditFilters['outcome'],
                'audit_from' => $auditFilters['from'], 'audit_to' => $auditFilters['to'],
            ], fn($value) => $value !== '');
        @endphp
        @if($auditPages > 1)
            <nav class="flex items-center justify-center gap-4 mt-6" aria-label="Audit log pages">
                @if($auditPage > 1)<a class="btn-rect-secondary !py-2 !px-4 !w-auto" href="/admin/dashboard?{{ http_build_query($auditQuery + ['audit_page' => $auditPage - 1]) }}">Previous</a>@endif
                <span class="text-[10px] text-slate-500 uppercase">Page {{ $auditPage }} of {{ $auditPages }}</span>
                @if($auditPage < $auditPages)<a class="btn-rect-secondary !py-2 !px-4 !w-auto" href="/admin/dashboard?{{ http_build_query($auditQuery + ['audit_page' => $auditPage + 1]) }}">Next</a>@endif
            </nav>
        @endif
    </div>
</section>

{{-- VERIFICATION --}}
<section id="sec-role-verify" class="content-section hidden">
    <div class="portal-frame !p-6 md:!p-8 border-orange-500/20">
        <h2 class="text-xl font-orbitron font-bold mb-6 uppercase">
            Pending <span class="text-orange-400">Verifications</span>
            <span class="ml-2 px-2 py-1 rounded bg-orange-500/15 text-orange-300 text-xs align-middle">{{ $adminPendingTeacherCount ?? count($pendingTeachers) }}</span>
        </h2>
        @if($eventEmailIssue)
            <div class="mb-5 rounded border border-red-500/30 bg-red-500/10 px-4 py-3 text-xs text-red-200">
                <i class="fas fa-envelope-circle-xmark mr-2 text-red-400"></i>
                <span class="font-bold">Teacher decisions are paused.</span> {{ $eventEmailIssue }}
            </div>
        @endif
        <div class="space-y-4">
            @forelse($pendingTeachers as $pt)
                <div class="flex flex-col sm:flex-row justify-between items-center p-4 bg-white/5 border border-white/10 rounded gap-4">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded bg-purple-500/20 flex items-center justify-center text-purple-400 text-xl">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <div>
                            <p class="font-bold">{{ $pt['last_name'] ?? '—' }}, {{ $pt['first_name'] ?? '—' }}</p>
                            <p class="text-[9px] text-slate-500 uppercase font-bold">Teacher Application</p>
                            <p class="text-[10px] text-cyan-500 font-mono">{{ $pt['email'] ?? '' }}</p>
                        </div>
                    </div>
                    <div class="flex gap-2 w-full sm:w-auto">
                        <form method="POST" action="/admin/approve-teacher/{{ $pt['id'] }}" class="flex-1 sm:flex-none">
                            @csrf
                            <button type="submit"
                                    class="w-full bg-green-600 hover:bg-green-500 px-6 py-2 text-[10px] font-bold text-black uppercase rounded">
                                Grant Access
                            </button>
                        </form>
                        <form method="POST" action="/admin/deny-teacher/{{ $pt['id'] }}" class="flex-1 sm:flex-none">
                            @csrf @method('DELETE')
                            <button type="submit"
                                    class="w-full border border-red-500 text-red-500 px-6 py-2 text-[10px] font-bold uppercase rounded">
                                Reject
                            </button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="text-slate-500 text-xs uppercase">No pending verifications at this time.</p>
            @endforelse
        </div>
    </div>
</section>

@include('partials.account-security', [
    'securityAccent' => 'text-red-500',
    'securityBorder' => 'border-red-500/20',
    'securityButton' => '!bg-red-600 !text-white',
])

{{-- PROFILE SECTION --}}
<section id="sec-profile" class="content-section hidden">
    <div class="portal-frame !p-6 md:!p-10 border-red-500/20">
        <h2 class="text-xl font-orbitron font-bold mb-10 uppercase">
            <i class="fas fa-user-secret mr-2"></i> ROOT <span class="text-red-500">PROFILE</span>
        </h2>
        <form method="POST" action="/admin/profile" class="space-y-6 max-w-2xl mx-auto" enctype="multipart/form-data">
            @csrf
            <div class="form-group">
                <label class="input-label">Profile Picture</label>
                <div class="flex items-center gap-4">
                    {{-- Preview circle --}}
                    <div class="w-16 h-16 rounded-full border-2 border-white/10 bg-white/5 flex items-center justify-center overflow-hidden shrink-0" id="avatar-preview-wrap" data-avatar-preview-wrap>
                        <img id="avatar-preview"
                            data-avatar-preview
                            src="{{ $user['avatar_url'] ?: asset('default.png') }}"
                            class="w-full h-full object-cover">
                        </div>
                        <div class="flex-1">
                            <label for="avatar-input"
                                class="cursor-pointer block w-full text-center border border-white/10 bg-white/5 hover:bg-white/10 transition-all rounded px-4 py-3 text-[10px] font-bold uppercase tracking-widest text-slate-400 hover:text-white">
                                <i class="fas fa-upload mr-2"></i> Choose Photo
                            </label>
                            <input type="file" id="avatar-input" name="avatar"
                                accept="image/jpeg,image/png,image/webp" class="hidden">
                            <p class="text-[9px] text-slate-600 mt-1 text-center">JPG, PNG • 2 MB or less</p>
                        </div>
                    </div>
                </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <div class="form-group">
                    <label class="input-label">First Name</label>
                    <div class="relative">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text" name="first_name" value="{{ $user['first_name'] ?? '' }}"
                               class="input-mobile-ultra" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="input-label">Last Name</label>
                    <div class="relative">
                        <i class="fas fa-id-card input-icon"></i>
                        <input type="text" name="last_name" value="{{ $user['last_name'] ?? '' }}"
                               class="input-mobile-ultra" required>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn-rect-primary !bg-red-600 !text-white mt-4">
                <i class="fas fa-database mr-2"></i> Update Profile
            </button>
        </form>
    </div>
</section>

{{-- REPORTS --}}
<section id="sec-reports" class="content-section hidden">
    <h2 class="text-xl md:text-2xl font-orbitron font-bold mb-6 uppercase border-b border-red-500/20 pb-2">
        Export <span class="text-green-400">Reports</span>
    </h2>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">

        <div class="portal-frame !p-6 border-l-4 border-cyan-500">
            <i class="fas fa-user-graduate text-3xl text-cyan-400 mb-4 block"></i>
            <h3 class="font-orbitron font-bold uppercase mb-1">Student Registry</h3>
            <p class="text-slate-500 text-xs mb-6">All students with grade, level, trophies, and join date.</p>
            <div class="flex gap-2">
                <a href="/admin/report/students?format=pdf"
                   class="flex-1 btn-rect-primary !py-2 !text-[10px] text-center">
                    <i class="fas fa-file-pdf mr-1"></i> PDF
                </a>
                <a href="/admin/report/students?format=csv"
                   class="flex-1 btn-rect-secondary !py-2 !text-[10px] text-center">
                    <i class="fas fa-file-csv mr-1"></i> CSV
                </a>
            </div>
        </div>

        <div class="portal-frame !p-6 border-l-4 border-purple-500">
            <i class="fas fa-chalkboard-teacher text-3xl text-purple-400 mb-4 block"></i>
            <h3 class="font-orbitron font-bold uppercase mb-1">Teacher Registry</h3>
            <p class="text-slate-500 text-xs mb-6">All teachers with quizzes created and join date.</p>
            <div class="flex gap-2">
                <a href="/admin/report/teachers?format=pdf"
                   class="flex-1 btn-rect-primary !py-2 !text-[10px] text-center">
                    <i class="fas fa-file-pdf mr-1"></i> PDF
                </a>
                <a href="/admin/report/teachers?format=csv"
                   class="flex-1 btn-rect-secondary !py-2 !text-[10px] text-center">
                    <i class="fas fa-file-csv mr-1"></i> CSV
                </a>
            </div>
        </div>

        <div class="portal-frame !p-6 border-l-4 border-purple-500">
            <i class="fas fa-vr-cardboard text-3xl text-purple-400 mb-4 block"></i>
            <h3 class="font-orbitron font-bold uppercase mb-1">Quiz Performance</h3>
            <p class="text-slate-500 text-xs mb-6">Every class assignment with attempts, average accuracy, and pass rate.</p>
            <div class="flex gap-2">
                <a href="/admin/report/quizzes?format=pdf" class="flex-1 btn-rect-primary !py-2 !text-[10px] text-center"><i class="fas fa-file-pdf mr-1"></i> PDF</a>
                <a href="/admin/report/quizzes?format=csv" class="flex-1 btn-rect-secondary !py-2 !text-[10px] text-center"><i class="fas fa-file-csv mr-1"></i> CSV</a>
            </div>
        </div>

        <div class="portal-frame !p-6 border-l-4 border-yellow-500">
            <i class="fas fa-school text-3xl text-yellow-400 mb-4 block"></i>
            <h3 class="font-orbitron font-bold uppercase mb-1">Classroom Activity</h3>
            <p class="text-slate-500 text-xs mb-6">Class status, enrollment, assignments, attempts, and average accuracy.</p>
            <div class="flex gap-2">
                <a href="/admin/report/classrooms?format=pdf" class="flex-1 btn-rect-primary !py-2 !text-[10px] text-center"><i class="fas fa-file-pdf mr-1"></i> PDF</a>
                <a href="/admin/report/classrooms?format=csv" class="flex-1 btn-rect-secondary !py-2 !text-[10px] text-center"><i class="fas fa-file-csv mr-1"></i> CSV</a>
            </div>
        </div>

        <div class="portal-frame !p-6 border-l-4 border-red-500">
            <i class="fas fa-chart-pie text-3xl text-red-400 mb-4 block"></i>
            <h3 class="font-orbitron font-bold uppercase mb-1">Platform Summary</h3>
            <p class="text-slate-500 text-xs mb-6">Full snapshot: users, quizzes, accuracy, top students.</p>
            <div class="flex gap-2">
                <a href="/admin/report/summary?format=pdf"
                   class="flex-1 btn-rect-primary !bg-red-600 !text-white !py-2 !text-[10px] text-center">
                    <i class="fas fa-file-pdf mr-1"></i> PDF
                </a>
                <a href="/admin/report/summary?format=csv"
                   class="flex-1 btn-rect-secondary !py-2 !text-[10px] text-center">
                    <i class="fas fa-file-csv mr-1"></i> CSV
                </a>
            </div>
        </div>

    </div>
</section>

@endsection

@section('modals')

<div id="suspendUserModal" class="modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="suspend-user-title">
    <div class="portal-frame !p-8 w-full max-w-md text-left border-yellow-500/40">
        <div class="flex justify-between items-start gap-4 mb-6">
            <div>
                <h3 id="suspend-user-title" class="font-orbitron font-bold uppercase">Suspend Account?</h3>
                <p id="suspend-user-name" class="text-xs text-slate-400 mt-2"></p>
            </div>
            <button type="button" data-action="closeModal" data-action-args='["suspendUserModal"]' aria-label="Close" class="text-slate-500 hover:text-white"><i class="fas fa-times"></i></button>
        </div>
        <p class="text-[10px] text-slate-500 mb-5">The user will be signed out and blocked from returning. Their classes, quizzes, and results are preserved.</p>
        <form id="suspendUserForm" method="POST" class="space-y-5">
            @csrf
            <input id="suspend-return-section" type="hidden" name="return_section">
            <div>
                <label for="suspension-reason" class="input-label">Reason</label>
                <textarea id="suspension-reason" name="reason" rows="4" maxlength="500" required class="input-field w-full"></textarea>
            </div>
            <div class="modal-action-stack">
                <button type="submit" class="btn-rect-primary !bg-yellow-500 !text-black">Suspend Account</button>
                <button type="button" data-action="closeModal" data-action-args='["suspendUserModal"]' class="modal-cancel">Cancel</button>
            </div>
        </form>
    </div>
</div>

<div id="deleteUserModal" class="modal-overlay hidden">
    <div class="portal-frame !p-10 w-full max-w-xs text-center border-red-500/50">
        <i class="fas fa-user-minus text-4xl text-red-600 mb-4"></i>
        <h3 class="font-orbitron font-bold mb-2 uppercase text-white">Deactivate User?</h3>
        <p id="delete-user-name" class="text-xs text-slate-400 mb-2"></p>
        <p class="text-[10px] text-slate-500 mb-8">Access will be blocked, but all records are preserved. Reactivate from Trash and Recovery. Permanent deletion is a separate action after seven days.</p>
        <div class="flex flex-col gap-2">
            <form id="deleteUserForm" method="POST">@csrf @method('DELETE')
                <button class="btn-rect-primary !bg-red-600 !text-white uppercase text-xs">Deactivate Account</button>
            </form>
            <button type="button" data-action="closeModal" data-action-args='["deleteUserModal"]' class="modal-cancel mt-3">Cancel</button>
        </div>
    </div>
</div>

<div id="logoutModal" class="modal-overlay hidden">
    <div class="portal-frame !p-10 w-full max-w-xs text-center border-red-500/30 shadow-[0_0_60px_rgba(255,0,0,0.2)]">
        <i class="fas fa-power-off text-4xl text-red-500 mb-4 animate-pulse"></i>
        <h3 class="font-orbitron font-bold mb-2 uppercase text-white">Are you sure you want to logout?</h3>
        <p class="text-[10px] text-slate-500 mb-8 uppercase tracking-widest">Ending Root Session</p>
        <div class="space-y-3">
            <form id="logoutForm" method="POST" action="/logout" class="mt-10">
                @csrf
                <button type="submit" class="btn-rect-primary !bg-red-600 !text-white">Confirm Logout</button>
            </form>
            <button type="button" data-action="closeModal" data-action-args='["logoutModal"]' class="modal-cancel">Cancel</button>
        </div>
    </div>
</div>

@endsection
