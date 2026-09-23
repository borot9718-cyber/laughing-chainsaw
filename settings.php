<?php use Kova\Core\View; $title = 'Paramètres — KOVA'; $pageScripts = ['settings']; ?>
<?php require __DIR__.'/_head.php'; ?>
<?php require __DIR__.'/_debug.php'; ?>
<div class="app-shell">
<?php require __DIR__.'/_nav.php'; ?>
<?php require __DIR__.'/_sidebar.php'; ?>
<main class="app-main" id="settings-root">
    <div class="page-heading">
        <div><span class="eyebrow">KOVA</span><h1>Paramètres</h1><p>Sécurité, apparence, notifications et confidentialité de votre compte.</p></div>
    </div>

    <nav class="settings-nav" aria-label="Sections">
        <a href="#s-security">Sécurité</a><a href="#s-appearance">Apparence</a><a href="#s-notifications">Notifications</a><a href="#s-privacy">Confidentialité</a><a href="#s-apps">Applications</a><a href="#s-data">Mes données</a>
    </nav>

    <section class="card settings-section" id="s-security">
        <h3>Sécurité du compte</h3>
        <p class="muted" id="sec-summary">Chargement…</p>

        <div class="setting-row">
            <div><strong>Mot de passe</strong><small>10 caractères minimum, deux types de caractères. Le changer déconnecte vos autres appareils.</small></div>
            <a class="button button-soft" href="/profil#password">Modifier</a>
        </div>

        <div class="setting-row" id="row-2fa">
            <div><strong>Double authentification (2FA)</strong><small id="twofa-desc">Protège votre compte même si votre mot de passe est volé : un code temporaire est demandé à chaque connexion.</small></div>
            <div class="setting-actions" id="twofa-actions"></div>
        </div>

        <div class="setting-row">
            <div><strong>Déconnecter tous les appareils</strong><small>Ferme toutes les sessions ouvertes sauf celle-ci. À faire si vous pensez que quelqu’un d’autre a accès à votre compte.</small></div>
            <button class="button button-soft" id="btn-logout-all" type="button">Tout déconnecter</button>
        </div>

        <div class="setting-row">
            <div><strong>Cet appareil</strong><small>Terminer votre session ici.</small></div>
            <a class="button button-soft" href="/deconnexion">Se déconnecter</a>
        </div>
    </section>

    <section class="card settings-section" id="s-appearance">
        <h3>Apparence et langue</h3>
        <div class="setting-row">
            <div><strong>Thème</strong><small>« Automatique » suit le réglage de votre téléphone.</small></div>
            <select id="theme-select" aria-label="Thème" class="settings-select"><option value="auto">Automatique</option><option value="light">Clair</option><option value="dark">Sombre</option></select>
        </div>
        <div class="setting-row">
            <div><strong>Langue</strong><small>Langue de l’interface.</small></div>
            <select id="lang-select" aria-label="Langue" class="settings-select"><option value="fr">Français</option><option value="en">English</option></select>
        </div>
    </section>

    <section class="card settings-section" id="s-notifications">
        <h3>Notifications</h3>
        <div class="setting-row">
            <div><strong>Notifications sur cet appareil</strong><small id="push-desc">Recevez un pop-up dans la barre de notification de votre téléphone (autorisation demandée par le navigateur).</small></div>
            <div class="setting-actions" id="push-actions"></div>
        </div>
        <p class="muted" style="margin:14px 0 4px">Choisissez ce que vous souhaitez recevoir :</p>
        <div class="toggle-list" id="prefs-list">
            <label class="toggle-row"><span><strong>Messages privés</strong><small>Un nouveau message</small></span><input type="checkbox" data-pref="messages"></label>
            <label class="toggle-row"><span><strong>J’aime</strong><small>Quelqu’un aime votre publication</small></span><input type="checkbox" data-pref="likes"></label>
            <label class="toggle-row"><span><strong>Commentaires</strong><small>Commentaires et réponses</small></span><input type="checkbox" data-pref="comments"></label>
            <label class="toggle-row"><span><strong>Abonnés</strong><small>Un nouvel abonné</small></span><input type="checkbox" data-pref="follows"></label>
            <label class="toggle-row"><span><strong>Groupes et communautés</strong><small>Demandes d’adhésion, approbations, nouveaux membres</small></span><input type="checkbox" data-pref="groups"></label>
            <label class="toggle-row"><span><strong>Boutique</strong><small>Commandes et changements de statut</small></span><input type="checkbox" data-pref="orders"></label>
        </div>
    </section>

    <section class="card settings-section" id="s-privacy">
        <h3>Confidentialité</h3>
        <div class="setting-row">
            <div><strong>Qui peut m’écrire ?</strong><small>Les personnes à qui vous avez déjà écrit peuvent toujours vous répondre.</small></div>
            <select id="msgperm-select" aria-label="Qui peut m’écrire" class="settings-select"><option value="everyone">Tout le monde</option><option value="followers">Les personnes que je suis</option><option value="nobody">Personne</option></select>
        </div>
        <div class="toggle-list">
            <label class="toggle-row"><span><strong>Apparaître dans la recherche</strong><small>Si désactivé, votre profil n’est pas proposé dans les résultats (il reste accessible par son lien).</small></span><input type="checkbox" id="discoverable"></label>
        </div>
        <h4 style="margin:20px 0 6px">Utilisateurs bloqués</h4>
        <div id="blocks-list"><p class="muted">Chargement…</p></div>
    </section>

    <section class="card settings-section" id="s-apps">
        <h3>Applications connectées</h3>
        <p class="muted">Applications auxquelles vous avez donné accès avec « Continuer avec KOVA ».</p>
        <div id="grants-list"><p class="muted">Chargement…</p></div>
        <div class="setting-row"><div><strong>Espace développeurs</strong><small>Utilisez KOVA comme bouton de connexion sur vos propres plateformes.</small></div><a class="button button-soft" href="/developpeurs">Ouvrir</a></div>
    </section>

    <section class="card settings-section" id="s-data">
        <h3>Mes données</h3>
        <div class="setting-row"><div><strong>Exporter mes données</strong><small>Télécharge un fichier JSON avec les informations liées à votre compte (mots de passe et secrets exclus).</small></div><button class="button button-soft" id="btn-export" type="button">Exporter</button></div>
        <div class="setting-row"><div><strong>Exercer un droit</strong><small>Accès, rectification, limitation, opposition, portabilité, retrait du consentement.</small></div><button class="button button-soft" id="btn-request" type="button">Faire une demande</button></div>
        <div class="setting-row danger-zone"><div><strong>Supprimer mon compte</strong><small>Action définitive : votre profil, vos publications, messages et fichiers sont supprimés ou anonymisés.</small></div><button class="button button-danger" id="btn-delete" type="button">Supprimer</button></div>
        <p class="muted" style="margin-top:12px"><a href="/confidentialite">Politique de confidentialité</a> · <a href="/conditions">Conditions d’utilisation</a></p>
    </section>
</main>
</div>
<?php require __DIR__.'/_foot.php'; ?>
