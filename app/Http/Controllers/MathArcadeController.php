<?php

namespace App\Http\Controllers;

use App\Services\MathArcadeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class MathArcadeController extends Controller
{
    public function __construct(private MathArcadeService $arcade) {}

    public function index(): View
    {
        $user = session('supabase_user');
        $arcade = $this->arcade->hub($user);
        $activePage = 'games';

        return view('student.games.index', compact('user', 'arcade', 'activePage'));
    }

    public function show(string $gameKey): View
    {
        $user = session('supabase_user');
        $gameState = $this->arcade->game($user, $gameKey);
        $activePage = 'games';

        return view('student.games.play', compact('user', 'gameState', 'activePage'));
    }

    public function start(string $gameKey): JsonResponse
    {
        try {
            return response()->json($this->arcade->start(session('supabase_user'), $gameKey));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function answer(Request $request, string $gameKey, string $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'sequence' => 'required|integer|between:1,1000001',
            'answer' => 'required|string|max:40',
        ]);

        try {
            return response()->json($this->arcade->answer(
                session('supabase_user'),
                $gameKey,
                $sessionId,
                (int) $validated['sequence'],
                trim($validated['answer'])
            ));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function finish(string $gameKey, string $sessionId): JsonResponse
    {
        try {
            return response()->json($this->arcade->finish(
                session('supabase_user'),
                $gameKey,
                $sessionId
            ));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function leaderboard(string $gameKey): JsonResponse
    {
        try {
            return response()->json($this->arcade->leaderboard(session('supabase_user'), $gameKey));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
