<?php
declare(strict_types=1);

// Point d'entrée dédié de l'administration KOVA.
// Le contrôle d'accès réel reste dans Router/App côté serveur.
$_GET['route'] = 'admin';
require dirname(__DIR__) . '/bootstrap/app.php';
$app->run();
