<?php
declare(strict_types=1);

namespace Kova\Core;

/**
 * Authentification à deux facteurs (TOTP, RFC 6238) compatible Google Authenticator, Authy,
 * Aegis, 1Password, Microsoft Authenticator… et chiffrement du secret au repos.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));   // 160 bits
    }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 5) as $chunk) $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32) ?? '');
        $bits = '';
        foreach (str_split($b32) as $c) {
            $p = strpos(self::ALPHABET, $c);
            if ($p === false) continue;
            $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) $out .= chr((int)bindec($byte));
        }
        return $out;
    }

    public static function code(string $secretB32, int $step): string
    {
        $key = self::base32Decode($secretB32);
        $msg = pack('N2', 0, $step);                       // compteur 64 bits big-endian
        $hash = hash_hmac('sha1', $msg, $key, true);
        $off = ord($hash[19]) & 0x0f;
        $bin = ((ord($hash[$off]) & 0x7f) << 24) | (ord($hash[$off + 1]) << 16) | (ord($hash[$off + 2]) << 8) | ord($hash[$off + 3]);
        return str_pad((string)($bin % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Vérifie un code (fenêtre ±1 pas de 30 s pour tolérer l'horloge).
     * $lastStep : dernier pas déjà consommé — un code ne peut servir qu'une fois (anti-rejeu).
     * @return int|null pas de temps accepté, ou null
     */
    public static function verify(string $secretB32, string $code, int $lastStep = 0, ?int $now = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) return null;
        $step = intdiv($now ?? time(), 30);
        for ($d = -1; $d <= 1; $d++) {
            $s = $step + $d;
            if ($s <= $lastStep) continue;
            if (hash_equals(self::code($secretB32, $s), $code)) return $s;
        }
        return null;
    }

    public static function uri(string $secretB32, string $account, string $issuer = 'KOVA'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secretB32 . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    // ── Codes de secours ───────────────────────────────────────────────────
    /** @return array{0:string[],1:string[]} [codes en clair (affichés une seule fois), empreintes à stocker] */
    public static function newBackupCodes(int $n = 8): array
    {
        $plain = [];
        $hashes = [];
        for ($i = 0; $i < $n; $i++) {
            $raw = strtolower(bin2hex(random_bytes(5)));                 // 10 hexa = 40 bits
            $code = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
            $plain[] = $code;
            $hashes[] = self::hashBackup($code);
        }
        return [$plain, $hashes];
    }

    public static function hashBackup(string $code): string
    {
        $code = strtolower(preg_replace('/[^a-f0-9]/i', '', $code) ?? '');
        return hash_hmac('sha256', 'backup|' . $code, (string)($GLOBALS['kova_hmac_key'] ?? 'kova'));
    }

    // ── Chiffrement du secret au repos (AES-256-GCM, clé dérivée de APP_KEY) ──
    public static function seal(string $plain): string
    {
        $key = hash_hkdf('sha256', (string)($GLOBALS['kova_hmac_key'] ?? 'kova'), 32, 'kova-totp-secret');
        if (!function_exists('openssl_encrypt')) return 'p:' . base64_encode($plain);
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) return 'p:' . base64_encode($plain);
        return 'g:' . base64_encode($iv . $tag . $ct);
    }

    public static function open(string $sealed): ?string
    {
        if (str_starts_with($sealed, 'p:')) return base64_decode(substr($sealed, 2), true) ?: null;
        if (!str_starts_with($sealed, 'g:') || !function_exists('openssl_decrypt')) return null;
        $raw = base64_decode(substr($sealed, 2), true);
        if ($raw === false || strlen($raw) < 29) return null;
        $key = hash_hkdf('sha256', (string)($GLOBALS['kova_hmac_key'] ?? 'kova'), 32, 'kova-totp-secret');
        $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $pt === false ? null : $pt;
    }
}
