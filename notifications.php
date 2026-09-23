<?php use Kova\Core\View; $title = 'Notifications — KOVA'; ?>
<?php require __DIR__.'/_head.php'; ?>
<?php require __DIR__.'/_debug.php'; ?>
<div class="app-shell">
<?php require __DIR__.'/_nav.php'; ?>
<?php require __DIR__.'/_sidebar.php'; ?>
<main class="app-main">
    <div class="page-heading">
        <div><span class="eyebrow">KOVA</span><h1>Notifications</h1><p>Les activités importantes regroupées au même endroit.</p></div>
        <div class="page-actions"><button class="button button-soft" id="btn-read-all-notifs">Tout marquer lu</button></div>
    </div>
    <section class="card notification-list" id="notification-list">
        <div class="feed-loading">Chargement…</div>
    </section>
</main>
</div>
<?php require __DIR__.'/_foot.php'; ?>
