<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Ptad\Config;

Config::load();

$mode = ($_GET['mode'] ?? 'hs_code') === 'description' ? 'description' : 'hs_code';
$query = trim((string) ($_GET['q'] ?? ''));

$pageTitle = $query !== '' ? "Results for \"{$query}\"" : 'Search';
$activeNav = 'search';
include __DIR__ . '/../partials/head.php';
?>

<div class="container py-4">
    <form action="/search" method="get" class="d-flex gap-2 mb-4" id="searchForm">
        <input type="hidden" name="mode" id="searchMode" value="<?= htmlspecialchars($mode) ?>">
        <input
            type="text"
            name="q"
            id="searchInput"
            class="form-control ptad-search-input"
            value="<?= htmlspecialchars($query) ?>"
            placeholder="Search HS code or product name..."
            autocomplete="off"
        >
        <button type="submit" class="btn btn-ptad-primary text-nowrap">Search</button>
    </form>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div id="resultMeta" class="text-muted small"></div>
        <div class="d-flex align-items-center gap-2">
            <label for="pageSizeSelect" class="small text-muted mb-0">Results per page</label>
            <select id="pageSizeSelect" class="form-select form-select-sm" style="width:auto;">
                <option value="10">10</option>
                <option value="50">50</option>
                <option value="100">100</option>
                <option value="500">500</option>
            </select>
        </div>
    </div>
    <div id="resultsContainer">
        <div class="ptad-skeleton" style="height:90px;" class="mb-3"></div>
    </div>
    <div id="paginationContainer" class="d-flex justify-content-center align-items-center flex-wrap gap-2 mt-4"></div>
    <div id="searchPageData" data-initial-query="<?= htmlspecialchars($query) ?>" data-initial-mode="<?= htmlspecialchars($mode) ?>" style="display:none;"></div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<script src="/assets/js/flags.js"></script>
<script src="/assets/js/search.js"></script>
