<?php
declare(strict_types=1);

namespace Kova\Core;

/**
 * Profils publics (lecture seule pour les autres), abonnements, blocages,
 * recherche globale, préférences (langue, notifications).
 *
 * Règle d'or : un utilisateur ne peut modifier QUE son propre profil. Toutes
 * les routes d'écriture de profil (App.php) utilisent exclusivement l'identité
 * de la session, jamais un identifiant envoyé par le client.
 */
final class Social extends Module
{
    private const PREF_KEYS = ['likes', 'comments', 'follows', 'groups', 'orders', 'messages'];
    private const THEMES = ['auto', 'light', 'dark'];
    private const MSG_PERMS = ['everyone', 'followers', 'nobody'];
    private const LANGS     = ['fr', 'en'];

    public function handle(string $path, string $method, array $input): void
    {
        if ($path === 'profile/view' && $method === 'GET')  $this->profileView();
        if ($path === 'profile/posts' && $method === 'GET') $this->profilePosts();
        if ($path === 'search' && $method === 'GET')        $this->search();
        if ($path === 'blocks' && $method === 'GET')        $this->blocksList();
        if ($path === 'settings' && $method === 'GET')      $this->settingsGet();
        if ($path === 'settings' && $method === 'POST')     $this->settingsSave($input);

        if (preg_match('#^users/([a-zA-Z0-9_]+)/(follow|unfollow|block|unblock|followers|following)$#', $path, $m)) {
            $target = $m[1];
            $action = $m[2];
            if ($method === 'POST' && $action === 'follow')   $this->follow($target);
            if ($method === 'POST' && $action === 'unfollow') $this->unfollow($target);
            if ($method === 'POST' && $action === 'block')    $this->block($target);
            if ($method === 'POST' && $action === 'unblock')  $this->unblock($target);
            if ($method === 'GET'  && $action === 'followers') $this->followList($target, true);
            if ($method === 'GET'  && $action === 'following') $this->followList($target, false);
        }

        if (preg_match('#^notifications/([a-zA-Z0-9_]+)/read$#', $path, $m) && $method === 'POST') {
            $u  = $this->me();
            $id = $m[1];
            $this->store->updateWhere('notifications',
                fn($n) => ($n['id'] ?? '') === $id && ($n['user_id'] ?? '') === $u['id'],
                fn($n) => array_merge($n, ['read' => true, 'read_at' => date('c')])
            );
            $this->ok();
        }
    }

    // ── Profil ─────────────────────────────────────────────────────────────
    private function profileView(): never
    {
        $me = $this->me();
        $id = trim((string)($_GET['user_id'] ?? ''));
        if ($id === '') $id = $me['id'];
        $target = $this->findById('users', $id);
        if (!$target || in_array(strtolower((string)($target['account_status'] ?? 'active')), ['banned'], true)) {
            $this->fail('Profil introuvable.', 404);
        }
        $isMe = $id === $me['id'];
        if (!$isMe && $this->blockedByThem($me['id'], $id)) $this->fail('Profil introuvable.', 404);

        $postsCount = 0;
        foreach ($this->store->all('posts') as $p) {
            if (($p['user_id'] ?? '') === $id && empty($p['deleted']) && empty($p['group_id']) && empty($p['community_id'])) $postsCount++;
        }
        $followers = 0;
        $following = 0;
        $iFollow   = false;
        foreach ($this->store->all('followers') as $f) {
            if (($f['followed_id'] ?? '') === $id) {
                $followers++;
                if (($f['follower_id'] ?? '') === $me['id']) $iFollow = true;
            }
            if (($f['follower_id'] ?? '') === $id) $following++;
        }
        $iBlock = false;
        foreach ($this->store->all('blocks') as $b) {
            if (($b['blocker_id'] ?? '') === $me['id'] && ($b['blocked_id'] ?? '') === $id) { $iBlock = true; break; }
        }
        $user = [
            'id'              => $id,
            'display_name'    => (string)($target['display_name'] ?? ''),
            'bio'             => (string)($target['bio'] ?? ''),
            'avatar_url'      => (string)($target['avatar_url'] ?? ''),
            'cover_url'       => (string)($target['cover_url'] ?? ''),
            'created_at'      => $target['created_at'] ?? null,
            'posts_count'     => $postsCount,
            'followers_count' => $followers,
            'following_count' => $following,
            'is_me'           => $isMe,
            'is_following'    => $iFollow,
            'is_blocked'      => $iBlock,
        ];
        if ($isMe) $user['email'] = (string)($target['email'] ?? '');
        $this->ok(['user' => $user]);
    }

    private function blockedByThem(string $viewerId, string $ownerId): bool
    {
        foreach ($this->store->all('blocks') as $b) {
            if (($b['blocker_id'] ?? '') === $ownerId && ($b['blocked_id'] ?? '') === $viewerId) return true;
        }
        return false;
    }

    private function profilePosts(): never
    {
        $me = $this->me();
        $id = trim((string)($_GET['user_id'] ?? ''));
        if ($id === '') $id = $me['id'];
        $target = $this->findById('users', $id);
        if (!$target) $this->fail('Profil introuvable.', 404);
        if ($id !== $me['id'] && $this->isBlocked($me['id'], $id)) $this->fail('Profil introuvable.', 404);

        $posts = [];
        foreach ($this->store->all('posts') as $p) {
            if (($p['user_id'] ?? '') !== $id || !empty($p['deleted']) || !empty($p['group_id']) || !empty($p['community_id'])) continue;
            $posts[] = $p;
        }
        usort($posts, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
        $posts = array_slice($posts, 0, 50);
        $out = [];
        foreach ($posts as $p) {
            $rs = is_array($p['reactions'] ?? null) ? $p['reactions'] : [];
            $p['author_name']   = (string)(($target['display_name'] ?? '') !== '' ? $target['display_name'] : 'Utilisateur');
            $p['author_avatar'] = (string)($target['avatar_url'] ?? '');
            $p['like_count']    = count(array_filter($rs, fn($r) => ($r['type'] ?? 'like') === 'like'));
            $p['liked_by_me']   = isset($rs[$me['id'] . '_like']);
            unset($p['reactions']);
            $out[] = $p;
        }
        $this->ok(['posts' => $out]);
    }

    // ── Abonnements / blocages ─────────────────────────────────────────────
    private function targetUser(string $id): array
    {
        $me = $this->me();
        if ($id === $me['id']) $this->fail('Action impossible sur votre propre compte.');
        $t = $this->findById('users', $id);
        if (!$t) $this->fail('Utilisateur introuvable.', 404);
        return $t;
    }

    private function follow(string $id): never
    {
        $me = $this->me();
        $this->targetUser($id);
        if ($this->isBlocked($me['id'], $id)) $this->fail('Vous ne pouvez pas suivre cet utilisateur.', 403);
        foreach ($this->store->all('followers') as $f) {
            if (($f['follower_id'] ?? '') === $me['id'] && ($f['followed_id'] ?? '') === $id) $this->ok(['following' => true]);
        }
        $this->store->insert('followers', [
            'id' => $this->newId('fol_'), 'follower_id' => $me['id'], 'followed_id' => $id, 'created_at' => date('c'),
        ]);
        $this->notify($id, 'follow', ($me['display_name'] ?: 'Quelqu’un') . ' s’est abonné(e) à vous.',
            ['link' => '/profil?user=' . $me['id'], 'actor_id' => $me['id'], 'dedupe' => 'follow:' . $me['id'] . ':' . $id]);
        $this->ok(['following' => true]);
    }

    private function unfollow(string $id): never
    {
        $me = $this->me();
        $rows = array_values(array_filter($this->store->all('followers'),
            fn($f) => !(($f['follower_id'] ?? '') === $me['id'] && ($f['followed_id'] ?? '') === $id)));
        $this->store->replace('followers', $rows);
        $this->ok(['following' => false]);
    }

    private function block(string $id): never
    {
        $me = $this->me();
        $this->targetUser($id);
        $already = false;
        foreach ($this->store->all('blocks') as $b) {
            if (($b['blocker_id'] ?? '') === $me['id'] && ($b['blocked_id'] ?? '') === $id) { $already = true; break; }
        }
        if (!$already) {
            $this->store->insert('blocks', ['id' => $this->newId('blk_'), 'blocker_id' => $me['id'], 'blocked_id' => $id, 'created_at' => date('c')]);
        }
        // Un blocage coupe aussi les abonnements dans les deux sens.
        $rows = array_values(array_filter($this->store->all('followers'), function ($f) use ($me, $id) {
            $a = $f['follower_id'] ?? '';
            $b = $f['followed_id'] ?? '';
            return !(($a === $me['id'] && $b === $id) || ($a === $id && $b === $me['id']));
        }));
        $this->store->replace('followers', $rows);
        $this->ok(['blocked' => true]);
    }

    private function unblock(string $id): never
    {
        $me = $this->me();
        $rows = array_values(array_filter($this->store->all('blocks'),
            fn($b) => !(($b['blocker_id'] ?? '') === $me['id'] && ($b['blocked_id'] ?? '') === $id)));
        $this->store->replace('blocks', $rows);
        $this->ok(['blocked' => false]);
    }

    private function blocksList(): never
    {
        $me    = $this->me();
        $users = $this->usersMap();
        $out   = [];
        foreach ($this->store->all('blocks') as $b) {
            if (($b['blocker_id'] ?? '') !== $me['id']) continue;
            $out[] = $this->pub($users[$b['blocked_id'] ?? ''] ?? null);
        }
        $this->ok(['blocks' => $out]);
    }

    private function followList(string $id, bool $followers): never
    {
        $me = $this->me();
        if (!$this->findById('users', $id)) $this->fail('Utilisateur introuvable.', 404);
        $users   = $this->usersMap();
        $blocked = array_flip($this->blockedIdsFor($me['id']));
        $out = [];
        foreach ($this->store->all('followers') as $f) {
            if ($followers && ($f['followed_id'] ?? '') === $id)      $uid = (string)($f['follower_id'] ?? '');
            elseif (!$followers && ($f['follower_id'] ?? '') === $id) $uid = (string)($f['followed_id'] ?? '');
            else continue;
            if (isset($blocked[$uid]) || !isset($users[$uid])) continue;
            $out[] = $this->pub($users[$uid]);
        }
        $this->ok(['users' => $out]);
    }

    // ── Recherche globale ──────────────────────────────────────────────────
    private function search(): never
    {
        $me    = $this->me();
        $q     = $this->text($_GET['q'] ?? '', 80);
        $limit = max(1, min(30, (int)($_GET['limit'] ?? 8)));
        $only  = (string)($_GET['type'] ?? '');
        $noSelf = !empty($_GET['exclude_self']);
        if (mb_strlen($q) < 2) $this->ok(['q' => $q, 'users' => [], 'groups' => [], 'communities' => [], 'posts' => [], 'products' => []]);

        $blocked = array_flip($this->blockedIdsFor($me['id']));
        $users   = $this->usersMap();

        $foundUsers = [];
        if ($only === '' || $only === 'users') {
            foreach ($users as $uid => $u) {
                if (isset($blocked[$uid])) continue;
                if ($noSelf && $uid === $me['id']) continue;
                if (in_array(strtolower((string)($u['account_status'] ?? 'active')), ['banned', 'suspended'], true)) continue;
                if (($u['discoverable'] ?? true) === false && $uid !== $me['id']) continue;     // profil non listé dans la recherche
                if (!$this->contains((string)($u['display_name'] ?? ''), $q)) continue;
                $foundUsers[] = $this->pub($u);
                if (count($foundUsers) >= $limit) break;
            }
        }

        $spaces = ['groups' => [], 'communities' => []];
        foreach (['groups' => 'group_members', 'communities' => 'community_members'] as $table => $membersTable) {
            if ($only !== '' && $only !== $table) continue;
            $fk = $table === 'groups' ? 'group_id' : 'community_id';
            $counts = [];
            foreach ($this->store->all($membersTable) as $r) {
                if (($r['status'] ?? 'active') === 'pending') continue;
                $counts[$r[$fk] ?? ''] = ($counts[$r[$fk] ?? ''] ?? 0) + 1;
            }
            foreach ($this->store->all($table) as $s) {
                if (!empty($s['deleted'])) continue;
                if (!$this->contains((string)($s['name'] ?? '') . ' ' . (string)($s['description'] ?? ''), $q)) continue;
                $spaces[$table][] = [
                    'id' => $s['id'], 'name' => (string)($s['name'] ?? ''), 'description' => (string)($s['description'] ?? ''),
                    'avatar_url' => (string)($s['avatar_url'] ?? ''),
                    'visibility' => ($table === 'groups' && (($s['visibility'] ?? 'public') === 'private')) ? 'private' : 'public',
                    'member_count' => (int)($counts[$s['id']] ?? 0),
                ];
                if (count($spaces[$table]) >= $limit) break;
            }
        }

        $foundPosts = [];
        if ($only === '' || $only === 'posts') {
            $all = $this->store->all('posts');
            usort($all, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
            foreach ($all as $p) {
                if (!empty($p['deleted']) || !empty($p['group_id']) || !empty($p['community_id'])) continue;
                if (isset($blocked[$p['user_id'] ?? ''])) continue;
                if (!$this->contains((string)($p['content'] ?? ''), $q)) continue;
                $a = $users[$p['user_id'] ?? ''] ?? null;
                $foundPosts[] = [
                    'id' => $p['id'], 'user_id' => $p['user_id'] ?? '', 'excerpt' => mb_substr((string)($p['content'] ?? ''), 0, 160),
                    'author_name' => $a['display_name'] ?? 'Utilisateur', 'author_avatar' => $a['avatar_url'] ?? '',
                    'created_at' => $p['created_at'] ?? null,
                ];
                if (count($foundPosts) >= $limit) break;
            }
        }

        $foundProducts = [];
        if ($only === '' || $only === 'products') {
            $all = $this->store->all('products');
            usort($all, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
            $currency = (string)$this->env->get('STORE_CURRENCY', 'FCFA');
            foreach ($all as $p) {
                if (!empty($p['deleted']) || isset($blocked[$p['seller_id'] ?? ''])) continue;
                if (!$this->contains((string)($p['title'] ?? '') . ' ' . (string)($p['description'] ?? '') . ' ' . (string)($p['category'] ?? ''), $q)) continue;
                $foundProducts[] = [
                    'id' => $p['id'], 'title' => (string)($p['title'] ?? ''), 'price' => (int)($p['price'] ?? 0), 'currency' => $currency,
                    'image_url' => (string)($p['image_url'] ?? ''), 'category' => (string)($p['category'] ?? ''),
                    'seller_name' => (string)(($users[$p['seller_id'] ?? '']['display_name'] ?? '') ?: 'Vendeur'),
                ];
                if (count($foundProducts) >= $limit) break;
            }
        }

        $this->ok(['q' => $q, 'users' => $foundUsers, 'groups' => $spaces['groups'], 'communities' => $spaces['communities'], 'posts' => $foundPosts, 'products' => $foundProducts]);
    }

    // ── Paramètres (langue + préférences de notification) ─────────────────
    private function prefsFor(string $uid): array
    {
        $prefs = array_fill_keys(self::PREF_KEYS, true);
        foreach ($this->store->all('notification_preferences') as $row) {
            if (($row['user_id'] ?? '') === $uid) {
                foreach (self::PREF_KEYS as $k) {
                    if (array_key_exists($k, $row)) $prefs[$k] = (bool)$row[$k];
                }
                break;
            }
        }
        return $prefs;
    }

    private function settingsGet(): never
    {
        $me = $this->me();
        $this->ok([
            'language'           => in_array(($me['language'] ?? 'fr'), self::LANGS, true) ? $me['language'] : 'fr',
            'theme'              => in_array(($me['theme'] ?? 'auto'), self::THEMES, true) ? $me['theme'] : 'auto',
            'message_permission' => in_array(($me['message_permission'] ?? 'everyone'), self::MSG_PERMS, true) ? $me['message_permission'] : 'everyone',
            'discoverable'       => ($me['discoverable'] ?? true) !== false,
            'prefs'              => $this->prefsFor($me['id']),
        ]);
    }

    private function settingsSave(array $input): never
    {
        $me = $this->me();
        $changes = [];
        if (isset($input['language'])) {
            $lang = (string)$input['language'];
            if (!in_array($lang, self::LANGS, true)) $this->fail('Langue non prise en charge.');
            $changes['language'] = $lang;
        }
        if (isset($input['theme'])) {
            if (!in_array((string)$input['theme'], self::THEMES, true)) $this->fail('Thème inconnu.');
            $changes['theme'] = (string)$input['theme'];
        }
        if (isset($input['message_permission'])) {
            if (!in_array((string)$input['message_permission'], self::MSG_PERMS, true)) $this->fail('Réglage de messagerie invalide.');
            $changes['message_permission'] = (string)$input['message_permission'];
        }
        if (array_key_exists('discoverable', $input)) $changes['discoverable'] = (bool)$input['discoverable'];
        if ($changes) {
            $this->store->updateWhere('users', fn($u) => ($u['id'] ?? '') === $me['id'], fn($u) => array_merge($u, $changes, ['updated_at' => date('c')]));
        }
        if (isset($input['prefs']) && is_array($input['prefs'])) {
            $prefs = $this->prefsFor($me['id']);
            foreach (self::PREF_KEYS as $k) {
                if (array_key_exists($k, $input['prefs'])) $prefs[$k] = (bool)$input['prefs'][$k];
            }
            $uid = (string)$me['id'];
            $this->store->mutate('notification_preferences', function (array $rows) use ($uid, $prefs) {
                $rows = array_values(array_filter($rows, fn($r) => ($r['user_id'] ?? '') !== $uid));
                $rows[] = array_merge(['user_id' => $uid, 'updated_at' => date('c')], $prefs);
                return $rows;
            });
        }
        $this->ok(['message' => 'Préférences enregistrées.']);
    }
}
