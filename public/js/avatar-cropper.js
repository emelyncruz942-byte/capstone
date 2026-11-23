(function () {
    'use strict';

    const OUTPUT_SIZE = 512;
    const MAX_BYTES = 2 * 1024 * 1024;
    const MAX_ZOOM = 4;
    const TYPES = new Set(['image/jpeg', 'image/png', 'image/webp']);
    let current = null;

    const clamp = (value, low, high) => Math.min(high, Math.max(low, value));

    // All positions are in decoded source-image pixels. The same bounded
    // square drives the preview and export, so no edge can expose a blank gap.
    function bounds(width, height, zoom = 1, centerX = width / 2, centerY = height / 2) {
        if (![width, height, zoom, centerX, centerY].every(Number.isFinite) || width <= 0 || height <= 0) {
            throw new Error('This photo could not be opened. Please choose another image.');
        }
        const actualZoom = clamp(zoom, 1, MAX_ZOOM);
        const size = Math.min(width, height) / actualZoom;
        const x = clamp(centerX, size / 2, width - size / 2);
        const y = clamp(centerY, size / 2, height - size / 2);
        return { x: x - size / 2, y: y - size / 2, size, centerX: x, centerY: y, zoom: actualZoom };
    }

    function pan(state, deltaX, deltaY, displayedSize) {
        if (!Number.isFinite(displayedSize) || displayedSize <= 0) return bounds(state.width, state.height, state.zoom, state.centerX, state.centerY);
        const crop = bounds(state.width, state.height, state.zoom, state.centerX, state.centerY);
        return bounds(state.width, state.height, crop.zoom,
            crop.centerX - deltaX * crop.size / displayedSize,
            crop.centerY - deltaY * crop.size / displayedSize);
    }

    function element(tag, className = '', text = '') {
        const node = document.createElement(tag);
        node.className = className;
        if (text) node.textContent = text;
        return node;
    }

    function button(text, className, label = '') {
        const node = element('button', className, text);
        node.type = 'button';
        if (label) node.setAttribute('aria-label', label);
        return node;
    }

    function crop(file) {
        if (!file || !TYPES.has(file.type) || file.size > MAX_BYTES) {
            if (current) current.cancel(true);
            return Promise.reject(new Error('Choose a JPEG, PNG, or WebP photo up to 2 MB.'));
        }
        const originalFocus = current?.returnFocus;
        if (current) current.cancel(false);

        return new Promise((resolve, reject) => {
            let closed = false;
            let overlay = null;
            let image = null;
            let objectUrl = null;
            let keyHandler = null;
            let navigationHandler = null;
            const inertStates = [];
            const previousFocus = originalFocus?.isConnected ? originalFocus : document.activeElement;
            const session = { returnFocus: previousFocus, cancel: restoreFocus => finish(null, null, restoreFocus !== false) };
            current = session;

            function finish(value, error = null, restoreFocus = true) {
                if (closed) return;
                closed = true;
                if (keyHandler) document.removeEventListener('keydown', keyHandler, true);
                if (navigationHandler) {
                    document.removeEventListener('mathverse:before-navigate', navigationHandler);
                    window.removeEventListener('pagehide', navigationHandler);
                }
                inertStates.forEach(([node, wasInert]) => { node.inert = wasInert; });
                if (overlay) overlay.remove();
                document.body.classList.remove('avatar-crop-open');
                if (objectUrl) URL.revokeObjectURL(objectUrl);
                image = null;
                if (current === session) current = null;
                if (restoreFocus && previousFocus?.isConnected && typeof previousFocus.focus === 'function') {
                    previousFocus.focus({ preventScroll: true });
                }
                if (error) reject(error); else resolve(value);
            }

            navigationHandler = () => finish(null, null, false);
            document.addEventListener('mathverse:before-navigate', navigationHandler);
            window.addEventListener('pagehide', navigationHandler);

            try {
                objectUrl = URL.createObjectURL(file);
                image = new Image();
                image.decoding = 'async';
                image.onload = () => {
                    if (closed) return;
                    try { openEditor(); } catch (error) { finish(null, error); }
                };
                image.onerror = () => finish(null, new Error('This photo could not be opened. Please choose another image.'));
                // Current browsers apply the photo's EXIF orientation when
                // decoding HTMLImageElement and drawing it onto this canvas.
                image.src = objectUrl;
            } catch (error) {
                finish(null, new Error('Photo cropping is unavailable in this browser. Please try another browser.'));
            }

            function openEditor() {
                const width = image.naturalWidth;
                const height = image.naturalHeight;
                let state = { width, height, ...bounds(width, height) };
                let pointer = null;
                let exporting = false;

                overlay = element('div', 'avatar-crop-overlay');
                const dialog = element('section', 'avatar-crop-dialog');
                dialog.setAttribute('role', 'dialog');
                dialog.setAttribute('aria-modal', 'true');
                dialog.setAttribute('aria-labelledby', 'avatar-crop-title');
                dialog.setAttribute('aria-describedby', 'avatar-crop-instructions');

                const header = element('header', 'avatar-crop-header');
                const headingGroup = element('div');
                const title = element('h2', '', 'Adjust your photo'); title.id = 'avatar-crop-title';
                const instructions = element('p', '', 'Drag to move your photo. Zoom in to choose what fits in the square.'); instructions.id = 'avatar-crop-instructions';
                headingGroup.append(title, instructions);
                const close = button('×', 'avatar-crop-close', 'Cancel photo crop');
                header.append(headingGroup, close);

                const stage = element('div', 'avatar-crop-stage');
                const canvas = element('canvas', 'avatar-crop-canvas');
                canvas.width = OUTPUT_SIZE; canvas.height = OUTPUT_SIZE; canvas.tabIndex = 0;
                canvas.setAttribute('role', 'img');
                canvas.setAttribute('aria-label', 'Photo crop preview. Use arrow keys to move the photo; hold Shift for larger moves.');
                const context = canvas.getContext('2d');
                if (!context) throw new Error('Photo cropping is unavailable in this browser.');
                const grid = element('div', 'avatar-crop-grid'); grid.setAttribute('aria-hidden', 'true');
                stage.append(canvas, grid);

                const zoomRow = element('div', 'avatar-crop-zoom');
                const zoomLabel = element('label', '', 'Zoom'); zoomLabel.htmlFor = 'avatar-crop-zoom';
                const zoomOut = button('−', 'avatar-crop-step', 'Zoom out');
                const zoom = element('input', 'avatar-crop-range');
                zoom.id = 'avatar-crop-zoom'; zoom.type = 'range'; zoom.min = '1'; zoom.max = String(MAX_ZOOM); zoom.step = '.01'; zoom.value = '1';
                const zoomIn = button('+', 'avatar-crop-step', 'Zoom in');
                const zoomValue = element('output', 'avatar-crop-zoom-value', '100%'); zoomValue.setAttribute('for', zoom.id);
                zoomRow.append(zoomLabel, zoomOut, zoom, zoomIn, zoomValue);

                const keyboardHint = element('p', 'avatar-crop-keyboard-hint', 'You can also move the photo with the arrow keys while the preview is selected.');
                const errorMessage = element('p', 'avatar-crop-error'); errorMessage.hidden = true; errorMessage.setAttribute('role', 'alert');
                const footer = element('footer', 'avatar-crop-footer');
                const reset = button('Reset', 'avatar-crop-reset');
                const cancel = button('Cancel', 'avatar-crop-cancel');
                const confirm = button('Use photo', 'avatar-crop-confirm');
                const actions = element('div', 'avatar-crop-actions'); actions.append(cancel, confirm);
                footer.append(reset, actions);
                dialog.append(header, stage, zoomRow, keyboardHint, errorMessage, footer);
                overlay.append(dialog);
                document.body.append(overlay);
                document.body.classList.add('avatar-crop-open');
                for (const sibling of document.body.children) {
                    if (sibling !== overlay && 'inert' in sibling) {
                        inertStates.push([sibling, sibling.inert]); sibling.inert = true;
                    }
                }

                function draw(next = state) {
                    const bounded = bounds(width, height, next.zoom, next.centerX, next.centerY);
                    state = { width, height, ...bounded };
                    context.clearRect(0, 0, OUTPUT_SIZE, OUTPUT_SIZE);
                    context.drawImage(image, state.x, state.y, state.size, state.size, 0, 0, OUTPUT_SIZE, OUTPUT_SIZE);
                    zoom.value = String(state.zoom);
                    zoomValue.textContent = `${Math.round(state.zoom * 100)}%`;
                    zoom.setAttribute('aria-valuetext', zoomValue.textContent);
                    zoomOut.disabled = state.zoom <= 1 || exporting;
                    zoomIn.disabled = state.zoom >= MAX_ZOOM || exporting;
                }

                function setZoom(value) {
                    if (!exporting) draw({ ...state, zoom: Number(value) });
                }
                zoom.addEventListener('input', () => setZoom(zoom.value));
                zoomOut.addEventListener('click', () => setZoom(state.zoom - .1));
                zoomIn.addEventListener('click', () => setZoom(state.zoom + .1));
                reset.addEventListener('click', () => { if (!exporting) draw({ width, height, ...bounds(width, height) }); });
                close.addEventListener('click', () => finish(null));
                cancel.addEventListener('click', () => finish(null));
                overlay.addEventListener('pointerdown', event => { if (event.target === overlay) finish(null); });

                canvas.addEventListener('pointerdown', event => {
                    if (exporting || pointer || event.button !== 0) return;
                    event.preventDefault(); canvas.focus({ preventScroll: true });
                    pointer = { id: event.pointerId, x: event.clientX, y: event.clientY };
                    if (typeof canvas.setPointerCapture === 'function') canvas.setPointerCapture(event.pointerId);
                    canvas.classList.add('is-dragging');
                });
                canvas.addEventListener('pointermove', event => {
                    if (!pointer || pointer.id !== event.pointerId || exporting) return;
                    event.preventDefault();
                    draw({ width, height, ...pan(state, event.clientX - pointer.x, event.clientY - pointer.y, canvas.getBoundingClientRect().width) });
                    pointer.x = event.clientX; pointer.y = event.clientY;
                });
                function endPointer(event) {
                    if (!pointer || pointer.id !== event.pointerId) return;
                    pointer = null; canvas.classList.remove('is-dragging');
                }
                canvas.addEventListener('pointerup', endPointer);
                canvas.addEventListener('pointercancel', endPointer);
                canvas.addEventListener('lostpointercapture', endPointer);

                keyHandler = event => {
                    if (event.key === 'Escape') {
                        event.preventDefault(); event.stopImmediatePropagation(); finish(null); return;
                    }
                    if (event.key === 'Tab') {
                        const focusable = Array.from(dialog.querySelectorAll('button, input, [tabindex="0"]')).filter(node => !node.disabled && !node.hidden);
                        const first = focusable[0], last = focusable[focusable.length - 1];
                        if (event.shiftKey && (document.activeElement === first || !dialog.contains(document.activeElement))) {
                            event.preventDefault(); last?.focus();
                        } else if (!event.shiftKey && (document.activeElement === last || !dialog.contains(document.activeElement))) {
                            event.preventDefault(); first?.focus();
                        }
                    }
                    if (document.activeElement !== canvas || exporting) return;
                    const direction = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[event.key];
                    if (!direction) return;
                    event.preventDefault();
                    const step = state.size * (event.shiftKey ? .05 : .01);
                    draw({ ...state, centerX: state.centerX - direction[0] * step, centerY: state.centerY - direction[1] * step });
                };
                document.addEventListener('keydown', keyHandler, true);

                confirm.addEventListener('click', () => {
                    if (exporting) return;
                    exporting = true; confirm.disabled = true; reset.disabled = true; zoom.disabled = true;
                    confirm.textContent = 'Preparing…'; dialog.setAttribute('aria-busy', 'true');
                    draw();
                    function exportFailed(error) {
                        exporting = false; confirm.disabled = false; reset.disabled = false; zoom.disabled = false;
                        confirm.textContent = 'Use photo'; dialog.removeAttribute('aria-busy');
                        errorMessage.hidden = false; errorMessage.textContent = error.message || 'The cropped photo could not be saved. Please try another image.';
                        draw(); confirm.focus();
                    }
                    try {
                        canvas.toBlob(blob => {
                            if (closed) return;
                            try {
                                if (!blob || !TYPES.has(blob.type) || blob.size > MAX_BYTES) throw new Error('The cropped photo could not be saved. Please choose another image.');
                                const extension = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp' }[blob.type];
                                const stem = (file.name || 'profile').replace(/\.[^./]+$/u, '').replace(/[<>:"/\\|?*\u0000-\u001f]/gu, '_').slice(0, 80) || 'profile';
                                finish(new File([blob], `${stem}-cropped.${extension}`, { type: blob.type, lastModified: Date.now() }));
                            } catch (error) { exportFailed(error); }
                        }, file.type, .92);
                    } catch (error) { exportFailed(error); }
                });
                draw(); canvas.focus({ preventScroll: true });
            }
        });
    }

    window.MathVerseAvatarCropper = Object.freeze({ crop, geometry: Object.freeze({ bounds, pan }) });
}());
