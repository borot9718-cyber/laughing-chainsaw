<?php
declare(strict_types=1);

namespace Kova\Core;

final class Router
{
    public function __construct(private App $app) {}

    public function dispatch(): void
    {
        $route  = trim((string)($_GET['route'] ?? parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)), '/');
        $route  = preg_replace('/\?.*$/', '', $route) ?? '';
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Pages publiques
        if ($route === '')              { $this->app->page('landing'); return; }
        if ($route === 'inscription')   { $this->app->page('auth', ['mode' => 'register']); return; }
        if ($route === 'connexion')     { $this->app->page('auth', ['mode' => 'login']); return; }
        if ($route === 'mot-de-passe-oublie') { $this->app->page('auth', ['mode' => 'forgot']); return; }
        if ($route === 'reinitialiser-mot-de-passe') {
            $this->app->page('auth', ['mode' => 'reset', 'token' => $_GET['token'] ?? '']);
            return;
        }
        if ($route === 'verifier-email') {
            // Vérification par code à 6 chiffres (les anciens liens arrivent ici aussi).
            $this->app->page('auth', ['mode' => 'verify', 'email' => strtolower(trim((string)($_GET['email'] ?? '')))]);
            return;
        }
        if ($route === 'a-propos')       { $this->app->page('about'); return; }
        if ($route === 'conditions')     { $this->app->page('terms'); return; }
        if ($route === 'confidentialite'){ $this->app->page('privacy'); return; }
        if ($route === 'health')         { $this->app->health(); return; }

        // Mode maintenance : les visiteurs/utilisateurs voient uniquement la page
        // de maintenance. Un administrateur authentifié conserve l'accès complet
        // à son espace d'administration. La connexion reste disponible afin qu'un
        // administrateur puisse ouvrir sa session pendant la maintenance.
        $maintenance = $this->app->maintenanceState();
        $maintenanceLoginAllowed = in_array($route, ['connexion', 'api/auth/login', 'api/auth/2fa', 'api/auth/verify-code', 'api/auth/logout', 'api/auth/me'], true);
        if (($maintenance['active'] ?? false) && !$this->app->isAdmin() && !$maintenanceLoginAllowed) {
            $this->app->page('maintenance', ['maintenance' => $maintenance]);
            return;
        }
        if ($route === 'debug/assets')   { $this->app->debugAssets(); return; }
        if ($route === 'robots' || $route === 'robots.txt')    { $this->app->robots(); return; }
        if ($route === 'sitemap' || $route === 'sitemap.xml')  { $this->app->sitemap(); return; }

        // « Continuer avec KOVA » (OAuth 2.0)
        if ($route === 'oauth/authorize') { $this->app->oauth()->authorize($method); return; }
        if (in_array($route, ['oauth/token', 'oauth/userinfo', 'oauth/revoke'], true)) { $this->app->api($route, $method); }
        if ($route === '.well-known/oauth-authorization-server' || $route === '.well-known/openid-configuration') {
            $this->app->oauth()->metadata();
        }

        // Déconnexion
        if ($route === 'deconnexion') {
            if ($method !== 'POST') {
                // Un simple lien (GET) ne déconnecte jamais : page de confirmation avec formulaire POST + jeton CSRF (F-08).
                $this->app->page('logout');
                return;
            }
            if (!Security::verifyCsrf((string)($_POST['csrf'] ?? $_SERVER['HTTP_X_KOVA_CSRF'] ?? ''))) {
                Response::redirect('/parametres');
            }
            $this->app->auth()->logout();
            Response::redirect('/');
        }

        // Pages protégées (requiert connexion)
        $protected = ['app', 'profil', 'messages', 'notifications', 'groupes', 'communautes', 'boutique', 'aide', 'parametres', 'admin', 'admin/maintenance', 'recherche', 'developpeurs'];
        if (in_array($route, $protected, true)) {
            $this->app->auth()->requireAuth();
            // Les comptes administrateurs utilisent exclusivement l'espace d'administration.
            if ($this->app->isAdmin() && !in_array($route, ['admin', 'admin/maintenance', 'developpeurs', 'parametres'], true)) {
                Response::redirect('/admin');
            }
            if (in_array($route, ['admin','admin/maintenance'], true) && !$this->app->isAdmin()) {
                http_response_code(403);
                $this->app->page('404');
                return;
            }
            // 2FA obligatoire pour les administrateurs (ADMIN_2FA_REQUIRED=true) : on les envoie l'activer.
            if (in_array($route, ['admin','admin/maintenance'], true) && !$this->app->adminSecurityOk()) {
                Response::redirect('/parametres?setup2fa=1');
            }
            $viewMap = [
                'app'          => 'dashboard',
                'profil'       => 'profile',
                'messages'     => 'messages',
                'notifications'=> 'notifications',
                'groupes'      => 'groups',
                'communautes'  => 'communities',
                'boutique'     => 'store',
                'aide'         => 'support',
                'parametres'   => 'settings',
                'admin'        => 'admin',
                'admin/maintenance' => 'admin-maintenance',
                'recherche'    => 'search',
                'developpeurs' => 'developers',
            ];
            $this->app->page($viewMap[$route]);
            return;
        }

        // API
        if (str_starts_with($route, 'api/')) {
            $this->app->api(substr($route, 4), $method);
            return;
        }

        http_response_code(404);
        $this->app->page('404');
    }
}
