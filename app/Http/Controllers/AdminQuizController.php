<?php

namespace App\Http\Controllers;

use App\Services\SupabaseService;
use Illuminate\Http\Request;

class AdminQuizController extends Controller
{
    public function __construct(private SupabaseService $supabase) {}

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

        $quizzes = $this->supabase->adminSelect('quizzes', '*', $filters);
        $questionCounts = $this->questionCounts(array_column($quizzes, 'id'));

        foreach ($quizzes as &$quiz) {
            $quiz['question_count'] = $questionCounts[$quiz['id']] ?? 0;
        }
        unset($quiz);

        return view('admin.quizzes.index', compact('user', 'quizzes', 'search', 'grade'));
    }

    public function library(Request $request)
    {
        $user = session('supabase_user');
        [$search, $grade, $safeSearch] = $this->quizFilters($request);
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

        $secondaryOrder = 'usage_count.desc,rating_average.desc,created_at.desc';
        if ($verifiedOnly) {
            $filters['order'] = $secondaryOrder;
            $result = $this->supabase->adminSelectPage(
                'quizzes',
                '*',
                $filters,
                $perPage,
                ($page - 1) * $perPage
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
        $quizzes = $result['data'];
        $total = $result['total'];
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages && $total > 0) {
            return redirect()->to($request->fullUrlWithQuery(['page' => $totalPages]));
        }

        $creatorNames = $this->creatorNames(array_column($quizzes, 'teacher_id'));
        $questionCounts = $this->questionCounts(array_column($quizzes, 'id'));
        $quizzesByGrade = array_fill(1, 6, []);

        foreach ($quizzes as &$quiz) {
            $quiz['creator_name'] = $creatorNames[$quiz['teacher_id']] ?? 'MathVerse User';
            $quiz['question_count'] = $questionCounts[$quiz['id']] ?? 0;
            $quizzesByGrade[(int) $quiz['grade_level']][] = $quiz;
        }
        unset($quiz);

        return view('admin.quizzes.library', compact(
            'user', 'quizzesByGrade', 'search', 'grade', 'page', 'total', 'totalPages',
            'verifiedOnly'
        ));
    }

    public function reports(Request $request)
    {
        $user = session('supabase_user');
        $status = (string) $request->query('status', 'active');
        if (!in_array($status, ['active', 'reviewed', 'dismissed'], true)) {
            $status = 'active';
        }

        $databaseStatus = $status === 'active' ? 'pending' : $status;
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 20;
        $result = $this->supabase->adminSelectPage(
            'quiz_reports',
            '*',
            [
                'status' => $databaseStatus,
                'order' => $status === 'active'
                    ? 'created_at.asc'
                    : 'reviewed_at.desc,created_at.desc',
            ],
            $perPage,
            ($page - 1) * $perPage
        );

        $reports = $this->enrichReports($result['data']);
        $total = $result['total'];
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages && $total > 0) {
            return redirect()->to($request->fullUrlWithQuery(['page' => $totalPages]));
        }

        $reportCounts = [
            'active' => $this->supabase->adminCount('quiz_reports', ['status' => 'pending']),
            'reviewed' => $this->supabase->adminCount('quiz_reports', ['status' => 'reviewed']),
            'dismissed' => $this->supabase->adminCount('quiz_reports', ['status' => 'dismissed']),
        ];

        return view('admin.quiz-reports.index', compact(
            'user', 'reports', 'status', 'page', 'total', 'totalPages', 'reportCounts'
        ));
    }

    public function showReport(string $reportId)
    {
        $user = session('supabase_user');
        $report = $this->supabase->adminSelect(
            'quiz_reports', '*', ['id' => $reportId]
        )[0] ?? null;
        if (!$report) {
            return redirect('/admin/quiz-reports')
                ->with('error', 'That quiz report no longer exists.');
        }

        $report = $this->enrichReports([$report])[0];
        $nextReport = $this->nextPendingReport($reportId);

        return view('admin.quiz-reports.show', compact('user', 'report', 'nextReport'));
    }

    public function store(Request $request)
    {
        $validated = $this->validateQuiz($request);
        $user = session('supabase_user');

        $created = $this->supabase->adminInsert('quizzes', [
            'teacher_id' => $user['id'],
            'topic' => trim($validated['topic']),
            'grade_level' => (int) $validated['grade_level'],
            'visibility' => $validated['visibility'],
            'verified_at' => now()->toIso8601String(),
            'verified_by' => $user['id'],
            'updated_at' => now()->toIso8601String(),
        ]);

        $quizId = $created[0]['id'] ?? null;
        if (!$quizId) {
            return redirect('/admin/quizzes')->with('error', 'The quiz could not be created.');
        }

        if (!$this->saveTemplateQuestions(
            $quizId,
            $validated['questions'],
            (int) $validated['grade_level']
        )) {
            $this->supabase->adminDelete('quizzes', ['id' => $quizId]);
            return redirect('/admin/quizzes')->with('error', 'The quiz questions could not be saved.');
        }

        $message = $validated['visibility'] === 'shared'
            ? 'Admin quiz created and shared with teachers.'
            : 'Private admin quiz created.';

        return redirect('/admin/quizzes')->with('success', $message);
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

    public function update(Request $request, string $id)
    {
        $user = session('supabase_user');
        $quiz = $this->ownedQuiz($id, $user['id']);

        if (!$quiz) {
            return redirect($this->quizDestination($request))
                ->with('error', 'You can only edit quizzes you created.');
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
            $user['id']
        );
        if (!$snapshot) {
            return back()->withInput()
                ->with('error', 'The current quiz version could not be preserved.');
        }

        $updated = $this->supabase->adminUpdate('quizzes', [
            'topic' => trim($validated['topic']),
            'grade_level' => (int) $validated['grade_level'],
            'visibility' => $validated['visibility'],
            'version' => $currentVersion + 1,
            'verified_at' => now()->toIso8601String(),
            'verified_by' => $user['id'],
            'updated_at' => now()->toIso8601String(),
        ], ['id' => $id, 'teacher_id' => $user['id']]);

        if (!isset($updated[0]['id'])) {
            if ($snapshot['created']) {
                $this->supabase->adminDelete('quiz_versions', ['id' => $snapshot['row']['id']]);
            }
            return back()->withInput()->with('error', 'The quiz could not be updated.');
        }

        $this->supabase->adminDelete('quiz_questions', ['quiz_id' => $id]);
        if (!$this->saveTemplateQuestions(
            $id,
            $validated['questions'],
            (int) $validated['grade_level']
        )) {
            $this->supabase->adminUpdate('quizzes', [
                'topic' => $quiz['topic'],
                'grade_level' => $quiz['grade_level'],
                'visibility' => $quiz['visibility'] ?? 'shared',
                'version' => $currentVersion,
                'verified_at' => $quiz['verified_at'] ?? null,
                'verified_by' => $quiz['verified_by'] ?? null,
                'updated_at' => $quiz['updated_at'] ?? $quiz['created_at'],
            ], ['id' => $id, 'teacher_id' => $user['id']]);
            $this->supabase->adminDelete('quiz_questions', ['quiz_id' => $id]);
            $this->restoreTemplateQuestions($oldQuestions);
            if ($snapshot['created']) {
                $this->supabase->adminDelete('quiz_versions', ['id' => $snapshot['row']['id']]);
            }

            return back()->withInput()
                ->with('error', 'The new questions could not be saved, so the previous quiz was restored.');
        }

        $this->supabase->audit($user, 'quiz.edited', 'quiz', $id, [
            'creator_id' => $quiz['teacher_id'] ?? null,
            'topic_before' => $quiz['topic'] ?? null,
            'topic_after' => trim($validated['topic']),
            'version_before' => $currentVersion,
            'version_after' => $currentVersion + 1,
        ]);

        if ($reportId = $this->reportContextId($request)) {
            return $this->afterReportedQuizUpdate($id, $reportId, $user);
        }

        return redirect("/admin/quizzes/{$id}/versions")
            ->with('success', 'Quiz updated. Its previous version is available below; existing class assignments were not changed.');
    }

    public function review(Request $request, string $id)
    {
        $user = session('supabase_user');
        $quiz = $this->supabase->adminSelect('quizzes', '*', ['id' => $id])[0] ?? null;

        if (!$quiz) {
            return redirect('/admin/quiz-library')->with('error', 'Quiz not found.');
        }

        $isOwnQuiz = ($quiz['teacher_id'] ?? null) === $user['id'];
        if (!$isOwnQuiz && ($quiz['visibility'] ?? 'shared') !== 'shared') {
            return redirect('/admin/quiz-library')->with('error', 'Quiz not found.');
        }

        $questionRows = $this->supabase->adminSelect(
            'quiz_questions',
            '*',
            ['quiz_id' => $id, 'order' => 'position.asc']
        );
        $questions = $this->reviewQuestions($questionRows);
        $questionsForForm = $this->questionsForForm($questionRows);
        $creatorNames = $this->creatorNames([$quiz['teacher_id']]);
        $creatorName = $creatorNames[$quiz['teacher_id']] ?? 'MathVerse User';

        $reportContext = null;
        if ($reportId = $this->reportContextId($request)) {
            $contextRow = $this->supabase->adminSelect('quiz_reports', '*', [
                'id' => $reportId,
                'quiz_id' => $id,
            ])[0] ?? null;
            if ($contextRow) {
                $reportContext = $this->enrichReports([$contextRow])[0];
            }
        }

        return view('admin.quizzes.review', compact(
            'user', 'quiz', 'questions', 'questionsForForm', 'creatorName',
            'isOwnQuiz', 'reportContext'
        ));
    }

    public function updateReviewed(Request $request, string $id)
    {
        $admin = session('supabase_user');
        $quiz = $this->supabase->adminSelect('quizzes', '*', [
            'id' => $id,
            'visibility' => 'shared',
        ])[0] ?? null;

        if (!$quiz || ($quiz['teacher_id'] ?? null) === ($admin['id'] ?? null)) {
            return redirect($this->quizDestination($request))
                ->with('error', 'That teacher quiz is no longer available for review.');
        }

        $validated = $this->validateQuiz($request);
        $oldQuestions = $this->supabase->adminSelect(
            'quiz_questions', '*', ['quiz_id' => $id, 'order' => 'position.asc']
        );
        $currentVersion = max(1, (int) ($quiz['version'] ?? 1));
        $snapshot = $this->preserveVersionSnapshot(
            $quiz,
            $oldQuestions,
            $currentVersion,
            $admin['id']
        );
        if (!$snapshot) {
            return back()->withInput()->with('error', 'The current quiz version could not be preserved.');
        }

        $updated = $this->supabase->adminUpdate('quizzes', [
            'topic' => trim($validated['topic']),
            'grade_level' => (int) $validated['grade_level'],
            'visibility' => $validated['visibility'],
            'version' => $currentVersion + 1,
            'verified_at' => null,
            'verified_by' => null,
            'updated_at' => now()->toIso8601String(),
        ], ['id' => $id]);
        if (!isset($updated[0]['id'])) {
            if ($snapshot['created']) {
                $this->supabase->adminDelete('quiz_versions', ['id' => $snapshot['row']['id']]);
            }
            return back()->withInput()->with('error', 'The teacher quiz could not be updated.');
        }

        $this->supabase->adminDelete('quiz_questions', ['quiz_id' => $id]);
        if (!$this->saveTemplateQuestions(
            $id,
            $validated['questions'],
            (int) $validated['grade_level']
        )) {
            $this->supabase->adminUpdate('quizzes', [
                'topic' => $quiz['topic'],
                'grade_level' => $quiz['grade_level'],
                'visibility' => $quiz['visibility'] ?? 'shared',
                'version' => $currentVersion,
                'verified_at' => $quiz['verified_at'] ?? null,
                'verified_by' => $quiz['verified_by'] ?? null,
                'updated_at' => $quiz['updated_at'] ?? $quiz['created_at'],
            ], ['id' => $id]);
            $this->supabase->adminDelete('quiz_questions', ['quiz_id' => $id]);
            $this->restoreTemplateQuestions($oldQuestions);
            if ($snapshot['created']) {
                $this->supabase->adminDelete('quiz_versions', ['id' => $snapshot['row']['id']]);
            }

            return back()->withInput()
                ->with('error', 'The new questions could not be saved, so the previous quiz was restored.');
        }

        $this->supabase->audit($admin, 'quiz.edited', 'quiz', $id, [
            'creator_id' => $quiz['teacher_id'] ?? null,
            'topic_before' => $quiz['topic'] ?? null,
            'topic_after' => trim($validated['topic']),
            'version_before' => $currentVersion,
            'version_after' => $currentVersion + 1,
            'admin_review_edit' => true,
        ]);

        if ($reportId = $this->reportContextId($request)) {
            return $this->afterReportedQuizUpdate($id, $reportId, $admin);
        }

        return redirect("/admin/quizzes/{$id}/versions")
            ->with('success', 'Teacher quiz updated. Its previous version is available below; existing class assignments were not changed.');
    }

    public function toggleVerified(string $id)
    {
        $admin = session('supabase_user');
        $quiz = $this->supabase->adminSelect('quizzes', '*', [
            'id' => $id, 'visibility' => 'shared',
        ])[0] ?? null;
        if (!$quiz) {
            return back()->with('error', 'Only a shared quiz can be verified.');
        }

        $creator = $this->supabase->adminSelect('profiles', 'role', [
            'id' => $quiz['teacher_id'],
        ])[0] ?? null;
        if (($creator['role'] ?? '') === 'admin' && !empty($quiz['verified_at'])) {
            return back()->with('error', 'Admin-created quizzes are always verified.');
        }

        $verify = empty($quiz['verified_at']);
        $updated = $this->supabase->adminUpdate('quizzes', [
            'verified_at' => $verify ? now()->toIso8601String() : null,
            'verified_by' => $verify ? $admin['id'] : null,
        ], ['id' => $id]);
        if (!isset($updated[0]['id'])) {
            return back()->with('error', 'The verification status could not be changed.');
        }

        $this->supabase->audit(
            $admin,
            $verify ? 'quiz.verified' : 'quiz.verification_removed',
            'quiz',
            $id,
            ['topic' => $quiz['topic'] ?? null]
        );

        return back()->with('success', $verify ? 'Quiz marked as verified.' : 'Verification removed.');
    }

    public function resolveReport(Request $request, string $reportId)
    {
        $validated = $request->validate(['status' => 'required|in:reviewed,dismissed']);
        $admin = session('supabase_user');
        if (!$this->completeReport(null, $reportId, $validated['status'], $admin)) {
            return back()->with('error', 'That report has already been handled or no longer exists.');
        }

        return redirect($this->nextPendingReportDestination())
            ->with(
                'success',
                'Quiz report marked as ' . $validated['status'] . '. The next active report is ready.'
            );
    }

    public function versions(string $id)
    {
        $user = session('supabase_user');
        $quiz = $this->supabase->adminSelect('quizzes', '*', ['id' => $id])[0] ?? null;
        $isOwnQuiz = $quiz && ($quiz['teacher_id'] ?? null) === $user['id'];
        if (!$quiz || (!$isOwnQuiz && ($quiz['visibility'] ?? '') !== 'shared')) {
            return redirect('/admin/quiz-library')->with('error', 'Quiz not found.');
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
        $creatorNames = $this->creatorNames([$quiz['teacher_id']]);
        $creatorName = $creatorNames[$quiz['teacher_id']] ?? 'MathVerse User';
        return view('admin.quizzes.versions', compact(
            'user', 'quiz', 'versions', 'isOwnQuiz', 'creatorName'
        ));
    }

    public function restoreVersion(string $id, int $version)
    {
        $admin = session('supabase_user');
        $quiz = $this->supabase->adminSelect('quizzes', '*', ['id' => $id])[0] ?? null;
        $isOwnQuiz = $quiz && ($quiz['teacher_id'] ?? null) === $admin['id'];
        if (!$quiz || (!$isOwnQuiz && ($quiz['visibility'] ?? '') !== 'shared')) {
            return redirect('/admin/quiz-library')->with('error', 'Quiz not found.');
        }

        $rpc = $this->supabase->adminRpcResult('restore_quiz_version', [
            'p_quiz_id' => $id,
            'p_version' => $version,
            'p_actor_id' => $admin['id'],
        ]);
        $restored = $rpc['data'][0] ?? [];
        if (!isset($restored['quiz_id'])) {
            $error = $rpc['error'] ?? null;
            return redirect("/admin/quizzes/{$id}/versions")
                ->with('error', $this->restoreFailureMessage($error));
        }

        $this->supabase->audit($admin, 'quiz.version_restored', 'quiz', $id, [
            'creator_id' => $quiz['teacher_id'] ?? null,
            'restored_version' => $version,
            'discarded_after_version' => $version,
        ]);

        return redirect("/admin/quizzes/{$id}/versions")
            ->with('success', "Version {$version} restored. Later versions were removed.");
    }

    public function destroy(Request $request, string $id)
    {
        $destination = $this->quizDestination($request);
        $returnToReports = $request->input('return_to') === 'reports';
        $quiz = $this->supabase->adminSelect(
            'quizzes', 'id,topic,teacher_id,visibility', ['id' => $id]
        )[0] ?? null;
        if (!$quiz) {
            return redirect($destination)->with('error', 'Quiz not found.');
        }

        $admin = session('supabase_user');
        $isOwnQuiz = ($quiz['teacher_id'] ?? null) === ($admin['id'] ?? null);
        if (!$isOwnQuiz && ($quiz['visibility'] ?? 'shared') !== 'shared') {
            return redirect($destination)->with('error', 'A private quiz can only be deleted by its creator.');
        }

        $result = $this->supabase->adminRpcResult('set_recovery_item', [
            'p_actor_id' => $admin['id'], 'p_kind' => 'quiz', 'p_id' => $id, 'p_restore' => false,
        ]);
        if ($result['error'] !== null || ($result['data'][0]['id'] ?? null) !== $id) {
            return redirect($destination)->with('error', 'The quiz could not be moved to Trash. Check the latest database update.');
        }
        $message = 'Quiz moved to Trash. Questions, versions, assignments and results are preserved.';

        if ($returnToReports) {
            return redirect($this->nextPendingReportDestination())
                ->with('success', $message . ' Its active reports were reviewed.');
        }

        return redirect('/admin/trash?type=quiz')->with('success', $message);
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

    private function saveTemplateQuestions(string $quizId, array $questions, int $grade): bool
    {
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

        $saved = $this->supabase->adminInsert('quiz_questions', $rows);

        return count($saved) === count($rows);
    }

    private function restoreTemplateQuestions(array $questions): void
    {
        $rows = array_map(function (array $question): array {
            unset($question['id']);
            return $question;
        }, $questions);

        if (!empty($rows)) {
            $this->supabase->adminInsert('quiz_questions', $rows);
        }
    }

    private function preserveVersionSnapshot(
        array $quiz,
        array $questions,
        int $version,
        string $actorId
    ): ?array {
        $existing = $this->supabase->adminSelect('quiz_versions', '*', [
            'quiz_id' => $quiz['id'],
            'version' => $version,
        ])[0] ?? null;
        if ($existing) {
            return ['row' => $existing, 'created' => false];
        }

        $created = $this->supabase->adminInsert('quiz_versions', [
            'quiz_id' => $quiz['id'],
            'version' => $version,
            'topic' => $quiz['topic'],
            'grade_level' => (int) $quiz['grade_level'],
            'visibility' => $quiz['visibility'] ?? 'shared',
            'questions' => $questions,
            'created_by' => $actorId,
        ])[0] ?? null;

        return $created ? ['row' => $created, 'created' => true] : null;
    }

    private function reviewQuestions(array $questions): array
    {
        return array_map(function (array $question): array {
            $choices = [
                $question['choice1'],
                $question['choice2'],
                $question['choice3'],
                $question['choice4'],
            ];
            $correctIndex = filter_var($question['correct_answer'], FILTER_VALIDATE_INT);
            if ($correctIndex === false || $correctIndex < 0 || $correctIndex > 3) {
                $correctIndex = array_search($question['correct_answer'], $choices, true);
                $correctIndex = $correctIndex === false ? 0 : $correctIndex;
            }

            return [
                'id' => $question['id'] ?? null,
                'position' => $question['position'] ?? null,
                'question' => $question['question'],
                'choices' => $choices,
                'correct_index' => $correctIndex,
            ];
        }, $questions);
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

    private function ownedQuiz(string $id, string $adminId): ?array
    {
        return $this->supabase->adminSelect('quizzes', '*', [
            'id' => $id,
            'teacher_id' => $adminId,
        ])[0] ?? null;
    }

    private function quizFilters(Request $request): array
    {
        $search = trim(mb_substr((string) $request->query('search', ''), 0, 80));
        $grade = (int) $request->query('grade', 0);
        $grade = ($grade >= 1 && $grade <= 6) ? $grade : null;
        $safeSearch = $this->safeSearchTerm($search);

        return [$search, $grade, $safeSearch];
    }

    private function quizDestination(Request $request): string
    {
        if ($request->input('return_to') === 'reports') {
            $reportId = $this->reportContextId($request);
            return $reportId ? "/admin/quiz-reports/{$reportId}" : '/admin/quiz-reports';
        }

        $base = $request->input('return_to') === 'library'
            ? '/admin/quiz-library'
            : '/admin/quizzes';
        $search = trim(mb_substr((string) $request->input('search', ''), 0, 80));
        $grade = (int) $request->input('grade', 0);
        $page = max(1, (int) $request->input('page', 1));
        $verified = $request->boolean('verified');
        $query = array_filter([
            'search' => $search,
            'grade' => ($grade >= 1 && $grade <= 6) ? $grade : null,
            'verified' => $verified ? 1 : null,
            'page' => $page > 1 ? $page : null,
        ], fn ($value) => $value !== null && $value !== '');

        return $base . ($query === [] ? '' : '?' . http_build_query($query));
    }

    private function creatorNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) {
            return [];
        }

        $profiles = $this->supabase->adminSelect(
            'profiles',
            'id,first_name,last_name,role',
            ['id' => ['operator' => 'in', 'value' => '(' . implode(',', $ids) . ')']]
        );

        $names = [];
        foreach ($profiles as $profile) {
            $name = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')) ?: 'MathVerse User';
            $names[$profile['id']] = ($profile['role'] ?? '') === 'admin' ? "{$name} (Admin)" : $name;
        }

        return $names;
    }

    private function enrichReports(array $reports): array
    {
        if ($reports === []) {
            return [];
        }

        $quizIds = array_values(array_unique(array_filter(array_column($reports, 'quiz_id'))));
        $quizzes = $quizIds === [] ? [] : $this->supabase->adminSelect(
            'quizzes',
            '*',
            ['id' => ['operator' => 'in', 'value' => '(' . implode(',', $quizIds) . ')']]
        );
        $quizMap = array_column($quizzes, null, 'id');

        $questionIds = array_values(array_unique(array_filter(array_column($reports, 'question_id'))));
        $questions = $questionIds === [] ? [] : $this->supabase->adminSelect(
            'quiz_questions',
            'id,position,question',
            ['id' => ['operator' => 'in', 'value' => '(' . implode(',', $questionIds) . ')']]
        );
        $questionMap = array_column($questions, null, 'id');

        $profileIds = array_merge(
            array_column($reports, 'reporter_id'),
            array_column($reports, 'reviewed_by'),
            array_column($reports, 'quiz_creator_id'),
            array_column($quizzes, 'teacher_id')
        );
        $profileNames = $this->creatorNames($profileIds);

        foreach ($reports as &$report) {
            $quiz = $quizMap[$report['quiz_id'] ?? ''] ?? null;
            $creatorId = $quiz['teacher_id'] ?? $report['quiz_creator_id'] ?? null;
            $question = $questionMap[$report['question_id'] ?? ''] ?? null;
            $questionText = $question['question'] ?? $report['question_text'] ?? null;

            $report['quiz'] = $quiz;
            $report['quiz_available'] = $quiz !== null;
            $report['quiz_topic_display'] = $quiz['topic']
                ?? $report['quiz_topic']
                ?? 'Deleted quiz';
            $report['quiz_grade_display'] = $quiz['grade_level']
                ?? $report['quiz_grade_level']
                ?? null;
            $report['quiz_creator_id_display'] = $creatorId;
            $report['quiz_creator_name'] = $profileNames[$creatorId] ?? 'Former user';
            $report['reporter_name'] = $profileNames[$report['reporter_id'] ?? ''] ?? 'Former user';
            $report['reviewer_name'] = $profileNames[$report['reviewed_by'] ?? ''] ?? null;
            $report['question_text_display'] = $questionText;
            $report['question_label'] = $questionText
                ? (!empty($question['position']) ? "Question {$question['position']}: " : '')
                    . \Illuminate\Support\Str::limit($questionText, 100)
                : null;
        }
        unset($report);

        return $reports;
    }

    private function completeReport(
        ?string $quizId,
        string $reportId,
        string $status,
        array $admin
    ): bool {
        $filters = ['id' => $reportId, 'status' => 'pending'];
        if ($quizId !== null) {
            $filters['quiz_id'] = $quizId;
        }

        $report = $this->supabase->adminSelect('quiz_reports', '*', $filters)[0] ?? null;
        if (!$report) {
            return false;
        }

        $updated = $this->supabase->adminUpdate('quiz_reports', [
            'status' => $status,
            'reviewed_by' => $admin['id'],
            'reviewed_at' => now()->toIso8601String(),
        ], $filters);
        if (!isset($updated[0]['id'])) {
            return false;
        }

        $this->supabase->audit($admin, 'quiz_report.' . $status, 'quiz_report', $reportId, [
            'quiz_id' => $report['quiz_id'] ?? $quizId,
            'quiz_topic' => $report['quiz_topic'] ?? null,
            'reason' => $report['reason'] ?? null,
        ]);

        return true;
    }

    private function nextPendingReport(?string $excludeId = null): ?array
    {
        $filters = [
            'status' => 'pending',
            'order' => 'created_at.asc',
            'limit' => 1,
        ];
        if ($excludeId !== null) {
            $filters['id'] = ['operator' => 'neq', 'value' => $excludeId];
        }

        $report = $this->supabase->adminSelect('quiz_reports', '*', $filters)[0] ?? null;
        return $report ? $this->enrichReports([$report])[0] : null;
    }

    private function nextPendingReportDestination(): string
    {
        $next = $this->nextPendingReport();
        return $next ? "/admin/quiz-reports/{$next['id']}" : '/admin/quiz-reports?status=active';
    }

    private function afterReportedQuizUpdate(string $quizId, string $reportId, array $admin)
    {
        $report = $this->supabase->adminSelect('quiz_reports', 'id,status', [
            'id' => $reportId,
            'quiz_id' => $quizId,
        ])[0] ?? null;

        if (!$report) {
            return redirect("/admin/quizzes/{$quizId}/versions")
                ->with('success', 'Quiz updated and its previous version was preserved. The report context no longer exists, so no report status was changed.');
        }

        if (($report['status'] ?? '') !== 'pending') {
            return redirect("/admin/quiz-reports/{$reportId}")
                ->with('success', 'Quiz updated. This report was already handled, so its status was not changed.');
        }

        if (!$this->completeReport($quizId, $reportId, 'reviewed', $admin)) {
            return redirect("/admin/quiz-reports/{$reportId}")
                ->with('error', 'The quiz was updated, but the report could not be marked as reviewed.');
        }

        return redirect($this->nextPendingReportDestination())
            ->with('success', 'Quiz updated and report reviewed. The next active report is ready.');
    }

    private function reportContextId(Request $request): ?string
    {
        $reportId = (string) $request->input('report_id', $request->query('report_id', ''));
        $returnTo = $request->input('return_to', $request->query('return_to'));

        if ($returnTo !== 'reports' || !\Illuminate\Support\Str::isUuid($reportId)) {
            return null;
        }

        return $reportId;
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

    private function questionCounts(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) {
            return [];
        }

        $questions = $this->supabase->adminSelect(
            'quiz_questions',
            'quiz_id',
            ['quiz_id' => ['operator' => 'in', 'value' => '(' . implode(',', $ids) . ')']]
        );

        $counts = [];
        foreach ($questions as $question) {
            $counts[$question['quiz_id']] = ($counts[$question['quiz_id']] ?? 0) + 1;
        }

        return $counts;
    }

}
