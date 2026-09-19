@php
    $activePage = $activePage ?? match (request()->query('section', 'overview')) {
        'classes' => 'classes',
        'stats' => 'analytics',
        default => 'dashboard',
    };
@endphp

<a href="/teacher/dashboard"
   id="btn-overview"
   class="nav-link w-full {{ $activePage === 'dashboard' ? 'active' : '' }}">
    <i class="fas fa-satellite-dish mr-3 w-5 text-cyan-400"></i> Dashboard
</a>
<a href="/teacher/quizzes"
   class="nav-link w-full {{ $activePage === 'quizzes' ? 'active' : '' }}">
    <i class="fas fa-vr-cardboard mr-3 w-5 text-purple-400"></i> VR Quiz Bees
</a>
<a href="/teacher/quiz-library"
   class="nav-link w-full {{ $activePage === 'library' ? 'active' : '' }}">
    <i class="fas fa-book-open mr-3 w-5 text-blue-400"></i> Quiz Library
</a>
<a href="/teacher/dashboard?section=classes"
   id="btn-classes"
   class="nav-link w-full {{ $activePage === 'classes' ? 'active' : '' }}">
    <i class="fas fa-chalkboard mr-3 w-5 text-yellow-400"></i> Classrooms
</a>
<a href="/teacher/dashboard?section=stats"
   id="btn-stats"
   class="nav-link w-full {{ $activePage === 'analytics' ? 'active' : '' }}">
    <i class="fas fa-chart-bar mr-3 w-5 text-pink-400"></i> Analytics
</a>
<a href="/teacher/learning-hub"
   class="nav-link w-full {{ $activePage === 'learning-analytics' ? 'active' : '' }}">
    <i class="fas fa-rocket mr-3 w-5 text-green-400"></i> Learning Hub Insights
</a>
<a href="/teacher/trash" class="nav-link w-full {{ $activePage === 'trash' ? 'active' : '' }}"><i class="fas fa-trash-restore mr-3 w-5 text-amber-400"></i> Trash and Recovery</a>
