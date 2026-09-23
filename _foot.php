<footer class="site-footer">
    <div><strong>KOVA</strong><span>Un espace social moderne.</span></div>
    <nav>
        <a href="/a-propos">À propos</a>
        <a href="/confidentialite">Confidentialité</a>
        <a href="/aide">Assistance</a>
    </nav>
    <span>© <?= date('Y') ?> KOVA</span>
</footer>
<!-- JS chargé ici pour garantir que le DOM est prêt.
     $pageScripts (défini par chaque vue) charge uniquement les modules utiles à la page. -->
<script src="/assets/js/kova.js?v=<?= $__v ?? "20260920-1" ?>"></script>
<?php foreach (($pageScripts ?? []) as $__js): ?>
<script src="/assets/js/kova-<?= htmlspecialchars((string)$__js, ENT_QUOTES, 'UTF-8') ?>.js?v=<?= $__v ?? "20260920-1" ?>"></script>
<?php endforeach; ?>
<?php if (!empty($user['id'])): ?>
<script src="/assets/js/kova-push.js?v=<?= $__v ?? "20260920-1" ?>"></script>
<?php endif; ?>
<script src="/assets/js/kova-i18n.js?v=<?= $__v ?? "20260920-1" ?>"></script>
</body>
</html>
