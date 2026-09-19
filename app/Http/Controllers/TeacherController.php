<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\SupabaseService;
use App\Support\SupabaseAccessToken;
use App\Support\QuizAnswer;
use App\Support\QuizReportStatus;
use App\Support\ClassCustomization;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;

class TeacherController extends Controller
{
    public function __construct(private SupabaseService $supabase) {}

    public function index()
    {
        $user    = session('supabase_user');
        $token   = SupabaseAccessToken::from(request());

        if (!$user || !$token) {
            return redirect('/')->with('error', 'Please log in first.');
        }

        $ownedQuizzes = $this->supabase->adminSelect(
            'quizzes',
            'id',
            ['teacher_id' => $user['id']]
        );
        $quizCount = count($ownedQuizzes);

        // Classes owned by this teacher
        $allClasses = $this->supabase->adminSelect('classes', '*', ['teacher_id' => $user['id']]);
        usort($allClasses, fn ($a, $b): int =>
            (\App\Support\AppDate::parse($b['created_at'] ?? null)?->getTimestamp() ?? 0)
            <=> (\App\Support\AppDate::parse($a['created_at'] ?? null)?->getTimestamp() ?? 0)
        );

        $allClassIds = array_column($allClasses, 'id');
        $customizations = empty($allClassIds) ? [] : $this->supabase->adminSelect(
            'class_customizations', '*', [
                'class_id' => ['operator' => 'in', 'value' => '(' . implode(',', $allClassIds) . ')'],
            ]
        );
        $customizationMap = array_column($customizations, null, 'class_id');
        foreach ($allClasses as &$class) {
            $class['customization'] = ClassCustomization::normalize(
                $customizationMap[$class['id']] ?? []
            );
        }
        unset($class);

        $classes = array_values(array_filter($allClasses, fn (array $class): bool => empty($class['archived_at'])));
        $archivedClasses = array_values(array_filter($allClasses, fn (array $class): bool => !empty($class['archived_at'])));
        $activeClassIds = array_column($classes, 'id');

        $memberships = empty($activeClassIds) ? [] : $this->supabase->adminSelect(
            'class_members',
            'student_id,class_id',
            ['class_id' => ['operator' => 'in', 'value' => '(' . implode(',', $activeClassIds) . ')']]
        );
        $studentCount = count(array_unique(array_column($memberships, 'student_id')));

        $recentQuizzes = empty($activeClassIds) ? [] : $this->supabase->adminSelect(
            'quiz_sessions',
            '*',
            [
                'teacher_id' => $user['id'],
                'class_id' => ['operator' => 'in', 'value' => '(' . implode(',', $activeClassIds) . ')'],
                'order' => 'created_at.desc',
                'limit' => 5,
            ]
        );

        return view('teacher.dashboard', compact(
            'user', 'studentCount', 'quizCount',
            'recentQuizzes', 'classes', 'archivedClasses'
        ));
    }

    // ── Profile ───────────────────────────────────────────

    public function updateProfile(Request $request)
    {
        if ($avatarError = $this->rejectInvalidAvatar($request, '/teacher/dashboard?section=profile')) {
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
            Log::warning('A teacher profile could not be updated.', [
                'user_id' => $userId,
                'exception' => $exception::class,
            ]);

            return redirect('/teacher/dashboard?section=profile')
                ->with('error', 'The profile service is temporarily unavailable. Please try again.');
        }
        if (!isset($profileUpdated[0]['id'])) {
            return redirect('/teacher/dashboard?section=profile')
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
                return redirect('/teacher/dashboard?section=profile')
                    ->with('error', 'Your profile details were saved, but the new avatar could not be uploaded.');
            }

            if ($avatarResult['error'] === 'attach') {
                return redirect('/teacher/dashboard?section=profile')
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

        return redirect('/teacher/dashboard?section=profile')->with('success', 'Profile updated successfully!');
    }

    public function stats()
    {
        $user = session('supabase_user');

        $classes = $this->supabase->adminSelect(
            'classes',
            'id,class_name,archived_at',
            ['teacher_id' => $user['id'], 'archived_at' => ['operator' => 'is', 'value' => 'null']]
        );
        $classIds = array_column($classes, 'id');
        $quizzes = empty($classIds) ? [] : $this->supabase->adminSelect(
            'quiz_sessions',
            'id,topic,created_at,class_id',
            ['class_id' => ['operator' => 'in', 'value' => '(' . implode(',', $classIds) . ')']]
        );
        $quizIds    = array_column($quizzes, 'id');
        $allResults = empty($quizIds) ? [] : $this->supabase->adminSelect(
            'quiz_results', 'correct_answers,total_questions,created_at,session_id,student_id', [
                'session_id' => ['operator' => 'in', 'value' => '(' . implode(',', $quizIds) . ')'],
                'is_counted' => true,
                'order' => 'created_at.asc',
            ]
        );

        // Average accuracy for each non-archived class.
        $classAccuracy = [];
        foreach ($classes as $class) {
            $sessionIds = array_column(array_values(array_filter(
                $quizzes,
                fn (array $quiz): bool => $quiz['class_id'] === $class['id']
            )), 'id');
            $classResults = array_values(array_filter(
                $allResults,
                fn (array $result): bool => in_array($result['session_id'], $sessionIds, true)
            ));
            $accuracies = array_map(fn (array $result): float => ($result['total_questions'] ?? 0) > 0
                ? (($result['correct_answers'] ?? 0) / $result['total_questions']) * 100
                : 0, $classResults);
            $classAccuracy[] = [
                'class_name' => $class['class_name'],
                'accuracy' => !empty($accuracies) ? round(array_sum($accuracies) / count($accuracies), 1) : 0,
                'attempts' => count($classResults),
            ];
        }

        // Attempts per day (last 14 days)
        $attemptsPerDay = [];
        $attemptCounts = \App\Support\AppDate::countsByDay($allResults);
        $today = \Carbon\CarbonImmutable::now(\App\Support\AppDate::timezone())->startOfDay();
        for ($i = 13; $i >= 0; $i--) {
            $day = $today->subDays($i);
            $label = $day->format('M d');
            $count = $attemptCounts[$day->toDateString()] ?? 0;
            $attemptsPerDay[] = ['date' => $label, 'count' => $count];
        }

        // Score distribution
        $distribution = [0, 0, 0, 0];
        foreach ($allResults as $r) {
            if ($r['total_questions'] === 0) continue;
            $pct = ($r['correct_answers'] / $r['total_questions']) * 100;
            if      ($pct <= 25) $distribution[0]++;
            elseif  ($pct <= 50) $distribution[1]++;
            elseif  ($pct <= 75) $distribution[2]++;
            else                 $distribution[3]++;
        }

        $totalAttempts = count($allResults);
        $avgAccuracy   = $totalAttempts > 0
            ? round(array_sum(array_map(fn($r) =>
                $r['total_questions'] > 0 ? ($r['correct_answers'] / $r['total_questions']) * 100 : 0,
                $allResults)) / $totalAttempts, 1)
            : 0;

        return response()->json([
            'classAccuracy'  => $classAccuracy,
            'attemptsPerDay' => $attemptsPerDay,
            'distribution'   => $distribution,
            'totalAttempts'  => $totalAttempts,
            'totalQuizzes'   => count($quizzes),
            'avgAccuracy'    => $avgAccuracy,
        ]);
    }

    public function reportQuizPerformance(Request $request)
    {
        $user    = session('supabase_user');
        $format  = $request->query('format', 'pdf');

        $quizzes    = $this->supabase->adminSelect('quiz_sessions', 'id,topic,room_code,created_at,class_id', ['teacher_id' => $user['id']]);
        $quizzes    = array_values(array_filter($quizzes, fn ($quiz) => !empty($quiz['class_id'])));
        $quizIds    = array_column($quizzes, 'id');
        $allResults = empty($quizIds) ? [] : $this->supabase->adminSelect(
            'quiz_results', 'correct_answers,total_questions,created_at,session_id,student_id', [
                'session_id' => ['operator' => 'in', 'value' => '(' . implode(',', $quizIds) . ')'],
                'is_counted' => true,
                'order' => 'created_at.asc',
            ]
        );

        $rows = [];
        foreach ($quizzes as $q) {
            $qResults = array_values(array_filter($allResults, fn($r) => $r['session_id'] === $q['id']));
            $attempts = count($qResults);
            $avgAcc   = null;
            $passed   = 0;

            if ($attempts > 0) {
                $avgAcc = round(array_sum(array_map(fn($r) =>
                    $r['total_questions'] > 0 ? ($r['correct_answers'] / $r['total_questions']) * 100 : 0,
                    $qResults)) / $attempts, 1);
                $passed = count(array_filter($qResults, fn($r) =>
                    $r['total_questions'] > 0 && ($r['correct_answers'] / $r['total_questions']) >= 0.75));
            }

            $rows[] = [
                'topic'     => $q['topic'],
                'room_code' => $q['room_code'],
                'attempts'  => $attempts,
                'avg_acc'   => $avgAcc,
                'pass_rate' => $attempts > 0 ? round(($passed / $attempts) * 100, 1) : null,
                'date'      => \App\Support\AppDate::format($q['created_at'], 'M d, Y'),
            ];
        }

        if ($format === 'csv') {
            return $this->downloadCsv($rows,
                ['Topic', 'Room Code', 'Attempts', 'Avg Accuracy %', 'Pass Rate %', 'Date Created'],
                ['topic', 'room_code', 'attempts', 'avg_acc', 'pass_rate', 'date'],
                'quiz-performance-report'
            );
        }

        $pdf = Pdf::loadView('reports.quiz-performance', [
            'rows'      => $rows,
            'teacher'   => $user,
            'generated' => now()->format('M d, Y h:i A'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('quiz-performance-report.pdf');
    }

    public function reportStudentProgress(Request $request)
    {
        $user   = session('supabase_user');
        $format = $request->query('format', 'pdf');

        // Get all students from teacher's classes
        $classes    = $this->supabase->adminSelect('classes', 'id,class_name', ['teacher_id' => $user['id']]);
        $classIds   = array_column($classes, 'id');
        $members = empty($classIds) ? [] : $this->supabase->adminSelect(
            'class_members',
            'student_id,class_id,profiles(first_name,last_name,grade_level,trophies)',
            ['class_id' => ['operator' => 'in', 'value' => '(' . implode(',', $classIds) . ')']]
        );
        $studentIds = array_unique(array_column(array_values($members), 'student_id'));
        $studentProfiles = [];
        foreach ($members as $member) {
            if (!isset($studentProfiles[$member['student_id']]) && !empty($member['profiles'])) {
                $studentProfiles[$member['student_id']] = $member['profiles'];
            }
        }

        $classSessions = empty($classIds)
            ? []
            : $this->supabase->adminSelect(
                'quiz_sessions',
                'id',
                ['class_id' => ['operator' => 'in', 'value' => '(' . implode(',', $classIds) . ')']]
            );
        $classSessionIds = array_column($classSessions, 'id');

        $allResults = empty($classSessionIds) ? [] : $this->supabase->adminSelect(
            'quiz_results',
            'correct_answers,total_questions,student_id,session_id,created_at',
            [
                'session_id' => ['operator' => 'in', 'value' => '(' . implode(',', $classSessionIds) . ')'],
                'is_counted' => true,
                'order' => 'created_at.asc',
            ]
        );

        $rows = [];
        foreach ($studentIds as $sid) {
            $p = $studentProfiles[$sid] ?? null;
            if (!$p) continue;

            $sResults = array_values(array_filter($allResults, fn($r) => $r['student_id'] === $sid));
            $attempts = count($sResults);
            $avgAcc   = null;

            if ($attempts > 0) {
                $avgAcc = round(array_sum(array_map(fn($r) =>
                    $r['total_questions'] > 0 ? ($r['correct_answers'] / $r['total_questions']) * 100 : 0,
                    $sResults)) / $attempts, 1);
            }

            $rows[] = [
                'name'        => ($p['last_name'] ?? '') . ', ' . ($p['first_name'] ?? ''),
                'grade'       => 'Grade ' . ($p['grade_level'] ?? 'N/A'),
                'quizzes'     => $attempts,
                'avg_acc'     => $avgAcc,
                'trophies'    => $p['trophies'] ?? 0,
            ];
        }

        usort($rows, fn($a, $b) =>
            (($b['avg_acc'] ?? -1) <=> ($a['avg_acc'] ?? -1))
            ?: strcmp($a['name'], $b['name'])
        );

        if ($format === 'csv') {
            return $this->downloadCsv($rows,
                ['Student Name', 'Grade Level', 'Quizzes Taken', 'Avg Accuracy %', 'Trophies'],
                ['name', 'grade', 'quizzes', 'avg_acc', 'trophies'],
                'student-progress-report'
            );
        }

        $pdf = Pdf::loadView('reports.student-progress', [
            'rows'      => $rows,
            'teacher'   => $user,
            'generated' => now()->format('M d, Y h:i A'),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('student-progress-report.pdf');
    }

    public function reportClasses(Request $request)
    {
        $user   = session('supabase_user');
        $format = $request->query('format', 'pdf');

        $classes = $this->supabase->adminSelect('classes', '*', ['teacher_id' => $user['id']]);
        $classIds = array_column($classes, 'id');
        $members = empty($classIds) ? [] : $this->supabase->adminSelect(
            'class_members', 'student_id,class_id,profiles(first_name,last_name)', [
                'class_id' => ['operator' => 'in', 'value' => '(' . implode(',', $classIds) . ')'],
            ]
        );

        $rows = [];
        foreach ($classes as $c) {
            $classMembers = array_values(array_filter($members, fn($m) => $m['class_id'] === $c['id']));
            $studentNames = [];

            foreach ($classMembers as $m) {
                if (!empty($m['profiles'])) {
                    $p = $m['profiles'];
                    $studentNames[] = ($p['last_name'] ?? '') . ', ' . ($p['first_name'] ?? '');
                }
            }

            $rows[] = [
                'class_name' => $c['class_name'],
                'join_code'  => $c['join_code'],
                'students'   => count($classMembers),
                'roster'     => implode(' | ', $studentNames),
                'created'    => \App\Support\AppDate::format($c['created_at'], 'M d, Y'),
            ];
        }

        if ($format === 'csv') {
            return $this->downloadCsv($rows,
                ['Class Name', 'Join Code', 'Total Students', 'Roster', 'Date Created'],
                ['class_name', 'join_code', 'students', 'roster', 'created'],
                'classes-report'
            );
        }

        $pdf = Pdf::loadView('reports.classes', [
            'rows'      => $rows,
            'teacher'   => $user,
            'generated' => now()->format('M d, Y h:i A'),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('classes-report.pdf');
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

    public function reportSingleQuiz(Request $request, string $id)
    {
        $format = $request->query('format', 'pdf');
        $user   = session('supabase_user');

        // Quiz details
        $quiz = $this->supabase->adminSelect('quiz_sessions', '*', ['id' => $id]);
        $quiz = $quiz[0] ?? null;

        if (!$quiz || ($quiz['teacher_id'] ?? null) !== $user['id'] || empty($quiz['class_id'])) {
            return back()->with('error', 'Quiz not found.');
        }

        // Questions
        $questions = $this->supabase->adminSelect('questions', '*', [
            'session_id' => $id,
            'order' => 'created_at.asc',
        ]);
        foreach ($questions as &$question) {
            $answer = QuizAnswer::resolve($question);
            $question['correct_answer_index'] = $answer['index'];
            $question['correct_answer_text'] = $answer['option'];
            $question['correct_answer_label'] = $answer['label'];
        }
        unset($question);

        // Eligibility is the assignment-time roster. Fall back to current class
        // members only for legacy sessions created before eligibility snapshots.
        $members = $this->supabase->adminSelect(
            'class_members',
            'student_id,joined_at',
            ['class_id' => $quiz['class_id'], 'order' => 'joined_at.asc']
        );
        $results = $this->supabase->adminSelect(
            'quiz_results',
            'correct_answers,total_questions,created_at,student_id,session_id',
            ['session_id' => $id, 'is_counted' => true, 'order' => 'created_at.asc']
        );
        $resultMap = array_column($results, null, 'student_id');
        $eligibility = $this->supabase->adminSelect(
            'quiz_session_students',
            'student_id,eligibility_status,allowed_attempts,retake_due_at,excuse_reason',
            ['session_id' => $id]
        );
        $eligibilityMap = array_column($eligibility, null, 'student_id');
        $legacyMembers = $members;
        $assignmentCutoff = $quiz['ended_at'] ?? $quiz['due_at'] ?? null;
        $cutoffTimestamp = \App\Support\AppDate::parse($assignmentCutoff)?->getTimestamp();
        if ($cutoffTimestamp !== null) {
            $legacyMembers = array_values(array_filter(
                $legacyMembers,
                fn (array $member): bool => empty($member['joined_at'])
                    || (\App\Support\AppDate::parse($member['joined_at'])?->getTimestamp() ?? PHP_INT_MAX) <= $cutoffTimestamp
            ));
        }
        $assignedStudentIds = $eligibility !== []
            ? array_column($eligibility, 'student_id')
            : array_column($legacyMembers, 'student_id');
        $studentIds = array_values(array_unique(array_merge(
            $assignedStudentIds,
            array_column($results, 'student_id')
        )));
        $profiles = empty($studentIds) ? [] : $this->supabase->adminSelect(
            'profiles',
            'id,first_name,last_name,grade_level',
            ['id' => ['operator' => 'in', 'value' => '(' . implode(',', $studentIds) . ')']]
        );
        $profileMap = array_column($profiles, null, 'id');

        $rows = [];
        foreach ($studentIds as $studentId) {
            $profile = $profileMap[$studentId] ?? [];
            $result = $resultMap[$studentId] ?? null;
            $studentEligibility = $eligibilityMap[$studentId] ?? null;
            $isExcused = !$result
                && ($studentEligibility['eligibility_status'] ?? '') === 'excused';
            $deadlineOpen = empty($quiz['due_at'])
                || now()->lt(\Carbon\Carbon::parse($quiz['due_at'], 'UTC'));
            $canStillSubmit = $deadlineOpen && (
                !($quiz['retake_mode'] ?? false)
                || (int) ($studentEligibility['allowed_attempts'] ?? 0) > 0
            );
            if (!empty($studentEligibility['retake_due_at'])
                && now()->gte(\Carbon\Carbon::parse($studentEligibility['retake_due_at'], 'UTC'))) {
                $canStillSubmit = false;
            }

            if ($result) {
                $total = (int) ($result['total_questions'] ?? 0);
                $correct = (int) ($result['correct_answers'] ?? 0);
                $accuracy = $total > 0 ? round(($correct / $total) * 100, 1) : 0;
                $status = $accuracy >= 75 ? 'Passed' : 'Failed';
                $date = \App\Support\AppDate::format($result['created_at'], 'M d, Y h:i A');
            } else {
                $total = null;
                $correct = null;
                $accuracy = null;
                $status = QuizReportStatus::withoutResult(
                    $isExcused ? 'excused' : ($studentEligibility['eligibility_status'] ?? null),
                    $quiz['status'] ?? null,
                    $canStillSubmit
                );
                $date = '—';
            }

            $rows[] = [
                'name'     => trim(($profile['last_name'] ?? '') . ', ' . ($profile['first_name'] ?? ''), ', ') ?: 'Unknown',
                'grade'    => 'Grade ' . ($profile['grade_level'] ?? 'N/A'),
                'score'    => $correct === null ? '—' : $correct . ' / ' . $total,
                'accuracy' => $accuracy,
                'status'   => $status,
                'date'     => $date,
            ];
        }

        $statusOrder = ['Passed' => 0, 'Failed' => 1, 'Pending' => 2, 'Missed' => 3, 'Excused' => 4];
        usort($rows, fn ($a, $b) =>
            (($statusOrder[$a['status']] ?? 9) <=> ($statusOrder[$b['status']] ?? 9))
            ?: (($b['accuracy'] ?? -1) <=> ($a['accuracy'] ?? -1))
            ?: strcmp($a['name'], $b['name'])
        );
        $completedRank = 0;
        foreach ($rows as &$row) {
            $row['rank'] = $row['accuracy'] === null ? null : ++$completedRank;
        }
        unset($row);

        $totalStudents = count($rows);
        $totalAttempts = count($results);
        $attemptAccuracies = array_values(array_filter(
            array_column($rows, 'accuracy'),
            fn ($accuracy): bool => $accuracy !== null
        ));
        $avgAccuracy   = $totalAttempts > 0
            ? round(array_sum($attemptAccuracies) / $totalAttempts, 1)
            : 0;
        $passed  = count(array_filter($rows, fn($r) => $r['status'] === 'Passed'));
        $failed  = count(array_filter($rows, fn($r) => $r['status'] === 'Failed'));
        $missed = count(array_filter($rows, fn($r) => $r['status'] === 'Missed'));
        $pending = count(array_filter($rows, fn($r) => $r['status'] === 'Pending'));
        $excused = count(array_filter($rows, fn($r) => $r['status'] === 'Excused'));
        $eligibleStudents = max(0, $totalStudents - $excused);

        $summary = [
            'topic'         => $quiz['topic'],
            'room_code'     => $quiz['room_code'],
            'total_questions' => count($questions),
            'total_students'  => $totalStudents,
            'total_attempts'  => $totalAttempts,
            'avg_accuracy'    => $avgAccuracy,
            'passed'          => $passed,
            'failed'          => $failed,
            'missed'          => $missed,
            'pending'         => $pending,
            'excused'         => $excused,
            'pass_rate'       => $totalAttempts > 0 ? round(($passed / $totalAttempts) * 100, 1) : 0,
            'completion_rate' => $eligibleStudents > 0
                ? round(($totalAttempts / $eligibleStudents) * 100, 1)
                : 0,
            'created'         => \App\Support\AppDate::format($quiz['created_at'], 'M d, Y'),
            'teacher'         => ($user['last_name'] ?? '') . ', ' . ($user['first_name'] ?? ''),
        ];

        if ($format === 'csv') {
            // CSV has two sections — summary then results
            return response()->streamDownload(function () use ($summary, $rows, $questions) {
                $out = fopen('php://output', 'w');

                // Summary section
                $this->writeCsvRow($out, ['Quiz Performance Report']);
                $this->writeCsvRow($out, ['Topic',           $summary['topic']]);
                $this->writeCsvRow($out, ['Room Code',        $summary['room_code']]);
                $this->writeCsvRow($out, ['Total Questions',  $summary['total_questions']]);
                $this->writeCsvRow($out, ['Total Students',   $summary['total_students']]);
                $this->writeCsvRow($out, ['Total Attempts',   $summary['total_attempts']]);
                $this->writeCsvRow($out, ['Missed',           $summary['missed']]);
                $this->writeCsvRow($out, ['Pending',          $summary['pending']]);
                $this->writeCsvRow($out, ['Excused',          $summary['excused']]);
                $this->writeCsvRow($out, ['Passed',           $summary['passed']]);
                $this->writeCsvRow($out, ['Failed',           $summary['failed']]);
                $this->writeCsvRow($out, ['Avg Attempt Accuracy', $summary['avg_accuracy'] . '%']);
                $this->writeCsvRow($out, ['Pass Rate of Attempts', $summary['pass_rate'] . '%']);
                $this->writeCsvRow($out, ['Completion Rate',   $summary['completion_rate'] . '%']);
                $this->writeCsvRow($out, ['Date Created',      $summary['created']]);
                $this->writeCsvRow($out, []);

                // Questions section
                $this->writeCsvRow($out, ['Questions']);
                $this->writeCsvRow($out, ['#', 'Question', 'Correct Answer']);
                foreach ($questions as $i => $q) {
                    $this->writeCsvRow($out, [$i + 1, $q['question'], $q['correct_answer_label']]);
                }
                $this->writeCsvRow($out, []);

                // Results section
                $this->writeCsvRow($out, ['Student Results']);
                $this->writeCsvRow($out, ['Rank', 'Student Name', 'Grade', 'Score', 'Accuracy', 'Status', 'Date Taken']);
                foreach ($rows as $r) {
                    $this->writeCsvRow($out, [
                        $r['rank'] ?? '—',
                        $r['name'],
                        $r['grade'],
                        $r['score'],
                        $r['accuracy'] === null ? '—' : $r['accuracy'] . '%',
                        $r['status'],
                        $r['date'],
                    ]);
                }

                fclose($out);
            }, "quiz-{$quiz['room_code']}-report.csv", ['Content-Type' => 'text/csv']);
        }

        $pdf = Pdf::loadView('reports.single-quiz', compact('summary', 'rows', 'questions'))
            ->setPaper('a4', 'portrait');

        return $pdf->download("quiz-{$quiz['room_code']}-report.pdf");
    }

    public function reportSingleClassroom(Request $request, string $id)
    {
        $format = $request->query('format', 'pdf');
        $user   = session('supabase_user');

        // Class details
        $class = $this->supabase->adminSelect('classes', '*', ['id' => $id]);
        $class = $class[0] ?? null;

        if (!$class || ($class['teacher_id'] ?? null) !== $user['id']) {
            return back()->with('error', 'Class not found.');
        }

        // Members
        $members = $this->supabase->adminSelect(
            'class_members',
            'student_id,joined_at,profiles(first_name,last_name,grade_level,trophies,level)',
            ['class_id' => $id]
        );

        // Only results produced by quiz sessions assigned to this class.
        $classSessions = $this->supabase->adminSelect('quiz_sessions', 'id', ['class_id' => $id]);
        $classSessionIds = array_column($classSessions, 'id');
        $allResults = empty($classSessionIds) ? [] : $this->supabase->adminSelect(
            'quiz_results',
            'correct_answers,total_questions,student_id,session_id,created_at',
            [
                'session_id' => ['operator' => 'in', 'value' => '(' . implode(',', $classSessionIds) . ')'],
                'is_counted' => true,
                'order' => 'created_at.asc',
            ]
        );

        $rows = [];
        foreach ($members as $m) {
            $p = $m['profiles'] ?? null;
            if (!$p) continue;

            $sResults = array_values(array_filter($allResults, fn($r) => $r['student_id'] === $m['student_id']));
            $attempts = count($sResults);
            $avgAcc   = null;

            if ($attempts > 0) {
                $avgAcc = round(array_sum(array_map(fn($r) =>
                    $r['total_questions'] > 0
                        ? ($r['correct_answers'] / $r['total_questions']) * 100
                        : 0,
                    $sResults)) / $attempts, 1);
            }

            $rows[] = [
                'name'     => ($p['last_name'] ?? '') . ', ' . ($p['first_name'] ?? ''),
                'grade'    => 'Grade ' . ($p['grade_level'] ?? 'N/A'),
                'level'    => $p['level'] ?? 1,
                'trophies' => $p['trophies'] ?? 0,
                'quizzes'  => $attempts,
                'avg_acc'  => $avgAcc,
                'joined'   => isset($m['joined_at'])
                    ? \App\Support\AppDate::format($m['joined_at'], 'M d, Y')
                    : 'N/A',
            ];
        }

        usort($rows, fn($a, $b) =>
            (($b['avg_acc'] ?? -1) <=> ($a['avg_acc'] ?? -1))
            ?: strcmp($a['name'], $b['name'])
        );

        $classAccuracies = array_map(
            fn (array $result): float => (int) ($result['total_questions'] ?? 0) > 0
                ? ((int) ($result['correct_answers'] ?? 0) / (int) $result['total_questions']) * 100
                : 0,
            $allResults
        );

        $summary = [
            'class_name'     => $class['class_name'],
            'join_code'      => $class['join_code'],
            'total_students' => count($rows),
            'avg_accuracy'   => $classAccuracies !== []
                ? round(array_sum($classAccuracies) / count($classAccuracies), 1)
                : 0,
            'created'        => \App\Support\AppDate::format($class['created_at'], 'M d, Y'),
            'teacher'        => ($user['last_name'] ?? '') . ', ' . ($user['first_name'] ?? ''),
        ];

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($summary, $rows) {
                $out = fopen('php://output', 'w');

                $this->writeCsvRow($out, ['Classroom Report']);
                $this->writeCsvRow($out, ['Class Name',      $summary['class_name']]);
                $this->writeCsvRow($out, ['Join Code',        $summary['join_code']]);
                $this->writeCsvRow($out, ['Total Students',   $summary['total_students']]);
                $this->writeCsvRow($out, ['Avg Accuracy',     $summary['avg_accuracy'] . '%']);
                $this->writeCsvRow($out, ['Teacher',          $summary['teacher']]);
                $this->writeCsvRow($out, ['Date Created',     $summary['created']]);
                $this->writeCsvRow($out, []);

                $this->writeCsvRow($out, ['Student Roster']);
                $this->writeCsvRow($out, ['Rank', 'Student Name', 'Grade', 'Level', 'Trophies', 'Quizzes Taken', 'Avg Accuracy', 'Date Joined']);
                foreach ($rows as $i => $r) {
                    $this->writeCsvRow($out, [
                        $i + 1,
                        $r['name'],
                        $r['grade'],
                        $r['level'],
                        $r['trophies'],
                        $r['quizzes'],
                        $r['avg_acc'] === null ? '—' : $r['avg_acc'] . '%',
                        $r['joined'],
                    ]);
                }

                fclose($out);
            }, "classroom-{$class['join_code']}-report.csv", ['Content-Type' => 'text/csv']);
        }

        $pdf = Pdf::loadView('reports.single-classroom', compact('summary', 'rows'))
            ->setPaper('a4', 'portrait');

        return $pdf->download("classroom-{$class['join_code']}-report.pdf");
    }
}
