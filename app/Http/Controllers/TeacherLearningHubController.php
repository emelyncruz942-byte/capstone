<?php

namespace App\Http\Controllers;

use App\Services\TeacherLearningHubAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TeacherLearningHubController extends Controller
{
    public function __construct(private TeacherLearningHubAnalyticsService $analytics) {}

    public function index(Request $request): View
    {
        $validated = $request->validate([
            'class_id' => 'nullable|uuid',
            'days' => 'nullable|integer|in:7,30,90,180',
        ]);
        $user = session('supabase_user');
        $analytics = $this->analytics->dashboard(
            $user,
            $validated['class_id'] ?? null,
            (int) ($validated['days'] ?? 30)
        );
        $activePage = 'learning-analytics';

        return view('teacher.learning-hub.index', compact('user', 'analytics', 'activePage'));
    }
}
