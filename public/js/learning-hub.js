function initializeLearningHub() {
    const root = document.getElementById('practice-arena');
    const stateElement = document.getElementById('practice-initial-state');
    if (!root || !stateElement || root.dataset.practiceReady === 'true') return;
    root.dataset.practiceReady = 'true';

    let initialState;
    try {
        const serialized = stateElement instanceof HTMLTemplateElement
            ? stateElement.content.textContent
            : stateElement.textContent;
        initialState = JSON.parse(serialized);
    } catch {
        showToast('MathVerse could not load this practice mission.', true);
        return;
    }

    let question = initialState.question;
    let session = initialState.session;
    let selectedAnswer = '';
    let questionStartedAt = Date.now();
    let busy = false;

    const elements = {
        world: document.getElementById('practice-world'),
        icon: document.getElementById('practice-icon'),
        iconWrap: document.getElementById('practice-icon-wrap'),
        competency: document.getElementById('practice-competency'),
        difficulty: document.getElementById('practice-difficulty'),
        mission: document.getElementById('practice-mission'),
        position: document.getElementById('practice-position'),
        progress: document.getElementById('practice-progress'),
        prompt: document.getElementById('practice-prompt'),
        answerArea: document.getElementById('practice-answer-area'),
        form: document.getElementById('practice-answer-form'),
        error: document.getElementById('practice-answer-error'),
        hintButton: document.getElementById('practice-hint-button'),
        submitButton: document.getElementById('practice-submit-button'),
        nextButton: document.getElementById('practice-next-button'),
        hints: document.getElementById('practice-hints'),
        hintList: document.getElementById('practice-hint-list'),
        feedback: document.getElementById('practice-feedback'),
        feedbackIcon: document.getElementById('practice-feedback-icon'),
        feedbackTitle: document.getElementById('practice-feedback-title'),
        feedbackAnswer: document.getElementById('practice-feedback-answer'),
        feedbackExplanation: document.getElementById('practice-feedback-explanation'),
        xpReward: document.getElementById('practice-xp-reward'),
        masteryReward: document.getElementById('practice-mastery-reward'),
        comboReward: document.getElementById('practice-combo-reward'),
        checkpoint: document.getElementById('practice-checkpoint'),
        checkpointSummary: document.getElementById('practice-checkpoint-summary'),
        xp: document.getElementById('practice-xp'),
        combo: document.getElementById('practice-combo'),
        level: document.getElementById('practice-level'),
        masteryLabel: document.getElementById('practice-mastery-label'),
        masteryBar: document.getElementById('practice-mastery-bar'),
        solved: document.getElementById('practice-solved'),
        correct: document.getElementById('practice-correct'),
        breakCard: document.getElementById('practice-break-card'),
    };

    function setBusy(value) {
        busy = value;
        const answered = elements.feedback.classList.contains('practice-feedback-visible');
        root.setAttribute('aria-busy', String(value));
        elements.submitButton.disabled = value || answered;
        elements.nextButton.disabled = value;
        elements.hintButton.disabled = value || answered || !question.has_more_hints;
        [elements.submitButton, elements.nextButton, elements.hintButton].forEach(button => {
            button.classList.toggle('opacity-50', button.disabled);
            button.classList.toggle('cursor-not-allowed', button.disabled);
        });
    }

    function showAnswerError(message) {
        elements.error.textContent = message;
        elements.error.classList.remove('hidden');
    }

    function clearAnswerError() {
        elements.error.textContent = '';
        elements.error.classList.add('hidden');
    }

    function renderProgress(currentPosition, outcome = null) {
        elements.progress.replaceChildren();
        for (let position = 1; position <= 5; position++) {
            const segment = document.createElement('span');
            segment.className = 'practice-progress-segment';
            segment.setAttribute('aria-label', `Problem ${position}`);
            if (position < currentPosition) segment.classList.add('complete');
            if (position === currentPosition) segment.classList.add('current');
            if (position === currentPosition && outcome === true) segment.classList.add('correct');
            if (position === currentPosition && outcome === false) segment.classList.add('incorrect');
            elements.progress.append(segment);
        }
    }

    function buildChoiceAnswers(options) {
        const wrapper = document.createElement('div');
        wrapper.className = 'grid grid-cols-1 sm:grid-cols-3 gap-3';

        options.forEach((option, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'practice-choice';
            button.dataset.value = String(option);
            button.setAttribute('aria-pressed', 'false');

            const label = document.createElement('span');
            label.className = 'practice-choice-label';
            label.textContent = String.fromCharCode(65 + index);
            const text = document.createElement('span');
            text.textContent = String(option);
            button.append(label, text);

            button.addEventListener('click', () => {
                if (busy || elements.feedback.classList.contains('practice-feedback-visible')) return;
                selectedAnswer = String(option);
                wrapper.querySelectorAll('.practice-choice').forEach(choice => {
                    const selected = choice === button;
                    choice.classList.toggle('selected', selected);
                    choice.setAttribute('aria-pressed', String(selected));
                });
                clearAnswerError();
            });
            wrapper.append(button);
        });

        elements.answerArea.append(wrapper);
    }

    function buildNumberAnswer() {
        const label = document.createElement('label');
        label.className = 'input-label';
        label.htmlFor = 'practice-number-answer';
        label.textContent = 'Your answer';

        const input = document.createElement('input');
        input.id = 'practice-number-answer';
        input.type = 'text';
        input.inputMode = 'decimal';
        input.autocomplete = 'off';
        input.maxLength = 120;
        input.placeholder = 'Enter your answer';
        input.className = 'input-mobile-ultra !pl-4 text-lg md:text-xl text-center font-bold';
        input.addEventListener('input', () => {
            selectedAnswer = input.value.trim();
            clearAnswerError();
        });
        input.addEventListener('keydown', event => {
            if (event.key === 'Enter' && !busy) {
                event.preventDefault();
                elements.form.requestSubmit();
            }
        });

        elements.answerArea.append(label, input);
        window.setTimeout(() => input.focus(), 50);
    }

    function appendHint(text) {
        if (!text) return;
        const item = document.createElement('li');
        item.textContent = text;
        elements.hintList.append(item);
        elements.hints.classList.remove('hidden');
    }

    function renderQuestion(nextQuestion) {
        question = nextQuestion;
        session = nextQuestion.session;
        selectedAnswer = '';
        questionStartedAt = Date.now();
        clearAnswerError();

        root.style.setProperty('--practice-accent', question.color);
        elements.world.textContent = question.world;
        elements.competency.textContent = question.competency_title;
        elements.difficulty.textContent = question.difficulty;
        elements.mission.textContent = question.mission;
        elements.position.textContent = question.mission_position;
        elements.prompt.textContent = question.prompt;
        elements.icon.className = `fas ${question.icon}`;
        elements.iconWrap.style.color = question.color;
        elements.iconWrap.style.borderColor = `${question.color}66`;
        elements.iconWrap.style.backgroundColor = `${question.color}16`;
        elements.solved.textContent = session.questions_answered;
        elements.correct.textContent = session.correct_answers;
        elements.combo.textContent = session.current_combo;

        renderProgress(question.mission_position);
        elements.answerArea.replaceChildren();
        if (question.answer_type === 'choice') {
            buildChoiceAnswers(question.options);
        } else {
            buildNumberAnswer();
        }

        elements.hintList.replaceChildren();
        (question.revealed_hints || []).forEach(appendHint);
        elements.hints.classList.toggle('hidden', !(question.revealed_hints || []).length);
        elements.hintButton.classList.toggle('hidden', !question.has_more_hints);

        elements.feedback.className = 'hidden mt-7 rounded-xl border p-6';
        elements.submitButton.classList.remove('hidden');
        elements.nextButton.classList.add('hidden');
        setBusy(false);
    }

    async function postJson(url, payload = {}) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken(),
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(payload),
        });

        let data = {};
        try {
            data = await response.json();
        } catch {
            data = {};
        }

        if (!response.ok) {
            const validationMessage = data.errors
                ? Object.values(data.errors).flat()[0]
                : null;
            throw new Error(validationMessage || data.message || 'MathVerse could not complete that action.');
        }

        document.dispatchEvent(new CustomEvent('mathverse:data-changed'));
        return data;
    }

    function lockAnswerControls() {
        elements.answerArea.querySelectorAll('input, button').forEach(control => {
            control.disabled = true;
        });
        elements.hintButton.disabled = true;
    }

    function rewardBurst() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        const colors = ['#22d3ee', '#a78bfa', '#f59e0b', '#34d399'];
        for (let index = 0; index < 14; index++) {
            const particle = document.createElement('span');
            particle.className = 'practice-reward-particle';
            particle.style.setProperty('--reward-x', `${(Math.random() * 220) - 110}px`);
            particle.style.setProperty('--reward-y', `${-40 - (Math.random() * 140)}px`);
            particle.style.backgroundColor = colors[index % colors.length];
            root.append(particle);
            particle.addEventListener('animationend', () => particle.remove(), { once: true });
        }
    }

    function showFeedback(result) {
        const correct = Boolean(result.correct);
        renderProgress(question.mission_position, correct);
        lockAnswerControls();

        elements.feedback.className = `practice-feedback-visible mt-7 rounded-xl border p-6 ${correct ? 'correct' : 'incorrect'}`;
        elements.feedbackIcon.className = `w-12 h-12 rounded-full flex items-center justify-center shrink-0 ${correct ? 'bg-green-500/15 text-green-400' : 'bg-red-500/15 text-red-400'}`;
        elements.feedbackIcon.firstElementChild.className = `fas ${correct ? 'fa-check' : 'fa-rotate-left'}`;
        elements.feedbackTitle.textContent = correct ? 'System synchronized!' : 'Recovery route activated';
        elements.feedbackAnswer.textContent = correct
            ? `Correct answer: ${result.correct_answer}`
            : `The correct answer is ${result.correct_answer}.`;
        elements.feedbackExplanation.textContent = result.explanation;
        elements.xpReward.textContent = `+${result.xp_awarded} XP`;
        elements.masteryReward.textContent = `${result.mastery}% mastery · ${result.mastery_status}`;
        elements.comboReward.textContent = result.combo > 0 ? `${result.combo}x combo` : 'New combo next';

        elements.checkpoint.classList.toggle('hidden', !result.mission_complete);
        if (result.mission_complete) {
            elements.checkpointSummary.textContent = `${result.mission_correct}/5 correct in the latest mission · ${result.session_xp} XP earned in this adventure.`;
        }

        elements.submitButton.classList.add('hidden');
        elements.nextButton.classList.remove('hidden');
        elements.nextButton.innerHTML = result.mission_complete
            ? 'Begin Next Mission <i class="fas fa-rocket ml-2"></i>'
            : 'Next Problem <i class="fas fa-forward ml-2"></i>';

        elements.xp.textContent = Number(result.profile?.xp || 0).toLocaleString();
        elements.level.textContent = result.profile?.level || 1;
        elements.combo.textContent = result.combo || 0;
        elements.solved.textContent = result.questions_answered;
        elements.correct.textContent = result.correct_answers;
        elements.masteryLabel.textContent = `${result.mastery}% · ${result.mastery_status}`;
        elements.masteryBar.style.width = `${Math.max(0, Math.min(100, Number(result.mastery) || 0))}%`;

        if (correct) rewardBurst();
        if (result.trophy_awarded) {
            showToast('Trophy unlocked for 10 correct adventure answers!');
        } else if (result.daily_answered === result.daily_goal) {
            showToast('Daily Quest complete! You can keep practicing.');
        }
        if (result.questions_answered >= 20 && result.mission_complete) {
            elements.breakCard.classList.remove('hidden');
        }
    }

    elements.form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || elements.feedback.classList.contains('practice-feedback-visible')) return;
        if (!selectedAnswer) {
            showAnswerError('Choose or enter an answer before continuing.');
            elements.answerArea.querySelector('input, button')?.focus();
            return;
        }

        setBusy(true);
        clearAnswerError();
        try {
            const result = await postJson(`/student/learning-hub/questions/${question.id}/answer`, {
                answer: selectedAnswer,
                response_ms: Math.min(3600000, Date.now() - questionStartedAt),
            });
            showFeedback(result);
        } catch (error) {
            showAnswerError(error.message);
            showToast(error.message, true);
        } finally {
            setBusy(false);
            if (elements.feedback.classList.contains('practice-feedback-visible')) {
                elements.hintButton.disabled = true;
            }
        }
    });

    elements.hintButton.addEventListener('click', async () => {
        if (busy || !question.has_more_hints) return;
        setBusy(true);
        try {
            const result = await postJson(`/student/learning-hub/questions/${question.id}/hint`);
            appendHint(result.hint);
            question.has_more_hints = Boolean(result.has_more);
            question.hints_used = Number(result.hints_used || 0);
            elements.hintButton.classList.toggle('hidden', !question.has_more_hints);
        } catch (error) {
            showToast(error.message, true);
        } finally {
            setBusy(false);
        }
    });

    elements.nextButton.addEventListener('click', async () => {
        if (busy) return;
        setBusy(true);
        elements.nextButton.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-2"></i> Preparing Challenge';
        try {
            const result = await postJson('/student/learning-hub/questions/next', {
                session_id: question.session_id,
            });
            renderQuestion(result.question);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } catch (error) {
            showToast(error.message, true);
            elements.nextButton.innerHTML = 'Try Again <i class="fas fa-rotate-right ml-2"></i>';
        } finally {
            setBusy(false);
        }
    });

    renderQuestion(question);
}

onMathVerseReady(initializeLearningHub);
