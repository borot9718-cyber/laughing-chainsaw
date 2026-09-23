<?php $title='KOVA — Votre espace social'; ?>
<?php $description = 'KOVA est un espace social moderne : publications, groupes publics et privés, messagerie, boutique et connexion sécurisée avec double authentification.'; ?>
<?php require __DIR__.'/_head.php'; ?>
<?php require __DIR__.'/_debug.php'; ?>
<div class="landing">
<header class="landing-nav">
    <a class="brand brand-large" href="/">
        <span class="brand-symbol">K</span><span class="brand-name">KOVA</span>
    </a>
    <nav class="landing-links">
        <a href="#fonctionnalites">Fonctionnalités</a>
        <a href="#confidentialite">Confidentialité</a>
        <a href="#pwa">Application</a>
    </nav>
    <div class="landing-actions">
        <a class="button button-ghost" href="/connexion">Se connecter</a>
        <a class="button button-primary" href="/inscription">Créer mon compte</a>
    </div>
</header>

<main>
<section class="hero">
    <div class="hero-copy">
        <div class="eyebrow"><span class="status-dot"></span> KOVA est prêt à évoluer avec vous</div>
        <h1>Votre espace social.<br><span>À votre manière.</span></h1>
        <p>Communiquez, publiez, découvrez des communautés, échangez en privé et construisez votre univers numérique dans une interface pensée pour rester claire.</p>
        <div class="hero-actions">
            <a class="button button-primary button-xl" href="/inscription">Commencer sur KOVA</a>
            <a class="button button-soft button-xl" href="#fonctionnalites">Découvrir KOVA</a>
        </div>
        <div class="hero-note">
            <svg viewBox="0 0 24 24"><path d="M12 3 5 6v5c0 4.8 2.9 8.3 7 10 4.1-1.7 7-5.2 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-4"/></svg>
            Contrôle des données, sécurité et confidentialité intégrés à l'architecture.
        </div>
    </div>
    <div class="hero-visual">
        <div class="app-preview">
            <div class="preview-top"><span class="brand-mini">K</span><span>Accueil</span><span class="preview-dot"></span></div>
            <div class="preview-search">Rechercher sur KOVA</div>
            <div class="preview-grid">
                <div class="preview-card preview-main">
                    <div class="preview-author"><span class="avatar">K</span><div><strong>Votre fil</strong><small>Pour vous</small></div></div>
                    <div class="preview-lines"><i></i><i></i><i></i></div>
                    <div class="preview-media"></div>
                    <div class="preview-actions"><span></span><span></span><span></span></div>
                </div>
                <div class="preview-side"><div></div><div></div><div></div></div>
            </div>
        </div>
        <div class="floating-card floating-one"><span class="icon-tile"><svg viewBox="0 0 24 24"><path d="M4 12a8 8 0 1 0 16 0"/><path d="M4 12h4M16 12h4"/></svg></span><strong>Messages</strong><small>Vos conversations au même endroit</small></div>
        <div class="floating-card floating-two"><span class="icon-tile"><svg viewBox="0 0 24 24"><path d="M5 5h14v14H5z"/><path d="m8 12 2.5 2.5L16 9"/></svg></span><strong>Communautés</strong><small>Des espaces organisés</small></div>
    </div>
</section>

<section class="trust-strip">
    <span>Une architecture pensée pour</span>
    <strong>Web</strong><strong>Mobile</strong><strong>PWA</strong><strong>Multilingue</strong><strong>JSON</strong>
</section>

<section id="fonctionnalites" class="section">
    <div class="section-heading"><span class="eyebrow">UNIVERS KOVA</span><h2>Tout ce qu'il faut, sans interface surchargée.</h2><p>Chaque fonctionnalité repose sur le même système visuel pour rester cohérent sur mobile comme sur ordinateur.</p></div>
    <div class="feature-grid">
        <article class="feature-card"><span class="icon-tile"><svg viewBox="0 0 24 24"><path d="M4 5h16v14H4z"/><path d="m7 9 3 3-3 3M12 15h5"/></svg></span><h3>Publications</h3><p>Texte, médias, réactions, commentaires, partages, hashtags et origine du contenu.</p></article>
        <article class="feature-card"><span class="icon-tile"><svg viewBox="0 0 24 24"><path d="M20 11.5a8 8 0 0 1-8 8 8.7 8.7 0 0 1-3.8-.9L4 20l1.4-4.1A8 8 0 1 1 20 11.5Z"/></svg></span><h3>Messages</h3><p>Conversations privées, pièces jointes, lecture, réponses et organisation des échanges.</p></article>
        <article class="feature-card"><span class="icon-tile"><svg viewBox="0 0 24 24"><path d="M4 6h16v12H4z"/><path d="M8 10h8M8 14h5"/></svg></span><h3>Communautés</h3><p>Groupes, communautés, membres, rôles, invitations et modération structurée.</p></article>
        <article class="feature-card"><span class="icon-tile"><svg viewBox="0 0 24 24"><path d="M5 7h14l-1 12H6L5 7Z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg></span><h3>Boutique</h3><p>Produits, vendeurs, favoris, panier, commandes et commandes suivies avec le vendeur (paiement convenu directement).</p></article>
        <article class="feature-card"><span class="icon-tile"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><path d="m12 8 1.2 2.6L16 12l-2.8 1.4L12 16l-1.2-2.6L8 12l2.8-1.4L12 8Z"/></svg></span><h3>Premium <small style="font-weight:600;color:var(--muted)">— à venir</small></h3><p>Abonnements et promotion de publications séparés pour permettre une évolution propre.</p></article>
        <article class="feature-card"><span class="icon-tile"><svg viewBox="0 0 24 24"><path d="M12 3 5 6v5c0 4.8 2.9 8.3 7 10 4.1-1.7 7-5.2 7-10V6l-7-3Z"/><path d="M9 12l2 2 4-4"/></svg></span><h3>Protection</h3><p>Sessions, protection CSRF, validation, journalisation, limitation des tentatives et centre de confidentialité.</p></article>
    </div>
</section>

<section id="confidentialite" class="dark-section">
    <div class="split">
        <div><span class="eyebrow">CONFIDENTIALITÉ</span><h2>Vos réglages ne sont pas une réflexion après coup.</h2><p>KOVA prévoit dès son architecture les préférences de visibilité, les sessions, les contrôles de données, les signalements et les journaux de sécurité.</p><a class="text-link" href="/confidentialite">Voir la politique de confidentialité <span>→</span></a></div>
        <div class="security-panel"><div class="security-row"><span class="security-check">✓</span><div><strong>Sessions contrôlées</strong><small>Déconnexion automatique après la période configurée d'inactivité.</small></div></div><div class="security-row"><span class="security-check">✓</span><div><strong>Stockage séparé</strong><small>Les fichiers médias et les données applicatives sont découplés.</small></div></div><div class="security-row"><span class="security-check">✓</span><div><strong>Modération traçable</strong><small>Actions et décisions peuvent être journalisées.</small></div></div></div>
    </div>
</section>

<section id="pwa" class="section install-section">
    <div class="install-card"><div><span class="eyebrow">APPLICATION WEB PROGRESSIVE</span><h2>KOVA peut devenir une application installée.</h2><p>Le manifeste, le service worker et la gestion du badge d'application sont intégrés au socle. Le navigateur et le système décident ensuite des capacités réellement disponibles.</p></div><button class="button button-primary" type="button" data-install-pwa hidden>Installer KOVA</button></div>
</section>
</main>
<?php require __DIR__.'/_foot.php'; ?>
