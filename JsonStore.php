<?php
declare(strict_types=1);

namespace Kova\Core;

/**
 * Stockage JSON fichier par fichier.
 *
 * Renforcements :
 *  - dossier en 0700 et fichiers en 0600 ;
 *  - un .htaccess « Require all denied » (+ index.html) est recréé automatiquement si absent :
 *    le dossier storage/ peut être exclu d'un déploiement sans exposer les données ;
 *  - insert() et updateWhere() se font sous verrou exclusif (flock) : deux requêtes simultanées ne
 *    s'écrasent plus mutuellement (lecture-modification-écriture atomique) ;
 *  - écriture atomique via fichier temporaire unique puis rename().
 *
 * Limite assumée : ce n'est pas une base de données transactionnelle. Les appelants qui font
 * eux-mêmes all() puis replace() restent exposés à une écriture concurrente ; utilisez mutate()
 * pour toute nouvelle opération lecture-modification-écriture.
 */
class JsonStore
{
    /** @var array<string,resource> */
    private array $locks = [];

    public function __construct(private string $dir)
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        @chmod($dir, 0700);
        $this->protectDirectory();
    }

    private function protectDirectory(): void
    {
        $ht = rtrim($this->dir, '/\\') . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "# Données privées : jamais servies par le serveur web.\nRequire all denied\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
        }
        $ix = rtrim($this->dir, '/\\') . '/index.html';
        if (!is_file($ix)) @file_put_contents($ix, '');
    }

    public function all(string $name): array
    {
        $file = $this->file($name);
        if (!is_file($file)) return [];
        $json = file_get_contents($file);
        $data = json_decode($json ?: '[]', true);
        return is_array($data) ? $data : [];
    }

    public function replace(string $name, array $data): void
    {
        $file = $this->file($name);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new \RuntimeException('Impossible de sérialiser '.$name);
        if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new \RuntimeException('Écriture impossible : '.$name);
        @chmod($tmp, 0600);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('Remplacement impossible : '.$name);
        }
        @chmod($file, 0600);
    }

    /** Verrou exclusif sur une collection pendant l'exécution de $fn (ré-entrant). */
    public function locked(string $name, callable $fn): mixed
    {
        $file = $this->file($name);
        if (isset($this->locks[$file])) return $fn();      // déjà verrouillé par cet appel
        $fh = @fopen($file . '.lock', 'c');
        if ($fh === false) return $fn();                   // pas de verrou possible : comportement d'origine
        @chmod($file . '.lock', 0600);
        flock($fh, LOCK_EX);
        $this->locks[$file] = $fh;
        try {
            return $fn();
        } finally {
            unset($this->locks[$file]);
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** Lecture-modification-écriture atomique : $fn reçoit la liste et retourne la nouvelle liste. */
    public function mutate(string $name, callable $fn): void
    {
        $this->locked($name, function () use ($name, $fn) {
            $data = $fn($this->all($name));
            if (is_array($data)) $this->replace($name, array_values($data));
            return null;
        });
    }

    public function insert(string $name, array $record): array
    {
        return $this->locked($name, function () use ($name, $record) {
            $data = $this->all($name);
            $data[] = $record;
            $this->replace($name, $data);
            return $record;
        });
    }

    public function updateWhere(string $name, callable $predicate, callable $mutator): int
    {
        return (int)$this->locked($name, function () use ($name, $predicate, $mutator) {
            $data = $this->all($name);
            $count = 0;
            foreach ($data as $i => $row) {
                if ($predicate($row)) {
                    $data[$i] = $mutator($row);
                    $count++;
                }
            }
            if ($count) $this->replace($name, $data);
            return $count;
        });
    }

    private function file(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            throw new \InvalidArgumentException('Nom JSON invalide');
        }
        return rtrim($this->dir, '/\\') . '/' . $name . '.json';
    }
}
