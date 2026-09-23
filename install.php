<?php
declare(strict_types=1);

/*
 * INSTALLATEUR UNIQUE KOVA
 * Ne dépend pas de bootstrap, d'un compte, d'un admin ni du SMTP.
 * À supprimer immédiatement après réussite.
 */
function loadEnv(string $file): array {
    $out = [];
    foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);
        if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) $value = substr($value, 1, -1);
        $out[trim($key)] = $value;
    }
    return $out;
}
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function rowsFromJson(string $dir, string $name): array {
    $file = rtrim($dir, '/\\') . '/' . $name . '.json';
    if (!is_file($file)) return [];
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
}
function getPdo(array $env): PDO {
    $dsn = trim((string)($env['DB_DSN'] ?? ''));
    if ($dsn === '') $dsn = 'mysql:host=' . ($env['DB_HOST'] ?? '') . ';port=' . ($env['DB_PORT'] ?? '3306') . ';dbname=' . ($env['DB_DATABASE'] ?? '') . ';charset=' . ($env['DB_CHARSET'] ?? 'utf8mb4');
    return new PDO($dsn, (string)($env['DB_USERNAME'] ?? ''), (string)($env['DB_PASSWORD'] ?? ''), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
}
function tableName(string $name): string { return preg_match('/^[A-Za-z0-9_]+$/', $name) ? $name : 'kova_records'; }

$root = __DIR__;
$env = loadEnv($root . '/.env');
$jsonDir = $root . '/' . ($env['JSON_STORAGE_PATH'] ?? 'storage/json');
$table = tableName((string)($env['DB_TABLE'] ?? 'kova_records'));
$lockFile = $root . '/storage/kova-install.lock';
$message = '';
$error = '';
$locked = is_file($lockFile);
$collections = [];
$total = 0;
foreach (glob(rtrim($jsonDir, '/\\') . '/*.json') ?: [] as $file) {
    $name = basename($file, '.json');
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) continue;
    $count = count(rowsFromJson($jsonDir, $name));
    $collections[$name] = $count;
    $total += $count;
}
$existingGateway = rowsFromJson($jsonDir, 'ai_gateway_settings');
$gatewayToken = '';
$gatewaySettings = null;
foreach ($existingGateway as $gatewayRow) if (($gatewayRow['id'] ?? '') === 'default') $gatewaySettings = $gatewayRow;
if (!$gatewaySettings) {
    $gatewayToken = 'kova_ai_' . bin2hex(random_bytes(24));
    $gatewaySettings = ['id'=>'default','enabled'=>true,'token_hash'=>hash('sha256',$gatewayToken),'token_prefix'=>substr($gatewayToken,0,16),'style'=>'','posts_per_day'=>1,'publish_hours'=>['09:00'],'updated_at'=>date('c'),'updated_by'=>'installer'];
}
$oauthClient = ['client_id'=>'kova-ai-publisher','name'=>'KOVA AI Publisher','description'=>'Assistant de publication IA KOVA','website'=>'https://kovapub-mddh5jnl.manus.space','redirect_uris'=>['https://kovapub-mddh5jnl.manus.space/api/kova/oauth/callback'],'type'=>'public','verified'=>true,'disabled'=>false,'secret_hash'=>'','created_by'=>'installer','created_at'=>date('c')];
$collections['ai_gateway_settings'] = max(1, $collections['ai_gateway_settings'] ?? 0);
$collections['oauth_clients'] = max(1, $collections['oauth_clients'] ?? 0);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$locked) {
    $email = strtolower(trim((string)($_POST['admin_email'] ?? '')));
    $name = trim((string)($_POST['admin_name'] ?? ''));
    $password = (string)($_POST['admin_password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || strlen($password) < 12) $error = 'Nom, e-mail valide et mot de passe de 12 caractères minimum obligatoires.';
    elseif (empty($env['DB_HOST']) && empty($env['DB_DSN'])) $error = 'Configuration DB absente dans .env.';
    else {
        try {
            $pdo = getPdo($env);
            $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
            if ($driver === 'sqlite') $pdo->exec("CREATE TABLE IF NOT EXISTS {$table} (collection TEXT NOT NULL, record_id TEXT NOT NULL, payload TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, PRIMARY KEY (collection, record_id))");
            else $pdo->exec("CREATE TABLE IF NOT EXISTS {$table} (collection VARCHAR(120) NOT NULL, record_id VARCHAR(191) NOT NULL, payload LONGTEXT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (collection, record_id), INDEX idx_{$table}_collection (collection)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $stateCheck = $pdo->prepare("SELECT payload FROM {$table} WHERE collection='migration_state' AND record_id='initial_admin_and_database' LIMIT 1");
            $stateCheck->execute();
            if ($stateCheck->fetch()) throw new RuntimeException('Cette installation est déjà terminée.');
            $admin = ['id'=>'usr_'.bin2hex(random_bytes(8)),'email'=>$email,'display_name'=>substr($name,0,120),'password_hash'=>password_hash($password, PASSWORD_DEFAULT),'role'=>'SUPERADMIN','account_status'=>'active','verified'=>true,'verified_at'=>date('c'),'session_version'=>1,'twofa_enabled'=>false,'created_at'=>date('c'),'updated_at'=>date('c'),'last_login'=>null];
            $pdo->beginTransaction();
            $insert = $pdo->prepare("INSERT INTO {$table} (collection,record_id,payload,created_at,updated_at) VALUES (:collection,:record_id,:payload,:created_at,:updated_at)");
            $now = date('Y-m-d H:i:s');
            foreach ($collections as $collection => $_count) {
                $rows = rowsFromJson($jsonDir, $collection);
                if ($collection === 'users') $rows[] = $admin;
                if ($collection === 'ai_gateway_settings') $rows = [$gatewaySettings];
                if ($collection === 'oauth_clients') $rows = [$oauthClient];
                $delete = $pdo->prepare("DELETE FROM {$table} WHERE collection=:collection");
                $delete->execute(['collection'=>$collection]);
                foreach ($rows as $row) {
                    if (empty($row['id'])) $row['id'] = 'rec_'.bin2hex(random_bytes(10));
                    $insert->execute(['collection'=>$collection,'record_id'=>(string)$row['id'],'payload'=>json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>$now,'updated_at'=>$now]);
                }
            }
            $state = ['id'=>'initial_admin_and_database','admin_id'=>$admin['id'],'status'=>'completed','completed_at'=>date('c')];
            $insert->execute(['collection'=>'migration_state','record_id'=>'initial_admin_and_database','payload'=>json_encode($state, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>$now,'updated_at'=>$now]);
            $pdo->commit();
            @mkdir(dirname($lockFile), 0700, true);
            @file_put_contents($lockFile, date('c') . "\n", LOCK_EX);
            @chmod($lockFile, 0600);
            $locked = true;
            $message = 'Installation terminée : SUPERADMIN créé, base migrée et installateur verrouillé.' . ($gatewayToken !== '' ? ' Jeton gateway à copier dans le deuxième site : ' . $gatewayToken : ' Le jeton gateway existant a été conservé.');
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
            error_log('[KOVA installer] ' . $exception->getMessage());
            $error = 'Échec de l’installation : ' . e($exception->getMessage());
        }
    }
}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Installation KOVA</title><style>body{font-family:Arial,sans-serif;background:#f5f7fb;color:#111827;padding:28px}.card{max-width:760px;margin:auto;background:white;padding:28px;border-radius:18px;box-shadow:0 18px 60px #0f172a18}h1{margin-top:0}.muted{color:#687386}.notice{padding:14px;border-radius:12px;margin:16px 0}.ok{background:#edf9f4;color:#147653}.err{background:#fff0f2;color:#a92840}label{display:grid;gap:6px;margin:14px 0;font-weight:bold;font-size:13px}input{padding:12px;border:1px solid #d9e0ea;border-radius:10px;font:inherit}.button{background:linear-gradient(135deg,#315cf6,#7a35f5);color:white;border:0;border-radius:12px;padding:13px 18px;font-weight:bold}table{width:100%;border-collapse:collapse;margin:18px 0}td{padding:8px;border-bottom:1px solid #e3e8f0}td:last-child{text-align:right}</style></head><body><main class="card"><p class="muted">KOVA · Installation autonome</p><h1>Initialiser la plateforme</h1><p class="muted">Aucun compte, accès admin ou e-mail n’est nécessaire. Cette page crée le premier SUPERADMIN et migre les JSON vers MySQL.</p><?php if($message): ?><div class="notice ok"><?=e($message)?></div><?php endif; ?><?php if($error): ?><div class="notice err"><?=e($error)?></div><?php endif; ?><?php if(!$locked): ?><table><tr><td>Collections JSON</td><td><?=count($collections)?></td></tr><tr><td>Enregistrements</td><td><?=$total?></td></tr></table><form method="post"><label>Nom du SUPERADMIN<input name="admin_name" required maxlength="120"></label><label>E-mail de connexion<input type="email" name="admin_email" required></label><label>Mot de passe (12 caractères minimum)<input type="password" name="admin_password" minlength="12" required></label><button class="button" type="submit">Créer le SUPERADMIN et migrer</button></form><?php else: ?><div class="notice ok">Installateur verrouillé. Connectez-vous avec le SUPERADMIN créé via /connexion.</div><?php endif; ?><p class="muted">Supprimez install.php immédiatement après réussite.</p></main></body></html>
