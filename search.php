<?php $title = 'Recherche — KOVA'; ?>
<?php require __DIR__.'/_head.php'; ?>
<?php require __DIR__.'/_debug.php'; ?>
<div class="app-shell">
<?php require __DIR__.'/_nav.php'; ?>
<?php require __DIR__.'/_sidebar.php'; ?>
<main class="app-main">
    <div class="page-heading"><div><span class="eyebrow">KOVA</span><h1>Recherche</h1><p>Personnes, groupes, communautés, publications et produits.</p></div></div>
    <div id="search-page">
        <form class="card search-page-form" role="search" action="/recherche" method="get">
            <input type="search" name="q" placeholder="Rechercher sur KOVA" aria-label="Rechercher" autocomplete="off">
            <button class="button button-primary" type="submit">Rechercher</button>
        </form>
        <div class="chips search-chips" role="tablist" aria-label="Filtrer la recherche">
            <button type="button" role="tab" data-search-type="">Tout</button>
            <button type="button" role="tab" data-search-type="users">Personnes</button>
            <button type="button" role="tab" data-search-type="groups">Groupes</button>
            <button type="button" role="tab" data-search-type="communities">Communautés</button>
            <button type="button" role="tab" data-search-type="posts">Publications</button>
            <button type="button" role="tab" data-search-type="products">Produits</button>
        </div>
        <section class="card search-page-results" data-search-out><p class="muted">Saisissez au moins 2 caractères.</p></section>
    </div>
</main></div>
<?php require __DIR__.'/_foot.php'; ?>
