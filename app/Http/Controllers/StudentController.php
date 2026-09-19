<?php

namespace App\Http\Controllers;

use App\Services\SupabaseService;
use App\Support\ClassCustomization;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StudentController extends Controller
{
    public function __construct(private SupabaseService $supabase) {}

    public function index()
    {
        $user = session('supabase_user');
        $profile = $this->supabase->adminSelect('profiles', '*', ['id' => $user['id']])[0] ?? $user;
        $gradeLevel = (int) ($profile['grade_level'] ?? 0);

        // The database returns only the top ten plus the current student.
        $rankingResult = $this->supabase->adminRpcResult('student_trophy_leaderboard', [
            'p_student_id' => $user['id'],
            'p_limit' => 10,
        ]);
        $rankingPayload = $rankingResult['error'] === null && is_array($rankingResult['data'][0] ?? null)
            ? $rankingResult['data'][0] : [];
        $rank = isset($rankingPayload['personal_rank']) && is_numeric($rankingPayload['personal_rank'])
            ? (int) $rankingPayload['personal_rank'] : 'N/A';
        $leaderboard = [];
        foreach (($rankingPayload['leaderboard'] ?? []) as $student) {
            if (!is_array($student) || !isset($student['id']) || !is_numeric($student['rank'] ?? null)) continue;
            $leaderboard[] = [
                'id' => (string) $student['id'],
                'rank' => max(1, (int) $student['rank']),
                'display_name' => mb_substr(trim((string) ($student['display_name'] ?? 'Student')) ?: 'Student', 0, 64),
                'level' => max(1, (int) ($student['level'] ?? 1)),
                'trophies' => max(0, (int) ($student['trophies'] ?? 0)),
                'is_current' => (bool) ($student['is_current'] ?? false),
            ];
        }

        $memberships = $this->supabase->adminSelect(
            'class_members',
            'class_id,joined_at',
            ['student_id' => $user['id']]
        );
        $membershipMap = [];
        foreach ($memberships as $membership) {
            $membershipMap[$membership['class_id']] = $membership['joined_at'] ?? null;
        }
        $membershipClassIds = array_keys($membershipMap);
        $classRows = empty($membershipClassIds) ? [] : $this->supabase->adminSelect(
            'classes', '*', [
                'id' => ['operator' => 'in', 'value' => '(' . implode(',', $membershipClassIds) . ')'],
            ]
        );
        $allClasses = array_column($classRows, null, 'id');

        $classIds = array_keys($allClasses);
        $endedSessions = empty($classIds) ? [] : $this->supabase->adminSelect(
            'quiz_sessions',
            'id,class_id,topic,room_code,status,retake_mode,created_at,ended_at',
            [
                'class_id' => ['operator' => 'in', 'value' => '(' . implode(',', $classIds) . ')'],
                'status' => ['operator' => 'in', 'value' => '(completed,active)'],
                'order' => 'created_at.desc',
            ]
        );
        $endedSessionIds = array_column($endedSessions, 'id');
        $eligibilityRows = empty($endedSessionIds) ? [] : $this->supabase->adminSelect(
            'quiz_session_students', 'session_id,eligibility_status,allowed_attempts,excuse_reason,retake_due_at', [
                'student_id' => $user['id'],
                'session_id' => ['operator' => 'in', 'value' => '(' . implode(',', $endedSessionIds) . ')'],
            ]
        );
        $eligibilityMap = array_column($eligibilityRows, null, 'session_id');

        $attemptRows = empty($endedSessionIds) ? [] : $this->supabase->adminSelect(
            'quiz_results', 'session_id', [
                'student_id' => $user['id'],
                'session_id' => ['operator' => 'in', 'value' => '(' . implode(',', $endedSessionIds) . ')'],
            ]
        );
        $attemptCounts = [];
        foreach ($attemptRows as $attempt) {
            $attemptCounts[$attempt['session_id']] = ($attemptCounts[$attempt['session_id']] ?? 0) + 1;
        }
        $endedSessions = array_values(array_filter(
            $endedSessions,
            function (array $session) use ($eligibilityMap, $attemptCounts): bool {
                $eligibility = $eligibilityMap[$session['id']] ?? null;
                if (!$eligibility) {
                    return false;
                }
                if (($session['status'] ?? '') === 'completed') {
                    return true;
                }

                return (bool) ($session['retake_mode'] ?? false)
                    && (
                        ($attemptCounts[$session['id']] ?? 0) >= (int) ($eligibility['allowed_attempts'] ?? 0)
                        || (
                            !empty($eligibility['retake_due_at'])
                            && now()->gte(\Carbon\Carbon::parse($eligibility['retake_due_at'], 'UTC'))
                        )
                    );
            }
        ));
        foreach ($endedSessions as &$session) {
            $session['eligibility'] = $eligibilityMap[$session['id']];
        }
        unset($session);

        $results = $this->supabase->adminSelect(
            'quiz_results',
            'id,session_id,correct_answers,total_questions,created_at',
            ['student_id' => $user['id'], 'is_counted' => true, 'order' => 'created_at.asc']
        );
        $firstResultMap = [];
        foreach ($results as $result) {
            $firstResultMap[$result['session_id']] ??= $result;
        }

        $sessionMap = array_column($endedSessions, null, 'id');
        $quizHistory = [];
        foreach ($firstResultMap as $sessionId => $result) {
            $session = $sessionMap[$sessionId] ?? null;
            if (!$session) {
                continue;
            }
            $result['quiz_sessions'] = $session;
            $quizHistory[] = $result;
        }
        usort($quizHistory, fn (array $a, array $b): int =>
            (\App\Support\AppDate::parse($b['created_at'] ?? null)?->getTimestamp() ?? 0)
            <=> (\App\Support\AppDate::parse($a['created_at'] ?? null)?->getTimestamp() ?? 0)
        );

        $missedQuizzes = [];
        foreach ($endedSessions as $session) {
            if (($session['eligibility']['eligibility_status'] ?? '') === 'excused') {
                continue;
            }
            if (isset($firstResultMap[$session['id']])) {
                continue;
            }
            $session['class_name'] = $allClasses[$session['class_id']]['class_name'] ?? 'Class';
            $missedQuizzes[] = $session;
        }

        $accuracies = [];
        foreach ($quizHistory as &$history) {
            $total = (int) ($history['total_questions'] ?? 0);
            $history['accuracy'] = $total > 0
                ? round(((int) $history['correct_answers'] / $total) * 100, 1)
                : 0;
            $accuracies[] = $history['accuracy'];
        }
        unset($history);

        $studentAnalytics = [
            'ended' => count($endedSessions),
            'taken' => count($quizHistory),
            'missed' => count($missedQuizzes),
            'excused' => count(array_filter(
                $endedSessions,
                fn (array $session): bool => ($session['eligibility']['eligibility_status'] ?? '') === 'excused'
            )),
            'passed' => count(array_filter($accuracies, fn (float $accuracy): bool => $accuracy >= 75)),
            'failed' => count(array_filter($accuracies, fn (float $accuracy): bool => $accuracy < 75)),
            'average' => !empty($accuracies) ? round(array_sum($accuracies) / count($accuracies), 1) : 0,
            'best' => !empty($accuracies) ? max($accuracies) : 0,
        ];

        $classes = array_values(array_filter($allClasses, fn (array $class): bool => empty($class['archived_at'])));
        $activeClassIds = array_column($classes, 'id');
        $customizationRows = empty($activeClassIds) ? [] : $this->supabase->adminSelect(
            'class_customizations', '*', [
                'class_id' => ['operator' => 'in', 'value' => '(' . implode(',', $activeClassIds) . ')'],
            ]
        );
        $customizationMap = array_column($customizationRows, null, 'class_id');
        foreach ($classes as &$class) {
            $class['customization'] = ClassCustomization::normalize(
                $customizationMap[$class['id']] ?? [],
                '#22c55e'
            );
        }
        unset($class);

        return view('student.dashboard', compact(
            'user', 'profile', 'rank', 'leaderboard', 'quizHistory', 'missedQuizzes',
            'studentAnalytics', 'classes', 'gradeLevel'
        ));
    }

    public function reportProgress(Request $request)
    {
        $user = session('supabase_user');
        $format = $request->query('format', 'pdf');

        $eligibilityRows = $this->supabase->adminSelect(
            'quiz_session_students',
            'session_id,eligibility_status,excuse_reason',
            ['student_id' => $user['id']]
        );
        $resultRows = $this->supabase->adminSelect(
            'quiz_results',
            'session_id,correct_answers,total_questions,created_at',
            [
                'student_id' => $user['id'],
                'is_counted' => true,
                'order' => 'created_at.asc',
            ]
        );
        $sessionIds = array_values(array_unique(array_merge(
            array_column($eligibilityRows, 'session_id'),
            array_column($resultRows, 'session_id')
        )));
        $sessions = empty($sessionIds) ? [] : $this->supabase->adminSelect(
            'quiz_sessions',
            'id,class_id,topic,room_code,status,created_at,ended_at',
            [
                'id' => ['operator' => 'in', 'value' => '(' . implode(',', $sessionIds) . ')'],
                'status' => 'completed',
                'order' => 'ended_at.desc,created_at.desc',
            ]
        );

        $classIds = array_values(array_unique(array_filter(array_column($sessions, 'class_id'))));
        $classes = empty($classIds) ? [] : $this->supabase->adminSelect(
            'classes',
            'id,class_name',
            ['id' => ['operator' => 'in', 'value' => '(' . implode(',', $classIds) . ')']]
        );
        $classMap = array_column($classes, null, 'id');
        $eligibilityMap = array_column($eligibilityRows, null, 'session_id');
        $resultMap = [];
        foreach ($resultRows as $result) {
            $resultMap[$result['session_id']] ??= $result;
        }

        $rows = [];
        foreach ($sessions as $session) {
            $result = $resultMap[$session['id']] ?? null;
            $eligibility = $eligibilityMap[$session['id']] ?? null;
            $isExcused = !$result && ($eligibility['eligibility_status'] ?? '') === 'excused';

            if ($result) {
                $total = (int) ($result['total_questions'] ?? 0);
                $correct = (int) ($result['correct_answers'] ?? 0);
                $accuracy = $total > 0 ? round(($correct / $total) * 100, 1) : 0;
                $status = $accuracy >= 75 ? 'Passed' : 'Failed';
                $score = "{$correct} / {$total}";
                $date = \App\Support\AppDate::format($result['created_at'], 'M d, Y h:i A');
            } else {
                $accuracy = null;
                $status = $isExcused ? 'Excused' : 'Missed';
                $score = '—';
                $date = '—';
            }

            $rows[] = [
                'topic' => $session['topic'] ?? 'Untitled Quiz',
                'class_name' => $classMap[$session['class_id']]['class_name'] ?? 'Former Class',
                'room_code' => $session['room_code'] ?? '—',
                'score' => $score,
                'accuracy' => $accuracy,
                'status' => $status,
                'date' => $date,
            ];
        }

        $attemptRows = array_values(array_filter($rows, fn (array $row): bool => $row['accuracy'] !== null));
        $attempts = count($attemptRows);
        $passed = count(array_filter($rows, fn (array $row): bool => $row['status'] === 'Passed'));
        $failed = count(array_filter($rows, fn (array $row): bool => $row['status'] === 'Failed'));
        $missed = count(array_filter($rows, fn (array $row): bool => $row['status'] === 'Missed'));
        $excused = count(array_filter($rows, fn (array $row): bool => $row['status'] === 'Excused'));
        $accuracies = array_column($attemptRows, 'accuracy');
        $summary = [
            'student' => trim(($user['last_name'] ?? '') . ', ' . ($user['first_name'] ?? ''), ', '),
            'grade' => 'Grade ' . ($user['grade_level'] ?? 'N/A'),
            'ended' => count($rows),
            'attempts' => $attempts,
            'passed' => $passed,
            'failed' => $failed,
            'missed' => $missed,
            'excused' => $excused,
            'average' => $attempts > 0 ? round(array_sum($accuracies) / $attempts, 1) : null,
            'best' => $attempts > 0 ? max($accuracies) : null,
            'generated' => now()->format('M d, Y h:i A'),
        ];

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($summary, $rows) {
                $out = fopen('php://output', 'w');
                $this->writeCsvRow($out, ['MathVerse Personal Progress Report']);
                $this->writeCsvRow($out, ['Student', $summary['student']]);
                $this->writeCsvRow($out, ['Grade', $summary['grade']]);
                $this->writeCsvRow($out, ['Ended Assignments', $summary['ended']]);
                $this->writeCsvRow($out, ['Completed Attempts', $summary['attempts']]);
                $this->writeCsvRow($out, ['Passed', $summary['passed']]);
                $this->writeCsvRow($out, ['Failed', $summary['failed']]);
                $this->writeCsvRow($out, ['Missed', $summary['missed']]);
                $this->writeCsvRow($out, ['Excused', $summary['excused']]);
                $this->writeCsvRow($out, ['Average Attempt Accuracy', $summary['average'] === null ? '—' : $summary['average'] . '%']);
                $this->writeCsvRow($out, ['Best Accuracy', $summary['best'] === null ? '—' : $summary['best'] . '%']);
                $this->writeCsvRow($out, []);
                $this->writeCsvRow($out, ['Quiz', 'Class', 'Room Code', 'Score', 'Accuracy', 'Status', 'Date Taken']);
                foreach ($rows as $row) {
                    $this->writeCsvRow($out, [
                        $row['topic'],
                        $row['class_name'],
                        $row['room_code'],
                        $row['score'],
                        $row['accuracy'] === null ? '—' : $row['accuracy'] . '%',
                        $row['status'],
                        $row['date'],
                    ]);
                }
                fclose($out);
            }, 'my-mathverse-progress.csv', ['Content-Type' => 'text/csv']);
        }

        $pdf = Pdf::loadView('reports.student-personal', compact('summary', 'rows'))
            ->setPaper('a4', 'landscape');

        return $pdf->download('my-mathverse-progress.pdf');
    }

    public function updateProfile(Request $request)
    {
        if ($avatarError = $this->rejectInvalidAvatar($request, '/student/dashboard?section=profile')) {
            return $avatarError;
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'grade_level' => 'required|integer|between:1,6',
            'leaderboard_alias' => 'nullable|string|min:2|max:30',
            'show_on_leaderboard' => 'nullable|boolean',
        ]);

        $user = session('supabase_user');
        $userId = $user['id'];
        $newGrade = (int) $validated['grade_level'];

        try {
            if ($newGrade !== (int) ($user['grade_level'] ?? 0)) {
                $memberships = $this->supabase->adminSelect('class_members', 'class_id', ['student_id' => $userId]);
                foreach ($memberships as $membership) {
                    $class = $this->supabase->adminSelect(
                        'classes', 'class_name,grade_level,archived_at', ['id' => $membership['class_id']]
                    )[0] ?? null;
                    if ($class && empty($class['archived_at']) && (int) $class['grade_level'] !== $newGrade) {
                        return redirect('/student/dashboard?section=profile')->with(
                            'error',
                            "Ask your teacher to remove or archive {$class['class_name']} before changing to Grade {$newGrade}."
                        );
                    }
                }
            }

            $profileUpdated = $this->supabase->updateProfile($userId, [
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'grade_level' => $newGrade,
                'leaderboard_alias' => trim((string) ($validated['leaderboard_alias'] ?? '')) ?: null,
                'show_on_leaderboard' => $request->boolean('show_on_leaderboard'),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('A student profile could not be updated.', [
                'user_id' => $userId,
                'exception' => $exception::class,
            ]);

            return redirect('/student/dashboard?section=profile')
                ->with('error', 'The profile service is temporarily unavailable. Please try again.');
        }

        if (!isset($profileUpdated[0]['id'])) {
            return redirect('/student/dashboard?section=profile')
                ->with('error', 'The profile could not be updated. Your active class grades must remain matched.');
        }

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
                return redirect('/student/dashboard?section=profile')
                    ->with('error', 'Your profile details were saved, but the new avatar could not be uploaded.');
            }

            if ($avatarResult['error'] === 'attach') {
                return redirect('/student/dashboard?section=profile')
                    ->with('error', 'Your profile details were saved, but the new avatar could not be attached.');
            }
        }

        $updated = session('supabase_user');
        $updated['first_name'] = $validated['first_name'];
        $updated['last_name'] = $validated['last_name'];
        $updated['grade_level'] = $newGrade;
        $updated['leaderboard_alias'] = trim((string) ($validated['leaderboard_alias'] ?? '')) ?: null;
        $updated['show_on_leaderboard'] = $request->boolean('show_on_leaderboard');
        if ($avatarUrl) {
            $updated['avatar_url'] = $avatarUrl;
        }
        session(['supabase_user' => $updated]);

        $this->supabase->audit($updated, 'profile.updated', 'profile', $userId, [
            'grade_before' => $user['grade_level'] ?? null,
            'grade_after' => $newGrade,
            'avatar_changed' => $avatarUrl !== null,
        ]);

        return redirect('/student/dashboard?section=profile')->with('success', 'Profile updated successfully!');
    }
}
