function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
}

let allModules = [];
let activeType = 'all';

function renderModules() {
    const container = document.getElementById('allModulesContainer');
    const filtered = activeType === 'all' ? allModules : allModules.filter(m => m.type === activeType);

    if (filtered.length === 0) {
        container.innerHTML = '<p class="text-muted">No arrangements match this filter.</p>';
        return;
    }

    container.innerHTML = filtered.map(m => {
        const statusClass = m.status === 'suspended' ? 'suspended' : 'in-force';
        const statusLabel = m.status === 'suspended' ? 'Suspended' : 'In Force';
        return `<a href="/modules/${encodeURIComponent(m.code)}" class="ptad-module-row">
            <div>
                <span class="ptad-agreement-type">${escapeHtml(m.type)}</span>
                <span class="ptad-module-status ${statusClass}" style="margin-left:0.5rem;"><span class="dot"></span> ${statusLabel}</span>
                <div class="ptad-agreement-name mt-1">${escapeHtml(m.short_name)}</div>
                <div class="text-muted small">${escapeHtml(m.summary)}</div>
            </div>
            <div class="lines-count">${m.tariff_line_count.toLocaleString()} lines</div>
        </a>`;
    }).join('');
}

async function loadAllModules() {
    const container = document.getElementById('allModulesContainer');
    try {
        const res = await fetch('/api/modules');
        const data = await res.json();
        if (!data.success) {
            container.innerHTML = `<div class="alert alert-warning">${escapeHtml(data.error.message)}</div>`;
            return;
        }
        allModules = data.data;
        renderModules();
    } catch (e) {
        container.innerHTML = '<div class="alert alert-danger">Something went wrong. Please try again.</div>';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    loadAllModules();

    document.getElementById('typeFilters').addEventListener('click', function (e) {
        const btn = e.target.closest('button[data-type]');
        if (!btn) return;

        document.querySelectorAll('#typeFilters button').forEach(b => {
            b.classList.remove('btn-ptad-primary');
            b.classList.add('btn-outline-secondary');
        });
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-ptad-primary');

        activeType = btn.dataset.type;
        renderModules();
    });
});
