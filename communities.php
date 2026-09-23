<?php $title = 'Communautés — KOVA'; $pageScripts = ['spaces']; ?>
<?php require __DIR__.'/_head.php'; ?>
<?php require __DIR__.'/_debug.php'; ?>
<div class="app-shell">
<?php require __DIR__.'/_nav.php'; ?>
<?php require __DIR__.'/_sidebar.php'; ?>
<main class="app-main" id="spaces-root" data-kind="communities">
    <div id="space-list-view">
        <div class="page-heading">
            <div><span class="eyebrow">KOVA</span><h1>Communautés</h1><p>Des espaces ouverts organisés autour de sujets communs.</p></div>
            <div class="page-actions"><button class="button button-primary" id="btn-create-space">Créer une communauté</button></div>
        </div>
        <div class="space-toolbar"><input type="search" id="space-search" placeholder="Rechercher une communauté" aria-label="Rechercher une communauté"></div>
        <section class="space-section"><h3>Mes communautés</h3><div id="my-spaces" class="space-grid"><p class="muted">Chargement…</p></div></section>
        <section class="space-section" id="pending-section" hidden><h3>Demandes envoyées</h3><div id="pending-spaces" class="space-grid"></div></section>
        <section class="space-section"><h3>Découvrir</h3><div id="discover-spaces" class="space-grid"><p class="muted">Chargement…</p></div></section>
    </div>
    <div id="space-detail" hidden></div>
</main></div>
<?php require __DIR__.'/_foot.php'; ?>
