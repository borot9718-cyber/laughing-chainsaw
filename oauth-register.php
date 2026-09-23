<?php
declare(strict_types=1);

/* Outil temporaire : à supprimer immédiatement après réussite. */
function loadEnv(string $file): array { $out=[]; foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) { $line=trim($line); if($line==='' || str_starts_with($line,'#') || !str_contains($line,'=')) continue; [$key,$value]=explode('=',$line,2); $out[trim($key)]=trim($value," \t\n\r\0\x0B\"'"); } return $out; }
function h(string $v): string { return htmlspecialchars($v,ENT_QUOTES,'UTF-8'); }
$root=__DIR__; $env=loadEnv($root.'/.env'); $lock=$root.'/storage/kova-install.lock'; $error=''; $done=false;
if(!is_file($lock)) $error='La migration principale doit être terminée avant cet enregistrement.';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST' && $error==='') {
  try {
    $dsn='mysql:host='.($env['DB_HOST']??'').';port='.($env['DB_PORT']??'3306').';dbname='.($env['DB_DATABASE']??'').';charset='.($env['DB_CHARSET']??'utf8mb4');
    $pdo=new PDO($dsn,(string)($env['DB_USERNAME']??''),(string)($env['DB_PASSWORD']??''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $table=(string)($env['DB_TABLE']??'kova_records'); if(!preg_match('/^[A-Za-z0-9_]+$/',$table)) $table='kova_records';
    $client=['client_id'=>'kova-ai-publisher','name'=>'KOVA AI Publisher','description'=>'Assistant de publication IA KOVA','website'=>'https://kovapub-mddh5jnl.manus.space','redirect_uris'=>['https://kovapub-mddh5jnl.manus.space/api/kova/oauth/callback'],'type'=>'public','verified'=>true,'disabled'=>false,'secret_hash'=>'','created_by'=>'installer','created_at'=>date('c')];
    $payload=json_encode($client,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); $now=date('Y-m-d H:i:s');
    $check=$pdo->prepare("SELECT record_id FROM {$table} WHERE collection='oauth_clients' AND record_id='kova-ai-publisher' LIMIT 1"); $check->execute();
    if($check->fetch()) { $q=$pdo->prepare("UPDATE {$table} SET payload=:payload,updated_at=:updated_at WHERE collection='oauth_clients' AND record_id='kova-ai-publisher'"); $q->execute(['payload'=>$payload,'updated_at'=>$now]); }
    else { $q=$pdo->prepare("INSERT INTO {$table} (collection,record_id,payload,created_at,updated_at) VALUES ('oauth_clients','kova-ai-publisher',:payload,:created_at,:updated_at)"); $q->execute(['payload'=>$payload,'created_at'=>$now,'updated_at'=>$now]); }
    $done=true;
  } catch(Throwable $e) { error_log('[KOVA oauth register] '.$e->getMessage()); $error='Impossible d’enregistrer l’application OAuth. Vérifiez la connexion MySQL.'; }
}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>OAuth KOVA</title><style>body{font-family:Arial,sans-serif;background:#f5f7fb;color:#111827;padding:28px}.card{max-width:700px;margin:auto;background:#fff;padding:28px;border-radius:18px;box-shadow:0 18px 60px #0f172a18}h1{margin-top:0}.muted{color:#687386}.notice{padding:14px;border-radius:12px;margin:16px 0}.ok{background:#edf9f4;color:#147653}.err{background:#fff0f2;color:#a92840}.button{background:linear-gradient(135deg,#315cf6,#7a35f5);color:#fff;border:0;border-radius:12px;padding:13px 18px;font-weight:700}</style></head><body><main class="card"><p class="muted">KOVA · Connexion de profil</p><h1>Enregistrer KOVA AI Publisher</h1><p class="muted">Cette page ajoute uniquement l’application OAuth et son adresse de retour. Elle ne relance pas la migration.</p><?php if($error): ?><div class="notice err"><?=h($error)?></div><?php elseif($done): ?><div class="notice ok">Application OAuth enregistrée. Vous pouvez maintenant reconnecter votre profil depuis KOVA AI Publisher.</div><p class="muted"><strong>Supprimez oauth-register.php immédiatement.</strong></p><?php else: ?><form method="post"><button class="button" type="submit">Enregistrer l’application OAuth</button></form><?php endif; ?></main></body></html>
