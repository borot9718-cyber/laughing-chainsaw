<?php use Kova\Core\View; $title='Maintenance — KOVA'; $m=is_array($maintenance??null)?$maintenance:[]; $end=(string)($m['end_at']??''); ?>
<?php require __DIR__.'/_head.php'; ?>
<div class="debug-page"><main class="debug-card maintenance-card">
  <div class="brand-symbol">K</div>
  <span class="eyebrow">KOVA</span>
  <h1>Maintenance en cours</h1>
  <p><?= View::e($m['message'] ?? 'KOVA est temporairement en maintenance.') ?></p>
  <?php if ($end !== ''): ?><div class="card stat-card"><strong id="maintenance-countdown" data-countdown-end="<?= View::e($end) ?>">--:--:--</strong><span>Temps restant</span></div><?php endif; ?>
  <p class="muted">Le site reviendra automatiquement dès la fin de la maintenance.</p>
</main></div>
<?php require __DIR__.'/_foot.php'; ?>
