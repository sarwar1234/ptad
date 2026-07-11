function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
}

function renderModuleHeader(agreement, tariffLineCount, memberCount) {
    const statusClass = agreement.status === 'suspended' ? 'suspended' : 'in-force';
    const statusLabel = agreement.status === 'suspended' ? 'Suspended' : 'In Force';

    return `<div class="ptad-module-header">
        <div class="row align-items-start g-4">
            <div class="col-lg-8">
                <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                    <span class="ptad-agreement-type">${escapeHtml(agreement.type)}</span>
                    <span class="ptad-module-status ${statusClass}"><span class="dot"></span> ${statusLabel}</span>
                </div>
                <h1 class="h3 mb-1">${escapeHtml(agreement.short_name)}</h1>
                <p class="mb-2">${escapeHtml(agreement.full_name)}</p>
                <p class="mb-0">${escapeHtml(agreement.summary)}</p>
            </div>
            <div class="col-lg-4">
                <div class="row g-2">
                    <div class="col-4">
                        <div class="ptad-header-ministat">
                            <div class="num">${tariffLineCount.toLocaleString()}</div>
                            <div class="label">Lines</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="ptad-header-ministat">
                            <div class="num">${memberCount}</div>
                            <div class="label">Members</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="ptad-header-ministat">
                            <div class="num" style="font-size:0.95rem;">${escapeHtml(agreement.coverage)}</div>
                            <div class="label">Coverage</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="small mt-3" style="color:rgba(255,255,255,0.55);">Last updated: ${agreement.last_updated ? escapeHtml(agreement.last_updated) : 'unknown'}</div>
    </div>`;
}

function renderModuleGuidance(moduleGuidance) {
    if (!moduleGuidance || !moduleGuidance.m2_notes || moduleGuidance.m2_notes.length === 0) return '';
    const notes = moduleGuidance.m2_notes.map(n => {
        const isBlocking = n.priority === 1;
        return `<div class="ptad-guidance-note ${isBlocking ? 'is-blocking' : ''}">
            <strong>${escapeHtml(n.group)}</strong> — ${escapeHtml(n.text)}
        </div>`;
    }).join('');
    return `<div class="ptad-notes-panel">
        <div class="ptad-notes-panel-title">&#9888;&#65039; Important Notes for This Arrangement</div>
        ${notes}
    </div>`;
}

function renderMembers(members) {
    if (!members || members.length === 0) return '';
    const cards = members.map(m => {
        const notImplemented = m.member_status !== 'implemented';
        return `<div class="col-6 col-md-4 col-lg-3">
            <div class="ptad-member-card ${notImplemented ? 'not-implemented' : ''}">
                <div class="country">${flagImg(m.iso2)} ${escapeHtml(m.country)}</div>
                <div class="role">${escapeHtml(m.role || 'party')}</div>
                ${notImplemented ? `<div class="status-flag">${escapeHtml(m.member_status.replace('_', ' '))}</div>` : ''}
            </div>
        </div>`;
    }).join('');
    return `<h2 class="ptad-section-title-row">Member Countries</h2><div class="row g-2">${cards}</div>`;
}

function renderContentSections(sections) {
    if (!sections || sections.length === 0) return '';

    // Skip "member_overview" entirely here — its content (per-member
    // status notes like "Concession list available in the Tariff
    // Section" / "D-8 PTA not yet implemented...") duplicates what the
    // dedicated Member Cards grid already shows more clearly. Showing
    // both created confusing, redundant text on the page.
    const filtered = sections.filter(s => s.section_type !== 'member_overview');
    if (filtered.length === 0) return '';

    const groups = [];
    const seen = {};
    for (const s of filtered) {
        if (!seen[s.section_name]) {
            seen[s.section_name] = [];
            groups.push({ name: s.section_name, items: seen[s.section_name] });
        }
        seen[s.section_name].push(s);
    }

    const accordionItems = groups.map((g, idx) => {
        const collapseId = `section-collapse-${idx}`;
        const items = g.items.map(item => {
            let extraFields = '';
            if (item.fields) {
                try {
                    const parsed = JSON.parse(item.fields);
                    extraFields = '<div class="ptad-content-item-fields">' + Object.entries(parsed).map(([k, v]) =>
                        `<div class="field-row"><strong>${escapeHtml(k)}:</strong> ${escapeHtml(v)}</div>`
                    ).join('') + '</div>';
                } catch (e) { /* fields not valid JSON — skip rather than break rendering */ }
            }
            return `<div class="ptad-content-item">
                ${item.title ? `<div class="ptad-content-item-title">${escapeHtml(item.title)}</div>` : ''}
                ${item.body ? `<div class="ptad-content-item-body">${escapeHtml(item.body)}</div>` : ''}
                ${extraFields}
            </div>`;
        }).join('');

        return `<div class="accordion-item ptad-accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button ${idx === 0 ? '' : 'collapsed'}" type="button" data-bs-toggle="collapse" data-bs-target="#${collapseId}">
                    ${escapeHtml(g.name)} <span class="count-badge">${g.items.length}</span>
                </button>
            </h2>
            <div id="${collapseId}" class="accordion-collapse collapse ${idx === 0 ? 'show' : ''}" data-bs-parent="#sectionsAccordion">
                <div class="accordion-body">${items}</div>
            </div>
        </div>`;
    }).join('');

    return `<h2 class="ptad-section-title-row">Agreement Details</h2>
        <div class="accordion" id="sectionsAccordion">${accordionItems}</div>`;
}

function renderVerificationLinks(links) {
    if (!links || links.length === 0) return '';
    const cards = links.map(l => `
        <div class="col-md-6 col-lg-4">
            <a href="${escapeHtml(l.url)}" target="_blank" rel="noopener noreferrer" class="ptad-link-card">
                <div class="icon">&#128279;</div>
                <div class="name">${escapeHtml(l.resource_name || l.url)}</div>
                ${l.purpose ? `<div class="purpose">${escapeHtml(l.purpose)}</div>` : ''}
                ${l.official_source ? `<div class="purpose">Source: ${escapeHtml(l.official_source)}</div>` : ''}
            </a>
        </div>
    `).join('');
    return `<h2 class="ptad-section-title-row">Official Verification Links</h2><div class="row g-3">${cards}</div>`;
}

async function loadModule(code) {
    const container = document.getElementById('moduleContainer');

    try {
        const res = await fetch(`/api/modules/${encodeURIComponent(code)}`);
        const data = await res.json();

        if (!data.success) {
            container.innerHTML = `<div class="alert alert-warning">${escapeHtml(data.error.message)}</div>`;
            return;
        }

        const d = data.data;
        container.innerHTML =
            renderModuleHeader(d.agreement, d.tariff_line_count, d.members.length) +
            renderModuleGuidance(d.module_guidance) +
            renderMembers(d.members) +
            renderContentSections(d.content_sections) +
            renderVerificationLinks(d.verification_links);

    } catch (e) {
        container.innerHTML = '<div class="alert alert-danger">Something went wrong loading this module. Please try again.</div>';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const root = document.getElementById('modulePageData');
    const code = root.dataset.code;
    if (code) {
        loadModule(code);
    }
});
