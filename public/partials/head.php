<?php
/** @var string $pageTitle */
/** @var string $activeNav */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle ?? 'PTAD') ?> — PTAD</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
<link href="/assets/css/ptad.css" rel="stylesheet">
</head>
<body>

<nav class="ptad-nav">
    <div class="container d-flex align-items-center justify-content-between">
        <a class="navbar-brand" href="/">
            <span class="ptad-logo-mark">PT</span>
            <span>
                PTAD
                <small>Preferential Trade Access Database</small>
            </span>
        </a>
        <div class="d-none d-md-flex gap-4">
            <a class="nav-link <?= ($activeNav ?? '') === 'search' ? 'active' : '' ?>" href="/">Search</a>
            <a class="nav-link <?= ($activeNav ?? '') === 'countries' ? 'active' : '' ?>" href="/countries">Country Navigator</a>
            <a class="nav-link <?= ($activeNav ?? '') === 'modules' ? 'active' : '' ?>" href="/modules">All Arrangements</a>
        </div>
    </div>
</nav>
