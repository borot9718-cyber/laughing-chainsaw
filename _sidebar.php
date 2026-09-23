<?php use Kova\Core\View;
$current = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$isAdmin = !empty($user) && in_array(strtoupper((string)($user['role'] ?? 'USER')), ['ADMIN','SUPERADMIN'], true);
$links = $isAdmin ? [] : [
    'app'           => ['icon'=>'M4 10.5 12 4l8 6.5V20H4z M9 20v-6h6v6', 'label'=>'Accueil'],
    'messages'      => ['icon'=>'M20 11.5a8 8 0 0 1-8 8 8.7 8.7 0 0 1-3.8-.9L4 20l1.4-4.1A8 8 0 1 1 20 11.5Z', 'label'=>'Messages'],
    'groupes'       => ['icon'=>'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2 M23 21v-2a4 4 0 0 0-3-3.87 M16 3.13a4 4 0 0 1 0 7.75', 'label'=>'Groupes'],
    'communautes'   => ['icon'=>'M5 6h14v12H5z M8 10h8M8 14h5', 'label'=>'Communautés'],
    'boutique'      => ['icon'=>'M5 7h14l-1 12H6L5 7Z M9 7a3 3 0 0 1 6 0', 'label'=>'Boutique'],
    'notifications' => ['icon'=>'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9 M10 21h4', 'label'=>'Notifications'],
];
?>
<aside class="sidebar">
    <nav>
        <?php foreach ($links as $route => $item): ?>
        <a class="side-link <?= $current === $route ? 'active' : '' ?>" href="/<?= $route ?>">
            <svg viewBox="0 0 24 24"><path d="<?= $item['icon'] ?>"/></svg>
            <?= $item['label'] ?>
            <?php if ($route === 'notifications'): ?><span class="badge" data-badge="notifications" hidden>0</span><?php endif; ?>
            <?php if ($route === 'messages'): ?><span class="badge" data-badge="messages" hidden>0</span><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-bottom">
        <?php if ($isAdmin): ?>
        <a class="side-link <?= $current === 'admin' ? 'active' : '' ?>" href="/admin"><svg viewBox="0 0 24 24"><path d="M4 5h16v14H4z"/><path d="M8 9h8M8 13h5"/></svg>Administration</a>
        <a class="side-link <?= $current === 'admin/maintenance' ? 'active' : '' ?>" href="/admin/maintenance"><svg viewBox="0 0 24 24"><path d="M12 3v18M3 12h18"/></svg>Maintenance</a>
        <?php endif; ?>
        <a class="side-link <?= $current === 'developpeurs' ? 'active' : '' ?>" href="/developpeurs"><svg viewBox="0 0 24 24"><path d="m8 8-5 4 5 4M16 8l5 4-5 4M14 5l-4 14"/></svg>Développeurs</a>
        <?php if (!$isAdmin): ?>
        <a class="side-link <?= $current === 'profil' ? 'active' : '' ?>" href="/profil">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>Profil
        </a>
        <?php endif; ?>
        <a class="side-link <?= $current === 'parametres' ? 'active' : '' ?>" href="/parametres">
            <svg viewBox="0 0 24 24"><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"/><path d="M4 12h-1m18 0h-1M12 3v2m0 14v2M5.6 5.6 7 7m10 10 1.4 1.4M18.4 5.6 17 7M7 17l-1.4 1.4"/></svg>Paramètres
        </a>
        <a class="side-link" href="/deconnexion">
            <svg viewBox="0 0 24 24"><path d="M10 5H5v14h5"/><path d="m14 8 4 4-4 4M8 12h10"/></svg>Déconnexion
        </a>
    </div>
</aside>
