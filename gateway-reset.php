<?php
declare(strict_types=1);

/* Outil temporaire : à supprimer immédiatement après affichage du nouveau jeton. */
function envFile(string $file): array {
    $out=[];
    foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
        $line=trim($line); if ($line==='' || str_starts_with($line,'#') || !str_contains($line,'=')) continue;
        [$key,$value]=explode('=',$line,2); $out[trim($key)]=trim($value," \t\n\r\0\x0B\"'");
    }
    return $out;
}
function h(string $value): string { return htmlspecialchars($value,ENT_QUOTES,'UTF-8'); }
$root=__DIR__; $env=envFile($root.'/.env'); $lock=$root.'/storage/kova-install.lock'; $error=''; $token='';
if (!is_file($lock)) $error='La migration principale n’est pas marquée comme terminée. Utilisez d’abord install.php.';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST' && $error==='') {
    try {
        $dsn='mysql:host='.($env['DB_HOST']??'').';port='.($env['DB_PORT']??'3306').';dbname='.($env['DB_DATABASE']??'').';charset='.($env['DB_CHARSET']??'utf8mb4');
        $pdo=new PDO($dsn,(string)($env['DB_USERNAME']??''),(string)($env['DB_PASSWORD']??''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $table=(string)($env['DB_TABLE']??'kova_records'); if(!preg_match('/^[A-Za-z0-9_]+$/',$table)) $table='kova_records';
        $token='kova_ai_'.bin2hex(random_bytes(24));
        $settings=['id'=>'default','enabled'=>true,'token_hash'=>hash('sha256',$token),'token_prefix'=>substr($token,0,16),'style'=>'','posts_per_day'=>1,'publish_hours'=>['09:00'],'updated_at'=>date('c'),'updated_by'=>'gateway-reset'];
        $payload=json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); $now=date('Y-m-d H:i:s');
        $check=$pdo->prepare("SELECT record_id FROM {$table} WHERE collection='ai_gateway_settings' AND record_id='default' LIMIT 1"); $check->execute();
        if($check->fetch()) { $update=$pdo->prepare("UPDATE {$table} SET payload=:payload,updated_at=:updated_at WHERE collection='ai_gateway_settings' AND record_id='default'"); $update->execute(['payload'=>$payload,'updated_at'=>$now]); }
        else { $insert=$pdo->prepare("INSERT INTO {$table} (collection,record_id,payload,created_at,updated_at) VALUES ('ai_gateway_settings','default',:payload,:created_at,:updated_at)"); $insert->execute(['payload'=>$payload,'created_at'=>$now,'updated_at'=>$now]); }
        $oauth=['client_id'=>'kova-ai-publisher','name'=>'KOVA AI Publisher','description'=>'Assistant de publication IA KOVA','website'=>'https://kovapub-mddh5jnl.manus.space','redirect_uris'=>['https://kovapub-mddh5jnl.manus.space/api/kova/oauth/callback'],'type'=>'public','verified'=>true,'disabled'=>false,'secret_hash'=>'','created_by'=>'installer','created_at'=>date('c')];
        $oauthPayload=json_encode($oauth,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); $clientCheck=$pdo->prepare("SELECT record_id FROM {$table} WHERE collection='oauth_clients' AND record_id='kova-ai-publisher' LIMIT 1"); $clientCheck->execute();
        if($clientCheck->fetch()) { $clientUpdate=$pdo->prepare("UPDATE {$table} SET payload=:payload,updated_at=:updated_at WHERE collection='oauth_clients' AND record_id='kova-ai-publisher'"); $clientUpdate->execute(['payload'=>$oauthPayload,'updated_at'=>$now]); }
        else { $clientInsert=$pdo->prepare("INSERT INTO {$table} (collection,record_id,payload,created_at,updated_at) VALUES ('oauth_clients','kova-ai-publisher',:payload,:created_at,:updated_at)"); $clientInsert->execute(['payload'=>$oauthPayload,'created_at'=>$now,'updated_at'=>$now]); }
    } catch(Throwable $exception) { error_log('[KOVA gateway reset] '.$exception->getMessage()); $error='Impossible de régénérer le jeton. Vérifiez la connexion MySQL.'; }
}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Jeton gateway KOVA</title><style>body{font-family:Arial,sans-serif;background:#f5f7fb;color:#111827;padding:28px}.card{max-width:700px;margin:auto;background:white;padding:28px;border-radius:18px;box-shadow:0 18px 60px #0f172a18}h1{margin-top:0}.muted{color:#687386}.notice{padding:14px;border-radius:12px;margin:16px 0}.ok{background:#edf9f4;color:#147653}.err{background:#fff0f2;color:#a92840}.button{border:0;border-radius:12px;padding:13px 18px;font-weight:700;background:linear-gradient(135deg,#315cf6,#7a35f5);color:#fff;cursor:pointer}.token{display:block;word-break:break-all;background:#111827;color:#a7f3d0;padding:16px;border-radius:12px;font-family:monospace;font-size:15px}</style></head><body><main class="card"><p class="muted">KOVA · Passerelle</p><h1>Régénérer le jeton gateway</h1><p class="muted">La migration étant déjà terminée, cet outil remplace uniquement le jeton de connexion entre le site IA et la plateforme principale.</p><?php if($error): ?><div class="notice err"><?=h($error)?></div><?php elseif($token): ?><div class="notice ok">Nouveau jeton créé. Copiez-le maintenant dans KOVA AI Publisher :</div><code class="token"><?=h($token)?></code><p class="muted"><strong>Important :</strong> supprimez gateway-reset.php immédiatement après avoir copié le jeton.</p><?php else: ?><form method="post"><button class="button" type="submit">Générer un nouveau jeton</button></form><p class="muted">Cette action désactive l’ancien jeton.</p><?php endif; ?></main></body></html>
