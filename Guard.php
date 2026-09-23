<?php
declare(strict_types=1);

namespace Kova\Core;

/**
 * Garde anti-force-brute (F-01 de l'audit) : limitation progressive des tentatives de connexion
 * et « captcha » par preuve de travail, entièrement auto-hébergé (aucun service tiers).
 *
 * Trois compteurs indépendants, tous par empreinte (jamais d'e-mail ni d'IP en clair) :
 *  - couple (compte + IP) : 5 échecs → blocage 15 min, doublé à chaque récidive (max 24 h).
 *    Le blocage est lié à l'IP de l'attaquant : il ne peut donc PAS servir à verrouiller le
 *    compte d'un tiers (déni de service) ;
 *  - IP seule            : 25 échecs en 15 min (toutes cibles confondues, « credential stuffing »)
 *    → blocage 15 min ;
 *  - compte seul (toutes IP) : dès 3 échecs, une preuve de travail est exigée de tout le monde ;
 *    difficulté augmentée si les échecs sont massifs (attaque distribuée).
 *
 * Preuve de travail : le serveur émet un défi signé (HMAC, valable 5 min, à usage unique) ; le
 * navigateur cherche un nombre « nonce » tel que SHA-256(défi:nonce) commence par N bits à zéro
 * (~0,3 s pour un humain, coûteux pour un robot qui teste des milliers de comptes).
 */
final class Guard
{
    private const FILE = 'login_attempts';
    private const WINDOW = 900;            // 15 min
    private const LOCK_BASE = 900;         // 15 min
    private const LOCK_MAX = 86400;        // 24 h
    private const PAIR_MAX = 5;
    private const IP_MAX = 25;
    private const POW_AFTER = 3;
    private const POW_BITS = 16;
    private const POW_BITS_HARD = 18;
    private const POW_TTL = 300;

    public function __construct(private JsonStore $store, private Env $env) {}

    private function key(string $scope, string $value): string
    {
        return Security::fingerprint('guard:' . $scope, $value);
    }

    /** @return array<string,array> */
    private function load(): array
    {
        $now = time();
        $rows = [];
        foreach ($this->store->all(self::FILE) as $r) {
            $alive = ((int)($r['lock_until'] ?? 0) > $now) || ((int)($r['window_start'] ?? 0) + self::WINDOW > $now) || ((int)($r['expires_at'] ?? 0) > $now);
            if ($alive && isset($r['k'])) $rows[(string)$r['k']] = $r;
        }
        return $rows;
    }

    private function save(array $rows): void
    {
        $this->store->replace(self::FILE, array_values($rows));
    }

    /**
     * @return array{locked:int,pow_bits:int} locked = secondes restantes (0 = libre) ;
     *         pow_bits = difficulté de la preuve de travail exigée (0 = aucune)
     */
    public function status(string $email): array
    {
        $now = time();
        $rows = $this->load();
        $ip = Security::clientIp();
        $pair = $rows[$this->key('pair', $email . '|' . $ip)] ?? null;
        $byIp = $rows[$this->key('ip', $ip)] ?? null;
        $acct = $rows[$this->key('acct', $email)] ?? null;

        $locked = 0;
        foreach ([$pair, $byIp] as $r) {
            if ($r && (int)($r['lock_until'] ?? 0) > $now) $locked = max($locked, (int)$r['lock_until'] - $now);
        }
        $bits = 0;
        $acctFails = ($acct && (int)($acct['window_start'] ?? 0) + self::WINDOW > $now) ? (int)($acct['fails'] ?? 0) : 0;
        $ipFails = ($byIp && (int)($byIp['window_start'] ?? 0) + self::WINDOW > $now) ? (int)($byIp['fails'] ?? 0) : 0;
        $pairFails = ($pair && (int)($pair['window_start'] ?? 0) + self::WINDOW > $now) ? (int)($pair['fails'] ?? 0) : 0;
        if (max($acctFails, $pairFails) >= self::POW_AFTER || $ipFails >= 6) $bits = self::POW_BITS;
        if ($acctFails >= 15 || $ipFails >= 15) $bits = self::POW_BITS_HARD;
        return ['locked' => $locked, 'pow_bits' => $bits];
    }

    private function bump(array &$rows, string $key, int $max, bool $lock): void
    {
        $now = time();
        $r = $rows[$key] ?? ['k' => $key, 'fails' => 0, 'window_start' => $now, 'lock_until' => 0, 'lock_level' => 0];
        if ((int)$r['window_start'] + self::WINDOW <= $now) { $r['fails'] = 0; $r['window_start'] = $now; }
        $r['fails'] = (int)$r['fails'] + 1;
        if ($lock && (int)$r['fails'] >= $max) {
            $level = (int)($r['lock_level'] ?? 0);
            $r['lock_until'] = $now + min(self::LOCK_MAX, self::LOCK_BASE * (2 ** $level));
            $r['lock_level'] = min(6, $level + 1);
            $r['fails'] = 0;
            $r['window_start'] = $now;
        }
        $rows[$key] = $r;
    }

    public function fail(string $email): void
    {
        $rows = $this->load();
        $ip = Security::clientIp();
        $this->bump($rows, $this->key('pair', $email . '|' . $ip), self::PAIR_MAX, true);
        $this->bump($rows, $this->key('ip', $ip), self::IP_MAX, true);
        $this->bump($rows, $this->key('acct', $email), PHP_INT_MAX, false);
        $this->save($rows);
    }

    public function success(string $email): void
    {
        $rows = $this->load();
        $ip = Security::clientIp();
        unset($rows[$this->key('pair', $email . '|' . $ip)], $rows[$this->key('acct', $email)]);
        $this->save($rows);
    }

    // ── Preuve de travail ──────────────────────────────────────────────────
    private function secret(): string
    {
        return (string)($GLOBALS['kova_hmac_key'] ?? 'kova');
    }

    public function issueChallenge(int $bits): array
    {
        $id = bin2hex(random_bytes(12));
        $exp = time() + self::POW_TTL;
        $payload = $id . '.' . $exp . '.' . $bits;
        $sig = hash_hmac('sha256', 'pow|' . $payload, $this->secret());
        return ['challenge' => $payload . '.' . $sig, 'bits' => $bits];
    }

    /** Vérifie signature, expiration, difficulté, usage unique et la solution elle-même. */
    public function verifyProof(string $challenge, string $nonce, int $requiredBits): bool
    {
        if ($requiredBits <= 0) return true;
        if (!preg_match('/^([a-f0-9]{24})\.(\d{9,11})\.(\d{1,2})\.([a-f0-9]{64})$/', $challenge, $m)) return false;
        [, $id, $exp, $bits, $sig] = $m;
        if (!hash_equals(hash_hmac('sha256', 'pow|' . $id . '.' . $exp . '.' . $bits, $this->secret()), $sig)) return false;
        if ((int)$exp < time() || (int)$bits < $requiredBits || (int)$bits > 24) return false;
        if (!preg_match('/^[0-9]{1,12}$/', $nonce)) return false;
        if (!self::hasLeadingZeroBits(hash('sha256', $challenge . ':' . $nonce, true), (int)$bits)) return false;

        // Usage unique : un défi résolu ne peut pas être rejoué.
        $now = time();
        $used = array_values(array_filter($this->store->all('pow_used'), fn($r) => (int)($r['exp'] ?? 0) > $now));
        foreach ($used as $r) if (($r['id'] ?? '') === $id) return false;
        $used[] = ['id' => $id, 'exp' => (int)$exp];
        $this->store->replace('pow_used', $used);
        return true;
    }

    private static function hasLeadingZeroBits(string $bin, int $bits): bool
    {
        $full = intdiv($bits, 8);
        for ($i = 0; $i < $full; $i++) if (ord($bin[$i]) !== 0) return false;
        $rest = $bits % 8;
        if ($rest === 0) return true;
        return (ord($bin[$full]) >> (8 - $rest)) === 0;
    }
}
