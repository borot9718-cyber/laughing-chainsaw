<?php use Kova\Core\View; $title = 'Profil — KOVA'; $pageScripts = ['social']; ?>
<?php require __DIR__.'/_head.php'; ?>
<?php require __DIR__.'/_debug.php'; ?>
<div class="app-shell">
<?php require __DIR__.'/_nav.php'; ?>
<?php require __DIR__.'/_sidebar.php'; ?>
<main class="app-main">
    <div class="page-heading">
        <div><span class="eyebrow">KOVA</span><h1 id="profile-title">Profil</h1><p id="profile-subtitle">Identité et publications.</p></div>
    </div>

    <section class="profile-hero card">
        <div class="profile-cover" id="profile-cover-display">
            <!-- Éléments réservés au propriétaire : masqués par défaut, affichés par le JS uniquement sur SON profil -->
            <div class="profile-cover-actions" id="profile-cover-actions" hidden>
                <label class="button button-soft profile-upload-button" for="profile-cover-input">Changer la couverture</label>
                <input id="profile-cover-input" type="file" accept="image/jpeg,image/png,image/webp" hidden>
            </div>
        </div>
        <div class="profile-body">
            <div class="profile-avatar-wrap">
                <div class="avatar avatar-xl" id="profile-avatar-display">K</div>
                <label class="profile-avatar-edit" id="profile-avatar-edit" for="profile-avatar-input" hidden aria-label="Changer la photo de profil"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h3l1.5-2h7L17 7h3v12H4z"/><circle cx="12" cy="13" r="3.5"/></svg></label>
                <input id="profile-avatar-input" type="file" accept="image/jpeg,image/png,image/webp" hidden>
            </div>
            <div class="profile-info">
                <h2 id="profile-display-name">Chargement…</h2>
                <p id="profile-bio" class="muted user-content"></p>
            </div>
            <div class="profile-actions" id="profile-actions"></div>
        </div>
    </section>

    <div class="content-grid">
        <section class="card" id="profile-stats">
            <h3>Activité</h3>
            <div class="stat-inline">
                <div><strong id="stat-posts">—</strong><span>publications</span></div>
                <button type="button" class="stat-button" id="btn-followers"><strong id="stat-followers">—</strong><span>abonnés</span></button>
                <button type="button" class="stat-button" id="btn-following"><strong id="stat-following">—</strong><span>abonnements</span></button>
            </div>
        </section>
        <section class="card">
            <h3>Compte</h3>
            <p class="muted" id="profile-email">—</p>
            <p class="muted" style="font-size:.8rem">Membre depuis <span id="profile-since">—</span></p>
        </section>
    </div>

    <section class="profile-posts" id="profile-posts-section" style="max-width:1040px;margin:24px auto 0">
        <h3 style="margin:0 0 12px">Publications</h3>
        <div id="profile-posts" class="feed-list"><div class="feed-loading">Chargement…</div></div>
    </section>

    <!-- Réservé au propriétaire (affiché par le JS sur son propre profil uniquement) -->
    <section class="card" id="password" hidden style="max-width:1040px;margin:24px auto 0">
        <h3>Changer le mot de passe</h3>
        <form class="form-stack" id="change-password-form" style="max-width:400px;margin-top:16px">
            <div class="form-field"><label>Mot de passe actuel</label><input name="current_password" type="password" required autocomplete="current-password"></div>
            <div class="form-field"><label>Nouveau mot de passe</label><input name="new_password" type="password" required maxlength="72" data-strength placeholder="10 caractères minimum, 2 types de caractères" autocomplete="new-password"></div>
            <div class="form-message" data-form-message hidden></div>
            <button class="button button-soft" type="submit">Mettre à jour</button>
        </form>
    </section>
</main>
</div>
<?php require __DIR__.'/_foot.php'; ?>
