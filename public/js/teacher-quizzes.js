let editingQuizId = null;
let questionIndex = 0;

function getQuizBasePath() {
    const explicitPath = document.getElementById('quiz-form')?.dataset.quizBasePath;
    if (explicitPath) return explicitPath;
    return window.location.pathname.startsWith('/admin/') ? '/admin/quizzes' : '/teacher/quizzes';
}

function configureQuizEditMode(form, quizId) {
    editingQuizId = quizId;
    form.action = `${getQuizBasePath()}/${quizId}`;
    document.getElementById('method-field').innerHTML = `
        <input type="hidden" name="_method" value="PUT">
        <input type="hidden" name="editing_quiz_id" value="${escapeAttribute(quizId)}">`;
    document.getElementById('builder-title').innerHTML = 'Edit <span class="text-purple-400">Quiz</span>';
    document.getElementById('save-quiz-btn').innerHTML = '<i class="fas fa-check-circle mr-2"></i> Update Quiz';
}

async function loadQuizBuilder(quizId = null) {
    const form = document.getElementById('quiz-form');
    if (!form) return;
    form.dataset.mathverseDirty = 'true';

    editingQuizId = quizId;
    questionIndex = 0;

    const method = document.getElementById('method-field');
    const title = document.getElementById('builder-title');
    const saveButton = document.getElementById('save-quiz-btn');
    const builder = document.getElementById('questions-builder');
    builder.innerHTML = '';

    if (quizId) {
        configureQuizEditMode(form, quizId);

        try {
            const response = await fetch(`${getQuizBasePath()}/${quizId}`, {
                cache: 'no-store',
                headers: { 'Accept': 'application/json' },
            });
            if (!response.ok) throw new Error('Quiz could not be loaded.');
            const data = await response.json();
            if (!form.isConnected
                || document.getElementById('quiz-form') !== form
                || editingQuizId !== quizId) return;

            document.getElementById('q-topic').value = data.quiz.topic ?? '';
            document.getElementById('q-grade').value = String(data.quiz.grade_level ?? 1);
            document.getElementById('q-visibility').value = data.quiz.visibility ?? 'shared';

            (data.questions ?? []).forEach((question) => {
                const options = [question.choice1, question.choice2, question.choice3, question.choice4];
                let correctIndex = Number.parseInt(question.correct_answer, 10);
                if (!Number.isInteger(correctIndex) || correctIndex < 0 || correctIndex > 3) {
                    correctIndex = options.findIndex(option => option === question.correct_answer);
                    if (correctIndex < 0) correctIndex = 0;
                }
                addQuestionBlock(question.question, options, correctIndex);
            });

            if (!builder.children.length) addNewQuestion();
        } catch (error) {
            if (form.isConnected && editingQuizId === quizId) showToast(error.message, true);
            return;
        }
    } else {
        form.action = getQuizBasePath();
        method.innerHTML = '';
        title.innerHTML = 'Create <span class="text-purple-400">Quiz</span>';
        saveButton.innerHTML = '<i class="fas fa-save mr-2"></i> Save Quiz';
        document.getElementById('q-topic').value = '';
        document.getElementById('q-grade').value = '1';
        document.getElementById('q-visibility').value = 'shared';
        addNewQuestion();
    }

    toggleQuizView('editor');
}

function toggleQuizView(view) {
    const list = document.getElementById('quiz-list-container');
    const editor = document.getElementById('quiz-editor-container');
    if (!list || !editor) return;

    if (view === 'editor') {
        list.classList.add('hidden');
        editor.classList.remove('hidden');
        void editor.offsetWidth;
        editor.classList.add('animate-fade-in');
    } else {
        editor.classList.add('hidden');
        list.classList.remove('hidden');
    }
}

function addNewQuestion() {
    addQuestionBlock('', ['', '', '', ''], 0);
}

function resetQuestionBuilder() {
    questionIndex = 0;
    document.getElementById('questions-builder')?.replaceChildren();
}

function hydrateQuestionBuilder() {
    const stateElement = document.querySelector('[data-quiz-question-state]');
    const builder = document.getElementById('questions-builder');
    if (!stateElement || !builder || builder.dataset.questionStateReady === 'true') return false;

    let questions = [];
    try {
        const serialized = stateElement instanceof HTMLTemplateElement
            ? stateElement.content.textContent
            : stateElement.textContent;
        const parsed = JSON.parse(serialized);
        questions = Array.isArray(parsed) ? parsed : [];
    } catch {
        showToast('MathVerse could not load these quiz questions.', true);
    }

    resetQuestionBuilder();
    questions.forEach(question => {
        addQuestionBlock(
            question.question ?? '',
            Array.isArray(question.options) ? question.options : ['', '', '', ''],
            Number.parseInt(question.correct, 10) || 0
        );
    });
    if (!builder.children.length) addNewQuestion();
    builder.dataset.questionStateReady = 'true';

    return true;
}

function addQuestionBlock(text, options, correctIndex) {
    const container = document.getElementById('questions-builder');
    if (!container) return;

    const index = questionIndex++;
    const number = container.children.length + 1;
    const block = document.createElement('div');
    block.className = 'p-6 bg-black/40 border border-white/5 rounded relative question-block';
    block.innerHTML = `
        <button type="button" data-action="removeQuestion" data-action-self
                class="absolute top-4 right-4 text-slate-600 hover:text-red-500 transition-all"
                aria-label="Remove question">
            <i class="fas fa-trash"></i>
        </button>
        <div class="mb-4 pr-8">
            <label class="input-label opacity-60 question-number">Question ${number}</label>
            <div class="relative">
                <i class="fas fa-pen-fancy input-icon"></i>
                <input type="text" name="questions[${index}][question]"
                       value="${escapeAttribute(text)}" maxlength="1000"
                       placeholder="Enter question..." class="input-mobile-ultra" required>
            </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
            ${[0, 1, 2, 3].map(optionIndex => `
                <div class="relative">
                    <i class="fas fa-circle input-icon !text-[8px]"></i>
                    <input type="text" name="questions[${index}][options][]"
                           value="${escapeAttribute(options[optionIndex] ?? '')}" maxlength="500"
                           placeholder="Option ${optionIndex + 1}" class="input-mobile-ultra" required>
                </div>
            `).join('')}
        </div>
        <div class="form-group">
            <label class="input-label text-green-400">Correct Answer</label>
            <select name="questions[${index}][correct]"
                    class="input-mobile-ultra !pl-4 bg-slate-900 border-green-500/30 text-green-400">
                ${[0, 1, 2, 3].map(optionIndex => `
                    <option value="${optionIndex}" ${correctIndex === optionIndex ? 'selected' : ''}>
                        Option ${optionIndex + 1}
                    </option>
                `).join('')}
            </select>
        </div>`;
    container.appendChild(block);
}

function removeQuestion(button) {
    const container = document.getElementById('questions-builder');
    if (container.children.length === 1) {
        showToast('A quiz must have at least one question.');
        return;
    }

    button.closest('.question-block').remove();
    container.querySelectorAll('.question-number').forEach((label, index) => {
        label.textContent = `Question ${index + 1}`;
    });
}

function openAssignQuiz(quizId, topic, grade) {
    const modal = document.getElementById('assignQuizModal');
    const form = document.getElementById('assignQuizForm');
    const select = document.getElementById('assign-class-select');
    if (!modal || !form || !select) return;

    form.action = `/teacher/quizzes/${quizId}/assign`;
    document.getElementById('assign-quiz-topic').textContent = topic;
    document.getElementById('assign-quiz-grade').textContent = grade;

    let matchingClasses = 0;
    [...select.options].forEach((option, index) => {
        if (index === 0) return;
        const matches = Number(option.dataset.grade) === Number(grade);
        option.hidden = !matches;
        option.disabled = !matches;
        if (matches) matchingClasses++;
    });

    select.value = '';
    const preferredClass = modal.dataset.defaultClass;
    const preferredOption = [...select.options].find(option =>
        option.value === preferredClass && !option.disabled
    );
    if (preferredOption) select.value = preferredClass;

    const noClass = document.getElementById('assign-no-class');
    const submit = document.getElementById('assign-quiz-submit');
    noClass.classList.toggle('hidden', matchingClasses > 0);
    submit.disabled = matchingClasses === 0;
    submit.classList.toggle('opacity-40', matchingClasses === 0);
    submit.classList.toggle('cursor-not-allowed', matchingClasses === 0);

    openModal('assignQuizModal');
}

function openDeleteQuizModal(quizId, topic) {
    document.getElementById('deleteQuizForm').action = `${getQuizBasePath()}/${quizId}`;
    document.getElementById('delete-quiz-topic').textContent = topic;
    openModal('deleteQuizModal');
}

function openRestoreQuizVersion(version, topic) {
    const form = document.getElementById('restoreQuizVersionForm');
    if (!form) return;
    const basePath = form.dataset.restoreBase
        || window.location.pathname.replace(/\/$/, '');
    form.action = `${basePath}/${version}/restore`;
    document.getElementById('restore-version-summary').textContent = `Restore version ${version} of “${topic}”?`;
    openModal('restoreQuizVersionModal');
}

function escapeAttribute(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;');
}

function initializeQuizTools() {
    const hydrated = hydrateQuestionBuilder();
    const form = document.getElementById('quiz-form');
    if (form?.dataset.autoOpenBuilder === 'true') {
        form.dataset.mathverseDirty = 'true';
        const previousEditId = form.dataset.editingQuizId || '';
        if (/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(previousEditId)) {
            configureQuizEditMode(form, previousEditId);
        }
        if (!hydrated && !document.getElementById('questions-builder')?.children.length) addNewQuestion();
        toggleQuizView('editor');
    }
}

onMathVerseReady(initializeQuizTools);
document.addEventListener('mathverse:before-navigate', () => {
    editingQuizId = null;
    questionIndex = 0;
});
