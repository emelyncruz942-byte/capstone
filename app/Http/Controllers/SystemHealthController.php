<?php

namespace App\Http\Controllers;

use App\Services\SystemHealthService;
use Illuminate\View\View;

class SystemHealthController extends Controller
{
    public function __construct(private SystemHealthService $health) {}

    public function index(): View
    {
        $user = session('supabase_user');
        $health = $this->health->snapshot();
        $activePage = 'system-health';
        return view('admin.system-health', compact('user', 'health', 'activePage'));
    }
}
