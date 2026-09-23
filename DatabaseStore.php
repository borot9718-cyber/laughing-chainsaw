<?php
declare(strict_types=1);

namespace Kova\Core;

use PDO;
use PDOException;

/**
 * Stockage SQL compatible avec le contrat historique de JsonStore.
 * Chaque collection conserve ses enregistrements dans une ligne JSON afin de
 * préserver les champs dynamiques de la plateforme sans réécrire les modules.
 */
final class DatabaseStore extends JsonStore
{
    private PDO $pdo;
    private string $table;

    public function __construct(PDO $pdo, string $table = 'kova_records')
    {
        // Le parent n'est pas utilisé, mais l'appel évite toute dépendance à un
        // répertoire local lorsque l'héritage est chargé par PHP.
        parent::__construct(sys_get_temp_dir() . '/kova-db-store-' . bin2hex(random_bytes(4)));
        $this->pdo = $pdo;
        $this->table = $this->identifier($table);
        $this->ensureSchema();
    }

    public static function fromEnv(Env $env): self
    {
        $dsn = trim((string)$env->get('DB_DSN', ''));
        $user = (string)$env->get('DB_USERNAME', $env->get('DB_USER', ''));
        $password = (string)$env->get('DB_PASSWORD', '');
        if ($dsn === '') {
            $driver = strtolower((string)$env->get('DB_DRIVER', 'mysql'));
            if ($driver === 'sqlite') {
                $path = (string)$env->get('DB_DATABASE', dirname(__DIR__, 2) . '/storage/database.sqlite');
                $dsn = 'sqlite:' . $path;
            } else {
                $host = (string)$env->get('DB_HOST', '127.0.0.1');
                $port = (string)$env->get('DB_PORT', '3306');
                $database = (string)$env->get('DB_DATABASE', 'kova');
                $charset = (string)$env->get('DB_CHARSET', 'utf8mb4');
                $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
            }
        }
        try {
            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException('Connexion à la base de données impossible : ' . $e->getMessage(), 0, $e);
        }
        return new self($pdo, (string)$env->get('DB_TABLE', 'kova_records'));
    }

    private function ensureSchema(): void
    {
        $driver = strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'sqlite') {
            $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (collection TEXT NOT NULL, record_id TEXT NOT NULL, payload TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, PRIMARY KEY (collection, record_id))";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (collection VARCHAR(120) NOT NULL, record_id VARCHAR(191) NOT NULL, payload LONGTEXT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (collection, record_id), INDEX idx_{$this->table}_collection (collection)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        }
        $this->pdo->exec($sql);
    }

    public function all(string $name): array
    {
        $stmt = $this->pdo->prepare("SELECT payload FROM {$this->table} WHERE collection = :collection ORDER BY created_at ASC, record_id ASC");
        $stmt->execute(['collection' => $this->collection($name)]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $decoded = json_decode((string)$row['payload'], true);
            if (is_array($decoded)) $rows[] = $decoded;
        }
        return $rows;
    }

    public function replace(string $name, array $data): void
    {
        $collection = $this->collection($name);
        $this->transaction(function () use ($collection, $data): void {
            $delete = $this->pdo->prepare("DELETE FROM {$this->table} WHERE collection = :collection");
            $delete->execute(['collection' => $collection]);
            $this->insertRows($collection, array_values($data));
        });
    }

    public function locked(string $name, callable $fn): mixed
    {
        return $fn();
    }

    public function mutate(string $name, callable $fn): void
    {
        $this->transaction(function () use ($name, $fn): void {
            $data = $fn($this->all($name));
            if (is_array($data)) $this->replaceWithoutTransaction($this->collection($name), array_values($data));
        });
    }

    public function insert(string $name, array $record): array
    {
        $record = $this->normaliseRecord($record);
        $collection = $this->collection($name);
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("INSERT INTO {$this->table} (collection, record_id, payload, created_at, updated_at) VALUES (:collection, :record_id, :payload, :created_at, :updated_at)");
        $stmt->execute(['collection' => $collection, 'record_id' => (string)$record['id'], 'payload' => $this->encode($record), 'created_at' => $now, 'updated_at' => $now]);
        return $record;
    }

    public function updateWhere(string $name, callable $predicate, callable $mutator): int
    {
        $collection = $this->collection($name);
        $count = 0;
        $this->transaction(function () use ($collection, $predicate, $mutator, &$count): void {
            $stmt = $this->pdo->prepare("SELECT record_id, payload FROM {$this->table} WHERE collection = :collection ORDER BY created_at ASC, record_id ASC");
            $stmt->execute(['collection' => $collection]);
            $update = $this->pdo->prepare("UPDATE {$this->table} SET payload = :payload, updated_at = :updated_at WHERE collection = :collection AND record_id = :record_id");
            foreach ($stmt->fetchAll() as $row) {
                $record = json_decode((string)$row['payload'], true);
                if (!is_array($record) || !$predicate($record)) continue;
                $next = $this->normaliseRecord((array)$mutator($record));
                $update->execute(['payload' => $this->encode($next), 'updated_at' => date('Y-m-d H:i:s'), 'collection' => $collection, 'record_id' => (string)$row['record_id']]);
                $count++;
            }
        });
        return $count;
    }

    private function replaceWithoutTransaction(string $collection, array $data): void
    {
        $delete = $this->pdo->prepare("DELETE FROM {$this->table} WHERE collection = :collection");
        $delete->execute(['collection' => $collection]);
        $this->insertRows($collection, $data);
    }

    private function insertRows(string $collection, array $data): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO {$this->table} (collection, record_id, payload, created_at, updated_at) VALUES (:collection, :record_id, :payload, :created_at, :updated_at)");
        $now = date('Y-m-d H:i:s');
        foreach ($data as $record) {
            if (!is_array($record)) continue;
            $record = $this->normaliseRecord($record);
            $stmt->execute(['collection' => $collection, 'record_id' => (string)$record['id'], 'payload' => $this->encode($record), 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    private function normaliseRecord(array $record): array
    {
        if (empty($record['id'])) $record['id'] = 'rec_' . bin2hex(random_bytes(12));
        return $record;
    }

    private function encode(array $record): string
    {
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new \RuntimeException('Impossible de sérialiser un enregistrement.');
        return $json;
    }

    private function transaction(callable $fn): void
    {
        $outer = $this->pdo->inTransaction();
        if (!$outer) $this->pdo->beginTransaction();
        try { $fn(); if (!$outer) $this->pdo->commit(); }
        catch (\Throwable $e) { if (!$outer && $this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    private function collection(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) throw new \InvalidArgumentException('Nom de collection invalide');
        return $name;
    }

    private function identifier(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) throw new \InvalidArgumentException('Nom de table invalide');
        return $name;
    }
}

namespace Kova\Core;

// La classe historique est conservée comme contrat partagé par les modules.
if (!class_exists(JsonStore::class, false)) { require_once __DIR__ . '/JsonStore.php'; }
