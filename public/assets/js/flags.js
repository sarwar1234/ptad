/**
 * PTAD — Flag Helper
 * ============================================================
 * Renders a country flag as a real image from flagcdn.com,
 * given a 2-letter ISO code. Switched from a Unicode-emoji
 * approach after confirming a real, common limitation: many
 * Windows/Chrome combinations lack a color-emoji font capable
 * of rendering flag sequences, silently falling back to showing
 * the plain 2-letter code as text instead — not something more
 * JS code can fix, since it's an OS-level font issue.
 *
 * flagcdn.com serves lightweight SVG flag images by ISO2 code
 * and is added to the CSP's img-src allowlist specifically for
 * this. Falls back to a plain document icon for the one
 * legitimate no-flag case (European Union — not a real country,
 * has no ISO2 code by design).
 */
function flagImg(iso2, size) {
    size = size || 20;
    if (!iso2 || iso2.length !== 2) {
        return `<span style="display:inline-block;width:${size}px;text-align:center;">📄</span>`;
    }
    const code = iso2.toLowerCase();
    return `<img src="https://flagcdn.com/w40/${code}.png" width="${size}" alt="${code}" style="vertical-align:-3px;border-radius:2px;box-shadow:0 0 0 1px rgba(0,0,0,0.08);" loading="lazy">`;
}
