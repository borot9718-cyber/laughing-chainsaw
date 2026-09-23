<?php use Kova\Core\View; $title = 'Déconnexion — KOVA'; ?>
<?php require __DIR__.'/_head.php'; ?>
<div class="auth-page">
    <a class="brand brand-large auth-brand" href="/"><span class="brand-symbol">K</span><span class="brand-name">KOVA</span></a>
    <div class="oauth-wrap">
        <section class="auth-card">
            <div class="auth-card-head"><h2>Se déconnecter ?</h2><p>Votre session sur cet appareil sera fermée.</p></div>
            <form method="post" action="/deconnexion" class="form-stack">
                <input type="hidden" name="csrf" value="<?= View::e(\Kova\Core\Security::csrfToken()) ?>">
                <button class="button button-primary button-block button-lg" type="submit">Oui, me déconnecter</button>
                <a class="button button-soft button-block" href="/app">Annuler</a>
            </form>
        </section>
    </div>
</div>
<?php require __DIR__.'/_foot.php'; ?>
