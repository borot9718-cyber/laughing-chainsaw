<?php $title='Diagnostic des assets — KOVA'; ?>
<?php require __DIR__.'/_head.php'; ?>
<div class="debug-page"><main class="debug-card"><div class="brand-symbol">K</div><span class="eyebrow">DIAGNOSTIC</span><h1>CSS / JS / PWA</h1><p>Cette page vérifie que les fichiers physiques sont présents sur le serveur.</p><div class="debug-assets-list"><?php foreach($assets as $url=>$file): ?><div class="debug-asset"><div><strong><?= htmlspecialchars($url) ?></strong><small><?= htmlspecialchars($file) ?></small></div><span class="<?= is_file($file)?'debug-ok':'debug-fail' ?>"><?= is_file($file)?'OK':'ABSENT' ?></span></div><?php endforeach; ?></div><div id="asset-runtime-check" class="runtime-check">Vérification navigateur en cours…</div><a class="button button-soft" href="/">Retour</a></main></div>

<?php require __DIR__.'/_foot.php'; ?>
