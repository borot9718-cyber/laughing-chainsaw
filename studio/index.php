<?php
declare(strict_types=1);
require_once __DIR__ . '/services.php';
$path = trim((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)), '/');
if (str_starts_with($path, 'studio/')) $path = substr($path, 7);
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($path === 'api/handshake' && $method === 'GET') studio_api(fn()=>array_merge(['ok'=>true], (new KovaClient())->handshake()));
if ($path === 'api/style' && $method === 'GET') studio_api(fn()=>array_merge(['ok'=>true], (new KovaClient())->style()));
if ($path === 'api/generate/text' && $method === 'POST') studio_api(function(){ $in=studio_input(); $text=(new OpenAIProvider())->text((string)($in['idea']??''), (array)($in['options']??[])); $item=['id'=>'gen_'.bin2hex(random_bytes(8)),'type'=>'text','idea'=>$in['idea']??'','content'=>$text,'status'=>'ready','created_at'=>date('c')]; $rows=studio_json('history'); array_unshift($rows,$item); studio_save('history',array_slice($rows,0,500)); return ['ok'=>true,'item'=>$item]; });
if ($path === 'api/publish' && $method === 'POST') studio_api(function(){ $in=studio_input(); $payload=['content'=>trim((string)($in['content']??'')),'visibility'=>(string)($in['visibility']??'public'),'base_idea'=>(string)($in['idea']??''),'instructions'=>(string)($in['instructions']??'')]; if($payload['content']==='') throw new RuntimeException('Le contenu est obligatoire.'); if(!empty($in['image_data'])) $payload['image_data']=$in['image_data']; $result=(new KovaClient())->publish($payload); return ['ok'=>true,'publication'=>$result]; });
if ($path === 'api/schedule' && $method === 'POST') studio_api(function(){ $in=studio_input(); $at=(string)($in['scheduled_at']??''); $time=strtotime($at); if(!$time||$time<=time()) throw new RuntimeException('scheduled_at doit être une date future valide.'); $payload=['content'=>trim((string)($in['content']??'')),'scheduled_at'=>date(DATE_ATOM,$time),'visibility'=>(string)($in['visibility']??'public'),'base_idea'=>(string)($in['idea']??''),'instructions'=>(string)($in['instructions']??'')]; if(!empty($in['image_data'])) $payload['image_data']=$in['image_data']; $result=(new KovaClient())->publish($payload); return ['ok'=>true,'scheduled_at'=>$payload['scheduled_at'],'publication'=>$result]; });
if ($path === 'api/history' && $method === 'GET') studio_api(fn()=>['ok'=>true,'items'=>studio_json('history')]);
if ($path === '' || $path === 'index.php') { require __DIR__.'/view.php'; exit; }
http_response_code(404); echo 'Not found';
