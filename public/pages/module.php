<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Ptad\Config;

Config::load();

$code = strtoupper((string) ($_GET['code'] ?? ''));
$pageTitle = $code;
$activeNav = 'modules';
include __DIR__ . '/../partials/head.php';
?>

<div class="container py-4">
    <div id="moduleContainer">
        <div class="ptad-skeleton mb-3" style="height:140px;"></div>
        <div class="ptad-skeleton mb-3" style="height:90px;"></div>
        <div class="ptad-skeleton" style="height:300px;"></div>
    </div>
    <div id="modulePageData" data-code="<?= htmlspecialchars($code) ?>" style="display:none;"></div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<script src="/assets/js/flags.js"></script>
<script src="/assets/js/module.js"></script>
