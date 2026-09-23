<?php
declare(strict_types=1);

function studio_env(string $key, mixed $default = null): mixed {
    static $data;
    if ($data === null) {
        $data = [];
        $file = dirname(__DIR__) . '/.env.studio';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $data[trim($k)] = trim($v, " \t\"'");
            }
        }
    }
    return $_ENV[$key] ?? $_SERVER[$key] ?? $data[$key] ?? $default;
}

function studio_json(string $name): array {
    $dir = dirname(__DIR__) . '/' . trim((string)studio_env('STUDIO_STORAGE_PATH', 'storage/studio'), '/');
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = $dir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $name) . '.json';
    if (!is_file($file)) return [];
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function studio_save(string $name, array $data): void {
    $dir = dirname(__DIR__) . '/' . trim((string)studio_env('STUDIO_STORAGE_PATH', 'storage/studio'), '/');
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = $dir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $name) . '.json';
    $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, json_encode(array_values($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    @chmod($tmp, 0600); rename($tmp, $file); @chmod($file, 0600);
}

function studio_api(callable $fn): never {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try { echo json_encode($fn(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    catch (Throwable $e) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Erreur serveur Studio']); error_log('[KOVA Studio] '.$e->getMessage()); }
    exit;
}

function studio_input(): array {
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $_POST;
}
