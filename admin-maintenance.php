<?php $title = 'Maintenance — Administration KOVA'; ?>
<?php require __DIR__.'/_head.php'; ?>
<?php require __DIR__.'/_debug.php'; ?>
<div class="app-shell">
<?php require __DIR__.'/_nav.php'; ?>
<?php require __DIR__.'/_sidebar.php'; ?>
<main class="app-main">
  <div class="page-heading"><div><span class="eyebrow">ADMINISTRATION</span><h1>Maintenance du site</h1><p>Contrôlez l’accès public à KOVA sans bloquer l’administration.</p></div></div>
  <section class="card admin-maintenance-card">
    <div class="panel-title"><div><h3>État du site</h3><p id="maintenance-state" class="muted">Chargement…</p></div><a class="button button-soft" href="/admin">← Administration</a></div>
    <form id="admin-maintenance-form" class="admin-form">
      <label><input type="checkbox" id="maintenance-enabled"> Activer le mode maintenance</label>
      <div class="form-grid"><label>Début<input type="datetime-local" id="maintenance-start"></label><label>Fin<input type="datetime-local" id="maintenance-end"></label></div>
      <label>Message affiché aux visiteurs<textarea id="maintenance-message" maxlength="500" rows="4"></textarea></label>
      <div class="admin-actions"><button class="button button-primary" type="submit">Enregistrer les paramètres</button><span id="maintenance-status" class="muted"></span></div>
    </form>
  </section>
  <section class="card"><h3>Comportement</h3><p class="muted">Les visiteurs voient la page de maintenance pendant la période active. Les administrateurs authentifiés conservent l’accès à l’administration. À la date de fin, le mode maintenance est automatiquement désactivé.</p></section>
</main></div>
<script src="/assets/js/kova-maintenance.js?v=20260919-1" defer></script>
<?php require __DIR__.'/_foot.php'; ?>
