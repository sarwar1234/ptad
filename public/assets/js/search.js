let PAGE_SIZE = 10; // default matches the dropdown's first option; changes live when the user picks a different value
let currentOffset = 0;

function escapeHtml(str) {
  const div = document.createElement("div");
  div.textContent = str ?? "";
  return div.innerHTML;
}

function guidanceNoteHtml(note) {
  const isBlocking = note.priority === 1;
  return `<div class="ptad-guidance-note ${isBlocking ? "is-blocking" : ""}">
        <strong>${escapeHtml(note.group)}</strong> — ${escapeHtml(note.text)}
    </div>`;
}

function renderNormalResult(r) {
  const guidance = r.guidance || { z3_notes: [] };
  const topNote = guidance.z3_notes[0];
  const cardClass =
    topNote && topNote.priority === 1
      ? "is-blocked"
      : topNote && topNote.priority <= 2
        ? "is-caution"
        : "";

  let rateBlock = "";
  if (r.rate_value !== null && r.rate_value !== undefined) {
    rateBlock = `<div class="text-end">
            <div class="ptad-rate-figure">${parseFloat(r.rate_value).toFixed(2)}%</div>
            <div class="ptad-rate-label">Preferential Rate</div>
        </div>`;
  } else if (r.rate_text) {
    rateBlock = `<div class="text-end">
            <div class="ptad-rate-figure" style="font-size:1.1rem;">${escapeHtml(r.rate_text)}</div>
            <div class="ptad-rate-label">Preferential Rate</div>
        </div>`;
  }

  let advantageBlock = "";
  if (r.advantage_value !== null && r.advantage_value !== undefined) {
    advantageBlock = `<span class="badge bg-light text-success border border-success-subtle ms-2">−${parseFloat(r.advantage_value).toFixed(2)}pp advantage</span>`;
  }

  const notesHtml = guidance.z3_notes.map(guidanceNoteHtml).join("");

  return `<div class="ptad-result-card ${cardClass}">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <span class="ptad-agreement-name">${escapeHtml(r.agreement_name)}</span>
                <span class="ptad-agreement-type">${escapeHtml(r.agreement_type)}</span>
                <div class="text-muted small mt-1">
                    ${escapeHtml(r.hs_code_raw)} — ${escapeHtml(r.product_desc || "")}
                    ${r.import_country ? " · Market: " + escapeHtml(r.import_country) : ""}
                    ${r.member_country ? " · Member: " + escapeHtml(r.member_country) : ""}
                </div>
                <div class="text-muted small">MFN: ${r.mfn_text ? escapeHtml(r.mfn_text) : "—"} ${advantageBlock}</div>
            </div>
            ${rateBlock}
        </div>
        ${notesHtml}
        <div class="mt-2">
            <a href="/modules/${encodeURIComponent(r.agreement_code)}" class="small">View full module &rarr;</a>
        </div>
    </div>`;
}

function renderSaftaResult(r) {
  const isExcluded = r.status === "listed_excluded";
  const cardClass = isExcluded ? "is-blocked" : "";
  let body = "";
  if (isExcluded) {
    body = (r.members || [])
      .map(
        (m) =>
          `<div class="small text-muted">${escapeHtml(m.member_country)}: ${escapeHtml(m.product_desc || "")}</div>`,
      )
      .join("");
  }
  return `<div class="ptad-result-card ${cardClass}">
        <span class="ptad-agreement-name">${escapeHtml(r.agreement_name)}</span>
        <span class="ptad-agreement-type">SAFTA</span>
        <div class="ptad-guidance-note ${isExcluded ? "is-blocking" : ""} mt-2">${escapeHtml(r.note)}</div>
        ${body}
    </div>`;
}

async function runSearch(query, mode, offset) {
  const container = document.getElementById("resultsContainer");
  const meta = document.getElementById("resultMeta");
  if (query === "") {
    container.innerHTML =
      '<p class="text-muted">Enter a search term above.</p>';
    meta.textContent = "";
    return;
  }

  container.innerHTML =
    '<div class="ptad-skeleton mb-3" style="height:90px;"></div>'.repeat(3);

  const endpoint =
    mode === "description" ? "/api/search-description" : "/api/search";
  const param = mode === "description" ? "q" : "hs_code";
  const url = `${endpoint}?${param}=${encodeURIComponent(query)}&offset=${offset}&limit=${PAGE_SIZE}`;

  try {
    const res = await fetch(url);
    const data = await res.json();

    if (!data.success) {
      container.innerHTML = `<div class="alert alert-warning">${escapeHtml(data.error.message)}</div>`;
      meta.textContent = "";
      return;
    }

    if (data.data.length === 0) {
      container.innerHTML =
        '<p class="text-muted">No results found. Try a shorter code or a different term.</p>';
      meta.textContent = "";
      return;
    }

    const total = data.meta.total_matches ?? data.data.length;
    meta.textContent = `${total} result${total === 1 ? "" : "s"} found`;

    container.innerHTML = data.data
      .map((r) =>
        r.agreement_code === "SAFTA"
          ? renderSaftaResult(r)
          : renderNormalResult(r),
      )
      .join("");

    renderPagination(data.meta);
  } catch (e) {
    container.innerHTML =
      '<div class="alert alert-danger">Something went wrong. Please try again.</div>';
  }
}

function renderPagination(meta) {
  const el = document.getElementById("paginationContainer");
  if (!meta.total_matches || meta.total_matches <= PAGE_SIZE) {
    el.innerHTML = "";
    return;
  }
  const currentPage = Math.floor((meta.offset || 0) / PAGE_SIZE) + 1;
  const totalPages = Math.ceil(meta.total_matches / PAGE_SIZE);

  // Builds the list of page numbers to show, with "…" gaps for large
  // page counts (e.g. 333 results / 10 per page = 34 pages — showing
  // all 34 buttons would be unusable, so this collapses to something
  // like: 1 … 4 5 [6] 7 8 … 34).
  function pageList() {
    const pages = new Set([
      1,
      totalPages,
      currentPage,
      currentPage - 1,
      currentPage + 1,
    ]);
    return [...pages]
      .filter((p) => p >= 1 && p <= totalPages)
      .sort((a, b) => a - b);
  }

  let html =
    '<div class="d-flex align-items-center gap-1 flex-wrap justify-content-center">';

  if (currentPage > 1) {
    html += `<button class="btn btn-outline-secondary btn-sm" data-page="${currentPage - 2}">&larr; Prev</button>`;
  }

  let lastRendered = 0;
  for (const p of pageList()) {
    if (p - lastRendered > 1) {
      html += `<span class="text-muted small px-1">&hellip;</span>`;
    }
    const isActive = p === currentPage;
    html += `<button class="btn btn-sm ${isActive ? "btn-ptad-primary" : "btn-outline-secondary"}" data-page="${p - 1}" ${isActive ? "disabled" : ""}>${p}</button>`;
    lastRendered = p;
  }

  if (currentPage < totalPages) {
    html += `<button class="btn btn-outline-secondary btn-sm" data-page="${currentPage}">Next &rarr;</button>`;
  }

  html += "</div>";
  el.innerHTML = html;
}

function goToPage(pageIndex) {
  currentOffset = pageIndex * PAGE_SIZE;
  const query = document.getElementById("searchInput").value.trim();
  const mode = document.getElementById("searchMode").value;
  runSearch(query, mode, currentOffset);
  window.scrollTo({ top: 0, behavior: "smooth" });
}

document.addEventListener("DOMContentLoaded", function () {
  // Event delegation for pagination buttons — CSP blocks inline
  // onclick="" handlers just like inline <script> blocks, so this
  // uses addEventListener + a data-page attribute instead.
  document
    .getElementById("paginationContainer")
    .addEventListener("click", function (e) {
      const btn = e.target.closest("button[data-page]");
      if (btn) {
        goToPage(parseInt(btn.dataset.page, 10));
      }
    });

  // Page-size dropdown: changes take effect immediately, no submit
  // button needed — always resets to page 1 (offset 0), since the
  // old offset would land on the wrong rows once the page size changes.
  const pageSizeSelect = document.getElementById("pageSizeSelect");
  pageSizeSelect.value = String(PAGE_SIZE);
  pageSizeSelect.addEventListener("change", function () {
    PAGE_SIZE = parseInt(this.value, 10);
    currentOffset = 0;
    const query = document.getElementById("searchInput").value.trim();
    const mode = document.getElementById("searchMode").value;
    runSearch(query, mode, 0);
  });

  const root = document.getElementById("searchPageData");
  const initialQuery = root.dataset.initialQuery;
  const initialMode = root.dataset.initialMode;

  if (initialQuery) {
    runSearch(initialQuery, initialMode, 0);
  } else {
    document.getElementById("resultsContainer").innerHTML =
      '<p class="text-muted">Enter a search term above.</p>';
  }
});
