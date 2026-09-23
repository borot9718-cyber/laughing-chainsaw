<?php
declare(strict_types=1);

namespace Kova\Core;

/**
 * Espaces collectifs : groupes (publics ou privés) et communautés (publiques).
 *
 * Règles de confidentialité (comme sur Facebook) :
 *  - Groupe PUBLIC  : tout le monde voit le contenu ; rejoindre est immédiat.
 *  - Groupe PRIVÉ   : tout le monde voit la fiche (nom, description, nombre de
 *                     membres) mais SEULS LES MEMBRES voient publications et
 *                     liste des membres. Rejoindre = envoyer une demande, que
 *                     SEUL LE CRÉATEUR peut approuver ou refuser.
 *  - Seul le créateur modifie, supprime, approuve, exclut et nomme les modérateurs.
 *  - Les modérateurs peuvent supprimer des publications.
 */
final class Spaces extends Module
{
    private array $cfg;

    public function __construct(App $app, JsonStore $store, Auth $auth, Env $env, Cloudinary $cloud, private string $kind)
    {
        parent::__construct($app, $store, $auth, $env, $cloud);
        $this->cfg = $kind === 'groups'
            ? [
                'base' => 'groups', 'table' => 'groups', 'members' => 'group_members', 'fk' => 'group_id',
                'id' => 'grp_', 'mid' => 'gm_', 'key' => 'groups', 'private' => true,
                'page' => '/groupes', 'param' => 'group', 'folder' => 'groups',
                'the' => 'le groupe', 'The' => 'Le groupe', 'this' => 'ce groupe',
            ]
            : [
                'base' => 'communities', 'table' => 'communities', 'members' => 'community_members', 'fk' => 'community_id',
                'id' => 'com_', 'mid' => 'cm_', 'key' => 'communities', 'private' => false,
                'page' => '/communautes', 'param' => 'community', 'folder' => 'communities',
                'the' => 'la communauté', 'The' => 'La communauté', 'this' => 'cette communauté',
            ];
    }

    // ── Routage ────────────────────────────────────────────────────────────
    public function handle(string $path, string $method, array $input): void
    {
        $b = preg_quote($this->cfg['base'], '#');
        if (!preg_match('#^' . $b . '(?:/([a-zA-Z0-9_]+))?(?:/([a-z-]+))?(?:/([a-zA-Z0-9_]+))?(?:/([a-z]+))?$#', $path, $m)) return;
        $id  = $m[1] ?? '';
        $sub = $m[2] ?? '';
        $arg = $m[3] ?? '';
        $act = $m[4] ?? '';

        if ($id === '') {
            if ($method === 'GET')  $this->listSpaces();
            if ($method === 'POST') $this->create($input);
            return;
        }
        if ($sub === '') {
            if ($method === 'GET')    $this->detail($id);
            if ($method === 'DELETE') $this->delete($id);
            return;
        }
        if ($method !== 'GET' && $method !== 'POST') return;

        switch ($sub) {
            case 'update':
                if ($method === 'POST') $this->update($id, $input);
                return;
            case 'avatar':
            case 'cover':
                if ($method === 'POST') $this->image($id, $sub);
                return;
            case 'posts':
                if ($method === 'GET')  $this->posts($id);
                if ($method === 'POST') $this->createPost($id, $input);
                return;
            case 'members':
                if ($arg === '' && $method === 'GET') $this->members($id);
                if ($arg !== '' && $method === 'POST' && $act === 'remove') $this->removeMember($id, $arg);
                if ($arg !== '' && $method === 'POST' && $act === 'role')   $this->setRole($id, $arg, $input);
                return;
            case 'join':
                if ($method === 'POST') $this->join($id);
                return;
            case 'leave':
                if ($method === 'POST') $this->leave($id);
                return;
            case 'cancel-request':
                if ($method === 'POST') $this->cancelRequest($id);
                return;
            case 'requests':
                if ($arg === '' && $method === 'GET') $this->requests($id);
                if ($arg !== '' && $method === 'POST' && ($act === 'approve' || $act === 'reject')) $this->decide($id, $arg, $act === 'approve');
                return;
        }
    }

    // ── Accès aux données ──────────────────────────────────────────────────
    private function visibilityOf(array $s): string
    {
        if (!$this->cfg['private']) return 'public';
        return (($s['visibility'] ?? 'public') === 'private') ? 'private' : 'public';
    }

    private function getSpace(string $id): array
    {
        foreach ($this->store->all($this->cfg['table']) as $s) {
            if (($s['id'] ?? '') === $id && empty($s['deleted'])) return $s;
        }
        $this->fail($this->cfg['The'] . ' est introuvable.', 404);
    }

    private function memberRow(string $spaceId, string $uid): ?array
    {
        $fk = $this->cfg['fk'];
        foreach ($this->store->all($this->cfg['members']) as $r) {
            if (($r[$fk] ?? '') === $spaceId && ($r['user_id'] ?? '') === $uid) return $r;
        }
        return null;
    }

    /** owner | moderator | member | pending | none */
    private function roleOf(array $space, string $uid): string
    {
        if (($space['owner_id'] ?? '') === $uid) return 'owner';
        $row = $this->memberRow((string)$space['id'], $uid);
        if (!$row) return 'none';
        if (($row['status'] ?? 'active') === 'pending') return 'pending';
        return ($row['role'] ?? 'member') === 'moderator' ? 'moderator' : 'member';
    }

    private function isMemberRole(string $role): bool
    {
        return in_array($role, ['owner', 'moderator', 'member'], true);
    }

    private function canView(array $space, string $role): bool
    {
        return $this->visibilityOf($space) === 'public' || $this->isMemberRole($role);
    }

    /** Une seule lecture du fichier membres pour toute la liste. */
    private function indexMembers(): array
    {
        $fk = $this->cfg['fk'];
        $counts = [];
        $pending = [];
        $rows = [];
        foreach ($this->store->all($this->cfg['members']) as $r) {
            $sid = (string)($r[$fk] ?? '');
            if (($r['status'] ?? 'active') === 'pending') {
                $pending[$sid] = ($pending[$sid] ?? 0) + 1;
            } else {
                $counts[$sid] = ($counts[$sid] ?? 0) + 1;
            }
            $rows[$sid][(string)($r['user_id'] ?? '')] = $r;
        }
        return ['counts' => $counts, 'pending' => $pending, 'rows' => $rows];
    }

    private function decorate(array $s, string $uid, array $idx): array
    {
        $sid  = (string)$s['id'];
        $role = 'none';
        if (($s['owner_id'] ?? '') === $uid) {
            $role = 'owner';
        } elseif (isset($idx['rows'][$sid][$uid])) {
            $row  = $idx['rows'][$sid][$uid];
            $role = (($row['status'] ?? 'active') === 'pending') ? 'pending' : ((($row['role'] ?? 'member') === 'moderator') ? 'moderator' : 'member');
        }
        return [
            'id'            => $sid,
            'name'          => (string)($s['name'] ?? ''),
            'description'   => (string)($s['description'] ?? ''),
            'visibility'    => $this->visibilityOf($s),
            'avatar_url'    => (string)($s['avatar_url'] ?? ''),
            'cover_url'     => (string)($s['cover_url'] ?? ''),
            'owner_id'      => (string)($s['owner_id'] ?? ''),
            'created_at'    => $s['created_at'] ?? null,
            'member_count'  => (int)($idx['counts'][$sid] ?? 0),
            'viewer_status' => $role,
            'is_member'     => $this->isMemberRole($role),
            'is_owner'      => $role === 'owner',
            'can_moderate'  => in_array($role, ['owner', 'moderator'], true),
            'pending_count' => $role === 'owner' ? (int)($idx['pending'][$sid] ?? 0) : 0,
        ];
    }

    // ── Liste / détail ─────────────────────────────────────────────────────
    private function listSpaces(): never
    {
        $u   = $this->me();
        $q   = $this->text($_GET['q'] ?? '', 80);
        $idx = $this->indexMembers();
        $out = [];
        foreach ($this->store->all($this->cfg['table']) as $s) {
            if (!empty($s['deleted'])) continue;
            if ($q !== '' && !$this->contains((string)($s['name'] ?? '') . ' ' . (string)($s['description'] ?? ''), $q)) continue;
            $out[] = $this->decorate($s, $u['id'], $idx);
        }
        usort($out, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
        $this->ok([$this->cfg['key'] => $out]);
    }

    private function detail(string $id): never
    {
        $u     = $this->me();
        $space = $this->getSpace($id);
        $idx   = $this->indexMembers();
        $data  = $this->decorate($space, $u['id'], $idx);
        $users = $this->usersMap();
        $data['owner'] = $this->pub($users[$space['owner_id'] ?? ''] ?? null);
        $data['can_view_content'] = $this->canView($space, $data['viewer_status']);
        $data['can_post'] = $data['is_member'];
        $data['members_preview'] = [];
        if ($data['can_view_content']) {
            $n = 0;
            foreach ($idx['rows'][$id] ?? [] as $uid => $row) {
                if (($row['status'] ?? 'active') === 'pending') continue;
                $data['members_preview'][] = $this->pub($users[$uid] ?? null);
                if (++$n >= 8) break;
            }
        }
        $this->ok([$this->cfg['key'] === 'groups' ? 'group' : 'community' => $data]);
    }

    // ── Création / modification / suppression ─────────────────────────────
    private function create(array $input): never
    {
        $u    = $this->me();
        $name = $this->text($input['name'] ?? '', 60);
        if (mb_strlen($name) < 2) $this->fail('Le nom doit contenir entre 2 et 60 caractères.');
        $vis = 'public';
        if ($this->cfg['private'] && (($input['visibility'] ?? 'public') === 'private')) $vis = 'private';

        $space = [
            'id'          => $this->newId($this->cfg['id']),
            'name'        => $name,
            'description' => $this->text($input['description'] ?? '', 300),
            'visibility'  => $vis,
            'avatar_url'  => '',
            'cover_url'   => '',
            'owner_id'    => $u['id'],
            'created_at'  => date('c'),
            'deleted'     => false,
        ];
        $this->store->insert($this->cfg['table'], $space);
        $this->store->insert($this->cfg['members'], [
            'id' => $this->newId($this->cfg['mid']), $this->cfg['fk'] => $space['id'],
            'user_id' => $u['id'], 'role' => 'owner', 'status' => 'active', 'joined_at' => date('c'),
        ]);
        $data = $this->decorate($space, $u['id'], $this->indexMembers());
        $this->ok([$this->cfg['key'] === 'groups' ? 'group' : 'community' => $data], 201);
    }

    private function requireOwner(string $id): array
    {
        $u     = $this->me();
        $space = $this->getSpace($id);
        if (($space['owner_id'] ?? '') !== $u['id']) {
            $this->fail('Seul le créateur peut effectuer cette action.', 403);
        }
        return [$u, $space];
    }

    private function update(string $id, array $input): never
    {
        [$u, $space] = $this->requireOwner($id);
        $changes = ['updated_at' => date('c')];
        if (isset($input['name'])) {
            $name = $this->text($input['name'], 60);
            if (mb_strlen($name) < 2) $this->fail('Le nom doit contenir entre 2 et 60 caractères.');
            $changes['name'] = $name;
        }
        if (isset($input['description'])) $changes['description'] = $this->text($input['description'], 300);

        $wasPrivate = $this->visibilityOf($space) === 'private';
        if ($this->cfg['private'] && isset($input['visibility']) && in_array($input['visibility'], ['public', 'private'], true)) {
            $changes['visibility'] = (string)$input['visibility'];
            // Passage en public : les demandes en attente n'ont plus lieu d'être, on les accepte.
            if ($wasPrivate && $changes['visibility'] === 'public') {
                $fk = $this->cfg['fk'];
                $this->store->updateWhere($this->cfg['members'],
                    fn($r) => ($r[$fk] ?? '') === $id && ($r['status'] ?? 'active') === 'pending',
                    fn($r) => array_merge($r, ['status' => 'active', 'joined_at' => date('c')])
                );
            }
        }
        $this->store->updateWhere($this->cfg['table'], fn($s) => ($s['id'] ?? '') === $id, fn($s) => array_merge($s, $changes));
        $this->ok(['message' => 'Modifications enregistrées.']);
    }

    private function image(string $id, string $kind): never
    {
        [$u, $space] = $this->requireOwner($id);
        $max = $kind === 'avatar' ? 5 * 1048576 : 8 * 1048576;
        $url = $this->uploadOptional('image', $this->cfg['folder'] . '/' . $kind . 's', $max, false);
        if ($url === null) $this->fail('Sélectionnez une image.');
        $field = $kind === 'avatar' ? 'avatar_url' : 'cover_url';
        $old = (string)($space[$field] ?? '');
        $this->store->updateWhere($this->cfg['table'], fn($s) => ($s['id'] ?? '') === $id, fn($s) => array_merge($s, [$field => $url, 'updated_at' => date('c')]));
        if ($old !== '' && $old !== $url) $this->deleteImage($old);
        $this->ok([$field => $url]);
    }

    private function delete(string $id): never
    {
        [$u, $space] = $this->requireOwner($id);
        $this->store->updateWhere($this->cfg['table'], fn($s) => ($s['id'] ?? '') === $id, fn($s) => array_merge($s, ['deleted' => true, 'deleted_at' => date('c')]));
        $fk = $this->cfg['fk'];
        $this->store->updateWhere('posts', fn($p) => ($p[$fk] ?? '') === $id, fn($p) => array_merge($p, ['deleted' => true]));
        $this->ok();
    }

    // ── Publications ───────────────────────────────────────────────────────
    private function posts(string $id): never
    {
        $u     = $this->me();
        $space = $this->getSpace($id);
        $role  = $this->roleOf($space, $u['id']);
        if (!$this->canView($space, $role)) {
            $this->fail('Ce groupe est privé : seuls ses membres voient les publications.', 403);
        }
        $fk      = $this->cfg['fk'];
        $users   = $this->usersMap();
        $blocked = array_flip($this->blockedIdsFor($u['id']));
        $canMod  = in_array($role, ['owner', 'moderator'], true);
        $posts   = [];
        foreach ($this->store->all('posts') as $p) {
            if (($p[$fk] ?? '') !== $id || !empty($p['deleted'])) continue;
            if (isset($blocked[$p['user_id'] ?? ''])) continue;
            $posts[] = $p;
        }
        usort($posts, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
        $posts = array_slice($posts, 0, 100);
        $out = [];
        foreach ($posts as $p) {
            $out[] = $this->presentPost($p, $users, $u['id'], $canMod);
        }
        $this->ok(['posts' => $out, 'can_moderate' => $canMod]);
    }

    private function presentPost(array $p, array $users, string $uid, bool $canMod): array
    {
        $a  = $users[$p['user_id'] ?? ''] ?? null;
        $rs = is_array($p['reactions'] ?? null) ? $p['reactions'] : [];
        $p['author_name']   = $a ? (($a['display_name'] ?? '') !== '' ? $a['display_name'] : 'Utilisateur') : 'Utilisateur';
        $p['author_avatar'] = $a['avatar_url'] ?? '';
        $p['like_count']    = count(array_filter($rs, fn($r) => ($r['type'] ?? 'like') === 'like'));
        $p['liked_by_me']   = isset($rs[$uid . '_like']);
        $p['can_moderate']  = $canMod;
        unset($p['reactions']);
        return $p;
    }

    private function createPost(string $id, array $input): never
    {
        $u     = $this->me();
        $space = $this->getSpace($id);
        $role  = $this->roleOf($space, $u['id']);
        if (!$this->isMemberRole($role)) {
            $this->fail('Vous devez rejoindre ' . $this->cfg['the'] . ' pour publier.', 403);
        }
        $text = trim((string)($input['content'] ?? ''));
        if ($text === '') $this->fail('Le contenu est obligatoire.');
        $imageUrl = $this->uploadOptional('image', $this->cfg['folder'] . '/posts') ?? '';
        $disc = in_array(($input['ai_disclosure'] ?? 'self'), ['self', 'assisted', 'generated', 'unknown'], true) ? (string)($input['ai_disclosure'] ?? 'self') : 'self';
        $post = [
            'id'             => $this->newId('post_', 10),
            'user_id'        => $u['id'],
            $this->cfg['fk'] => $id,
            'content'        => mb_substr($text, 0, 5000),
            'image_url'      => $imageUrl,
            'ai_disclosure'  => $disc,
            'reactions'      => [],
            'comments_count' => 0,
            'created_at'     => date('c'),
            'edited_at'      => null,
            'deleted'        => false,
        ];
        $this->store->insert('posts', $post);
        $canMod = in_array($role, ['owner', 'moderator'], true);
        $this->ok(['post' => $this->presentPost($post, [$u['id'] => $u], $u['id'], $canMod)], 201);
    }

    // ── Membres ────────────────────────────────────────────────────────────
    private function members(string $id): never
    {
        $u     = $this->me();
        $space = $this->getSpace($id);
        $role  = $this->roleOf($space, $u['id']);
        if (!$this->canView($space, $role)) {
            $this->fail('Ce groupe est privé : la liste des membres est réservée aux membres.', 403);
        }
        $users = $this->usersMap();
        $fk    = $this->cfg['fk'];
        $rank  = ['owner' => 0, 'moderator' => 1, 'member' => 2];
        $out   = [];
        foreach ($this->store->all($this->cfg['members']) as $r) {
            if (($r[$fk] ?? '') !== $id || ($r['status'] ?? 'active') === 'pending') continue;
            $x = $this->pub($users[$r['user_id'] ?? ''] ?? null);
            $x['role'] = (string)($r['role'] ?? 'member');
            if (($space['owner_id'] ?? '') === $x['id']) $x['role'] = 'owner';
            $x['joined_at'] = $r['joined_at'] ?? null;
            $out[] = $x;
        }
        usort($out, fn($a, $b) => ($rank[$a['role']] ?? 2) <=> ($rank[$b['role']] ?? 2));
        $this->ok(['members' => $out, 'viewer_is_owner' => $role === 'owner']);
    }

    private function removeMember(string $id, string $targetId): never
    {
        [$u, $space] = $this->requireOwner($id);
        if ($targetId === $u['id']) $this->fail('Le créateur ne peut pas être exclu.');
        $fk = $this->cfg['fk'];
        $rows = array_values(array_filter($this->store->all($this->cfg['members']),
            fn($r) => !(($r[$fk] ?? '') === $id && ($r['user_id'] ?? '') === $targetId)));
        $this->store->replace($this->cfg['members'], $rows);
        $this->notify($targetId, 'group_removed', 'Vous avez été retiré de ' . $this->cfg['the'] . ' « ' . $space['name'] . ' ».', ['link' => $this->cfg['page']]);
        $this->ok();
    }

    private function setRole(string $id, string $targetId, array $input): never
    {
        [$u, $space] = $this->requireOwner($id);
        $role = (string)($input['role'] ?? '');
        if (!in_array($role, ['moderator', 'member'], true)) $this->fail('Rôle invalide.');
        if ($targetId === $u['id']) $this->fail('Le rôle du créateur ne peut pas être modifié.');
        $row = $this->memberRow($id, $targetId);
        if (!$row || ($row['status'] ?? 'active') === 'pending') $this->fail('Ce membre est introuvable.', 404);
        $fk = $this->cfg['fk'];
        $this->store->updateWhere($this->cfg['members'],
            fn($r) => ($r[$fk] ?? '') === $id && ($r['user_id'] ?? '') === $targetId,
            fn($r) => array_merge($r, ['role' => $role])
        );
        $this->ok(['role' => $role]);
    }

    // ── Adhésion ───────────────────────────────────────────────────────────
    private function join(string $id): never
    {
        $u     = $this->me();
        $space = $this->getSpace($id);
        $role  = $this->roleOf($space, $u['id']);
        if ($this->isMemberRole($role)) $this->ok(['status' => 'member']);
        if ($role === 'pending')        $this->ok(['status' => 'pending']);
        if ($this->isBlocked($u['id'], (string)($space['owner_id'] ?? ''))) {
            $this->fail('Vous ne pouvez pas rejoindre ' . $this->cfg['the'] . '.', 403);
        }
        $fk   = $this->cfg['fk'];
        $priv = $this->visibilityOf($space) === 'private';
        $this->store->insert($this->cfg['members'], [
            'id' => $this->newId($this->cfg['mid']), $fk => $id, 'user_id' => $u['id'], 'role' => 'member',
            'status' => $priv ? 'pending' : 'active',
            ($priv ? 'requested_at' : 'joined_at') => date('c'),
        ]);
        $name = $u['display_name'] ?: 'Un membre';
        if ($priv) {
            $this->notify((string)$space['owner_id'], 'group_request',
                $name . ' demande à rejoindre « ' . $space['name'] . ' ».',
                ['link' => $this->cfg['page'] . '?' . $this->cfg['param'] . '=' . $id . '&tab=requests', 'actor_id' => $u['id']]);
            $this->ok(['status' => 'pending', 'message' => 'Demande envoyée. Le créateur du groupe doit l’approuver.']);
        }
        $this->notify((string)$space['owner_id'], 'group_join',
            $name . ' a rejoint « ' . $space['name'] . ' ».',
            ['link' => $this->cfg['page'] . '?' . $this->cfg['param'] . '=' . $id, 'actor_id' => $u['id']]);
        $this->ok(['status' => 'member', 'message' => 'Vous avez rejoint ' . $this->cfg['the'] . '.']);
    }

    private function cancelRequest(string $id): never
    {
        $u  = $this->me();
        $fk = $this->cfg['fk'];
        $rows = array_values(array_filter($this->store->all($this->cfg['members']),
            fn($r) => !(($r[$fk] ?? '') === $id && ($r['user_id'] ?? '') === $u['id'] && ($r['status'] ?? 'active') === 'pending')));
        $this->store->replace($this->cfg['members'], $rows);
        $this->ok();
    }

    private function leave(string $id): never
    {
        $u     = $this->me();
        $space = $this->getSpace($id);
        if (($space['owner_id'] ?? '') === $u['id']) {
            $this->fail('Le créateur ne peut pas quitter son propre espace : supprimez-le à la place.');
        }
        $fk = $this->cfg['fk'];
        $rows = array_values(array_filter($this->store->all($this->cfg['members']),
            fn($r) => !(($r[$fk] ?? '') === $id && ($r['user_id'] ?? '') === $u['id'])));
        $this->store->replace($this->cfg['members'], $rows);
        $this->ok();
    }

    // ── Demandes d'adhésion (créateur uniquement) ─────────────────────────
    private function requests(string $id): never
    {
        [$u, $space] = $this->requireOwner($id);
        $users = $this->usersMap();
        $fk    = $this->cfg['fk'];
        $out   = [];
        foreach ($this->store->all($this->cfg['members']) as $r) {
            if (($r[$fk] ?? '') !== $id || ($r['status'] ?? 'active') !== 'pending') continue;
            $x = $this->pub($users[$r['user_id'] ?? ''] ?? null);
            $x['requested_at'] = $r['requested_at'] ?? null;
            $out[] = $x;
        }
        usort($out, fn($a, $b) => strcmp((string)($a['requested_at'] ?? ''), (string)($b['requested_at'] ?? '')));
        $this->ok(['requests' => $out]);
    }

    private function decide(string $id, string $targetId, bool $approve): never
    {
        [$u, $space] = $this->requireOwner($id);   // seul le créateur peut décider
        $row = $this->memberRow($id, $targetId);
        if (!$row || ($row['status'] ?? 'active') !== 'pending') {
            $this->fail('Cette demande n’existe plus.', 404);
        }
        $fk = $this->cfg['fk'];
        if ($approve) {
            $this->store->updateWhere($this->cfg['members'],
                fn($r) => ($r[$fk] ?? '') === $id && ($r['user_id'] ?? '') === $targetId,
                fn($r) => array_merge($r, ['status' => 'active', 'joined_at' => date('c')])
            );
            $this->notify($targetId, 'group_approved', 'Votre demande a été acceptée : bienvenue dans « ' . $space['name'] . ' » !',
                ['link' => $this->cfg['page'] . '?' . $this->cfg['param'] . '=' . $id]);
        } else {
            $rows = array_values(array_filter($this->store->all($this->cfg['members']),
                fn($r) => !(($r[$fk] ?? '') === $id && ($r['user_id'] ?? '') === $targetId)));
            $this->store->replace($this->cfg['members'], $rows);
            $this->notify($targetId, 'group_rejected', 'Votre demande pour rejoindre « ' . $space['name'] . ' » a été refusée.',
                ['link' => $this->cfg['page']]);
        }
        $this->ok(['approved' => $approve]);
    }
}
