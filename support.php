<?php use Kova\Core\View; $title = 'Assistance — KOVA'; ?>
<?php require __DIR__.'/_head.php'; ?>
<?php require __DIR__.'/_debug.php'; ?>
<div class="app-shell">
<?php require __DIR__.'/_nav.php'; ?>
<?php require __DIR__.'/_sidebar.php'; ?>
<main class="app-main">
    <div class="page-heading">
        <div><span class="eyebrow">KOVA</span><h1>Aide et assistance</h1><p>Envoyez une préoccupation à KOVA par demande interne. L’administration peut mettre à jour son statut, mais ne répond pas directement dans cette conversation.</p></div>
    </div>
    <section class="support-layout">
        <div class="card">
            <div class="panel-title"><div><h3>Nouvelle demande</h3><p class="muted">Choisissez le type, décrivez votre préoccupation et envoyez-la. Vous recevrez une notification dans KOVA et, si configuré, par e-mail.</p></div></div>
            <form id="support-form" class="form-stack">
                <div class="form-field"><label>Catégorie</label>
                    <select name="category">
                        <option value="technical">Problème technique</option>
                        <option value="account">Compte</option>
                        <option value="security">Sécurité</option>
                        <option value="report">Signalement</option>
                        <option value="suggestion">Suggestion</option>
                        <option value="store">Boutique / commande</option>
                    </select>
                </div>
                <div class="form-field"><label>Sujet</label><input name="subject" required maxlength="180"></div>
                <div class="form-field"><label>Message</label><textarea name="message" rows="7" required maxlength="5000"></textarea></div>
                <div class="form-message" data-form-message hidden></div>
                <button class="button button-primary" type="submit">Envoyer la demande</button>
            </form>
        </div>
        <aside class="card">
            <h3>Vos demandes</h3>
            <div id="support-tickets-list"><p class="muted">Chargement…</p></div>
        </aside>
    </section>
</main>
</div>
<?php require __DIR__.'/_foot.php'; ?>
