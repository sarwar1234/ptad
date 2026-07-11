<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Ptad\Config;

Config::load();

$pageTitle = 'Country Navigator';
$activeNav = 'countries';
include __DIR__ . '/../partials/head.php';
?>

<div class="container py-4">
    <h1 class="h3 mb-2">Country Navigator</h1>
    <p class="text-muted mb-4">Select a country to see every trade arrangement it holds with Pakistan — including arrangements it hasn't yet started applying.</p>

    <div class="row g-4">
        <div class="col-md-4 col-lg-3">
            <input
                type="text"
                id="countryFilter"
                class="form-control ptad-search-input mb-2"
                style="padding:0.6rem 0.9rem; font-size:0.92rem;"
                placeholder="Filter countries..."
                autocomplete="off"
            >
            <div id="countryList" class="ptad-country-list">
                <div class="ptad-skeleton" style="height:400px;"></div>
            </div>
        </div>
        <div class="col-md-8 col-lg-9">
            <div id="countryDetail">
                <p class="text-muted">Select a country from the list to see its arrangements.</p>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<script src="/assets/js/flags.js"></script>
<script src="/assets/js/countries.js"></script>
