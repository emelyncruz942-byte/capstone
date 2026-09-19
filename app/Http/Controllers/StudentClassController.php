<?php

namespace App\Http\Controllers;

use App\Services\SupabaseService;
use App\Support\ClassCustomization;
use Illuminate\Http\Request;

class StudentClassController extends Controller
{
    public function __construct(private SupabaseService $supabase) {}

    public function join(Request $request)
    {
        $validated = $request->validate(['join_code' => 'required|string|alpha_num|size:6']);
        $user = session('supabase_user');
        $joinCode = strtoupper(trim($validated['join_code']));
        $classResult = $this->supabase->adminSelectResult(
            'classes', 'id,class_name,grade_level,archived_at', ['join_code' => $joinCode]
        );
        $class = $classResult['data'][0] ?? null;

        if ($classResult['error'] !== null) {
            return redirect('/student/dashboard?section=class')->with(
                'error',
                $this->joinFailureMessage($classResult['error'])
            );
        }

        if (!$class || !empty($class['archived_at'])) {
            return redirect('/student/dashboard?section=class')->with('error', 'That class code is invalid or archived.');
        }

        $profileResult = $this->supabase->adminSelectResult(
            'profiles', 'id,role,grade_level', ['id' => $user['id']]
        );
        $profile = $profileResult['data'][0] ?? null;
        if (!$profile) {
            return redirect('/student/dashboard?section=class')->with(
                'error',
                $this->joinFailureMessage($profileResult['error'] ?? 'Your student profile could not be loaded.')
            );
        }

        if (($profile['role'] ?? null) !== 'student') {
            return redirect('/student/dashboard?section=class')
                ->with('error', 'Only student accounts can join a class.');
        }
        $studentGrade = (int) ($profile['grade_level'] ?? 0);
        $classGrade = (int) $class['grade_level'];
        if ($studentGrade !== $classGrade) {
            return redirect('/student/dashboard?section=class')->with(
                'error',
                "{$class['class_name']} is Grade {$classGrade}, but your profile is Grade {$studentGrade}."
            );
        }

        $existingResult = $this->supabase->adminSelectResult('class_members', 'student_id', [
            'class_id' => $class['id'], 'student_id' => $user['id'],
        ]);
        if ($existingResult['error'] !== null) {
            return redirect('/student/dashboard?section=class')->with(
                'error',
                $this->joinFailureMessage($existingResult['error'])
            );
        }
        $existing = $existingResult['data'];
        if (!empty($existing)) {
            return redirect("/student/classes/{$class['id']}")->with('error', 'You already belong to this class.');
        }

        // The server has already authenticated the student and verified the
        // class and grade. Use the service role for this one write so an
        // expired browser JWT or a roster SELECT policy cannot make a valid
        // membership insert look like it failed.
        $joinResult = $this->supabase->adminInsertResult('class_members', [
            'student_id' => $user['id'], 'class_id' => $class['id'],
        ]);

        // Verify the durable row instead of relying only on PostgREST's
        // returned representation. This also handles a simultaneous retry.
        $membershipResult = $this->supabase->adminSelectResult(
            'class_members', 'student_id,joined_at', [
                'class_id' => $class['id'], 'student_id' => $user['id'],
            ]
        );
        $membership = $membershipResult['data'][0] ?? null;

        return $membership
            ? redirect("/student/classes/{$class['id']}")->with('success', 'Successfully joined the class.')
            : redirect('/student/dashboard?section=class')->with(
                'error',
                $this->joinFailureMessage(
                    $joinResult['error']
                        ?? $membershipResult['error']
                        ?? 'The membership row was not created.'
                )
            );
    }

    public function show(string $id)
    {
        $user = session('supabase_user');
        $membership = $this->membership($id, $user['id']);
        if (!$membership) {
            return redirect('/student/dashboard?section=class')->with('error', 'You do not have access to that class.');
        }

        $class = $this->supabase->adminSelect('classes', '*', ['id' => $id])[0] ?? null;
        if (!$class || !empty($class['archived_at'])) {
            return redirect('/student/dashboard?section=class')->with('error', 'This class has been archived by the teacher.');
        }

        $this->advanceScheduledSessions($id);

        $customization = ClassCustomization::normalize($this->supabase->adminSelect(
            'class_customizations', '*', ['class_id' => $id]
        )[0] ?? [], '#22c55e');

        $sessions = $this->supabase->adminSelect('quiz_sessions', '*', ['class_id' => $id, 'order' => 'created_at.desc']);
        $sessionIds = array_column($sessions, 'id');
        $results = $this->supabase->adminSelect(
            'quiz_results',
            'session_id,correct_answers,total_questions,created_at',
            ['student_id' => $user['id'], 'is_counted' => true, 'order' => 'created_at.asc']
        );
        $attemptRows = $this->supabase->adminSelect(
            'quiz_results', 'session_id', ['student_id' => $user['id']]
        );
        $attemptCounts = [];
        foreach ($attemptRows as $attempt) {
            if (in_array($attempt['session_id'], $sessionIds, true)) {
                $attemptCounts[$attempt['session_id']] = ($attemptCounts[$attempt['session_id']] ?? 0) + 1;
            }
        }
        $resultMap = [];
        foreach ($results as $result) {
            if (in_array($result['session_id'], $sessionIds, true)) {
                $resultMap[$result['session_id']] ??= $result;
            }
        }

        $eligibilityRows = empty($sessionIds) ? [] : $this->supabase->adminSelect(
            'quiz_session_students',
            'session_id,eligibility_status,allowed_attempts,excuse_reason,retake_due_at',
            [
                'student_id' => $user['id'],
                'session_id' => ['operator' => 'in', 'value' => '(' . implode(',', $sessionIds) . ')'],
            ]
        );
        $eligibilityMap = array_column($eligibilityRows, null, 'session_id');

        foreach ($sessions as &$session) {
            $session['eligibility'] = $eligibilityMap[$session['id']] ?? null;
            $session['attempts_used'] = $attemptCounts[$session['id']] ?? 0;
            $session['effective_due_at'] = $session['eligibility']['retake_due_at']
                ?? $session['due_at']
                ?? null;
            $isPastStudentDue = !empty($session['effective_due_at'])
                && now()->gte(\Carbon\Carbon::parse($session['effective_due_at'], 'UTC'));
            $session['remaining_attempts'] = $isPastStudentDue
                ? 0
                : max(
                    0,
                    (int) ($session['eligibility']['allowed_attempts'] ?? 0) - $session['attempts_used']
                );
            $session['result'] = $resultMap[$session['id']] ?? null;
            if ($session['result']) {
                $total = (int) ($session['result']['total_questions'] ?? 0);
                $session['result']['accuracy'] = $total > 0
                    ? round(((int) $session['result']['correct_answers'] / $total) * 100, 1)
                    : 0;
            }
        }
        unset($session);

        $sessions = array_values(array_filter(
            $sessions, fn (array $session): bool => !empty($session['eligibility'])
        ));

        $openSessions = array_values(array_filter(
            $sessions,
            fn (array $session): bool => in_array($session['status'] ?? 'waiting', ['waiting', 'active'], true)
                && ($session['eligibility']['eligibility_status'] ?? '') === 'eligible'
                && (int) ($session['remaining_attempts'] ?? 0) > 0
        ));
        $endedSessions = array_values(array_filter(
            $sessions,
            fn (array $session): bool => ($session['status'] ?? 'waiting') === 'completed'
                || (
                    (bool) ($session['retake_mode'] ?? false)
                    && (int) ($session['remaining_attempts'] ?? 0) === 0
                )
        ));

        $accuracies = array_values(array_filter(array_map(
            fn (array $session) => ($session['eligibility']['eligibility_status'] ?? '') === 'eligible'
                ? ($session['result']['accuracy'] ?? null)
                : null,
            $endedSessions
        ), fn ($accuracy): bool => $accuracy !== null));
        $eligibleEnded = array_values(array_filter(
            $endedSessions,
            fn (array $session): bool => ($session['eligibility']['eligibility_status'] ?? '') === 'eligible'
        ));

        $analytics = [
            'attempts' => count($accuracies),
            'missed' => count($eligibleEnded) - count($accuracies),
            'excused' => count($endedSessions) - count($eligibleEnded),
            'passed' => count(array_filter($accuracies, fn (float $accuracy): bool => $accuracy >= 75)),
            'failed' => count(array_filter($accuracies, fn (float $accuracy): bool => $accuracy < 75)),
            'average' => !empty($accuracies) ? round(array_sum($accuracies) / count($accuracies), 1) : 0,
            'best' => !empty($accuracies) ? max($accuracies) : 0,
        ];

        $leaderboard = $this->classLeaderboard($id, array_column($endedSessions, 'id'));

        return view('student.classes.show', compact(
            'user', 'class', 'customization', 'openSessions', 'endedSessions', 'analytics', 'leaderboard'
        ));
    }

    public function review(string $classId, string $sessionId)
    {
        $user = session('supabase_user');
        $membership = $this->membership($classId, $user['id']);
        if (!$membership) {
            return redirect('/student/dashboard?section=class')->with('error', 'You do not have access to that quiz.');
        }

        $class = $this->supabase->adminSelect('classes', '*', ['id' => $classId])[0] ?? null;
        $session = $this->supabase->adminSelect('quiz_sessions', '*', [
            'id' => $sessionId, 'class_id' => $classId,
        ])[0] ?? null;
        if (!$class || !$session) {
            return redirect('/student/dashboard?section=stats')->with('error', 'Only ended quizzes can be reviewed.');
        }
        $eligibility = $this->supabase->adminSelect('quiz_session_students', '*', [
            'session_id' => $sessionId, 'student_id' => $user['id'],
        ])[0] ?? null;
        if (!$eligibility) {
            return redirect('/student/dashboard?section=stats')->with('error', 'You were not eligible for that quiz assignment.');
        }

        if (($session['status'] ?? '') !== 'completed') {
            return redirect("/student/classes/{$classId}")
                ->with('error', 'Answer keys are available only after the quiz has ended.');
        }

        $result = $this->supabase->adminSelect(
            'quiz_results',
            'correct_answers,total_questions,created_at',
            ['session_id' => $sessionId, 'student_id' => $user['id'], 'is_counted' => true, 'limit' => 1]
        )[0] ?? null;
        if ($result) {
            $total = (int) ($result['total_questions'] ?? 0);
            $result['accuracy'] = $total > 0 ? round(((int) $result['correct_answers'] / $total) * 100, 1) : 0;
            $result['status'] = $result['accuracy'] >= 75 ? 'Passed' : 'Failed';
        }

        $questions = $this->supabase->adminSelect('questions', '*', ['session_id' => $sessionId, 'order' => 'id.asc']);
        foreach ($questions as &$question) {
            $choices = array_values(array_filter([
                $question['choice1'] ?? null, $question['choice2'] ?? null,
                $question['choice3'] ?? null, $question['choice4'] ?? null,
                $question['choice5'] ?? null, $question['choice6'] ?? null,
            ], fn ($choice): bool => $choice !== null && $choice !== ''));
            $storedAnswer = (string) ($question['correct_answer'] ?? '');
            $answerIndex = ctype_digit($storedAnswer) ? (int) $storedAnswer : -1;
            $question['choices'] = $choices;
            if ($answerIndex < 0 || !isset($choices[$answerIndex])) {
                $matchedIndex = array_search($storedAnswer, $choices, true);
                $answerIndex = $matchedIndex === false ? -1 : $matchedIndex;
            }
            $question['correct_index'] = $answerIndex;
            $question['correct_text'] = $answerIndex >= 0 ? $choices[$answerIndex] : $storedAnswer;
        }
        unset($question);

        return view('student.classes.review', compact('user', 'class', 'session', 'result', 'questions', 'eligibility'));
    }

    private function membership(string $classId, string $studentId): ?array
    {
        return $this->supabase->adminSelect('class_members', 'student_id,joined_at', [
            'class_id' => $classId, 'student_id' => $studentId,
        ])[0] ?? null;
    }

    private function joinFailureMessage(?string $error): string
    {
        $message = trim(preg_replace('/\s+/', ' ', (string) $error));
        if ($message === '') {
            return 'The class could not be joined because the database returned no reason.';
        }

        $lower = strtolower($message);
        if (str_contains($lower, 'does not match class grade')) {
            return 'Your grade level does not match this class.';
        }
        if (str_contains($lower, 'archived class')) {
            return 'That class has been archived and cannot accept students.';
        }
        if (str_contains($lower, 'duplicate key')) {
            return 'You already belong to this class.';
        }

        return 'The class could not be joined. Please try again or ask your teacher to verify the class code.';
    }

    private function classLeaderboard(string $classId, array $sessionIds): array
    {
        if (empty($sessionIds)) {
            return [];
        }

        $members = $this->supabase->adminSelect(
            'class_members',
            'student_id,profiles(first_name,last_name,leaderboard_alias,show_on_leaderboard)',
            ['class_id' => $classId]
        );
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
        $resultMap = [];
        foreach ($results as $result) {
            $resultMap[$result['session_id'] . ':' . $result['student_id']] = $result;
        }

        $rows = [];
        foreach ($members as $member) {
            $studentEligibility = array_values(array_filter(
                $eligibility,
                fn (array $item): bool => $item['student_id'] === $member['student_id']
                    && ($item['eligibility_status'] ?? '') === 'eligible'
            ));
            $studentResults = [];
            foreach ($studentEligibility as $item) {
                $key = $item['session_id'] . ':' . $member['student_id'];
                if (isset($resultMap[$key])) {
                    $studentResults[] = $resultMap[$key];
                }
            }
            $accuracies = array_map(fn (array $result): float => ($result['total_questions'] ?? 0) > 0
                ? (($result['correct_answers'] ?? 0) / $result['total_questions']) * 100
                : 0, $studentResults);
            $profile = $member['profiles'] ?? [];
            $firstName = trim((string) ($profile['first_name'] ?? '')) ?: 'Student';
            $lastInitial = mb_substr(trim((string) ($profile['last_name'] ?? '')), 0, 1);
            $defaultName = $lastInitial !== '' ? "{$firstName} {$lastInitial}." : $firstName;
            $visibleName = trim((string) ($profile['leaderboard_alias'] ?? '')) ?: $defaultName;
            if (!($profile['show_on_leaderboard'] ?? true) && $member['student_id'] !== session('supabase_user')['id']) {
                $visibleName = 'Anonymous Student';
            }
            $eligibleCount = count($studentEligibility);
            $completedCount = count($studentResults);
            if ($eligibleCount === 0) {
                continue;
            }
            $rows[] = [
                'student_id' => $member['student_id'],
                'name' => $visibleName,
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

    private function advanceScheduledSessions(string $classId): void
    {
        $scheduled = $this->supabase->adminSelect(
            'quiz_sessions',
            'id,topic,available_at,status',
            [
                'class_id' => $classId,
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
                'status' => 'waiting',
            ]);
            if (isset($updated[0]['id'])) {
                $this->supabase->audit(['role' => 'system'], 'quiz.auto_started', 'quiz_session', $session['id'], [
                    'class_id' => $classId,
                    'topic' => $session['topic'] ?? null,
                    'available_at' => $session['available_at'] ?? null,
                ]);
            }
        }

        $sessions = $this->supabase->adminSelect('quiz_sessions', 'id,topic,due_at,status', [
            'class_id' => $classId,
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
                'status' => $session['status'],
            ]);
            if (isset($updated[0]['id'])) {
                $this->supabase->audit(['role' => 'system'], 'quiz.auto_ended', 'quiz_session', $session['id'], [
                    'class_id' => $classId,
                    'topic' => $session['topic'] ?? null,
                    'due_at' => $session['due_at'] ?? null,
                ]);
            }
        }
    }
}
