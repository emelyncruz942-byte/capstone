<?php

namespace App\Http\Controllers;

use App\Services\NotificationDeliveryService;
use App\Services\SupabaseService;
use App\Support\ClassCustomization;
use App\Support\SupabaseAccessToken;
use Illuminate\Http\Request;

class TeacherClassController extends Controller
{
    public function __construct(
        private SupabaseService $supabase,
        private NotificationDeliveryService $notificationDelivery,
    ) {}

    public function store(Request $request)
    {
        $validated = $request->validate([
            'class_name' => 'required|string|max:100',
            'grade_level' => 'required|integer|between:1,6',
        ]);

        $user = session('supabase_user');
        $token = SupabaseAccessToken::from(request());
        $joinCode = $this->generateJoinCode();

        $created = $this->supabase->insert('classes', [
            'teacher_id' => $user['id'],
            'class_name' => trim($validated['class_name']),
            'join_code' => $joinCode,
            'grade_level' => (int) $validated['grade_level'],
        ], $token);

        $classId = $created[0]['id'] ?? null;
        if (!$classId) {
            return redirect('/teacher/dashboard?section=classes')
                ->with('error', 'The class could not be created. Run the latest database update first.');
        }

        $this->supabase->insert('class_customizations', [
            'class_id' => $classId,
            'theme_color' => '#f59e0b',
            'icon' => 'chalkboard',
            'banner_pattern' => 'grid',
        ], $token);

        return redirect("/teacher/classes/{$classId}")
            ->with('success', "Class created. Join code: {$joinCode}");
    }

    public function show(string $id)
    {
        $user = session('supabase_user');
        $class = $this->ownedClass($id, $user['id']);
        if (!$class) {
            return redirect('/teacher/dashboard?section=classes')->with('error', 'Class not found.');
        }

        $this->advanceScheduledSessions($id, $user);

        $customization = $this->customization($id);
        $members = $this->supabase->adminSelect(
            'class_members',
            'student_id,joined_at,profiles(id,username,last_name,email,first_name,grade_level,level)',
            ['class_id' => $id, 'order' => 'joined_at.asc']
        );
        $mismatchedMembers = 0;
        foreach ($members as &$member) {
            $member['grade_mismatch'] = (int) ($member['profiles']['grade_level'] ?? 0)
                !== (int) $class['grade_level'];
            if ($member['grade_mismatch']) {
                $mismatchedMembers++;
            }
        }
        unset($member);

        $sessions = $this->supabase->adminSelect(
            'quiz_sessions',
            '*',
            ['class_id' => $id, 'teacher_id' => $user['id'], 'order' => 'created_at.desc']
        );

        $analytics = $this->sessionAnalytics(array_column($sessions, 'id'));
        foreach ($sessions as &$session) {
            $session['analytics'] = $analytics[$session['id']] ?? [
                'attempts' => 0,
                'average' => 0,
                'eligible' => 0,
                'missed' => 0,
                'completion_rate' => 0,
            ];
        }
        unset($session);

        $openSessions = array_values(array_filter(
            $sessions,
            fn (array $session): bool => in_array($session['status'] ?? 'waiting', ['waiting', 'active'], true)
        ));
        $endedSessions = array_values(array_filter(
            $sessions,
            fn (array $session): bool => ($session['status'] ?? 'waiting') === 'completed'
        ));

        $leaderboard = $this->classLeaderboard($members, array_column($endedSessions, 'id'));

        return view('teacher.classes.show', compact(
            'user', 'class', 'customization', 'members', 'mismatchedMembers',
            'openSessions', 'endedSessions', 'leaderboard'
        ));
    }

    public function settings(string $id)
    {
        $user = session('supabase_user');
        $class = $this->ownedClass($id, $user['id']);
        if (!$class) {
            return redirect('/teacher/dashboard?section=classes')->with('error', 'Class not found.');
        }

        $customization = $this->customization($id);

        return view('teacher.classes.settings', [
            'user' => $user,
            'class' => $class,
            'customization' => $customization,
            'themeColors' => ClassCustomization::COLORS,
            'icons' => ClassCustomization::ICONS,
            'patterns' => ClassCustomization::PATTERNS,
        ]);
    }

    public function updateSettings(Request $request, string $id)
    {
        $validated = $request->validate([
            'class_name' => 'required|string|max:100',
            'grade_level' => 'required|integer|between:1,6',
            'theme_color' => 'required|in:' . implode(',', ClassCustomization::COLORS),
            'icon' => 'required|in:' . implode(',', ClassCustomization::ICONS),
            'banner_pattern' => 'required|in:' . implode(',', ClassCustomization::PATTERNS),
        ]);

        $user = session('supabase_user');
        $token = SupabaseAccessToken::from(request());
        $class = $this->ownedClass($id, $user['id']);
        if (!$class) {
            return redirect('/teacher/dashboard?section=classes')->with('error', 'Class not found.');
        }

        $newGrade = (int) $validated['grade_level'];
        if ($newGrade !== (int) $class['grade_level']) {
            $sessions = $this->supabase->adminSelect(
                'quiz_sessions',
                'id',
                ['class_id' => $id, 'limit' => 1]
            );
            if (!empty($sessions)) {
                return redirect("/teacher/classes/{$id}/settings")
                    ->with('error', 'A class with quiz history cannot change grade level. Create a new class for the new grade.');
            }

            $members = $this->supabase->adminSelect(
                'class_members',
                'profiles(grade_level)',
                ['class_id' => $id]
            );
            $mismatch = array_filter(
                $members,
                fn (array $member): bool => (int) ($member['profiles']['grade_level'] ?? 0) !== $newGrade
            );

            if (!empty($mismatch)) {
                return redirect("/teacher/classes/{$id}/settings")
                    ->with('error', 'Remove students whose profile grade differs before changing the class grade.');
            }
        }

        $updated = $this->supabase->update('classes', [
            'class_name' => trim($validated['class_name']),
            'grade_level' => $newGrade,
        ], [
            'id' => $id,
            'teacher_id' => $user['id'],
        ], $token);

        if (!isset($updated[0]['id'])) {
            return redirect("/teacher/classes/{$id}/settings")
                ->with('error', 'The class details could not be updated.');
        }

        $customData = [
            'theme_color' => $validated['theme_color'],
            'icon' => $validated['icon'],
            'banner_pattern' => $validated['banner_pattern'],
            'updated_at' => now()->toIso8601String(),
        ];

        $existing = $this->supabase->adminSelect('class_customizations', 'class_id', ['class_id' => $id]);
        if (empty($existing)) {
            $customData['class_id'] = $id;
            $customizationUpdated = $this->supabase->insert('class_customizations', $customData, $token);
        } else {
            $customizationUpdated = $this->supabase->update(
                'class_customizations',
                $customData,
                ['class_id' => $id],
                $token
            );
        }

        if (!isset($customizationUpdated[0]['class_id'])) {
            return redirect("/teacher/classes/{$id}/settings")
                ->with('error', 'The class details were saved, but the visual design could not be updated.');
        }

        return redirect("/teacher/classes/{$id}/settings")->with('success', 'Class settings updated.');
    }

    public function regenerateCode(string $id)
    {
        $user = session('supabase_user');
        $token = SupabaseAccessToken::from(request());
        $class = $this->ownedClass($id, $user['id']);
        if (!$class) {
            return redirect('/teacher/dashboard?section=classes')->with('error', 'Class not found.');
        }
        if (!empty($class['archived_at'])) {
            return redirect("/teacher/classes/{$id}/settings")->with('error', 'Archived class codes cannot be regenerated.');
        }

        $joinCode = $this->generateJoinCode();
        $updated = $this->supabase->update('classes', ['join_code' => $joinCode], [
            'id' => $id,
            'teacher_id' => $user['id'],
        ], $token);

        if (!isset($updated[0]['id'])) {
            return redirect("/teacher/classes/{$id}/settings")
                ->with('error', 'A new join code could not be generated.');
        }

        return redirect("/teacher/classes/{$id}/settings")
            ->with('success', "New join code generated: {$joinCode}");
    }

    public function archive(string $id)
    {
        $user = session('supabase_user');
        $class = $this->ownedClass($id, $user['id']);
        if (!$class) {
            return redirect('/teacher/dashboard?section=classes')->with('error', 'Class not found.');
        }

        if (!empty($class['archived_at'])) {
            return redirect("/teacher/classes/{$id}/settings")->with('error', 'This class is already archived.');
        }

        $updated = $this->supabase->update(
            'classes',
            ['archived_at' => now()->toIso8601String()],
            ['id' => $id, 'teacher_id' => $user['id']],
            SupabaseAccessToken::from(request())
        );
        if (!isset($updated[0]['id'])) {
            return redirect("/teacher/classes/{$id}/settings")
                ->with('error', 'The class could not be archived. Run the latest database update first.');
        }

        $openSessions = $this->supabase->adminSelect('quiz_sessions', 'id,status', [
            'class_id' => $id,
            'teacher_id' => $user['id'],
        ]);
        foreach ($openSessions as $session) {
            if (in_array($session['status'] ?? 'waiting', ['waiting', 'active'], true)) {
                $this->supabase->adminUpdate('quiz_sessions', [
                    'status' => 'completed',
                    'is_active' => false,
                    'retake_mode' => false,
                    'ended_at' => now()->toIso8601String(),
                ], [
                    'id' => $session['id'],
                    'class_id' => $id,
                    'teacher_id' => $user['id'],
                    'status' => $session['status'] ?? 'waiting',
                ]);
            }
        }

        $this->supabase->adminUpdate('profiles', ['class_id' => null], ['class_id' => $id]);
        $this->supabase->audit($user, 'class.archived', 'class', $id, [
            'class_name' => $class['class_name'] ?? null,
        ]);

        return redirect('/teacher/dashboard?section=classes')
            ->with('success', 'Class archived. Its history is preserved and it no longer locks student grade levels.');
    }

    public function restore(string $id)
    {
        $user = session('supabase_user');
        $class = $this->ownedClass($id, $user['id']);
        if (!$class) {
            return redirect('/teacher/dashboard?section=classes')->with('error', 'Class not found.');
        }

        if (empty($class['archived_at'])) {
            return redirect("/teacher/classes/{$id}/settings")->with('error', 'This class is already active.');
        }

        $members = $this->supabase->adminSelect('class_members', 'profiles(grade_level)', ['class_id' => $id]);
        $mismatch = array_filter($members, fn (array $member): bool =>
            (int) ($member['profiles']['grade_level'] ?? 0) !== (int) $class['grade_level']
        );
        if (!empty($mismatch)) {
            return redirect("/teacher/classes/{$id}/settings")
                ->with('error', 'Remove students whose current grade differs from this class before restoring it.');
        }

        $updated = $this->supabase->update(
            'classes',
            ['archived_at' => null],
            ['id' => $id, 'teacher_id' => $user['id']],
            SupabaseAccessToken::from(request())
        );

        if (!isset($updated[0]['id'])) {
            return redirect("/teacher/classes/{$id}/settings")
                ->with('error', 'The class could not be restored.');
        }

        $this->supabase->audit($user, 'class.restored', 'class', $id, [
            'class_name' => $class['class_name'] ?? null,
        ]);
        return redirect("/teacher/classes/{$id}")->with('success', 'Class restored.');
    }

    public function destroy(Request $request, string $id)
    {
        $user = session('supabase_user');
        $class = $this->ownedClass($id, $user['id']);
        if (!$class) {
            return redirect('/teacher/dashboard?section=classes')->with('error', 'Class not found.');
        }

        // Protect against old clients accidentally submitting a student or
        // assignment DELETE to the class page instead of its child route.
        if ($request->input('delete_class_id') !== $id) {
            return redirect("/teacher/classes/{$id}/settings")
                ->with('error', 'Class deletion was not confirmed. No class data was removed.');
        }

        $deleted = $this->supabase->adminRpcResult('set_recovery_item', [
            'p_actor_id' => $user['id'],
            'p_kind' => 'class',
            'p_id' => $id,
            'p_restore' => false,
        ]);
        $deletedId = $deleted['data'][0]['id'] ?? null;
        if ($deleted['error'] !== null || $deletedId !== $id) {
            $error = strtolower((string) ($deleted['error'] ?? ''));
            $message = str_contains($error, 'set_recovery_item')
                || str_contains($error, 'schema cache')
                    ? 'Class deletion is unavailable. Run the latest database update, then try again.'
                    : 'The class could not be deleted. No changes were saved.';

            return redirect("/teacher/classes/{$id}/settings")
                ->with('error', $message);
        }

        return redirect('/teacher/trash')->with('success', 'Class moved to Trash. Students, assignments and results are preserved.');
    }

    public function removeStudent(string $classId, string $studentId)
    {
        $user = session('supabase_user');
        if (!$this->ownedClass($classId, $user['id'])) {
            return redirect('/teacher/dashboard?section=classes')->with('error', 'Class not found.');
        }

        $notificationWindowStart = now()->subSeconds(10)->utc()->toIso8601String();
        $removed = $this->supabase->adminDelete('class_members', [
            'class_id' => $classId,
            'student_id' => $studentId,
        ]);

        if (!$removed) {
            return redirect("/teacher/classes/{$classId}")
                ->with('error', 'The student could not be removed from the class.');
        }

        $this->supabase->adminUpdate('profiles', ['class_id' => null], [
            'id' => $studentId,
            'class_id' => $classId,
        ]);

        $removalEmail = $this->notificationDelivery->deliverNotificationEmailNow(
            $studentId,
            'removed_from_class',
            createdAfter: $notificationWindowStart,
        );
        $this->supabase->audit($user, 'class.student_removed', 'profile', $studentId, [
            'class_id' => $classId,
            'removal_email_sent' => $removalEmail['sent'],
            'removal_email_queued' => $removalEmail['queued'],
        ]);

        if (!$removalEmail['sent']) {
            return redirect("/teacher/classes/{$classId}")->with(
                'error',
                $removalEmail['queued']
                    ? 'Student removed, but the mail server did not accept the removal email immediately. MathVerse will retry it automatically.'
                    : 'Student removed, but the removal email could not be sent or queued. Check the mail and database settings.'
            );
        }

        return redirect("/teacher/classes/{$classId}")
            ->with('success', 'Student removed from the class. The removal email was sent.');
    }

    public function lobby(string $classId, string $sessionId)
    {
        $session = $this->ownedSession($classId, $sessionId);
        if (!$session) {
            return response()->json(['message' => 'Quiz session not found.'], 404);
        }

        $participants = $this->supabase->adminSelect(
            'quiz_participants',
            'student_id,profiles(first_name,last_name,level)',
            ['session_id' => $sessionId]
        );

        return response()->json($participants);
    }

    public function results(string $classId, string $sessionId)
    {
        $session = $this->ownedSession($classId, $sessionId);
        if (!$session) {
            return response()->json(['message' => 'Quiz session not found.'], 404);
        }

        $results = $this->supabase->adminSelect(
            'quiz_results',
            'student_id,correct_answers,total_questions,created_at,attempt_number,is_counted',
            ['session_id' => $sessionId, 'order' => 'attempt_number.asc']
        );
        $countedResults = [];
        $attemptCounts = [];
        foreach ($results as $result) {
            $studentId = $result['student_id'];
            $attemptCounts[$studentId] = ($attemptCounts[$studentId] ?? 0) + 1;
            if ($result['is_counted'] ?? false) {
                $countedResults[$studentId] = $result;
            }
        }

        $eligibility = $this->supabase->adminSelect(
            'quiz_session_students',
            'student_id,eligibility_status,allowed_attempts,excuse_reason,retake_due_at,profiles!quiz_session_students_student_id_fkey(first_name,last_name,email)',
            ['session_id' => $sessionId]
        );

        $rows = [];
        foreach ($eligibility as $item) {
            $studentId = $item['student_id'];
            $result = $countedResults[$studentId] ?? null;
            $attemptsUsed = $attemptCounts[$studentId] ?? 0;
            $retakeExpired = !empty($item['retake_due_at'])
                && now()->gte(\Carbon\Carbon::parse($item['retake_due_at'], 'UTC'));
            $item['result'] = $result;
            $item['attempts_used'] = $attemptsUsed;
            $item['remaining_attempts'] = $retakeExpired
                ? 0
                : max(0, (int) $item['allowed_attempts'] - $attemptsUsed);
            $hasRemainingAttempt = $item['remaining_attempts'] > 0;
            $item['can_grant_retake'] = ($session['status'] ?? '') === 'completed'
                || (bool) ($session['retake_mode'] ?? false);
            $item['assignment_status'] = ($item['eligibility_status'] ?? '') === 'excused'
                ? 'excused'
                : ($result ? 'completed' : (
                    ($session['status'] ?? '') === 'completed' || !$hasRemainingAttempt
                        ? 'missed'
                        : 'available'
                ));
            $rows[] = $item;
        }

        usort($rows, function (array $a, array $b): int {
            $aScore = (int) ($a['result']['correct_answers'] ?? -1);
            $bScore = (int) ($b['result']['correct_answers'] ?? -1);
            if ($aScore !== $bScore) {
                return $bScore <=> $aScore;
            }
            $aProfile = $a['profiles'] ?? [];
            $bProfile = $b['profiles'] ?? [];
            return strcmp(
                ($aProfile['last_name'] ?? '') . ($aProfile['first_name'] ?? ''),
                ($bProfile['last_name'] ?? '') . ($bProfile['first_name'] ?? '')
            );
        });

        return response()->json($rows);
    }

    public function updateAssignment(Request $request, string $classId, string $sessionId)
    {
        $validated = $request->validate([
            'time_limit' => 'required|integer|between:5,300',
            'available_at' => 'nullable|date',
            'due_at' => 'nullable|date',
        ]);
        $teacher = session('supabase_user');
        $session = $this->ownedSession($classId, $sessionId);
        if (!$session || !in_array($session['status'] ?? '', ['waiting', 'active'], true)) {
            return redirect("/teacher/classes/{$classId}")
                ->with('error', 'Only an assigned or active quiz can be updated.');
        }
        if (!empty($session['retake_mode'])) {
            return redirect("/teacher/classes/{$classId}")
                ->with('error', 'Finish the current retake window before changing assignment settings.');
        }

        $startAt = !empty($validated['available_at'])
            ? \Carbon\Carbon::parse($validated['available_at'], config('app.timezone'))->utc()
            : null;
        $dueAt = !empty($validated['due_at'])
            ? \Carbon\Carbon::parse($validated['due_at'], config('app.timezone'))->utc()
            : null;
        $now = now()->utc();

        if (($session['status'] ?? '') === 'active' && $startAt !== null && $startAt->isFuture()) {
            return back()->withInput()->with('error', 'An active quiz cannot be moved to a future start date.');
        }
        if ($dueAt !== null && $dueAt->lessThanOrEqualTo($now)) {
            return back()->withInput()->with('error', 'The due date must be in the future.');
        }
        if ($dueAt !== null && $startAt !== null && $dueAt->lessThanOrEqualTo($startAt)) {
            return back()->withInput()->with('error', 'The due date must be later than the start date.');
        }

        $isWaiting = ($session['status'] ?? '') === 'waiting';
        $startsNow = $isWaiting && $startAt !== null && $startAt->lessThanOrEqualTo($now);
        $data = [
            'time_limit' => (int) $validated['time_limit'],
            'available_at' => $startAt?->toIso8601String(),
            'due_at' => $dueAt?->toIso8601String(),
        ];
        if ($startsNow) {
            $data['status'] = 'active';
            $data['is_active'] = true;
            $data['started_at'] = $startAt->toIso8601String();
        } elseif ($isWaiting) {
            $data['status'] = 'waiting';
            $data['started_at'] = null;
        }

        $updated = $this->supabase->adminUpdate('quiz_sessions', $data, [
            'id' => $sessionId,
            'class_id' => $classId,
            'teacher_id' => $teacher['id'],
        ]);
        if (!isset($updated[0]['id'])) {
            return back()->withInput()->with('error', 'The assignment settings could not be updated.');
        }

        $this->supabase->audit($teacher, 'quiz.assignment_updated', 'quiz_session', $sessionId, [
            'class_id' => $classId,
            'time_limit' => (int) $validated['time_limit'],
            'available_at' => $startAt?->toIso8601String(),
            'due_at' => $dueAt?->toIso8601String(),
        ]);

        return redirect("/teacher/classes/{$classId}")
            ->with('success', $startsNow ? 'Assignment updated and started.' : 'Assignment settings updated.');
    }

    public function destroyAssignment(string $classId, string $sessionId)
    {
        $teacher = session('supabase_user');
        $session = $this->ownedSession($classId, $sessionId);
        if (!$session) {
            return redirect('/teacher/dashboard?section=classes')
                ->with('error', 'Quiz assignment not found.');
        }
        if (!in_array($session['status'] ?? 'waiting', ['waiting', 'active'], true)) {
            return redirect("/teacher/classes/{$classId}")
                ->with('error', 'Only an assigned or active quiz can be deleted. Ended quiz records are preserved.');
        }

        $deleted = $this->supabase->adminRpcResult('delete_open_quiz_assignment', [
            'p_teacher_id' => $teacher['id'],
            'p_class_id' => $classId,
            'p_session_id' => $sessionId,
        ]);

        $result = $deleted['data'][0] ?? null;
        if ($deleted['error'] !== null || !$result) {
            $reason = trim((string) ($deleted['error'] ?? 'The database returned no deletion result.'));
            if (str_contains(strtolower($reason), 'delete_open_quiz_assignment')) {
                return redirect("/teacher/classes/{$classId}")
                    ->with('error', 'The assignment deletion update is not installed. Run the latest database update, then try again.');
            }

            return redirect("/teacher/classes/{$classId}")
                ->with('error', 'The assignment could not be deleted. Please try again.');
        }

        $wasShared = filter_var(
            $result['was_shared_assignment'] ?? false,
            FILTER_VALIDATE_BOOL
        );
        $this->supabase->audit($teacher, 'quiz.assignment_deleted', 'quiz_session', $sessionId, [
            'class_id' => $classId,
            'topic' => $session['topic'] ?? null,
            'source_quiz_id' => $session['source_quiz_id'] ?? null,
            'shared_library_assignment' => $wasShared,
            'remaining_usage_count' => (int) ($result['remaining_usage_count'] ?? 0),
        ]);

        $message = $wasShared
            ? 'Assignment deleted. The shared quiz\'s Class Uses decreased by 1.'
            : 'Assignment deleted. Your quiz\'s Class Uses were not changed.';

        return redirect("/teacher/classes/{$classId}")->with('success', $message);
    }

    public function start(string $classId, string $sessionId)
    {
        $teacher = session('supabase_user');
        $session = $this->ownedSession($classId, $sessionId);
        if (!$session) {
            return response()->json(['message' => 'Quiz session not found.'], 404);
        }
        $class = $this->ownedClass($classId, $teacher['id']);
        if (!empty($class['archived_at'])) {
            return response()->json(['message' => 'Archived classes cannot start quizzes.'], 422);
        }
        if (($session['status'] ?? 'waiting') !== 'waiting') {
            return response()->json(['message' => 'Only an assigned quiz can be started.'], 422);
        }
        if (!empty($session['due_at']) && now()->gte(\Carbon\Carbon::parse($session['due_at'], 'UTC'))) {
            return response()->json(['message' => 'This quiz assignment is already past due.'], 422);
        }

        $updated = $this->supabase->update('quiz_sessions', [
            'status' => 'active',
            'is_active' => true,
            'available_at' => now()->toIso8601String(),
            'started_at' => now()->toIso8601String(),
        ], [
            'id' => $sessionId,
            'class_id' => $classId,
            'teacher_id' => $teacher['id'],
            'status' => 'waiting',
        ], SupabaseAccessToken::from(request()));

        if (!isset($updated[0]['id'])) {
            return response()->json(['message' => 'The quiz could not be started.'], 500);
        }

        $this->supabase->audit($teacher, 'quiz.started', 'quiz_session', $sessionId, [
            'class_id' => $classId,
            'topic' => $session['topic'] ?? null,
            'started_early' => !empty($session['available_at'])
                && now()->lt(\Carbon\Carbon::parse($session['available_at'], 'UTC')),
        ]);

        return response()->json(['success' => true]);
    }

    public function end(string $classId, string $sessionId)
    {
        $teacher = session('supabase_user');
        $session = $this->ownedSession($classId, $sessionId);
        if (!$session || !in_array($session['status'] ?? 'waiting', ['waiting', 'active'], true)) {
            return response()->json(['message' => 'This quiz has already ended.'], 422);
        }

        $updated = $this->supabase->update('quiz_sessions', [
            'status' => 'completed',
            'is_active' => false,
            'ended_at' => now()->toIso8601String(),
            'retake_mode' => false,
        ], [
            'id' => $sessionId,
            'class_id' => $classId,
            'teacher_id' => $teacher['id'],
            'status' => $session['status'],
        ], SupabaseAccessToken::from(request()));

        if (!isset($updated[0]['id'])) {
            return response()->json(['message' => 'The quiz could not be ended.'], 500);
        }

        $this->supabase->audit($teacher, 'quiz.ended', 'quiz_session', $sessionId, [
            'class_id' => $classId,
            'topic' => $session['topic'] ?? null,
        ]);
        return response()->json(['success' => true]);
    }

    public function grantRetake(Request $request, string $classId, string $sessionId, string $studentId)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
            'due_at' => 'nullable|date|after:now',
        ]);
        $session = $this->ownedSession($classId, $sessionId);
        if (!$session) {
            return response()->json(['message' => 'Quiz session not found.'], 404);
        }
        if (($session['status'] ?? '') !== 'completed' && !($session['retake_mode'] ?? false)) {
            return response()->json(['message' => 'End the original quiz before granting a retake.'], 422);
        }

        $dueAt = !empty($validated['due_at'])
            ? \Carbon\Carbon::parse($validated['due_at'], config('app.timezone'))->utc()->toIso8601String()
            : null;
        $teacher = session('supabase_user');
        $retake = $this->supabase->adminRpc('grant_quiz_retake', [
            'p_session_id' => $sessionId,
            'p_student_id' => $studentId,
            'p_teacher_id' => $teacher['id'],
            'p_reason' => trim($validated['reason']),
            'p_due_at' => $dueAt,
        ])[0] ?? null;

        if (!$retake || !isset($retake['new_allowed_attempts'])) {
            return response()->json(['message' => 'The retake could not be granted.'], 500);
        }

        $allowedAttempts = (int) $retake['new_allowed_attempts'];
        $dueAt = $retake['retake_due_at'] ?? $dueAt;

        $retakeEmail = $this->notificationDelivery->deliverNotificationEmailNow(
            $studentId,
            'quiz_retake_granted',
            "quiz-retake:{$sessionId}:{$studentId}:{$allowedAttempts}",
        );
        $this->supabase->audit($teacher, 'quiz.retake_granted', 'profile', $studentId, [
            'session_id' => $sessionId,
            'class_id' => $classId,
            'reason' => trim($validated['reason']),
            'allowed_attempts' => $allowedAttempts,
            'due_at' => $dueAt,
            'retake_email_sent' => $retakeEmail['sent'],
            'retake_email_queued' => $retakeEmail['queued'],
        ]);

        $message = $retakeEmail['sent']
            ? 'Retake granted. The student email was sent.'
            : ($retakeEmail['queued']
                ? 'Retake granted, but the mail server did not accept the student email immediately. MathVerse will retry it automatically.'
                : 'Retake granted, but the student email could not be sent or queued. Check the mail and database settings.');

        return response()->json([
            'success' => true,
            'message' => $message,
            'email_sent' => $retakeEmail['sent'],
            'email_queued' => $retakeEmail['queued'],
        ]);
    }

    public function excuseStudent(Request $request, string $classId, string $sessionId, string $studentId)
    {
        $validated = $request->validate(['reason' => 'required|string|max:500']);
        if (!$this->ownedSession($classId, $sessionId)) {
            return response()->json(['message' => 'Quiz session not found.'], 404);
        }

        $countedResult = $this->supabase->adminSelect('quiz_results', 'id', [
            'session_id' => $sessionId, 'student_id' => $studentId, 'is_counted' => true,
        ]);
        if ($countedResult) {
            return response()->json(['message' => 'A completed attempt cannot be marked excused.'], 422);
        }

        $teacher = session('supabase_user');
        $updated = $this->supabase->adminUpdate('quiz_session_students', [
            'eligibility_status' => 'excused',
            'allowed_attempts' => 0,
            'excused_at' => now()->toIso8601String(),
            'excused_by' => $teacher['id'],
            'excuse_reason' => trim($validated['reason']),
        ], ['session_id' => $sessionId, 'student_id' => $studentId]);
        if (!isset($updated[0]['student_id'])) {
            return response()->json(['message' => 'The student could not be marked excused.'], 500);
        }

        $excuseEmail = $this->notificationDelivery->deliverNotificationEmailNow(
            $studentId,
            'quiz_excused',
            "quiz-excused:{$sessionId}:{$studentId}",
        );
        $this->supabase->audit($teacher, 'quiz.student_excused', 'profile', $studentId, [
            'session_id' => $sessionId,
            'class_id' => $classId,
            'reason' => trim($validated['reason']),
            'excuse_email_sent' => $excuseEmail['sent'],
            'excuse_email_queued' => $excuseEmail['queued'],
        ]);

        $message = $excuseEmail['sent']
            ? 'Student marked excused. The student email was sent.'
            : ($excuseEmail['queued']
                ? 'Student marked excused, but the mail server did not accept the student email immediately. MathVerse will retry it automatically.'
                : 'Student marked excused, but the student email could not be sent or queued. Check the mail and database settings.');

        return response()->json([
            'success' => true,
            'message' => $message,
            'email_sent' => $excuseEmail['sent'],
            'email_queued' => $excuseEmail['queued'],
        ]);
    }

    private function ownedClass(string $classId, string $teacherId): ?array
    {
        return $this->supabase->adminSelect('classes', '*', [
            'id' => $classId,
            'teacher_id' => $teacherId,
        ])[0] ?? null;
    }

    private function ownedSession(string $classId, string $sessionId): ?array
    {
        $user = session('supabase_user');

        return $this->supabase->adminSelect('quiz_sessions', '*', [
            'id' => $sessionId,
            'class_id' => $classId,
            'teacher_id' => $user['id'],
        ])[0] ?? null;
    }

    private function customization(string $classId): array
    {
        $customization = $this->supabase->adminSelect(
            'class_customizations',
            '*',
            ['class_id' => $classId]
        )[0] ?? [];

        return ClassCustomization::normalize($customization);
    }

    private function sessionAnalytics(array $sessionIds): array
    {
        $sessionIds = array_values(array_unique(array_filter($sessionIds)));
        if (empty($sessionIds)) {
            return [];
        }

        $results = $this->supabase->adminSelect(
            'quiz_results',
            'session_id,student_id,correct_answers,total_questions,created_at',
            [
                'session_id' => ['operator' => 'in', 'value' => '(' . implode(',', $sessionIds) . ')'],
                'is_counted' => true,
                'order' => 'created_at.asc',
            ]
        );

        $eligibility = $this->supabase->adminSelect(
            'quiz_session_students', 'session_id,student_id,eligibility_status', [
                'session_id' => ['operator' => 'in', 'value' => '(' . implode(',', $sessionIds) . ')'],
            ]
        );

        $grouped = [];
        foreach ($results as $result) {
            $id = $result['session_id'];
            $accuracy = ($result['total_questions'] ?? 0) > 0
                ? (($result['correct_answers'] ?? 0) / $result['total_questions']) * 100
                : 0;
            $grouped[$id]['attempts'] = ($grouped[$id]['attempts'] ?? 0) + 1;
            $grouped[$id]['accuracy_sum'] = ($grouped[$id]['accuracy_sum'] ?? 0) + $accuracy;
        }

        foreach ($eligibility as $item) {
            if (($item['eligibility_status'] ?? '') !== 'eligible') {
                continue;
            }
            $id = $item['session_id'];
            $grouped[$id]['eligible'] = ($grouped[$id]['eligible'] ?? 0) + 1;
        }

        foreach ($grouped as &$item) {
            $item['attempts'] = $item['attempts'] ?? 0;
            $item['accuracy_sum'] = $item['accuracy_sum'] ?? 0;
            $item['average'] = $item['attempts'] > 0
                ? round($item['accuracy_sum'] / $item['attempts'], 1)
                : 0;
            $item['eligible'] = $item['eligible'] ?? 0;
            $item['missed'] = max(0, $item['eligible'] - $item['attempts']);
            $item['completion_rate'] = $item['eligible'] > 0
                ? round(($item['attempts'] / $item['eligible']) * 100, 1)
                : 0;
            unset($item['accuracy_sum']);
        }
        unset($item);

        return $grouped;
    }

    private function classLeaderboard(array $members, array $sessionIds): array
    {
        $sessionIds = array_values(array_unique(array_filter($sessionIds)));
        if (empty($members)) {
            return [];
        }

        $results = empty($sessionIds) ? [] : $this->supabase->adminSelect(
            'quiz_results',
            'session_id,student_id,correct_answers,total_questions,created_at',
            [
                'session_id' => ['operator' => 'in', 'value' => '(' . implode(',', $sessionIds) . ')'],
                'is_counted' => true,
                'order' => 'created_at.asc',
            ]
        );
        $eligibility = empty($sessionIds) ? [] : $this->supabase->adminSelect(
            'quiz_session_students', 'session_id,student_id,eligibility_status', [
                'session_id' => ['operator' => 'in', 'value' => '(' . implode(',', $sessionIds) . ')'],
            ]
        );
        $resultMap = [];
        foreach ($results as $result) {
            $resultMap[$result['session_id'] . ':' . $result['student_id']] = $result;
        }

        $rows = [];
        foreach ($members as $member) {
            $profile = $member['profiles'] ?? [];
            $studentId = $profile['id'] ?? $member['student_id'];
            $studentEligibility = array_values(array_filter(
                $eligibility,
                fn (array $item): bool => $item['student_id'] === $studentId
                    && ($item['eligibility_status'] ?? '') === 'eligible'
            ));
            $studentResults = [];
            foreach ($studentEligibility as $item) {
                $key = $item['session_id'] . ':' . $studentId;
                if (isset($resultMap[$key])) {
                    $studentResults[] = $resultMap[$key];
                }
            }
            $accuracies = array_map(fn (array $result): float => ($result['total_questions'] ?? 0) > 0
                ? (($result['correct_answers'] ?? 0) / $result['total_questions']) * 100
                : 0, $studentResults);
            $eligibleCount = count($studentEligibility);
            $completedCount = count($studentResults);
            if ($eligibleCount === 0) {
                continue;
            }
            $rows[] = [
                'student_id' => $studentId,
                'name' => trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')) ?: 'Unknown Student',
                'average' => $eligibleCount > 0 ? round(array_sum($accuracies) / $eligibleCount, 1) : 0,
                'quizzes' => $completedCount,
                'eligible' => $eligibleCount,
                'missed' => max(0, $eligibleCount - $completedCount),
                'completion_rate' => $eligibleCount > 0
                    ? round(($completedCount / $eligibleCount) * 100, 1)
                    : 0,
                'correct' => array_sum(array_column($studentResults, 'correct_answers')),
            ];
        }

        usort($rows, fn (array $a, array $b): int =>
            ($b['average'] <=> $a['average'])
            ?: ($b['completion_rate'] <=> $a['completion_rate'])
            ?: ($b['correct'] <=> $a['correct'])
            ?: ($b['quizzes'] <=> $a['quizzes'])
            ?: strcmp($a['name'], $b['name'])
        );

        foreach ($rows as $index => &$row) {
            $row['rank'] = $index + 1;
        }
        unset($row);

        return $rows;
    }

    private function advanceScheduledSessions(string $classId, array $teacher): void
    {
        $scheduled = $this->supabase->adminSelect(
            'quiz_sessions',
            'id,topic,available_at,status',
            [
                'class_id' => $classId,
                'teacher_id' => $teacher['id'],
                'status' => 'waiting',
                'available_at' => ['operator' => 'lte', 'value' => now()->utc()->toIso8601String()],
            ]
        );
        foreach ($scheduled as $session) {
            $updated = $this->supabase->adminUpdate('quiz_sessions', [
                'status' => 'active',
                'is_active' => true,
                'started_at' => $session['available_at'] ?? now()->toIso8601String(),
            ], [
                'id' => $session['id'],
                'class_id' => $classId,
                'teacher_id' => $teacher['id'],
                'status' => 'waiting',
            ]);
            if (isset($updated[0]['id'])) {
                $this->supabase->audit($teacher, 'quiz.auto_started', 'quiz_session', $session['id'], [
                    'class_id' => $classId,
                    'topic' => $session['topic'] ?? null,
                    'available_at' => $session['available_at'] ?? null,
                ]);
            }
        }

        $sessions = $this->supabase->adminSelect('quiz_sessions', 'id,topic,due_at,status', [
            'class_id' => $classId,
            'teacher_id' => $teacher['id'],
            'due_at' => ['operator' => 'lte', 'value' => now()->utc()->toIso8601String()],
        ]);

        foreach ($sessions as $session) {
            if (!in_array($session['status'] ?? '', ['waiting', 'active'], true)) {
                continue;
            }
            $updated = $this->supabase->adminUpdate('quiz_sessions', [
                'status' => 'completed',
                'is_active' => false,
                'retake_mode' => false,
                'ended_at' => $session['due_at'] ?? now()->toIso8601String(),
            ], [
                'id' => $session['id'],
                'class_id' => $classId,
                'teacher_id' => $teacher['id'],
                'status' => $session['status'],
            ]);
            if (isset($updated[0]['id'])) {
                $this->supabase->audit($teacher, 'quiz.auto_ended', 'quiz_session', $session['id'], [
                    'class_id' => $classId,
                    'topic' => $session['topic'] ?? null,
                    'due_at' => $session['due_at'] ?? null,
                ]);
            }
        }
    }

    private function generateJoinCode(): string
    {
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $characters[random_int(0, strlen($characters) - 1)];
            }

            if (empty($this->supabase->adminSelect('classes', 'id', ['join_code' => $code]))) {
                return $code;
            }
        }

        throw new \RuntimeException('Unable to generate an available class join code.');
    }
}
