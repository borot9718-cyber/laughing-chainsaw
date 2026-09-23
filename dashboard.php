<?php use Kova\Core\View; $title = 'Accueil — KOVA'; ?>
<?php require __DIR__.'/_head.php'; ?>
<?php require __DIR__.'/_debug.php'; ?>
<div class="app-shell">
<?php require __DIR__.'/_nav.php'; ?>
<?php require __DIR__.'/_sidebar.php'; ?>
<main class="app-main">
    <div class="page-heading">
        <div><span class="eyebrow">KOVA</span><h1>Votre fil</h1><p>Découvrez les publications, discussions et espaces qui comptent pour vous.</p></div>
        <div class="page-actions"><button class="button button-primary" id="btn-open-composer">Créer</button></div>
    </div>

    <section class="card home-search" id="home-search" role="search" aria-label="Recherche">
        <form class="home-search-form" action="/recherche" method="get">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
            <input type="search" name="q" placeholder="Rechercher des personnes, groupes, publications, produits…" autocomplete="off" aria-label="Rechercher sur KOVA">
            <button class="button button-primary" type="submit">Rechercher</button>
        </form>
        <div class="chips" role="tablist" aria-label="Filtrer la recherche">
            <button type="button" role="tab" data-search-type="">Tout</button>
            <button type="button" role="tab" data-search-type="users">Personnes</button>
            <button type="button" role="tab" data-search-type="groups">Groupes</button>
            <button type="button" role="tab" data-search-type="communities">Communautés</button>
            <button type="button" role="tab" data-search-type="posts">Publications</button>
            <button type="button" role="tab" data-search-type="products">Produits</button>
        </div>
        <div class="home-search-results" data-search-out hidden></div>
    </section>

    <section class="card push-banner" id="push-banner" hidden>
        <div class="push-banner-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg></div>
        <div class="push-banner-text"><strong>Ne ratez plus rien</strong><span>Recevez une notification sur votre téléphone pour les messages, commentaires et commandes.</span></div>
        <div class="push-banner-actions"><button class="button button-primary" id="push-enable" type="button">Activer</button><button class="button button-soft" id="push-later" type="button">Plus tard</button></div>
    </section>

    <div id="feed-area">
    <section class="composer card">
        <div class="avatar" id="composer-avatar-letter"><?= View::e(strtoupper(substr((string)($user['display_name'] ?? '') !== '' ? $user['display_name'] : 'K', 0, 1))) ?></div>
        <div class="composer-main">
            <button class="composer-trigger" id="btn-open-composer-2">Qu'avez-vous envie de partager ?</button>
            <div class="composer-tools">
                <button id="btn-open-composer-3"><svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="14" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m20 15-4-4-5 6-3-3-4 4"/></svg>Photo</button>
            </div>
        </div>
    </section>

    <div class="feed-tabs" role="tablist">
        <button type="button" role="tab" data-feed-filter="" aria-selected="true">Pour vous</button>
        <button type="button" role="tab" data-feed-filter="following" aria-selected="false">Abonnements</button>
    </div>

    <section id="feed" class="feed-list">
        <div class="feed-loading">Chargement du fil…</div>
    </section>
    </div>
</main>
</div>
<?php require __DIR__.'/_foot.php'; ?>
