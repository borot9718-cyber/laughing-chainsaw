<?php
use Kova\Core\View;
$title = 'Développeurs — KOVA'; $pageScripts = ['developers'];
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$base  = rtrim((string)($GLOBALS['kova_app_url'] ?? ''), '/') ?: (($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
?>
<?php require __DIR__.'/_head.php'; ?>
<?php require __DIR__.'/_debug.php'; ?>
<div class="app-shell">
<?php require __DIR__.'/_nav.php'; ?>
<?php require __DIR__.'/_sidebar.php'; ?>
<main class="app-main" id="dev-root">
    <div class="page-heading">
        <div><span class="eyebrow">KOVA</span><h1>Développeurs</h1><p>Faites de KOVA le bouton « Continuer avec KOVA » de vos autres plateformes.</p></div>
        <div class="page-actions"><button class="button button-primary" id="btn-new-app">Nouvelle application</button></div>
    </div>

    <section class="card dev-hero">
        <div>
            <h3>Comment ça marche</h3>
            <p class="muted">Vos utilisateurs se connectent à votre plateforme avec leur compte KOVA, exactement comme « Se connecter avec Google » ou « avec Facebook ». Ils voient un écran de consentement, choisissent d’autoriser ou non, puis reviennent sur votre site : vous recevez leur identifiant, leur nom, leur photo (et leur e-mail s’ils l’autorisent). Vous ne voyez jamais leur mot de passe.</p>
            <ol class="dev-steps">
                <li>Enregistrez votre plateforme ci-dessous (nom + URL de retour).</li>
                <li>Placez le bouton « Continuer avec KOVA » sur votre page de connexion.</li>
                <li>Échangez le code reçu contre un jeton, puis lisez le profil.</li>
            </ol>
        </div>
        <div class="dev-preview">
            <span class="muted">Aperçu du bouton</span>
            <span class="kova-oauth-button" role="img" aria-label="Aperçu du bouton Continuer avec KOVA"><span class="brand-symbol">K</span>Continuer avec KOVA</span>
        </div>
    </section>

    <section class="dev-section">
        <h3>Mes applications</h3>
        <div id="apps-list"><p class="muted">Chargement…</p></div>
    </section>

    <section class="card dev-docs">
        <h3>Documentation</h3>
        <p class="muted">Protocole : OAuth 2.0 « Authorization Code » avec PKCE (compatible avec toute bibliothèque OAuth standard).</p>
        <table class="dev-table">
            <tr><td>Autorisation</td><td><code><?= View::e($base) ?>/oauth/authorize</code></td></tr>
            <tr><td>Jeton</td><td><code><?= View::e($base) ?>/oauth/token</code></td></tr>
            <tr><td>Profil</td><td><code><?= View::e($base) ?>/oauth/userinfo</code></td></tr>
            <tr><td>Révocation</td><td><code><?= View::e($base) ?>/oauth/revoke</code></td></tr>
            <tr><td>Métadonnées</td><td><code><?= View::e($base) ?>/api/oauth/metadata</code> <small class="muted">(alias : /.well-known/oauth-authorization-server, parfois filtré par l’hébergeur)</small></td></tr>
            <tr><td>Portées</td><td><code>profile</code> (nom, photo, bio) · <code>email</code> (adresse e-mail)</td></tr>
        </table>

        <h4>1. Rediriger l’utilisateur vers KOVA</h4>
        <pre><code><?= View::e($base) ?>/oauth/authorize
  ?response_type=code
  &amp;client_id=VOTRE_CLIENT_ID
  &amp;redirect_uri=https://votre-site.com/callback
  &amp;scope=profile%20email
  &amp;state=VALEUR_ALEATOIRE
  &amp;code_challenge=CHALLENGE_PKCE
  &amp;code_challenge_method=S256</code></pre>
        <p class="muted"><code>state</code> protège contre le CSRF : générez-le, stockez-le en session et vérifiez-le au retour. PKCE (<code>code_challenge</code>) est <strong>obligatoire</strong> pour les applications publiques (SPA, mobile) et recommandé pour les autres.</p>

        <h4>2. Échanger le code contre un jeton (côté serveur)</h4>
        <pre><code>curl -X POST <?= View::e($base) ?>/oauth/token \
  -u VOTRE_CLIENT_ID:VOTRE_CLIENT_SECRET \
  -d grant_type=authorization_code \
  -d code=CODE_RECU \
  -d redirect_uri=https://votre-site.com/callback \
  -d code_verifier=VERIFIER_PKCE

→ {"access_token":"kva_…","token_type":"Bearer","expires_in":3600,
   "refresh_token":"kvr_…","scope":"profile email"}</code></pre>

        <h4>3. Lire le profil</h4>
        <pre><code>curl <?= View::e($base) ?>/oauth/userinfo -H "Authorization: Bearer kva_…"

→ {"sub":"usr_…","name":"…","picture":"https://…","bio":"…",
   "email":"…","email_verified":true}</code></pre>
        <p class="muted"><code>sub</code> est l’identifiant KOVA unique et stable de l’utilisateur : utilisez-le comme clé de compte sur votre plateforme (pas l’e-mail). Le jeton d’accès dure 1 h ; renouvelez-le avec <code>grant_type=refresh_token</code> (valide 30 jours).</p>
        <h4>Exemple complet (PHP, sans bibliothèque)</h4>
        <?php $__ex = @file_get_contents(dirname(__DIR__, 2) . '/tools/oauth-client-example/kova-login.php'); ?>
        <pre><code><?= View::e($__ex !== false && $__ex !== '' ? $__ex : '// Exemple indisponible sur ce serveur.') ?></code></pre>
    </section>
</main>
</div>
<?php require __DIR__.'/_foot.php'; ?>
