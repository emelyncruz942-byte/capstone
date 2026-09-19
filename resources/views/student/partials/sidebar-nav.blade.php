@php
    $activePage = $activePage ?? request()->query('section', 'stats');
@endphp

<a href="/student/learning-hub" id="btn-learning"
   class="nav-link w-full {{ in_array($activePage, ['learning', 'practice'], true) ? 'active' : '' }}">
    <i class="fas fa-rocket mr-3 w-5 text-purple-400"></i> Learning Hub
</a>
<a href="/student/games" id="btn-games"
   class="nav-link w-full {{ $activePage === 'games' ? 'active' : '' }}">
    <i class="fas fa-gamepad mr-3 w-5 text-pink-400"></i> Math Games
</a>
<a href="/student/dashboard?section=stats" id="btn-stats"
   class="nav-link w-full {{ $activePage === 'stats' ? 'active' : '' }}">
    <i class="fas fa-chart-line mr-3 w-5 text-cyan-400"></i> My Stats
</a>
<a href="/student/dashboard?section=ranking" id="btn-ranking"
   class="nav-link w-full {{ $activePage === 'ranking' ? 'active' : '' }}">
    <i class="fas fa-trophy mr-3 w-5 text-yellow-500"></i> Ranking
</a>
<a href="/student/dashboard?section=class" id="btn-class"
   class="nav-link w-full {{ $activePage === 'class' ? 'active' : '' }}">
    <i class="fas fa-chalkboard mr-3 w-5 text-green-400"></i> My Classes
</a>
