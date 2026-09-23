<?php
declare(strict_types=1);

namespace Kova\Core;

final class App
{
    public function __construct(
        private Env $env,
        private Debug $debugger,
        private JsonStore $store,
        private Auth $auth
    ) {}

    public function run(): void { (new Router($this))->dispatch(); }
    public function auth(): Auth { return $this->auth; }
    public function store(): JsonStore { return $this->store; }
    public function env(): Env { return $this->env; }

    public function maintenanceState(): array
    {
        $rows = $this->store->all('site_settings');
        $cfg = [];
        foreach ($rows as $row) {
            if (($row['key'] ?? '') === 'maintenance') { $cfg = is_array($row['value'] ?? null) ? $row['value'] : []; break; }
        }
        $now = time();
        $start = !empty($cfg['start_at']) ? strtotime((string)$cfg['start_at']) : null;
        $end = !empty($cfg['end_at']) ? strtotime((string)$cfg['end_at']) : null;
        $enabled = !empty($cfg['enabled']);
        if ($enabled && $end !== null && $end <= $now) {
            $enabled = false;
            $cfg['enabled'] = false;
            $cfg['updated_at'] = date('c');
            $this->store->updateWhere('site_settings', fn($r) => ($r['key'] ?? '') === 'maintenance', fn($r) => array_merge($r, ['value'=>$cfg,'updated_at'=>date('c')]));
        }
        $active = $enabled && ($start === null || $start <= $now) && ($end === null || $end > $now);
        return [
            'enabled' => $enabled, 'active' => $active,
            'start_at' => $cfg['start_at'] ?? '', 'end_at' => $cfg['end_at'] ?? '',
            'message' => $cfg['message'] ?? 'KOVA est temporairement en maintenance. Merci de revenir un peu plus tard.',
            'updated_at' => $cfg['updated_at'] ?? ''
        ];
    }

    public function requireMaintenanceAdmin(): array
    {
        return $this->requireAdmin();
    }

    public function saveMaintenance(array $input, array $admin): array
    {
        $enabled = !empty($input['enabled']);
        $message = mb_substr(trim((string)($input['message'] ?? '')), 0, 500);
        if ($message === '') $message = 'KOVA est temporairement en maintenance. Merci de revenir un peu plus tard.';
        $startRaw = trim((string)($input['start_at'] ?? ''));
        $endRaw = trim((string)($input['end_at'] ?? ''));
        $start = $startRaw !== '' ? strtotime($startRaw) : null;
        $end = $endRaw !== '' ? strtotime($endRaw) : null;
        if ($enabled && $end !== null && $start !== null && $end <= $start) Response::json(['ok'=>false,'error'=>'La fin de maintenance doit être après le début.'],422);
        if ($enabled && $end !== null && $end <= time()) Response::json(['ok'=>false,'error'=>'La date de fin doit être dans le futur.'],422);
        $value = ['enabled'=>$enabled,'start_at'=>$start ? date('c',$start) : '','end_at'=>$end ? date('c',$end) : '','message'=>$message,'updated_at'=>date('c'),'updated_by'=>$admin['id']];
        $count = $this->store->updateWhere('site_settings', fn($r)=>(($r['key']??'')==='maintenance'), fn($r)=>array_merge($r,['value'=>$value,'updated_at'=>date('c')]));
        if (!$count) $this->store->insert('site_settings',['id'=>'set_'.bin2hex(random_bytes(8)),'key'=>'maintenance','value'=>$value,'updated_at'=>date('c')]);
        $this->logActivity((string)$admin['id'],'maintenance_update',['enabled'=>$enabled,'start_at'=>$value['start_at'],'end_at'=>$value['end_at']]);
        Security::audit($this->store,'maintenance_update',(string)$admin['id'],['enabled'=>$enabled]);
        return $this->maintenanceState();
    }

    public function page(string $view, array $data = []): void
    {
        $user = $this->auth->user();
        // Les vues n'ont jamais besoin des secrets (hachage, 2FA, codes) : on ne les leur transmet pas.
        View::render($view, array_merge([
            'user'        => $user ? Auth::sanitizeUser($user) : $user,
            'debug'       => $this->debugger->enabled(),
            'siteName'    => $this->env->get('SEO_SITE_NAME', 'KOVA'),
            'description' => $this->env->get('SEO_DESCRIPTION', 'KOVA, un espace social moderne.'),
        ], $data));
    }

    public function health(): never
    {
        $checks = [
            'php'            => PHP_VERSION,
            'json_storage'   => is_dir(dirname(__DIR__, 2) . '/storage/json'),
            'css'            => is_file(dirname(__DIR__, 2) . '/assets/css/kova.css'),
            'js'             => is_file(dirname(__DIR__, 2) . '/assets/js/kova.js'),
            'manifest'       => is_file(dirname(__DIR__, 2) . '/manifest.webmanifest'),
            'service_worker' => is_file(dirname(__DIR__, 2) . '/sw.js'),
            'phpmailer'      => is_file(dirname(__DIR__, 2) . '/vendor/phpmailer/src/PHPMailer.php'),
            'debug'          => $this->debugger->enabled(),
        ];
        $healthy = !in_array(false, [$checks['json_storage'],$checks['css'],$checks['js'],$checks['manifest'],$checks['service_worker'],$checks['phpmailer']], true);
        // Version de PHP, état du debug… : réservés aux administrateurs (ne pas informer un attaquant).
        Response::json($this->isAdmin() ? ['ok' => $healthy, 'checks' => $checks] : ['ok' => $healthy], $healthy ? 200 : 503);
    }

    public function robots(): never
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        $url = rtrim((string)$this->env->get('APP_URL', ''), '/');
        $blocked = ['/api/', '/debug/', '/oauth/', '/admin', '/app', '/profil', '/messages', '/notifications', '/groupes', '/communautes',
                    '/boutique', '/parametres', '/recherche', '/developpeurs', '/aide', '/deconnexion', '/reinitialiser-mot-de-passe', '/verifier-email'];
        echo "User-agent: *\nAllow: /\n";
        foreach ($blocked as $b) echo "Disallow: $b\n";
        echo "\nSitemap: {$url}/sitemap.xml\n";
        exit;
    }

    public function sitemap(): never
    {
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        $url   = rtrim((string)$this->env->get('APP_URL', ''), '/');
        // Seules les pages publiques et indexables (les autres sont privées ou en noindex).
        $pages = ['/' => '1.0', '/a-propos' => '0.6', '/confidentialite' => '0.4', '/conditions' => '0.4'];
        $mtime = static fn(string $view) => gmdate('c', (int)@filemtime(dirname(__DIR__, 2) . '/resources/views/' . $view . '.php') ?: time());
        $views = ['/' => 'landing', '/a-propos' => 'about', '/confidentialite' => 'privacy', '/conditions' => 'terms'];
        echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($pages as $p => $prio) {
            echo '<url><loc>' . htmlspecialchars($url . ($p === '/' ? '/' : $p), ENT_XML1, 'UTF-8') . '</loc><lastmod>' . $mtime($views[$p]) . '</lastmod><priority>' . $prio . '</priority></url>';
        }
        echo '</urlset>';
        exit;
    }

    public function debugAssets(): void
    {
        if (!$this->debugger->enabled()) { http_response_code(404); echo 'Debug désactivé.'; return; }
        $base   = dirname(__DIR__, 2);
        $assets = [
            '/assets/css/kova.css'          => $base . '/assets/css/kova.css',
            '/assets/js/kova.js'            => $base . '/assets/js/kova.js',
            '/assets/images/kova-logo.svg'  => $base . '/assets/images/kova-logo.svg',
            '/manifest.webmanifest'         => $base . '/manifest.webmanifest',
            '/sw.js'                        => $base . '/sw.js',
        ];
        $this->page('debug-assets', ['assets' => $assets]);
    }

    public function isAdmin(?array $user = null): bool
    {
        $user ??= $this->auth->user();
        return is_array($user) && in_array(strtoupper((string)($user['role'] ?? 'USER')), ['ADMIN', 'SUPERADMIN'], true);
    }

    /** Vrai si l'administrateur satisfait la politique 2FA (ADMIN_2FA_REQUIRED). */
    public function adminSecurityOk(?array $user = null): bool
    {
        $user ??= $this->auth->user();
        if (!$user || !$this->env->bool('ADMIN_2FA_REQUIRED', false)) return true;
        return !empty($user['twofa_enabled']);
    }

    public function requireAdmin(): array
    {
        $user = $this->auth->user();
        if (!$user) Response::json(['ok' => false, 'error' => 'Authentification requise.'], 401);
        if (!$this->isAdmin($user)) Response::json(['ok' => false, 'error' => 'Accès administrateur requis.'], 403);
        if (!$this->adminSecurityOk($user)) Response::json(['ok' => false, 'error' => 'Activez la double authentification (Paramètres › Sécurité) pour utiliser l’administration.', 'code' => 'twofa_required'], 403);
        return $user;
    }

    /** Types de notification => clé de préférence (Paramètres > Notifications). */
    private const NOTIF_PREF = [
        'like' => 'likes', 'comment' => 'comments', 'reply' => 'comments', 'follow' => 'follows',
        'group_request' => 'groups', 'group_join' => 'groups', 'group_approved' => 'groups',
        'group_rejected' => 'groups', 'group_removed' => 'groups',
        'order_new' => 'orders', 'order_status' => 'orders', 'message' => 'messages',
    ];

    /** Préférence de notification (Paramètres › Notifications) : vrai par défaut. */
    public function prefEnabled(string $userId, string $key): bool
    {
        foreach ($this->store->all('notification_preferences') as $row) {
            if (($row['user_id'] ?? '') === $userId) return !array_key_exists($key, $row) || (bool)$row[$key];
        }
        return true;
    }

    public function addNotification(string $userId, string $type, string $message, array $meta = []): void
    {
        $prefKey = self::NOTIF_PREF[$type] ?? null;
        if ($prefKey !== null && !$this->prefEnabled($userId, $prefKey)) return;

        // Anti-doublon : on ne recrée pas une notification identique encore NON LUE
        // (ex. plusieurs messages d'une même conversation, « j'aime » retiré puis remis).
        $inApp = true;
        if (!empty($meta['dedupe'])) {
            foreach ($this->store->all('notifications') as $n) {
                if (($n['user_id'] ?? '') === $userId && ($n['dedupe'] ?? '') === $meta['dedupe'] && empty($n['read'])) { $inApp = false; break; }
            }
        }
        if ($inApp) {
            $this->store->insert('notifications', array_merge([
                'id' => 'notif_' . bin2hex(random_bytes(8)),
                'user_id' => $userId,
                'type' => $type,
                'message' => $message,
                'read' => false,
                'created_at' => date('c'),
            ], $meta));
        }
        // Pop-up sur le téléphone / l'ordinateur (si l'utilisateur a activé les notifications push).
        if (isset(self::NOTIF_PREF[$type])) {
            $link = (string)($meta['link'] ?? '/notifications');
            $this->push($userId, 'KOVA', $message, str_starts_with($link, '/') && !str_starts_with($link, '//') ? $link : '/notifications', (string)($meta['dedupe'] ?? $type));
        }
    }

    /**
     * Envoie une notification push à tous les appareils de l'utilisateur.
     * Ne lève jamais d'exception : une notification qui échoue ne doit pas casser l'action en cours.
     * @return array{sent:int,devices:int}
     */
    public function push(string $userId, string $title, string $body, string $url = '/notifications', string $tag = ''): array
    {
        $out = ['sent' => 0, 'devices' => 0];
        try {
            $wp = new WebPush($this->env);
            $subs = array_values(array_filter($this->store->all('push_subscriptions'), fn($r) => ($r['user_id'] ?? '') === $userId));
            $out['devices'] = count($subs);
            if (!$subs || !$wp->configured()) return $out;
            $unread = 0;
            foreach ($this->store->all('notifications') as $n) if (($n['user_id'] ?? '') === $userId && empty($n['read'])) $unread++;
            $payload = ['title' => mb_substr($title, 0, 80), 'body' => mb_substr($body, 0, 180), 'url' => $url, 'tag' => $tag !== '' ? mb_substr($tag, 0, 64) : 'kova', 'unreadCount' => $unread];
            $gone = [];
            foreach (array_slice($subs, 0, 8) as $sub) {
                $r = $wp->send($sub, $payload);
                if ($r['ok']) $out['sent']++;
                elseif ($r['gone']) $gone[] = (string)($sub['id'] ?? '');
            }
            if ($gone) {
                $this->store->mutate('push_subscriptions', fn(array $rows) => array_values(array_filter($rows, fn($r) => !in_array($r['id'] ?? '', $gone, true))));
            }
        } catch (\Throwable $e) {
            error_log('[KOVA push] ' . $e->getMessage());
        }
        return $out;
    }

    /** Journal d'activité d'administration (affiché dans l'espace admin). */
    public function logActivity(string $actorId, string $action, array $meta = []): void
    {
        $logs = $this->store->all('activity_logs');
        $logs[] = ['id' => 'log_' . bin2hex(random_bytes(6)), 'actor_id' => $actorId, 'action' => $action, 'meta' => $meta, 'created_at' => date('c')];
        $this->store->replace('activity_logs', array_slice($logs, -1000));
    }

    /**
     * Rôle actif de l'utilisateur dans l'espace (groupe/communauté) d'une publication :
     * owner | moderator | member | none.
     */
    public function spaceRole(array $post, string $userId): string
    {
        foreach ([['group_id', 'groups', 'group_members'], ['community_id', 'communities', 'community_members']] as [$fk, $table, $members]) {
            $sid = (string)($post[$fk] ?? '');
            if ($sid === '') continue;
            foreach ($this->store->all($table) as $sp) {
                if (($sp['id'] ?? '') === $sid && empty($sp['deleted']) && ($sp['owner_id'] ?? '') === $userId) return 'owner';
            }
            foreach ($this->store->all($members) as $r) {
                if (($r[$fk] ?? '') === $sid && ($r['user_id'] ?? '') === $userId && ($r['status'] ?? 'active') !== 'pending') {
                    return ($r['role'] ?? 'member') === 'moderator' ? 'moderator' : 'member';
                }
            }
        }
        return 'none';
    }

    /**
     * Une publication de groupe PRIVÉ n'est visible (lecture, commentaires, réactions)
     * que par les membres. Les autres publications sont visibles par tout membre connecté.
     */
    public function canSeePost(array $post, string $userId): bool
    {
        $gid = (string)($post['group_id'] ?? '');
        if ($gid !== '') {
            foreach ($this->store->all('groups') as $g) {
                if (($g['id'] ?? '') !== $gid) continue;
                if (!empty($g['deleted'])) return false;
                if (($g['visibility'] ?? 'public') !== 'private') return true;
                return $this->spaceRole($post, $userId) !== 'none';
            }
            return false;
        }
        return true;
    }

    private function findPost(string $id): ?array
    {
        foreach ($this->store->all('posts') as $p) {
            if (($p['id'] ?? '') === $id && empty($p['deleted'])) return $p;
        }
        return null;
    }

    /** Chemin interne sûr (anti « open redirect ») pour le paramètre next. */
    private function safeNext(string $p): string
    {
        if ($p === '' || $p[0] !== '/' || str_starts_with($p, '//') || str_contains($p, "\\") || preg_match('/[\r\n]/', $p) || str_starts_with($p, '/api/')) return '';
        return substr($p, 0, 800);
    }

    /** Modules d'API : chacun répond (et termine la requête) s'il reconnaît la route. */
    private function modules(): array
    {
        $cloud = new Cloudinary($this->env);
        $args  = [$this, $this->store, $this->auth, $this->env, $cloud];
        return [
            new Social(...$args),
            new Spaces(...[...$args, 'groups']),
            new Spaces(...[...$args, 'communities']),
            new Messaging(...$args),
            new Shop(...$args),
            new OAuth(...$args),
            new Account(...$args),
        ];
    }

    public function oauth(): OAuth
    {
        return new OAuth($this, $this->store, $this->auth, $this->env, new Cloudinary($this->env));
    }

    private function gatewaySettings(): array
    {
        foreach ($this->store->all('ai_gateway_settings') as $row) {
            if (($row['id'] ?? '') === 'default') return $row;
        }
        return ['id'=>'default','enabled'=>false,'token_hash'=>'','token_prefix'=>'','style'=>'','posts_per_day'=>1,'publish_hours'=>['09:00'],'updated_at'=>''];
    }

    private function requireGatewayToken(): array
    {
        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) Response::json(['ok'=>false,'error'=>'Jeton de passerelle requis.'],401);
        $token = trim($m[1]);
        $settings = $this->gatewaySettings();
        if (empty($settings['enabled']) || empty($settings['token_hash']) || !hash_equals((string)$settings['token_hash'], hash('sha256', $token))) {
            Response::json(['ok'=>false,'error'=>'Jeton de passerelle invalide ou désactivé.'],401);
        }
        return $settings;
    }

    private function requireGatewayIdentity(): array
    {
        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) Response::json(['ok'=>false,'error'=>'Jeton gateway ou profil requis.'],401);
        $token = trim($m[1]);
        $settings = $this->gatewaySettings();
        if (!empty($settings['enabled']) && !empty($settings['token_hash']) && hash_equals((string)$settings['token_hash'], hash('sha256',$token))) return ['settings'=>$settings,'user'=>null];
        $hash = hash('sha256',$token);
        foreach ($this->store->all('oauth_tokens') as $oauthToken) {
            if (($oauthToken['access_hash'] ?? '') !== $hash || !empty($oauthToken['revoked']) || (int)($oauthToken['access_expires'] ?? 0) < time()) continue;
            foreach ($this->store->all('users') as $user) if ((string)($user['id'] ?? '') === (string)($oauthToken['user_id'] ?? '') && !in_array(strtolower((string)($user['account_status'] ?? 'active')), ['suspended','banned'], true)) return ['settings'=>$settings,'user'=>$user];
        }
        Response::json(['ok'=>false,'error'=>'Jeton gateway ou profil invalide/expiré.'],401);
    }

    // ─── API centralisée ───────────────────────────────────────────────────────
    public function api(string $path, string $method): never
    {
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        // Pré-vol CORS : uniquement pour les endpoints OAuth publics (jetons Bearer, sans cookies).
        if ($method === 'OPTIONS') {
            if (in_array($path, ['oauth/token', 'oauth/userinfo', 'oauth/revoke'], true)) {
                OAuth::cors();
                http_response_code(204);
                exit;
            }
            Response::json(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
        }

        // Endpoint de rafraîchissement du jeton CSRF. Il est volontairement
        // en GET et sans cache : il permet de récupérer un nouveau jeton lorsque
        // la page a été ouverte avant un renouvellement de session.
        if ($path === 'security/csrf' && $method === 'GET') {
            header('Cache-Control: no-store, no-cache, must-revalidate');
            Response::json(['ok' => true, 'csrf' => Security::csrfToken()]);
        }

        // Corps de requête : multipart, JSON (fetch) ou formulaire classique
        // (les clients OAuth envoient application/x-www-form-urlencoded).
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if (str_starts_with($contentType, 'multipart/form-data')) {
            $input = $_POST ?: [];
        } else {
            $rawInput = file_get_contents('php://input') ?: '';
            $decoded  = $rawInput !== '' ? json_decode($rawInput, true) : null;
            $input    = is_array($decoded) ? $decoded : ($_POST ?: []);
        }

        // Protection CSRF : toute requête qui modifie des données doit porter l'en-tête
        // X-Requested-With, qu'un site tiers ne peut pas ajouter sans autorisation CORS.
        // (Les endpoints OAuth token/revoke s'authentifient par secret client, sans cookie.)
        $csrfExempt = in_array($path, ['oauth/token', 'oauth/revoke', 'oauth/userinfo'], true) || str_starts_with($path, 'gateway/');
        if (!$csrfExempt && !in_array($method, ['GET', 'HEAD'], true)) {
            $csrf = (string)($_SERVER['HTTP_X_KOVA_CSRF'] ?? '');
            if (!Security::verifyCsrf($csrf)) {
                Response::json(['ok' => false, 'error' => 'Requête refusée : session de sécurité renouvelée. Actualisez la page et réessayez.', 'code' => 'csrf_invalid'], 403);
            }
            // Les mutations venant d'un navigateur doivent aussi être
            // originées depuis KOVA. Les clients API authentifiés par leur
            // propre protocole (OAuth) restent exemptés ci-dessus.
            if (!Security::sameOriginRequest() && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
                Response::json(['ok' => false, 'error' => 'Origine de requête non autorisée.'], 403);
            }
            $writeLimit = str_starts_with($path, 'profile/avatar') || str_starts_with($path, 'profile/cover') || str_starts_with($path, 'store/products')
                ? 30 : 120;
            if (!Security::rateLimit($this->store, 'api-write', $writeLimit, 60)) {
                Response::json(['ok'=>false,'error'=>'Trop de requêtes. Réessayez dans un instant.'],429);
            }
        }
        $mailer     = new Mailer($this->env);
        $cloudinary = new Cloudinary($this->env);

        // ── Auth : inscription (un code à 6 chiffres est envoyé par e-mail) ──
        if ($path === 'auth/register' && $method === 'POST') {
            if (!Security::rateLimit($this->store, 'register', 8, 3600)) Response::json(['ok'=>false,'error'=>'Trop de tentatives. Réessayez plus tard.'],429);
            if (($input['privacy_consent'] ?? false) !== true || ($input['terms_consent'] ?? false) !== true) {
                Response::json(['ok'=>false,'error'=>'Vous devez accepter les conditions et la politique de confidentialité.'],422);
            }
            try {
                $user = $this->auth->register(
                    (string)($input['email'] ?? ''),
                    (string)($input['password'] ?? ''),
                    (string)($input['date_of_birth'] ?? ''),
                    (string)($input['display_name'] ?? '')
                );
                // Réponse IDENTIQUE que l'adresse soit nouvelle ou déjà utilisée (anti-énumération).
                $neutral = ['ok' => true, 'email' => $user['email'], 'mail_sent' => true,
                    'message' => 'Si l’adresse est valide, un code à 6 chiffres vient d’être envoyé à ' . $user['email'] . '.'];
                if (!empty($user['duplicate'])) {
                    $mailer->sendSecurityNotice($user['email'], 'Tentative d’inscription', 'Quelqu’un a essayé de créer un compte avec votre adresse',
                        'Un compte existe déjà pour cette adresse : rien n’a été modifié. Si c’était vous, connectez-vous ou utilisez « Mot de passe oublié ».');
                    Security::audit($this->store, 'register_duplicate', null);
                    Response::json($neutral, 201);
                }
                $this->store->insert('consent_records', [
                    'id' => 'con_' . bin2hex(random_bytes(8)),
                    'user_id' => $user['id'],
                    'terms_version' => '1.0',
                    'privacy_version' => '1.1',
                    'accepted_at' => date('c'),
                    'source' => 'registration',
                ]);
                Security::audit($this->store, 'account_created', (string)$user['id']);
                $sent = $mailer->sendVerification($user['email'], (string)$user['plain_code']);
                if (!$sent) $neutral['message'] = 'Compte créé, mais l’e-mail n’a pas pu être envoyé. Utilisez « Renvoyer le code » ou contactez l’administrateur.';
                $neutral['mail_sent'] = $sent;
                Response::json($neutral, 201);
            } catch (\RuntimeException $e) {            // erreurs de validation voulues (messages destinés à l'utilisateur)
                Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
            } catch (\Throwable $e) {                   // erreur interne : jamais de détail technique au client
                error_log('[KOVA register] ' . $e->getMessage());
                Response::json(['ok' => false, 'error' => 'Une erreur est survenue. Réessayez dans un instant.'], 500);
            }
        }

        // ── Auth : connexion (limitation progressive + preuve de travail + 2FA) ──
        if ($path === 'auth/login' && $method === 'POST') {
            $loginEmail = strtolower(trim((string)($input['email'] ?? '')));
            $guard = new Guard($this->store, $this->env);
            // Plafond global par IP, tous comptes confondus (filet de sécurité).
            if (!Security::rateLimit($this->store, 'login-ip', 40, 300)) {
                Response::json(['ok'=>false,'error'=>'Trop de tentatives. Patientez quelques minutes.','code'=>'rate_limited','retry_after'=>300],429);
            }
            $state = $guard->status($loginEmail);
            if ($state['locked'] > 0) {
                header('Retry-After: ' . $state['locked']);
                Response::json(['ok'=>false,'code'=>'locked','retry_after'=>$state['locked'],
                    'error'=>'Trop de tentatives. Réessayez dans ' . max(1, (int)ceil($state['locked'] / 60)) . ' min.'], 429);
            }
            if ($state['pow_bits'] > 0 && !$guard->verifyProof((string)($input['pow_token'] ?? ''), (string)($input['pow_nonce'] ?? ''), $state['pow_bits'])) {
                $ch = $guard->issueChallenge($state['pow_bits']);
                Response::json(['ok'=>false,'code'=>'pow_required','challenge'=>$ch['challenge'],'bits'=>$ch['bits'],
                    'error'=>'Vérification de sécurité en cours…'], 428);
            }
            $res = $this->auth->checkCredentials($loginEmail, (string)($input['password'] ?? ''));
            $next = $this->safeNext((string)($input['next'] ?? ''));
            if ($res['status'] === 'invalid') {
                $guard->fail($loginEmail);
                Security::audit($this->store, 'login_failed', null, ['e' => Security::fingerprint('audit-email', $loginEmail)]);
                usleep(random_int(150000, 350000));        // freine l'automatisation sans gêner un humain
                Response::json(['ok' => false, 'error' => 'E-mail ou mot de passe incorrect.'], 401);
            }
            if ($res['status'] === 'unverified') {
                Response::json(['ok' => false, 'unverified' => true, 'email' => $loginEmail,
                    'error' => 'Adresse e-mail non vérifiée. Saisissez le code reçu par e-mail.'], 403);
            }
            if ($res['status'] === 'suspended') {
                Response::json(['ok' => false, 'error' => 'Ce compte est suspendu. Contactez l’assistance.'], 403);
            }
            $guard->success($loginEmail);
            if ($res['status'] === 'twofa') {
                $this->auth->startTwoFactor($res['user'], $next);
                Response::json(['ok' => true, 'twofa_required' => true, 'csrf' => Security::rotateCsrf(),
                    'message' => 'Saisissez le code de votre application d’authentification (ou un code de secours).'], 202);
            }
            $this->auth->completeLogin($res['user']);
            Security::audit($this->store, 'login_success', (string)$res['user']['id']);
            Response::json(['ok' => true, 'csrf' => Security::csrfToken(), 'redirect' => $next !== '' ? $next : '/app']);
        }

        // ── Auth : seconde étape (code 2FA) ──
        if ($path === 'auth/2fa' && $method === 'POST') {
            if (!Security::rateLimit($this->store, 'twofa-verify', 30, 900)) Response::json(['ok'=>false,'error'=>'Trop de tentatives. Réessayez plus tard.'],429);
            $r = $this->auth->completeTwoFactor((string)($input['code'] ?? ''));
            if (!$r['ok']) { usleep(random_int(150000, 350000)); Response::json(['ok' => false, 'error' => $r['error']], 401); }
            Security::audit($this->store, 'login_success_2fa', (string)($this->auth->user()['id'] ?? ''));
            Response::json(['ok' => true, 'csrf' => Security::csrfToken(), 'redirect' => $r['next'] !== '' ? $r['next'] : '/app']);
        }

        // ── Auth : validation du code à 6 chiffres (connecte l'utilisateur) ──
        if ($path === 'auth/verify-code' && $method === 'POST') {
            if (!Security::rateLimit($this->store, 'verify-code', 30, 900)) Response::json(['ok'=>false,'error'=>'Trop de tentatives. Réessayez plus tard.'],429);
            $r = $this->auth->verifyCode((string)($input['email'] ?? ''), (string)($input['code'] ?? ''));
            if (!$r['ok']) { usleep(random_int(100000, 250000)); Response::json(['ok' => false, 'error' => $r['error']], 422); }
            // Le code vient d'être prouvé pour CE compte (jamais pour un compte déjà vérifié : voir Auth::verifyCode).
            $this->auth->loginById((string)$r['user']['id']);
            Security::audit($this->store, 'email_verified', (string)$r['user']['id']);
            $next = $this->safeNext((string)($input['next'] ?? ''));
            Response::json(['ok' => true, 'csrf' => Security::csrfToken(), 'message' => 'Adresse e-mail vérifiée.', 'redirect' => $next !== '' ? $next : '/app']);
        }

        // ── Auth : renvoyer un code ──
        if ($path === 'auth/resend-code' && $method === 'POST') {
            if (!Security::rateLimit($this->store, 'resend-code', 10, 3600)) Response::json(['ok'=>false,'error'=>'Trop de demandes. Réessayez plus tard.'],429);
            $email = strtolower(trim((string)($input['email'] ?? '')));
            $r = $this->auth->resendCode($email);
            if (!$r['ok']) {
                Response::json(['ok' => false, 'wait' => $r['wait'], 'error' => 'Patientez ' . $r['wait'] . ' s avant de demander un nouveau code.'], 429);
            }
            if ($r['code'] !== null) $mailer->sendVerification($email, $r['code']);
            // Réponse identique que le compte existe ou non (anti-énumération).
            Response::json(['ok' => true, 'wait' => 60, 'message' => 'Si un compte non vérifié existe pour cette adresse, un nouveau code vient d’être envoyé.']);
        }

        // ── Auth : déconnexion ──
        if ($path === 'auth/logout' && $method === 'POST') {
            $this->auth->logout();
            Response::json(['ok' => true]);
        }

        // ── Auth : utilisateur courant ──
        if ($path === 'auth/me' && $method === 'GET') {
            $u = $this->auth->user();
            if (!$u) Response::json(['ok' => false, 'error' => 'Non authentifié.'], 401);
            Response::json(['ok' => true, 'user' => Auth::sanitizeUser($u)]);
        }

        // ── Auth : mot de passe oublié ──
        if ($path === 'auth/forgot-password' && $method === 'POST') {
            if (!Security::rateLimit($this->store, 'forgot-password', 5, 3600)) Response::json(['ok'=>false,'error'=>'Trop de demandes. Réessayez plus tard.'],429);
            $email  = strtolower(trim((string)($input['email'] ?? '')));
            $record = $this->auth->createPasswordReset($email);
            if ($record) $mailer->sendPasswordReset($email, (string)($record['plain_token'] ?? ''));
            // Toujours répondre OK (anti-énumération)
            Response::json(['ok' => true, 'message' => 'Si cet e-mail existe, un lien de réinitialisation a été envoyé.']);
        }

        // ── Auth : réinitialiser mot de passe ──
        if ($path === 'auth/reset-password' && $method === 'POST') {
            if (!Security::rateLimit($this->store, 'reset-password', 10, 3600)) Response::json(['ok'=>false,'error'=>'Trop de tentatives. Réessayez plus tard.'],429);
            try {
                $ok = $this->auth->resetPassword(
                    (string)($input['token'] ?? ''),
                    (string)($input['password'] ?? '')
                );
                if (!$ok) Response::json(['ok' => false, 'error' => 'Lien invalide ou expiré.'], 422);
                Security::audit($this->store, 'password_reset', null);
                Response::json(['ok' => true, 'message' => 'Mot de passe mis à jour. Vous pouvez vous connecter.']);
            } catch (\Throwable $e) {
                Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
        }

        // ── Profil : écriture = UNIQUEMENT son propre profil ──
        // L'identité vient toujours de la session ; tout identifiant envoyé par le client
        // qui ne correspond pas à l'utilisateur connecté est refusé.
        if (str_starts_with($path, 'profile/') && $method === 'POST') {
            $me = $this->auth->user();
            if (!$me) Response::json(['ok' => false, 'error' => 'Non authentifié.'], 401);
            $claimed = (string)($input['user_id'] ?? $_POST['user_id'] ?? '');
            if ($claimed !== '' && $claimed !== $me['id']) {
                Response::json(['ok' => false, 'error' => 'Vous ne pouvez modifier que votre propre profil.'], 403);
            }
        }

        if ($path === 'profile/update' && $method === 'POST') {
            $u = $this->auth->user();
            $displayName = mb_substr(trim((string)($input['display_name'] ?? '')), 0, 60);
            $bio         = mb_substr(trim((string)($input['bio'] ?? '')), 0, 300);
            if (mb_strlen($displayName) < 2) Response::json(['ok' => false, 'error' => 'Le nom affiché doit contenir au moins 2 caractères.'], 422);
            $this->store->updateWhere('users',
                fn($r) => $r['id'] === $u['id'],
                fn($r) => array_merge($r, ['display_name' => $displayName, 'bio' => $bio, 'updated_at' => date('c')])
            );
            Response::json(['ok' => true, 'message' => 'Profil mis à jour.']);
        }

        if (($path === 'profile/avatar' || $path === 'profile/cover') && $method === 'POST') {
            $u = $this->auth->user();
            $isAvatar = $path === 'profile/avatar';
            $file = $_FILES['image'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) Response::json(['ok' => false, 'error' => 'Sélectionnez une image.'], 422);
            $images = new ImageStore($this->env, $cloudinary);
            $saved = $images->save((string)$file['tmp_name'], $isAvatar ? 'avatars' : 'covers', $isAvatar ? 5 * 1048576 : 8 * 1048576, false);
            if ($saved['url'] === null) Response::json(['ok' => false, 'error' => $saved['error']], (int)$saved['status']);
            $url = $saved['url'];
            $field = $isAvatar ? 'avatar_url' : 'cover_url';
            $oldUrl = (string)($u[$field] ?? '');
            $this->store->updateWhere('users', fn($r) => $r['id'] === $u['id'], fn($r) => array_merge($r, [$field => $url, 'updated_at' => date('c')]));
            if ($oldUrl !== '' && $oldUrl !== $url) $images->delete($oldUrl);
            Response::json(['ok' => true, $field => $url]);
        }

        if ($path === 'profile/change-password' && $method === 'POST') {
            $u = $this->auth->user();
            if (!Security::rateLimit($this->store, 'change-password:' . $u['id'], 8, 900)) Response::json(['ok'=>false,'error'=>'Trop de tentatives. Réessayez plus tard.'],429);
            $current = (string)($input['current_password'] ?? '');
            $newPass = (string)($input['new_password'] ?? '');
            if (!Passwords::verify($current, (string)($u['password_hash'] ?? ''))) {
                Response::json(['ok' => false, 'error' => 'Mot de passe actuel incorrect.'], 422);
            }
            if (($err = Passwords::validate($newPass, (string)$u['email'], (string)($u['display_name'] ?? ''))) !== null) {
                Response::json(['ok' => false, 'error' => $err], 422);
            }
            if (hash_equals($current, $newPass)) Response::json(['ok' => false, 'error' => 'Le nouveau mot de passe doit être différent de l’ancien.'], 422);
            $newVersion = (int)($u['session_version'] ?? 1) + 1;
            $this->store->updateWhere('users',
                fn($r) => ($r['id'] ?? '') === $u['id'],
                fn($r) => array_merge($r, ['password_hash' => Passwords::hash($newPass), 'session_version' => $newVersion, 'updated_at' => date('c')])
            );
            // Les AUTRES appareils sont déconnectés ; celui-ci reste connecté avec un nouvel identifiant de session.
            $this->auth->refreshSession($u, $newVersion);
            Security::audit($this->store, 'password_changed', (string)$u['id']);
            $mailer->sendSecurityNotice((string)$u['email'], 'Mot de passe modifié', 'Votre mot de passe a été modifié', 'Le mot de passe de votre compte vient d’être changé. Vos autres appareils ont été déconnectés.');
            Response::json(['ok' => true, 'csrf' => Security::csrfToken(), 'message' => 'Mot de passe mis à jour. Vos autres appareils ont été déconnectés.']);
        }

        // ── Posts : liste (fil général ou « abonnements ») ──
        if ($path === 'posts' && $method === 'GET') {
            $me = $this->auth->user();
            if (!$me) Response::json(['ok' => false, 'error' => 'Non authentifié.'], 401);
            $meId = (string)$me['id'];
            $filter = (string)($_GET['filter'] ?? '');
            $blocked = [];
            foreach ($this->store->all('blocks') as $bl) {
                if (($bl['blocker_id'] ?? '') === $meId) $blocked[$bl['blocked_id'] ?? ''] = true;
                if (($bl['blocked_id'] ?? '') === $meId) $blocked[$bl['blocker_id'] ?? ''] = true;
            }
            $following = [];
            if ($filter === 'following') {
                foreach ($this->store->all('followers') as $f) {
                    if (($f['follower_id'] ?? '') === $meId) $following[$f['followed_id'] ?? ''] = true;
                }
                $following[$meId] = true;
            }
            $posts = array_filter($this->store->all('posts'), function ($p) use ($blocked, $filter, $following) {
                if (!empty($p['deleted']) || !empty($p['group_id']) || !empty($p['community_id'])) return false;
                if (isset($blocked[$p['user_id'] ?? ''])) return false;
                if ($filter === 'following' && !isset($following[$p['user_id'] ?? ''])) return false;
                return true;
            });
            usort($posts, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
            $posts = array_slice($posts, 0, 100);
            $users = [];
            foreach ($this->store->all('users') as $usr) { $users[$usr['id']] = $usr; }
            $posts = array_map(function ($p) use ($users, $meId) {
                $author = $users[$p['user_id']] ?? null;
                $p['author_name']   = $author ? ($author['display_name'] ?: 'Utilisateur') : 'Utilisateur';
                $p['author_avatar'] = $author['avatar_url'] ?? '';
                $reactions = is_array($p['reactions'] ?? null) ? $p['reactions'] : [];
                $p['like_count']  = count(array_filter($reactions, fn($r) => ($r['type'] ?? 'like') === 'like'));
                $p['liked_by_me'] = isset($reactions[$meId . '_like']);
                unset($p['reactions']);
                return $p;
            }, array_values($posts));
            Response::json(['ok' => true, 'posts' => $posts]);
        }

        // ── Posts : créer ──
        if ($path === 'posts' && $method === 'POST') {
            $u = $this->auth->user();
            if (!$u) Response::json(['ok' => false, 'error' => 'Authentification requise.'], 401);
            $text = trim((string)($input['content'] ?? ''));
            if ($text === '') Response::json(['ok' => false, 'error' => 'Le contenu est obligatoire.'], 422);

            $imageUrl = '';
            if (!empty($_FILES['image']['tmp_name']) && ($_FILES['image']['error'] ?? 0) === UPLOAD_ERR_OK) {
                $saved = (new ImageStore($this->env, $cloudinary))->save((string)$_FILES['image']['tmp_name'], 'posts', 8 * 1048576, true);
                if ($saved['url'] === null) Response::json(['ok' => false, 'error' => $saved['error']], (int)$saved['status']);
                $imageUrl = $saved['url'];
            }
            $disclosure = in_array(($input['ai_disclosure'] ?? 'self'), ['self', 'assisted', 'generated', 'unknown'], true) ? (string)$input['ai_disclosure'] : 'self';

            $post = [
                'id'             => 'post_' . bin2hex(random_bytes(10)),
                'user_id'        => $u['id'],
                'content'        => mb_substr($text, 0, 5000),
                'image_url'      => $imageUrl,
                'ai_disclosure'  => $disclosure,
                'reactions'      => [],
                'comments_count' => 0,
                'created_at'     => date('c'),
                'edited_at'      => null,
                'deleted'        => false,
            ];
            $this->store->insert('posts', $post);
            $post['author_name']   = $u['display_name'] ?: 'Utilisateur';
            $post['author_avatar'] = $u['avatar_url'] ?? '';
            Response::json(['ok' => true, 'post' => $post], 201);
        }

        // ── Posts : modifier (auteur uniquement) ──
        if (preg_match('/^posts\/([a-zA-Z0-9_]+)$/', $path, $m) && in_array($method, ['PUT', 'PATCH'], true)) {
            $u = $this->auth->user();
            if (!$u) Response::json(['ok' => false, 'error' => 'Non authentifié.'], 401);
            $text = trim((string)($input['content'] ?? ''));
            if ($text === '') Response::json(['ok' => false, 'error' => 'Le contenu est obligatoire.'], 422);
            $postId = $m[1];
            $count  = $this->store->updateWhere('posts',
                fn($p) => $p['id'] === $postId && $p['user_id'] === $u['id'] && empty($p['deleted']),
                fn($p) => array_merge($p, ['content' => mb_substr($text, 0, 5000), 'edited_at' => date('c')])
            );
            if (!$count) Response::json(['ok' => false, 'error' => 'Publication introuvable.'], 404);
            Response::json(['ok' => true]);
        }

        // ── Commentaires : liste ──
        if (preg_match('#^posts/([a-zA-Z0-9_]+)/comments$#', $path, $m) && $method === 'GET') {
            $me = $this->auth->user();
            if (!$me) Response::json(['ok' => false, 'error' => 'Non authentifié.'], 401);
            $postId = $m[1];
            $post   = $this->findPost($postId);
            if (!$post || !$this->canSeePost($post, (string)$me['id'])) Response::json(['ok' => false, 'error' => 'Publication introuvable ou réservée aux membres.'], 403);
            $comments = array_values(array_filter(
                $this->store->all('comments'),
                fn($c) => ($c['post_id'] ?? '') === $postId && empty($c['deleted'])
            ));
            usort($comments, fn($a, $b) => strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? '')));
            $users = [];
            foreach ($this->store->all('users') as $usr) { $users[$usr['id']] = $usr; }
            $meId = (string)$me['id'];
            $comments = array_map(function ($c) use ($users, $meId) {
                $author = $users[$c['user_id']] ?? null;
                $c['author_name'] = $author ? ($author['display_name'] ?: 'Utilisateur') : 'Utilisateur';
                $c['author_avatar'] = $author['avatar_url'] ?? '';
                $reactions = is_array($c['reactions'] ?? null) ? $c['reactions'] : [];
                $c['like_count'] = count(array_filter($reactions, fn($r) => ($r['type'] ?? 'like') === 'like'));
                $c['liked_by_me'] = isset($reactions[$meId . '_like']);
                $c['is_mine'] = ($c['user_id'] ?? '') === $meId;
                unset($c['reactions']);
                return $c;
            }, $comments);
            Response::json(['ok' => true, 'comments' => $comments]);
        }

        // ── Commentaires : créer ──
        if (preg_match('#^posts/([a-zA-Z0-9_]+)/comments$#', $path, $m) && $method === 'POST') {
            $u = $this->auth->user();
            if (!$u) Response::json(['ok' => false, 'error' => 'Non authentifié.'], 401);
            $postId = $m[1];
            $text   = trim((string)($input['content'] ?? ''));
            if ($text === '') Response::json(['ok' => false, 'error' => 'Le commentaire ne peut pas être vide.'], 422);
            $post = $this->findPost($postId);
            if (!$post) Response::json(['ok' => false, 'error' => 'Publication introuvable.'], 404);
            if (!$this->canSeePost($post, (string)$u['id'])) Response::json(['ok' => false, 'error' => 'Vous devez être membre de ce groupe pour commenter.'], 403);
            $parentId = trim((string)($input['parent_id'] ?? '')) ?: null;
            $parent = null;
            if ($parentId) {
                foreach ($this->store->all('comments') as $pc) {
                    if (($pc['id'] ?? '') === $parentId && ($pc['post_id'] ?? '') === $postId && empty($pc['deleted'])) { $parent = $pc; break; }
                }
                if (!$parent) $parentId = null;
            }
            $comment = [
                'id'         => 'cmt_' . bin2hex(random_bytes(8)),
                'post_id'    => $postId,
                'user_id'    => $u['id'],
                'content'    => mb_substr($text, 0, 1000),
                'parent_id'  => $parentId,
                'reactions'  => [],
                'created_at' => date('c'),
                'deleted'    => false,
            ];
            $this->store->insert('comments', $comment);
            $this->store->updateWhere('posts',
                fn($p) => $p['id'] === $postId,
                fn($p) => array_merge($p, ['comments_count' => (int)($p['comments_count'] ?? 0) + 1])
            );
            $who  = $u['display_name'] ?: 'Quelqu’un';
            $link = !empty($post['group_id']) ? '/groupes?group=' . $post['group_id'] : (!empty($post['community_id']) ? '/communautes?community=' . $post['community_id'] : '/app#post-' . $postId);
            if (($post['user_id'] ?? '') !== $u['id']) {
                $this->addNotification((string)$post['user_id'], 'comment', $who . ' a commenté votre publication.', ['link' => $link, 'actor_id' => $u['id']]);
            }
            if ($parent && ($parent['user_id'] ?? '') !== $u['id'] && ($parent['user_id'] ?? '') !== ($post['user_id'] ?? '')) {
                $this->addNotification((string)$parent['user_id'], 'reply', $who . ' a répondu à votre commentaire.', ['link' => $link, 'actor_id' => $u['id']]);
            }
            $comment['author_name'] = $u['display_name'] ?: 'Utilisateur';
            $comment['author_avatar'] = $u['avatar_url'] ?? '';
            $comment['like_count'] = 0;
            $comment['liked_by_me'] = false;
            $comment['is_mine'] = true;
            unset($comment['reactions']);
            Response::json(['ok' => true, 'comment' => $comment], 201);
        }

        // ── Commentaires : réaction ──
        if ($path === 'comments/react' && $method === 'POST') {
            $u = $this->auth->user();
            if (!$u) Response::json(['ok' => false, 'error' => 'Non authentifié.'], 401);
            $commentId = (string)($input['comment_id'] ?? '');
            $target = null;
            foreach ($this->store->all('comments') as $cc) if (($cc['id'] ?? '') === $commentId && empty($cc['deleted'])) { $target = $cc; break; }
            if (!$target) Response::json(['ok' => false, 'error' => 'Commentaire introuvable.'], 404);
            $parentPost = $this->findPost((string)($target['post_id'] ?? ''));
            if (!$parentPost || !$this->canSeePost($parentPost, (string)$u['id'])) Response::json(['ok' => false, 'error' => 'Accès réservé aux membres du groupe.'], 403);
            $updatedComment = null;
            $this->store->updateWhere('comments', fn($c) => ($c['id'] ?? '') === $commentId && empty($c['deleted']), function($c) use ($u, &$updatedComment) {
                $reactions = is_array($c['reactions'] ?? null) ? $c['reactions'] : [];
                $key = $u['id'] . '_like';
                if (isset($reactions[$key])) unset($reactions[$key]);
                else $reactions[$key] = ['user_id' => $u['id'], 'type' => 'like', 'at' => date('c')];
                $c['reactions'] = $reactions; $updatedComment = $c; return $c;
            });
            $reactions = is_array($updatedComment['reactions'] ?? null) ? $updatedComment['reactions'] : [];
            Response::json(['ok' => true, 'liked_by_me' => isset($reactions[$u['id'].'_like']), 'like_count' => count(array_filter($reactions, fn($r) => ($r['type'] ?? '') === 'like'))]);
        }

        // ── Commentaires : supprimer (auteur, auteur de la publication, ou modérateur de l'espace) ──
        if (preg_match('#^comments/([a-zA-Z0-9_]+)$#', $path, $m) && $method === 'DELETE') {
            $u = $this->auth->user();
            if (!$u) Response::json(['ok' => false, 'error' => 'Non authentifié.'], 401);
            $cid = $m[1];
            $target = null;
            foreach ($this->store->all('comments') as $cc) if (($cc['id'] ?? '') === $cid && empty($cc['deleted'])) { $target = $cc; break; }
            if (!$target) Response::json(['ok' => false, 'error' => 'Commentaire introuvable.'], 404);
            $parentPost = $this->findPost((string)($target['post_id'] ?? ''));
            $allowed = ($target['user_id'] ?? '') === $u['id']
                || ($parentPost && ($parentPost['user_id'] ?? '') === $u['id'])
                || ($parentPost && in_array($this->spaceRole($parentPost, (string)$u['id']), ['owner', 'moderator'], true));
            if (!$allowed) Response::json(['ok' => false, 'error' => 'Action non autorisée.'], 403);
            $this->store->updateWhere('comments', fn($c) => ($c['id'] ?? '') === $cid, fn($c) => array_merge($c, ['deleted' => true]));
            if ($parentPost) {
                $this->store->updateWhere('posts', fn($p) => ($p['id'] ?? '') === $parentPost['id'],
                    fn($p) => array_merge($p, ['comments_count' => max(0, (int)($p['comments_count'] ?? 0) - 1)]));
            }
            Response::json(['ok' => true]);
        }

        // ── Posts : supprimer (auteur ; ou propriétaire/modérateur de l'espace pour ses publications) ──
        if (preg_match('/^posts\/([a-zA-Z0-9_]+)$/', $path, $m) && $method === 'DELETE') {
            $u = $this->auth->user();
            if (!$u) Response::json(['ok' => false, 'error' => 'Non authentifié.'], 401);
            $postId = $m[1];
            $post = $this->findPost($postId);
            if (!$post) Response::json(['ok' => false, 'error' => 'Publication introuvable.'], 404);
            $allowed = ($post['user_id'] ?? '') === $u['id']
                || in_array($this->spaceRole($post, (string)$u['id']), ['owner', 'moderator'], true);
            if (!$allowed) Response::json(['ok' => false, 'error' => 'Action non autorisée.'], 403);
            $this->store->updateWhere('posts', fn($p) => ($p['id'] ?? '') === $postId,
                fn($p) => array_merge($p, ['deleted' => true, 'deleted_by' => $u['id']]));
            Response::json(['ok' => true]);
        }

        // ── Réactions ──
        if ($path === 'posts/react' && $method === 'POST') {
            $u = $this->auth->user();
            if (!$u) Response::json(['ok' => false, 'error' => 'Non authentifié.'], 401);
            $postId = (string)($input['post_id'] ?? '');
            $type   = 'like';
            $post   = $this->findPost($postId);
            if (!$post) Response::json(['ok' => false, 'error' => 'Publication introuvable.'], 404);
            if (!$this->canSeePost($post, (string)$u['id'])) Response::json(['ok' => false, 'error' => 'Accès réservé aux membres du groupe.'], 403);
            $updatedPost = null;
            $added = false;
            $this->store->updateWhere('posts',
                fn($p) => $p['id'] === $postId && empty($p['deleted']),
                function ($p) use ($u, $type, &$updatedPost, &$added) {
                    $reactions = is_array($p['reactions'] ?? null) ? $p['reactions'] : [];
                    $key = $u['id'] . '_' . $type;
                    if (isset($reactions[$key])) unset($reactions[$key]);
                    else { $reactions[$key] = ['user_id' => $u['id'], 'type' => $type, 'at' => date('c')]; $added = true; }
                    $p['reactions'] = $reactions;
                    $updatedPost = $p;
                    return $p;
                }
            );
            $reactions = is_array($updatedPost['reactions'] ?? null) ? $updatedPost['reactions'] : [];
            if ($added && ($post['user_id'] ?? '') !== $u['id']) {
                $link = !empty($post['group_id']) ? '/groupes?group=' . $post['group_id'] : (!empty($post['community_id']) ? '/communautes?community=' . $post['community_id'] : '/app#post-' . $postId);
                $this->addNotification((string)$post['user_id'], 'like', ($u['display_name'] ?: 'Quelqu’un') . ' a aimé votre publication.',
                    ['link' => $link, 'actor_id' => $u['id'], 'dedupe' => 'like:' . $postId . ':' . $u['id']]);
            }
            Response::json(['ok' => true, 'liked_by_me' => isset($reactions[$u['id'] . '_' . $type]), 'like_count' => count(array_filter($reactions, fn($r) => ($r['type'] ?? '') === 'like'))]);
        }

        // ── Notifications : nombre non lues ──
        if ($path === 'notifications/unread-count' && $method === 'GET') {
            $u = $this->auth->user();
            $count = 0;
            if ($u) {
                foreach ($this->store->all('notifications') as $n) {
                    if (($n['user_id'] ?? '') === $u['id'] && empty($n['read'])) $count++;
                }
            }
            Response::json(['ok' => true, 'count' => $count]);
        }

        // ── Notifications : liste ──
        if ($path === 'notifications' && $method === 'GET') {
            $u = $this->auth->user();
            if (!$u) Response::json(['ok' => false, 'error' => 'Non authentifié.'], 401);
            $notifs = array_filter(
                $this->store->all('notifications'),
                fn($n) => ($n['user_id'] ?? '') === $u['id']
            );
            usort($notifs, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
            Response::json(['ok' => true, 'notifications' => array_slice(array_values($notifs), 0, 100)]);
        }

        // ── Notifications : tout marquer lu ──
        if ($path === 'notifications/read-all' && $method === 'POST') {
            $u = $this->auth->user();
            if (!$u) Response::json(['ok' => false, 'error' => 'Non authentifié.'], 401);
            $this->store->updateWhere('notifications',
                fn($n) => ($n['user_id'] ?? '') === $u['id'] && empty($n['read']),
                fn($n) => array_merge($n, ['read' => true, 'read_at' => date('c')])
            );
            Response::json(['ok' => true]);
        }

        // ── Support : créer ticket ──
        if ($path === 'support/tickets' && $method === 'POST') {
            $u = $this->auth->user();
            if (!$u) Response::json(['ok' => false, 'error' => 'Authentification requise.'], 401);
            $ticket = [
                'id'         => 'req_' . bin2hex(random_bytes(8)),
                'user_id'    => $u['id'],
                'category'   => in_array(($input['category'] ?? 'technical'), ['technical','account','security','report','suggestion','store','privacy'], true) ? (string)$input['category'] : 'technical',
                'subject'    => mb_substr(trim((string)($input['subject'] ?? '')), 0, 180),
                'message'    => mb_substr(trim((string)($input['message'] ?? '')), 0, 5000),
                'status'     => 'new',
                'priority'   => 'normal',
                'created_at' => date('c'),
                'updated_at' => date('c'),
            ];
            if ($ticket['subject'] === '' || $ticket['message'] === '') {
                Response::json(['ok' => false, 'error' => 'Sujet et message obligatoires.'], 422);
            }
            $this->store->insert('support_tickets', $ticket);
            $adminEmail = trim((string)$this->env->get('ADMIN_EMAIL', $this->env->get('MAIL_FROM_ADDRESS', '')));
            if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                $mailer->sendSupportAlert($adminEmail, $u, $ticket);
            }
            $this->addNotification($u['id'], 'support_created', 'Votre demande a bien été reçue par KOVA.', ['ticket_id' => $ticket['id']]);
            Response::json(['ok' => true, 'ticket' => $ticket, 'message' => 'Votre demande a été enregistrée. L’administration ne répond pas directement dans cette conversation.'], 201);
        }

        // ── Support : liste tickets de l'utilisateur ──
        if ($path === 'support/tickets' && $method === 'GET') {
            $u = $this->auth->user();
            if (!$u) Response::json(['ok' => false, 'error' => 'Non authentifié.'], 401);
            $tickets = array_filter(
                $this->store->all('support_tickets'),
                fn($t) => ($t['user_id'] ?? '') === $u['id']
            );
            usort($tickets, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
            Response::json(['ok' => true, 'tickets' => array_values($tickets)]);
        }

        // ── Support : détail d'un ticket ──
        if ($path === 'support/tickets/detail' && $method === 'GET') {
            $u = $this->auth->user();
            if (!$u) Response::json(['ok' => false, 'error' => 'Authentification requise.'], 401);
            $id = (string)($_GET['id'] ?? '');
            foreach ($this->store->all('support_tickets') as $t) {
                if (($t['id'] ?? '') === $id && ($t['user_id'] ?? '') === $u['id']) {
                    Response::json(['ok' => true, 'ticket' => $t]);
                }
            }
            Response::json(['ok' => false, 'error' => 'Demande introuvable.'], 404);
        }

        // ── Signalement : créer ──
        if ($path === 'reports' && $method === 'POST') {
            $u = $this->auth->user();
            if (!$u) Response::json(['ok'=>false,'error'=>'Authentification requise.'],401);
            $type = trim((string)($input['target_type'] ?? 'post'));
            $target = trim((string)($input['target_id'] ?? ''));
            $reason = trim((string)($input['reason'] ?? ''));
            $details = mb_substr(trim((string)($input['details'] ?? '')),0,2000);
            if (!in_array($type,['post','comment','profile','message','group','community'],true) || $target==='' || $reason==='') Response::json(['ok'=>false,'error'=>'Type, contenu et motif obligatoires.'],422);
            $report=['id'=>'rpt_'.bin2hex(random_bytes(8)),'reporter_id'=>$u['id'],'target_type'=>$type,'target_id'=>$target,'reason'=>mb_substr($reason,0,120),'details'=>$details,'status'=>'new','created_at'=>date('c'),'updated_at'=>date('c')];
            $this->store->insert('reports',$report);
            $this->addNotification($u['id'],'report_created','Votre signalement a été transmis à l’administration.',['report_id'=>$report['id']]);
            $adminEmail=trim((string)$this->env->get('ADMIN_EMAIL',$this->env->get('MAIL_FROM_ADDRESS','')));
            if($adminEmail!=='' && filter_var($adminEmail,FILTER_VALIDATE_EMAIL)) $mailer->sendReportAlert($adminEmail,$u,$report);
            Response::json(['ok'=>true,'report'=>$report,'message'=>'Signalement transmis.'],201);
        }

        // ── Administration : tableau de bord ──
        if ($path === 'admin/gateway' && $method === 'GET') {
            $this->requireAdmin();
            $settings = $this->gatewaySettings();
            unset($settings['token_hash']);
            Response::json(['ok'=>true,'gateway'=>$settings]);
        }
        if ($path === 'admin/gateway/token' && $method === 'POST') {
            $admin = $this->requireAdmin();
            $token = 'kova_ai_' . bin2hex(random_bytes(24));
            $settings = array_merge($this->gatewaySettings(), ['id'=>'default','enabled'=>true,'token_hash'=>hash('sha256',$token),'token_prefix'=>substr($token,0,16),'updated_at'=>date('c'),'updated_by'=>$admin['id']]);
            $this->store->updateWhere('ai_gateway_settings', fn($r)=>($r['id']??'')==='default', fn($r)=>$settings);
            if (!$this->store->all('ai_gateway_settings')) $this->store->insert('ai_gateway_settings',$settings);
            $this->logActivity((string)$admin['id'],'gateway_token_rotate',[]);
            Response::json(['ok'=>true,'token'=>$token,'gateway'=>array_diff_key($settings,['token_hash'=>true])]);
        }
        if ($path === 'admin/gateway' && $method === 'POST') {
            $admin = $this->requireAdmin();
            $current = $this->gatewaySettings();
            $hours = array_values(array_filter(array_map(fn($h)=>preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',(string)$h)?$h:null,(array)($input['publish_hours']??$current['publish_hours']??['09:00']))));
            $next = array_merge($current,['enabled'=>!empty($input['enabled']),'style'=>mb_substr(trim((string)($input['style']??'')),0,4000),'posts_per_day'=>max(1,min(50,(int)($input['posts_per_day']??1))),'publish_hours'=>$hours?:['09:00'],'updated_at'=>date('c'),'updated_by'=>$admin['id']]);
            $count=$this->store->updateWhere('ai_gateway_settings',fn($r)=>($r['id']??'')==='default',fn($r)=>$next);
            if(!$count) $this->store->insert('ai_gateway_settings',$next);
            $this->logActivity((string)$admin['id'],'gateway_settings_update',['posts_per_day'=>$next['posts_per_day']]);
            unset($next['token_hash']); Response::json(['ok'=>true,'gateway'=>$next]);
        }

        if ($path === 'admin/post-images' && $method === 'GET') {
            $this->requireAdmin(); $posts = $this->store->all('posts'); $items = [];
            foreach ($posts as $post) {
                $url = trim((string)($post['image_url'] ?? ''));
                if ($url === '' || !empty($post['image_migrated_at']) || str_starts_with($url, '/uploads/img/')) continue;
                $items[] = ['id'=>(string)($post['id'] ?? ''), 'image_url'=>$url, 'created_at'=>(string)($post['created_at'] ?? ''), 'user_id'=>(string)($post['user_id'] ?? '')];
            }
            Response::json(['ok'=>true,'total'=>count($items),'items'=>array_slice($items,0,25)]);
        }
        if ($path === 'admin/post-images' && $method === 'POST') {
            $this->requireAdmin(); $limit = max(1, min(10, (int)($input['limit'] ?? 5))); $posts = $this->store->all('posts'); $results = [];
            foreach ($posts as $post) {
                if (count($results) >= $limit) break;
                $oldUrl = trim((string)($post['image_url'] ?? '')); $id = (string)($post['id'] ?? '');
                if ($id === '' || $oldUrl === '' || !empty($post['image_migrated_at']) || str_starts_with($oldUrl, '/uploads/img/')) continue;
                $saved = (new ImageStore($this->env, $cloudinary))->saveLegacyRemote($oldUrl, 'legacy-posts', 8 * 1048576, false);
                if (!empty($saved['url'])) {
                    $newUrl = (string)$saved['url'];
                    $changed = $this->store->updateWhere('posts', fn($row) => (string)($row['id'] ?? '') === $id && (string)($row['image_url'] ?? '') === $oldUrl, function($row) use ($newUrl, $oldUrl) { $row['image_url'] = $newUrl; $row['image_storage'] = 'kova'; $row['legacy_image_url'] = $oldUrl; $row['image_migrated_at'] = date('c'); return $row; });
                    $results[] = ['id'=>$id,'ok'=>$changed > 0,'image_url'=>$newUrl,'error'=>$changed ? null : 'Publication modifiée entre-temps.'];
                } else $results[] = ['id'=>$id,'ok'=>false,'image_url'=>$oldUrl,'error'=>$saved['error'] ?? 'Échec du traitement.'];
            }
            Response::json(['ok'=>true,'processed'=>count($results),'migrated'=>count(array_filter($results, fn($r) => $r['ok'] === true)),'results'=>$results]);
        }

        // ── Passerelle IA externe : accès par jeton administrateur ──
        if ($path === 'gateway/handshake' && $method === 'GET') {
            $identity=$this->requireGatewayIdentity(); $settings=$identity['settings']; $user=$identity['user'];
            Response::json(['ok'=>true,'platform'=>$this->env->get('SEO_SITE_NAME','KOVA'),'profile'=>$user?['id'=>$user['id'],'name'=>$user['display_name']??$user['email']??'Profil KOVA']:null,'capabilities'=>['publication_style','publication_schedule','publication_submit'],'gateway'=>['posts_per_day'=>(int)($settings['posts_per_day']??1),'publish_hours'=>$settings['publish_hours']??['09:00']]]);
        }
        if ($path === 'gateway/publication-style' && $method === 'GET') {
            $settings=$this->requireGatewayToken(); Response::json(['ok'=>true,'style'=>(string)($settings['style']??''),'posts_per_day'=>(int)($settings['posts_per_day']??1),'publish_hours'=>$settings['publish_hours']??['09:00']]);
        }
        if ($path === 'gateway/publications' && $method === 'POST') {
            $identity=$this->requireGatewayIdentity(); $settings=$identity['settings']; $profile=$identity['user'];
            $content=trim((string)($input['content']??'')); if($content==='') Response::json(['ok'=>false,'error'=>'Le contenu est obligatoire.'],422);
            $imageUrl=trim((string)($input['image_url']??'')); if($imageUrl!=='' && !filter_var($imageUrl,FILTER_VALIDATE_URL)) $imageUrl='';
            $imageData=trim((string)($input['image_data']??''));
            if ($imageData !== '') {
                $encoded = str_contains($imageData, ',') ? substr($imageData, strpos($imageData, ',') + 1) : $imageData;
                $bytes = base64_decode($encoded, true);
                if ($bytes === false) Response::json(['ok'=>false,'error'=>'Données image invalides.'],422);
                $savedImage = (new ImageStore($this->env, $cloudinary))->saveBytes($bytes, 'ai-publisher', 8 * 1048576, false);
                if (empty($savedImage['url'])) Response::json(['ok'=>false,'error'=>$savedImage['error']??'Image refusée par le stockage KOVA.'], (int)($savedImage['status']??422));
                $imageUrl = (string)$savedImage['url'];
            } elseif ($imageUrl !== '') {
                $allowedImageHosts = ['kovapub-mddh5jnl.manus.space']; $configuredImageHost = trim((string)$this->env->get('AI_PUBLISHER_HOST','')); if ($configuredImageHost !== '') $allowedImageHosts[] = preg_replace('/^https?:\/\//','',$configuredImageHost);
                $savedImage = (new ImageStore($this->env, $cloudinary))->saveRemote($imageUrl, 'ai-publisher', $allowedImageHosts, 8 * 1048576, false);
                if (empty($savedImage['url'])) Response::json(['ok'=>false,'error'=>$savedImage['error']??'Image refusée par le stockage KOVA.'], (int)($savedImage['status']??422));
                $imageUrl = (string)$savedImage['url'];
            }
            $post=['id'=>'post_ai_'.bin2hex(random_bytes(8)),'user_id'=>(string)($profile['id']??$input['author_id']??'ai_gateway'),'content'=>mb_substr($content,0,5000),'image_url'=>$imageUrl,'visibility'=>in_array(($input['visibility']??'public'),['public','followers','private'],true)?$input['visibility']:'public','scheduled_at'=>trim((string)($input['scheduled_at']??'')),'source'=>$profile?'kova_ai_publisher':'ai_gateway','created_at'=>date('c'),'updated_at'=>date('c'),'deleted'=>false,'reactions'=>[],'comments_count'=>0,'shares_count'=>0];
            $this->store->insert('posts',$post); Response::json(['ok'=>true,'id'=>$post['id'],'image_url'=>$post['image_url'],'publication'=>$post],201);
        }

        // ── Administration : tableau de bord ──
        if ($path === 'admin/overview' && $method === 'GET') {
            $this->requireAdmin();
            $users = $this->store->all('users');
            $posts = $this->store->all('posts');
            $reports = $this->store->all('reports');
            $tickets = $this->store->all('support_tickets');
            Response::json(['ok' => true, 'stats' => [
                'users' => count($users),
                'posts' => count(array_filter($posts, fn($p) => empty($p['deleted']))),
                'reports_open' => count(array_filter($reports, fn($r) => !in_array(($r['status'] ?? 'new'), ['resolved','closed'], true))),
                'tickets_open' => count(array_filter($tickets, fn($t) => !in_array(($t['status'] ?? 'new'), ['resolved','closed'], true))),
            ]]);
        }

        // ── Administration : utilisateurs ──
        if ($path === 'admin/users' && $method === 'GET') {
            $this->requireAdmin();
            $users = array_map(fn($u) => Auth::sanitizeUser($u), $this->store->all('users'));
            usort($users, fn($a,$b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
            Response::json(['ok' => true, 'users' => array_values($users)]);
        }

        if ($path === 'admin/users/status' && $method === 'POST') {
            $adminUser = $this->requireAdmin();
            $id = trim((string)($input['user_id'] ?? ''));
            $status = strtolower(trim((string)($input['status'] ?? 'active')));
            if ($id === $adminUser['id']) Response::json(['ok' => false, 'error' => 'Vous ne pouvez pas modifier votre propre statut.'], 422);
            if (!in_array($status, ['active','suspended','banned'], true)) Response::json(['ok' => false, 'error' => 'Statut invalide.'], 422);
            // Un administrateur ne peut pas suspendre/bannir un autre administrateur ou un super-administrateur.
            foreach ($this->store->all('users') as $tu) {
                if (($tu['id'] ?? '') === $id && in_array(strtoupper((string)($tu['role'] ?? 'USER')), ['ADMIN', 'SUPERADMIN'], true)
                    && strtoupper((string)($adminUser['role'] ?? '')) !== 'SUPERADMIN') {
                    Response::json(['ok' => false, 'error' => 'Seul un super-administrateur peut agir sur un compte administrateur.'], 403);
                }
                if (($tu['id'] ?? '') === $id && strtoupper((string)($tu['role'] ?? '')) === 'SUPERADMIN') {
                    Response::json(['ok' => false, 'error' => 'Un super-administrateur ne peut pas être suspendu depuis l’interface.'], 403);
                }
            }
            $count = $this->store->updateWhere('users', fn($u) => ($u['id'] ?? '') === $id, fn($u) => array_merge($u, ['account_status'=>$status,'updated_at'=>date('c')]));
            if (!$count) Response::json(['ok' => false, 'error' => 'Utilisateur introuvable.'], 404);
            $this->addNotification($id, 'account_status', 'Le statut de votre compte a été mis à jour par l’administration.', ['account_status'=>$status]);
            if ($status !== 'active') { $this->auth->logoutUserSessionsNow($id); }
            $this->logActivity((string)$adminUser['id'], 'user_status', ['user_id'=>$id,'status'=>$status]);
            Security::audit($this->store, 'admin_user_status', (string)$adminUser['id'], ['target' => $id, 'status' => $status]);
            Response::json(['ok' => true, 'status' => $status]);
        }

        if ($path === 'admin/users/role' && $method === 'POST') {
            $adminUser = $this->requireAdmin();
            $id = trim((string)($input['user_id'] ?? ''));
            $role = strtoupper(trim((string)($input['role'] ?? 'USER')));
            if ($id === $adminUser['id']) Response::json(['ok' => false, 'error' => 'Vous ne pouvez pas modifier votre propre rôle.'], 422);
            if (!in_array($role, ['USER','MODERATOR','ADMIN'], true)) Response::json(['ok' => false, 'error' => 'Rôle invalide.'], 422);
            $target = null; foreach ($this->store->all('users') as $tu) if (($tu['id'] ?? '') === $id) { $target = $tu; break; }
            if (!$target) Response::json(['ok'=>false,'error'=>'Utilisateur introuvable.'],404);
            $actorRole = strtoupper((string)($adminUser['role'] ?? ''));
            if ($actorRole !== 'SUPERADMIN' && (strtoupper((string)($target['role'] ?? '')) === 'ADMIN' || $role === 'ADMIN')) Response::json(['ok' => false, 'error' => 'Seul un super-administrateur peut gérer le rôle ADMIN.'], 403);
            $count = $this->store->updateWhere('users', fn($u) => ($u['id'] ?? '') === $id, fn($u) => array_merge($u, ['role'=>$role,'updated_at'=>date('c')]));
            if (!$count) Response::json(['ok' => false, 'error' => 'Utilisateur introuvable.'], 404);
            $this->auth->logoutUserSessionsNow($id);     // les droits changent : ses sessions actuelles sont invalidées
            $this->logActivity((string)$adminUser['id'], 'user_role', ['user_id'=>$id,'role'=>$role]);
            Security::audit($this->store, 'admin_user_role', (string)$adminUser['id'], ['target' => $id, 'role' => $role]);
            Response::json(['ok' => true, 'role' => $role]);
        }

        // ── Administration : demandes de protection des données ─────────────
        if ($path === 'admin/privacy-requests' && $method === 'GET') {
            $this->requireAdmin();
            $requests = $this->store->all('privacy_requests');
            $users = [];
            foreach ($this->store->all('users') as $u) $users[$u['id']] = $u;
            foreach ($requests as &$r) {
                $owner = $users[$r['user_id'] ?? ''] ?? [];
                $r['user_name'] = $owner['display_name'] ?? 'Compte supprimé';
                $r['user_email'] = $owner['email'] ?? '';
            }
            unset($r);
            usort($requests, fn($a,$b)=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')));
            Response::json(['ok'=>true,'requests'=>array_values($requests)]);
        }

        if ($path === 'admin/privacy-requests/status' && $method === 'POST') {
            $admin = $this->requireAdmin();
            $id = trim((string)($input['request_id'] ?? ''));
            $status = strtolower(trim((string)($input['status'] ?? 'in_progress')));
            if (!in_array($status, ['new','in_progress','completed','rejected'], true)) {
                Response::json(['ok'=>false,'error'=>'Statut invalide.'],422);
            }
            $count = $this->store->updateWhere('privacy_requests',
                fn($r)=>(string)($r['id']??'') === $id,
                fn($r)=>array_merge($r,['status'=>$status,'updated_at'=>date('c'),'handled_by'=>$admin['id']])
            );
            if (!$count) Response::json(['ok'=>false,'error'=>'Demande introuvable.'],404);
            Security::audit($this->store,'privacy_request_status',(string)$admin['id'],['request_id'=>$id,'status'=>$status]);
            Response::json(['ok'=>true,'status'=>$status]);
        }

        // ── Administration : demandes utilisateurs, lecture seule côté utilisateur ──
        if ($path === 'admin/support' && $method === 'GET') {
            $this->requireAdmin();
            $tickets = $this->store->all('support_tickets');
            $users = [];
            foreach ($this->store->all('users') as $u) $users[$u['id']] = $u;
            $tickets = array_map(function($t) use ($users) {
                $u = $users[$t['user_id'] ?? ''] ?? [];
                $t['user_name'] = $u['display_name'] ?? ($u['email'] ?? 'Utilisateur');
                $t['user_email'] = $u['email'] ?? '';
                return $t;
            }, $tickets);
            usort($tickets, fn($a,$b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
            Response::json(['ok' => true, 'tickets' => array_values($tickets)]);
        }

        if ($path === 'admin/support/status' && $method === 'POST') {
            $adminUser = $this->requireAdmin();
            $id = trim((string)($input['ticket_id'] ?? ''));
            $status = strtolower(trim((string)($input['status'] ?? 'open')));
            if (!in_array($status, ['new','open','in_progress','resolved','closed'], true)) Response::json(['ok'=>false,'error'=>'Statut invalide.'],422);
            $found = null;
            foreach ($this->store->all('support_tickets') as $t) if (($t['id'] ?? '') === $id) { $found=$t; break; }
            if (!$found) Response::json(['ok'=>false,'error'=>'Demande introuvable.'],404);
            $this->store->updateWhere('support_tickets', fn($t)=>($t['id']??'')===$id, fn($t)=>array_merge($t,['status'=>$status,'updated_at'=>date('c'),'last_action_by'=>$adminUser['id']]));
            $label = ['new'=>'Nouveau','open'=>'Ouvert','in_progress'=>'En cours','resolved'=>'Résolu','closed'=>'Fermé'][$status];
            $this->addNotification($found['user_id'], 'support_status', 'Le statut de votre demande a changé : '.$label.'.', ['ticket_id'=>$id,'status'=>$status]);
            $owner = null; foreach ($this->store->all('users') as $u) if (($u['id'] ?? '') === $found['user_id']) {$owner=$u;break;}
            if ($owner && !empty($owner['email'])) $mailer->sendSupportStatus((string)$owner['email'], $found, $label);
            Response::json(['ok'=>true,'status'=>$status]);
        }

        // ── Administration : signalements ──
        if ($path === 'admin/reports' && $method === 'GET') {
            $this->requireAdmin();
            $reports = $this->store->all('reports');
            usort($reports, fn($a,$b)=>strcmp($b['created_at']??'',$a['created_at']??''));
            $postsById = []; foreach ($this->store->all('posts') as $pp) $postsById[$pp['id']] = $pp;
            $cmtsById = []; foreach ($this->store->all('comments') as $cc) $cmtsById[$cc['id']] = $cc;
            $reports = array_map(function ($r) use ($postsById, $cmtsById) {
                $t = (string)($r['target_id'] ?? '');
                if (($r['target_type'] ?? '') === 'post' && isset($postsById[$t])) {
                    $r['target_preview'] = mb_substr((string)($postsById[$t]['content'] ?? ''), 0, 200);
                    $r['target_deleted'] = !empty($postsById[$t]['deleted']);
                } elseif (($r['target_type'] ?? '') === 'comment' && isset($cmtsById[$t])) {
                    $r['target_preview'] = mb_substr((string)($cmtsById[$t]['content'] ?? ''), 0, 200);
                    $r['target_deleted'] = !empty($cmtsById[$t]['deleted']);
                }
                return $r;
            }, $reports);
            Response::json(['ok'=>true,'reports'=>array_values($reports)]);
        }

        if ($path === 'admin/reports/status' && $method === 'POST') {
            $adminUser = $this->requireAdmin();
            $id = trim((string)($input['report_id'] ?? ''));
            $status = strtolower(trim((string)($input['status'] ?? 'reviewing')));
            if (!in_array($status,['new','reviewing','resolved','dismissed'],true)) Response::json(['ok'=>false,'error'=>'Statut invalide.'],422);
            $count=$this->store->updateWhere('reports',fn($r)=>($r['id']??'')===$id,fn($r)=>array_merge($r,['status'=>$status,'updated_at'=>date('c'),'handled_by'=>$adminUser['id']]));
            if(!$count) Response::json(['ok'=>false,'error'=>'Signalement introuvable.'],404);
            $this->logActivity((string)$adminUser['id'], 'report_status', ['report_id'=>$id,'status'=>$status]);
            Response::json(['ok'=>true,'status'=>$status]);
        }

        // ── Administration : modération du contenu ──
        if ($path === 'admin/posts/delete' && $method === 'POST') {
            $adminUser=$this->requireAdmin(); $id=trim((string)($input['post_id']??''));
            $count=$this->store->updateWhere('posts',fn($p)=>($p['id']??'')===$id,fn($p)=>array_merge($p,['deleted'=>true,'moderated_by'=>$adminUser['id'],'moderated_at'=>date('c')]));
            if(!$count) Response::json(['ok'=>false,'error'=>'Publication introuvable.'],404); $this->logActivity((string)$adminUser['id'],'post_delete',['post_id'=>$id]); Response::json(['ok'=>true]);
        }
        if ($path === 'admin/comments/delete' && $method === 'POST') {
            $adminUser=$this->requireAdmin(); $id=trim((string)($input['comment_id']??''));
            $count=$this->store->updateWhere('comments',fn($c)=>($c['id']??'')===$id,fn($c)=>array_merge($c,['deleted'=>true,'moderated_by'=>$adminUser['id'],'moderated_at'=>date('c')]));
            if(!$count) Response::json(['ok'=>false,'error'=>'Commentaire introuvable.'],404); $this->logActivity((string)$adminUser['id'],'comment_delete',['comment_id'=>$id]); Response::json(['ok'=>true]);
        }
        if ($path === 'admin/activity' && $method === 'GET') {
            $this->requireAdmin(); $logs=$this->store->all('activity_logs'); usort($logs,fn($a,$b)=>strcmp($b['created_at']??'',$a['created_at']??'')); Response::json(['ok'=>true,'logs'=>array_slice($logs,0,200)]);
        }

        // ── Administration : maintenance ───────────────────────────────────
        if ($path === 'admin/maintenance' && $method === 'GET') {
            $this->requireMaintenanceAdmin();
            Response::json(['ok'=>true,'maintenance'=>$this->maintenanceState()]);
        }
        if ($path === 'admin/maintenance' && $method === 'POST') {
            $admin = $this->requireMaintenanceAdmin();
            $state = $this->saveMaintenance($input, $admin);
            Response::json(['ok'=>true,'maintenance'=>$state]);
        }

        // ── Confidentialité : données, demandes et suppression ───────────────
        if ($path === 'privacy/export' && $method === 'GET') {
            $u = $this->auth->user();
            if (!$u) Response::json(['ok'=>false,'error'=>'Authentification requise.'],401);
            if (!Security::rateLimit($this->store, 'privacy-export', 3, 3600)) {
                Response::json(['ok'=>false,'error'=>'Trop de demandes d’export. Réessayez plus tard.'],429);
            }
            $data = $this->privacyExport((string)$u['id']);
            Security::audit($this->store, 'data_export', (string)$u['id']);
            header('Content-Disposition: attachment; filename="kova-donnees-' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$u['id']) . '.json"');
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, private');
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($path === 'privacy/request' && $method === 'POST') {
            $u = $this->auth->user();
            if (!$u) Response::json(['ok'=>false,'error'=>'Authentification requise.'],401);
            if (!Security::rateLimit($this->store, 'privacy-request', 10, 3600)) {
                Response::json(['ok'=>false,'error'=>'Trop de demandes. Réessayez plus tard.'],429);
            }
            $type = strtolower(trim((string)($input['type'] ?? 'access')));
            $allowed = ['access','rectification','erasure','restriction','objection','portability','consent'];
            if (!in_array($type, $allowed, true)) Response::json(['ok'=>false,'error'=>'Type de demande invalide.'],422);
            $message = mb_substr(trim((string)($input['message'] ?? '')), 0, 3000);
            $request = [
                'id' => 'prv_' . bin2hex(random_bytes(8)),
                'user_id' => $u['id'],
                'type' => $type,
                'message' => $message,
                'status' => 'new',
                'created_at' => date('c'),
                'updated_at' => date('c'),
            ];
            $this->store->insert('privacy_requests', $request);
            Security::audit($this->store, 'privacy_request', (string)$u['id'], ['type'=>$type]);
            $adminEmail = trim((string)$this->env->get('ADMIN_EMAIL', $this->env->get('MAIL_FROM_ADDRESS', '')));
            if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                $mailer->sendSupportAlert($adminEmail, $u, [
                    'id'=>$request['id'], 'category'=>'privacy', 'subject'=>'Demande de protection des données : '.$type,
                    'message'=>$message !== '' ? $message : 'Demande créée depuis les paramètres de confidentialité.',
                ]);
            }
            Response::json(['ok'=>true,'request'=>$request,'message'=>'Votre demande a été enregistrée.'],201);
        }

        if ($path === 'privacy/delete' && $method === 'POST') {
            $u = $this->auth->user();
            if (!$u) Response::json(['ok'=>false,'error'=>'Authentification requise.'],401);
            if (!Security::rateLimit($this->store, 'privacy-delete', 3, 86400)) {
                Response::json(['ok'=>false,'error'=>'Trop de demandes de suppression. Réessayez demain.'],429);
            }
            $password = (string)($input['password'] ?? '');
            if ($password === '' || !Passwords::verify($password, (string)($u['password_hash'] ?? ''))) {
                Security::audit($this->store, 'data_deletion_failed', (string)$u['id']);
                Response::json(['ok'=>false,'error'=>'Mot de passe incorrect.'],422);
            }
            // Compte protégé par 2FA : la suppression exige aussi un code (TOTP ou secours).
            if (!empty($u['twofa_enabled'])) {
                $secret = Totp::open((string)($u['twofa_secret_enc'] ?? ''));
                $codeIn = (string)($input['code'] ?? '');
                $okCode = $secret !== null && Totp::verify($secret, $codeIn, (int)($u['twofa_last_step'] ?? 0)) !== null;
                if (!$okCode) {
                    Security::audit($this->store, 'data_deletion_failed_2fa', (string)$u['id']);
                    Response::json(['ok'=>false,'error'=>'Code de double authentification requis ou incorrect.','twofa_required'=>true],422);
                }
            }
            $uid = (string)$u['id'];
            $images = new ImageStore($this->env, new Cloudinary($this->env));
            foreach ([$u['avatar_url'] ?? '', $u['cover_url'] ?? ''] as $url) {
                if (is_string($url) && $url !== '') $images->delete($url);
            }
            foreach (['posts' => 'user_id', 'messages' => 'sender_id', 'products' => 'seller_id'] as $table => $col) {
                foreach ($this->store->all($table) as $row) {
                    if (($row[$col] ?? '') === $uid && !empty($row['image_url'])) $images->delete((string)$row['image_url']);
                }
            }
            $this->privacyDelete($uid);
            Security::audit($this->store, 'account_deleted', null, ['deleted_user' => hash('sha256', $uid)]);
            $this->auth->logout();
            Response::json(['ok'=>true,'message'=>'Votre compte et les données pouvant être supprimées ont été traités.']);
        }

        if ($path === 'privacy/requests' && $method === 'GET') {
            $u = $this->auth->user();
            if (!$u) Response::json(['ok'=>false,'error'=>'Authentification requise.'],401);
            $rows = array_values(array_filter($this->store->all('privacy_requests'), fn($r)=>(string)($r['user_id']??'') === (string)$u['id']));
            usort($rows, fn($a,$b)=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')));
            Response::json(['ok'=>true,'requests'=>$rows]);
        }

        // ── Modules : groupes, communautés, social, messagerie, boutique, OAuth ──
        foreach ($this->modules() as $module) {
            $module->handle($path, $method, $input);
        }

        Response::json(['ok' => false, 'error' => 'Route API inconnue.'], 404);
    }
    /**
     * Construit un export utilisateur sans secrets d'authentification.
     */
    private function privacyExport(string $uid): array
    {
        $files = [
            'users','profiles','posts','comments','reactions','message_reactions','shares',
            'followers','friendships','blocks','favorites','notifications','notification_preferences',
            'messages','message_reads','conversations','conversation_members','groups','group_members',
            'communities','community_members','support_tickets','support_messages','support_attachments',
            'reports','appeals','orders','products','carts','sellers','consent_records',
            'oauth_clients','oauth_grants','activity_logs','security_events','push_subscriptions',
            'sessions','password_resets','verification_tokens','verification_codes','privacy_requests',
        ];
        $out = [
            'exported_at' => date('c'),
            'account' => null,
            'data' => [],
        ];

        foreach ($this->store->all('users') as $row) {
            if (($row['id'] ?? '') === $uid) {
                $out['account'] = $this->privacySanitize($row);
                break;
            }
        }

        foreach ($files as $name) {
            $rows = $this->store->all($name);
            $matched = [];
            foreach ($rows as $row) {
                if ($this->containsUserId($row, $uid)) {
                    $matched[] = $this->privacySanitize($row);
                }
            }
            if ($matched) $out['data'][$name] = array_values($matched);
        }
        return $out;
    }

    private function containsUserId(mixed $value, string $uid): bool
    {
        if (is_string($value)) return $value === $uid;
        if (!is_array($value)) return false;
        foreach ($value as $v) if ($this->containsUserId($v, $uid)) return true;
        return false;
    }

    private function privacySanitize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        $out = [];
        foreach ($value as $k => $v) {
            if (preg_match('/password|token|secret|authorization|hash|backup|_enc$|^auth$|p256dh|endpoint|code$|session_version|attempts|twofa_last/i', (string)$k)) continue;
            $out[$k] = is_array($v) ? $this->privacySanitize($v) : $v;
        }
        return $out;
    }

    /**
     * Suppression/anonymisation déterministe des données liées à un compte.
     * Les enregistrements qui peuvent constituer une trace transactionnelle
     * sont conservés sous forme minimisée et sans coordonnées de contact.
     */
    private function privacyDelete(string $uid): void
    {
        $deleted = 'deleted_' . substr(hash('sha256', $uid), 0, 16);

        $removeBy = function(string $name, callable $predicate): void {
            $rows = $this->store->all($name);
            $kept = [];
            foreach ($rows as $row) if (!$predicate($row)) $kept[] = $row;
            if (count($kept) !== count($rows)) $this->store->replace($name, $kept);
        };

        foreach ([
            'comments','reactions','message_reactions','shares','followers','friendships','blocks',
            'favorites','notifications','notification_preferences','message_reads','conversation_members',
            'group_members','community_members','carts','push_subscriptions','password_resets',
            'verification_tokens','verification_codes','privacy_requests'
        ] as $name) {
            $removeBy($name, fn($r) => $this->containsUserId($r, $uid));
        }

        // Publications et contenus créés par le compte.
        foreach (['posts','messages','products'] as $name) {
            $removeBy($name, fn($r) => (string)($r['user_id'] ?? $r['sender_id'] ?? $r['seller_id'] ?? '') === $uid);
        }

        // Conversations : retirer le membre sans exposer le compte supprimé.
        $this->store->updateWhere('conversations',
            fn($r) => $this->containsUserId($r['participants'] ?? [], $uid),
            function($r) use ($uid) {
                if (isset($r['participants']) && is_array($r['participants'])) {
                    $r['participants'] = array_values(array_filter($r['participants'], fn($id)=>(string)$id !== $uid));
                }
                if (($r['last_sender_id'] ?? '') === $uid) $r['last_sender_id'] = null;
                return $r;
            }
        );

        // Groupes/communautés dont l'utilisateur était propriétaire.
        foreach (['groups','communities'] as $name) {
            $this->store->updateWhere($name,
                fn($r)=>(string)($r['owner_id']??'') === $uid,
                fn($r)=>array_merge($r,['deleted'=>true,'deleted_at'=>date('c'),'owner_id'=>null])
            );
        }

        // Les commandes peuvent devoir être conservées pour la traçabilité :
        // les coordonnées de livraison sont supprimées et les parties
        // supprimées sont pseudonymisées.
        $this->store->updateWhere('orders',
            fn($r)=>(string)($r['buyer_id']??'') === $uid || (string)($r['seller_id']??'') === $uid,
            function($r) use ($uid,$deleted) {
                if (($r['buyer_id']??'') === $uid) $r['buyer_id'] = $deleted;
                if (($r['seller_id']??'') === $uid) $r['seller_id'] = $deleted;
                $r['phone'] = null;
                $r['address'] = null;
                $r['note'] = null;
                return $r;
            }
        );

        // Signalements/tickets : conserver uniquement la traçabilité nécessaire.
        $this->store->updateWhere('reports',
            fn($r)=>($r['reporter_id']??'') === $uid,
            fn($r)=>array_merge($r,['reporter_id'=>$deleted,'details'=>''])
        );
        $this->store->updateWhere('support_tickets',
            fn($r)=>($r['user_id']??'') === $uid,
            fn($r)=>array_merge($r,['user_id'=>$deleted,'message'=>''])
        );
        $this->store->updateWhere('consent_records',
            fn($r)=>($r['user_id']??'') === $uid,
            fn($r)=>array_merge($r,['user_id'=>$deleted])
        );
        $this->store->updateWhere('security_events',
            fn($r)=>($r['user_id']??'') === $uid,
            fn($r)=>array_merge($r,['user_id'=>$deleted])
        );
        $this->store->updateWhere('activity_logs',
            fn($r)=>($r['user_id']??$r['actor_id']??'') === $uid,
            fn($r)=>array_merge($r,['user_id'=>$deleted,'actor_id'=>$deleted])
        );

        // Applications OAuth appartenant au compte : désactivation et retrait
        // des secrets publics ; les autorisations de l'utilisateur sont retirées.
        $this->store->updateWhere('oauth_clients',
            fn($r)=>(string)($r['owner_id']??'') === $uid,
            fn($r)=>array_merge($r,['owner_id'=>null,'disabled'=>true,'deleted'=>true])
        );
        $removeBy('oauth_tokens', fn($r)=>(string)($r['user_id']??'') === $uid);
        $removeBy('oauth_grants', fn($r)=>(string)($r['user_id']??'') === $uid);
        $removeBy('oauth_codes', fn($r)=>(string)($r['user_id']??'') === $uid);

        // Profil secondaire éventuel.
        $removeBy('profiles', fn($r)=>(string)($r['user_id']??'') === $uid);
        $removeBy('sellers', fn($r)=>(string)($r['user_id']??'') === $uid);

        // Enfin, supprimer l'identité principale.
        $removeBy('users', fn($r)=>(string)($r['id']??'') === $uid);
    }

}
