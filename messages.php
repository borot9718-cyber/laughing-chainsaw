<?php $title = 'Messages — KOVA'; $pageScripts = ['messages']; ?>
<?php require __DIR__.'/_head.php'; ?>
<?php require __DIR__.'/_debug.php'; ?>
<div class="app-shell">
<?php require __DIR__.'/_nav.php'; ?>
<?php require __DIR__.'/_sidebar.php'; ?>
<main class="app-main" id="messages-root">
    <div class="page-heading"><div><span class="eyebrow">KOVA</span><h1>Messages</h1><p>Retrouvez vos conversations privées.</p></div><div class="page-actions"><button class="button button-primary" id="btn-new-conv">Nouvelle conversation</button></div></div>
    <section class="messages-layout card" id="messages-layout">
        <aside class="conversation-list">
            <div class="panel-title"><strong>Conversations</strong></div>
            <div id="conv-list"><div class="feed-loading">Chargement…</div></div>
        </aside>
        <section class="chat-pane" id="chat-pane">
            <div class="chat-empty">
                <div class="icon-large"><svg viewBox="0 0 24 24"><path d="M20 11.5a8 8 0 0 1-8 8 8.7 8.7 0 0 1-3.8-.9L4 20l1.4-4.1A8 8 0 1 1 20 11.5Z"/></svg></div>
                <h3>Sélectionnez une conversation</h3><p>Les échanges restent dans votre espace privé.</p>
            </div>
        </section>
    </section>
</main></div>
<?php require __DIR__.'/_foot.php'; ?>
