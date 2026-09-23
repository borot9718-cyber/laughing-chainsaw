<?php
declare(strict_types=1);

namespace Kova\Core;

/**
 * Notifications « push » (Web Push, RFC 8030) chiffrées (RFC 8291, aes128gcm) et authentifiées par
 * VAPID (RFC 8292) — sans bibliothèque externe : uniquement OpenSSL et cURL.
 *
 * Sécurité :
 *  - l'endpoint fourni par le navigateur est validé (HTTPS, nom de domaine d'un service push connu,
 *    jamais une adresse IP ni un hôte interne) : pas de SSRF ;
 *  - la charge utile est chiffrée de bout en bout : le service push n'en voit pas le contenu ;
 *  - toute erreur est absorbée : une notification qui échoue ne doit jamais casser une action.
 *
 * Prérequis : VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY (base64url) / VAPID_SUBJECT dans .env, extensions
 * openssl et curl, ET un hébergeur qui autorise les connexions HTTPS sortantes.
 */
final class WebPush
{
    /** Suffixes de domaines des services push des navigateurs (Chrome/Edge/Opera/Samsung, Firefox, Safari, Windows). */
    private const ALLOWED_SUFFIXES = [
        '.googleapis.com', '.mozilla.com', '.mozaws.net', '.push.apple.com', '.notify.windows.com', '.push.samsung.com',
    ];

    public function __construct(private Env $env) {}

    public static function b64uEncode(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
    public static function b64uDecode(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }

    /** Disjoncteur : si les connexions sortantes sont bloquées, on n'y perd pas des secondes à chaque action. */
    private function breakerFile(): string { return dirname(__DIR__, 2) . '/storage/push_down.flag'; }
    private function breakerOpen(): bool { $f = $this->breakerFile(); return is_file($f) && (int)@file_get_contents($f) > time(); }
    private function tripBreaker(): void { $f = $this->breakerFile(); @mkdir(dirname($f), 0700, true); @file_put_contents($f, (string)(time() + 600), LOCK_EX); }

    public function publicKey(): string { return (string)$this->env->get('VAPID_PUBLIC_KEY', ''); }

    public function configured(): bool
    {
        return $this->publicKey() !== '' && (string)$this->env->get('VAPID_PRIVATE_KEY', '') !== ''
            && function_exists('openssl_pkey_derive') && function_exists('openssl_sign') && extension_loaded('curl');
    }

    public function endpointAllowed(string $endpoint): bool
    {
        $p = parse_url($endpoint);
        if (!is_array($p) || strtolower((string)($p['scheme'] ?? '')) !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])) return false;
        if (isset($p['port']) && (int)$p['port'] !== 443) return false;
        $host = strtolower((string)$p['host']);
        if (filter_var($host, FILTER_VALIDATE_IP) || $host === 'localhost' || !str_contains($host, '.')) return false;
        $suffixes = self::ALLOWED_SUFFIXES;
        foreach (array_filter(array_map('trim', explode(',', (string)$this->env->get('PUSH_ALLOWED_HOSTS', '')))) as $extra) {
            $suffixes[] = '.' . ltrim(strtolower($extra), '.');
        }
        foreach ($suffixes as $s) {
            if (str_ends_with($host, $s) || $host === ltrim($s, '.')) return true;
        }
        return false;
    }

    // ── Encodages DER/PEM ──────────────────────────────────────────────────
    private function pemFromDer(string $der, string $label): string
    {
        return "-----BEGIN $label-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END $label-----\n";
    }

    /** Clé publique P-256 (65 octets non compressés) → PEM SPKI. */
    private function publicPem(string $raw65): string
    {
        return $this->pemFromDer(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw65, 'PUBLIC KEY');
    }

    /** Clé privée P-256 brute (32 octets) + publique (65 octets) → PEM SEC1 « EC PRIVATE KEY ». */
    private function privatePem(string $d32, string $pub65): string
    {
        $der = hex2bin('30770201010420') . $d32 . hex2bin('a00a06082a8648ce3d030107a144034200') . $pub65;
        return $this->pemFromDer($der, 'EC PRIVATE KEY');
    }

    /** Signature ECDSA DER → concaténation brute R‖S (64 octets) exigée par JWT ES256. */
    private function derToRaw(string $der): ?string
    {
        if (strlen($der) < 8 || ord($der[0]) !== 0x30) return null;
        $i = 2;
        if (ord($der[1]) & 0x80) $i = 2 + (ord($der[1]) & 0x7f);
        $parts = [];
        for ($n = 0; $n < 2; $n++) {
            if (!isset($der[$i]) || ord($der[$i]) !== 0x02) return null;
            $len = ord($der[$i + 1]);
            $int = substr($der, $i + 2, $len);
            $i += 2 + $len;
            $int = ltrim($int, "\x00");
            if (strlen($int) > 32) return null;
            $parts[] = str_pad($int, 32, "\x00", STR_PAD_LEFT);
        }
        return $parts[0] . $parts[1];
    }

    private function vapidJwt(string $audience): ?string
    {
        $priv = self::b64uDecode((string)$this->env->get('VAPID_PRIVATE_KEY', ''));
        $pub = self::b64uDecode($this->publicKey());
        if (strlen($priv) !== 32 || strlen($pub) !== 65) return null;
        $subject = (string)$this->env->get('VAPID_SUBJECT', 'mailto:' . (string)$this->env->get('ADMIN_EMAIL', 'admin@example.com'));
        $header = self::b64uEncode((string)json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64uEncode((string)json_encode(['aud' => $audience, 'exp' => time() + 43200, 'sub' => $subject]));
        $input = $header . '.' . $claims;
        $key = openssl_pkey_get_private($this->privatePem($priv, $pub));
        if ($key === false) return null;
        $der = '';
        if (!openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) return null;
        $raw = $this->derToRaw($der);
        return $raw === null ? null : $input . '.' . self::b64uEncode($raw);
    }

    // ── Chiffrement du message (RFC 8291) ──────────────────────────────────
    /** @return string|null corps « aes128gcm » complet (en-tête + texte chiffré) */
    public function encrypt(string $plaintext, string $uaPublic65, string $authSecret16, ?string $fixedSalt = null, $fixedKey = null): ?string
    {
        if (strlen($uaPublic65) !== 65 || $uaPublic65[0] !== "\x04" || strlen($authSecret16) < 8) return null;
        $key = $fixedKey ?? openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) return null;
        $d = openssl_pkey_get_details($key);
        if (!is_array($d) || empty($d['ec']['x']) || empty($d['ec']['y'])) return null;
        $asPublic = "\x04" . str_pad($d['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        $peer = openssl_pkey_get_public($this->publicPem($uaPublic65));
        if ($peer === false) return null;
        $shared = @openssl_pkey_derive($peer, $key, 32);
        if (!is_string($shared) || strlen($shared) !== 32) $shared = @openssl_pkey_derive($key, $peer, 32);   // ordre des paramètres selon la version de PHP
        if (!is_string($shared) || strlen($shared) !== 32) return null;

        $salt = $fixedSalt ?? random_bytes(16);
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\x00" . $uaPublic65 . $asPublic, $authSecret16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        $tag = '';
        $ct = openssl_encrypt($plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ct === false) return null;
        return $salt . pack('N', 4096) . chr(65) . $asPublic . $ct . $tag;
    }

    /**
     * Envoie une notification.
     * @param array{endpoint:string,p256dh:string,auth:string} $sub
     * @return array{ok:bool,gone:bool,status:int}
     */
    public function send(array $sub, array $payload, int $ttl = 86400): array
    {
        $fail = ['ok' => false, 'gone' => false, 'status' => 0];
        try {
            if (!$this->configured() || $this->breakerOpen()) return $fail;
            $endpoint = (string)($sub['endpoint'] ?? '');
            if (!$this->endpointAllowed($endpoint)) return $fail + ['gone' => true];
            $json = (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (strlen($json) > 3000) return $fail;

            $body = $this->encrypt($json, self::b64uDecode((string)($sub['p256dh'] ?? '')), self::b64uDecode((string)($sub['auth'] ?? '')));
            if ($body === null) return $fail;
            $p = parse_url($endpoint);
            $jwt = $this->vapidJwt($p['scheme'] . '://' . $p['host']);
            if ($jwt === null) return $fail;

            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_FOLLOWLOCATION => false,                 // jamais de redirection (anti-SSRF)
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/octet-stream',
                    'Content-Encoding: aes128gcm',
                    'Content-Length: ' . strlen($body),
                    'TTL: ' . $ttl,
                    'Urgency: normal',
                    'Authorization: vapid t=' . $jwt . ', k=' . $this->publicKey(),
                ],
            ]);
            curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($status === 0) $this->tripBreaker();          // aucune réponse : réseau sortant bloqué ou service injoignable
            return ['ok' => $status >= 200 && $status < 300, 'gone' => in_array($status, [404, 410], true), 'status' => $status];
        } catch (\Throwable $e) {
            error_log('[KOVA WebPush] ' . $e->getMessage());
            return $fail;
        }
    }
}
