<?php

namespace App\Http\Controllers;

use App\Services\NumberGuessGameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class NumberGuessGameController extends Controller
{
    public function __construct(private NumberGuessGameService $game) {}

    public function index(): View
    {
        $user = session('supabase_user');
        $gameState = $this->game->dashboard($user);
        $activePage = 'games';

        return view('student.games.number-guess', compact('user', 'gameState', 'activePage'));
    }

    public function start(): JsonResponse
    {
        try {
            return response()->json($this->game->start(session('supabase_user')));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function guess(Request $request, string $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'guess' => 'required|integer|between:1,2000000000',
            'expected_guesses' => 'required|integer|between:0,1000000',
        ]);

        try {
            return response()->json($this->game->guess(
                session('supabase_user'),
                $sessionId,
                (int) $validated['guess'],
                (int) $validated['expected_guesses']
            ));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function finish(string $sessionId): JsonResponse
    {
        try {
            return response()->json($this->game->finish(
                session('supabase_user'),
                $sessionId
            ));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function leaderboard(): JsonResponse
    {
        try {
            return response()->json($this->game->leaderboard(session('supabase_user')));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
