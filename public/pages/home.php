<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Ptad\Config;
use Ptad\Database\Connection;

Config::load();
$pdo = Connection::get();

$agreementCount = (int) $pdo->query("SELECT COUNT(*) FROM agreements")->fetchColumn();
$tariffLineCount = (int) $pdo->query("SELECT COUNT(*) FROM tariff_lines")->fetchColumn();
$countryCount = (int) $pdo->query("SELECT COUNT(*) FROM countries")->fetchColumn();

$pageTitle = 'Search Preferential Tariffs';
$activeNav = 'search';
include __DIR__ . '/../partials/head.php';
?>

<section class="ptad-hero">
    <div class="container">
        <div class="ptad-hero-eyebrow"><span class="dot"></span> Official TDAP Trade Intelligence Tool</div>
        <h1>Find Pakistan's preferential tariff rate for any product, in any market.</h1>
        <p class="lead">Search by HS code or product name across every trade agreement, GSP scheme, and preferential arrangement Pakistan holds — with plain-language guidance on how each rate actually applies.</p>
    </div>
</section>

<div class="container">
    <div class="ptad-search-card">
        <ul class="nav ptad-search-tabs mb-3" id="searchTabs">
            <li class="nav-item">
                <button class="nav-link active" data-mode="hs_code" type="button">HS Code</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-mode="description" type="button">Product Description</button>
            </li>
        </ul>

        <form id="searchForm" action="/search" method="get" class="d-flex gap-2 flex-column flex-md-row">
            <input type="hidden" name="mode" id="searchMode" value="hs_code">
            <input
                type="text"
                name="q"
                id="searchInput"
                class="form-control ptad-search-input flex-grow-1"
                placeholder="e.g. 0802.1200, or type a few digits"
                autocomplete="off"
                autofocus
            >
            <button type="submit" class="btn btn-ptad-primary">Search</button>
        </form>
        <div class="mt-2 small text-muted" id="searchHint">
            Enter a full or partial HS code — e.g. "0802" finds every line under that heading.
        </div>
    </div>
</div>

<div class="container">
    <div class="row g-3 ptad-stats-row">
        <div class="col-md-4">
            <div class="ptad-stat-card">
                <div class="ptad-stat-icon">&#128196;</div>
                <div class="ptad-stat-num"><?= number_format($agreementCount) ?></div>
                <div class="ptad-stat-label">Trade Arrangements</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="ptad-stat-card">
                <div class="ptad-stat-icon">&#128202;</div>
                <div class="ptad-stat-num"><?= number_format($tariffLineCount) ?>+</div>
                <div class="ptad-stat-label">Tariff Lines</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="ptad-stat-card">
                <div class="ptad-stat-icon">&#127760;</div>
                <div class="ptad-stat-num"><?= number_format($countryCount) ?></div>
                <div class="ptad-stat-label">Countries Covered</div>
            </div>
        </div>
    </div>
</div>

<section class="container py-5">
    <div class="row g-4">
        <div class="col-md-4">
            <div class="ptad-feature-card">
                <div class="ptad-feature-badge fta">FTA</div>
                <h3 class="h5">FTAs &amp; PTAs</h3>
                <p class="text-muted small mb-0">Bilateral and preferential agreements with Iran, Sri Lanka, Malaysia, China, and more — each with its own negotiated schedule.</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="ptad-feature-card">
                <div class="ptad-feature-badge gsp">GSP</div>
                <h3 class="h5">GSP Schemes</h3>
                <p class="text-muted small mb-0">Unilateral preference schemes from the EU, UK, USA, Canada, and others — including EAEU's rule-based eligibility.</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="ptad-feature-card">
                <div class="ptad-feature-badge rta">RTA</div>
                <h3 class="h5">Regional Arrangements</h3>
                <p class="text-muted small mb-0">SAFTA, SAPTA, D-8, GSTP, and PTN — multi-country frameworks with their own eligibility logic.</p>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<script src="/assets/js/home.js"></script>
