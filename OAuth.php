<?php
declare(strict_types=1);

namespace Kova\Core;

/**
 * KOVA comme fournisseur d'identité : « Continuer avec KOVA » sur vos autres plateformes.
 *
 * Protocole : OAuth 2.0 « Authorization Code » (RFC 6749) + PKCE (RFC 7636),
 * jetons Bearer (RFC 6750), révocation (RFC 7009), métadonnées (RFC 8414).
 *
 * Sécurité :
 *  - redirect_uri comparée EXACTEMENT aux URLs enregistrées (jamais de redirection ailleurs) ;
 *  - codes à usage unique (10 min) ; la réutilisation d'un code révoque les jetons émis ;
 *  - secrets, codes et jetons ne sont stockés que hachés (SHA-256) ;
 *  - PKCE (S256) obligatoire pour les clients publics (SPA, mobile) ;
 *  - page de consentement protégée par jeton CSRF ;
 *  - les applications non vérifiées affichent un avertissement.
 */
final class OAuth extends Module
{
    private const SCOPES = [
        'profile' => 'Voir votre nom, votre photo de profil et votre bio',
        'email'   => 'Voir votre adresse e-mail',
    ];
    private const CODE_TTL    = 600;        // 10 minutes
    private const ACCESS_TTL  = 3600;       // 1 heure
    private const REFRESH_TTL = 2592000;    // 30 jours
    private const MAX_APPS    = 10;

    // ── Utilitaires ────────────────────────────────────────────────────────
    public static function cors(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
        header('Access-Control-Max-Age: 600');
    }

    private function baseUrl(): string
    {
        $u = rtrim((string)($GLOBALS['kova_app_url'] ?? ''), '/');
        if ($u !== '') return $u;
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    private function hashOf(string $v): string
    {
        return hash('sha256', $v);
    }

    private function oauthError(string $error, string $description, int $status = 400): never
    {
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        if ($status === 401) header('WWW-Authenticate: Basic realm="KOVA OAuth"');
        Response::json(['error' => $error, 'error_description' => $description], $status);
    }

    private function safeNextPath(string $p): bool
    {
        return $p !== '' && $p[0] === '/' && !str_starts_with($p, '//') && !str_contains($p, "\\") && !preg_match('/[\r\n]/', $p);
    }

    /** @return string[] portées valides, ou [] si l'une est inconnue */
    private function parseScopes(string $raw): array
    {
        $raw = trim($raw) === '' ? 'profile' : $raw;
        $out = [];
        foreach (preg_split('/[\s,]+/', trim($raw)) ?: [] as $s) {
            if ($s === '' || $s === 'openid') continue;      // « openid » toléré mais sans effet
            if (!isset(self::SCOPES[$s])) return [];
            $out[$s] = true;
        }
        return $out ? array_keys($out) : ['profile'];
    }

    private function clientByPublicId(string $clientId, bool $includeDisabled = false): ?array
    {
        if ($clientId === '') return null;
        foreach ($this->store->all('oauth_clients') as $c) {
            if (($c['client_id'] ?? '') === $clientId && empty($c['deleted']) && ($includeDisabled || empty($c['disabled']))) return $c;
        }
        return null;
    }

    private function validRedirectUri(string $uri): bool
    {
        if ($uri === '' || strlen($uri) > 500 || str_contains($uri, '#') || preg_match('/[\s\x00-\x1f]/', $uri)) return false;
        $p = parse_url($uri);
        if (!is_array($p) || empty($p['scheme']) || empty($p['host']) || isset($p['user']) || isset($p['pass'])) return false;
        $scheme = strtolower($p['scheme']);
        $host   = strtolower($p['host']);
        if ($scheme === 'https') return true;
        return $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    private function parseRedirectUris(mixed $raw): array
    {
        $list = is_array($raw) ? $raw : preg_split('/[\r\n,]+/', (string)$raw);
        $out = [];
        foreach ($list ?: [] as $uri) {
            $uri = trim((string)$uri);
            if ($uri === '') continue;
            if (!$this->validRedirectUri($uri)) {
                $this->fail('URL de redirection invalide : « ' . mb_substr($uri, 0, 80) . ' ». Utilisez https:// (ou http://localhost pour vos tests).');
            }
            $out[$uri] = true;
        }
        if (!$out) $this->fail('Indiquez au moins une URL de redirection.');
        if (count($out) > 10) $this->fail('10 URLs de redirection maximum.');
        return array_keys($out);
    }

    private function publicClientView(array $c): array
    {
        $grants = 0;
        foreach ($this->store->all('oauth_grants') as $g) {
            if (($g['client_id'] ?? '') === ($c['client_id'] ?? '') && empty($g['revoked'])) $grants++;
        }
        return [
            'client_id'     => (string)$c['client_id'],
            'name'          => (string)($c['name'] ?? ''),
            'description'   => (string)($c['description'] ?? ''),
            'website'       => (string)($c['website'] ?? ''),
            'redirect_uris' => array_values((array)($c['redirect_uris'] ?? [])),
            'type'          => (string)($c['type'] ?? 'confidential'),
            'verified'      => !empty($c['verified']),
            'disabled'      => !empty($c['disabled']),
            'users_count'   => $grants,
            'created_at'    => $c['created_at'] ?? null,
        ];
    }

    // ── Routage API ────────────────────────────────────────────────────────
    public function handle(string $path, string $method, array $input): void
    {
        if (!$this->env->bool('OAUTH_ENABLED', true) && str_starts_with($path, 'oauth/')) {
            $this->oauthError('temporarily_unavailable', 'Le service « Continuer avec KOVA » est désactivé.', 503);
        }
        if (str_starts_with($path, 'oauth/') && in_array($path, ['oauth/token', 'oauth/userinfo', 'oauth/revoke'], true)) {
            self::cors();
        }

        // Métadonnées sous un chemin non intercepté par le filtrage de certains hébergeurs (F-06).
        if ($path === 'oauth/metadata' && $method === 'GET') $this->metadata();
        if ($path === 'oauth/token'    && $method === 'POST') $this->token($input);
        if ($path === 'oauth/revoke'   && $method === 'POST') $this->revoke($input);
        if ($path === 'oauth/userinfo' && ($method === 'GET' || $method === 'POST')) $this->userinfo();

        if ($path === 'developer/apps' && $method === 'GET')  $this->myApps();
        if ($path === 'developer/apps' && $method === 'POST') $this->createApp($input);
        if (preg_match('#^developer/apps/([a-zA-Z0-9_]+)(?:/([a-z]+))?$#', $path, $m)) {
            $cid = $m[1];
            $sub = $m[2] ?? '';
            if ($sub === ''       && $method === 'DELETE') $this->deleteApp($cid);
            if ($sub === 'update' && $method === 'POST')   $this->updateApp($cid, $input);
            if ($sub === 'secret' && $method === 'POST')   $this->regenerateSecret($cid);
        }

        if ($path === 'oauth/grants' && $method === 'GET') $this->myGrants();
        if (preg_match('#^oauth/grants/([a-zA-Z0-9_]+)/revoke$#', $path, $m) && $method === 'POST') {
            $this->revokeGrant($m[1]);
        }

        if ($path === 'admin/oauth/apps' && $method === 'GET')         $this->adminApps();
        if ($path === 'admin/oauth/apps/update' && $method === 'POST') $this->adminUpdateApp($input);
    }

    // ── Métadonnées (RFC 8414) ─────────────────────────────────────────────
    public function metadata(): never
    {
        self::cors();
        $b = $this->baseUrl();
        Response::json([
            'issuer'                                => $b,
            'metadata_endpoint'                     => $b . '/api/oauth/metadata',
            'authorization_endpoint'                => $b . '/oauth/authorize',
            'token_endpoint'                        => $b . '/oauth/token',
            'userinfo_endpoint'                     => $b . '/oauth/userinfo',
            'revocation_endpoint'                   => $b . '/oauth/revoke',
            'scopes_supported'                      => array_keys(self::SCOPES),
            'response_types_supported'              => ['code'],
            'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported'      => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
        ]);
    }

    // ── Page d'autorisation (navigateur) ───────────────────────────────────
    public function authorize(string $method): void
    {
        if (!$this->env->bool('OAUTH_ENABLED', true)) {
            $this->errorPage('Service indisponible', 'La connexion avec KOVA est désactivée sur cette plateforme.');
            return;
        }
        // Anti-clickjacking : l'écran de consentement ne doit jamais s'afficher dans une iframe.
        header('X-Frame-Options: DENY');
        header('Content-Security-Policy: ' . Security::csp(['frame-ancestors' => "'none'"]));
        $src = $method === 'POST' ? $_POST : $_GET;

        $client   = $this->clientByPublicId(trim((string)($src['client_id'] ?? '')));
        $redirect = trim((string)($src['redirect_uri'] ?? ''));
        if (!$client) {
            $this->errorPage('Application inconnue', 'Cette application n’est pas enregistrée auprès de KOVA (ou a été désactivée).');
            return;
        }
        // Tant que l'URL de retour n'est pas validée, on N'Y REDIRIGE JAMAIS.
        if ($redirect === '' || !in_array($redirect, (array)($client['redirect_uris'] ?? []), true)) {
            $this->errorPage('Adresse de retour non autorisée', 'L’adresse de redirection demandée ne correspond à aucune adresse enregistrée pour « ' . (string)$client['name'] . ' ».');
            return;
        }

        $state = (string)($src['state'] ?? '');
        if (($src['response_type'] ?? '') !== 'code') {
            $this->redirectError($redirect, $state, 'unsupported_response_type', 'Seul response_type=code est pris en charge.');
        }
        $scopes = $this->parseScopes((string)($src['scope'] ?? ''));
        if (!$scopes) {
            $this->redirectError($redirect, $state, 'invalid_scope', 'Portée demandée inconnue.');
        }
        $challenge = (string)($src['code_challenge'] ?? '');
        if ($challenge !== '') {
            if (($src['code_challenge_method'] ?? '') !== 'S256' || !preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $challenge)) {
                $this->redirectError($redirect, $state, 'invalid_request', 'PKCE : utilisez code_challenge_method=S256 et un challenge valide.');
            }
        } elseif (($client['type'] ?? 'confidential') === 'public') {
            $this->redirectError($redirect, $state, 'invalid_request', 'PKCE (code_challenge) est obligatoire pour les applications publiques.');
        }

        $user = $this->auth->user();
        if (!$user) {
            $uri = (string)($_SERVER['REQUEST_URI'] ?? '/oauth/authorize');
            if ($method === 'POST' || !$this->safeNextPath($uri)) $uri = '/oauth/authorize?' . http_build_query($_GET);
            Response::redirect('/connexion?next=' . rawurlencode($uri));
        }

        // Décision de l'utilisateur (formulaire de consentement)
        if ($method === 'POST') {
            $token = (string)($_SESSION['oauth_csrf'] ?? '');
            if ($token === '' || !hash_equals($token, (string)($_POST['csrf'] ?? ''))) {
                $this->errorPage('Session expirée', 'La confirmation a expiré. Retournez sur l’application et recommencez.');
                return;
            }
            unset($_SESSION['oauth_csrf']);
            if (($_POST['decision'] ?? '') !== 'approve') {
                $this->redirectError($redirect, $state, 'access_denied', 'L’utilisateur a refusé l’accès.');
            }
            $this->issueCode($client, $user, $scopes, $redirect, $state, $challenge);
        }

        // Déjà autorisé avec ces mêmes portées : connexion transparente.
        if (($src['prompt'] ?? '') !== 'consent' && $this->grantCovers((string)$user['id'], (string)$client['client_id'], $scopes)) {
            $this->issueCode($client, $user, $scopes, $redirect, $state, $challenge);
        }

        $rp = parse_url($redirect);
        $origin = ($rp['scheme'] ?? 'https') . '://' . ($rp['host'] ?? '') . (isset($rp['port']) ? ':' . $rp['port'] : '');
        header('Content-Security-Policy: ' . Security::csp(['frame-ancestors' => "'none'", 'form-action' => "'self' " . $origin]));
        $csrf = bin2hex(random_bytes(16));
        $_SESSION['oauth_csrf'] = $csrf;
        $owner = $this->findById('users', (string)($client['owner_id'] ?? ''));
        $this->app->page('oauth-authorize', [
            'mode'        => 'consent',
            'client'      => $this->publicClientView($client),
            'developer'   => $owner['display_name'] ?? 'Un membre KOVA',
            'scopes'      => $scopes,
            'scopeLabels' => self::SCOPES,
            'csrf'        => $csrf,
            'params'      => [
                'client_id' => $client['client_id'], 'redirect_uri' => $redirect, 'response_type' => 'code',
                'scope' => implode(' ', $scopes), 'state' => $state,
                'code_challenge' => $challenge, 'code_challenge_method' => $challenge !== '' ? 'S256' : '',
            ],
        ]);
    }

    private function errorPage(string $title, string $message): void
    {
        http_response_code(400);
        $this->app->page('oauth-authorize', ['mode' => 'error', 'errorTitle' => $title, 'errorMessage' => $message]);
    }

    private function redirectError(string $redirect, string $state, string $error, string $description): never
    {
        $q = ['error' => $error, 'error_description' => $description];
        if ($state !== '') $q['state'] = $state;
        Response::redirect($redirect . (str_contains($redirect, '?') ? '&' : '?') . http_build_query($q));
    }

    private function grantCovers(string $userId, string $clientId, array $scopes): bool
    {
        foreach ($this->store->all('oauth_grants') as $g) {
            if (($g['user_id'] ?? '') === $userId && ($g['client_id'] ?? '') === $clientId && empty($g['revoked'])) {
                return !array_diff($scopes, (array)($g['scopes'] ?? []));
            }
        }
        return false;
    }

    private function issueCode(array $client, array $user, array $scopes, string $redirect, string $state, string $challenge): never
    {
        $code = 'kvc_' . bin2hex(random_bytes(24));
        $rows = array_values(array_filter($this->store->all('oauth_codes'), fn($c) => (int)($c['expires_at'] ?? 0) > time() - 3600));
        $rows[] = [
            'code_hash' => $this->hashOf($code), 'client_id' => $client['client_id'], 'user_id' => $user['id'],
            'redirect_uri' => $redirect, 'scopes' => $scopes, 'code_challenge' => $challenge,
            'expires_at' => time() + self::CODE_TTL, 'used' => false, 'created_at' => date('c'),
        ];
        $this->store->replace('oauth_codes', $rows);

        // Enregistre / met à jour l'autorisation (visible dans Paramètres > Applications connectées)
        $grants = $this->store->all('oauth_grants');
        $found = false;
        foreach ($grants as $i => $g) {
            if (($g['user_id'] ?? '') === $user['id'] && ($g['client_id'] ?? '') === $client['client_id']) {
                $merged = array_values(array_unique(array_merge(empty($g['revoked']) ? (array)($g['scopes'] ?? []) : [], $scopes)));
                $grants[$i] = array_merge($g, ['scopes' => $merged, 'revoked' => false, 'updated_at' => date('c')]);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $grants[] = [
                'id' => $this->newId('grt_'), 'user_id' => $user['id'], 'client_id' => $client['client_id'],
                'scopes' => $scopes, 'revoked' => false, 'created_at' => date('c'), 'updated_at' => date('c'),
            ];
        }
        $this->store->replace('oauth_grants', $grants);

        $q = ['code' => $code];
        if ($state !== '') $q['state'] = $state;
        Response::redirect($redirect . (str_contains($redirect, '?') ? '&' : '?') . http_build_query($q));
    }

    // ── Authentification du client (endpoint /token et /revoke) ────────────
    private function authorizationHeader(): string
    {
        $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($h === '' && function_exists('getallheaders')) {
            foreach ((array)getallheaders() as $k => $v) {
                if (strtolower((string)$k) === 'authorization') { $h = (string)$v; break; }
            }
        }
        return $h;
    }

    private function authenticateClient(array $input): array
    {
        $clientId = (string)($input['client_id'] ?? '');
        $secret   = (string)($input['client_secret'] ?? '');
        $h = $this->authorizationHeader();
        if (stripos($h, 'Basic ') === 0) {
            $decoded = base64_decode(trim(substr($h, 6)), true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$id, $sec] = explode(':', $decoded, 2);
                $clientId = urldecode($id);
                $secret   = urldecode($sec);
            }
        }
        $client = $this->clientByPublicId($clientId);
        if (!$client) $this->oauthError('invalid_client', 'Client inconnu ou désactivé.', 401);
        if (($client['type'] ?? 'confidential') === 'confidential') {
            $stored = (string)($client['secret_hash'] ?? '');
            if ($secret === '' || $stored === '' || !hash_equals($stored, $this->hashOf($secret))) {
                $this->oauthError('invalid_client', 'Secret client invalide.', 401);
            }
        }
        return $client;
    }

    // ── /oauth/token ───────────────────────────────────────────────────────
    private function token(array $input): never
    {
        $client = $this->authenticateClient($input);
        $grant  = (string)($input['grant_type'] ?? '');

        if ($grant === 'authorization_code') {
            $code = (string)($input['code'] ?? '');
            if ($code === '') $this->oauthError('invalid_request', 'Paramètre « code » manquant.');
            $hash = $this->hashOf($code);
            $row  = null;
            foreach ($this->store->all('oauth_codes') as $c) {
                if (hash_equals((string)($c['code_hash'] ?? ''), $hash)) { $row = $c; break; }
            }
            if (!$row || ($row['client_id'] ?? '') !== $client['client_id']) {
                $this->oauthError('invalid_grant', 'Code invalide.');
            }
            if (!empty($row['used'])) {
                // Rejeu d'un code : on révoque tout ce qui en est issu (RFC 6749 §4.1.2).
                $this->store->updateWhere('oauth_tokens', fn($t) => ($t['code_hash'] ?? '') === $hash, fn($t) => array_merge($t, ['revoked' => true]));
                $this->oauthError('invalid_grant', 'Ce code a déjà été utilisé.');
            }
            if ((int)($row['expires_at'] ?? 0) < time()) $this->oauthError('invalid_grant', 'Code expiré.');
            if (($input['redirect_uri'] ?? '') !== ($row['redirect_uri'] ?? null)) {
                $this->oauthError('invalid_grant', 'redirect_uri différente de celle de la demande d’autorisation.');
            }
            if (($row['code_challenge'] ?? '') !== '') {
                $verifier = (string)($input['code_verifier'] ?? '');
                if (!preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier)) $this->oauthError('invalid_grant', 'code_verifier manquant ou invalide.');
                $calc = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
                if (!hash_equals((string)$row['code_challenge'], $calc)) $this->oauthError('invalid_grant', 'Vérification PKCE échouée.');
            } elseif (($client['type'] ?? 'confidential') === 'public') {
                $this->oauthError('invalid_grant', 'PKCE requis pour un client public.');
            }
            $this->store->updateWhere('oauth_codes', fn($c) => hash_equals((string)($c['code_hash'] ?? ''), $hash), fn($c) => array_merge($c, ['used' => true]));
            $this->issueTokens($client, (string)$row['user_id'], (array)$row['scopes'], $hash);
        }

        if ($grant === 'refresh_token') {
            $rt = (string)($input['refresh_token'] ?? '');
            if ($rt === '') $this->oauthError('invalid_request', 'Paramètre « refresh_token » manquant.');
            $hash = $this->hashOf($rt);
            $row = null;
            foreach ($this->store->all('oauth_tokens') as $t) {
                if (hash_equals((string)($t['refresh_hash'] ?? ''), $hash)) { $row = $t; break; }
            }
            if (!$row || ($row['client_id'] ?? '') !== $client['client_id'] || !empty($row['revoked']) || (int)($row['refresh_expires'] ?? 0) < time()) {
                $this->oauthError('invalid_grant', 'Refresh token invalide ou expiré.');
            }
            $this->store->updateWhere('oauth_tokens', fn($t) => ($t['id'] ?? '') === $row['id'], fn($t) => array_merge($t, ['revoked' => true]));
            $this->issueTokens($client, (string)$row['user_id'], (array)$row['scopes'], (string)($row['code_hash'] ?? ''));
        }

        $this->oauthError('unsupported_grant_type', 'grant_type pris en charge : authorization_code, refresh_token.');
    }

    private function issueTokens(array $client, string $userId, array $scopes, string $codeHash): never
    {
        $user = $this->findById('users', $userId);
        if (!$user || in_array(strtolower((string)($user['account_status'] ?? 'active')), ['suspended', 'banned'], true)) {
            $this->oauthError('invalid_grant', 'Le compte utilisateur n’est plus actif.');
        }
        // L'autorisation ne doit pas avoir été révoquée entre-temps.
        $active = false;
        foreach ($this->store->all('oauth_grants') as $g) {
            if (($g['user_id'] ?? '') === $userId && ($g['client_id'] ?? '') === $client['client_id'] && empty($g['revoked'])) { $active = true; break; }
        }
        if (!$active) $this->oauthError('invalid_grant', 'L’utilisateur a révoqué l’accès de cette application.');

        $access  = 'kva_' . bin2hex(random_bytes(32));
        $refresh = 'kvr_' . bin2hex(random_bytes(32));
        $rows = array_values(array_filter($this->store->all('oauth_tokens'), fn($t) => (int)($t['refresh_expires'] ?? 0) > time() - 86400));
        $rows[] = [
            'id' => $this->newId('tok_'), 'client_id' => $client['client_id'], 'user_id' => $userId, 'scopes' => array_values($scopes),
            'access_hash' => $this->hashOf($access), 'access_expires' => time() + self::ACCESS_TTL,
            'refresh_hash' => $this->hashOf($refresh), 'refresh_expires' => time() + self::REFRESH_TTL,
            'revoked' => false, 'code_hash' => $codeHash, 'created_at' => date('c'),
        ];
        $this->store->replace('oauth_tokens', $rows);
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        Response::json([
            'access_token'  => $access,
            'token_type'    => 'Bearer',
            'expires_in'    => self::ACCESS_TTL,
            'refresh_token' => $refresh,
            'scope'         => implode(' ', $scopes),
        ]);
    }

    // ── /oauth/userinfo ────────────────────────────────────────────────────
    private function userinfo(): never
    {
        $h = $this->authorizationHeader();
        $bearer = stripos($h, 'Bearer ') === 0 ? trim(substr($h, 7)) : '';
        $fail = function (string $desc): never {
            header('WWW-Authenticate: Bearer error="invalid_token"');
            Response::json(['error' => 'invalid_token', 'error_description' => $desc], 401);
        };
        if ($bearer === '') $fail('Jeton Bearer manquant.');
        $hash = $this->hashOf($bearer);
        $row = null;
        foreach ($this->store->all('oauth_tokens') as $t) {
            if (hash_equals((string)($t['access_hash'] ?? ''), $hash)) { $row = $t; break; }
        }
        if (!$row || !empty($row['revoked']) || (int)($row['access_expires'] ?? 0) < time()) $fail('Jeton invalide ou expiré.');
        $client = $this->clientByPublicId((string)$row['client_id']);
        $user   = $this->findById('users', (string)$row['user_id']);
        if (!$client || !$user || in_array(strtolower((string)($user['account_status'] ?? 'active')), ['suspended', 'banned'], true)) {
            $fail('Le compte ou l’application n’est plus actif.');
        }
        $scopes = (array)($row['scopes'] ?? []);
        $out = ['sub' => (string)$user['id']];
        if (in_array('profile', $scopes, true)) {
            $out['name']    = (string)($user['display_name'] ?? '');
            $out['picture'] = (string)($user['avatar_url'] ?? '');
            $out['bio']     = (string)($user['bio'] ?? '');
            $out['locale']  = (string)($user['language'] ?? 'fr');
            $out['profile'] = $this->baseUrl() . '/profil?user=' . $user['id'];
        }
        if (in_array('email', $scopes, true)) {
            $out['email']          = (string)($user['email'] ?? '');
            $out['email_verified'] = !empty($user['verified']);
        }
        header('Cache-Control: no-store');
        Response::json($out);
    }

    // ── /oauth/revoke ──────────────────────────────────────────────────────
    private function revoke(array $input): never
    {
        $client = $this->authenticateClient($input);
        $token  = (string)($input['token'] ?? '');
        if ($token !== '') {
            $hash = $this->hashOf($token);
            $this->store->updateWhere('oauth_tokens',
                fn($t) => ($t['client_id'] ?? '') === $client['client_id']
                    && (hash_equals((string)($t['access_hash'] ?? ''), $hash) || hash_equals((string)($t['refresh_hash'] ?? ''), $hash)),
                fn($t) => array_merge($t, ['revoked' => true])
            );
        }
        Response::json([]);   // RFC 7009 : toujours 200
    }

    // ── Espace développeur ─────────────────────────────────────────────────
    private function canCreateApps(array $u): bool
    {
        if ((string)$this->env->get('OAUTH_APP_CREATORS', 'all') !== 'admins') return true;
        return $this->app->isAdmin($u);
    }

    private function ownedApp(string $clientId, array $u): array
    {
        $c = $this->clientByPublicId($clientId, true);
        if (!$c || ($c['owner_id'] ?? '') !== $u['id']) $this->fail('Application introuvable.', 404);
        return $c;
    }

    private function myApps(): never
    {
        $u = $this->me();
        $out = [];
        foreach ($this->store->all('oauth_clients') as $c) {
            if (empty($c['deleted']) && ($c['owner_id'] ?? '') === $u['id']) $out[] = $this->publicClientView($c);
        }
        usort($out, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
        $this->ok(['apps' => $out, 'endpoints' => [
            'authorize' => $this->baseUrl() . '/oauth/authorize',
            'token'     => $this->baseUrl() . '/oauth/token',
            'userinfo'  => $this->baseUrl() . '/oauth/userinfo',
            'revoke'    => $this->baseUrl() . '/oauth/revoke',
            'metadata'  => $this->baseUrl() . '/.well-known/oauth-authorization-server',
        ], 'can_create' => $this->canCreateApps($u)]);
    }

    private function createApp(array $input): never
    {
        $u = $this->me();
        if (!$this->canCreateApps($u)) $this->fail('La création d’applications est réservée aux administrateurs.', 403);
        $count = 0;
        foreach ($this->store->all('oauth_clients') as $c) {
            if (empty($c['deleted']) && ($c['owner_id'] ?? '') === $u['id']) $count++;
        }
        if ($count >= self::MAX_APPS) $this->fail('Limite de ' . self::MAX_APPS . ' applications atteinte.');

        $name = $this->text($input['name'] ?? '', 60);
        if (mb_strlen($name) < 2) $this->fail('Le nom de l’application doit contenir au moins 2 caractères.');
        $website = $this->text($input['website'] ?? '', 200);
        if ($website !== '' && !preg_match('#^https?://#i', $website)) $this->fail('Le site web doit commencer par http:// ou https://.');
        $type = (($input['type'] ?? 'confidential') === 'public') ? 'public' : 'confidential';

        $secret = '';
        $client = [
            'client_id'     => 'kova_' . bin2hex(random_bytes(12)),
            'secret_hash'   => '',
            'type'          => $type,
            'name'          => $name,
            'description'   => $this->text($input['description'] ?? '', 200),
            'website'       => $website,
            'redirect_uris' => $this->parseRedirectUris($input['redirect_uris'] ?? ''),
            'owner_id'      => $u['id'],
            'verified'      => false,
            'disabled'      => false,
            'deleted'       => false,
            'created_at'    => date('c'),
        ];
        if ($type === 'confidential') {
            $secret = 'kvs_' . bin2hex(random_bytes(24));
            $client['secret_hash'] = $this->hashOf($secret);
        }
        $this->store->insert('oauth_clients', $client);
        $this->ok(['app' => $this->publicClientView($client), 'client_secret' => $secret,
            'message' => $secret !== '' ? 'Application créée. Copiez le secret maintenant : il ne sera plus affiché.' : 'Application créée.'], 201);
    }

    private function updateApp(string $clientId, array $input): never
    {
        $u = $this->me();
        $c = $this->ownedApp($clientId, $u);
        $changes = ['updated_at' => date('c')];
        if (isset($input['name'])) {
            $name = $this->text($input['name'], 60);
            if (mb_strlen($name) < 2) $this->fail('Le nom de l’application doit contenir au moins 2 caractères.');
            $changes['name'] = $name;
        }
        if (isset($input['description'])) $changes['description'] = $this->text($input['description'], 200);
        if (isset($input['website'])) {
            $w = $this->text($input['website'], 200);
            if ($w !== '' && !preg_match('#^https?://#i', $w)) $this->fail('Le site web doit commencer par http:// ou https://.');
            $changes['website'] = $w;
        }
        if (isset($input['redirect_uris'])) $changes['redirect_uris'] = $this->parseRedirectUris($input['redirect_uris']);
        $this->store->updateWhere('oauth_clients', fn($x) => ($x['client_id'] ?? '') === $clientId, fn($x) => array_merge($x, $changes));
        $this->ok(['message' => 'Application mise à jour.']);
    }

    private function regenerateSecret(string $clientId): never
    {
        $u = $this->me();
        $c = $this->ownedApp($clientId, $u);
        if (($c['type'] ?? 'confidential') !== 'confidential') $this->fail('Une application publique n’a pas de secret.');
        $secret = 'kvs_' . bin2hex(random_bytes(24));
        $this->store->updateWhere('oauth_clients', fn($x) => ($x['client_id'] ?? '') === $clientId,
            fn($x) => array_merge($x, ['secret_hash' => $this->hashOf($secret), 'updated_at' => date('c')]));
        $this->ok(['client_secret' => $secret, 'message' => 'Nouveau secret généré. L’ancien ne fonctionne plus.']);
    }

    private function deleteApp(string $clientId): never
    {
        $u = $this->me();
        $this->ownedApp($clientId, $u);
        $this->store->updateWhere('oauth_clients', fn($x) => ($x['client_id'] ?? '') === $clientId, fn($x) => array_merge($x, ['deleted' => true, 'deleted_at' => date('c')]));
        $this->store->updateWhere('oauth_tokens', fn($t) => ($t['client_id'] ?? '') === $clientId, fn($t) => array_merge($t, ['revoked' => true]));
        $this->store->updateWhere('oauth_grants', fn($g) => ($g['client_id'] ?? '') === $clientId, fn($g) => array_merge($g, ['revoked' => true]));
        $this->ok();
    }

    // ── Applications connectées (côté utilisateur) ─────────────────────────
    private function myGrants(): never
    {
        $u = $this->me();
        $out = [];
        foreach ($this->store->all('oauth_grants') as $g) {
            if (($g['user_id'] ?? '') !== $u['id'] || !empty($g['revoked'])) continue;
            $c = $this->clientByPublicId((string)($g['client_id'] ?? ''), true);
            if (!$c) continue;
            $out[] = [
                'client_id' => $c['client_id'], 'name' => (string)$c['name'], 'website' => (string)($c['website'] ?? ''),
                'verified' => !empty($c['verified']), 'scopes' => array_values((array)($g['scopes'] ?? [])),
                'granted_at' => $g['created_at'] ?? null,
            ];
        }
        $this->ok(['grants' => $out]);
    }

    private function revokeGrant(string $clientId): never
    {
        $u = $this->me();
        $this->store->updateWhere('oauth_grants', fn($g) => ($g['user_id'] ?? '') === $u['id'] && ($g['client_id'] ?? '') === $clientId, fn($g) => array_merge($g, ['revoked' => true, 'revoked_at' => date('c')]));
        $this->store->updateWhere('oauth_tokens', fn($t) => ($t['user_id'] ?? '') === $u['id'] && ($t['client_id'] ?? '') === $clientId, fn($t) => array_merge($t, ['revoked' => true]));
        $this->ok();
    }

    // ── Administration ─────────────────────────────────────────────────────
    private function adminApps(): never
    {
        $this->app->requireAdmin();
        $users = $this->usersMap();
        $out = [];
        foreach ($this->store->all('oauth_clients') as $c) {
            if (!empty($c['deleted'])) continue;
            $v = $this->publicClientView($c);
            $v['owner'] = $this->pub($users[$c['owner_id'] ?? ''] ?? null)['display_name'];
            $out[] = $v;
        }
        $this->ok(['apps' => $out]);
    }

    private function adminUpdateApp(array $input): never
    {
        $admin = $this->app->requireAdmin();
        $cid = (string)($input['client_id'] ?? '');
        $c = $this->clientByPublicId($cid, true);
        if (!$c) $this->fail('Application introuvable.', 404);
        $changes = ['updated_at' => date('c')];
        if (array_key_exists('verified', $input)) $changes['verified'] = (bool)$input['verified'];
        if (array_key_exists('disabled', $input)) $changes['disabled'] = (bool)$input['disabled'];
        $this->store->updateWhere('oauth_clients', fn($x) => ($x['client_id'] ?? '') === $cid, fn($x) => array_merge($x, $changes));
        $this->app->logActivity((string)$admin['id'], 'oauth_app_update', ['client_id' => $cid] + array_diff_key($changes, ['updated_at' => 1]));
        $this->ok();
    }
}
