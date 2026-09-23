<?php
declare(strict_types=1);

// Polyfills UTF-8 pour les hébergements mutualisés sans mbstring.
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $value, ?string $encoding = null): int {
        preg_match_all('/./us', $value, $m);
        return count($m[0]);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string {
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) return substr($value, $start, $length);
        return implode('', $length === null ? array_slice($chars, $start) : array_slice($chars, $start, $length));
    }
}

require_once __DIR__ . '/../app/Core/Env.php';
require_once __DIR__ . '/../app/Core/security.php';
require_once __DIR__ . '/../app/Core/Debug.php';
require_once __DIR__ . '/../app/Core/Passwords.php';
require_once __DIR__ . '/../app/Core/Totp.php';
require_once __DIR__ . '/../app/Core/JsonStore.php';
require_once __DIR__ . '/../app/Core/DatabaseStore.php';
require_once __DIR__ . '/../app/Core/Response.php';
require_once __DIR__ . '/../app/Core/Auth.php';
require_once __DIR__ . '/../app/Core/View.php';
require_once __DIR__ . '/../app/Core/Mailer.php';
require_once __DIR__ . '/../app/Core/Cloudinary.php';
require_once __DIR__ . '/../app/Core/ImageStore.php';
require_once __DIR__ . '/../app/Core/WebPush.php';
require_once __DIR__ . '/../app/Core/Guard.php';
require_once __DIR__ . '/../app/Core/Module.php';
require_once __DIR__ . '/../app/Core/Spaces.php';
require_once __DIR__ . '/../app/Core/Social.php';
require_once __DIR__ . '/../app/Core/Messaging.php';
require_once __DIR__ . '/../app/Core/Shop.php';
require_once __DIR__ . '/../app/Core/OAuth.php';
require_once __DIR__ . '/../app/Core/Account.php';
require_once __DIR__ . '/../app/Core/Router.php';
require_once __DIR__ . '/../app/Core/App.php';

$env   = new Kova\Core\Env(__DIR__ . '/../.env');

// Clé secrète applicative (signature des codes e-mail, preuves de travail, chiffrement des secrets 2FA…).
// Priorité à APP_KEY (.env). Sans elle, une clé aléatoire est créée UNE fois dans storage/ — attention : si
// ce fichier disparaît, les secrets 2FA chiffrés avec elle deviennent illisibles ; définissez APP_KEY.
$__key = (string)$env->get('APP_KEY', '');
if (strlen($__key) < 32) {
    $__keyFile = __DIR__ . '/../storage/app_secret.key';
    if (!is_file($__keyFile)) {
        @mkdir(dirname($__keyFile), 0700, true);
        @file_put_contents($__keyFile, bin2hex(random_bytes(32)), LOCK_EX);
        @chmod($__keyFile, 0600);
    }
    $__key = (string)@file_get_contents($__keyFile) ?: 'kova-insecure-fallback-' . __DIR__;
}
$GLOBALS['kova_hmac_key'] = $__key;
$GLOBALS['kova_trusted_ip_header'] = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', (string)$env->get('TRUSTED_PROXY_HEADER', '')) ?? '');
$debug = new Kova\Core\Debug($env);
Kova\Core\Security::boot($env);
$debug->boot();

$storageDriver = strtolower((string)$env->get('STORAGE_DRIVER', 'json'));
if ($storageDriver === 'json') {
    $store = new Kova\Core\JsonStore(__DIR__ . '/../' . $env->get('JSON_STORAGE_PATH', 'storage/json'));
} else {
    $store = Kova\Core\DatabaseStore::fromEnv($env);
}
$auth  = new Kova\Core\Auth($store, $env);

$GLOBALS['kova_app_url'] = rtrim((string)$env->get('APP_URL', ''), '/');
$app = new Kova\Core\App($env, $debug, $store, $auth);
