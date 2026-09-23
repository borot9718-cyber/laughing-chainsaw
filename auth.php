<?php
use Kova\Core\View;
$mode     = $mode ?? 'login';
$register = $mode === 'register';
$forgot   = $mode === 'forgot';
$reset    = $mode === 'reset';
$verify   = $mode === 'verify';
$titleMap = ['register'=>'Créer un compte','login'=>'Connexion','forgot'=>'Mot de passe oublié','reset'=>'Nouveau mot de passe','verify'=>'Vérification'];
// Retour après connexion (ex. autorisation « Continuer avec KOVA ») : chemin interne uniquement.
$nextRaw  = (string)($_GET['next'] ?? '');
$next     = ($nextRaw !== '' && $nextRaw[0] === '/' && !str_starts_with($nextRaw, '//') && !str_contains($nextRaw, '\\') && !str_starts_with($nextRaw, '/api/')) ? $nextRaw : '';
$nextQs   = $next !== '' ? '?next=' . rawurlencode($next) : '';
$emailVal = (string)($email ?? '');
$title    = ($titleMap[$mode] ?? 'KOVA') . ' — KOVA';
?>
<?php require __DIR__.'/_head.php'; ?>
<?php require __DIR__.'/_debug.php'; ?>
<div class="auth-page">
    <a class="brand brand-large auth-brand" href="/"><span class="brand-symbol">K</span><span class="brand-name">KOVA</span></a>
    <div class="auth-layout">
        <section class="auth-aside">
            <span class="eyebrow"><?= $register ? 'CRÉATION DE COMPTE' : 'KOVA' ?></span>
            <h1><?= $register ? 'Créez votre espace.' : ($forgot || $reset ? 'Récupérez votre accès.' : 'Retrouvez votre espace.') ?></h1>
            <p><?= $register ? 'Rejoignez KOVA et commencez à construire votre univers social.' : 'Votre espace social moderne, sécurisé et pensé pour vous.' ?></p>
            <div class="auth-points">
                <div><span class="check-icon">✓</span><span>Interface moderne et responsive</span></div>
                <div><span class="check-icon">✓</span><span>Contrôles de confidentialité intégrés</span></div>
                <div><span class="check-icon">✓</span><span>Application web installable</span></div>
            </div>
        </section>
        <section class="auth-card">

<?php if ($verify): ?>
            <div class="auth-card-head"><h2>Vérifiez votre e-mail</h2>
                <p><?php if ($emailVal !== ''): ?>Nous avons envoyé un code à 6 chiffres à <strong><?= View::e($emailVal) ?></strong>.<?php else: ?>Saisissez votre adresse e-mail et le code à 6 chiffres reçu.<?php endif; ?> Il est valable 15 minutes.</p></div>
            <form id="verify-form" class="form-stack" action="" method="post" novalidate data-next="<?= View::e($next) ?>">
                <?php if ($emailVal !== ''): ?>
                <input type="hidden" name="email" value="<?= View::e($emailVal) ?>">
                <?php else: ?>
                <div class="form-field"><label for="verify-email">Adresse e-mail</label><input id="verify-email" name="email" type="email" required autocomplete="email" placeholder="vous@exemple.com"></div>
                <?php endif; ?>
                <div class="form-field"><label for="verify-code">Code de vérification</label>
                    <input id="verify-code" class="code-input" name="code" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" placeholder="000000" required>
                </div>
                <div class="form-message" data-form-message hidden></div>
                <button class="button button-primary button-block button-lg" type="submit">Vérifier</button>
                <button class="button button-soft button-block" type="button" id="resend-code" disabled>Renvoyer le code</button>
            </form>
            <div class="auth-switch"><a href="/connexion<?= View::e($nextQs) ?>">← Retour à la connexion</a></div>

<?php elseif ($forgot): ?>
            <div class="auth-card-head"><h2>Mot de passe oublié</h2><p>Entrez votre adresse e-mail pour recevoir un lien de réinitialisation.</p></div>
            <form id="forgot-form" class="form-stack" action="" method="post" novalidate>
                <div class="form-field"><label for="email">Adresse e-mail</label><input id="email" name="email" type="email" required placeholder="vous@exemple.com" autocomplete="email"></div>
                <div class="form-message" data-form-message hidden></div>
                <button class="button button-primary button-block button-lg" type="submit">Envoyer le lien</button>
            </form>
            <div class="auth-switch"><a href="/connexion">← Retour à la connexion</a></div>

<?php elseif ($reset): ?>
            <div class="auth-card-head"><h2>Nouveau mot de passe</h2><p>Choisissez un mot de passe d'au moins 10 caractères.</p></div>
            <form id="reset-form" class="form-stack" action="" method="post" novalidate>
                <input type="hidden" name="token" value="<?= View::e($token ?? '') ?>">
                <div class="form-field"><label for="password">Nouveau mot de passe</label>
                    <div class="password-wrap">
                        <input id="password" name="password" type="password" required maxlength="72" data-strength autocomplete="new-password" placeholder="10 caractères minimum, 2 types de caractères">
                        <button type="button" class="password-toggle" data-password-toggle aria-label="Afficher">
                            <svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/></svg>
                        </button>
                    </div>
                </div>
                <div class="form-message" data-form-message hidden></div>
                <button class="button button-primary button-block button-lg" type="submit">Réinitialiser</button>
            </form>

<?php elseif ($register): ?>
            <div class="auth-card-head"><h2>Créer mon compte</h2><p>Quelques informations pour commencer.</p></div>
            <form id="register-form" class="form-stack" action="" method="post" novalidate data-next="<?= View::e($next) ?>">
                <div class="form-field">
                    <label for="reg-display-name">Pseudo</label>
                    <input id="reg-display-name" name="display_name" type="text" autocomplete="nickname" required minlength="2" maxlength="30" placeholder="Le nom affiché sur votre profil">
                </div>
                <div class="form-field">
                    <label for="reg-email">Adresse e-mail</label>
                    <input id="reg-email" name="email" type="email" autocomplete="email" required placeholder="vous@exemple.com">
                </div>
                <div class="form-field">
                    <label for="reg-password">Mot de passe</label>
                    <div class="password-wrap">
                        <input id="reg-password" name="password" type="password" autocomplete="new-password" required maxlength="72" data-strength placeholder="10 caractères minimum, 2 types de caractères">
                        <button type="button" class="password-toggle" data-password-toggle aria-label="Afficher">
                            <svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/></svg>
                        </button>
                    </div>
                </div>
                <div class="form-field">
                    <label for="reg-dob">Date de naissance</label>
                    <input id="reg-dob" name="date_of_birth" type="date" max="<?= View::e((new DateTimeImmutable('today'))->modify('-16 years')->format('Y-m-d')) ?>" required>
                    <small class="field-hint">Non affichée publiquement par défaut.</small>
                </div>
                <label class="check-field">
                    <input type="checkbox" name="terms" required>
                    <span>J'accepte les <a href="/conditions">conditions</a> et la <a href="/confidentialite">politique de confidentialité</a>.</span>
                </label>
                <label class="check-field">
                    <input type="checkbox" name="privacy_consent" required>
                    <span>J'accepte le traitement de mes données personnelles tel qu'il est décrit dans la politique de confidentialité.</span>
                </label>
                <div class="form-message" data-form-message hidden></div>
                <button class="button button-primary button-block button-lg" type="submit">Créer mon compte</button>
            </form>
            <div class="auth-switch">Vous avez déjà un compte ? <a href="/connexion<?= View::e($nextQs) ?>">Se connecter</a></div>

<?php else: ?>
            <div class="auth-card-head"><h2>Se connecter</h2><p>Entrez vos identifiants KOVA.</p></div>
            <form id="login-form" class="form-stack" action="" method="post" novalidate data-next="<?= View::e($next) ?>">
                <div class="form-field">
                    <label for="login-email">Adresse e-mail</label>
                    <input id="login-email" name="email" type="email" autocomplete="email" required placeholder="vous@exemple.com">
                </div>
                <div class="form-field">
                    <label for="login-password">Mot de passe</label>
                    <div class="password-wrap">
                        <input id="login-password" name="password" type="password" autocomplete="current-password" required maxlength="200" placeholder="Votre mot de passe">
                        <button type="button" class="password-toggle" data-password-toggle aria-label="Afficher">
                            <svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/></svg>
                        </button>
                    </div>
                </div>
                <div style="text-align:right;margin-top:-8px">
                    <a href="/mot-de-passe-oublie" style="font-size:.85rem;color:var(--primary)">Mot de passe oublié ?</a>
                </div>
                <div class="form-message" data-form-message hidden></div>
                <button class="button button-primary button-block button-lg" type="submit">Se connecter</button>
            </form>
            <div class="auth-switch">Pas encore de compte ? <a href="/inscription<?= View::e($nextQs) ?>">Créer un compte</a></div>

            <!-- Seconde étape (double authentification) : affichée par le JS quand le compte l'exige -->
            <form id="twofa-form" class="form-stack" action="" method="post" novalidate hidden data-next="">
                <div class="auth-card-head"><h2>Double authentification</h2><p>Saisissez le code à 6 chiffres de votre application d’authentification, ou l’un de vos codes de secours.</p></div>
                <div class="form-field">
                    <label for="twofa-code">Code</label>
                    <input id="twofa-code" class="code-input" name="code" type="text" inputmode="text" autocomplete="one-time-code" maxlength="11" required placeholder="000000">
                </div>
                <div class="form-message" data-form-message hidden></div>
                <button class="button button-primary button-block button-lg" type="submit">Valider</button>
                <button class="button button-soft button-block" type="button" id="twofa-cancel">Annuler</button>
            </form>
<?php endif; ?>

        </section>
    </div>
</div>
<?php require __DIR__.'/_foot.php'; ?>
