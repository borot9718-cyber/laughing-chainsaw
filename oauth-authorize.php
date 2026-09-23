<?php
use Kova\Core\View;
$mode = $mode ?? 'error';
$title = ($mode === 'consent' ? 'Autoriser l’accès' : 'Erreur d’autorisation') . ' — KOVA';
?>
<?php require __DIR__.'/_head.php'; ?>
<div class="auth-page">
    <a class="brand brand-large auth-brand" href="/"><span class="brand-symbol">K</span><span class="brand-name">KOVA</span></a>
    <div class="oauth-wrap">
        <section class="auth-card oauth-card">
<?php if ($mode === 'consent'):
    $c = $client; $host = (string)parse_url((string)$params['redirect_uri'], PHP_URL_HOST); ?>
            <div class="oauth-apps">
                <span class="brand-symbol">K</span><span class="oauth-arrow">⇄</span>
                <span class="oauth-app-initial"><?= View::e(strtoupper(mb_substr((string)$c['name'], 0, 1))) ?></span>
            </div>
            <div class="auth-card-head" style="text-align:center">
                <h2><?= View::e($c['name']) ?> souhaite accéder à votre compte KOVA</h2>
                <?php if (!empty($c['description'])): ?><p><?= View::e($c['description']) ?></p><?php endif; ?>
            </div>

            <?php if (empty($c['verified'])): ?>
            <div class="oauth-warning">
                <strong>Application non vérifiée</strong>
                <span>Développée par <?= View::e($developer) ?>. N’autorisez l’accès que si vous connaissez et faites confiance à ce site<?= !empty($c['website']) ? ' (' . View::e($c['website']) . ')' : '' ?>.</span>
            </div>
            <?php endif; ?>

            <p class="oauth-lead"><?= View::e($c['name']) ?> pourra :</p>
            <ul class="oauth-scopes">
                <?php foreach ($scopes as $s): ?>
                <li><span class="check-icon">✓</span><span><?= View::e($scopeLabels[$s] ?? $s) ?></span></li>
                <?php endforeach; ?>
            </ul>
            <p class="muted oauth-note">Votre mot de passe n’est jamais partagé. Vous pouvez retirer cet accès à tout moment dans Paramètres › Applications connectées.</p>

            <form method="post" action="/oauth/authorize" class="oauth-form">
                <?php foreach ($params as $k => $v): ?>
                <input type="hidden" name="<?= View::e($k) ?>" value="<?= View::e((string)$v) ?>">
                <?php endforeach; ?>
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <button class="button button-primary button-block button-lg" type="submit" name="decision" value="approve">Autoriser</button>
                <button class="button button-soft button-block" type="submit" name="decision" value="deny" formnovalidate>Annuler</button>
            </form>

            <div class="oauth-account">
                Connecté en tant que <strong><?= View::e($user['display_name'] ?: $user['email']) ?></strong> ·
                <a href="/deconnexion">Ce n’est pas vous ?</a>
            </div>
            <p class="muted oauth-note">Après validation, vous serez redirigé vers <strong><?= View::e($host) ?></strong>.</p>
<?php else: ?>
            <div class="auth-card-head" style="text-align:center">
                <h2><?= View::e($errorTitle ?? 'Erreur') ?></h2>
                <p><?= View::e($errorMessage ?? 'Une erreur est survenue.') ?></p>
            </div>
            <a class="button button-soft button-block" href="/">Retour à KOVA</a>
<?php endif; ?>
        </section>
    </div>
</div>
<?php require __DIR__.'/_foot.php'; ?>
