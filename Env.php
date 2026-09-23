<?php
declare(strict_types=1);

namespace Kova\Core;

final class Env
{
    private array $data = [];

    public function __construct(string $file)
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }
            $this->data[trim($key)] = $value;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? $this->data[$key] ?? null;
        return $v === null || $v === '' ? $default : $v;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = strtolower((string)$this->get($key, $default ? 'true' : 'false'));
        return in_array($v, ['1','true','yes','on'], true);
    }
}
