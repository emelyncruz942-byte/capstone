<?php

namespace App\Http\Controllers;

use App\Services\AdaptivePracticeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class LearningHubController extends Controller
{
    public function __construct(private AdaptivePracticeService $practice) {}

    public function index(): View
    {
        $user = session('supabase_user');
        $hub = $this->practice->dashboard($user);
        $activePage = 'learning';

        return view('student.learning-hub.index', compact('user', 'hub', 'activePage'));
    }

    public function practice(Request $request): View|RedirectResponse
    {
        $mode = $request->validate([
            'mode' => 'nullable|in:adventure,daily,review,focus',
            'topic' => 'nullable|string|max:80|regex:/^[a-z0-9-]+$/',
        ])['mode'] ?? 'adventure';
        $topic = $request->input('topic');
        $user = session('supabase_user');

        try {
            $practiceState = $this->practice->startOrResume($user, $mode, $topic);
        } catch (RuntimeException $exception) {
            return redirect('/student/learning-hub')->with('error', $exception->getMessage());
        }

        $activePage = 'learning';

        return view(
            'student.learning-hub.practice',
            compact('user', 'practiceState', 'activePage')
        );
    }

    public function nextQuestion(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'required|uuid',
        ]);

        try {
            $question = $this->practice->nextQuestion(
                session('supabase_user'),
                $validated['session_id']
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['question' => $question]);
    }

    public function revealHint(string $questionId): JsonResponse
    {
        try {
            $hint = $this->practice->revealHint(session('supabase_user'), $questionId);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($hint);
    }

    public function submitAnswer(Request $request, string $questionId): JsonResponse
    {
        $validated = $request->validate([
            'answer' => 'required|string|max:120',
            'response_ms' => 'nullable|integer|between:0,3600000',
        ]);

        try {
            $result = $this->practice->submitAnswer(
                session('supabase_user'),
                $questionId,
                trim($validated['answer']),
                isset($validated['response_ms']) ? (int) $validated['response_ms'] : null
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($result);
    }
}
