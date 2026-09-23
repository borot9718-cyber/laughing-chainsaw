<?php
declare(strict_types=1);

namespace Kova\Core;

/**
 * Sécurité du compte et notifications push :
 *  - double authentification TOTP (activation, désactivation, codes de secours) ;
 *  - déconnexion de tous les appareils ;
 *  - abonnements Web Push (navigateur/téléphone) et notification de test.
 * Toute action sensible exige le mot de passe actuel (et le code 2FA si activé) et est limitée en débit.
 */
final class Account extends Module
{
    public function handle(string $path, string $method, array $input): void
    {
        if ($path === 'account/security' && $method === 'GET')            $this->status();
        if ($path === 'account/2fa/setup' && $method === 'POST')          $this->twofaSetup($input);
        if ($path === 'account/2fa/enable' && $method === 'POST')         $this->twofaEnable($input);
        if ($path === 'account/2fa/disable' && $method === 'POST')        $this->twofaDisable($input);
        if ($path === 'account/2fa/backup-codes' && $method === 'POST')   $this->backupCodes($input);
        if ($path === 'account/sessions/logout-all' && $method === 'POST') $this->logoutAll($input);

        if ($path === 'push/config' && $method === 'GET')       $this->pushConfig();
        if ($path === 'push/subscribe' && $method === 'POST')   $this->pushSubscribe($input);
        if ($path === 'push/unsubscribe' && $method === 'POST') $this->pushUnsubscribe($input);
        if ($path === 'push/test' && $method === 'POST')        $this->pushTest();
    }

    // ── Aides ──────────────────────────────────────────────────────────────
    private function throttle(string $scope, int $max = 10, int $window = 900): void
    {
        if (!Security::rateLimit($this->store, $scope, $max, $window)) $this->fail('Trop de tentatives. Réessayez dans quelques minutes.', 429);
    }

    /** Mot de passe actuel obligatoire pour toute opération sensible. */
    private function requirePassword(array $u, array $input): void
    {
        $this->throttle('acct-sensitive:' . $u['id']);
        if (!Passwords::verify((string)($input['password'] ?? ''), (string)($u['password_hash'] ?? ''))) {
            Security::audit($this->store, 'sensitive_action_bad_password', (string)$u['id']);
            $this->fail('Mot de passe incorrect.', 422);
        }
    }

    private function saveUser(string $id, array $changes): void
    {
        $this->store->updateWhere('users', fn($r) => ($r['id'] ?? '') === $id, fn($r) => array_merge($r, $changes, ['updated_at' => date('c')]));
    }

    private function notifyEmail(array $u, string $subject, string $headline, string $message): void
    {
        if (!empty($u['email'])) (new Mailer($this->env))->sendSecurityNotice((string)$u['email'], $subject, $headline, $message);
    }

    /** Code TOTP valide OU code de secours non utilisé (le second est consommé). */
    private function checkSecondFactor(array $u, string $code): bool
    {
        $secret = Totp::open((string)($u['twofa_secret_enc'] ?? ''));
        if ($secret !== null) {
            $step = Totp::verify($secret, $code, (int)($u['twofa_last_step'] ?? 0));
            if ($step !== null) { $this->saveUser((string)$u['id'], ['twofa_last_step' => $step]); return true; }
        }
        $hash = Totp::hashBackup($code);
        $backups = array_values((array)($u['twofa_backup'] ?? []));
        $i = array_search($hash, $backups, true);
        if ($i !== false && strlen(preg_replace('/[^a-f0-9]/i', '', $code) ?? '') === 10) {
            unset($backups[$i]);
            $this->saveUser((string)$u['id'], ['twofa_backup' => array_values($backups)]);
            return true;
        }
        return false;
    }

    // ── État ───────────────────────────────────────────────────────────────
    private function status(): never
    {
        $u = $this->me();
        $email = (string)($u['email'] ?? '');
        $masked = preg_replace('/(?<=.).(?=[^@]*@)/u', '•', $email) ?? $email;
        $this->ok([
            'email'           => $masked,
            'email_verified'  => !empty($u['verified']),
            'twofa_enabled'   => !empty($u['twofa_enabled']),
            'backup_left'     => count((array)($u['twofa_backup'] ?? [])),
            'last_login'      => $u['last_login'] ?? null,
            'created_at'      => $u['created_at'] ?? null,
            'is_admin'        => $this->app->isAdmin($u),
        ]);
    }

    // ── 2FA ────────────────────────────────────────────────────────────────
    private function twofaSetup(array $input): never
    {
        $u = $this->me();
        if (!empty($u['twofa_enabled'])) $this->fail('La double authentification est déjà activée.');
        $this->requirePassword($u, $input);
        $secret = Totp::generateSecret();
        $this->saveUser((string)$u['id'], ['twofa_pending_secret_enc' => Totp::seal($secret), 'twofa_pending_at' => date('c')]);
        $account = (string)($u['email'] ?? 'compte');
        $this->ok([
            'secret'  => trim(chunk_split($secret, 4, ' ')),
            'uri'     => Totp::uri($secret, $account, (string)$this->env->get('APP_NAME', 'KOVA')),
            'message' => 'Ajoutez ce secret à votre application d’authentification, puis saisissez le code à 6 chiffres qu’elle affiche.',
        ]);
    }

    private function twofaEnable(array $input): never
    {
        $u = $this->me();
        $this->throttle('twofa-enable:' . $u['id'], 10, 900);
        if (!empty($u['twofa_enabled'])) $this->fail('La double authentification est déjà activée.');
        $pending = (string)($u['twofa_pending_secret_enc'] ?? '');
        $pendingAt = strtotime((string)($u['twofa_pending_at'] ?? '')) ?: 0;
        $secret = $pending !== '' && $pendingAt > time() - 1800 ? Totp::open($pending) : null;
        if ($secret === null) $this->fail('Configuration expirée. Recommencez l’activation.');
        $step = Totp::verify($secret, (string)($input['code'] ?? ''));
        if ($step === null) $this->fail('Code incorrect. Vérifiez l’heure de votre téléphone et réessayez.');
        [$plain, $hashes] = Totp::newBackupCodes(8);
        $this->saveUser((string)$u['id'], [
            'twofa_enabled' => true, 'twofa_secret_enc' => $pending, 'twofa_last_step' => $step,
            'twofa_backup' => $hashes, 'twofa_pending_secret_enc' => null, 'twofa_pending_at' => null, 'twofa_enabled_at' => date('c'),
        ]);
        Security::audit($this->store, 'twofa_enabled', (string)$u['id']);
        $this->notifyEmail($u, 'Double authentification activée', 'Double authentification activée', 'La double authentification vient d’être activée sur votre compte.');
        $this->ok(['backup_codes' => $plain, 'message' => 'Double authentification activée. Conservez vos codes de secours en lieu sûr : ils ne seront plus affichés.']);
    }

    private function twofaDisable(array $input): never
    {
        $u = $this->me();
        if (empty($u['twofa_enabled'])) $this->fail('La double authentification n’est pas activée.');
        $this->requirePassword($u, $input);
        if (!$this->checkSecondFactor($u, (string)($input['code'] ?? ''))) $this->fail('Code de vérification incorrect.', 422);
        if ($this->app->isAdmin($u) && $this->env->bool('ADMIN_2FA_REQUIRED', false)) {
            $this->fail('La double authentification est obligatoire pour les administrateurs.', 403);
        }
        $this->saveUser((string)$u['id'], ['twofa_enabled' => false, 'twofa_secret_enc' => null, 'twofa_backup' => [], 'twofa_last_step' => 0]);
        Security::audit($this->store, 'twofa_disabled', (string)$u['id']);
        $this->notifyEmail($u, 'Double authentification désactivée', 'Double authentification désactivée', 'La double authentification vient d’être désactivée sur votre compte.');
        $this->ok(['message' => 'Double authentification désactivée.']);
    }

    private function backupCodes(array $input): never
    {
        $u = $this->me();
        if (empty($u['twofa_enabled'])) $this->fail('Activez d’abord la double authentification.');
        $this->requirePassword($u, $input);
        if (!$this->checkSecondFactor($u, (string)($input['code'] ?? ''))) $this->fail('Code de vérification incorrect.', 422);
        [$plain, $hashes] = Totp::newBackupCodes(8);
        $this->saveUser((string)$u['id'], ['twofa_backup' => $hashes]);
        Security::audit($this->store, 'twofa_backup_regenerated', (string)$u['id']);
        $this->ok(['backup_codes' => $plain, 'message' => 'Nouveaux codes générés. Les anciens ne fonctionnent plus.']);
    }

    // ── Sessions ───────────────────────────────────────────────────────────
    private function logoutAll(array $input): never
    {
        $u = $this->me();
        $this->requirePassword($u, $input);
        $this->auth->logoutEverywhere((string)$u['id']);
        Security::audit($this->store, 'logout_everywhere', (string)$u['id']);
        $this->notifyEmail($u, 'Appareils déconnectés', 'Tous vos appareils ont été déconnectés', 'Toutes les sessions ouvertes sur votre compte ont été fermées, sauf celle utilisée pour cette action.');
        $this->ok(['csrf' => Security::csrfToken(), 'message' => 'Tous les autres appareils ont été déconnectés.']);
    }

    // ── Web Push ───────────────────────────────────────────────────────────
    private function pushConfig(): never
    {
        $u = $this->me();
        $push = new WebPush($this->env);
        $mine = 0;
        foreach ($this->store->all('push_subscriptions') as $s) if (($s['user_id'] ?? '') === $u['id']) $mine++;
        $this->ok(['supported' => $push->configured(), 'public_key' => $push->configured() ? $push->publicKey() : '', 'devices' => $mine]);
    }

    private function pushSubscribe(array $input): never
    {
        $u = $this->me();
        $this->throttle('push-subscribe:' . $u['id'], 20, 3600);
        $push = new WebPush($this->env);
        if (!$push->configured()) $this->fail('Les notifications push ne sont pas configurées sur ce serveur.', 503);
        $endpoint = trim((string)($input['endpoint'] ?? ''));
        $p256dh = (string)($input['keys']['p256dh'] ?? '');
        $auth = (string)($input['keys']['auth'] ?? '');
        if (strlen($endpoint) > 600 || !$push->endpointAllowed($endpoint)) $this->fail('Service de notification non pris en charge.');
        if (strlen(WebPush::b64uDecode($p256dh)) !== 65 || strlen(WebPush::b64uDecode($auth)) < 8 || strlen($auth) > 64) $this->fail('Clés d’abonnement invalides.');

        $uid = (string)$u['id'];
        $this->store->mutate('push_subscriptions', function (array $rows) use ($endpoint, $uid, $p256dh, $auth) {
            $rows = array_values(array_filter($rows, fn($r) => ($r['endpoint'] ?? '') !== $endpoint));   // un endpoint = un seul propriétaire
            $mine = array_values(array_filter($rows, fn($r) => ($r['user_id'] ?? '') === $uid));
            if (count($mine) >= 8) {                                                                       // 8 appareils max : on retire les plus anciens
                $drop = array_column(array_slice($mine, 0, count($mine) - 7), 'id');
                $rows = array_values(array_filter($rows, fn($r) => !in_array($r['id'] ?? '', $drop, true)));
            }
            $rows[] = ['id' => 'psh_' . bin2hex(random_bytes(6)), 'user_id' => $uid, 'endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth,
                       'ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160), 'created_at' => date('c')];
            return $rows;
        });
        $this->ok(['message' => 'Notifications activées sur cet appareil.']);
    }

    private function pushUnsubscribe(array $input): never
    {
        $u = $this->me();
        $endpoint = trim((string)($input['endpoint'] ?? ''));
        $uid = (string)$u['id'];
        $this->store->mutate('push_subscriptions', fn(array $rows) => array_values(array_filter($rows,
            fn($r) => !(($r['user_id'] ?? '') === $uid && ($endpoint === '' || ($r['endpoint'] ?? '') === $endpoint)))));
        $this->ok(['message' => 'Notifications désactivées sur cet appareil.']);
    }

    private function pushTest(): never
    {
        $u = $this->me();
        $this->throttle('push-test:' . $u['id'], 5, 3600);
        $r = $this->app->push((string)$u['id'], 'KOVA', 'Les notifications fonctionnent sur cet appareil.', '/notifications', 'test');
        if ($r['sent'] > 0) $this->ok(['sent' => $r['sent'], 'message' => 'Notification de test envoyée.']);
        $this->fail($r['devices'] === 0 ? 'Aucun appareil enregistré : activez d’abord les notifications.' : 'Envoi impossible depuis le serveur (connexions sortantes bloquées par l’hébergeur ?). Les notifications fonctionneront tant que KOVA est ouvert.', 502);
    }
}
