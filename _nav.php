<?php use Kova\Core\View; ?>
<header class="topbar">
    <a class="brand" href="<?= !empty($user) ? (in_array(strtoupper((string)($user['role'] ?? 'USER')), ['ADMIN','SUPERADMIN'], true) ? '/admin' : '/app') : '/' ?>" aria-label="KOVA">
        <span class="brand-symbol">K</span><span class="brand-name">KOVA</span>
    </a>
    <?php if(!empty($user)): ?>
    <?php if (!in_array(strtoupper((string)($user['role'] ?? 'USER')), ['ADMIN','SUPERADMIN'], true)): ?>
    <div class="top-search">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
        <input type="search" placeholder="Rechercher sur KOVA" aria-label="Rechercher">
    </div>
    <?php endif; ?>
    <nav class="top-actions">
        <?php if (!in_array(strtoupper((string)($user['role'] ?? 'USER')), ['ADMIN','SUPERADMIN'], true)): ?>
        <a href="/notifications" class="icon-button" aria-label="Notifications">
            <svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
            <span class="badge" data-badge="notifications" hidden>0</span>
        </a>
        <a href="/messages" class="icon-button" aria-label="Messages">
            <svg viewBox="0 0 24 24"><path d="M20 11.5a8 8 0 0 1-8 8 8.7 8.7 0 0 1-3.8-.9L4 20l1.4-4.1A8 8 0 1 1 20 11.5Z"/><path d="M8 12h.01M12 12h.01M16 12h.01"/></svg>
            <span class="badge" data-badge="messages" hidden>0</span>
        </a>
        <?php endif; ?>
        <?php if (!in_array(strtoupper((string)($user['role'] ?? 'USER')), ['ADMIN','SUPERADMIN'], true)): ?>
        <a href="/profil" class="avatar avatar-sm" id="nav-avatar-letter" title="Mon profil">
            <?= View::e(strtoupper(substr($user['display_name'] ?: $user['email'] ?? 'K', 0, 1))) ?>
        </a>
        <?php endif; ?>
        <a href="/deconnexion" class="mobile-logout" aria-label="Déconnexion">Quitter</a>
    </nav>
    <?php else: ?>
    <nav class="top-actions">
        <a class="button button-ghost" href="/connexion">Se connecter</a>
        <a class="button button-primary" href="/inscription">Créer un compte</a>
    </nav>
    <?php endif; ?>
</header>
