<?php
/**
 * Exemple : « Continuer avec KOVA » sur VOTRE autre plateforme (PHP pur, sans bibliothèque).
 *
 * 1. Enregistrez l'application dans KOVA › Développeurs (type « Confidentielle »),
 *    avec pour URL de retour l'adresse publique de CE fichier (ex. https://votre-site.com/kova-login.php).
 * 2. Renseignez les 4 constantes ci-dessous.
 * 3. Placez un lien « Continuer avec KOVA » vers  kova-login.php?action=login
 *
 * Ce fichier gère les deux étapes : départ vers KOVA, puis retour (échange du code + lecture du profil).
 * Adaptez la fonction kova_login_success() pour créer/retrouver le compte dans VOTRE base de données,
 * en utilisant $profile['sub'] (identifiant KOVA stable) comme clé — jamais l'e-mail seul.
 */
declare(strict_types=1);
session_start();

const KOVA_BASE          = 'https://votre-kova.example';       // adresse de votre KOVA
const KOVA_CLIENT_ID     = 'kova_xxxxxxxxxxxxxxxxxxxxxxxx';    // Développeurs › Client ID
const KOVA_CLIENT_SECRET = 'kvs_xxxxxxxxxxxxxxxxxxxxxxxxxxxx'; // Développeurs › Client secret (à garder côté serveur)
const KOVA_REDIRECT_URI  = 'https://votre-site.com/kova-login.php';

function b64url(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }

function http_post(string $url, array $fields): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string)$body, true) ?: []];
}

/** À adapter : retrouvez ou créez l'utilisateur local, puis ouvrez SA session. */
function kova_login_success(array $profile): void {
    $_SESSION['user'] = [
        'kova_id' => $profile['sub'],
        'name'    => $profile['name'] ?? '',
        'picture' => $profile['picture'] ?? '',
        'email'   => $profile['email'] ?? null,
    ];
    header('Location: /');   // votre page d'accueil connectée
    exit;
}

// ── Étape 1 : envoyer l'utilisateur vers KOVA ─────────────────────────────
if (($_GET['action'] ?? '') === 'login') {
    $_SESSION['kova_state']    = bin2hex(random_bytes(16));      // anti-CSRF
    $_SESSION['kova_verifier'] = b64url(random_bytes(48));       // PKCE
    $challenge = b64url(hash('sha256', $_SESSION['kova_verifier'], true));
    header('Location: ' . KOVA_BASE . '/oauth/authorize?' . http_build_query([
        'response_type' => 'code', 'client_id' => KOVA_CLIENT_ID, 'redirect_uri' => KOVA_REDIRECT_URI,
        'scope' => 'profile email', 'state' => $_SESSION['kova_state'],
        'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ]));
    exit;
}

// ── Étape 2 : retour de KOVA ─────────────────────────────────────────────
if (isset($_GET['error'])) { exit('Connexion annulée : ' . htmlspecialchars((string)$_GET['error'])); }
if (isset($_GET['code'])) {
    if (!hash_equals((string)($_SESSION['kova_state'] ?? ''), (string)($_GET['state'] ?? ''))) {
        http_response_code(400); exit('État invalide (possible tentative CSRF).');
    }
    [$status, $tok] = http_post(KOVA_BASE . '/oauth/token', [
        'grant_type' => 'authorization_code', 'code' => $_GET['code'], 'redirect_uri' => KOVA_REDIRECT_URI,
        'client_id' => KOVA_CLIENT_ID, 'client_secret' => KOVA_CLIENT_SECRET,
        'code_verifier' => $_SESSION['kova_verifier'] ?? '',
    ]);
    unset($_SESSION['kova_state'], $_SESSION['kova_verifier']);
    if ($status !== 200 || empty($tok['access_token'])) { http_response_code(502); exit('Échec de l’échange du code.'); }

    $ch = curl_init(KOVA_BASE . '/oauth/userinfo');
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok['access_token']],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $profile = json_decode((string)curl_exec($ch), true) ?: [];
    curl_close($ch);
    if (empty($profile['sub'])) { http_response_code(502); exit('Profil KOVA introuvable.'); }

    kova_login_success($profile);
}
http_response_code(400);
echo 'Utilisez ?action=login pour démarrer.';
