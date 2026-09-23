<?php
declare(strict_types=1);

/**
 * KOVA — diagnostic d'installation et de sécurité (LECTURE SEULE).
 *
 * Accès : uniquement en ligne de commande (php kova-check.php) OU connecté en administrateur,
 * ET seulement si CHECK_ENABLED=true (ou APP_DEBUG=true) dans .env. Sinon : 404, comme si le
 * fichier n'existait pas. Désactivez CHECK_ENABLED / supprimez ce fichier en production.
 *
 * Ce script ne crée AUCUN compte et ne touche à AUCUNE donnée réelle : les tests fonctionnels
 * (inscription, code e-mail, connexion, verrouillage, 2FA, chiffrement push, ré-encodage d'image)
 * s'exécutent sur un stockage temporaire jetable. Il n'affiche jamais de secret.
 */

$isCli = PHP_SAPI === 'cli';
ob_start();                                            // garde les en-têtes de sécurité lisibles avant la sortie
require __DIR__ . '/bootstrap/app.php';                // définit $env, $store, $auth, $app (sans exécuter la requête)

use Kova\Core\{Auth, Guard, ImageStore, JsonStore, Passwords, Totp, WebPush, Cloudinary};

$enabled = $env->bool('CHECK_ENABLED', false) || $env->bool('APP_DEBUG', false);
$authorised = false;
if ($enabled) {
    if ($isCli) {
        $authorised = true;
    } else {
        $u = $auth->user();
        $authorised = $u && $app->isAdmin($u);
    }
}
if (!$authorised) {
    ob_end_clean();
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found";
    exit;
}

$results = [];
function check(string $group, string $label, string $status, string $detail = ''): void {
    global $results;
    $results[] = compact('group', 'label', 'status', 'detail');
}
$ok   = fn(string $g, string $l, string $d = '') => check($g, $l, 'ok', $d);
$warn = fn(string $g, string $l, string $d = '') => check($g, $l, 'warn', $d);
$fail = fn(string $g, string $l, string $d = '') => check($g, $l, 'fail', $d);
$root = __DIR__;

// ── 1. Environnement PHP ────────────────────────────────────────────────
$g = 'PHP';
version_compare(PHP_VERSION, '8.1.0', '>=') ? $ok($g, 'Version PHP', PHP_VERSION) : $fail($g, 'Version PHP', PHP_VERSION . ' — 8.1 minimum requis');
foreach (['json' => true, 'session' => true, 'hash' => true, 'mbstring' => false, 'curl' => true, 'openssl' => true, 'fileinfo' => true, 'gd' => false, 'exif' => false, 'iconv' => false] as $ext => $required) {
    if (extension_loaded($ext)) { $ok($g, "Extension $ext"); continue; }
    $why = ['gd' => 'sans GD, les images ne sont pas ré-encodées (seul un contrôle par signature s’applique)', 'exif' => 'l’orientation des photos de téléphone ne sera pas corrigée', 'mbstring' => 'polyfill utilisé', 'iconv' => 'la politique de mots de passe est un peu moins fine'][$ext] ?? '';
    $required ? $fail($g, "Extension $ext", 'manquante') : $warn($g, "Extension $ext", $why);
}
function_exists('openssl_pkey_derive') ? $ok($g, 'ECDH (openssl_pkey_derive) — notifications push') : $warn($g, 'openssl_pkey_derive absent', 'Web Push impossible ; le mode de secours (KOVA ouvert) reste actif');
defined('PASSWORD_ARGON2ID') ? $ok($g, 'Hachage argon2id disponible') : $warn($g, 'argon2id indisponible', 'bcrypt (coût 12) utilisé à la place : acceptable');

// ── 2. Syntaxe de tous les fichiers PHP (détecte une erreur avant qu'un visiteur ne la trouve) ──
$g = 'Syntaxe PHP';
$phpFiles = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
    $p = $f->getPathname();
    if ($f->isFile() && str_ends_with($p, '.php') && !str_contains($p, '/vendor/') && !str_contains($p, '/storage/')) $phpFiles[] = $p;
}
$bad = 0;
foreach ($phpFiles as $p) {
    try { token_get_all((string)file_get_contents($p), TOKEN_PARSE); }
    catch (\ParseError $e) { $bad++; $fail($g, str_replace($root . '/', '', $p), 'ligne ' . $e->getLine() . ' : ' . $e->getMessage()); }
}
$bad === 0 ? $ok($g, count($phpFiles) . ' fichiers PHP analysés', 'aucune erreur de syntaxe') : null;

// ── 3. Classes et cohérence front ↔ serveur ─────────────────────────────
$g = 'Application';
foreach (['App', 'Auth', 'Router', 'Security', 'JsonStore', 'Passwords', 'Guard', 'Totp', 'ImageStore', 'WebPush', 'Account', 'Spaces', 'Social', 'Messaging', 'Shop', 'OAuth', 'Mailer', 'Cloudinary'] as $c) {
    class_exists("Kova\\Core\\$c") ? null : $fail($g, "Classe $c", 'introuvable : fichier non chargé dans bootstrap/app.php ?');
}
$phpSrc = '';
foreach (glob($root . '/app/Core/*.php') ?: [] as $p) $phpSrc .= file_get_contents($p);
$missingRoutes = [];
foreach (glob($root . '/assets/js/*.js') ?: [] as $js) {
    preg_match_all('#["\'`]/api/([a-z0-9_/-]+)#i', (string)file_get_contents($js), $m);
    foreach (array_unique($m[1]) as $route) {
        $route = rtrim($route, '/');
        if ($route === '' || str_contains($phpSrc, "'" . $route) || str_contains($phpSrc, '"' . $route) || str_contains($phpSrc, '#^' . $route) || str_contains($phpSrc, '^' . $route)) continue;
        // routes dynamiques : on vérifie le premier segment + le suivant
        $segs = explode('/', $route); $probe = implode('/', array_slice($segs, 0, 2));
        if (!str_contains($phpSrc, $probe)) $missingRoutes[] = basename($js) . ' → /api/' . $route;
    }
}
$missingRoutes ? $warn($g, 'Appels JS sans route serveur reconnue', implode(' ; ', array_slice($missingRoutes, 0, 8))) : $ok($g, 'Toutes les routes /api appelées par le JS existent côté serveur');
$viewsMissing = [];
foreach (glob($root . '/resources/views/*.php') ?: [] as $v) {
    if (preg_match('/\$pageScripts\s*=\s*\[([^\]]*)\]/', (string)file_get_contents($v), $mm)) {
        foreach (preg_split('/[\s,\'"]+/', $mm[1], -1, PREG_SPLIT_NO_EMPTY) as $name) {
            if (!is_file($root . "/assets/js/kova-$name.js")) $viewsMissing[] = basename($v) . ' → kova-' . $name . '.js';
        }
    }
}
$viewsMissing ? $fail($g, 'Modules JS référencés par une vue mais absents', implode(' ; ', $viewsMissing)) : $ok($g, 'Tous les modules JS référencés par les vues existent');
foreach (['assets/css/kova.css', 'assets/js/kova.js', 'sw.js', 'manifest.webmanifest', 'assets/images/kova-logo.svg'] as $f) is_file("$root/$f") ? null : $fail($g, "Fichier $f", 'manquant');
is_file("$root/assets/images/kova-og.png") ? $ok($g, 'Image de partage kova-og.png') : $warn($g, 'kova-og.png absent', 'aperçu de partage (WhatsApp/Facebook) sans image');
$inline = [];
foreach (glob($root . '/resources/views/*.php') ?: [] as $v) {
    if (preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', (string)file_get_contents($v))) $inline[] = basename($v);
    if (preg_match('/\son(click|submit|change|error|load)\s*=/i', (string)file_get_contents($v))) $inline[] = basename($v) . ' (on*=)';
}
$inline ? $fail($g, 'Script inline détecté (bloqué par la CSP)', implode(', ', $inline)) : $ok($g, 'Aucun script inline : compatible avec la CSP stricte');

// ── 4. Configuration ─────────────────────────────────────────────────────
$g = 'Configuration';
$url = (string)$env->get('APP_URL', '');
str_starts_with(strtolower($url), 'https://') ? $ok($g, 'APP_URL en HTTPS', $url) : $warn($g, 'APP_URL', $url === '' ? 'non défini' : 'pas en https://');
strlen((string)$env->get('APP_KEY', '')) >= 32 ? $ok($g, 'APP_KEY (≥ 32 caractères)') : $warn($g, 'APP_KEY trop courte ou absente', 'une clé aléatoire est générée dans storage/ ; définissez APP_KEY pour ne pas perdre les secrets 2FA si storage/ est vidé');
$env->bool('APP_DEBUG', false) ? $warn($g, 'APP_DEBUG=true', 'à désactiver en production') : $ok($g, 'APP_DEBUG désactivé');
($env->get('MAIL_HOST', '') && $env->get('MAIL_USERNAME', '')) ? $ok($g, 'SMTP configuré') : $warn($g, 'SMTP non configuré', 'les codes de vérification e-mail ne partiront pas');
$wp = new WebPush($env);
$wp->configured() ? $ok($g, 'Clés VAPID (push) configurées') : $warn($g, 'Notifications push serveur non configurées', 'VAPID_PUBLIC_KEY/PRIVATE_KEY/SUBJECT ; sinon mode de secours uniquement');
$cloud = new Cloudinary($env);
$mode = strtolower((string)$env->get('IMAGE_STORAGE', 'auto'));
($cloud->configured() || $mode === 'local') ? $ok($g, 'Stockage d’images', $mode . ($cloud->configured() ? ' (Cloudinary configuré)' : ' (dossier local)')) : $ok($g, 'Stockage d’images', 'dossier local uploads/img (Cloudinary non configuré)');
$env->get('SESSION_EPOCH', '') !== '' ? $ok($g, 'SESSION_EPOCH défini') : $warn($g, 'SESSION_EPOCH non défini', 'valeur par défaut 3 ; changez-la pour déconnecter tout le monde après un incident');
if ($env->bool('ADMIN_2FA_REQUIRED', false)) $ok($g, '2FA obligatoire pour les administrateurs');
else $warn($g, 'ADMIN_2FA_REQUIRED=false', 'recommandé : true, une fois la 2FA activée sur votre compte admin');

// ── 5. Fichiers, permissions, exposition ────────────────────────────────
$g = 'Stockage & exposition';
$sj = $root . '/' . trim((string)$env->get('JSON_STORAGE_PATH', 'storage/json'), '/');
if (is_dir($sj) && is_writable($sj)) {
    $t = $sj . '/.check_' . bin2hex(random_bytes(4));
    (@file_put_contents($t, 'x') !== false) ? (@unlink($t) || true) && $ok($g, 'storage/json accessible en écriture') : $fail($g, 'storage/json', 'écriture impossible');
} else $fail($g, 'storage/json', 'introuvable ou non inscriptible');
is_file($sj . '/.htaccess') ? $ok($g, 'storage/json/.htaccess (accès web interdit)') : $warn($g, 'storage/json/.htaccess absent', 'recréé automatiquement au prochain accès ; la règle du .htaccess racine protège déjà /storage');
$perm = is_dir($sj) ? (fileperms($sj) & 0777) : 0;
($perm & 0007) ? $warn($g, 'Permissions de storage/json', decoct($perm) . ' : lisible par tous') : $ok($g, 'Permissions de storage/json', decoct($perm));
is_file($root . '/uploads/.htaccess') ? $ok($g, 'uploads/.htaccess (aucun script exécutable)') : $fail($g, 'uploads/.htaccess absent', 'les fichiers envoyés pourraient être exécutés !');
is_dir($root . '/uploads/img') || @mkdir($root . '/uploads/img', 0755, true) ? (is_writable($root . '/uploads/img') ? $ok($g, 'uploads/img inscriptible') : $warn($g, 'uploads/img non inscriptible', 'images locales impossibles')) : $warn($g, 'uploads/img', 'création impossible');
foreach (['debug' => 'api-test.php', 'i' => 'i.php'] as $f) if (is_file("$root/$f")) $warn($g, "Fichier de diagnostic $f présent", 'à supprimer en production');
foreach (glob($root . '/test-*') ?: [] as $f) $warn($g, 'Fichier de test ' . basename($f), 'à supprimer en production');
if ($url !== '' && function_exists('curl_init')) {
    foreach (['/.env' => 'fichier .env', '/storage/json/users.json' => 'données utilisateurs', '/app/Core/App.php' => 'code source', '/uploads/.htaccess' => 'config uploads', '/tools/oauth-client-example/kova-login.php' => 'tools/'] as $p => $what) {
        $ch = curl_init(rtrim($url, '/') . $p);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => false]);
        curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code === 0) { $warn($g, "Exposition $what", 'test impossible (connexion sortante bloquée ?)'); break; }
        in_array($code, [401, 403, 404], true) ? $ok($g, "$p inaccessible depuis le web", "HTTP $code") : $fail($g, "$p ACCESSIBLE depuis le web !", "HTTP $code — $what");
    }
}

// ── 6. Session et en-têtes ──────────────────────────────────────────────
$g = 'Session & en-têtes';
$cp = session_get_cookie_params();
!empty($cp['httponly']) ? $ok($g, 'Cookie de session HttpOnly') : $fail($g, 'Cookie de session non HttpOnly');
(($cp['samesite'] ?? '') !== '') ? $ok($g, 'Cookie SameSite', (string)$cp['samesite']) : $warn($g, 'Cookie sans SameSite');
(Kova\Core\Security::isHttps() ? !empty($cp['secure']) : true) ? $ok($g, 'Cookie Secure (HTTPS)') : $fail($g, 'HTTPS détecté mais cookie non Secure');
ini_get('session.use_strict_mode') === '1' ? $ok($g, 'session.use_strict_mode=1', 'anti-fixation de session') : $fail($g, 'session.use_strict_mode inactif');
$hdrs = strtolower(implode("\n", headers_list()));
foreach (['content-security-policy' => 'CSP', 'x-content-type-options' => 'nosniff', 'x-frame-options' => 'X-Frame-Options', 'referrer-policy' => 'Referrer-Policy', 'permissions-policy' => 'Permissions-Policy'] as $h => $label) {
    str_contains($hdrs, $h) ? $ok($g, "En-tête $label") : $fail($g, "En-tête $label absent");
}
preg_match('/content-security-policy:([^\n]*)/', $hdrs, $mcsp);
$scriptSrc = '';
if (!empty($mcsp[1]) && preg_match('/script-src([^;]*)/', $mcsp[1], $ms)) $scriptSrc = $ms[1];
(str_contains($scriptSrc, 'unsafe-inline') || str_contains($scriptSrc, 'unsafe-eval')) ? $fail($g, 'CSP script-src trop permissive', trim($scriptSrc)) : $ok($g, 'CSP script-src stricte', trim($scriptSrc));

// ── 7. Tests fonctionnels (stockage TEMPORAIRE, aucune donnée réelle) ──
$g = 'Tests fonctionnels';
$tmp = sys_get_temp_dir() . '/kovacheck_' . bin2hex(random_bytes(6));
@mkdir($tmp, 0700, true);
try {
    $ts = new JsonStore($tmp);
    $tauth = new Auth($ts, $env);
    $email = 'test-' . bin2hex(random_bytes(3)) . '@example.com';
    $pw = 'Zx9!kL2#mQ7v';

    // Politique de mots de passe
    Passwords::validate('aaaaaaaaaa') !== null && Passwords::validate('1234567890') !== null && Passwords::validate('password123') !== null
        ? $ok($g, 'Politique de mots de passe : valeurs triviales refusées') : $fail($g, 'Politique de mots de passe trop permissive');
    Passwords::validate($pw, $email, 'Testeur') === null ? $ok($g, 'Politique de mots de passe : mot de passe robuste accepté') : $fail($g, 'Un mot de passe robuste est refusé', (string)Passwords::validate($pw, $email, 'Testeur'));
    $h = Passwords::hash($pw);
    (Passwords::verify($pw, $h) && !Passwords::verify('autre', $h) && !Passwords::verify($pw, null)) ? $ok($g, 'Hachage / vérification des mots de passe') : $fail($g, 'Hachage des mots de passe');

    // Inscription + code e-mail (régression de la faille « verify-code sur compte déjà vérifié »)
    $u = $tauth->register($email, $pw, '1990-01-01', 'Testeur');
    $code = (string)($u['plain_code'] ?? '');
    $tauth->verifyCode($email, $code === '000000' ? '111111' : '000000')['ok'] === false ? $ok($g, 'Code e-mail erroné refusé') : $fail($g, 'Un code e-mail erroné est ACCEPTÉ');
    $tauth->verifyCode($email, $code)['ok'] === true ? $ok($g, 'Code e-mail correct accepté') : $fail($g, 'Le code e-mail correct est refusé');
    $tauth->verifyCode($email, '000000')['ok'] === false && $tauth->verifyCode($email, $code)['ok'] === false
        ? $ok($g, 'Compte déjà vérifié : AUCUN code n’ouvre de session (faille corrigée)') : $fail($g, 'FAILLE : un compte déjà vérifié accepte un code');
    (($tauth->register($email, $pw, '1990-01-01', 'Autre')['duplicate'] ?? false) === true) ? $ok($g, 'Inscription avec une adresse existante : réponse neutre') : $fail($g, 'Doublon d’e-mail non géré');

    // Connexion + 2FA
    $tauth->checkCredentials($email, 'mauvais')['status'] === 'invalid' ? $ok($g, 'Mauvais mot de passe refusé') : $fail($g, 'Mauvais mot de passe accepté');
    $tauth->checkCredentials('inconnu@example.com', $pw)['status'] === 'invalid' ? $ok($g, 'Compte inconnu : même réponse qu’un mauvais mot de passe') : $fail($g, 'Compte inconnu accepté');
    $tauth->checkCredentials($email, $pw)['status'] === 'ok' ? $ok($g, 'Connexion valide reconnue') : $fail($g, 'Connexion valide refusée');
    $secret = Totp::generateSecret();
    $ts->updateWhere('users', fn($r) => $r['email'] === $email, fn($r) => array_merge($r, ['twofa_enabled' => true, 'twofa_secret_enc' => Totp::seal($secret)]));
    $tauth->checkCredentials($email, $pw)['status'] === 'twofa' ? $ok($g, '2FA : la connexion exige la seconde étape') : $fail($g, '2FA ignorée à la connexion');
    Totp::open(Totp::seal($secret)) === $secret ? $ok($g, '2FA : secret chiffré au repos, relu correctement') : $fail($g, 'Chiffrement du secret 2FA');
    Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1) === '287082' ? $ok($g, 'TOTP conforme au vecteur RFC 6238') : $fail($g, 'TOTP non conforme à la RFC 6238');
    Totp::verify($secret, Totp::code($secret, intdiv(time(), 30))) !== null && Totp::verify($secret, '000000') === null ? $ok($g, 'TOTP : bon code accepté, faux code refusé') : $fail($g, 'Vérification TOTP');

    // Anti-force-brute
    $guard = new Guard($ts, $env);
    for ($i = 0; $i < 5; $i++) $guard->fail($email);
    ($guard->status($email)['locked'] > 0) ? $ok($g, 'Verrouillage après 5 échecs', $guard->status($email)['locked'] . ' s') : $fail($g, 'Aucun verrouillage après 5 échecs');
    $ch = $guard->issueChallenge(8);
    $nonce = null;
    for ($n = 0; $n < 200000; $n++) { $d = hash('sha256', $ch['challenge'] . ':' . $n, true); if (ord($d[0]) === 0) { $nonce = (string)$n; break; } }
    ($nonce !== null && $guard->verifyProof($ch['challenge'], $nonce, 8) && !$guard->verifyProof($ch['challenge'], $nonce, 8))
        ? $ok($g, 'Preuve de travail : valide une fois, rejouée = refusée') : $fail($g, 'Preuve de travail');

    // Ré-encodage d'image : une image « polyglotte » (JPEG + PHP) doit être nettoyée
    if (function_exists('imagecreatetruecolor')) {
        $im = imagecreatetruecolor(64, 64); $f = $tmp . '/p.jpg'; imagejpeg($im, $f, 80); imagedestroy($im);
        file_put_contents($f, "\n<?php echo 'PWNED'; ?>\n<script>alert(1)</script>", FILE_APPEND);
        $store = new ImageStore($env, $cloud);
        $m = new ReflectionMethod($store, 'sanitize');
        $r = $m->invoke($store, $f, IMAGETYPE_JPEG, 64, 64);
        if (is_array($r) && isset($r['data'])) {
            $body = (string)$r['data'];
            (!str_contains($body, '<?php') && !str_contains($body, '<script')) ? $ok($g, 'Image piégée (JPEG+PHP) : code injecté détruit au ré-encodage') : $fail($g, 'Image piégée non nettoyée');
        } else $fail($g, 'Ré-encodage d’image', 'échec');
        $probe = (new ReflectionMethod($store, 'storeLocal'))->invoke($store, "\xFF\xD8\xFF" . str_repeat('a', 200), 'jpg');
        if (is_string($probe)) { $ok($g, 'Enregistrement local des images', 'uploads/img inscriptible'); @unlink($root . $probe); }
        else $fail($g, 'Enregistrement local des images impossible', 'vérifiez les droits d’écriture de uploads/img');
    } else $warn($g, 'Test de ré-encodage d’image ignoré', 'GD absent');

    // Web Push : chiffrement de bout en bout (aller-retour complet, côté « navigateur » simulé)
    if (function_exists('openssl_pkey_derive')) {
        $ua = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $d = openssl_pkey_get_details($ua);
        $uaPub = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $auth16 = random_bytes(16);
        $plain = '{"title":"KOVA","body":"test é"}';
        $body = $wp->encrypt($plain, $uaPub, $auth16);
        if ($body === null) { $fail($g, 'Web Push : chiffrement impossible'); }
        else {
            $salt = substr($body, 0, 16); $asPub = substr($body, 21, 65); $ct = substr($body, 86);
            $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $asPub), 64, "\n") . "-----END PUBLIC KEY-----\n";
            $asKey = openssl_pkey_get_public($pem);
            $shared = @openssl_pkey_derive($asKey, $ua, 32);
            if (!is_string($shared) || strlen($shared) !== 32) $shared = @openssl_pkey_derive($ua, $asKey, 32);
            $ikm = hash_hkdf('sha256', (string)$shared, 32, "WebPush: info\0" . $uaPub . $asPub, $auth16);
            $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
            $nonceB = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
            $dec = openssl_decrypt(substr($ct, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonceB, substr($ct, -16));
            ($dec !== false && $dec === $plain . "\x02") ? $ok($g, 'Web Push : chiffrement RFC 8291 vérifié (aller-retour)') : $fail($g, 'Web Push : le message chiffré ne se déchiffre pas');
        }
        if ($wp->configured()) {
            $jwt = (new ReflectionMethod($wp, 'vapidJwt'))->invoke($wp, 'https://fcm.googleapis.com');
            (is_string($jwt) && count(explode('.', $jwt)) === 3) ? $ok($g, 'Web Push : jeton VAPID (ES256) signé') : $fail($g, 'Web Push : signature VAPID impossible', 'vérifiez VAPID_PRIVATE_KEY (base64url, 32 octets) et VAPID_PUBLIC_KEY (65 octets)');
        }
    } else $warn($g, 'Test Web Push ignoré', 'openssl_pkey_derive absent');
} catch (\Throwable $e) {
    $fail($g, 'Exception pendant les tests', get_class($e) . ' : ' . $e->getMessage());
} finally {
    foreach (glob($tmp . '/{,.}*', GLOB_BRACE) ?: [] as $x) if (is_file($x)) @unlink($x);
    @rmdir($tmp);
}

// ── Sortie ──────────────────────────────────────────────────────────────
$counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];
foreach ($results as $r) $counts[$r['status']]++;
ob_end_clean();
$wantJson = $isCli ? in_array('--json', $argv ?? [], true) : (($_GET['format'] ?? '') === 'json');
if ($wantJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['counts' => $counts, 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit($counts['fail'] ? 1 : 0);
}
if ($isCli) {
    foreach ($results as $r) printf("[%s] %-22s %s %s\n", strtoupper($r['status']), $r['group'], $r['label'], $r['detail'] !== '' ? '— ' . $r['detail'] : '');
    printf("\nOK: %d   Avertissements: %d   Erreurs: %d\n", $counts['ok'], $counts['warn'], $counts['fail']);
    exit($counts['fail'] ? 1 : 0);
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Diagnostic KOVA</title>
<style>body{font:14px/1.5 system-ui,sans-serif;background:#0b1020;color:#e8ecf7;margin:0;padding:24px}main{max-width:980px;margin:auto}h1{margin:0 0 6px}table{width:100%;border-collapse:collapse;margin:14px 0}td{padding:7px 10px;border-bottom:1px solid #1e2a4a;vertical-align:top}.g{color:#9aa6c0;width:180px}.ok{color:#4ade80}.warn{color:#fbbf24}.fail{color:#f87171;font-weight:700}.pill{display:inline-block;padding:2px 10px;border-radius:99px;background:#18233f;margin-right:8px}small{color:#9aa6c0}</style></head><body><main>
<h1>Diagnostic KOVA</h1><p><span class="pill ok"><?= $counts['ok'] ?> OK</span><span class="pill warn"><?= $counts['warn'] ?> avertissements</span><span class="pill fail"><?= $counts['fail'] ?> erreurs</span></p>
<p><small>Lecture seule — aucun compte ni donnée réelle modifiés. Désactivez CHECK_ENABLED ou supprimez ce fichier en production.</small></p>
<table><?php foreach ($results as $r): ?><tr><td class="g"><?= $e($r['group']) ?></td><td class="<?= $e($r['status']) ?>"><?= ['ok' => '✔', 'warn' => '⚠', 'fail' => '✘'][$r['status']] ?> <?= $e($r['label']) ?><?= $r['detail'] !== '' ? '<br><small>' . $e($r['detail']) . '</small>' : '' ?></td></tr><?php endforeach; ?></table>
</main></body></html>
