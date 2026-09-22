(function () {
    'use strict';

    function createGestureTracker() {
        var current = null;
        var dragging = false;

        return {
            down: function (details) {
                if (current !== null) return false;
                current = details;
                dragging = false;
                return true;
            },
            activate: function (pointerId) {
                if (!current || current.id !== pointerId) return false;
                dragging = true;
                return true;
            },
            move: function (pointerId, x, y) {
                if (!current || current.id !== pointerId) return 'ignore';
                if (dragging) return 'drag';
                var distance = Math.hypot(x - current.x, y - current.y);
                if (current.type === 'mouse' && distance >= 6) {
                    dragging = true;
                    return 'start';
                }
                if (current.type !== 'mouse' && distance > 10) {
                    current = null;
                    return 'scroll';
                }
                return 'pending';
            },
            current: function () { return current; },
            isDragging: function () { return dragging; },
            reset: function () { current = null; dragging = false; }
        };
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { createGestureTracker: createGestureTracker };
        return;
    }

    var lessons = Array.prototype.slice.call(document.querySelectorAll('[data-lesson-id]'));
    var editor = document.getElementById('lesson-editor');
    var picker = document.getElementById('duplicate-picker');
    var overwriteDialog = document.getElementById('overwrite-dialog');
    var status = document.querySelector('[data-copy-status]');
    var csrf = editor ? editor.querySelector('[name=csrf_token]') : null;
    var weekDate = document.querySelector('[data-week-date]');
    var source = null;
    var target = null;
    var busy = false;
    var gestures = createGestureTracker();
    var longPressTimer = null;
    var suppressClick = false;
    var ghost = null;
    var over = null;

    function setStatus(message, error) {
        if (!status) return;
        status.textContent = message || '';
        status.classList.toggle('error', !!error);
    }

    function openEditor(link, event) {
        if (suppressClick) {
            event.preventDefault();
            return;
        }
        if (!editor || !editor.showModal) return;
        event.preventDefault();
        editor.querySelector('input[name=occurrence_id]').value = link.dataset.lessonId;
        editor.querySelector('input[name=date]').value = link.dataset.date;
        editor.querySelector('h2').textContent = 'Edit lesson planning';
        var classLink = document.getElementById('lesson-class-link');
        classLink.textContent = link.dataset.class;
        classLink.href = '/teacher/class?class_id=' + encodeURIComponent(link.dataset.classId);
        editor.querySelector('.lesson-context').lastChild.textContent = ' · ' + link.dataset.room + ' | ' + link.dataset.date + ' · ' + link.dataset.period;
        editor.querySelector('[name=lesson_outline]').value = link.dataset.outline;
        editor.querySelector('[name=requisitions]').value = link.dataset.requisitions;
        editor.querySelector('[name=risk_assessment]').value = link.dataset.risk;
        editor.querySelector('[data-nothing-required]').checked = link.dataset.state === 'nothing_required';
        editor.showModal();
    }

    function clearLongPress() {
        if (longPressTimer !== null) window.clearTimeout(longPressTimer);
        longPressTimer = null;
    }

    function beginDrag(link, x, y) {
        if (busy || !gestures.isDragging()) return;
        clearLongPress();
        source = link;
        source.classList.add('copy-source');
        document.body.classList.add('lesson-copy-active');
        lessons.forEach(function (lesson) {
            if (lesson !== source) lesson.classList.add('copy-target');
        });
        ghost = document.createElement('div');
        ghost.className = 'lesson-copy-ghost';
        ghost.textContent = 'Copy ' + source.dataset.class;
        document.body.appendChild(ghost);
        moveGhost(x, y);
        setStatus('Copying lesson planning. Drop onto another lesson.', false);
        var selection = window.getSelection ? window.getSelection() : null;
        if (selection) selection.removeAllRanges();
        var pointer = gestures.current();
        if (pointer && source.setPointerCapture) {
            try { source.setPointerCapture(pointer.id); } catch (ignore) {}
        }
    }

    function moveGhost(x, y) {
        if (!ghost) return;
        ghost.style.left = (x + 14) + 'px';
        ghost.style.top = (y + 14) + 'px';
    }

    function targetAt(x, y) {
        var element = document.elementFromPoint(x, y);
        var lesson = element && element.closest ? element.closest('[data-lesson-id]') : null;
        return lesson && lesson !== source ? lesson : null;
    }

    function setOver(lesson) {
        if (over === lesson) return;
        if (over) over.classList.remove('copy-over');
        over = lesson;
        if (over) over.classList.add('copy-over');
    }

    function endDrag(cancelled) {
        clearLongPress();
        if (!gestures.isDragging()) {
            gestures.reset();
            return;
        }
        var droppedOn = cancelled ? null : over;
        suppressClick = true;
        window.setTimeout(function () { suppressClick = false; }, 700);
        document.body.classList.remove('lesson-copy-active');
        lessons.forEach(function (lesson) { lesson.classList.remove('copy-source', 'copy-target', 'copy-over'); });
        if (ghost) ghost.remove();
        ghost = null;
        over = null;
        gestures.reset();
        if (droppedOn) prepareCopy(source, droppedOn);
        else setStatus('Copy cancelled.', false);
    }

    function prepareCopy(from, to) {
        if (busy || !from || !to || from === to) return;
        source = from;
        target = to;
        if (source.dataset.planningPopulated !== 'true') {
            setStatus('The source lesson has no planning information to copy.', true);
            return;
        }
        if (target.dataset.planningPopulated === 'true') {
            showOverwrite(false, target.dataset.planningRevision);
            return;
        }
        requestCopy(false, '');
    }

    function showOverwrite(changed, revision) {
        if (!overwriteDialog || !overwriteDialog.showModal || !target) return;
        overwriteDialog.dataset.targetRevision = revision || '';
        overwriteDialog.querySelector('[data-overwrite-target]').textContent = 'Target: ' + target.dataset.targetLabel;
        overwriteDialog.querySelector('[data-target-changed]').hidden = !changed;
        if (picker && picker.open) picker.close();
        if (!overwriteDialog.open) overwriteDialog.showModal();
    }

    function setBusy(value) {
        busy = value;
        document.body.classList.toggle('lesson-copy-saving', value);
        document.querySelectorAll('[data-copy-selected], [data-confirm-overwrite]').forEach(function (button) {
            button.disabled = value;
        });
    }

    function requestCopy(overwrite, revision) {
        if (busy || !source || !target || !csrf || !weekDate) return;
        setBusy(true);
        setStatus('Copying planning…', false);
        var data = new FormData();
        data.set('action', 'duplicate');
        data.set('_async', '1');
        data.set('csrf_token', csrf.value);
        data.set('date', weekDate.textContent);
        data.set('source_occurrence_id', source.dataset.lessonId);
        data.set('target_occurrence_id', target.dataset.lessonId);
        if (overwrite) data.set('overwrite', 'yes');
        if (revision) data.set('target_revision', revision);
        fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (response) {
            var type = response.headers.get('content-type') || '';
            if (response.redirected || type.indexOf('application/json') === -1) throw new Error('Your session expired. Please sign in and try again.');
            return response.json().then(function (payload) { return { response: response, payload: payload }; });
        }).then(function (result) {
            var payload = result.payload;
            if (payload.status === 'confirmation_required') {
                showOverwrite(!!payload.target_changed, payload.target_revision);
                setStatus(payload.target_changed ? 'The target changed. Confirm again before overwriting it.' : 'Confirmation is required before overwriting this lesson.', true);
                return;
            }
            if (!result.response.ok || !payload.ok) throw new Error(payload.message || 'The planning could not be copied. Please try again.');
            updateTarget(payload);
            if (overwriteDialog && overwriteDialog.open) overwriteDialog.close();
            if (picker && picker.open) picker.close();
            setStatus('Planning copied to ' + target.dataset.targetLabel + '.', false);
        }).catch(function (error) {
            setStatus(error && error.message ? error.message : 'The planning could not be copied. Please try again.', true);
        }).finally(function () {
            setBusy(false);
        });
    }

    function updateTarget(payload) {
        target.dataset.outline = payload.outline || '';
        target.dataset.requisitions = payload.requisitions || '';
        target.dataset.risk = payload.risk || '';
        target.dataset.state = payload.state || 'not_completed';
        target.dataset.planningPopulated = payload.populated ? 'true' : 'false';
        target.dataset.planningRevision = payload.target_revision || '';
        var body = target.querySelector('.lesson-body');
        if (!body) return;
        body.textContent = '';
        if (target.dataset.requisitions === '') {
            var empty = document.createElement('span');
            empty.className = 'muted';
            empty.textContent = 'No requisitions entered';
            body.appendChild(empty);
        } else {
            body.textContent = target.dataset.requisitions;
        }
    }

    lessons.forEach(function (link) {
        link.draggable = false;
        link.addEventListener('dragstart', function (event) { event.preventDefault(); });
        link.addEventListener('contextmenu', function (event) { event.preventDefault(); });
        link.addEventListener('selectstart', function (event) { event.preventDefault(); });
        link.addEventListener('click', function (event) { openEditor(link, event); });
        link.addEventListener('pointerdown', function (event) {
            if (busy || event.button !== 0 || !gestures.down({ id: event.pointerId, type: event.pointerType, x: event.clientX, y: event.clientY, link: link })) return;
            if (event.pointerType !== 'mouse') {
                longPressTimer = window.setTimeout(function () {
                    var pointer = gestures.current();
                    if (pointer && gestures.activate(event.pointerId)) beginDrag(link, pointer.x, pointer.y);
                }, 500);
            }
        });
    });

    document.addEventListener('pointermove', function (event) {
        var action = gestures.move(event.pointerId, event.clientX, event.clientY);
        if (action === 'ignore') return;
        if (action === 'start') {
            var pointer = gestures.current();
            beginDrag(pointer.link, event.clientX, event.clientY);
        }
        if (action === 'scroll') {
            clearLongPress();
            return;
        }
        if (!gestures.isDragging()) return;
        event.preventDefault();
        moveGhost(event.clientX, event.clientY);
        setOver(targetAt(event.clientX, event.clientY));
    }, { passive: false });
    document.addEventListener('pointerup', function (event) {
        var pointer = gestures.current();
        if (!pointer || pointer.id !== event.pointerId) return;
        if (gestures.isDragging()) event.preventDefault();
        endDrag(false);
    }, { passive: false });
    document.addEventListener('pointercancel', function (event) {
        var pointer = gestures.current();
        if (pointer && pointer.id === event.pointerId) endDrag(true);
    });
    document.addEventListener('touchmove', function (event) {
        if (gestures.isDragging() && event.cancelable) event.preventDefault();
    }, { passive: false });

    document.querySelectorAll('[data-close]').forEach(function (button) {
        button.addEventListener('click', function () {
            var dialog = button.closest('dialog');
            if (dialog && dialog.open && !busy) dialog.close();
        });
    });
    document.querySelectorAll('[data-nothing-required]').forEach(function (box) {
        box.addEventListener('change', function () {
            var field = box.closest('label').querySelector('[name=requisitions]');
            if (box.checked) field.value = 'Nothing required';
            else if (field.value === 'Nothing required') field.value = '';
        });
    });
    var duplicateButton = document.querySelector('[data-open-duplicate]');
    if (duplicateButton) duplicateButton.addEventListener('click', function () {
        var id = editor.querySelector('[name=occurrence_id]').value;
        source = lessons.find(function (lesson) { return lesson.dataset.lessonId === id; }) || null;
        if (!source || !picker || !picker.showModal) return;
        var dirty = editor.querySelector('[name=lesson_outline]').value !== source.dataset.outline
            || editor.querySelector('[name=requisitions]').value !== source.dataset.requisitions
            || editor.querySelector('[name=risk_assessment]').value !== source.dataset.risk;
        if (dirty) {
            setStatus('Save or discard your lesson changes before duplicating its saved planning.', true);
            return;
        }
        var select = picker.querySelector('[data-duplicate-target]');
        select.textContent = '';
        lessons.forEach(function (lesson) {
            if (lesson === source) return;
            var option = document.createElement('option');
            option.value = lesson.dataset.lessonId;
            option.textContent = lesson.dataset.targetLabel;
            select.appendChild(option);
        });
        editor.close();
        picker.showModal();
        select.focus();
    });
    var copySelected = document.querySelector('[data-copy-selected]');
    if (copySelected) copySelected.addEventListener('click', function () {
        var select = picker.querySelector('[data-duplicate-target]');
        target = lessons.find(function (lesson) { return lesson.dataset.lessonId === select.value; }) || null;
        prepareCopy(source, target);
    });
    var confirmOverwrite = document.querySelector('[data-confirm-overwrite]');
    if (confirmOverwrite) confirmOverwrite.addEventListener('click', function () {
        requestCopy(true, overwriteDialog.dataset.targetRevision || '');
    });
}());
