let disposeNumberGuessGame = null;

function initializeNumberGuessGame() {
    const root = document.getElementById('number-guess-game');
    const stateElement = document.getElementById('number-guess-initial-state');
    if (!root || !stateElement || root.dataset.numberGuessReady === 'true') return;
    root.dataset.numberGuessReady = 'true';

    let initialState;
    try {
        const serialized = stateElement instanceof HTMLTemplateElement
            ? stateElement.content.textContent
            : stateElement.textContent;
        initialState = JSON.parse(serialized);
    } catch {
        showToast('MathVerse could not load Number Guess.', true);
        return;
    }

    if (!initialState.configured) return;

    const elements = {
        bestScore: document.getElementById('number-guess-best-score'),
        personalRank: document.getElementById('number-guess-personal-rank'),
        score: document.getElementById('number-guess-score'),
        tries: document.getElementById('number-guess-tries'),
        gamesPlayed: document.getElementById('number-guess-games-played'),
        time: document.getElementById('number-guess-time'),
        timerTrack: root.querySelector('.number-guess-timer-track'),
        timerFill: document.getElementById('number-guess-timer-fill'),
        eyebrow: document.getElementById('number-guess-eyebrow'),
        prompt: document.getElementById('number-guess-prompt'),
        range: document.getElementById('number-guess-range'),
        feedback: document.getElementById('number-guess-feedback'),
        form: document.getElementById('number-guess-form'),
        input: document.getElementById('number-guess-input'),
        inputError: document.getElementById('number-guess-input-error'),
        decrement: document.getElementById('number-guess-decrement'),
        increment: document.getElementById('number-guess-increment'),
        submit: document.getElementById('number-guess-submit'),
        start: document.getElementById('number-guess-start'),
        end: document.getElementById('number-guess-end'),
        refreshBoard: document.getElementById('number-guess-refresh-board'),
        leaderboard: document.getElementById('number-guess-leaderboard'),
        confirmEnd: document.getElementById('number-guess-confirm-end'),
        endTitle: document.getElementById('number-guess-end-title'),
        endMessage: document.getElementById('number-guess-end-message'),
        resultTitle: document.getElementById('number-guess-result-title'),
        resultSummary: document.getElementById('number-guess-result-summary'),
        playAgain: document.getElementById('number-guess-play-again'),
    };

    if (Object.values(elements).some(element => !element)) {
        showToast('The Number Guess controls could not be loaded.', true);
        return;
    }

    const abortController = new AbortController();
    const rules = initialState.rules;
    const baseTimerMs = Number(rules.starting_seconds || 60) * 1000;
    let session = initialState.session;
    let personal = initialState.personal;
    let deadline = 0;
    let timerCeilingMs = baseTimerMs;
    let timerHandle = null;
    let busy = false;
    let disposed = false;
    let finishInFlight = false;
    let finishRetryAt = 0;
    let leaderboardBusy = false;
    let pendingEndAction = 'finish';

    function gameIsActive() {
        return Boolean(session && session.status === 'active');
    }

    function setDeadline(remainingMs) {
        const safeRemaining = Math.max(0, Number(remainingMs || 0));
        deadline = performance.now() + safeRemaining;
        timerCeilingMs = Math.max(baseTimerMs, safeRemaining);
    }

    function remainingTime() {
        return gameIsActive() ? Math.max(0, deadline - performance.now()) : 0;
    }

    function setBusy(value) {
        busy = value;
        root.setAttribute('aria-busy', String(value));
        renderControls();
    }

    function renderControls() {
        const active = gameIsActive();
        [elements.input, elements.decrement, elements.increment, elements.submit]
            .forEach(control => {
                control.disabled = busy || !active;
            });
        elements.start.disabled = busy;
        elements.end.disabled = busy || !active;
        elements.refreshBoard.disabled = busy || leaderboardBusy;

        [elements.input, elements.decrement, elements.increment, elements.submit, elements.start, elements.end, elements.refreshBoard]
            .forEach(control => {
                control.classList.toggle('opacity-50', control.disabled);
                control.classList.toggle('cursor-not-allowed', control.disabled);
            });

        elements.start.innerHTML = active
            ? '<i class="fas fa-rotate-right mr-2"></i> Restart Game'
            : '<i class="fas fa-play mr-2"></i> Start Game';
    }

    function renderPersonal(nextPersonal = personal) {
        const previousRank = personal?.rank ?? null;
        personal = {
            ...personal,
            ...nextPersonal,
            rank: nextPersonal?.rank ?? previousRank,
        };
        elements.bestScore.textContent = String(personal.best_score || 0);
        elements.personalRank.textContent = personal.rank ? `#${personal.rank}` : '—';
        elements.gamesPlayed.textContent = String(personal.games_played || 0);
    }

    function setFeedback(message, tone = 'neutral') {
        elements.feedback.textContent = message;
        elements.feedback.dataset.tone = tone;
    }

    function showInputError(message) {
        elements.inputError.textContent = message;
        elements.inputError.classList.remove('hidden');
        elements.input.setAttribute('aria-invalid', 'true');
    }

    function clearInputError() {
        elements.inputError.textContent = '';
        elements.inputError.classList.add('hidden');
        elements.input.removeAttribute('aria-invalid');
    }

    function midpoint() {
        return Math.ceil((1 + Number(session?.range_max || rules.starting_range_max || 100)) / 2);
    }

    function focusGuess(select = true) {
        window.setTimeout(() => {
            if (!gameIsActive() || disposed) return;
            elements.input.focus({ preventScroll: true });
            if (select) elements.input.select();
        }, 40);
    }

    function renderSession({ resetInput = false } = {}) {
        const active = gameIsActive();
        const rangeMax = Number(session?.range_max || rules.starting_range_max || 100);
        elements.score.textContent = String(session?.score || 0);
        elements.tries.textContent = String(session?.guesses || 0);
        elements.input.max = String(rangeMax);
        elements.range.replaceChildren(
            document.createTextNode('Enter a whole number from 1 to '),
            Object.assign(document.createElement('strong'), {
                className: 'text-cyan-300',
                textContent: rangeMax.toLocaleString(),
            }),
            document.createTextNode('.'),
        );
        elements.eyebrow.textContent = active ? 'Live Game' : 'Ready for a challenge?';
        elements.prompt.textContent = active ? 'Find the hidden number' : 'Start a new game';

        const currentGuess = Number(elements.input.value);
        if (resetInput || !Number.isInteger(currentGuess) || currentGuess < 1 || currentGuess > rangeMax) {
            elements.input.value = String(midpoint());
        }

        renderControls();
        renderTimer();
    }

    function renderTimer() {
        const remainingMs = remainingTime();
        const seconds = Math.ceil(remainingMs / 1000);
        const maximumSeconds = Math.max(Number(rules.starting_seconds || 60), Math.ceil(timerCeilingMs / 1000));
        const percent = timerCeilingMs > 0 ? Math.min(100, (remainingMs / timerCeilingMs) * 100) : 0;
        elements.time.textContent = String(seconds);
        elements.timerFill.style.width = `${percent}%`;
        elements.timerTrack.setAttribute('aria-valuemax', String(maximumSeconds));
        elements.timerTrack.setAttribute('aria-valuenow', String(seconds));
        elements.timerTrack.classList.toggle('is-urgent', gameIsActive() && seconds <= 10);

        if (gameIsActive() && remainingMs <= 0 && !finishInFlight && performance.now() >= finishRetryAt) {
            void finishGame({ timedOut: true, showResult: true });
        }
    }

    function errorMessage(payload, response) {
        const validation = payload?.errors && Object.values(payload.errors).flat()[0];
        return validation || payload?.message || `Request failed (${response.status}).`;
    }

    async function requestJson(url, { method = 'GET', payload = null } = {}) {
        const headers = { 'Accept': 'application/json' };
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
        details.textContent = `Grade ${row.grade_level} · ${row.best_guesses} tries`;
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
            empty.textContent = 'No scores yet. Be the first explorer on the board!';
            elements.leaderboard.append(empty);
            return;
        }
        rows.forEach(row => elements.leaderboard.append(leaderboardEntry(row)));
    }

    async function refreshLeaderboard({ announce = false } = {}) {
        if (leaderboardBusy || disposed) return;
        leaderboardBusy = true;
        renderControls();
        elements.refreshBoard.classList.add('is-spinning');
        try {
            const result = await requestJson('/student/games/number-guess/leaderboard');
            renderPersonal(result.personal);
            renderLeaderboard(result.leaderboard);
            if (announce) showToast('Leaderboard refreshed.');
        } catch (error) {
            if (error.name !== 'AbortError') showToast(error.message, true);
        } finally {
            leaderboardBusy = false;
            elements.refreshBoard.classList.remove('is-spinning');
            renderControls();
        }
    }

    async function startGame() {
        setBusy(true);
        clearInputError();
        closeModal('numberGuessEndModal');
        closeModal('numberGuessResultModal');
        try {
            const result = await requestJson('/student/games/number-guess/start', { method: 'POST', payload: {} });
            session = result.session;
            renderPersonal(result.personal);
            setDeadline(session.remaining_ms);
            setFeedback('Game started! Use the high-or-low clues to close in on the number.', 'neutral');
            renderSession({ resetInput: true });
            focusGuess();
            document.dispatchEvent(new CustomEvent('mathverse:data-changed'));
        } catch (error) {
            if (error.name !== 'AbortError') showToast(error.message, true);
        } finally {
            setBusy(false);
        }
    }

    function completedSummary(result) {
        const score = Number(result.session.score || 0);
        const guesses = Number(result.session.guesses || 0);
        const answer = result.outcome?.correct_number;
        const answerText = Number.isInteger(Number(answer)) ? ` The final hidden number was ${answer}.` : '';
        return `You found ${score} ${score === 1 ? 'number' : 'numbers'} in ${guesses} ${guesses === 1 ? 'try' : 'tries'}.${answerText}`;
    }

    function showCompletedGame(result, timedOut) {
        const expired = timedOut || result.session.status === 'expired';
        elements.resultTitle.textContent = expired ? "Time's Up!" : 'Game Ended';
        elements.resultSummary.textContent = completedSummary(result);
        setFeedback(
            expired ? `Time is up. Final score: ${result.session.score}.` : `Run ended. Final score: ${result.session.score}.`,
            'finished',
        );
        openModal('numberGuessResultModal');
        void refreshLeaderboard();
        document.dispatchEvent(new CustomEvent('mathverse:data-changed'));
    }

    async function finishGame({ timedOut = false, showResult = true } = {}) {
        if (!gameIsActive() || finishInFlight) return false;
        finishInFlight = true;
        setBusy(true);
        try {
            const result = await requestJson(`/student/games/number-guess/${encodeURIComponent(session.id)}/finish`, {
                method: 'POST',
                payload: {},
            });
            session = result.session;
            renderPersonal(result.personal);
            deadline = 0;
            renderSession();
            if (showResult) showCompletedGame(result, timedOut);
            return true;
        } catch (error) {
            finishRetryAt = performance.now() + 3000;
            if (error.name !== 'AbortError') {
                setFeedback('Connection interrupted. MathVerse will verify the timer again.', 'high');
                showToast(error.message, true);
            }
            return false;
        } finally {
            finishInFlight = false;
            setBusy(false);
        }
    }

    async function submitGuess() {
        if (!gameIsActive() || busy) return;
        clearInputError();
        const guess = Number(elements.input.value);
        const rangeMax = Number(session.range_max);
        if (!Number.isInteger(guess) || guess < 1 || guess > rangeMax) {
            showInputError(`Enter a whole number from 1 to ${rangeMax.toLocaleString()}.`);
            focusGuess();
            return;
        }

        setBusy(true);
        try {
            const expectedGuesses = Number(session.guesses || 0);
            const result = await requestJson(`/student/games/number-guess/${encodeURIComponent(session.id)}/guess`, {
                method: 'POST',
                payload: { guess, expected_guesses: expectedGuesses },
            });
            session = result.session;
            renderPersonal(result.personal);
            setDeadline(session.remaining_ms);

            if (result.outcome.finished) {
                renderSession();
                showCompletedGame(result, result.session.status === 'expired');
                return;
            }

            if (result.outcome.direction === 'stale') {
                setFeedback('Game state refreshed. That guess was already recorded in this run.', 'neutral');
                showToast('Your latest verified game state is ready.');
                renderSession({ resetInput: true });
                focusGuess();
                return;
            }

            if (result.outcome.direction === 'correct') {
                setFeedback(`Correct! +${rules.correct_bonus_seconds} seconds. The new range now reaches ${Number(session.range_max).toLocaleString()}.`, 'correct');
                root.classList.remove('number-guess-correct-flash');
                void root.offsetWidth;
                root.classList.add('number-guess-correct-flash');
                if (result.personal.new_best) showToast(`New best score: ${result.personal.best_score}!`);
                renderSession({ resetInput: true });
            } else if (result.outcome.direction === 'low') {
                setFeedback(`${guess.toLocaleString()} is too low. Aim higher!`, 'low');
                renderSession();
            } else {
                setFeedback(`${guess.toLocaleString()} is too high. Aim lower!`, 'high');
                renderSession();
            }
            focusGuess();
        } catch (error) {
            if (error.name !== 'AbortError') {
                showInputError(error.message);
                showToast(error.message, true);
            }
        } finally {
            setBusy(false);
        }
    }

    function adjustGuess(change) {
        if (!gameIsActive() || busy) return;
        const rangeMax = Number(session.range_max);
        const current = Number(elements.input.value);
        const next = Math.max(1, Math.min(rangeMax, Number.isInteger(current) ? current + change : midpoint()));
        elements.input.value = String(next);
        clearInputError();
        focusGuess(false);
    }

    function requestEnd(action) {
        if (!gameIsActive()) return;
        pendingEndAction = action;
        const restarting = action === 'restart';
        elements.endTitle.textContent = restarting ? 'Restart Current Game?' : 'End Current Game?';
        elements.endMessage.textContent = restarting
            ? 'Your current verified score stays on the leaderboard, but its timer and hidden number will be replaced.'
            : 'Your verified score stays on the leaderboard, but this timer cannot be resumed.';
        elements.confirmEnd.textContent = restarting ? 'Restart Game' : 'End Game';
        openModal('numberGuessEndModal');
    }

    elements.form.addEventListener('submit', event => {
        event.preventDefault();
        void submitGuess();
    });
    elements.input.addEventListener('input', clearInputError);
    elements.input.addEventListener('keydown', event => {
        if (event.key === 'ArrowUp') {
            event.preventDefault();
            adjustGuess(1);
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            adjustGuess(-1);
        }
    });
    elements.decrement.addEventListener('click', () => adjustGuess(-1));
    elements.increment.addEventListener('click', () => adjustGuess(1));
    elements.start.addEventListener('click', () => {
        if (gameIsActive()) requestEnd('restart');
        else void startGame();
    });
    elements.end.addEventListener('click', () => requestEnd('finish'));
    elements.confirmEnd.addEventListener('click', async () => {
        closeModal('numberGuessEndModal');
        const shouldRestart = pendingEndAction === 'restart';
        const finished = await finishGame({ showResult: !shouldRestart });
        if (finished && shouldRestart) await startGame();
    });
    elements.playAgain.addEventListener('click', () => void startGame());
    elements.refreshBoard.addEventListener('click', () => void refreshLeaderboard({ announce: true }));

    const visibilityHandler = () => {
        if (!document.hidden) renderTimer();
    };
    document.addEventListener('visibilitychange', visibilityHandler);

    if (gameIsActive()) {
        setDeadline(session.remaining_ms);
        setFeedback('Your game is still running. Keep guessing!', 'neutral');
        renderSession({ resetInput: true });
        focusGuess();
    } else {
        renderSession({ resetInput: true });
        setFeedback('Use the clues to narrow the range before time runs out.', 'neutral');
    }
    renderPersonal(personal);
    timerHandle = window.setInterval(renderTimer, 100);

    disposeNumberGuessGame = () => {
        disposed = true;
        abortController.abort();
        if (timerHandle !== null) window.clearInterval(timerHandle);
        document.removeEventListener('visibilitychange', visibilityHandler);
        window.MathVerseNavigation?.clearCache();
        closeModal('numberGuessEndModal');
        closeModal('numberGuessResultModal');
        disposeNumberGuessGame = null;
    };
}

document.addEventListener('mathverse:before-navigate', () => disposeNumberGuessGame?.());
onMathVerseReady(initializeNumberGuessGame);
