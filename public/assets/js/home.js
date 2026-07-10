document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('#searchTabs .nav-link').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('#searchTabs .nav-link').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            const mode = tab.dataset.mode;
            document.getElementById('searchMode').value = mode;
            const input = document.getElementById('searchInput');
            const hint = document.getElementById('searchHint');
            if (mode === 'description') {
                input.placeholder = 'e.g. "rice", "pneumatic tyres"';
                hint.textContent = 'Search by product name — useful for historical codes that predate the HS system.';
            } else {
                input.placeholder = 'e.g. 0802.1200, or type a few digits';
                hint.textContent = 'Enter a full or partial HS code — e.g. "0802" finds every line under that heading.';
            }
        });
    });
});
