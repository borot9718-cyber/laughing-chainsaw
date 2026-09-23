<?php use Kova\Core\View; $title = 'Administration — KOVA'; ?>
<?php require __DIR__.'/_head.php'; ?>
<?php require __DIR__.'/_debug.php'; ?>
<div class="app-shell">
<?php require __DIR__.'/_nav.php'; ?>
<?php require __DIR__.'/_sidebar.php'; ?>
<main class="app-main">
  <div class="page-heading"><div><span class="eyebrow">KOVA</span><h1>Administration</h1><p>Modération, sécurité, utilisateurs et demandes.</p></div></div>
  <section class="stats-grid" id="admin-stats"></section>
  <section class="admin-grid">
    <div class="card"><div class="panel-title"><div><h3>Demandes d’assistance</h3><p class="muted">Les utilisateurs peuvent écrire à l’administration, sans conversation retour.</p></div></div><div id="admin-support-list"><p class="muted">Chargement…</p></div></div>
    <div class="card"><div class="panel-title"><div><h3>Signalements</h3><p class="muted">Traitez les signalements et gardez une trace de leur état.</p></div></div><div id="admin-reports-list"><p class="muted">Chargement…</p></div></div>
  </section>
  <section class="card"><div class="panel-title"><div><h3>Demandes de protection des données</h3><p class="muted">Accès, rectification, suppression, opposition et autres demandes.</p></div></div><div id="admin-privacy-list"><p class="muted">Chargement…</p></div></section>
  <section class="card"><div class="panel-title"><div><h3>Utilisateurs</h3><p class="muted">Rôle, suspension et bannissement.</p></div></div><div id="admin-users-list"><p class="muted">Chargement…</p></div></section>
  <section class="card"><div class="panel-title"><div><h3>Applications « Continuer avec KOVA »</h3><p class="muted">Vérifiez ou désactivez les plateformes qui utilisent KOVA pour la connexion.</p></div></div><div id="admin-apps-list"><p class="muted">Chargement…</p></div></section>
  <section class="card admin-gateway-card"><div class="panel-title"><div><h3>Passerelle IA de publication</h3><p class="muted">Connectez le site IA externe avec un jeton révocable et définissez le style ainsi que le rythme de publication.</p></div></div>
    <form id="admin-gateway-form" class="admin-form">
      <label><input type="checkbox" id="gateway-enabled"> Autoriser la passerelle</label>
      <label>Style de publication<textarea id="gateway-style" maxlength="4000" rows="4" placeholder="Ton, longueur, thèmes, règles éditoriales…"></textarea></label>
      <div class="form-grid"><label>Publications par jour<input type="number" id="gateway-count" min="1" max="50" value="1"></label><label>Heures (HH:MM séparées par des virgules)<input type="text" id="gateway-hours" placeholder="09:00, 18:00"></label></div>
      <div class="admin-actions"><button class="button button-primary" type="submit">Enregistrer le style</button><button class="button button-soft" type="button" id="gateway-rotate">Créer / renouveler le jeton</button><span id="gateway-status" class="muted"></span></div>
      <div id="gateway-token-result" class="form-message success" hidden></div>
    </form>
  </section>
  <section class="card admin-images-card"><div class="panel-title"><div><h3>Images des anciennes publications</h3><p class="muted">Récupérez les images externes, appliquez les contrôles KOVA et stockez une copie compressée dans votre stockage cloud.</p></div></div>
    <div class="admin-actions"><button class="button button-soft" type="button" id="legacy-images-scan">Analyser les anciennes images</button><label class="inline-field">Lot<input type="number" id="legacy-images-limit" min="1" max="10" value="5"></label><button class="button button-primary" type="button" id="legacy-images-run" disabled>Traiter le lot</button><span id="legacy-images-status" class="muted"></span></div>
    <div id="legacy-images-list" class="admin-row-list"><p class="muted">Aucune analyse effectuée.</p></div>
  </section>
  <section class="card admin-maintenance-card"><div class="panel-title"><div><h3>Mode maintenance</h3><p class="muted">Bloque l’accès public pendant une période définie. Les administrateurs restent autorisés.</p></div></div>
    <form id="admin-maintenance-form" class="admin-form">
      <label><input type="checkbox" id="maintenance-enabled"> Activer la maintenance</label>
      <div class="form-grid"><label>Début<input type="datetime-local" id="maintenance-start"></label><label>Fin<input type="datetime-local" id="maintenance-end"></label></div>
      <label>Message<textarea id="maintenance-message" maxlength="500" rows="3"></textarea></label>
      <div class="admin-actions"><button class="button button-primary" type="submit">Enregistrer</button><span id="maintenance-status" class="muted"></span></div>
    </form>
  </section>
  <section class="card"><div class="panel-title"><div><h3>Journal d’activité</h3><p class="muted">Dernières actions d’administration.</p></div></div><div id="admin-log-list"><p class="muted">Chargement…</p></div></section>
</main></div>
<?php require __DIR__.'/_foot.php'; ?>
<script src="/assets/js/kova-admin.js?v=20260919-1" defer></script>
