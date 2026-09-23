<?php
declare(strict_types=1);

namespace Kova\Core;

final class View
{
    public static function render(string $view, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        $file = dirname(__DIR__, 2) . '/resources/views/' . $view . '.php';
        if (!is_file($file)) throw new \RuntimeException('Vue introuvable: '.$view);
        require $file;
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}
