let lobbyTimer = null;
let currentLobbyUrl = null;
let pendingQuizAction = null;
let currentResultsContext = null;
let pendingStudentException = null;

function openLobby(classId, sessionId, topic, code) {
    currentLobbyUrl = `/teacher/classes/${classId}/quizzes/${sessionId}/lobby`;
    document.getElementById('lobby-title').textContent = `${topic} - Lobby`;
    document.getElementById('lobby-code').textContent = code;
    openModal('liveLobbyModal');
    fetchLobby();
    clearInterval(lobbyTimer);
    lobbyTimer = setInterval(fetchLobby, 3000);
}

async function fetchLobby() {
    if (!currentLobbyUrl) return;
    const body = document.getElementById('lobby-tbody');

    try {
        const response = await fetch(currentLobbyUrl, {
            cache: 'no-store',
            headers: { 'Accept': 'application/json' },
        });
        if (!response.ok) throw new Error('The lobby could not be loaded.');
        const participants = await response.json();

        if (!participants.length) {
            body.innerHTML = '<tr><td class="p-8 text-center text-slate-500 text-xs uppercase">Waiting for students to connect...</td></tr>';
            return;
        }

        body.innerHTML = participants.map((participant) => {
            const profile = participant.profiles ?? {};
            const name = `${profile.last_name ?? ''}, ${profile.first_name ?? 'Unknown'}`;
            return `<tr class="border-b border-white/5">
                <td class="p-4"><i class="fas fa-vr-cardboard text-purple-400 mr-3"></i>${escapeClassroomHtml(name)}</td>
                <td class="p-4 text-right text-cyan-400 font-mono text-xs">Level ${Number(profile.level ?? 1)}</td>
            </tr>`;
        }).join('');
    } catch (error) {
        body.innerHTML = `<tr><td class="p-8 text-center text-red-400 text-xs">${escapeClassroomHtml(error.message)}</td></tr>`;
    }
}

function closeLobby() {
    clearInterval(lobbyTimer);
    lobbyTimer = null;
    currentLobbyUrl = null;
    closeModal('liveLobbyModal');
}

async function openResults(classId, sessionId, topic) {
    currentResultsContext = { classId, sessionId, topic };
    document.getElementById('results-modal-title').textContent = `${topic} - Analytics`;
    const body = document.getElementById('results-tbody');
    body.innerHTML = '<tr><td colspan="6" class="py-8 text-center text-slate-500"><i class="fas fa-circle-notch fa-spin text-2xl"></i></td></tr>';
    openModal('viewResultsModal');

    try {
        const response = await fetch(`/teacher/classes/${classId}/quizzes/${sessionId}/results`, {
            cache: 'no-store',
            headers: { 'Accept': 'application/json' },
        });
        if (!response.ok) throw new Error('Quiz analytics could not be loaded.');
        const results = await response.json();

        if (!results.length) {
            body.innerHTML = '<tr><td colspan="6" class="py-8 text-center text-slate-500 text-xs uppercase">No eligible students for this assignment.</td></tr>';
            return;
        }

        body.innerHTML = results.map((assignment) => {
            const profile = assignment.profiles ?? {};
            const result = assignment.result ?? null;
            const total = Number(result?.total_questions ?? 0);
            const correct = Number(result?.correct_answers ?? 0);
            const accuracy = result && total > 0 ? Math.round((correct / total) * 100) : null;
            const passed = accuracy !== null && accuracy >= 75;
            const color = accuracy === null ? 'text-slate-500' : (passed ? 'text-green-400' : 'text-red-400');
            const name = `${profile.last_name ?? 'Unknown'}, ${profile.first_name ?? 'Unknown'}`;
            const assignmentStatus = String(assignment.assignment_status ?? 'available');
            const statusLabels = {
                completed: passed ? 'Passed' : 'Failed',
                missed: 'Missed',
                excused: 'Excused',
                available: 'Available',
            };
            const statusColors = {
                completed: passed ? 'text-green-400' : 'text-red-400',
                missed: 'text-red-400',
                excused: 'text-purple-400',
                available: 'text-yellow-400',
            };
            const actionData = `data-student-id="${escapeClassroomAttribute(assignment.student_id)}" data-student-name="${escapeClassroomAttribute(name)}"`;
            const retakeButton = assignment.can_grant_retake
                ? `<button type="button" class="quiz-student-action text-cyan-400 hover:text-white text-[9px] uppercase font-bold" data-action="retake" ${actionData}>Grant Retake</button>`
                : '';
            const excuseButton = !result && assignmentStatus !== 'excused'
                ? `<button type="button" class="quiz-student-action text-purple-400 hover:text-white text-[9px] uppercase font-bold ml-3" data-action="excuse" ${actionData}>Excuse</button>`
                : '';

            return `<tr class="border-b border-white/5">
                <td class="py-4 font-bold">${escapeClassroomHtml(name)}</td>
                <td class="py-4 font-bold ${statusColors[assignmentStatus] ?? 'text-slate-400'}">${escapeClassroomHtml(statusLabels[assignmentStatus] ?? assignmentStatus)}</td>
                <td class="py-4 text-cyan-400 font-mono">${result ? `${correct} / ${total}` : '—'}</td>
                <td class="py-4 font-bold ${color}">${accuracy === null ? '—' : `${accuracy}%`}</td>
                <td class="py-4 text-slate-400">${Number(assignment.attempts_used ?? 0)} / ${Number(assignment.allowed_attempts ?? 0)}</td>
                <td class="py-4 text-right whitespace-nowrap">
                    ${retakeButton}
                    ${excuseButton}
                </td>
            </tr>`;
        }).join('');
    } catch (error) {
        body.innerHTML = `<tr><td colspan="6" class="py-8 text-center text-red-400 text-xs">${escapeClassroomHtml(error.message)}</td></tr>`;
    }
}

function initializeClassroomResultActions() {
    const resultsBody = document.getElementById('results-tbody');
    if (!resultsBody || resultsBody.dataset.classroomReady === 'true') return;
    resultsBody.dataset.classroomReady = 'true';
    resultsBody.addEventListener('click', (event) => {
        const button = event.target.closest('.quiz-student-action');
        if (!button || !currentResultsContext) return;
        openStudentException(
            button.dataset.action,
            currentResultsContext.classId,
            currentResultsContext.sessionId,
            button.dataset.studentId,
            button.dataset.studentName,
        );
    });
}

function openStudentException(action, classId, sessionId, studentId, studentName) {
    pendingStudentException = { action, classId, sessionId, studentId };
    const isExcuse = action === 'excuse';
    document.getElementById('exception-modal-title').textContent = isExcuse ? 'Mark as Excused' : 'Grant Retake';
    document.getElementById('exception-student-name').textContent = studentName;
    document.getElementById('exception-due-wrapper').classList.toggle('hidden', isExcuse);
    document.getElementById('exception-due-at').disabled = isExcuse;
    document.getElementById('exception-reason').value = '';
    document.getElementById('exception-due-at').value = '';
    syncTemporalInputTone(document.getElementById('exception-due-at'));
    document.getElementById('confirmStudentException').textContent = isExcuse ? 'Mark Excused' : 'Grant Retake';
    openModal('quizStudentExceptionModal');
}

function initializeStudentExceptionForm() {
    const form = document.getElementById('quizStudentExceptionForm');
    if (!form || form.dataset.classroomReady === 'true') return;
    form.dataset.classroomReady = 'true';
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!pendingStudentException) return;

        const button = document.getElementById('confirmStudentException');
        const { action, classId, sessionId, studentId } = pendingStudentException;
        const payload = {
            reason: document.getElementById('exception-reason').value.trim(),
        };
        if (action === 'retake') {
            payload.due_at = document.getElementById('exception-due-at').value || null;
        }
        button.disabled = true;
        button.classList.add('opacity-50');

        try {
            const response = await fetch(`/teacher/classes/${classId}/quizzes/${sessionId}/students/${studentId}/${action}`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken(),
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message ?? 'The exception could not be saved.');
            document.dispatchEvent(new CustomEvent('mathverse:data-changed'));
            closeModal('quizStudentExceptionModal');
            showToast(data.message ?? 'Student exception saved.', data.email_sent === false);
            await openResults(classId, sessionId, currentResultsContext?.topic ?? 'Quiz');
        } catch (error) {
            showToast(error.message, true);
        } finally {
            button.disabled = false;
            button.classList.remove('opacity-50');
        }
    });
}

function openQuizAction(classId, sessionId, action, topic) {
    pendingQuizAction = { classId, sessionId, action };
    const isEnd = action === 'end';
    const icon = document.getElementById('quiz-action-icon');
    const button = document.getElementById('confirmQuizAction');

    document.getElementById('quiz-action-title').textContent = isEnd ? 'End Quiz?' : 'Start Quiz?';
    document.getElementById('quiz-action-topic').textContent = topic;
    icon.className = `fas ${isEnd ? 'fa-stop text-red-400' : 'fa-play text-green-400'} text-4xl mb-4`;
    button.textContent = isEnd ? 'End Quiz' : 'Start Quiz';
    button.classList.toggle('!bg-red-600', isEnd);
    button.classList.toggle('!text-white', isEnd);
    openModal('quizActionModal');
}

function initializeQuizActionButton() {
    const button = document.getElementById('confirmQuizAction');
    if (!button || button.dataset.classroomReady === 'true') return;
    button.dataset.classroomReady = 'true';
    button.addEventListener('click', async () => {
        if (!pendingQuizAction) return;
        const { classId, sessionId, action } = pendingQuizAction;
        button.disabled = true;
        button.classList.add('opacity-50');

        try {
            const response = await fetch(`/teacher/classes/${classId}/quizzes/${sessionId}/${action}`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken(),
                    'Accept': 'application/json',
                },
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message ?? 'The quiz status could not be changed.');
            document.dispatchEvent(new CustomEvent('mathverse:data-changed'));
            closeModal('quizActionModal');
            showToast(action === 'end' ? 'Quiz ended.' : 'Quiz started.');
            if (window.MathVerseNavigation) {
                await window.MathVerseNavigation.refresh();
            } else {
                window.location.assign(window.location.href);
            }
        } catch (error) {
            showToast(error.message, true);
            button.disabled = false;
            button.classList.remove('opacity-50');
            closeModal('quizActionModal');
        }
    });
}

function openSessionReport(sessionId, topic) {
    document.getElementById('quiz-report-topic').textContent = topic;
    document.getElementById('quiz-report-pdf').href = `/teacher/report/quiz/${sessionId}?format=pdf`;
    document.getElementById('quiz-report-csv').href = `/teacher/report/quiz/${sessionId}?format=csv`;
    openModal('quizReportModal');
}

function openRemoveStudent(classId, studentId, name) {
    document.getElementById('removeStudentForm').action = `/teacher/classes/${classId}/students/${studentId}`;
    document.getElementById('remove-student-name').textContent = name;
    openModal('removeStudentModal');
}

function openAssignmentSettings(classId, sessionId, topic, timeLimit, startAt, dueAt, isActive) {
    document.getElementById('assignmentSettingsForm').action = `/teacher/classes/${classId}/quizzes/${sessionId}`;
    document.getElementById('assignment-settings-topic').textContent = topic;
    document.getElementById('assignment-time-limit').value = Number(timeLimit ?? 20);
    const startInput = document.getElementById('assignment-start-at');
    const dueInput = document.getElementById('assignment-due-at');
    startInput.value = startAt ?? '';
    dueInput.value = dueAt ?? '';
    syncTemporalInputTone(startInput);
    syncTemporalInputTone(dueInput);
    document.getElementById('assignment-start-tip').textContent = isActive
        ? 'This quiz is already active. Its start date cannot be moved into the future.'
        : 'If set, the assignment starts automatically. If blank, start it manually.';
    openModal('assignmentSettingsModal');
}

function openDeleteAssignment(classId, sessionId, topic) {
    document.getElementById('deleteAssignmentForm').action = `/teacher/classes/${classId}/quizzes/${sessionId}`;
    document.getElementById('delete-assignment-topic').textContent = topic;
    openModal('deleteAssignmentModal');
}

function escapeClassroomHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function escapeClassroomAttribute(value) {
    return escapeClassroomHtml(value).replaceAll('`', '&#096;');
}

function initializeTeacherClassroom() {
    initializeClassroomResultActions();
    initializeStudentExceptionForm();
    initializeQuizActionButton();
}

onMathVerseReady(initializeTeacherClassroom);
document.addEventListener('mathverse:before-navigate', () => {
    clearInterval(lobbyTimer);
    lobbyTimer = null;
    currentLobbyUrl = null;
    currentResultsContext = null;
    pendingStudentException = null;
    pendingQuizAction = null;
});
