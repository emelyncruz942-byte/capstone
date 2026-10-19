function initializeAdminDashboard() {
    if (document.getElementById('dashboard-content')?.dataset.dashboardRole !== 'admin') return;
    if (!document.getElementById('sec-overview')) return;

    const requested = new URLSearchParams(window.location.search).get('section') ?? 'overview';
    const normalized = requested === 'password' ? 'security' : requested;
    const allowed = ['overview', 'stats', 'students', 'teachers', 'role-verify', 'audit', 'reports', 'profile', 'security'];
    showSection(allowed.includes(normalized) ? normalized : 'overview');

    if (typeof applyChartDefaults === 'function' && typeof Chart !== 'undefined') {
        applyChartDefaults();
    }

    if (requested === 'stats' && typeof loadAdminStats === 'function') {
        requestAnimationFrame(() => requestAnimationFrame(() => loadAdminStats({ foreground: false })));
    }
}

onMathVerseReady(initializeAdminDashboard);
document.addEventListener('mathverse:section-change', event => {
    if (event.detail?.section === 'stats'
        && document.getElementById('dashboard-content')?.dataset.dashboardRole === 'admin'
        && typeof loadAdminStats === 'function') {
        requestAnimationFrame(() => requestAnimationFrame(() => loadAdminStats({ foreground: false })));
    }
});

function confirmDelete(id, name) {
    document.getElementById('deleteUserForm').action = `/admin/user/${id}`;
    document.getElementById('delete-user-name').textContent = name || 'Selected user';
    openModal('deleteUserModal');
}

function confirmReleaseDelete(id, version) {
    const form = document.getElementById('releaseDeleteForm');
    const versionLabel = document.getElementById('release-delete-version');
    if (!form || !versionLabel
        || !/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(id || '')) return;

    form.action = `/admin/game-releases/${encodeURIComponent(id)}`;
    versionLabel.textContent = version ? `Version ${version}` : 'Selected game version';
    openModal('releaseDeleteModal');
}

function confirmSuspend(id, name, section) {
    document.getElementById('suspendUserForm').action = `/admin/user/${id}/suspend`;
    document.getElementById('suspend-user-name').textContent = name || 'Selected user';
    document.getElementById('suspend-return-section').value = section;
    document.getElementById('suspension-reason').value = '';
    openModal('suspendUserModal');
}

function openReportDeleteModal(quizId, topic, reportId) {
    const form = document.getElementById('reportDeleteQuizForm');
    if (!form) return;
    form.action = `/admin/quizzes/${quizId}`;
    document.getElementById('report-delete-topic').textContent = `“${topic}” will be removed from the shared library.`;
    document.getElementById('report-delete-report-id').value = reportId;
    openModal('reportDeleteQuizModal');
}
