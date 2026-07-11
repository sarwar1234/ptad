function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
}

let allCountries = [];
let activeCountryName = null;

function renderCountryList(countries, filterText) {
    const listEl = document.getElementById('countryList');
    const filtered = filterText
        ? countries.filter(c => c.name.toLowerCase().includes(filterText.toLowerCase()))
        : countries;

    if (filtered.length === 0) {
        listEl.innerHTML = '<div class="p-3 text-muted small">No countries match.</div>';
        return;
    }

    listEl.innerHTML = filtered.map(c => `
        <div class="ptad-country-item ${c.name === activeCountryName ? 'active' : ''}" data-country="${escapeHtml(c.name)}">
            <span>${flagImg(c.iso2)} ${escapeHtml(c.name)}</span>
            ${c.is_ldc ? '<span class="ldc-badge">LDC</span>' : ''}
        </div>
    `).join('');
}

function renderAgreementCard(a) {
    const notImplemented = a.member_status !== 'implemented';
    return `<div class="ptad-agreement-card ${notImplemented ? 'not-implemented' : ''}">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <span class="ptad-agreement-name">${escapeHtml(a.agreement_name)}</span>
                <span class="ptad-agreement-type">${escapeHtml(a.agreement_type)}</span>
                <div class="text-muted small mt-1">
                    Role: ${escapeHtml(a.role || 'party')}
                    ${a.member_ceiling_pct ? ' · Ceiling: ' + escapeHtml(String(a.member_ceiling_pct)) + '%' : ''}
                </div>
            </div>
            <a href="/modules/${encodeURIComponent(a.agreement_code)}" class="btn btn-outline-secondary btn-sm">View Module</a>
        </div>
        ${a.guidance_note ? `<div class="ptad-guidance-note is-blocking mt-2">${escapeHtml(a.guidance_note)}</div>` : ''}
    </div>`;
}

async function loadCountryDetail(countryName) {
    activeCountryName = countryName;
    renderCountryList(allCountries, document.getElementById('countryFilter').value);

    const detailEl = document.getElementById('countryDetail');
    detailEl.innerHTML = '<div class="ptad-skeleton" style="height:200px;"></div>';

    try {
        const res = await fetch(`/api/countries/${encodeURIComponent(countryName)}/agreements`);
        const data = await res.json();

        if (!data.success) {
            detailEl.innerHTML = `<div class="alert alert-warning">${escapeHtml(data.error.message)}</div>`;
            return;
        }

        const d = data.data;
        const header = `<div class="ptad-country-header">
            <span>${flagImg(d.country.iso2, 32)}</span>
            <h2 class="h4 mb-0">${escapeHtml(d.country.name)}</h2>
            ${d.country.is_ldc ? '<span class="ldc-badge" style="font-size:0.75rem;">Least Developed Country</span>' : ''}
        </div>`;

        if (d.agreements.length === 0) {
            detailEl.innerHTML = header + '<p class="text-muted">No trade arrangements found for this country.</p>';
            return;
        }

        detailEl.innerHTML = header + d.agreements.map(renderAgreementCard).join('');
    } catch (e) {
        detailEl.innerHTML = '<div class="alert alert-danger">Something went wrong. Please try again.</div>';
    }
}

async function loadCountryList() {
    const listEl = document.getElementById('countryList');
    try {
        const res = await fetch('/api/countries');
        const data = await res.json();
        if (!data.success) {
            listEl.innerHTML = '<div class="p-3 text-danger small">Could not load countries.</div>';
            return;
        }
        allCountries = data.data;
        renderCountryList(allCountries, '');
    } catch (e) {
        listEl.innerHTML = '<div class="p-3 text-danger small">Something went wrong.</div>';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    loadCountryList();

    document.getElementById('countryFilter').addEventListener('input', function () {
        renderCountryList(allCountries, this.value);
    });

    // Event delegation for country clicks (list is re-rendered often,
    // so binding once on the parent avoids re-attaching listeners).
    document.getElementById('countryList').addEventListener('click', function (e) {
        const item = e.target.closest('.ptad-country-item');
        if (item) {
            loadCountryDetail(item.dataset.country);
        }
    });
});
