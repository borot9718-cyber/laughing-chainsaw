<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap/app.php';
$now=time(); $count=0;
$count=$store->updateWhere('posts', function(array $p) use($now): bool { return ($p['publication_status']??'')==='scheduled' && !empty($p['scheduled_at']) && (($t=strtotime((string)$p['scheduled_at']))!==false && $t <= $now) && empty($p['deleted']); }, function(array $p): array { $p['publication_status']='published'; $p['published_at']=date('c'); $p['updated_at']=date('c'); return $p; });
echo json_encode(['ok'=>true,'published'=>$count,'at'=>date('c')], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
