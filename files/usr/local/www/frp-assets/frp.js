(function () {
    'use strict';
    function statusPanel(panel) {
        if (panel.dataset.frpInitialized) return;
        panel.dataset.frpInitialized = 'true';
        async function refresh() {
            if (!document.contains(panel)) return;
            if (!document.hidden) {
                const controller = new AbortController();
                const timeout = setTimeout(() => controller.abort(), 5000);
                try {
                    const response = await fetch('/frp_client.php?ajax=status', {cache: 'no-store', signal: controller.signal});
                    if (!response.ok) throw new Error('Status unavailable');
                    const data = await response.json();
                    const state = panel.querySelector('[data-frp-state]');
                    state.textContent = data.state;
                    state.className = 'label label-' + (data.healthy === true ? 'success' : (data.running ? 'warning' : 'default'));
                    const table = panel.querySelector('[data-frp-tunnel-table]');
                    if (table) table.hidden = !data.proxies.length;
                    panel.querySelector('[data-frp-detail]').textContent = data.detail;
                    panel.querySelector('[data-frp-updated]').textContent = new Date(data.updated * 1000).toLocaleTimeString();
                    const body = panel.querySelector('[data-frp-proxies]');
                    body.replaceChildren();
                    data.proxies.forEach(proxy => {
                        const tr = document.createElement('tr');
                        [proxy.name, proxy.type, proxy.local_addr, proxy.remote_addr, proxy.err || proxy.status].forEach(value => {
                            const td = document.createElement('td'); td.textContent = value; tr.appendChild(td);
                        });
                        body.appendChild(tr);
                    });
                    if (!data.proxies.length) {
                        const tr = document.createElement('tr'), td = document.createElement('td');
                        td.colSpan = 5; td.textContent = data.state; tr.appendChild(td); body.appendChild(tr);
                    }
                } catch (error) {
                    const state = panel.querySelector('[data-frp-state]');
                    state.textContent = 'Status unavailable';
                    state.className = 'label label-warning';
                    panel.querySelector('[data-frp-detail]').textContent = 'Status could not be refreshed. Check your connection or sign in again.';
                } finally { clearTimeout(timeout); }
            }
            setTimeout(refresh, 10000);
        }
        refresh();
    }
    document.querySelectorAll('[data-frp-status]').forEach(statusPanel);
    const form = document.getElementById('frp-form');
    if (!form || form.dataset.frpInitialized) return;
    form.dataset.frpInitialized = 'true';
    const source = document.getElementById('toml'), area = document.getElementById('frp-editor');
    const toggle = document.getElementById('frp-editor-toggle');
    let editor = null, submitting = false;
    function values() {
        return JSON.stringify(Array.from(form.elements).filter(e => e.name && !e.name.startsWith('__csrf') && e.type !== 'submit')
            .map(e => [e.name, e.type === 'checkbox' ? e.checked : e.value]));
    }
    const initial = values();
    const pending = form.dataset.frpUnsaved === 'true';
    function changed() {
        const dirty = pending || values() !== initial;
        document.getElementById('frp-dirty').hidden = !dirty;
        const review = document.getElementById('frp-review');
        if (review && values() !== initial) review.hidden = true;
    }
    if (window.ace) {
        ace.config.set('basePath', '/frp-assets/ace');
        area.hidden = false;
        editor = ace.edit(area);
        editor.setTheme('ace/theme/textmate');
        editor.session.setMode('ace/mode/toml');
        editor.session.setUseWorker(false);
        editor.setOptions({showPrintMargin: false, fontSize: '14px', useSoftTabs: true, tabSize: 4});
        editor.setValue(source.value, -1);
        source.hidden = true;
        toggle.hidden = false;
        if (window.ResizeObserver) new ResizeObserver(() => editor.resize()).observe(area);
        editor.session.on('change', () => { source.value = editor.getValue(); changed(); });
    }
    toggle.addEventListener('click', () => {
        if (!editor) return;
        if (source.hidden) {
            source.hidden = false; area.hidden = true;
            toggle.textContent = toggle.dataset.highlightLabel; source.focus();
        } else {
            editor.setValue(source.value, -1); source.hidden = true; area.hidden = false;
            toggle.textContent = toggle.dataset.plainLabel; editor.resize(); editor.focus();
        }
    });
    form.addEventListener('input', changed);
    form.addEventListener('change', changed);
    form.addEventListener('submit', () => { submitting = true; });
    const reviewed = document.getElementById('frp-review');
    if (reviewed) reviewed.scrollIntoView({block: 'start'});
    changed();
    window.addEventListener('beforeunload', event => {
        if (!submitting && (pending || values() !== initial)) { event.preventDefault(); event.returnValue = ''; }
    });
})();
