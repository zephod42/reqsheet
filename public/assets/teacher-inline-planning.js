(function () {
    'use strict';

    var active = null;

    function resize(area) {
        area.style.height = 'auto';
        area.style.height = Math.max(area.scrollHeight, 80) + 'px';
    }

    function emptyText(cell) {
        return cell.dataset.section === 'requisitions' ? 'No requisitions entered'
            : cell.dataset.section === 'outline' ? 'No outline entered' : 'No risk assessment entered';
    }

    function showValue(cell) {
        var value = cell.dataset.savedValue || '';
        var valueNode = document.createElement('div');
        valueNode.className = 'day-lesson-value';
        if (value === '') {
            var empty = document.createElement('span');
            empty.className = 'muted';
            empty.textContent = emptyText(cell);
            valueNode.appendChild(empty);
        } else {
            valueNode.textContent = value;
        }
        cell.replaceChildren(
            Object.assign(document.createElement('div'), { className: 'day-lesson-heading' }),
            valueNode
        );
        cell.querySelector('.day-lesson-heading').textContent = cell.dataset.label;
        cell.classList.remove('is-editing');
        if (cell.dataset.feedback) {
            var feedback = document.createElement('span');
            feedback.className = 'inline-save-status';
            feedback.setAttribute('role', 'status');
            feedback.textContent = cell.dataset.feedback;
            valueNode.appendChild(feedback);
            delete cell.dataset.feedback;
            window.setTimeout(function () { if (feedback.parentNode) feedback.remove(); }, 2200);
        }
    }

    function changed(cell) {
        var area = cell.querySelector('textarea');
        var box = cell.querySelector('[name="nothing_required"]');
        var value = area ? area.value : '';
        var nothing = !!box && box.checked;
        return value !== (cell.dataset.savedValue || '') || nothing !== (cell.dataset.nothingRequired === 'true');
    }

    function close(cell) {
        if (!cell) return;
        showValue(cell);
        if (active === cell) active = null;
    }

    function open(cell) {
        if (active && active !== cell) {
            if (changed(active) && !window.confirm('Discard unsaved changes?')) return;
            close(active);
        }
        active = cell;
        cell.classList.add('is-editing');
        var section = cell.dataset.section;
        var requisitions = section === 'requisitions';
        var area = document.createElement('textarea');
        area.name = 'value';
        if (requisitions) area.className = 'requisition-editor';
        area.value = cell.dataset.savedValue || '';
        var form = document.createElement('form');
        form.method = 'post';
        form.action = cell.dataset.saveUrl || window.location.href;
        form.className = 'inline-lesson-form';
        form.innerHTML = '<label><span class="visually-hidden"></span></label><div class="form-actions"><button type="submit">Save</button><button type="button" class="secondary" data-inline-cancel>Cancel</button></div><span class="inline-save-status" role="status" aria-live="polite"></span>';
        ['date', 'class_id'].forEach(function (name) {
            var dataName = name === 'class_id' ? 'classId' : name;
            if (cell.dataset[dataName]) {
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = name;
                hidden.value = cell.dataset[dataName];
                form.appendChild(hidden);
            }
        });
        form.querySelector('.visually-hidden').textContent = cell.dataset.label;
        form.querySelector('label').insertBefore(area, form.querySelector('label').firstChild);
        if (requisitions) {
            var checkLabel = document.createElement('label');
            checkLabel.className = 'check-label';
            checkLabel.innerHTML = '<input type="checkbox" name="nothing_required" value="yes"> Nothing required';
            form.insertBefore(checkLabel, form.querySelector('.form-actions'));
            var box = checkLabel.querySelector('input');
            box.checked = cell.dataset.nothingRequired === 'true';
            if (box.checked) { area.value = 'Nothing required'; area.disabled = true; }
            box.addEventListener('change', function () {
                if (box.checked) {
                    area.dataset.previousValue = area.value === 'Nothing required' ? '' : area.value;
                    area.value = 'Nothing required';
                    area.disabled = true;
                } else {
                    area.disabled = false;
                    area.value = area.dataset.previousValue || '';
                }
                resize(area);
            });
        }
        cell.replaceChildren(Object.assign(document.createElement('div'), { className: 'day-lesson-heading' }), form);
        cell.querySelector('.day-lesson-heading').textContent = cell.dataset.label;
        area.addEventListener('input', function () { resize(area); });
        form.querySelector('[data-inline-cancel]').addEventListener('click', function () { close(cell); });
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (form.dataset.saving === 'true') return;
            form.dataset.saving = 'true';
            var button = form.querySelector('button[type="submit"]');
            var status = form.querySelector('.inline-save-status');
            button.disabled = true;
            status.textContent = 'Saving…';
            var data = new FormData(form);
            data.set('csrf_token', cell.dataset.csrf);
            data.set('occurrence_id', cell.dataset.occurrenceId);
            data.set('section', section);
            data.set('value', area.value);
            data.set('_async', '1');
            fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (response) { return response.json().then(function (payload) { if (!response.ok || !payload.ok) throw new Error(payload.message || 'The lesson section could not be saved.'); return payload; }); })
                .then(function (payload) {
                    cell.dataset.savedValue = payload.value || '';
                    cell.dataset.nothingRequired = payload.nothing_required ? 'true' : 'false';
                    cell.dataset.feedback = 'Saved';
                    active = null;
                    showValue(cell);
                })
                .catch(function (error) {
                    form.dataset.saving = 'false';
                    button.disabled = false;
                    status.textContent = error.message || 'The lesson section could not be saved. Please try again.';
                });
        });
        resize(area);
        area.focus();
        area.setSelectionRange(area.value.length, area.value.length);
    }

    document.querySelectorAll('[data-inline-planning-cell][data-section]').forEach(function (cell) {
        cell.addEventListener('click', function (event) {
            if (event.target.closest('form,button,a,textarea,input')) return;
            open(cell);
        });
        cell.addEventListener('keydown', function (event) {
            if ((event.key === 'Enter' || event.key === ' ') && !cell.classList.contains('is-editing')) {
                event.preventDefault();
                open(cell);
            }
        });
    });

    window.addEventListener('beforeunload', function (event) {
        if (active && changed(active)) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
}());
