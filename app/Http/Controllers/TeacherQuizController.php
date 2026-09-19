<?php

namespace App\Http\Controllers;

use App\Jobs\RecalculateQuizUsageCount;
use App\Services\AdminPushService;
use App\Services\SupabaseService;
use App\Support\SupabaseAccessToken;
use Illuminate\Http\Request;

class TeacherQuizController extends Controller
{
    public function __construct(
        private SupabaseService $supabase,
        private AdminPushService $adminPush
    ) {}

    public function index(Request $request)
    {
        $user = session('supabase_user');
        [$search, $grade, $safeSearch] = $this->quizFilters($request);
        $filters = [
            'teacher_id' => $user['id'],
            'order' => 'created_at.desc',
        ];

        if ($grade !== null) {
            $filters['grade_level'] = $grade;
        }
        if ($safeSearch !== '') {
            $filters['topic'] = ['operator' => 'ilike', 'value' => "*{$safeSearch}*"];
        }

        $quizzes = $this->supabase->adminSelect(
            'quizzes',
            '*',
            $filters
        );

        $questionCounts = $this->questionCounts(array_column($quizzes, 'id'));
        foreach ($quizzes as &$quiz) {
            $quiz['question_count'] = $questionCounts[$quiz['id']] ?? 0;
        }
        unset($quiz);

        $classes = $this->teacherClasses($user['id']);
        $preferredClassId = $this->preferredClassId($request, $classes);

        return view('teacher.quizzes.index', compact(
            'user', 'quizzes', 'classes', 'preferredClassId', 'search', 'grade'
        ));
    }

    public function library(Request $request)
    {
        $user = session('supabase_user');
        [$search, $grade, $safeSearch] = $this->quizFilters($request);
        $bookmarkedOnly = $request->boolean('bookmarked');
        $verifiedOnly = $request->boolean('verified');
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 24;

        $filters = [
            'teacher_id' => ['operator' => 'neq', 'value' => $user['id']],
            'visibility' => 'shared',
        ];

        if ($grade !== null) {
            $filters['grade_level'] = $grade;
        }

        if ($safeSearch !== '') {
            $filters['topic'] = ['operator' => 'ilike', 'value' => "*{$safeSearch}*"];
        }

        if ($verifiedOnly) {
            $filters['verified_at'] = ['operator' => 'not.is', 'value' => 'null'];
        }

        if ($bookmarkedOnly) {
            $bookmarks = $this->supabase->adminSelect(
                'quiz_bookmarks', 'quiz_id', ['user_id' => $user['id']]
            );
            $bookmarkIds = array_values(array_unique(array_filter(array_column($bookmarks, 'quiz_id'))));
            if (empty($bookmarkIds)) {
                $result = ['data' => [], 'total' => 0];
            } else {
                $filters['id'] = ['operator' => 'in', 'value' => '(' . implode(',', $bookmarkIds) . ')'];
            }
        }

        if (!isset($result)) {
            $secondaryOrder = 'usage_count.desc,rating_average.desc,created_at.desc';
            if ($verifiedOnly) {
                $filters['order'] = $secondaryOrder;
                $result = $this->supabase->adminSelectPage(
                    'quizzes', '*', $filters, $perPage, ($page - 1) * $perPage
                );
            } else {
                $result = $this->supabase->adminSelectPrioritizedPage(
                    'quizzes',
                    '*',
                    $filters,
                    'verified_at',
                    $secondaryOrder,
                    $perPage,
                    ($page - 1) * $perPage
                );
            }
        }

        $quizzes = $result['data'];
        $total = $result['total'];
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages && $total > 0) {
            return redirect()->to($request->fullUrlWithQuery(['page' => $totalPages]));
        }

        $creatorNames = $this->creatorNames(array_column($quizzes, 'teacher_id'));
        $questionCounts = $this->questionCounts(array_column($quizzes, 'id'));
        $preferences = $this->libraryPreferences($user['id'], array_column($quizzes, 'id'));
        $quizzesByGrade = array_fill(1, 6, []);

        foreach ($quizzes as &$quiz) {
            $quiz['creator_name'] = $creatorNames[$quiz['teacher_id']] ?? 'MathVerse Teacher';
            $quiz['question_count'] = $questionCounts[$quiz['id']] ?? 0;
            $quiz['bookmarked'] = isset($preferences['bookmarks'][$quiz['id']]);
            $quiz['user_rating'] = $preferences['ratings'][$quiz['id']] ?? null;
            $quizzesByGrade[(int) $quiz['grade_level']][] = $quiz;
        }
        unset($quiz);

        $classes = $this->teacherClasses($user['id']);
        $preferredClassId = $this->preferredClassId($request, $classes);

        return view('teacher.quizzes.library', compact(
            'user', 'quizzesByGrade', 'classes', 'preferredClassId',
            'search', 'grade', 'page', 'total', 'totalPages', 'bookmarkedOnly', 'verifiedOnly'
        ));
    }

    public function review(Request $request, string $id)
    {
        $user = session('supabase_user');
        $quiz = $this->supabase->adminSelect('quizzes', '*', ['id' => $id])[0] ?? null;

        if (!$quiz || ($quiz['visibility'] ?? 'shared') !== 'shared') {
            return redirect('/teacher/quiz-library')->with('error', 'Quiz not found.');
        }

        if (($quiz['teacher_id'] ?? null) === $user['id']) {
            return redirect('/teacher/quizzes')->with('error', 'Open your quiz from My Quizzes to edit it.');
        }

        $templateQuestions = $this->supabase->adminSelect(
            'quiz_questions',
            '*',
            ['quiz_id' => $id, 'order' => 'position.asc']
        );
        $questionsForForm = $this->questionsForForm($templateQuestions);
        $reportQuestions = array_map(fn (array $question): array => [
            'id' => $question['id'],
            'position' => $question['position'] ?? null,
            'question' => $question['question'] ?? '',
        ], $templateQuestions);
        $creatorNames = $this->creatorNames([$quiz['teacher_id']]);
        $creatorName = $creatorNames[$quiz['teacher_id']] ?? 'MathVerse Teacher';
        $classes = $this->teacherClasses($user['id']);
        $preferredClassId = $this->preferredClassId($request, $classes);
        $preferences = $this->libraryPreferences($user['id'], [$id]);
        $isBookmarked = isset($preferences['bookmarks'][$id]);
        $userRating = $preferences['ratings'][$id] ?? null;

        return view('teacher.quizzes.review', compact(
            'user', 'quiz', 'questionsForForm', 'creatorName', 'classes', 'preferredClassId',
            'isBookmarked', 'userRating', 'reportQuestions'
        ));
    }

    public function toggleBookmark(string $id)
    {
        $user = session('supabase_user');
        $quiz = $this->sharedQuiz($id, $user['id']);
        if (!$quiz) {
            return back()->with('error', 'That shared quiz is no longer available.');
        }

        $existing = $this->supabase->adminSelect('quiz_bookmarks', 'quiz_id', [
            'quiz_id' => $id, 'user_id' => $user['id'],
        ]);
        if ($existing) {
            $this->supabase->adminDelete('quiz_bookmarks', [
                'quiz_id' => $id, 'user_id' => $user['id'],
            ]);
            return back()->with('success', 'Bookmark removed.');
        }

        $created = $this->supabase->adminInsert('quiz_bookmarks', [
            'quiz_id' => $id, 'user_id' => $user['id'],
        ]);
        return isset($created[0]['quiz_id'])
            ? back()->with('success', 'Quiz bookmarked.')
            : back()->with('error', 'The bookmark could not be saved.');
    }

    public function rate(Request $request, string $id)
    {
        $validated = $request->validate(['rating' => 'required|integer|between:1,5']);
        $user = session('supabase_user');
        if (!$this->sharedQuiz($id, $user['id'])) {
            return back()->with('error', 'Only another creator’s shared quiz can be rated.');
        }

        $saved = $this->supabase->adminUpsert('quiz_ratings', [
            'quiz_id' => $id,
            'user_id' => $user['id'],
            'rating' => (int) $validated['rating'],
            'updated_at' => now()->toIso8601String(),
        ], 'quiz_id,user_id');

        return isset($saved[0]['quiz_id'])
            ? back()->with('success', 'Your rating was saved.')
            : back()->with('error', 'Your rating could not be saved.');
    }

    public function report(Request $request, string $id)
    {
        $validated = $request->validate([
            'reason' => 'required|in:incorrect_answer,unclear_question,inappropriate,duplicate,other',
            'question_id' => 'nullable|integer|min:1',
            'details' => 'nullable|string|max:1000',
        ]);
        $user = session('supabase_user');
        $quiz = $this->sharedQuiz($id, $user['id']);
        if (!$quiz) {
            return back()->with('error', 'Only an available shared quiz can be reported.');
        }

        if (!empty($validated['question_id'])) {
            $question = $this->supabase->adminSelect('quiz_questions', 'id', [
                'id' => $validated['question_id'], 'quiz_id' => $id,
            ]);
            if (!$question) {
                return back()->with('error', 'The selected question is not part of this quiz.');
            }
        }

        $pending = $this->supabase->adminSelect('quiz_reports', 'id', [
            'quiz_id' => $id, 'reporter_id' => $user['id'], 'status' => 'pending',
        ]);
        if ($pending) {
            return back()->with('error', 'You already have a pending report for this quiz.');
        }

        $created = $this->supabase->adminInsert('quiz_reports', [
            'quiz_id' => $id,
            'reporter_id' => $user['id'],
            'question_id' => $validated['question_id'] ?? null,
            'reason' => $validated['reason'],
            'details' => trim((string) ($validated['details'] ?? '')) ?: null,
        ]);
        if (!isset($created[0]['id'])) {
            return back()->with('error', 'The report could not be submitted.');
        }

        $this->supabase->audit($user, 'quiz.reported', 'quiz', $id, [
            'reason' => $validated['reason'],
            'report_id' => $created[0]['id'],
        ]);

        $reporterName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))
            ?: 'A teacher';
        $reason = ucfirst(str_replace('_', ' ', $validated['reason']));
        $this->adminPush->sendAfterResponse(
            'Shared quiz reported',
            "{$reporterName} reported {$quiz['topic']}: {$reason}.",
            "/admin/quiz-reports/{$created[0]['id']}",
            "quiz-report-{$created[0]['id']}"
        );

        return back()->with('success', 'Report submitted for admin review.');
    }

    public function versions(string $id)
    {
        $user = session('supabase_user');
        $quiz = $this->ownedQuiz($id, $user['id']);
        if (!$quiz) {
            return redirect('/teacher/quizzes')->with('error', 'Quiz not found.');
        }

        $versions = $this->supabase->adminSelect(
            'quiz_versions', '*', ['quiz_id' => $id, 'order' => 'version.desc']
        );
        $currentVersionNumber = max(1, (int) ($quiz['version'] ?? 1));
        $currentQuestions = $this->supabase->adminSelect(
            'quiz_questions', '*', ['quiz_id' => $id, 'order' => 'position.asc']
        );
        $versions = array_values(array_filter(
            $versions,
            fn (array $version): bool => (int) ($version['version'] ?? 0) !== $currentVersionNumber
        ));
        array_unshift($versions, [
            'version' => $currentVersionNumber,
            'topic' => $quiz['topic'],
            'grade_level' => (int) $quiz['grade_level'],
            'visibility' => $quiz['visibility'] ?? 'shared',
            'questions' => $currentQuestions,
            'created_at' => $quiz['updated_at'] ?? $quiz['created_at'] ?? now()->toIso8601String(),
            'is_current' => true,
        ]);
        return view('teacher.quizzes.versions', compact('user', 'quiz', 'versions'));
    }

    public function assignShared(Request $request, string $id)
    {
        $validated = $this->validateSharedAssignmentQuiz($request);
        $assignment = $request->validate([
            'class_ids' => 'required|array|min:1|max:100',
            'class_ids.*' => 'uuid|distinct',
            'time_limit' => 'required|integer|between:5,300',
            'available_at' => 'nullable|date',
            'due_at' => 'nullable|date',
        ]);

        $user = session('supabase_user');
        $sourceQuizResult = $this->supabase->adminSelectResult('quizzes', '*', ['id' => $id]);
        if ($sourceQuizResult['error'] !== null) {
            return back()->withInput()->with(
                'error',
                $this->assignmentFailureMessage($sourceQuizResult['error'], true)
            );
        }
        $sourceQuiz = $sourceQuizResult['data'][0] ?? null;

        if (!$sourceQuiz
            || ($sourceQuiz['teacher_id'] ?? null) === $user['id']
            || ($sourceQuiz['visibility'] ?? 'shared') !== 'shared') {
            return redirect('/teacher/quiz-library')
                ->with('error', 'The shared quiz is no longer available.');
        }

        $classIds = array_values($assignment['class_ids']);
        $classResult = $this->supabase->adminSelectResult('classes', '*', [
            'id' => ['operator' => 'in', 'value' => '(' . implode(',', $classIds) . ')'],
            'teacher_id' => $user['id'],
        ]);
        if ($classResult['error'] !== null) {
            return back()->withInput()->with(
                'error',
                $this->assignmentFailureMessage($classResult['error'], true)
            );
        }
        $classes = $classResult['data'];
        $classesById = [];
        foreach ($classes as $class) {
            $classesById[$class['id']] = $class;
        }

        if (count($classesById) !== count($classIds)) {
            return back()->withInput()->with('error', 'One or more selected classes are not available.');
        }

        $grade = (int) $sourceQuiz['grade_level'];
        $orderedClasses = [];
        $classesToAssign = [];
        $existingAssignments = [];
        foreach ($classIds as $classId) {
            $class = $classesById[$classId];
            if (!empty($class['archived_at'])) {
                return back()->withInput()->with('error', 'Archived classes cannot receive quiz assignments.');
            }
            if ((int) $class['grade_level'] !== $grade) {
                return back()->withInput()
                    ->with('error', 'Every selected class must match the quiz grade level.');
            }

            $existingResult = $this->supabase->adminSelectResult('quiz_sessions', 'id,status', [
                'class_id' => $classId,
                'source_quiz_id' => $sourceQuiz['id'],
            ]);
            if ($existingResult['error'] !== null) {
                return back()->withInput()->with(
                    'error',
                    $this->assignmentFailureMessage($existingResult['error'], true)
                );
            }
            $alreadyAssigned = $existingResult['data'];
            $alreadyAssigned = array_filter(
                $alreadyAssigned,
                fn (array $session): bool => in_array(
                    $session['status'] ?? 'waiting',
                    ['waiting', 'active'],
                    true
                )
            );
            if (!empty($alreadyAssigned)) {
                $existingAssignments[$classId] = array_values($alreadyAssigned)[0];
            } else {
                $classesToAssign[] = $class;
            }
            $orderedClasses[] = $class;
        }

        $schedule = $this->assignmentSchedule($assignment);
        $sessionQuestions = $this->sessionQuestionsFromValidated($validated['questions'], $grade);

        $createdSessionIds = [];
        try {
            foreach ($existingAssignments as $existingAssignment) {
                $existingQuestionResult = $this->supabase->adminSelectResult('questions', 'id', [
                    'session_id' => $existingAssignment['id'],
                ]);
                if ($existingQuestionResult['error'] !== null) {
                    throw new \RuntimeException($existingQuestionResult['error']);
                }
                if (empty($existingQuestionResult['data'])) {
                    $questionResult = $this->saveSessionQuestions(
                        $existingAssignment['id'],
                        $sessionQuestions
                    );
                    if (!$questionResult['success']) {
                        throw new \RuntimeException(
                            $questionResult['error'] ?? 'An existing class assignment could not save its questions.'
                        );
                    }
                }
            }

            if (!empty($classesToAssign)) {
                $newClassIds = array_column($classesToAssign, 'id');
                $atomicResult = $this->supabase->adminRpcResult(
                    'assign_shared_quiz_to_classes',
                    [
                        'p_teacher_id' => $user['id'],
                        'p_source_quiz_id' => $sourceQuiz['id'],
                        'p_class_ids' => $newClassIds,
                        'p_topic' => trim($validated['topic']),
                        'p_grade_level' => $grade,
                        'p_time_limit' => (int) $assignment['time_limit'],
                        'p_available_at' => $schedule['available_at'],
                        'p_due_at' => $schedule['due_at'],
                        'p_questions' => $sessionQuestions,
                    ]
                );

                if ($atomicResult['error'] === null) {
                    $createdSessionIds = array_values(array_filter(array_column(
                        $atomicResult['data'],
                        'session_id'
                    )));
                    if (count($atomicResult['data']) !== count($classesToAssign)
                        || count($createdSessionIds) !== count($classesToAssign)) {
                        throw new \RuntimeException(
                            'The atomic assignment returned an unexpected number of class sessions.'
                        );
                    }
                } else {
                    // Older databases may not expose the atomic function to
                    // PostgREST yet. The direct path uses the same required
                    // grade and schedule fields and preserves its exact error
                    // if it also fails.
                    foreach ($classesToAssign as $class) {
                        $sessionResult = $this->insertAssignmentSession([
                            'teacher_id' => $user['id'],
                            'source_quiz_id' => $sourceQuiz['id'],
                            'topic' => trim($validated['topic']),
                            'room_code' => $this->generateRoomCode(),
                            'class_id' => $class['id'],
                            'grade_level' => $grade,
                            'max_members' => 60,
                            'time_limit' => (int) $assignment['time_limit'],
                            'assigned_at' => now()->toIso8601String(),
                            'available_at' => $schedule['available_at'],
                            'due_at' => $schedule['due_at'],
                            'started_at' => $schedule['started_at'],
                            'is_active' => $schedule['is_active'],
                            'status' => $schedule['status'],
                        ]);

                        $sessionId = $sessionResult['data'][0]['id'] ?? null;
                        if (!$sessionId) {
                            throw new \RuntimeException(
                                $sessionResult['error']
                                    ?? $atomicResult['error']
                                    ?? 'The database returned no reason.'
                            );
                        }
                        $createdSessionIds[] = $sessionId;

                        $questionResult = $this->saveSessionQuestions($sessionId, $sessionQuestions);
                        if (!$questionResult['success']) {
                            throw new \RuntimeException(
                                $questionResult['error']
                                    ?? 'A class assignment could not save its questions.'
                            );
                        }
                    }
                }
            }
        } catch (\Throwable $exception) {
            $this->rollbackSessions($createdSessionIds);

            return back()->withInput()->with(
                'error',
                $this->assignmentFailureMessage($exception->getMessage(), true)
            );
        }

        $classCount = count($orderedClasses);
        $classLabel = $classCount === 1 ? '1 class' : "{$classCount} classes";
        $newAssignmentCount = count($createdSessionIds);
        $existingAssignmentCount = count($existingAssignments);

        $this->refreshQuizUsageCount($sourceQuiz['id']);

        $this->supabase->audit($user, 'quiz.shared_assigned', 'quiz', $sourceQuiz['id'], [
            'class_ids' => $classIds,
            'class_count' => $classCount,
            'new_assignment_count' => $newAssignmentCount,
            'existing_assignment_count' => $existingAssignmentCount,
            'topic' => trim($validated['topic']),
        ]);

        $destination = $this->sharedAssignmentDestination($orderedClasses);

        if ($newAssignmentCount === 0) {
            return redirect($destination)->with(
                'success',
                "This quiz was already assigned to the selected {$classLabel}. Opening "
                    . ($classCount === 1 ? 'the classroom.' : 'your Classrooms section.')
            );
        }

        return redirect($destination)
            ->with('success', "Quiz assigned to {$classLabel} using the original Grade {$grade} level. The shared original and classes were not changed.");
    }

    public function store(Request $request)
    {
        $validated = $this->validateQuiz($request);
        $user = session('supabase_user');
        $token = SupabaseAccessToken::from(request());

        $created = $this->supabase->insert('quizzes', [
            'teacher_id' => $user['id'],
            'topic' => trim($validated['topic']),
            'grade_level' => (int) $validated['grade_level'],
            'visibility' => $validated['visibility'],
            'updated_at' => now()->toIso8601String(),
        ], $token);

        $quizId = $created[0]['id'] ?? null;
        if (!$quizId) {
            return redirect('/teacher/quizzes')
                ->with('error', 'The quiz could not be created. Run the latest database update first.');
        }

        if (!$this->saveTemplateQuestions(
            $quizId,
            $validated['questions'],
            (int) $validated['grade_level'],
            $token
        )) {
            $this->supabase->delete('quizzes', [
                'id' => $quizId,
                'teacher_id' => $user['id'],
            ], $token);

            return redirect('/teacher/quizzes')
                ->with('error', 'The questions could not be saved. Please try again.');
        }

        $message = $validated['visibility'] === 'shared'
            ? 'Quiz created and shared with other teachers.'
            : 'Private quiz created.';

        return redirect('/teacher/quizzes')->with('success', $message);
    }

    public function update(Request $request, string $id)
    {
        $user = session('supabase_user');
        $token = SupabaseAccessToken::from(request());
        $quiz = $this->ownedQuiz($id, $user['id']);

        if (!$quiz) {
            return redirect('/teacher/quizzes')->with('error', 'You can only edit quizzes you created.');
        }

        $validated = $this->validateQuiz($request);
        $oldQuestions = $this->supabase->adminSelect(
            'quiz_questions',
            '*',
            ['quiz_id' => $id, 'order' => 'position.asc']
        );

        $currentVersion = max(1, (int) ($quiz['version'] ?? 1));
        $snapshot = $this->preserveVersionSnapshot(
            $quiz,
            $oldQuestions,
            $currentVersion,
            $user['id'],
            $token
        );

        if (!$snapshot) {
            return redirect('/teacher/quizzes')->with('error', 'The current quiz version could not be preserved.');
        }

        $updated = $this->supabase->update('quizzes', [
            'topic' => trim($validated['topic']),
            'grade_level' => (int) $validated['grade_level'],
            'visibility' => $validated['visibility'],
            'version' => $currentVersion + 1,
            'verified_at' => null,
            'verified_by' => null,
            'updated_at' => now()->toIso8601String(),
        ], [
            'id' => $id,
            'teacher_id' => $user['id'],
        ], $token);

        if (!isset($updated[0]['id'])) {
            if ($snapshot['created']) {
                $this->supabase->adminDelete('quiz_versions', [
                    'id' => $snapshot['row']['id'],
                    'quiz_id' => $id,
                    'created_by' => $user['id'],
                ]);
            }
            return redirect('/teacher/quizzes')->with('error', 'The quiz could not be updated.');
        }

        $this->supabase->delete('quiz_questions', ['quiz_id' => $id], $token);
        $saved = $this->saveTemplateQuestions(
            $id,
            $validated['questions'],
            (int) $validated['grade_level'],
            $token
        );

        if (!$saved) {
            $this->supabase->update('quizzes', [
                'topic' => $quiz['topic'],
                'grade_level' => $quiz['grade_level'],
                'visibility' => $quiz['visibility'] ?? 'shared',
                'version' => $currentVersion,
                'verified_at' => $quiz['verified_at'] ?? null,
                'verified_by' => $quiz['verified_by'] ?? null,
                'updated_at' => $quiz['updated_at'] ?? $quiz['created_at'],
            ], [
                'id' => $id,
                'teacher_id' => $user['id'],
            ], $token);
            $this->supabase->delete('quiz_questions', ['quiz_id' => $id], $token);
            $this->restoreTemplateQuestions($oldQuestions, $token);
            if ($snapshot['created']) {
                $this->supabase->adminDelete('quiz_versions', [
                    'id' => $snapshot['row']['id'],
                    'quiz_id' => $id,
                    'created_by' => $user['id'],
                ]);
            }

            return redirect('/teacher/quizzes')
                ->with('error', 'The new questions could not be saved, so the previous quiz was restored.');
        }

        $this->supabase->audit($user, 'quiz.edited', 'quiz', $id, [
            'topic_before' => $quiz['topic'] ?? null,
            'topic_after' => trim($validated['topic']),
            'version_before' => $currentVersion,
            'version_after' => $currentVersion + 1,
        ]);

        return redirect("/teacher/quizzes/{$id}/versions")
            ->with('success', 'Quiz updated successfully.');
    }

    public function restoreVersion(string $id, int $version)
    {
        $user = session('supabase_user');
        $quiz = $this->ownedQuiz($id, $user['id']);
        if (!$quiz) {
            return redirect('/teacher/quizzes')->with('error', 'Quiz not found.');
        }

        $rpc = $this->supabase->adminRpcResult('restore_quiz_version', [
            'p_quiz_id' => $id,
            'p_version' => $version,
            'p_actor_id' => $user['id'],
        ]);
        $restored = $rpc['data'][0] ?? [];
        if (!isset($restored['quiz_id'])) {
            $error = $rpc['error'] ?? null;
            return redirect("/teacher/quizzes/{$id}/versions")
                ->with('error', $this->restoreFailureMessage($error));
        }

        $this->supabase->audit($user, 'quiz.version_restored', 'quiz', $id, [
            'restored_version' => $version,
            'discarded_after_version' => $version,
        ]);

        return redirect("/teacher/quizzes/{$id}/versions")
            ->with('success', "Version {$version} restored. Later versions were removed.");
    }

    public function destroy(string $id)
    {
        $user = session('supabase_user');
        $quiz = $this->ownedQuiz($id, $user['id']);
        if (!$quiz) {
            return redirect('/teacher/quizzes')->with('error', 'You can only delete quizzes you created.');
        }

        $result = $this->supabase->adminRpcResult('set_recovery_item', [
            'p_actor_id' => $user['id'], 'p_kind' => 'quiz', 'p_id' => $id, 'p_restore' => false,
        ]);
        if ($result['error'] !== null || ($result['data'][0]['id'] ?? null) !== $id) {
            return redirect('/teacher/quizzes')->with('error', 'The quiz could not be moved to Trash. Check the latest database update.');
        }

        return redirect('/teacher/trash')
            ->with('success', 'Quiz moved to Trash. Questions, versions, assignments and results are preserved.');
    }

    public function show(string $id)
    {
        $user = session('supabase_user');
        $quiz = $this->ownedQuiz($id, $user['id']);

        if (!$quiz) {
            return response()->json(['message' => 'Quiz not found.'], 404);
        }

        $questions = $this->supabase->adminSelect(
            'quiz_questions',
            '*',
            ['quiz_id' => $id, 'order' => 'position.asc']
        );

        return response()->json(compact('quiz', 'questions'));
    }

    public function assign(Request $request, string $id)
    {
        $validated = $request->validate([
            'class_id' => 'required|uuid',
            'time_limit' => 'required|integer|between:5,300',
            'available_at' => 'nullable|date',
            'due_at' => 'nullable|date',
        ]);

        $user = session('supabase_user');
        $quiz = $this->ownedQuiz($id, $user['id']);
        $class = $this->supabase->adminSelect('classes', '*', [
            'id' => $validated['class_id'],
            'teacher_id' => $user['id'],
        ])[0] ?? null;

        if (!$quiz || !$class) {
            return back()->with('error', 'The selected quiz or class is not available.');
        }

        if (!empty($class['archived_at'])) {
            return back()->with('error', 'Archived classes cannot receive new quiz assignments.');
        }

        if ((int) $quiz['grade_level'] !== (int) $class['grade_level']) {
            return back()->with('error', 'A quiz can only be assigned to a class with the same grade level.');
        }

        $alreadyOpen = $this->supabase->adminSelect('quiz_sessions', 'id,status', [
            'class_id' => $class['id'],
            'source_quiz_id' => $quiz['id'],
        ]);
        $alreadyOpen = array_filter(
            $alreadyOpen,
            fn ($session) => in_array($session['status'] ?? 'waiting', ['waiting', 'active'], true)
        );

        if (!empty($alreadyOpen)) {
            return back()->with('error', 'This quiz is already assigned or active in that class.');
        }

        $templateQuestions = $this->supabase->adminSelect(
            'quiz_questions',
            '*',
            ['quiz_id' => $quiz['id'], 'order' => 'position.asc']
        );

        if (empty($templateQuestions)) {
            return back()->with('error', 'This quiz has no questions and cannot be assigned.');
        }

        $sessionResult = $this->supabase->adminInsertResult('quiz_sessions', [
            'teacher_id' => $user['id'],
            'source_quiz_id' => $quiz['id'],
            'topic' => $quiz['topic'],
            'room_code' => $this->generateRoomCode(),
            'class_id' => $class['id'],
            'grade_level' => (int) $quiz['grade_level'],
            'max_members' => 60,
            'time_limit' => (int) $validated['time_limit'],
            'assigned_at' => now()->toIso8601String(),
            ...$this->assignmentSchedule($validated),
        ]);

        $sessionId = $sessionResult['data'][0]['id'] ?? null;
        if (!$sessionId) {
            return back()->with(
                'error',
                $this->assignmentFailureMessage($sessionResult['error'] ?? null)
            );
        }

        $questionResult = $this->saveSessionQuestions($sessionId, $templateQuestions);
        if (!$questionResult['success']) {
            $this->rollbackSessions([$sessionId]);
            $this->refreshQuizUsageCount($quiz['id']);

            return back()->with(
                'error',
                $this->assignmentFailureMessage($questionResult['error'] ?? null)
            );
        }

        // Class Uses measures adoption by other teachers through the shared
        // library. Assigning the creator's own quiz must not change it.
        $this->refreshQuizUsageCount($quiz['id']);

        return redirect("/teacher/classes/{$class['id']}")
            ->with('success', "Quiz assigned to {$class['class_name']}. Its VR code is ready, and the class grade was not changed.");
    }

    private function validateQuiz(Request $request): array
    {
        return $request->validate([
            'topic' => 'required|string|max:150',
            'grade_level' => 'required|integer|between:1,6',
            'visibility' => 'required|in:private,shared',
            'questions' => 'required|array|min:1|max:100',
            'questions.*.question' => 'required|string|max:1000',
            'questions.*.options' => 'required|array|size:4',
            'questions.*.options.*' => 'required|string|max:500',
            'questions.*.correct' => 'required|integer|between:0,3',
        ]);
    }

    private function validateSharedAssignmentQuiz(Request $request): array
    {
        return $request->validate([
            'topic' => 'required|string|max:150',
            'questions' => 'required|array|min:1|max:100',
            'questions.*.question' => 'required|string|max:1000',
            'questions.*.options' => 'required|array|size:4',
            'questions.*.options.*' => 'required|string|max:500',
            'questions.*.correct' => 'required|integer|between:0,3',
        ]);
    }

    private function quizFilters(Request $request): array
    {
        $search = trim(mb_substr((string) $request->query('search', ''), 0, 80));
        $grade = (int) $request->query('grade', 0);
        $grade = ($grade >= 1 && $grade <= 6) ? $grade : null;
        $safeSearch = $this->safeSearchTerm($search);

        return [$search, $grade, $safeSearch];
    }

    private function saveTemplateQuestions(
        string $quizId,
        array $questions,
        int $grade,
        string $token
    ): bool {
        $rows = [];
        foreach (array_values($questions) as $index => $question) {
            $options = array_values($question['options']);
            $rows[] = [
                'quiz_id' => $quizId,
                'position' => $index + 1,
                'grade' => $grade,
                'type' => 'multiple_choice',
                'question' => trim($question['question']),
                'choice1' => trim($options[0]),
                'choice2' => trim($options[1]),
                'choice3' => trim($options[2]),
                'choice4' => trim($options[3]),
                'choice5' => '',
                'choice6' => '',
                'correct_answer' => (string) ((int) $question['correct']),
            ];
        }

        $saved = $this->supabase->insert('quiz_questions', $rows, $token);

        return count($saved) === count($rows);
    }

    private function restoreTemplateQuestions(array $questions, string $token): void
    {
        $rows = array_map(function (array $question): array {
            unset($question['id']);
            return $question;
        }, $questions);

        if (!empty($rows)) {
            $this->supabase->insert('quiz_questions', $rows, $token);
        }
    }

    private function preserveVersionSnapshot(
        array $quiz,
        array $questions,
        int $version,
        string $userId,
        string $token
    ): ?array {
        $existing = $this->supabase->adminSelect('quiz_versions', '*', [
            'quiz_id' => $quiz['id'],
            'version' => $version,
        ])[0] ?? null;
        if ($existing) {
            return ['row' => $existing, 'created' => false];
        }

        $created = $this->supabase->insert('quiz_versions', [
            'quiz_id' => $quiz['id'],
            'version' => $version,
            'topic' => $quiz['topic'],
            'grade_level' => (int) $quiz['grade_level'],
            'visibility' => $quiz['visibility'] ?? 'shared',
            'questions' => $questions,
            'created_by' => $userId,
        ], $token)[0] ?? null;

        return $created ? ['row' => $created, 'created' => true] : null;
    }

    private function questionsForForm(array $questions): array
    {
        return array_map(function (array $question): array {
            $options = [
                $question['choice1'],
                $question['choice2'],
                $question['choice3'],
                $question['choice4'],
            ];
            $correctIndex = filter_var($question['correct_answer'], FILTER_VALIDATE_INT);
            if ($correctIndex === false || $correctIndex < 0 || $correctIndex > 3) {
                $correctIndex = array_search($question['correct_answer'], $options, true);
                $correctIndex = $correctIndex === false ? 0 : $correctIndex;
            }

            return [
                'question' => $question['question'],
                'options' => $options,
                'correct' => $correctIndex,
            ];
        }, $questions);
    }

    private function sessionQuestionsFromValidated(array $questions, int $grade): array
    {
        return array_map(function (array $question) use ($grade): array {
            $options = array_values($question['options']);

            return [
                'grade' => $grade,
                'type' => 'multiple_choice',
                'question' => trim($question['question']),
                'choice1' => trim($options[0]),
                'choice2' => trim($options[1]),
                'choice3' => trim($options[2]),
                'choice4' => trim($options[3]),
                'choice5' => '',
                'choice6' => '',
                'correct_answer' => (string) ((int) $question['correct']),
            ];
        }, array_values($questions));
    }

    private function rollbackSessions(array $sessionIds): void
    {
        foreach (array_reverse($sessionIds) as $sessionId) {
            // The user's current schema has non-cascading foreign keys, so all
            // possible children must be removed before the session itself.
            $this->supabase->adminDelete('quiz_results', ['session_id' => $sessionId]);
            $this->supabase->adminDelete('quiz_participants', ['session_id' => $sessionId]);
            $this->supabase->adminDelete('quiz_session_students', ['session_id' => $sessionId]);
            $this->supabase->adminDelete('questions', ['session_id' => $sessionId]);
            $this->supabase->adminDelete('quiz_sessions', ['id' => $sessionId]);
        }
    }

    private function insertAssignmentSession(array $payload): array
    {
        $result = $this->supabase->adminInsertResult('quiz_sessions', $payload);
        if (!empty($result['data'])) {
            return $result;
        }

        // grade_level is NOT NULL in the deployed schema. Retrying without it
        // only hides a stale PostgREST schema cache error behind a second,
        // misleading not-null failure.
        return $result;
    }

    private function refreshQuizUsageCount(string $quizId): void
    {
        RecalculateQuizUsageCount::dispatch($quizId)
            ->onConnection('deferred');
    }

    private function sharedAssignmentDestination(array $classes): string
    {
        return count($classes) === 1
            ? "/teacher/classes/{$classes[0]['id']}"
            : '/teacher/dashboard?section=classes';
    }

    private function saveSessionQuestions(string $sessionId, array $questions): array
    {
        $rows = array_map(fn (array $question): array => [
            'session_id' => $sessionId,
            'grade' => (int) $question['grade'],
            'type' => $question['type'] ?? 'multiple_choice',
            'question' => $question['question'],
            'choice1' => $question['choice1'],
            'choice2' => $question['choice2'],
            'choice3' => $question['choice3'],
            'choice4' => $question['choice4'],
            'choice5' => $question['choice5'] ?? '',
            'choice6' => $question['choice6'] ?? '',
            'correct_answer' => (string) $question['correct_answer'],
        ], $questions);

        $result = $this->supabase->adminInsertResult('questions', $rows);

        return [
            'success' => count($result['data']) === count($rows),
            'error' => $result['error'],
        ];
    }

    private function ownedQuiz(string $id, string $teacherId): ?array
    {
        return $this->supabase->adminSelect('quizzes', '*', [
            'id' => $id,
            'teacher_id' => $teacherId,
        ])[0] ?? null;
    }

    private function sharedQuiz(string $id, string $viewerId): ?array
    {
        $quiz = $this->supabase->adminSelect('quizzes', '*', [
            'id' => $id, 'visibility' => 'shared',
        ])[0] ?? null;

        return $quiz && ($quiz['teacher_id'] ?? null) !== $viewerId ? $quiz : null;
    }

    private function libraryPreferences(string $userId, array $quizIds): array
    {
        $quizIds = array_values(array_unique(array_filter($quizIds)));
        if (empty($quizIds)) {
            return ['bookmarks' => [], 'ratings' => []];
        }

        $filter = ['operator' => 'in', 'value' => '(' . implode(',', $quizIds) . ')'];
        $bookmarks = $this->supabase->adminSelect('quiz_bookmarks', 'quiz_id', [
            'user_id' => $userId, 'quiz_id' => $filter,
        ]);
        $ratings = $this->supabase->adminSelect('quiz_ratings', 'quiz_id,rating', [
            'user_id' => $userId, 'quiz_id' => $filter,
        ]);

        return [
            'bookmarks' => array_fill_keys(array_column($bookmarks, 'quiz_id'), true),
            'ratings' => array_column($ratings, 'rating', 'quiz_id'),
        ];
    }

    private function teacherClasses(string $teacherId): array
    {
        return $this->supabase->adminSelect(
            'classes',
            'id,class_name,grade_level,archived_at',
            [
                'teacher_id' => $teacherId,
                'archived_at' => ['operator' => 'is', 'value' => 'null'],
                'order' => 'class_name.asc',
            ]
        );
    }

    private function preferredClassId(Request $request, array $classes): ?string
    {
        $requested = (string) $request->query('class_id', '');
        foreach ($classes as $class) {
            if ($class['id'] === $requested) {
                return $requested;
            }
        }

        return null;
    }

    private function creatorNames(array $teacherIds): array
    {
        $teacherIds = array_values(array_unique(array_filter($teacherIds)));
        if (empty($teacherIds)) {
            return [];
        }

        $profiles = $this->supabase->adminSelect(
            'profiles',
            'id,first_name,last_name',
            ['id' => ['operator' => 'in', 'value' => '(' . implode(',', $teacherIds) . ')']]
        );

        $names = [];
        foreach ($profiles as $profile) {
            $names[$profile['id']] = trim(
                ($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')
            ) ?: 'MathVerse Teacher';
        }

        return $names;
    }

    private function questionCounts(array $quizIds): array
    {
        $quizIds = array_values(array_unique(array_filter($quizIds)));
        if (empty($quizIds)) {
            return [];
        }

        $questions = $this->supabase->adminSelect(
            'quiz_questions',
            'quiz_id',
            ['quiz_id' => ['operator' => 'in', 'value' => '(' . implode(',', $quizIds) . ')']]
        );

        $counts = [];
        foreach ($questions as $question) {
            $quizId = $question['quiz_id'];
            $counts[$quizId] = ($counts[$quizId] ?? 0) + 1;
        }

        return $counts;
    }

    private function generateRoomCode(): string
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $code = (string) random_int(1000, 9999);
            $open = $this->supabase->adminSelect('quiz_sessions', 'id', [
                'room_code' => $code,
                'is_active' => true,
            ]);

            if (empty($open)) {
                return $code;
            }
        }

        throw new \RuntimeException('Unable to generate an available VR room code.');
    }

    private function assignmentSchedule(array $validated): array
    {
        $availableAt = !empty($validated['available_at'])
            ? \Carbon\Carbon::parse($validated['available_at'], config('app.timezone'))->utc()
            : null;
        $dueAt = !empty($validated['due_at'])
            ? \Carbon\Carbon::parse($validated['due_at'], config('app.timezone'))->utc()
            : null;

        $now = now()->utc();
        if ($dueAt !== null && $dueAt->lessThanOrEqualTo($now)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'due_at' => 'The due date must be in the future.',
            ]);
        }
        if ($dueAt !== null && $availableAt !== null && $dueAt->lessThanOrEqualTo($availableAt)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'due_at' => 'The due date must be later than the start date.',
            ]);
        }

        $startsNow = $availableAt !== null && $availableAt->lessThanOrEqualTo($now);

        return [
            'available_at' => $availableAt?->toIso8601String(),
            'due_at' => $dueAt?->toIso8601String(),
            'started_at' => $startsNow ? $availableAt->toIso8601String() : null,
            'status' => $startsNow ? 'active' : 'waiting',
            'is_active' => true,
        ];
    }

    private function assignmentFailureMessage(?string $error, bool $multipleClasses = false): string
    {
        $message = trim(preg_replace('/\s+/', ' ', (string) $error));
        $prefix = $multipleClasses
            ? 'No classes were assigned. '
            : 'The quiz was not assigned. ';

        if ($message === '') {
            return $prefix . 'The database returned no failure reason. Please try again.';
        }

        $lower = strtolower($message);
        if (str_contains($lower, 'duplicate key')
            || str_contains($lower, 'quiz_sessions_one_open_quiz_per_class')) {
            return $prefix . 'This quiz already has an assigned or active session in the selected class.';
        }
        if (str_contains($lower, 'does not match class grade')) {
            return $prefix . 'The quiz and class grade levels do not match.';
        }
        if (str_contains($lower, 'assign_shared_quiz_to_classes')
            || str_contains($lower, 'could not find the function')) {
            return $prefix . 'Run the latest shared-assignment database update, then try again.';
        }
        if (str_contains($lower, 'schema cache')
            || (str_contains($lower, 'column') && str_contains($lower, 'does not exist'))) {
            return $prefix . 'The assignment database update is incomplete. Run the latest database update, then try again.';
        }

        return $prefix . 'Please try again. If the problem continues, contact an administrator.';
    }

    private function restoreFailureMessage(?string $error): string
    {
        $message = trim(preg_replace('/\s+/', ' ', (string) $error));
        if ($message === '') {
            return 'The selected version could not be restored. Please try again.';
        }
        if (str_contains(strtolower($message), 'schema cache')
            || str_contains(strtolower($message), 'could not find the function')
            || str_contains(strtolower($message), 'restore_quiz_version')) {
            return 'Version restoration is unavailable. Run the standalone quiz database update, then try again.';
        }

        return 'The selected version could not be restored. Please try again.';
    }
}
