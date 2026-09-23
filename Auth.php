<?php
declare(strict_types=1);

namespace Kova\Core;

final class Auth
{
    public string $lastError = '';

    private const CODE_TTL_SECONDS = 900;   // 15 minutes
    private const CODE_MAX_TRIES   = 5;
    private const CODE_COOLDOWN    = 60;    // secondes entre deux envois
    private const TWOFA_TTL        = 300;   // 5 min pour saisir le code 2FA
    private const TWOFA_MAX_TRIES  = 5;

    public function __construct(private JsonStore $store, private Env $env) {
        $this->start();
    }

    // ── Session ────────────────────────────────────────────────────────────
    private function epoch(): string
    {
        return (string)$this->env->get('SESSION_EPOCH', '3');
    }

    private function userAgentHash(): string
    {
        return hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    private function start(): void
    {
        Security::startSession();
        $idle = (int)$this->env->get('SESSION_DAYS', 3) * 86400;
        $absolute = (int)$this->env->get('SESSION_ABSOLUTE_DAYS', 14) * 86400;
        $expired = (!empty($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > $idle)
                || (!empty($_SESSION['born']) && time() - (int)$_SESSION['born'] > $absolute);
        if ($expired) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['last_activity'] = time();
    }

    /** Ouvre une session authentifiée. L'identifiant de session est TOUJOURS régénéré (anti-fixation). */
    private function beginSession(array $user): void
    {
        $keep = [];                                     // on ne conserve rien de l'état anonyme
        $_SESSION = $keep;
        session_regenerate_id(true);
        $_SESSION['user_id']      = $user['id'];
        $_SESSION['auth_version'] = (int)($user['session_version'] ?? 1);
        $_SESSION['epoch']        = $this->epoch();
        $_SESSION['ua']           = $this->userAgentHash();
        $_SESSION['born']         = time();
        $_SESSION['last_activity'] = time();
        Security::rotateCsrf();
        $this->store->updateWhere('users', fn($r) => ($r['id'] ?? '') === $user['id'], fn($r) => array_merge($r, ['last_login' => date('c')]));
    }

    public function user(): ?array
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) return null;
        // Session émise avant la dernière rotation globale (SESSION_EPOCH) ou depuis un autre navigateur.
        if (($_SESSION['epoch'] ?? '') !== $this->epoch() || !hash_equals((string)($_SESSION['ua'] ?? ''), $this->userAgentHash())) {
            $this->logout();
            return null;
        }
        foreach ($this->store->all('users') as $u) {
            if (($u['id'] ?? '') === $id) {
                $storedVersion = (int)($u['session_version'] ?? 1);
                if ((int)($_SESSION['auth_version'] ?? 0) !== $storedVersion) {
                    $this->logout();
                    return null;
                }
                // Un compte suspendu/banni perd immédiatement sa session en cours.
                if (in_array(strtolower((string)($u['account_status'] ?? 'active')), ['suspended', 'banned'], true)) {
                    $this->logout();
                    return null;
                }
                return $u;
            }
        }
        return null;
    }

    public function requireAuth(): void
    {
        if (!$this->user()) {
            Response::redirect('/connexion');
        }
    }

    /** Champs secrets à ne JAMAIS renvoyer au navigateur. */
    public static function sanitizeUser(array $u): array
    {
        foreach (array_keys($u) as $k) {
            if (preg_match('/hash|secret|token|backup|code|_enc$|session_version|twofa_last|attempts|verification_/i', (string)$k)) unset($u[$k]);
        }
        return $u;
    }

    // ── Connexion ──────────────────────────────────────────────────────────
    /**
     * Contrôle des identifiants SANS ouvrir de session.
     * @return array{status:string,user:?array} status : ok | twofa | invalid | suspended | unverified
     * Le temps de réponse est identique que le compte existe ou non (hachage factice).
     */
    public function checkCredentials(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $found = null;
        foreach ($this->store->all('users') as $u) {
            if (strtolower((string)($u['email'] ?? '')) === $email) { $found = $u; break; }
        }
        $okPassword = Passwords::verify($password, $found['password_hash'] ?? null);
        if (!$found || !$okPassword) return ['status' => 'invalid', 'user' => null];

        if (Passwords::needsRehash((string)$found['password_hash'])) {
            $newHash = Passwords::hash($password);
            $this->store->updateWhere('users', fn($r) => ($r['id'] ?? '') === $found['id'], fn($r) => array_merge($r, ['password_hash' => $newHash]));
        }
        if (in_array(strtolower((string)($found['account_status'] ?? 'active')), ['suspended', 'banned'], true)) {
            return ['status' => 'suspended', 'user' => null];
        }
        if ($this->env->bool('EMAIL_VERIFICATION_REQUIRED', false) && empty($found['verified'])) {
            return ['status' => 'unverified', 'user' => null];
        }
        if (!empty($found['twofa_enabled'])) return ['status' => 'twofa', 'user' => $found];
        return ['status' => 'ok', 'user' => $found];
    }

    public function completeLogin(array $user): void
    {
        $this->beginSession($user);
    }

    /** Ouvre la session d'un utilisateur dont l'e-mail vient d'être prouvé par code (voir verifyCode). */
    public function loginById(string $id): bool
    {
        foreach ($this->store->all('users') as $u) {
            if (($u['id'] ?? '') === $id) {
                if (in_array(strtolower((string)($u['account_status'] ?? 'active')), ['suspended', 'banned'], true)) return false;
                if (!empty($u['twofa_enabled'])) return false;     // 2FA : passe obligatoirement par la connexion normale
                $this->beginSession($u);
                return true;
            }
        }
        return false;
    }

    // ── Double authentification (TOTP) au moment de la connexion ──────────
    public function startTwoFactor(array $user, string $next = ''): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['pending_2fa'] = ['uid' => $user['id'], 'exp' => time() + self::TWOFA_TTL, 'tries' => 0, 'next' => $next];
        $_SESSION['last_activity'] = time();
    }

    public function pendingTwoFactor(): ?array
    {
        $p = $_SESSION['pending_2fa'] ?? null;
        if (!is_array($p) || (int)($p['exp'] ?? 0) < time()) { unset($_SESSION['pending_2fa']); return null; }
        return $p;
    }

    /** @return array{ok:bool,error:string,next:string} */
    public function completeTwoFactor(string $code): array
    {
        $p = $this->pendingTwoFactor();
        if (!$p) return ['ok' => false, 'error' => 'Session de vérification expirée. Reconnectez-vous.', 'next' => ''];
        if ((int)$p['tries'] >= self::TWOFA_MAX_TRIES) {
            unset($_SESSION['pending_2fa']);
            return ['ok' => false, 'error' => 'Trop de tentatives. Reconnectez-vous.', 'next' => ''];
        }
        $user = null;
        foreach ($this->store->all('users') as $u) if (($u['id'] ?? '') === $p['uid']) { $user = $u; break; }
        if (!$user || empty($user['twofa_enabled'])) { unset($_SESSION['pending_2fa']); return ['ok' => false, 'error' => 'Vérification impossible.', 'next' => '']; }

        $secret = Totp::open((string)($user['twofa_secret_enc'] ?? ''));
        $ok = false;
        if ($secret !== null) {
            $step = Totp::verify($secret, $code, (int)($user['twofa_last_step'] ?? 0));
            if ($step !== null) {
                $ok = true;
                $this->store->updateWhere('users', fn($r) => ($r['id'] ?? '') === $user['id'], fn($r) => array_merge($r, ['twofa_last_step' => $step]));
            }
        }
        if (!$ok) {   // code de secours (usage unique)
            $h = Totp::hashBackup($code);
            $backups = array_values((array)($user['twofa_backup'] ?? []));
            $idx = array_search($h, $backups, true);
            if ($idx !== false && strlen(preg_replace('/[^a-f0-9]/i', '', $code) ?? '') === 10) {
                unset($backups[$idx]);
                $this->store->updateWhere('users', fn($r) => ($r['id'] ?? '') === $user['id'], fn($r) => array_merge($r, ['twofa_backup' => array_values($backups)]));
                $ok = true;
            }
        }
        if (!$ok) {
            $_SESSION['pending_2fa']['tries'] = (int)$p['tries'] + 1;
            return ['ok' => false, 'error' => 'Code incorrect.', 'next' => ''];
        }
        $next = (string)($p['next'] ?? '');
        $this->beginSession($user);
        return ['ok' => true, 'error' => '', 'next' => $next];
    }

    /** Déconnecte TOUS les autres appareils : les sessions existantes deviennent invalides. */
    public function logoutEverywhere(string $userId): void
    {
        $new = 1;
        $this->store->updateWhere('users', function ($r) use ($userId) { return ($r['id'] ?? '') === $userId; },
            function ($r) use (&$new) { $new = (int)($r['session_version'] ?? 1) + 1; return array_merge($r, ['session_version' => $new]); });
        $_SESSION['auth_version'] = $new;
        session_regenerate_id(true);
        Security::rotateCsrf();
    }

    /** Invalide immédiatement toutes les sessions d'un AUTRE utilisateur (suspension, changement de rôle). */
    public function logoutUserSessionsNow(string $userId): void
    {
        $this->store->updateWhere('users', fn($r) => ($r['id'] ?? '') === $userId,
            fn($r) => array_merge($r, ['session_version' => (int)($r['session_version'] ?? 1) + 1]));
    }

    /** Recharge la session courante après un changement de version (ex. changement de mot de passe). */
    public function refreshSession(array $user, int $newVersion): void
    {
        $_SESSION['auth_version'] = $newVersion;
        session_regenerate_id(true);
        Security::rotateCsrf();
    }

    // ── Vérification d'e-mail par code à 6 chiffres ───────────────────────
    private function newCode(): string
    {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /** Le code n'est jamais stocké en clair : HMAC lié à l'e-mail et à APP_KEY. */
    private function codeHash(string $email, string $code): string
    {
        return hash_hmac('sha256', strtolower(trim($email)) . '|' . $code, (string)($GLOBALS['kova_hmac_key'] ?? 'kova'));
    }

    private function findUserByEmail(string $email): ?array
    {
        foreach ($this->store->all('users') as $u) {
            if (strtolower((string)($u['email'] ?? '')) === strtolower(trim($email))) return $u;
        }
        return null;
    }

    /**
     * Valide un code d'e-mail.
     *
     * CORRECTIF DE SÉCURITÉ CRITIQUE : auparavant, un compte DÉJÀ vérifié renvoyait ok=true, et
     * l'appelant ouvrait alors une session pour ce compte → n'importe qui pouvait se connecter à
     * un compte vérifié avec son e-mail et un code quelconque. Un compte déjà vérifié n'a plus
     * aucun code valide : la réponse est identique à celle d'un code faux.
     *
     * @return array{ok:bool,error:string,user:?array}
     */
    public function verifyCode(string $email, string $code): array
    {
        $generic = ['ok' => false, 'error' => 'Code invalide ou expiré.', 'user' => null];
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) return ['ok' => false, 'error' => 'Saisissez le code à 6 chiffres reçu par e-mail.', 'user' => null];
        $u = $this->findUserByEmail($email);
        if (!$u || !empty($u['verified'])) {
            $this->codeHash($email, $code);                     // même coût de calcul dans tous les cas
            return $generic;
        }

        $hash = (string)($u['verification_code_hash'] ?? '');
        if ($hash === '' || strtotime((string)($u['verification_expires'] ?? '')) < time()) {
            return ['ok' => false, 'error' => 'Code expiré. Demandez un nouveau code.', 'user' => null];
        }
        if ((int)($u['verification_attempts'] ?? 0) >= self::CODE_MAX_TRIES) {
            return ['ok' => false, 'error' => 'Trop de tentatives. Demandez un nouveau code.', 'user' => null];
        }
        if (!hash_equals($hash, $this->codeHash((string)$u['email'], $code))) {
            $this->store->updateWhere('users', fn($r) => ($r['id'] ?? '') === $u['id'],
                fn($r) => array_merge($r, ['verification_attempts' => (int)($r['verification_attempts'] ?? 0) + 1]));
            $left = self::CODE_MAX_TRIES - ((int)($u['verification_attempts'] ?? 0) + 1);
            return ['ok' => false, 'error' => 'Code incorrect.' . ($left > 0 ? " Il vous reste $left essai" . ($left > 1 ? 's' : '') . '.' : ' Demandez un nouveau code.'), 'user' => null];
        }
        $this->store->updateWhere('users', fn($r) => ($r['id'] ?? '') === $u['id'],
            fn($r) => array_merge($r, ['verified' => true, 'verified_at' => date('c'), 'verification_code_hash' => '', 'verification_expires' => '', 'verification_attempts' => 0]));
        return ['ok' => true, 'error' => '', 'user' => $u];
    }

    /**
     * Génère un nouveau code pour un compte non vérifié.
     * @return array{ok:bool,code:?string,wait:int} code = null si rien à envoyer (compte inconnu/déjà vérifié :
     *         on ne le révèle pas à l'appelant, pour éviter l'énumération des comptes)
     */
    public function resendCode(string $email): array
    {
        $u = $this->findUserByEmail($email);
        if (!$u || !empty($u['verified'])) return ['ok' => true, 'code' => null, 'wait' => 0];
        $sentAt = strtotime((string)($u['verification_sent_at'] ?? '')) ?: 0;
        $wait = self::CODE_COOLDOWN - (time() - $sentAt);
        if ($wait > 0) return ['ok' => false, 'code' => null, 'wait' => $wait];
        $code = $this->newCode();
        $this->store->updateWhere('users', fn($r) => ($r['id'] ?? '') === $u['id'], fn($r) => array_merge($r, [
            'verification_code_hash' => $this->codeHash((string)$u['email'], $code),
            'verification_expires'   => date('c', time() + self::CODE_TTL_SECONDS),
            'verification_attempts'  => 0,
            'verification_sent_at'   => date('c'),
        ]));
        return ['ok' => true, 'code' => $code, 'wait' => 0];
    }

    // ── Inscription ────────────────────────────────────────────────────────
    /**
     * Crée un compte. Si l'adresse existe déjà, ne révèle RIEN (F-05 de l'audit) : retourne
     * ['duplicate'=>true, 'email'=>…] et l'appelant envoie un avis à la personne concernée ;
     * la réponse HTTP est alors identique à celle d'une vraie inscription.
     */
    public function register(string $email, string $password, string $dob, string $displayName): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new \RuntimeException('Adresse e-mail invalide.');
        }
        $displayName = trim($displayName);
        if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 30) {
            throw new \RuntimeException('Le pseudo doit contenir entre 2 et 30 caractères.');
        }
        if (preg_match('/[\x00-\x1f\x7f<>]/', $displayName)) {
            throw new \RuntimeException('Le pseudo contient des caractères non autorisés.');
        }
        if (($err = Passwords::validate($password, $email, $displayName)) !== null) {
            throw new \RuntimeException($err);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
            throw new \RuntimeException('Date de naissance invalide.');
        }
        try {
            $birth = new \DateTimeImmutable($dob);
            $today = new \DateTimeImmutable('today');
            $minimumBirth = $today->modify('-16 years');
            if ($birth > $minimumBirth || $birth > $today) {
                throw new \RuntimeException('KOVA est accessible à partir de 16 ans.');
            }
            if ($birth < $today->modify('-120 years')) throw new \RuntimeException('Date de naissance invalide.');
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Date de naissance invalide.');
        }

        if ($this->findUserByEmail($email)) {
            Passwords::hash($password);                         // même durée que la création réelle
            return ['duplicate' => true, 'email' => $email];
        }

        $code = $this->newCode();
        $user = [
            'id'                  => 'usr_' . bin2hex(random_bytes(10)),
            'email'               => $email,
            'password_hash'       => Passwords::hash($password),
            'date_of_birth'       => $dob,
            'display_name'        => $displayName,
            'bio'                 => '',
            'avatar_url'          => '',
            'cover_url'           => '',
            'verified'            => false,
            'verification_code_hash' => $this->codeHash($email, $code),
            'verification_expires'   => date('c', time() + self::CODE_TTL_SECONDS),
            'verification_attempts'  => 0,
            'verification_sent_at'   => date('c'),
            'role'                => 'USER',
            'account_status'      => 'active',
            'session_version'     => 1,
            'created_at'          => date('c'),
        ];
        $this->store->insert('users', $user);
        return $user + ['plain_code' => $code];   // clé transitoire : jamais stockée
    }

    // ── Mot de passe oublié ────────────────────────────────────────────────
    public function createPasswordReset(string $email): ?array
    {
        $u = $this->findUserByEmail($email);
        if (!$u) return null;
        $token = bin2hex(random_bytes(32));
        $record = [
            'id'         => 'rst_' . bin2hex(random_bytes(8)),
            'user_id'    => $u['id'],
            'token_hash' => hash('sha256', $token),
            'email'      => $u['email'],
            'expires_at' => date('c', time() + 3600),
            'used'       => false,
            'created_at' => date('c'),
        ];
        $this->store->insert('password_resets', $record);
        return $record + ['plain_token' => $token];
    }

    public function resetPassword(string $token, string $newPassword): bool
    {
        $reset = null;
        foreach ($this->store->all('password_resets') as $r) {
            $matches = !empty($r['token_hash']) && hash_equals((string)$r['token_hash'], hash('sha256', $token));
            if ($matches && !($r['used'] ?? true) && strtotime((string)($r['expires_at'] ?? '0')) > time()) { $reset = $r; break; }
        }
        if (!$reset) return false;
        $user = null;
        foreach ($this->store->all('users') as $u) if (($u['id'] ?? '') === $reset['user_id']) { $user = $u; break; }
        if (!$user) return false;
        if (($err = Passwords::validate($newPassword, (string)$user['email'], (string)($user['display_name'] ?? ''))) !== null) {
            throw new \RuntimeException($err);
        }
        $hash = Passwords::hash($newPassword);
        $this->store->updateWhere('users',
            fn($u) => ($u['id'] ?? '') === $reset['user_id'],
            fn($u) => array_merge($u, ['password_hash' => $hash, 'session_version' => (int)($u['session_version'] ?? 1) + 1, 'updated_at' => date('c')])
        );
        $this->store->updateWhere('password_resets',
            fn($r) => ($r['user_id'] ?? '') === $reset['user_id'],
            fn($r) => array_merge($r, ['used' => true])
        );
        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000, 'path' => $params['path'] ?? '/', 'domain' => $params['domain'] ?? '',
                'secure' => (bool)($params['secure'] ?? false), 'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
