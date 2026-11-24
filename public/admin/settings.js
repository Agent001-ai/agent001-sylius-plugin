(function () {
    'use strict';

    const script = document.currentScript;
    const progressUrl = script.dataset.progressUrl;
    const labels = JSON.parse(script.dataset.labels || '{}');
    const badges = {
        queued: 'bg-secondary-lt',
        reading: 'bg-blue-lt',
        indexing: 'bg-blue-lt',
        success: 'bg-green-lt',
        failed: 'bg-red-lt',
    };
    const alerts = { success: 'alert-success', failed: 'alert-danger' };
    const busyStatuses = ['reading', 'indexing'];
    let wasRunning = false;

    document.querySelectorAll('form[data-agent001-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.agent001Confirm)) {
                event.preventDefault();
            }
        });
    });

    const nodes = (attribute, code) => document.querySelectorAll(
        '[data-agent001-' + attribute + '="' + CSS.escape(code) + '"]',
    );

    const setBusy = (button, busy) => {
        button.disabled = busy;
        button.querySelector('[data-agent001-sync-spinner]')?.classList.toggle('d-none', !busy);
        button.querySelector('[data-agent001-sync-icon]')?.classList.toggle('d-none', busy);
        const label = button.querySelector('[data-agent001-sync-label]');
        if (label) {
            label.textContent = busy ? button.dataset.labelBusy : button.dataset.labelIdle;
        }
    };

    document.querySelectorAll('form[data-agent001-catalog]').forEach((form) => {
        form.addEventListener('submit', () => {
            form.querySelectorAll('[data-agent001-sync]').forEach((button) => {
                window.setTimeout(() => setBusy(button, true), 0);
            });
        });
    });

    document.querySelectorAll('[data-agent001-taxon-group]').forEach((group) => {
        const boxes = Array.from(group.querySelectorAll('input[type="checkbox"]'));
        const counter = group.querySelector('[data-agent001-taxon-count]');
        const update = () => {
            if (!counter) {
                return;
            }
            const selected = boxes.filter((box) => box.checked).length;
            counter.textContent = counter.dataset.template
                .replace('%selected%', String(selected))
                .replace('%total%', String(boxes.length));
        };

        group.querySelectorAll('[data-agent001-select]').forEach((button) => {
            button.addEventListener('click', () => {
                const checked = button.dataset.agent001Select === 'all';
                boxes.forEach((box) => {
                    box.checked = checked;
                });
                update();
            });
        });
        boxes.forEach((box) => box.addEventListener('change', update));
    });

    const render = (code, state) => {
        nodes('status', code).forEach((node) => {
            node.textContent = labels[state.status] || '-';
        });
        nodes('badge', code).forEach((node) => {
            node.classList.remove(...new Set(Object.values(badges)));
            node.classList.add(badges[state.status] || 'bg-secondary-lt');
        });
        nodes('spinner', code).forEach((node) => {
            node.classList.toggle('d-none', !busyStatuses.includes(state.status));
        });
        nodes('error', code).forEach((node) => {
            const failed = state.status === 'failed';
            node.textContent = failed ? state.message : '';
            node.classList.toggle('d-none', !failed);
        });
        nodes('count', code).forEach((node) => {
            node.textContent = String(state.count);
        });
        nodes('message', code).forEach((node) => {
            node.textContent = state.message;
        });
        nodes('alert', code).forEach((node) => {
            node.classList.remove('alert-info', ...Object.values(alerts));
            node.classList.add(alerts[state.status] || 'alert-info');
            node.classList.toggle('d-none', !state.message);
            const tone = alerts[state.status] ? state.status : 'info';
            node.querySelectorAll('[data-agent001-alert-icon]').forEach((icon) => {
                icon.classList.toggle('d-none', icon.dataset.agent001AlertIcon !== tone);
            });
        });
        nodes('sync', code).forEach((button) => setBusy(button, state.running));
    };

    async function poll() {
        const response = await fetch(progressUrl, { headers: { Accept: 'application/json' } });
        if (!response.ok) {
            return;
        }

        const progress = await response.json();
        let running = false;
        Object.entries(progress).forEach(([code, state]) => {
            render(code, state);
            running = running || state.running;
        });

        if (running) {
            wasRunning = true;
            window.setTimeout(poll, 5000);
        } else if (wasRunning) {
            window.location.reload();
        }
    }

    if (progressUrl) {
        poll();
    }
})();
