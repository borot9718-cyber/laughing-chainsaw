<?php
use Kova\Core\View;
$user = is_array($user ?? null) ? $user : [];
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$route = trim((string)($_GET['route'] ?? ''), '/');
$effectiveRoute = $route !== '' ? $route : trim($path, '/');
$privateRoutes = ['app','profil','messages','notifications','groupes','communautes','boutique','aide','parametres','recherche','developpeurs','oauth/authorize'];
$lang = in_array(($user['language'] ?? 'fr'), ['fr','en'], true) ? $user['language'] : 'fr';
$theme = in_array(($user['theme'] ?? 'auto'), ['auto','light','dark'], true) ? $user['theme'] : 'auto';
$__v = '20260920-1';
$private = in_array($effectiveRoute, $privateRoutes, true);
$canonicalPath = $route !== '' ? '/' . $route : $path;
$baseUrl   = rtrim((string)($GLOBALS['kova_app_url'] ?? 'https://your-domain.example'), '/');
$canonical = $baseUrl . $canonicalPath;

// ── Aperçu lors du partage (WhatsApp, Facebook, Telegram, LinkedIn, X…) ──
// Nom et description : .env (SEO_SITE_NAME, SEO_DESCRIPTION). Description propre à une page :
// définir $description dans la vue concernée.
$ogSiteName = (string)($siteName ?? 'KOVA');
$ogDesc     = (string)($description ?? 'KOVA, un espace social moderne.');
// Image de partage : PNG/JPG 1200×630 (les SVG ne sont pas acceptés par Facebook/WhatsApp/LinkedIn).
// Chemin (ex. /assets/images/kova-og.png) ou URL complète dans .env : SEO_DEFAULT_IMAGE
$ogImage = trim((string)($_ENV['SEO_DEFAULT_IMAGE'] ?? getenv('SEO_DEFAULT_IMAGE') ?: ''));
if ($ogImage === '') $ogImage = '/assets/images/kova-og.png';
if (!preg_match('#^https?://#i', $ogImage)) $ogImage = $baseUrl . '/' . ltrim($ogImage, '/');
?>
<!doctype html>
<html lang="<?= View::e($lang) ?>" data-theme="<?= View::e($theme) ?>" data-lang="<?= View::e($lang) ?>" data-user-id="<?= View::e($user['id'] ?? '') ?>"<?= !empty($debug) ? ' data-debug="1"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0b1020">
<meta name="color-scheme" content="<?= $theme === 'dark' ? 'dark' : ($theme === 'light' ? 'light' : 'light dark') ?>">
<title><?= View::e($title ?? 'KOVA') ?></title>
<meta name="description" content="<?= View::e($ogDesc) ?>">
<meta name="robots" content="<?= $private ? 'noindex,nofollow' : 'index,follow' ?>">
<meta name="kova-csrf" content="<?= View::e(\Kova\Core\Security::csrfToken()) ?>">
<link rel="canonical" href="<?= View::e($canonical) ?>">
<link rel="icon" type="image/svg+xml" href="/assets/images/kova-logo.svg">
<link rel="icon" type="image/png" sizes="192x192" href="/assets/images/generated/kova-192.png">
<link rel="apple-touch-icon" href="/assets/images/generated/kova-192.png">
<link rel="manifest" href="/manifest.webmanifest">

<!-- Open Graph : Facebook, WhatsApp, Telegram, LinkedIn, Discord… -->
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= View::e($ogSiteName) ?>">
<meta property="og:locale" content="<?= $lang === 'en' ? 'en_US' : 'fr_FR' ?>">
<meta property="og:title" content="<?= View::e($title ?? 'KOVA') ?>">
<meta property="og:description" content="<?= View::e($ogDesc) ?>">
<meta property="og:url" content="<?= View::e($canonical) ?>">
<meta property="og:image" content="<?= View::e($ogImage) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="<?= View::e($ogSiteName) ?>">

<!-- X (Twitter) -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= View::e($title ?? 'KOVA') ?>">
<meta name="twitter:description" content="<?= View::e($ogDesc) ?>">
<meta name="twitter:image" content="<?= View::e($ogImage) ?>">

<link rel="stylesheet" href="/assets/css/kova.css?v=<?= $__v ?>">
</head>
<body>
<div id="kova-js-errors" class="js-error-dock" hidden></div>