<?php $title = 'Boutique — KOVA'; $pageScripts = ['store']; ?>
<?php require __DIR__.'/_head.php'; ?>
<?php require __DIR__.'/_debug.php'; ?>
<div class="app-shell">
<?php require __DIR__.'/_nav.php'; ?>
<?php require __DIR__.'/_sidebar.php'; ?>
<main class="app-main" id="store-root">
    <div class="page-heading">
        <div><span class="eyebrow">KOVA</span><h1>Boutique</h1><p>Produits, vendeurs, favoris et commandes.</p></div>
        <div class="page-actions"><button class="button button-primary" id="btn-new-product">Vendre un produit</button></div>
    </div>
    <div class="feed-tabs store-tabs" role="tablist" id="store-tabs">
        <button type="button" role="tab" data-tab="catalog">Catalogue</button>
        <button type="button" role="tab" data-tab="favorites">Favoris</button>
        <button type="button" role="tab" data-tab="cart">Panier <span class="pill" id="cart-count" hidden>0</span></button>
        <button type="button" role="tab" data-tab="orders">Mes commandes</button>
        <button type="button" role="tab" data-tab="sales">Mes ventes</button>
        <button type="button" role="tab" data-tab="mine">Mes produits</button>
    </div>
    <div class="store-toolbar" id="store-toolbar">
        <input type="search" id="store-q" placeholder="Rechercher un produit" aria-label="Rechercher un produit">
        <select id="store-cat" aria-label="Catégorie"><option value="">Toutes les catégories</option></select>
        <select id="store-sort" aria-label="Trier"><option value="recent">Plus récents</option><option value="price_asc">Prix croissant</option><option value="price_desc">Prix décroissant</option></select>
    </div>
    <div id="store-content"><div class="feed-loading">Chargement…</div></div>
    <p class="muted store-note">Le paiement se règle directement avec le vendeur (à la livraison ou selon votre accord) : KOVA transmet la commande et le suivi.</p>
</main></div>
<?php require __DIR__.'/_foot.php'; ?>
