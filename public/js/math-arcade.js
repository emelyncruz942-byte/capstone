let disposeMathArcadeGame = null;

function initializeMathArcadeGame() {
    const root = document.getElementById('math-arcade-game');
    const stateElement = document.getElementById('math-arcade-initial-state');
    if (!root || !stateElement || root.dataset.arcadeReady === 'true') return;
    root.dataset.arcadeReady = 'true';

    let initialState;
    try {
        const serialized = stateElement instanceof HTMLTemplateElement
            ? stateElement.content.textContent
            : stateElement.textContent;
        initialState = JSON.parse(serialized);
    } catch {
        showToast('MathVerse could not load this arcade game.', true);
        return;
    }

    if (!initialState.configured) return;

    const gameKey = root.dataset.gameKey;
    const allowedGames = new Set([
        'mental-arithmetic',
        'equation-balance',
        'pattern-pulse',
    ]);
    if (!allowedGames.has(gameKey)) {
        showToast('This arcade game is not available.', true);
        return;
    }

    const elements = {
        bestScore: document.getElementById('arcade-best-score'),
        personalRank: document.getElementById('arcade-personal-rank'),
        score: document.getElementById('arcade-score'),
        answers: document.getElementById('arcade-answers'),
        streak: document.getElementById('arcade-streak'),
        time: document.getElementById('arcade-time'),
        timerTrack: root.querySelector('.number-guess-timer-track'),
        timerFill: document.getElementById('arcade-timer-fill'),
        sequence: document.getElementById('arcade-sequence'),
        prompt: document.getElementById('arcade-prompt'),
        feedback: document.getElementById('arcade-feedback'),
        form: document.getElementById('arcade-form'),
        inputLabel: document.getElementById('arcade-input-label'),
        input: document.getElementById('arcade-input'),
        choices: document.getElementById('arcade-choice-options'),
        inputError: document.getElementById('arcade-input-error'),
        submit: document.getElementById('arcade-submit'),
        start: document.getElementById('arcade-start'),
        end: document.getElementById('arcade-end'),
        refreshBoard: document.getElementById('arcade-refresh-board'),
        leaderboard: document.getElementById('arcade-leaderboard'),
        resultTitle: document.getElementById('arcade-result-title'),
        resultSummary: document.getElementById('arcade-result-summary'),
        playAgain: document.getElementById('arcade-play-again'),
    };

    if (Object.values(elements).some(element => !element)) {
        showToast('The arcade controls could not be loaded.', true);
        return;
    }

    const abortController = new AbortController();
    const baseTimerMs = Number(initialState.rules?.starting_seconds || 60) * 1000;
    const gameTitle = String(initialState.game?.title || 'Arcade');
    let session = initialState.session;
    let personal = initialState.personal;
    let selectedChoice = '';
    let deadline = 0;
    let timerHandle = null;
    let busy = false;
    let boardBusy = false;
    let finishInFlight = false;
    let finishRetryAt = 0;
    let disposed = false;

    function gameIsActive() {
        return Boolean(session && session.status === 'active');
    }

    function setDeadline(remainingMs) {
        deadline = performance.now() + Math.max(0, Number(remainingMs || 0));
    }

    function remainingTime() {
        return gameIsActive() ? Math.max(0, deadline - performance.now()) : 0;
    }

    function challengeIsChoice() {
        return gameIsActive() && session.challenge?.answer_type === 'choice';
    }

    function setFeedback(message, tone = 'neutral') {
        elements.feedback.textContent = message;
        elements.feedback.dataset.tone = tone;
    }

    function clearInputError() {
        elements.inputError.textContent = '';
        elements.inputError.classList.add('hidden');
        elements.input.removeAttribute('aria-invalid');
    }

    function showInputError(message) {
        elements.inputError.textContent = message;
        elements.inputError.classList.remove('hidden');
        elements.input.setAttribute('aria-invalid', 'true');
    }

    function setBusy(value) {
        busy = value;
        root.setAttribute('aria-busy', String(value));
        renderControls();
    }

    function renderControls() {
        const active = gameIsActive();
        elements.input.disabled = busy || !active || challengeIsChoice();
        elements.submit.disabled = busy || !active || (challengeIsChoice() && selectedChoice === '');
        elements.start.disabled = busy;
        elements.end.disabled = busy || !active;
        elements.refreshBoard.disabled = busy || boardBusy;
        elements.choices.querySelectorAll('button').forEach(button => {
            button.disabled = busy || !active;
        });

        [elements.input, elements.submit, elements.start, elements.end, elements.refreshBoard]
            .forEach(control => {
                control.classList.toggle('opacity-50', control.disabled);
                control.classList.toggle('cursor-not-allowed', control.disabled);
            });

        elements.start.replaceChildren();
        const icon = document.createElement('i');
        icon.className = `fas ${active ? 'fa-rotate-right' : 'fa-play'} mr-2`;
        elements.start.append(icon, document.createTextNode(active ? ' Restart Run' : ' Start Run'));
    }

    function renderPersonal(nextPersonal = personal) {
        const previousRank = personal?.rank ?? null;
        personal = {
            ...personal,
            ...nextPersonal,
            rank: nextPersonal?.rank ?? previousRank,
        };
        elements.bestScore.textContent = String(personal?.best_score || 0);
        elements.personalRank.textContent = personal?.rank ? `#${personal.rank}` : '—';
    }

    function chooseOption(value, button) {
        if (!gameIsActive() || busy) return;
        selectedChoice = value;
        elements.choices.querySelectorAll('button').forEach(option => {
            const selected = option === button;
            option.classList.toggle('is-selected', selected);
            option.setAttribute('aria-pressed', String(selected));
        });
        clearInputError();
        renderControls();
    }

    function renderChallenge({ resetAnswer = false } = {}) {
        const active = gameIsActive();
        if (!active) {
            elements.sequence.textContent = 'Ready for launch?';
            elements.prompt.textContent = 'Start a new 60-second run';
            elements.input.classList.remove('hidden');
            elements.choices.classList.add('hidden');
            elements.choices.replaceChildren();
            selectedChoice = '';
            return;
        }

        elements.sequence.textContent = `Question ${session.sequence}`;
        elements.prompt.textContent = String(session.challenge.prompt || 'Solve the challenge');
        const choice = challengeIsChoice();
        elements.input.classList.toggle('hidden', choice);
        elements.choices.classList.toggle('hidden', !choice);
        elements.inputLabel.textContent = choice ? 'Choose the correct symbol' : 'Your answer';

        if (resetAnswer) {
            elements.input.value = '';
            selectedChoice = '';
        }

        elements.choices.replaceChildren();
        if (choice) {
            const options = Array.isArray(session.challenge.options) ? session.challenge.options : [];
            options.forEach(value => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'arcade-choice-button';
                button.textContent = String(value);
                button.setAttribute('aria-pressed', 'false');
                button.addEventListener('click', () => chooseOption(String(value), button), {
                    signal: abortController.signal,
                });
                elements.choices.append(button);
            });
        }
    }

    function renderSession(options = {}) {
        elements.score.textContent = String(session?.score || 0);
        elements.answers.textContent = String(session?.answers || 0);
        elements.streak.textContent = String(session?.streak || 0);
        renderChallenge(options);
        renderControls();
        renderTimer();
    }

    function renderTimer() {
        const remainingMs = remainingTime();
        const seconds = Math.ceil(remainingMs / 1000);
        const percent = baseTimerMs > 0 ? Math.min(100, (remainingMs / baseTimerMs) * 100) : 0;
        elements.time.textContent = String(seconds);
        elements.timerFill.style.width = `${percent}%`;
        elements.timerTrack.setAttribute('aria-valuenow', String(seconds));
        elements.timerTrack.classList.toggle('is-urgent', gameIsActive() && seconds <= 10);

        if (gameIsActive() && remainingMs <= 0 && !finishInFlight && performance.now() >= finishRetryAt) {
            void finishGame({ timedOut: true });
        }
    }

    function focusAnswer() {
        window.setTimeout(() => {
            if (!disposed && gameIsActive() && !challengeIsChoice()) {
                elements.input.focus({ preventScroll: true });
            }
        }, 40);
    }

    function errorMessage(payload, response) {
        const validation = payload?.errors && Object.values(payload.errors).flat()[0];
        return validation || payload?.message || `Request failed (${response.status}).`;
    }

    async function requestJson(url, { method = 'GET', payload = null } = {}) {
        const headers = { Accept: 'application/json' };
        if (method !== 'GET') {
            headers['X-CSRF-TOKEN'] = csrfToken();
            headers['Content-Type'] = 'application/json';
        }

        const response = await fetch(url, {
            method,
            headers,
            credentials: 'same-origin',
            cache: 'no-store',
            signal: abortController.signal,
            body: payload === null ? null : JSON.stringify(payload),
        });
        const body = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(errorMessage(body, response));

        return body;
    }

    function leaderboardEntry(row) {
        const item = document.createElement('li');
        item.className = `number-guess-rank${row.is_current ? ' is-current' : ''}`;
        item.dataset.rank = String(row.rank);

        const rank = document.createElement('span');
        rank.className = 'number-guess-rank-number';
        rank.textContent = `#${row.rank}`;

        const identity = document.createElement('span');
        identity.className = 'min-w-0 flex-1';
        const name = document.createElement('strong');
        name.className = 'block truncate';
        name.textContent = `${row.display_name}${row.is_current ? ' (You)' : ''}`;
        const details = document.createElement('small');
        details.textContent = `Streak ${row.best_streak} · ${row.games_played} runs`;
        identity.append(name, details);

        const score = document.createElement('strong');
        score.className = 'number-guess-rank-score';
        score.textContent = String(row.best_score);
        item.append(rank, identity, score);
        return item;
    }

    function renderLeaderboard(rows) {
        elements.leaderboard.replaceChildren();
        if (!Array.isArray(rows) || rows.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'number-guess-board-empty';
            empty.textContent = 'No scores yet. Complete the first run!';
            elements.leaderboard.append(empty);
            return;
        }
        rows.forEach(row => elements.leaderboard.append(leaderboardEntry(row)));
    }

    async function refreshLeaderboard({ announce = false } = {}) {
        if (boardBusy || disposed) return;
        boardBusy = true;
        renderControls();
        try {
            const result = await requestJson(`/student/games/${encodeURIComponent(gameKey)}/leaderboard`);
            renderPersonal(result.personal);
            renderLeaderboard(result.leaderboard);
            if (announce) showToast('Leaderboard refreshed.');
        } catch (error) {
            if (error.name !== 'AbortError') showToast(error.message, true);
        } finally {
            boardBusy = false;
            renderControls();
        }
    }

    async function startGame() {
        if (busy) return;
        setBusy(true);
        clearInputError();
        closeModal('arcadeResultModal');
        try {
            const result = await requestJson(`/student/games/${encodeURIComponent(gameKey)}/start`, {
                method: 'POST',
                payload: {},
            });
            session = result.session;
            renderPersonal(result.personal);
            setDeadline(session.remaining_ms);
            setFeedback('Run started. Read carefully and keep the reasoning streak alive!', 'neutral');
            renderSession({ resetAnswer: true });
            focusAnswer();
            document.dispatchEvent(new CustomEvent('mathverse:data-changed'));
        } catch (error) {
            if (error.name !== 'AbortError') showToast(error.message, true);
        } finally {
            setBusy(false);
        }
    }

    function completedSummary(result) {
        const score = Number(result.session.score || 0);
        const answers = Number(result.session.answers || 0);
        const streak = Number(result.session.best_streak || 0);
        return `You scored ${score} from ${answers} ${answers === 1 ? 'answer' : 'answers'}. Best streak: ${streak}.`;
    }

    function showCompletedGame(result, timedOut) {
        const expired = timedOut || result.session.status === 'expired';
        elements.resultTitle.textContent = expired ? "Time's Up!" : 'Run Ended';
        elements.resultSummary.textContent = completedSummary(result);
        setFeedback(`${expired ? 'Time is up' : 'Run ended'}. Final score: ${result.session.score}.`, 'finished');
        openModal('arcadeResultModal');
        void refreshLeaderboard();
        document.dispatchEvent(new CustomEvent('mathverse:data-changed'));
    }

    async function finishGame({ timedOut = false } = {}) {
        if (!gameIsActive() || finishInFlight) return false;
        finishInFlight = true;
        setBusy(true);
        try {
            const result = await requestJson(`/student/games/${encodeURIComponent(gameKey)}/${encodeURIComponent(session.id)}/finish`, {
                method: 'POST',
                payload: {},
            });
            session = result.session;
            renderPersonal(result.personal);
            deadline = 0;
            renderSession({ resetAnswer: true });
            showCompletedGame(result, timedOut);
            return true;
        } catch (error) {
            finishRetryAt = performance.now() + 3000;
            if (error.name !== 'AbortError') {
                setFeedback('Connection interrupted. MathVerse will verify the timer again.', 'incorrect');
                showToast(error.message, true);
            }
            return false;
        } finally {
            finishInFlight = false;
            setBusy(false);
        }
    }

    async function submitAnswer() {
        if (!gameIsActive() || busy) return;
        clearInputError();
        const choice = challengeIsChoice();
        const answer = choice ? selectedChoice : elements.input.value.trim();
        if (answer === '') {
            showInputError(choice ? 'Choose one of the comparison symbols.' : 'Enter an answer.');
            focusAnswer();
            return;
        }
        if (!choice && !/^-?[0-9]+$/.test(answer)) {
            showInputError('Enter a whole number, including a minus sign if needed.');
            focusAnswer();
            return;
        }

        setBusy(true);
        try {
            const sequence = Number(session.sequence);
            const result = await requestJson(`/student/games/${encodeURIComponent(gameKey)}/${encodeURIComponent(session.id)}/answer`, {
                method: 'POST',
                payload: { sequence, answer },
            });
            session = result.session;
            renderPersonal(result.personal);

            if (result.outcome.finished) {
                deadline = 0;
                renderSession({ resetAnswer: true });
                showCompletedGame(result, result.session.status === 'expired');
                return;
            }

            setDeadline(session.remaining_ms);
            renderSession({ resetAnswer: true });
            if (result.outcome.direction === 'stale') {
                setFeedback('Game state refreshed. That question was already answered in this run.', 'neutral');
                showToast('Your latest verified question is ready.');
                focusAnswer();
                return;
            }
            const explanation = result.outcome.explanation ? ` ${result.outcome.explanation}` : '';
            if (result.outcome.correct) {
                setFeedback(`Correct!${explanation}`, 'correct');
                root.classList.remove('arcade-correct-flash');
                void root.offsetWidth;
                root.classList.add('arcade-correct-flash');
            } else {
                const correctAnswer = result.outcome.correct_answer
                    ? ` The correct answer was ${result.outcome.correct_answer}.`
                    : '';
                setFeedback(`Not quite.${correctAnswer}${explanation}`, 'incorrect');
            }
            if (result.personal.new_best) showToast(`New ${gameTitle} best: ${result.personal.best_score}!`);
            focusAnswer();
        } catch (error) {
            if (error.name !== 'AbortError') {
                showInputError(error.message);
                showToast(error.message, true);
            }
        } finally {
            setBusy(false);
        }
    }

    elements.form.addEventListener('submit', event => {
        event.preventDefault();
        void submitAnswer();
    }, { signal: abortController.signal });
    elements.input.addEventListener('input', clearInputError, { signal: abortController.signal });
    elements.start.addEventListener('click', () => void startGame(), { signal: abortController.signal });
    elements.end.addEventListener('click', () => void finishGame(), { signal: abortController.signal });
    elements.playAgain.addEventListener('click', () => void startGame(), { signal: abortController.signal });
    elements.refreshBoard.addEventListener('click', () => void refreshLeaderboard({ announce: true }), { signal: abortController.signal });

    const visibilityHandler = () => {
        if (!document.hidden) renderTimer();
    };
    document.addEventListener('visibilitychange', visibilityHandler);

    if (gameIsActive()) {
        setDeadline(session.remaining_ms);
        setFeedback('Your verified run is active. Keep going!', 'neutral');
        renderSession({ resetAnswer: true });
        focusAnswer();
    } else {
        renderSession({ resetAnswer: true });
        setFeedback('Every run rotates through varied question types.', 'neutral');
    }
    renderPersonal(personal);
    timerHandle = window.setInterval(renderTimer, 100);

    disposeMathArcadeGame = () => {
        disposed = true;
        abortController.abort();
        if (timerHandle !== null) window.clearInterval(timerHandle);
        document.removeEventListener('visibilitychange', visibilityHandler);
        window.MathVerseNavigation?.clearCache();
        closeModal('arcadeResultModal');
        disposeMathArcadeGame = null;
    };
}

document.addEventListener('mathverse:before-navigate', () => disposeMathArcadeGame?.());
onMathVerseReady(initializeMathArcadeGame);
