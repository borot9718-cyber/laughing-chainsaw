<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';

use Kova\Core\JsonStore;
use Kova\Core\DatabaseStore;
use Kova\Core\Security;

$user = $app->auth()->user();
if (!$user || !$app->isAdmin($user)) {
    http_response_code(403);
    exit('Accès administrateur requis.');
}

$root = dirname(__DIR__);
$env = $app->env();
$jsonPath = $root . '/' . $env->get('JSON_STORAGE_PATH', 'storage/json');
$json = new JsonStore($jsonPath);
$files = glob(rtrim($jsonPath, '/\\') . '/*.json') ?: [];
$collections = [];
$total = 0;
foreach ($files as $file) {
    $name = basename($file, '.json');
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) continue;
    $count = count($json->all($name));
    $collections[$name] = $count;
    $total += $count;
}
$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf((string)($_POST['csrf'] ?? ''))) {
        $error = 'Session de sécurité expirée. Rechargez la page.';
    } else {
        try {
            $db = DatabaseStore::fromEnv($env);
            foreach (array_keys($collections) as $name) $db->replace($name, $json->all($name));
            $app->logActivity((string)$user['id'], 'database_migration', ['collections'=>count($collections),'records'=>$total]);
            $message = "Migration terminée : {$total} enregistrements dans " . count($collections) . ' collections. Les JSON sources ont été conservés.';
        } catch (Throwable $e) {
            error_log('[KOVA migration] ' . $e->getMessage());
            $error = 'La migration a échoué. Vérifiez les paramètres DB et les droits MySQL.';
        }
    }
}
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Migration base de données — KOVA</title><style>body{font-family:Inter,Arial,sans-serif;background:#f5f7fb;color:#111827;margin:0;padding:32px}.card{max-width:760px;margin:auto;background:#fff;border:1px solid #e3e8f0;border-radius:18px;padding:28px;box-shadow:0 18px 60px #0f172a14}h1{margin-top:0}.muted{color:#687386}.notice{padding:13px;border-radius:12px;margin:16px 0}.ok{background:#edf9f4;color:#147653}.err{background:#fff0f2;color:#a92840}.button{border:0;border-radius:12px;padding:13px 18px;font-weight:700;background:linear-gradient(135deg,#315cf6,#7a35f5);color:#fff;cursor:pointer}table{width:100%;border-collapse:collapse;margin:18px 0}td{padding:8px;border-bottom:1px solid #e3e8f0}td:last-child{text-align:right}</style></head>
<body><main class="card"><p class="muted">KOVA · Administration</p><h1>Migrer les données vers MySQL</h1><p class="muted">Cette page importe les JSON vers la table SQL configurée. Les fichiers JSON ne sont ni supprimés ni modifiés.</p>
<?php if ($message): ?><div class="notice ok"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<table><?php foreach ($collections as $name=>$count): ?><tr><td><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></td><td><?= (int)$count ?></td></tr><?php endforeach; ?><tr><td><strong>Total</strong></td><td><strong><?= (int)$total ?></strong></td></tr></table>
<form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars(Security::csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><button class="button" type="submit">Exécuter la migration</button></form>
<p class="muted">Après la migration, vous pouvez supprimer ou renommer ce fichier si vous ne souhaitez plus conserver l’accès web.</p></main></body></html>
