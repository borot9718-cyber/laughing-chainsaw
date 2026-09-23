<?php
declare(strict_types=1);

namespace Kova\Core;

/**
 * Base commune des modules d'API (groupes, messagerie, boutique, OAuth…).
 * Chaque module expose handle() : s'il reconnaît la route, il répond via
 * Response::json() (qui termine la requête) ; sinon il retourne sans rien faire.
 */
abstract class Module
{
    public function __construct(
        protected App $app,
        protected JsonStore $store,
        protected Auth $auth,
        protected Env $env,
        protected Cloudinary $cloud
    ) {}

    abstract public function handle(string $path, string $method, array $input): void;

    // ── Réponses ───────────────────────────────────────────────────────────
    protected function ok(array $extra = [], int $status = 200): never
    {
        Response::json(array_merge(['ok' => true], $extra), $status);
    }

    protected function fail(string $message, int $status = 422): never
    {
        Response::json(['ok' => false, 'error' => $message], $status);
    }

    /** Utilisateur connecté et actif, sinon 401. */
    protected function me(): array
    {
        $u = $this->auth->user();
        if (!$u) $this->fail('Authentification requise.', 401);
        return $u;
    }

    // ── Données ────────────────────────────────────────────────────────────
    protected function newId(string $prefix, int $bytes = 8): string
    {
        return $prefix . bin2hex(random_bytes($bytes));
    }

    protected function findById(string $table, string $id): ?array
    {
        foreach ($this->store->all($table) as $row) {
            if (($row['id'] ?? '') === $id) return $row;
        }
        return null;
    }

    /** @return array<string,array> id => utilisateur */
    protected function usersMap(): array
    {
        $map = [];
        foreach ($this->store->all('users') as $u) {
            if (isset($u['id'])) $map[$u['id']] = $u;
        }
        return $map;
    }

    /** Vue publique et minimale d'un utilisateur (jamais d'e-mail ni de hash). */
    protected function pub(?array $u): array
    {
        if (!$u) {
            return ['id' => '', 'display_name' => 'Utilisateur', 'avatar_url' => '', 'bio' => ''];
        }
        return [
            'id'           => (string)($u['id'] ?? ''),
            'display_name' => (string)(($u['display_name'] ?? '') !== '' ? $u['display_name'] : 'Utilisateur'),
            'avatar_url'   => (string)($u['avatar_url'] ?? ''),
            'bio'          => (string)($u['bio'] ?? ''),
        ];
    }

    protected function text(mixed $value, int $max): string
    {
        return mb_substr(trim((string)$value), 0, $max);
    }

    protected function lower(string $s): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    protected function contains(string $haystack, string $needle): bool
    {
        return $needle === '' || str_contains($this->lower($haystack), $this->lower($needle));
    }

    /** Vrai si l'un des deux utilisateurs a bloqué l'autre. */
    protected function isBlocked(string $a, string $b): bool
    {
        foreach ($this->store->all('blocks') as $row) {
            $x = $row['blocker_id'] ?? '';
            $y = $row['blocked_id'] ?? '';
            if (($x === $a && $y === $b) || ($x === $b && $y === $a)) return true;
        }
        return false;
    }

    /** Identifiants que $userId a bloqués ou qui l'ont bloqué. */
    protected function blockedIdsFor(string $userId): array
    {
        $ids = [];
        foreach ($this->store->all('blocks') as $row) {
            if (($row['blocker_id'] ?? '') === $userId) $ids[$row['blocked_id'] ?? ''] = true;
            if (($row['blocked_id'] ?? '') === $userId) $ids[$row['blocker_id'] ?? ''] = true;
        }
        unset($ids['']);
        return array_keys($ids);
    }

    protected function notify(string $userId, string $type, string $message, array $meta = []): void
    {
        $this->app->addNotification($userId, $type, $message, $meta);
    }

    /**
     * Traite un fichier image optionnel envoyé sous $field (voir ImageStore pour les contrôles).
     * Retourne l'URL de l'image, ou null s'il n'y a pas de fichier.
     * Termine la requête avec une erreur claire si le fichier est invalide.
     */
    protected function uploadOptional(string $field, string $folderSuffix, int $maxBytes = 8388608, bool $allowGif = true): ?string
    {
        $file = $_FILES[$field] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || empty($file['tmp_name'])) {
            return null;
        }
        if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) $this->fail('Échec de la réception du fichier.');
        $r = (new ImageStore($this->env, $this->cloud))->save((string)$file['tmp_name'], $folderSuffix, $maxBytes, $allowGif);
        if ($r['url'] === null) $this->fail((string)$r['error'], (int)$r['status']);
        return $r['url'];
    }

    /** Supprime une image stockée par KOVA (locale ou Cloudinary). */
    protected function deleteImage(?string $url): void
    {
        if ($url) (new ImageStore($this->env, $this->cloud))->delete($url);
    }
}
