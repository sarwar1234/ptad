<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Ptad\Config;

Config::load();

$pageTitle = 'All Arrangements';
$activeNav = 'modules';
include __DIR__ . '/../partials/head.php';
?>

<div class="container py-4">
    <h1 class="h3 mb-2">All Trade Arrangements</h1>
    <p class="text-muted mb-4">Every FTA, PTA, GSP scheme, and regional arrangement Pakistan currently holds.</p>

    <div class="d-flex gap-2 mb-4 flex-wrap" id="typeFilters">
        <button class="btn btn-sm btn-ptad-primary" data-type="all">All</button>
        <button class="btn btn-sm btn-outline-secondary" data-type="FTA">FTA</button>
        <button class="btn btn-sm btn-outline-secondary" data-type="PTA">PTA</button>
        <button class="btn btn-sm btn-outline-secondary" data-type="GSP">GSP</button>
        <button class="btn btn-sm btn-outline-secondary" data-type="RTA">RTA</button>
    </div>

    <div id="allModulesContainer">
        <div class="ptad-skeleton mb-2" style="height:70px;"></div>
        <div class="ptad-skeleton mb-2" style="height:70px;"></div>
        <div class="ptad-skeleton mb-2" style="height:70px;"></div>
    </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<script src="/assets/js/all-modules.js"></script>
