<?php
declare(strict_types=1);

namespace Kova\Core;

/**
 * Couche de sécurité centrale KOVA.
 *
 * Elle regroupe les protections qui doivent être appliquées de façon cohérente :
 * - en-têtes HTTP de sécurité ;
 * - jetons CSRF pour les requêtes authentifiées ;
 * - contrôle Origin/Referer sur les mutations navigateur ;
 * - limitation des tentatives sensibles ;
 * - journalisation minimale des événements de sécurité ;
 * - contrôle de taille des requêtes.
 *
 * Cette couche complète les contrôles métier : elle ne remplace pas
 * l'autorisation propre à chaque route.
 */
final class Security
{
    private const CSRF_KEY = '_kova_csrf';
    private const MAX_BODY_BYTES = 10_485_760; // 10 Mo
    private const RATE_FILE = 'rate_limits';

    /** HTTPS effectif (y compris derrière un reverse-proxy qui termine TLS). */
    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    /**
     * Démarre LA session, une seule fois, avec des paramètres durcis.
     *  - use_strict_mode : le serveur REFUSE un identifiant de session qu'il n'a pas émis
     *    (défense de fond contre la fixation de session) ;
     *  - cookies uniquement (pas d'identifiant dans l'URL), HttpOnly, SameSite=Lax, Secure en HTTPS ;
     *  - préfixe __Host- en HTTPS : cookie lié à ce domaine exact, non écrasable par un sous-domaine.
     * Auparavant, Security::boot() démarrait la session AVANT Auth::start() : les paramètres de
     * cookie d'Auth n'étaient alors jamais appliqués. Tout passe désormais par ici.
     */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $https = self::isHttps();
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.use_trans_sid', '0');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_samesite', 'Lax');
        @ini_set('session.sid_length', '48');
        @ini_set('session.sid_bits_per_character', '6');
        if (!headers_sent()) {
            session_name($https ? '__Host-KOVASESS' : 'KOVASESS');
            session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'domain' => '', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        }
        @session_start();
    }

    /**
     * Politique de sécurité du contenu. AUCUN script inline (script-src 'self' seul) : une injection
     * HTML ne peut plus exécuter de code. Les valeurs dynamiques passent par des attributs data-* et
     * des <meta>. Les styles en ligne ne sont permis qu'en attribut (style-src-attr), jamais en <style>.
     * $override permet à une page précise de durcir/assouplir UNE directive (ex. l'écran OAuth).
     */
    public static function csp(array $override = []): string
    {
        $d = [
            'default-src' => "'self'", 'base-uri' => "'self'", 'object-src' => "'none'", 'frame-ancestors' => "'self'",
            'form-action' => "'self'", 'script-src' => "'self'", 'script-src-attr' => "'none'",
            'style-src' => "'self'", 'style-src-elem' => "'self'", 'style-src-attr' => "'unsafe-inline'",
            'img-src' => "'self' https://res.cloudinary.com data: blob:", 'connect-src' => "'self'",
            'font-src' => "'self' data:", 'manifest-src' => "'self'", 'worker-src' => "'self'", 'media-src' => "'self'",
        ];
        foreach ($override as $k => $v) $d[$k] = $v;
        $parts = [];
        foreach ($d as $k => $v) $parts[] = $k . ' ' . $v;
        if (self::isHttps()) $parts[] = 'upgrade-insecure-requests';
        return implode('; ', $parts);
    }

    public static function boot(Env $env): void
    {
        self::startSession();
        if (headers_sent()) return;

        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: SAMEORIGIN');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        // COOP « same-origin » coupe window.opener : parfait pour l'application, mais casserait les connexions
        // « Continuer avec KOVA » ouvertes en fenêtre popup par un autre site. On l'assouplit uniquement sur
        // les pages d'authentification et d'autorisation (aucune donnée sensible n'y est affichée).
        $__p = trim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
        header('Cross-Origin-Opener-Policy: ' . (in_array($__p, ['connexion', 'inscription', 'verifier-email', 'oauth/authorize'], true) ? 'unsafe-none' : 'same-origin'));
        header('Cross-Origin-Resource-Policy: same-origin');
        header('X-Permitted-Cross-Domain-Policies: none');
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header_remove('X-Powered-By');

        $https = self::isHttps();
        if ($https || str_starts_with(strtolower((string)$env->get('APP_URL', '')), 'https://')) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        header('Content-Security-Policy: ' . self::csp());
        header('X-Request-ID: ' . self::requestId());

        $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > self::MAX_BODY_BYTES) {
            http_response_code(413);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Requête trop volumineuse.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    /**
     * Conservé pour compatibilité avec d'anciennes vues. Il n'y a plus de script inline : la CSP
     * ne l'autorise plus, cette valeur n'est donc plus utilisée. Générée à chaque requête (jamais
     * réutilisée entre requêtes).
     */
    public static function scriptNonce(): string
    {
        static $nonce = null;
        return $nonce ??= rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    public static function requestId(): string
    {
        static $id = null;
        if ($id !== null) return $id;
        $id = 'req_' . bin2hex(random_bytes(10));
        return $id;
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION[self::CSRF_KEY]) || !is_string($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::CSRF_KEY];
    }

    /** Nouveau jeton CSRF (à appeler à chaque changement d'identité de la session). */
    public static function rotateCsrf(): string
    {
        self::startSession();
        $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        return $_SESSION[self::CSRF_KEY];
    }

    public static function verifyCsrf(string $provided): bool
    {
        $expected = self::csrfToken();
        return $provided !== '' && hash_equals($expected, $provided);
    }

    public static function sameOriginRequest(): bool
    {
        $appUrl = rtrim((string)($GLOBALS['kova_app_url'] ?? ''), '/');
        $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));

        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($origin !== '') {
            $parts = parse_url($origin);
            if (!is_array($parts) || empty($parts['host'])) return false;
            $originHost = strtolower((string)$parts['host']);
            $currentHost = strtolower(preg_replace('/:\d+$/', '', $host) ?? $host);
            $configuredHost = strtolower((string)(parse_url($appUrl, PHP_URL_HOST) ?: ''));
            return $originHost === $currentHost || ($configuredHost !== '' && $originHost === $configuredHost);
        }

        if ($referer !== '') {
            $parts = parse_url($referer);
            if (!is_array($parts) || empty($parts['host'])) return false;
            $refHost = strtolower((string)$parts['host']);
            $currentHost = strtolower(preg_replace('/:\d+$/', '', $host) ?? $host);
            $configuredHost = strtolower((string)(parse_url($appUrl, PHP_URL_HOST) ?: ''));
            return $refHost === $currentHost || ($configuredHost !== '' && $refHost === $configuredHost);
        }

        // Les clients non-navigateurs peuvent ne pas envoyer Origin/Referer.
        // Les API sensibles exigent alors le jeton CSRF ou une authentification
        // propre à leur protocole.
        return false;
    }

    /**
     * Adresse IP du client.
     *  - Par défaut : REMOTE_ADDR (non falsifiable).
     *  - Derrière un reverse-proxy connu : définir TRUSTED_PROXY_HEADER dans .env
     *    (ex. HTTP_CF_CONNECTING_IP, HTTP_X_REAL_IP, HTTP_X_FORWARDED_FOR).
     *  - Sans configuration : si REMOTE_ADDR est une adresse privée/réservée (proxy interne de
     *    l'hébergeur), on lit X-Forwarded-For. Un client externe ne peut PAS abuser de ce repli :
     *    son REMOTE_ADDR est public, l'en-tête est alors ignoré.
     * Sans cela, tous les visiteurs partageraient l'IP du proxy et seraient limités ensemble.
     */
    public static function clientIp(): string
    {
        $remote = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: '0.0.0.0';
        $first = static function (string $raw): string {
            foreach (explode(',', $raw) as $part) {
                $ip = filter_var(trim($part), FILTER_VALIDATE_IP);
                if ($ip) return $ip;
            }
            return '';
        };
        $header = (string)($GLOBALS['kova_trusted_ip_header'] ?? '');
        if ($header !== '' && !empty($_SERVER[$header])) {
            $ip = $first((string)$_SERVER[$header]);
            if ($ip !== '') return $ip;
        }
        $isPublic = filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        if (!$isPublic) {
            foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $h) {
                if (!empty($_SERVER[$h])) {
                    $ip = $first((string)$_SERVER[$h]);
                    if ($ip !== '') return $ip;
                }
            }
        }
        return $remote;
    }

    /**
     * Retourne false lorsque la limite est dépassée.
     * La clé est hachée pour éviter de stocker des identifiants bruts dans le
     * fichier de limitation.
     */
    public static function rateLimit(JsonStore $store, string $scope, int $max, int $windowSeconds): bool
    {
        $now = time();
        $key = hash('sha256', $scope . '|' . self::clientIp());
        $allowed = true;
        // Lecture-modification-écriture sous verrou : deux requêtes simultanées ne se comptent plus « une seule fois ».
        $store->mutate(self::RATE_FILE, function (array $rows) use ($now, $key, $max, $windowSeconds, &$allowed) {
            $kept = [];
            $count = 0;
            foreach ($rows as $row) {
                if (($row['expires_at'] ?? 0) <= $now) continue;
                $kept[] = $row;
                if (($row['key'] ?? '') === $key) $count = (int)($row['count'] ?? 0);
            }
            if ($count >= $max) { $allowed = false; return $kept; }
            $updated = false;
            foreach ($kept as $i => $row) {
                if (($row['key'] ?? '') === $key) { $kept[$i]['count'] = $count + 1; $updated = true; break; }
            }
            if (!$updated) $kept[] = ['key' => $key, 'count' => 1, 'expires_at' => $now + $windowSeconds];
            return $kept;
        });
        return $allowed;
    }

    /** Empreinte stable d'un identifiant (e-mail, IP…) : on ne stocke jamais la valeur brute. */
    public static function fingerprint(string $namespace, string $value): string
    {
        return hash_hmac('sha256', $namespace . '|' . strtolower(trim($value)), (string)($GLOBALS['kova_hmac_key'] ?? 'kova'));
    }

    public static function audit(JsonStore $store, string $event, ?string $userId = null, array $meta = []): void
    {
        $safeMeta = ['ip' => self::fingerprint('audit-ip', self::clientIp())];
        foreach ($meta as $k => $v) {
            if (preg_match('/password|token|secret|cookie|authorization/i', (string)$k)) continue;
            if (is_scalar($v) || $v === null) $safeMeta[(string)$k] = $v;
        }
        $row = [
            'id' => 'sec_' . bin2hex(random_bytes(8)),
            'event' => $event,
            'user_id' => $userId,
            'request_id' => self::requestId(),
            'created_at' => date('c'),
            'meta' => $safeMeta,
        ];
        // Journal borné (2000 entrées) : écriture atomique sous verrou.
        $store->mutate('security_events', function (array $rows) use ($row) {
            $rows[] = $row;
            return count($rows) > 2000 ? array_slice($rows, -2000) : $rows;
        });
    }
}
