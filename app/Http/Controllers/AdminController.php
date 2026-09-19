<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\SupabaseService;
use App\Services\NotificationDeliveryService;
use Barryvdh\DomPDF\Facade\Pdf;

class AdminController extends Controller
{
    public function __construct(
        private SupabaseService $supabase,
        private NotificationDeliveryService $notificationDelivery,
    ) {}

    public function index(Request $request)
    {
        $user = session('supabase_user');

        $selectedGrade = (int) $request->query('student_grade', $request->query('grade', 0));
        $selectedGrade = ($selectedGrade >= 1 && $selectedGrade <= 6) ? $selectedGrade : 0;
        $studentSearch = $this->registrySearch($request->query('student_search', ''));
        $teacherSearch = $this->registrySearch($request->query('teacher_search', ''));
        $studentSort = $this->registrySort((string) $request->query('student_sort', 'name_asc'));
        $teacherSort = $this->registrySort((string) $request->query('teacher_sort', 'name_asc'));
        $studentPage = max(1, (int) $request->query('student_page', 1));
        $teacherPage = max(1, (int) $request->query('teacher_page', 1));
        $perPage = 25;

        $studentFilters = ['role' => 'student', 'order' => $this->registryOrder($studentSort),
            'deactivated_at' => ['operator' => 'is', 'value' => 'null']];
        if ($selectedGrade !== 0) {
            $studentFilters['grade_level'] = $selectedGrade;
        }
        if ($studentSearch !== '') {
            $studentFilters['or'] = $this->registryOrFilter($studentSearch);
        }

        $teacherFilters = ['role' => 'teacher', 'order' => $this->registryOrder($teacherSort),
            'deactivated_at' => ['operator' => 'is', 'value' => 'null']];
        if ($teacherSearch !== '') {
            $teacherFilters['or'] = $this->registryOrFilter($teacherSearch);
        }

        $studentResult = $this->supabase->adminSelectPage(
            'profiles', '*', $studentFilters, $perPage, ($studentPage - 1) * $perPage
        );
        $teacherResult = $this->supabase->adminSelectPage(
            'profiles', '*', $teacherFilters, $perPage, ($teacherPage - 1) * $perPage
        );

        $students = $studentResult['data'];
        $teachers = $teacherResult['data'];
        $studentTotal = $studentResult['total'];
        $teacherTotal = $teacherResult['total'];
        $studentPages = max(1, (int) ceil($studentTotal / $perPage));
        $teacherPages = max(1, (int) ceil($teacherTotal / $perPage));

        if ($studentPage > $studentPages && $studentTotal > 0) {
            return redirect()->to($request->fullUrlWithQuery(['student_page' => $studentPages, 'section' => 'students']));
        }
        if ($teacherPage > $teacherPages && $teacherTotal > 0) {
            return redirect()->to($request->fullUrlWithQuery(['teacher_page' => $teacherPages, 'section' => 'teachers']));
        }

        $totalStudents = $this->supabase->adminCount('profiles', ['role' => 'student']);
        $totalTeachers = $this->supabase->adminCount('profiles', ['role' => 'teacher']);
        $totalPending = $this->supabase->adminCount('profiles', ['role' => 'pending_teacher']);
        $totalUsers = $totalStudents + $totalTeachers + $totalPending;
        $totalQuizzes = $this->supabase->adminCount('quizzes');
        $pendingTeachers = $this->supabase->adminSelect(
            'profiles', '*', ['role' => 'pending_teacher', 'order' => 'created_at.asc',
                'deactivated_at' => ['operator' => 'is', 'value' => 'null']]
        );
        $pendingReportCount = $this->supabase->adminCount('quiz_reports', ['status' => 'pending']);

        $auditPage = max(1, (int) $request->query('audit_page', 1));
        $auditFilters = $this->auditFilters($request);
        $auditResult = $this->supabase->adminRpcResult('search_audit_logs', [
            'p_search' => $auditFilters['search'] ?: null,
            'p_category' => $auditFilters['category'] ?: null,
            'p_actor_role' => $auditFilters['actor_role'] ?: null,
            'p_action' => $auditFilters['action'] ?: null,
            'p_outcome' => $auditFilters['outcome'] ?: null,
            'p_from' => $this->auditDate($auditFilters['from']),
            'p_to' => $this->auditDate($auditFilters['to'], true),
            'p_limit' => 30,
            'p_offset' => ($auditPage - 1) * 30,
        ]);
        $auditSearchReady = $auditResult['error'] === null;
        if ($auditSearchReady) {
            $auditLogs = $auditResult['data'];
            $auditTotal = (int) ($auditLogs[0]['total_count'] ?? 0);
        } else {
            $legacy = $this->supabase->adminSelectPage(
                'audit_logs', '*', ['order' => 'created_at.desc'], 30, ($auditPage - 1) * 30
            );
            $auditLogs = $legacy['data'];
            $auditTotal = $legacy['total'];
        }
        $auditPages = max(1, (int) ceil($auditTotal / 30));
        if ($auditPage > $auditPages && $auditTotal > 0) {
            return redirect()->to($request->fullUrlWithQuery([
                'audit_page' => $auditPages,
                'section' => 'audit',
            ]));
        }
        $actorIds = array_values(array_unique(array_filter(array_map(
            fn (array $log): mixed => trim((string) ($log['actor_name'] ?? '')) === '' ? ($log['actor_id'] ?? null) : null,
            $auditLogs
        ))));
        $actors = empty($actorIds) ? [] : $this->supabase->adminSelect(
            'profiles', 'id,first_name,last_name',
            ['id' => ['operator' => 'in', 'value' => '(' . implode(',', $actorIds) . ')']]
        );
        $actorNames = [];
        foreach ($actors as $actor) {
            $actorNames[$actor['id']] = trim(
                ($actor['first_name'] ?? '') . ' ' . ($actor['last_name'] ?? '')
            ) ?: 'Administrator';
        }
        foreach ($auditLogs as &$log) {
            $log['actor_name'] = trim((string) ($log['actor_name'] ?? ''))
                ?: ($actorNames[$log['actor_id'] ?? ''] ?? 'System');
        }
        unset($log);

        $eventEmailIssue = $this->teacherDecisionEmailIssue();

        return view('admin.dashboard', compact(
            'user', 'students', 'teachers', 'selectedGrade', 'totalUsers', 'totalTeachers',
            'totalStudents', 'totalQuizzes', 'pendingTeachers', 'studentSearch', 'teacherSearch',
            'studentSort', 'teacherSort', 'studentPage', 'teacherPage', 'studentPages',
            'teacherPages', 'studentTotal', 'teacherTotal', 'auditLogs', 'auditPage',
            'auditPages', 'auditTotal', 'auditFilters', 'auditSearchReady',
            'pendingReportCount', 'eventEmailIssue'
        ));
    }

    public function deleteUser(string $id)
    {
        return app(RecoveryController::class)->deactivate($id);
    }

    public function approveTeacher(string $id)
    {
        $profile = $this->supabase->adminSelect(
            'profiles', 'id,role,first_name,last_name,email', ['id' => $id,
                'deactivated_at' => ['operator' => 'is', 'value' => 'null']]
        )[0] ?? null;
        if (!$profile || ($profile['role'] ?? '') !== 'pending_teacher') {
            return redirect('/admin/dashboard?section=role-verify')
                ->with('error', 'Only a pending teacher application can be approved.');
        }
        if (filter_var((string) ($profile['email'] ?? ''), FILTER_VALIDATE_EMAIL) === false) {
            return redirect('/admin/dashboard?section=role-verify')
                ->with('error', 'The teacher was not approved because the application has no valid email address.');
        }
        if (($emailIssue = $this->teacherDecisionEmailIssue()) !== null) {
            return redirect('/admin/dashboard?section=role-verify')
                ->with('error', 'The teacher was not approved because the decision email is unavailable. ' . $emailIssue);
        }

        $auditMetadata = [
            'name' => trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')),
            'email' => $profile['email'] ?? null,
        ];
        $intentId = $this->supabase->beginPrivilegedAudit(
            session('supabase_user'), 'teacher.approved', 'profile', $id, $auditMetadata
        );
        if ($intentId === null) {
            return redirect('/admin/dashboard?section=role-verify')
                ->with('error', 'Approval was blocked because the secure audit trail is unavailable. Check System Health and try again.');
        }

        $updated = $this->supabase->adminUpdate(
            'profiles', ['role' => 'teacher'], ['id' => $id, 'role' => 'pending_teacher',
                'deactivated_at' => ['operator' => 'is', 'value' => 'null']]
        );
        if (!isset($updated[0]['id'])) {
            $this->supabase->completePrivilegedAudit(
                $intentId, false, ['stage' => 'role_update'], 'The teacher role update failed.'
            );
            return redirect('/admin/dashboard?section=role-verify')
                ->with('error', 'The teacher application could not be approved.');
        }

        $decisionEmail = $this->notificationDelivery
            ->deliverTeacherApprovalEmailNow($profile);
        $this->supabase->completePrivilegedAudit($intentId, true, [
            'decision_email_sent' => $decisionEmail['sent'],
            'decision_email_queued' => $decisionEmail['queued'],
        ]);

        if (!$decisionEmail['sent']) {
            return redirect('/admin/dashboard?section=role-verify')
                ->with(
                    'error',
                    $decisionEmail['queued']
                        ? 'Teacher approved, but the mail server did not accept the approval email immediately. MathVerse will retry it automatically.'
                        : 'Teacher approved, but the approval email could not be sent or queued. Check the mail and database settings.'
                );
        }

        return redirect('/admin/dashboard?section=role-verify')
            ->with('success', 'Teacher approved. The approval email was sent.');
    }

    public function denyTeacher(string $id)
    {
        $profile = $this->supabase->adminSelect(
            'profiles', 'id,role,first_name,last_name,email', ['id' => $id]
        )[0] ?? null;
        if (!$profile || ($profile['role'] ?? '') !== 'pending_teacher') {
            return redirect('/admin/dashboard?section=role-verify')
                ->with('error', 'Only a pending teacher application can be rejected.');
        }
        if (filter_var((string) ($profile['email'] ?? ''), FILTER_VALIDATE_EMAIL) === false) {
            return redirect('/admin/dashboard?section=role-verify')
                ->with('error', 'The application was not rejected because it has no valid decision-email address.');
        }
        if (($emailIssue = $this->teacherDecisionEmailIssue()) !== null) {
            return redirect('/admin/dashboard?section=role-verify')
                ->with('error', 'The application was not rejected because the decision email is unavailable. ' . $emailIssue);
        }

        $teacherName = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
        $intentId = $this->supabase->beginPrivilegedAudit(
            session('supabase_user'), 'teacher.rejected', 'profile', $id,
            ['name' => $teacherName, 'email' => $profile['email'] ?? null]
        );
        if ($intentId === null) {
            return redirect('/admin/dashboard?section=role-verify')
                ->with('error', 'Rejection was blocked because the secure audit trail is unavailable. Check System Health and try again.');
        }

        try {
            $result = $this->supabase->adminRpcResult('set_account_deactivated', [
                'p_actor_id' => session('supabase_user.id'), 'p_id' => $id, 'p_restore' => false,
            ]);
            $deleted = $result['error'] === null && ($result['data'][0]['id'] ?? null) === $id;
        } catch (\Throwable $exception) {
            Log::warning('Pending teacher deletion failed.', [
                'target_user_id' => $id,
                'exception' => $exception::class,
            ]);
            $deleted = false;
        }

        if (!$deleted) {
            $this->supabase->completePrivilegedAudit(
                $intentId, false, ['stage' => 'account_deactivation'], 'The pending account deactivation failed.'
            );
            return redirect('/admin/dashboard?section=role-verify')
                ->with('error', 'Failed to reject application.');
        }

        $decisionEmail = $this->notificationDelivery->deliverStandaloneEmailNow(
            eventType: 'teacher_denied',
            recipientEmail: (string) ($profile['email'] ?? ''),
            recipientName: $teacherName,
            title: 'Teacher application not approved',
            message: 'An administrator reviewed your MathVerse teacher application, did not approve it, and closed the pending registration.',
            actionUrl: '/',
            data: [],
            deliveryKey: 'teacher-denied:' . $id,
        );

        $this->supabase->completePrivilegedAudit($intentId, true, [
            'decision_email_sent' => $decisionEmail['sent'],
            'decision_email_queued' => $decisionEmail['queued'],
        ]);

        $redirect = redirect('/admin/dashboard?section=role-verify');
        if (!$decisionEmail['sent']) {
            return $redirect->with(
                'error',
                $decisionEmail['queued']
                    ? 'Application rejected, but the mail server did not accept the decision email immediately. MathVerse will retry it automatically.'
                    : 'Application rejected, but the decision email could not be sent or queued. Check the mail and database settings.'
            );
        }

        return $redirect->with('success', 'Application rejected. The decision email was sent.');
    }

    public function suspendUser(Request $request, string $id)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
            'return_section' => 'nullable|in:students,teachers',
        ]);
        $profile = $this->manageableProfile($id);
        $section = $validated['return_section'] ?? (($profile['role'] ?? '') === 'teacher' ? 'teachers' : 'students');

        if (!$profile) {
            return redirect("/admin/dashboard?section={$section}")
                ->with('error', 'Only student and teacher accounts can be suspended.');
        }
        if (!empty($profile['suspended_at'])) {
            return redirect("/admin/dashboard?section={$section}")
                ->with('error', 'That account is already suspended.');
        }

        $admin = session('supabase_user');
        $reason = trim($validated['reason']);
        $intentId = $this->supabase->beginPrivilegedAudit($admin, 'user.suspended', 'profile', $id, [
            'role' => $profile['role'],
            'reason' => $reason,
        ]);
        if ($intentId === null) {
            return redirect("/admin/dashboard?section={$section}")
                ->with('error', 'Suspension was blocked because the secure audit trail is unavailable. Check System Health and try again.');
        }
        if (!$this->supabase->setAuthUserSuspended($id, true)) {
            $this->supabase->completePrivilegedAudit(
                $intentId, false, ['stage' => 'authentication_suspend'], 'The authentication suspension failed.'
            );
            return redirect("/admin/dashboard?section={$section}")
                ->with('error', 'The authentication service could not suspend that account. No profile changes were made.');
        }
        $notificationWindowStart = now()->subSeconds(10)->utc()->toIso8601String();
        $updated = $this->supabase->adminUpdate('profiles', [
            'suspended_at' => now()->toIso8601String(),
            'suspended_by' => $admin['id'],
            'suspension_reason' => $reason,
        ], ['id' => $id, 'role' => $profile['role']]);

        if (!isset($updated[0]['id'])) {
            $rolledBack = $this->supabase->setAuthUserSuspended($id, false);
            $this->supabase->completePrivilegedAudit(
                $intentId, false,
                ['stage' => 'profile_suspend', 'authentication_rollback' => $rolledBack],
                'The profile suspension update failed.'
            );
            return redirect("/admin/dashboard?section={$section}")
                ->with('error', 'The account could not be suspended.');
        }

        $statusEmail = $this->notificationDelivery->deliverNotificationEmailNow(
            $id,
            'account_suspended',
            createdAfter: $notificationWindowStart,
        );
        $this->supabase->completePrivilegedAudit($intentId, true, [
            'status_email_sent' => $statusEmail['sent'],
            'status_email_queued' => $statusEmail['queued'],
        ]);

        if (!$statusEmail['sent']) {
            return redirect("/admin/dashboard?section={$section}")
                ->with(
                    'error',
                    $statusEmail['queued']
                        ? 'Account suspended, but the mail server did not accept the status email immediately. MathVerse will retry it automatically.'
                        : 'Account suspended, but its status email could not be sent or queued. Check the mail and database settings.'
                );
        }

        return redirect("/admin/dashboard?section={$section}")
            ->with('success', 'Account suspended. Its data was preserved, and the status email was sent.');
    }

    public function restoreUser(Request $request, string $id)
    {
        $profile = $this->manageableProfile($id);
        $section = $request->input('return_section') === 'teachers' ? 'teachers' : 'students';
        if (!$profile || empty($profile['suspended_at'])) {
            return redirect("/admin/dashboard?section={$section}")
                ->with('error', 'That account is not suspended.');
        }

        $admin = session('supabase_user');
        $intentId = $this->supabase->beginPrivilegedAudit($admin, 'user.restored', 'profile', $id, [
            'role' => $profile['role'],
        ]);
        if ($intentId === null) {
            return redirect("/admin/dashboard?section={$section}")
                ->with('error', 'Restoration was blocked because the secure audit trail is unavailable. Check System Health and try again.');
        }
        if (!$this->supabase->setAuthUserSuspended($id, false)) {
            $this->supabase->completePrivilegedAudit(
                $intentId, false, ['stage' => 'authentication_restore'], 'The authentication restoration failed.'
            );
            return redirect("/admin/dashboard?section={$section}")
                ->with('error', 'The authentication service could not restore that account. It remains suspended.');
        }
        $notificationWindowStart = now()->subSeconds(10)->utc()->toIso8601String();
        $updated = $this->supabase->adminUpdate('profiles', [
            'suspended_at' => null,
            'suspended_by' => null,
            'suspension_reason' => null,
        ], ['id' => $id, 'role' => $profile['role']]);

        if (!isset($updated[0]['id'])) {
            $rolledBack = $this->supabase->setAuthUserSuspended($id, true);
            $this->supabase->completePrivilegedAudit(
                $intentId, false,
                ['stage' => 'profile_restore', 'authentication_rollback' => $rolledBack],
                'The profile restoration update failed.'
            );
            return redirect("/admin/dashboard?section={$section}")
                ->with('error', 'The account could not be restored.');
        }

        $statusEmail = $this->notificationDelivery->deliverNotificationEmailNow(
            $id,
            'account_restored',
            createdAfter: $notificationWindowStart,
        );
        $this->supabase->completePrivilegedAudit($intentId, true, [
            'status_email_sent' => $statusEmail['sent'],
            'status_email_queued' => $statusEmail['queued'],
        ]);

        if (!$statusEmail['sent']) {
            return redirect("/admin/dashboard?section={$section}")
                ->with(
                    'error',
                    $statusEmail['queued']
                        ? 'Account restored, but the mail server did not accept the status email immediately. MathVerse will retry it automatically.'
                        : 'Account restored, but its status email could not be sent or queued. Check the mail and database settings.'
                );
        }

        return redirect("/admin/dashboard?section={$section}")
            ->with('success', 'Account restored. The status email was sent.');
    }

    public function updateProfile(Request $request)
    {
        if ($avatarError = $this->rejectInvalidAvatar($request, '/admin/dashboard?section=profile')) {
            return $avatarError;
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
        ]);

        $user  = session('supabase_user');
        $userId = $user['id'];

        // ── UPDATE BASIC INFO
        try {
            $profileUpdated = $this->supabase->updateProfile($userId, [
                'first_name'  => $validated['first_name'],
                'last_name'   => $validated['last_name'],
                'grade_level' => 0,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('An administrator profile could not be updated.', [
                'user_id' => $userId,
                'exception' => $exception::class,
            ]);

            return redirect('/admin/dashboard?section=profile')
                ->with('error', 'The profile service is temporarily unavailable. Please try again.');
        }
        if (!isset($profileUpdated[0]['id'])) {
            return redirect('/admin/dashboard?section=profile')
                ->with('error', 'The profile could not be updated.');
        }

        // ── UPLOAD AVATAR (USE SAME USER ID)
        $avatarUrl = null;

        if ($request->hasFile('avatar')) {
            $avatarResult = $this->replaceProfileAvatar(
                $this->supabase,
                $userId,
                $request->file('avatar'),
                $user['avatar_url'] ?? null
            );
            $avatarUrl = $avatarResult['url'];

            if ($avatarResult['error'] === 'upload') {
                return redirect('/admin/dashboard?section=profile')
                    ->with('error', 'Your profile details were saved, but the new avatar could not be uploaded.');
            }

            if ($avatarResult['error'] === 'attach') {
                return redirect('/admin/dashboard?section=profile')
                    ->with('error', 'Your profile details were saved, but the new avatar could not be attached.');
            }
        }

        // ── UPDATE SESSION
        $updated = session('supabase_user');
        $updated['first_name']  = $validated['first_name'];
        $updated['last_name']   = $validated['last_name'];
        $updated['grade_level'] = 0;

        if ($avatarUrl) {
            $updated['avatar_url'] = $avatarUrl;
        }

        session(['supabase_user' => $updated]);

        $this->supabase->audit($updated, 'profile.updated', 'profile', $userId, [
            'avatar_changed' => $avatarUrl !== null,
        ]);

        return redirect('/admin/dashboard?section=profile')->with('success', 'Profile updated successfully!');
    }

    public function stats()
    {
        // All 3 calls happen as fast as possible — no loops making extra calls
        $allResults = $this->supabase->adminSelect(
            'quiz_results',
            'correct_answers,total_questions,created_at,session_id,student_id',
            ['is_counted' => true, 'order' => 'created_at.asc']
        );
        $quizzes    = $this->supabase->adminSelect('quizzes', 'id,topic,teacher_id,created_at');
        $profiles   = $this->supabase->adminSelect('profiles', 'id,role,created_at');

        // Group UTC database instants by the same local day as the chart labels.
        $registrationCounts = \App\Support\AppDate::countsByDay($profiles);
        $attemptCounts = \App\Support\AppDate::countsByDay($allResults);
        $today = \Carbon\CarbonImmutable::now(\App\Support\AppDate::timezone())->startOfDay();
        $registrationsPerDay = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = $today->subDays($i);
            $label = $day->format('M d');
            $count = $registrationCounts[$day->toDateString()] ?? 0;
            $registrationsPerDay[] = ['date' => $label, 'count' => $count];
        }

        // Attempts per day
        $attemptsPerDay = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = $today->subDays($i);
            $label = $day->format('M d');
            $count = $attemptCounts[$day->toDateString()] ?? 0;
            $attemptsPerDay[] = ['date' => $label, 'count' => $count];
        }

        // Role breakdown
        $roleBreakdown = [
            'students' => count(array_filter($profiles, fn($p) => $p['role'] === 'student')),
            'teachers' => count(array_filter($profiles, fn($p) => $p['role'] === 'teacher')),
            'pending'  => count(array_filter($profiles, fn($p) => $p['role'] === 'pending_teacher')),
        ];

        // Score distribution
        $distribution = [0, 0, 0, 0];
        foreach ($allResults as $r) {
            if (($r['total_questions'] ?? 0) === 0) continue;
            $pct = ($r['correct_answers'] / $r['total_questions']) * 100;
            if      ($pct <= 25) $distribution[0]++;
            elseif  ($pct <= 50) $distribution[1]++;
            elseif  ($pct <= 75) $distribution[2]++;
            else                 $distribution[3]++;
        }

        $totalAttempts = count($allResults);
        $avgAccuracy   = $totalAttempts > 0
            ? round(array_sum(array_map(fn($r) =>
                ($r['total_questions'] ?? 0) > 0
                    ? ($r['correct_answers'] / $r['total_questions']) * 100 : 0,
                $allResults)) / $totalAttempts, 1)
            : 0;

        return response()->json([
            'registrationsPerDay' => $registrationsPerDay,
            'attemptsPerDay'      => $attemptsPerDay,
            'roleBreakdown'       => $roleBreakdown,
            'distribution'        => $distribution,
            'totalAttempts'       => $totalAttempts,
            'totalQuizzes'        => count($quizzes),
            'totalUsers'          => count($profiles),
            'avgAccuracy'         => $avgAccuracy,
        ]);
    }

    public function reportStudents(Request $request)
    {
        $format   = $request->query('format', 'pdf');
        $profiles = $this->supabase->adminSelect('profiles', '*', ['role' => 'student']);

        $rows = array_map(fn($p) => [
            'name'     => ($p['last_name'] ?? '') . ', ' . ($p['first_name'] ?? ''),
            'email'    => $p['email'] ?? '',
            'grade'    => $p['grade_level'] ? 'Grade ' . $p['grade_level'] : 'N/A',
            'trophies' => $p['trophies'] ?? 0,
            'level'    => $p['level'] ?? 1,
            'joined'   => isset($p['created_at'])
                ? \App\Support\AppDate::format($p['created_at'], 'M d, Y')
                : 'N/A',
        ], $profiles);

        usort($rows, fn($a, $b) => strcmp($a['name'], $b['name']));

        if ($format === 'csv') {
            return $this->downloadCsv($rows,
                ['Name', 'Email', 'Grade', 'Level', 'Trophies', 'Date Joined'],
                ['name', 'email', 'grade', 'level', 'trophies', 'joined'],
                'student-registry-report'
            );
        }

        $pdf = Pdf::loadView('reports.students', [
            'rows'      => $rows,
            'generated' => now()->format('M d, Y h:i A'),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('student-registry-report.pdf');
    }

    public function reportTeachers(Request $request)
    {
        $format   = $request->query('format', 'pdf');
        $profiles = $this->supabase->adminSelect('profiles', '*', ['role' => 'teacher']);

        // Get quiz count per teacher
        $allQuizzes = $this->supabase->adminSelect('quizzes', 'id,teacher_id');

        $rows = array_map(function($p) use ($allQuizzes) {
            $quizCount = count(array_filter($allQuizzes, fn($q) => $q['teacher_id'] === $p['id']));
            return [
                'name'       => ($p['last_name'] ?? '') . ', ' . ($p['first_name'] ?? ''),
                'email'      => $p['email'] ?? '',
                'grade'      => $p['grade_level'] ? 'Grade ' . $p['grade_level'] : 'N/A',
                'quizzes'    => $quizCount,
                'joined'     => isset($p['created_at'])
                    ? \App\Support\AppDate::format($p['created_at'], 'M d, Y')
                    : 'N/A',
            ];
        }, $profiles);

        usort($rows, fn($a, $b) => strcmp($a['name'], $b['name']));

        if ($format === 'csv') {
            return $this->downloadCsv($rows,
                ['Name', 'Email', 'Quizzes Created', 'Date Joined'],
                ['name', 'email', 'quizzes', 'joined'],
                'teacher-registry-report'
            );
        }

        $pdf = Pdf::loadView('reports.teachers', [
            'rows'      => $rows,
            'generated' => now()->format('M d, Y h:i A'),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('teacher-registry-report.pdf');
    }

    public function reportSummary(Request $request)
    {
        $format     = $request->query('format', 'pdf');
        $profiles   = $this->supabase->adminSelect('profiles', '*');
        $quizzes    = $this->supabase->adminSelect('quizzes', 'id');
        $allResults = $this->supabase->adminSelect(
            'quiz_results',
            'correct_answers,total_questions,student_id,session_id,created_at',
            ['is_counted' => true, 'order' => 'created_at.asc']
        );

        $students = array_values(array_filter($profiles, fn($p) => $p['role'] === 'student'));
        $teachers = array_values(array_filter($profiles, fn($p) => $p['role'] === 'teacher'));
        $pending  = array_values(array_filter($profiles, fn($p) => $p['role'] === 'pending_teacher'));

        $totalAttempts = count($allResults);
        $avgAccuracy   = 0;
        if ($totalAttempts > 0) {
            $avgAccuracy = round(array_sum(array_map(fn($r) =>
                $r['total_questions'] > 0 ? ($r['correct_answers'] / $r['total_questions']) * 100 : 0,
                $allResults)) / $totalAttempts, 1);
        }

        // Top 10 students by trophies
        usort($students, fn($a, $b) => ($b['trophies'] ?? 0) - ($a['trophies'] ?? 0));
        $top10 = array_slice($students, 0, 10);
        $top10 = array_map(fn($s) => [
            'name'     => ($s['last_name'] ?? '') . ', ' . ($s['first_name'] ?? ''),
            'grade'    => 'Grade ' . ($s['grade_level'] ?? 'N/A'),
            'trophies' => $s['trophies'] ?? 0,
        ], $top10);

        $summary = [
            'total_users'    => count($students) + count($teachers) + count($pending),
            'total_students' => count($students),
            'total_teachers' => count($teachers),
            'total_pending'  => count($pending),
            'total_quizzes'  => count($quizzes),
            'total_attempts' => $totalAttempts,
            'avg_accuracy'   => $avgAccuracy . '%',
            'generated'      => now()->format('M d, Y h:i A'),
        ];

        if ($format === 'csv') {
            $summaryRows = [
                ['Metric', 'Value'],
                ['Total Users',    $summary['total_users']],
                ['Total Students', $summary['total_students']],
                ['Total Teachers', $summary['total_teachers']],
                ['Pending Teachers', $summary['total_pending']],
                ['Total Quizzes',  $summary['total_quizzes']],
                ['Total Attempts', $summary['total_attempts']],
                ['Avg Accuracy',   $summary['avg_accuracy']],
            ];
            return response()->streamDownload(function () use ($summaryRows, $top10) {
                $out = fopen('php://output', 'w');
                foreach ($summaryRows as $row) {
                    $this->writeCsvRow($out, $row);
                }
                $this->writeCsvRow($out, []);
                $this->writeCsvRow($out, ['Top 10 Students by Trophies']);
                $this->writeCsvRow($out, ['Name', 'Grade', 'Trophies']);
                foreach ($top10 as $student) {
                    $this->writeCsvRow($out, [$student['name'], $student['grade'], $student['trophies']]);
                }
                fclose($out);
            }, 'platform-summary.csv', ['Content-Type' => 'text/csv']);
        }

        $pdf = Pdf::loadView('reports.summary', compact('summary', 'top10'))
            ->setPaper('a4', 'portrait');

        return $pdf->download('platform-summary-report.pdf');
    }

    public function reportQuizzes(Request $request)
    {
        $format = $request->query('format', 'pdf');
        $sessions = $this->supabase->adminSelect(
            'quiz_sessions',
            'id,teacher_id,class_id,topic,room_code,status,created_at,ended_at',
            [
                'class_id' => ['operator' => 'not.is', 'value' => 'null'],
                'order' => 'created_at.desc',
            ]
        );
        $sessionIds = array_column($sessions, 'id');
        $classIds = array_values(array_unique(array_filter(array_column($sessions, 'class_id'))));
        $teacherIds = array_values(array_unique(array_filter(array_column($sessions, 'teacher_id'))));

        $results = empty($sessionIds) ? [] : $this->supabase->adminSelect(
            'quiz_results',
            'session_id,correct_answers,total_questions',
            [
                'session_id' => ['operator' => 'in', 'value' => '(' . implode(',', $sessionIds) . ')'],
                'is_counted' => true,
            ]
        );
        $questions = empty($sessionIds) ? [] : $this->supabase->adminSelect(
            'questions',
            'id,session_id',
            ['session_id' => ['operator' => 'in', 'value' => '(' . implode(',', $sessionIds) . ')']]
        );
        $classes = empty($classIds) ? [] : $this->supabase->adminSelect(
            'classes',
            'id,class_name',
            ['id' => ['operator' => 'in', 'value' => '(' . implode(',', $classIds) . ')']]
        );
        $teachers = empty($teacherIds) ? [] : $this->supabase->adminSelect(
            'profiles',
            'id,first_name,last_name',
            ['id' => ['operator' => 'in', 'value' => '(' . implode(',', $teacherIds) . ')']]
        );

        $classMap = array_column($classes, null, 'id');
        $teacherMap = array_column($teachers, null, 'id');
        $resultsBySession = [];
        foreach ($results as $result) {
            $resultsBySession[$result['session_id']][] = $result;
        }
        $questionCounts = array_count_values(array_column($questions, 'session_id'));

        $rows = [];
        foreach ($sessions as $session) {
            $sessionResults = $resultsBySession[$session['id']] ?? [];
            $accuracies = array_map(
                fn (array $result): float => (int) ($result['total_questions'] ?? 0) > 0
                    ? ((int) ($result['correct_answers'] ?? 0) / (int) $result['total_questions']) * 100
                    : 0,
                $sessionResults
            );
            $passed = count(array_filter($accuracies, fn (float $accuracy): bool => $accuracy >= 75));
            $teacher = $teacherMap[$session['teacher_id']] ?? [];

            $rows[] = [
                'topic' => $session['topic'] ?? 'Untitled Quiz',
                'class_name' => $classMap[$session['class_id']]['class_name'] ?? 'Deleted Class',
                'teacher' => trim(($teacher['last_name'] ?? '') . ', ' . ($teacher['first_name'] ?? ''), ', ') ?: 'Deleted User',
                'room_code' => $session['room_code'] ?? '—',
                'status' => ucfirst((string) ($session['status'] ?? 'waiting')),
                'questions' => $questionCounts[$session['id']] ?? 0,
                'attempts' => count($sessionResults),
                'avg_accuracy' => $accuracies !== [] ? round(array_sum($accuracies) / count($accuracies), 1) : null,
                'pass_rate' => $sessionResults !== [] ? round(($passed / count($sessionResults)) * 100, 1) : null,
                'created' => \App\Support\AppDate::format($session['created_at'], 'M d, Y'),
            ];
        }

        if ($format === 'csv') {
            return $this->downloadCsv(
                $rows,
                ['Quiz', 'Class', 'Teacher', 'Room Code', 'Status', 'Questions', 'Attempts', 'Avg Accuracy %', 'Pass Rate %', 'Assigned'],
                ['topic', 'class_name', 'teacher', 'room_code', 'status', 'questions', 'attempts', 'avg_accuracy', 'pass_rate', 'created'],
                'platform-quiz-performance-report'
            );
        }

        return Pdf::loadView('reports.admin-quizzes', [
            'rows' => $rows,
            'generated' => now()->format('M d, Y h:i A'),
        ])->setPaper('a4', 'landscape')->download('platform-quiz-performance-report.pdf');
    }

    public function reportClassrooms(Request $request)
    {
        $format = $request->query('format', 'pdf');
        $classes = $this->supabase->adminSelect('classes', '*', ['order' => 'created_at.desc']);
        $classIds = array_column($classes, 'id');
        $teacherIds = array_values(array_unique(array_filter(array_column($classes, 'teacher_id'))));
        $members = empty($classIds) ? [] : $this->supabase->adminSelect(
            'class_members',
            'class_id,student_id',
            ['class_id' => ['operator' => 'in', 'value' => '(' . implode(',', $classIds) . ')']]
        );
        $sessions = empty($classIds) ? [] : $this->supabase->adminSelect(
            'quiz_sessions',
            'id,class_id',
            ['class_id' => ['operator' => 'in', 'value' => '(' . implode(',', $classIds) . ')']]
        );
        $sessionIds = array_column($sessions, 'id');
        $results = empty($sessionIds) ? [] : $this->supabase->adminSelect(
            'quiz_results',
            'session_id,correct_answers,total_questions',
            [
                'session_id' => ['operator' => 'in', 'value' => '(' . implode(',', $sessionIds) . ')'],
                'is_counted' => true,
            ]
        );
        $teachers = empty($teacherIds) ? [] : $this->supabase->adminSelect(
            'profiles',
            'id,first_name,last_name',
            ['id' => ['operator' => 'in', 'value' => '(' . implode(',', $teacherIds) . ')']]
        );

        $teacherMap = array_column($teachers, null, 'id');
        $memberCounts = array_count_values(array_column($members, 'class_id'));
        $sessionCounts = array_count_values(array_column($sessions, 'class_id'));
        $sessionClassMap = array_column($sessions, 'class_id', 'id');
        $resultsByClass = [];
        foreach ($results as $result) {
            $classId = $sessionClassMap[$result['session_id']] ?? null;
            if ($classId !== null) {
                $resultsByClass[$classId][] = $result;
            }
        }

        $rows = [];
        foreach ($classes as $class) {
            $classResults = $resultsByClass[$class['id']] ?? [];
            $accuracies = array_map(
                fn (array $result): float => (int) ($result['total_questions'] ?? 0) > 0
                    ? ((int) ($result['correct_answers'] ?? 0) / (int) $result['total_questions']) * 100
                    : 0,
                $classResults
            );
            $teacher = $teacherMap[$class['teacher_id']] ?? [];
            $rows[] = [
                'class_name' => $class['class_name'] ?? 'Untitled Class',
                'teacher' => trim(($teacher['last_name'] ?? '') . ', ' . ($teacher['first_name'] ?? ''), ', ') ?: 'Deleted User',
                'grade' => 'Grade ' . ($class['grade_level'] ?? 'N/A'),
                'status' => empty($class['archived_at']) ? 'Active' : 'Archived',
                'students' => $memberCounts[$class['id']] ?? 0,
                'assignments' => $sessionCounts[$class['id']] ?? 0,
                'attempts' => count($classResults),
                'avg_accuracy' => $accuracies !== [] ? round(array_sum($accuracies) / count($accuracies), 1) : null,
                'created' => \App\Support\AppDate::format($class['created_at'], 'M d, Y'),
            ];
        }

        if ($format === 'csv') {
            return $this->downloadCsv(
                $rows,
                ['Class', 'Teacher', 'Grade', 'Status', 'Students', 'Quiz Assignments', 'Attempts', 'Avg Accuracy %', 'Created'],
                ['class_name', 'teacher', 'grade', 'status', 'students', 'assignments', 'attempts', 'avg_accuracy', 'created'],
                'platform-classroom-activity-report'
            );
        }

        return Pdf::loadView('reports.admin-classrooms', [
            'rows' => $rows,
            'generated' => now()->format('M d, Y h:i A'),
        ])->setPaper('a4', 'landscape')->download('platform-classroom-activity-report.pdf');
    }

    private function teacherDecisionEmailIssue(): ?string
    {
        if (!$this->notificationDelivery->isReady()) {
            return 'The event-email outbox is not installed. Apply the notification delivery database updates first.';
        }

        return $this->notificationDelivery->emailConfigurationIssue();
    }

    private function manageableProfile(string $id): ?array
    {
        $profile = $this->supabase->adminSelect(
            'profiles',
            'id,role,first_name,last_name,email,suspended_at,suspension_reason,deactivated_at',
            ['id' => $id]
        )[0] ?? null;

        return $profile && empty($profile['deactivated_at']) && in_array($profile['role'] ?? '', ['student', 'teacher'], true)
            ? $profile
            : null;
    }

    private function auditFilters(Request $request): array
    {
        // Empty strings become null in Laravel middleware. An explicitly empty
        // category still means all streams; only an absent filter defaults.
        $category = $request->query->has('audit_category')
            ? (string) $request->query('audit_category') : 'security';
        $actorRole = (string) $request->query('audit_actor_role', '');
        $outcome = (string) $request->query('audit_outcome', '');
        $action = strtolower(trim(mb_substr((string) $request->query('audit_action', ''), 0, 100)));
        if ($action !== '' && preg_match('/^[a-z0-9._-]+$/', $action) !== 1) $action = '';

        return [
            'search' => $this->safeSearchTerm($request->query('audit_search', ''), 80),
            'category' => in_array($category, ['security', 'activity'], true) ? $category : '',
            'actor_role' => in_array($actorRole, ['admin', 'teacher', 'student', 'system'], true) ? $actorRole : '',
            'action' => $action,
            'outcome' => in_array($outcome, ['pending', 'succeeded', 'failed'], true) ? $outcome : '',
            'from' => $this->validAuditDate($request->query('audit_from', '')),
            'to' => $this->validAuditDate($request->query('audit_to', '')),
        ];
    }

    private function validAuditDate(mixed $value): string
    {
        $value = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) return '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }

    private function auditDate(string $value, bool $exclusiveEnd = false): ?string
    {
        return \App\Support\AppDate::dayBoundaryUtc($value, $exclusiveEnd);
    }

    private function registrySearch(mixed $value): string
    {
        return $this->safeSearchTerm($value);
    }

    private function registryOrFilter(string $search): string
    {
        return '(first_name.ilike.*' . $search
            . '*,last_name.ilike.*' . $search
            . '*,email.ilike.*' . $search . '*)';
    }

    private function registrySort(string $sort): string
    {
        return in_array($sort, ['name_asc', 'name_desc', 'newest', 'oldest'], true)
            ? $sort
            : 'name_asc';
    }

    private function registryOrder(string $sort): string
    {
        return match ($sort) {
            'name_desc' => 'last_name.desc,first_name.desc',
            'newest' => 'created_at.desc',
            'oldest' => 'created_at.asc',
            default => 'last_name.asc,first_name.asc',
        };
    }

    private function downloadCsv(array $rows, array $headers, array $keys, string $filename): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return response()->streamDownload(function () use ($rows, $headers, $keys) {
            $out = fopen('php://output', 'w');
            $this->writeCsvRow($out, $headers);
            foreach ($rows as $row) {
                $this->writeCsvRow($out, array_map(fn($k) => $row[$k] ?? '', $keys));
            }
            fclose($out);
        }, "{$filename}.csv", ['Content-Type' => 'text/csv']);
    }
}
