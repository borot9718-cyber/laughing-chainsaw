<?php
declare(strict_types=1);

/**
 * Migration KOVA JSON -> SQL.
 * Usage : php tools/migrate-json-to-database.php [--execute]
 * Sans --execute, le script contrôle la connexion et affiche un aperçu.
 * Les fichiers JSON ne sont jamais supprimés ni modifiés.
 */
require_once __DIR__ . '/../app/Core/Env.php';
require_once __DIR__ . '/../app/Core/JsonStore.php';
require_once __DIR__ . '/../app/Core/DatabaseStore.php';

use Kova\Core\DatabaseStore;
use Kova\Core\Env;
use Kova\Core\JsonStore;

$root = dirname(__DIR__);
$env = new Env($root . '/.env');
$jsonPath = $root . '/' . $env->get('JSON_STORAGE_PATH', 'storage/json');
$json = new JsonStore($jsonPath);
$db = DatabaseStore::fromEnv($env);
$execute = in_array('--execute', $argv ?? [], true);
$files = glob(rtrim($jsonPath, '/\\') . '/*.json') ?: [];
$collections = [];
$total = 0;
foreach ($files as $file) {
    $name = basename($file, '.json');
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name) || str_ends_with($name, '.lock')) continue;
    $rows = $json->all($name);
    $collections[$name] = count($rows);
    $total += count($rows);
}
echo "KOVA — migration JSON vers SQL\n";
echo "Mode : " . ($execute ? 'EXECUTION' : 'APERÇU') . "\n";
echo "Collections : " . count($collections) . " | Enregistrements : {$total}\n";
foreach ($collections as $name => $count) echo sprintf(" - %-32s %d\n", $name, $count);
if (!$execute) { echo "Aucune donnée modifiée. Relancez avec --execute pour importer.\n"; exit(0); }
$manifest = ['started_at'=>date('c'),'source'=>$jsonPath,'collections'=>[],'records'=>0];
foreach ($collections as $name => $count) {
    $rows = $json->all($name);
    $db->replace($name, $rows);
    $manifest['collections'][$name] = ['records'=>count($rows),'migrated_at'=>date('c')];
    $manifest['records'] += count($rows);
    echo "Importé : {$name} ({$count})\n";
}
$manifest['completed_at'] = date('c');
$manifestPath = $root . '/storage/migration-json-to-database-' . date('Ymd-His') . '.json';
@mkdir(dirname($manifestPath), 0700, true);
file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
@chmod($manifestPath, 0600);
echo "Migration terminée. Journal : {$manifestPath}\n";
