function initializeTeacherDashboard() {
    if (document.getElementById('dashboard-content')?.dataset.dashboardRole !== 'teacher') return;
    if (!document.getElementById('sec-overview')) return;

    const requested = new URLSearchParams(window.location.search).get('section') ?? 'overview';
    const normalized = requested === 'password' ? 'security' : requested;
    const section = ['overview', 'classes', 'stats', 'profile', 'security'].includes(normalized)
        ? normalized
        : 'overview';

    showSection(section);

    if (typeof applyChartDefaults === 'function' && typeof Chart !== 'undefined') {
        applyChartDefaults();
    }

    if (section === 'stats' && typeof loadTeacherStats === 'function') {
        requestAnimationFrame(() => requestAnimationFrame(() => loadTeacherStats()));
    }
}

onMathVerseReady(initializeTeacherDashboard);
document.addEventListener('mathverse:section-change', event => {
    if (event.detail?.section === 'stats'
        && document.getElementById('dashboard-content')?.dataset.dashboardRole === 'teacher'
        && typeof loadTeacherStats === 'function') {
        requestAnimationFrame(() => requestAnimationFrame(() => loadTeacherStats()));
    }
});
