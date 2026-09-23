<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap/app.php';

use Kova\Core\JsonStore;
use Kova\Core\DatabaseStore;
use Kova\Core\Passwords;
use Kova\Core\Security;

$root = __DIR__;
$env = $app->env();
$jsonPath = $root . '/' . $env->get('JSON_STORAGE_PATH', 'storage/json');
$json = new JsonStore($jsonPath);
$users = $json->all('users');
$completed = false;
$setupState = null;
foreach ($json->all('migration_state') as $row) if (($row['id'] ?? '') === 'initial_admin_and_database') { $setupState = $row; $completed = !empty($row['completed_at']); }
$adminCount = count(array_filter($users, fn($u) => in_array(strtoupper((string)($u['role'] ?? 'USER')), ['ADMIN','SUPERADMIN'], true)));
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
    if ($completed) $error = 'Cette initialisation a déjà été terminée.';
    elseif (!Security::verifyCsrf((string)($_POST['csrf'] ?? ''))) $error = 'Session de sécurité expirée. Rechargez la page.';
    elseif (!hash_equals((string)$env->get('MIGRATION_SETUP_KEY', ''), (string)($_POST['setup_key'] ?? ''))) $error = 'Clé d’installation incorrecte.';
    elseif ($adminCount > 0 && (($setupState['status'] ?? '') !== 'started')) $error = 'Un administrateur existe déjà dans les données JSON. Aucun second administrateur n’a été créé.';
    else {
        $email = strtolower(trim((string)($_POST['admin_email'] ?? '')));
        $name = trim((string)($_POST['admin_name'] ?? ''));
        $password = (string)($_POST['admin_password'] ?? '');
        if (($setupState['status'] ?? '') !== 'started' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || strlen($password) < 12)) {
            $error = 'Nom, e-mail valide et mot de passe d’au moins 12 caractères obligatoires.';
        } elseif (($setupState['status'] ?? '') !== 'started' && count(array_filter($users, fn($u) => strtolower((string)($u['email'] ?? '')) === $email)) > 0) {
            $error = 'Cet e-mail existe déjà dans les données JSON.';
        } else {
            try {
                if (($setupState['status'] ?? '') === 'started') {
                    $admin = null; foreach ($json->all('users') as $candidate) if (($candidate['id'] ?? '') === ($setupState['admin_id'] ?? '')) { $admin = $candidate; break; }
                    if (!$admin) throw new RuntimeException('Compte SUPERADMIN initial introuvable pour la reprise.');
                } else {
                    $admin = ['id'=>'usr_'.bin2hex(random_bytes(8)),'email'=>$email,'display_name'=>mb_substr($name,0,120),'password_hash'=>Passwords::hash($password),'role'=>'SUPERADMIN','account_status'=>'active','verified'=>true,'verified_at'=>date('c'),'session_version'=>1,'twofa_enabled'=>false,'created_at'=>date('c'),'updated_at'=>date('c'),'last_login'=>null];
                    $json->insert('users', $admin);
                    $json->replace('migration_state', [['id'=>'initial_admin_and_database','admin_id'=>$admin['id'],'created_at'=>date('c'),'status'=>'started']]);
                }
                $db = DatabaseStore::fromEnv($env);
                $collections['users'] = count($json->all('users'));
                foreach (array_keys($collections) as $collection) $db->replace($collection, $json->all($collection));
                $json->replace('migration_state', [['id'=>'initial_admin_and_database','admin_id'=>$admin['id'],'completed_at'=>date('c'),'status'=>'completed']]);
                $db->replace('migration_state', $json->all('migration_state'));
                $message = 'Initialisation terminée. Le compte SUPERADMIN a été créé et les données ont été migrées. Cette page est désormais désactivée.';
                $completed = true;
            } catch (Throwable $e) {
                error_log('[KOVA initial migration] '.$e->getMessage());
                $error = 'La migration a échoué. Vérifiez la connexion MySQL. Le JSON source peut être restauré avant une nouvelle tentative.';
            }
        }
    }
}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Initialisation KOVA</title><style>body{font-family:Inter,Arial,sans-serif;background:#f5f7fb;color:#111827;margin:0;padding:32px}.card{max-width:760px;margin:auto;background:#fff;border:1px solid #e3e8f0;border-radius:18px;padding:28px;box-shadow:0 18px 60px #0f172a14}h1{margin-top:0}.muted{color:#687386}.notice{padding:13px;border-radius:12px;margin:16px 0}.ok{background:#edf9f4;color:#147653}.err{background:#fff0f2;color:#a92840}.button{border:0;border-radius:12px;padding:13px 18px;font-weight:700;background:linear-gradient(135deg,#315cf6,#7a35f5);color:#fff;cursor:pointer}label{display:grid;gap:6px;margin:14px 0;font-weight:700;font-size:13px}input{border:1px solid #d9e0ea;border-radius:10px;padding:12px;font:inherit}table{width:100%;border-collapse:collapse;margin:18px 0}td{padding:8px;border-bottom:1px solid #e3e8f0}td:last-child{text-align:right}</style></head><body><main class="card"><p class="muted">KOVA · Initialisation unique</p><h1>Créer le SUPERADMIN et migrer</h1><p class="muted">Aucun compte administrateur existant n’est requis. Un seul SUPERADMIN sera créé dans les JSON avant l’import SQL. Après réussite, cette page sera désactivée.</p><?php if($message): ?><div class="notice ok"><?=htmlspecialchars($message,ENT_QUOTES,'UTF-8')?></div><?php endif; ?><?php if($error): ?><div class="notice err"><?=htmlspecialchars($error,ENT_QUOTES,'UTF-8')?></div><?php endif; ?><?php if(!$completed): ?><table><tr><td>Collections</td><td><?=count($collections)?></td></tr><tr><td>Enregistrements actuels</td><td><?=$total?></td></tr><tr><td>Administrateurs actuels</td><td><?=$adminCount?></td></tr></table><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars(Security::csrfToken(),ENT_QUOTES,'UTF-8')?>"><label>Clé d’installation<input type="password" name="setup_key" required></label><label>Nom du SUPERADMIN<input name="admin_name" required maxlength="120"></label><label>E-mail du SUPERADMIN<input type="email" name="admin_email" required></label><label>Mot de passe du SUPERADMIN<input type="password" name="admin_password" minlength="12" required></label><button class="button" type="submit">Créer l’unique SUPERADMIN et migrer</button></form><?php endif; ?><p class="muted">Après utilisation, supprimez admin-migration.php du serveur.</p></main></body></html>
