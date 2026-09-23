<?php
declare(strict_types=1);

namespace Kova\Core;

final class Debug
{
    private array $errors = [];

    public function __construct(private Env $env) {}

    public function boot(): void
    {
        date_default_timezone_set((string)$this->env->get('APP_TIMEZONE', 'Europe/Paris'));

        if ($this->enabled()) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
            ini_set('display_startup_errors', '1');
            ini_set('log_errors', '1');
        } else {
            error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
            ini_set('log_errors', '1');
        }

        set_error_handler(function(int $severity, string $message, string $file, int $line): bool {
            $entry = compact('severity','message','file','line');
            $this->errors[] = $entry;
            $this->writeLog($entry);
            if ($this->enabled()) {
                echo '<div class="kova-debug-error"><strong>PHP '.$severity.'</strong> '
                    . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
                    . ' <small>'.htmlspecialchars($file, ENT_QUOTES, 'UTF-8').':'.$line.'</small></div>';
            }
            return true;
        });

        set_exception_handler(function(\Throwable $e): void {
            $this->writeLog([
                'type' => 'exception',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            http_response_code(500);
            if ($this->enabled()) {
                echo '<!doctype html><html><head><meta charset="utf-8"><title>KOVA Debug</title>'
                    . '<link rel="stylesheet" href="/assets/css/kova.css"></head><body class="debug-page">'
                    . '<main class="debug-card"><div class="brand-mark">K</div><h1>Erreur KOVA</h1>'
                    . '<p>'.htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8').'</p>'
                    . '<pre>'.htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8').'</pre>'
                    . '</main></body></html>';
            } else {
                echo 'Une erreur interne est survenue.';
            }
        });

        register_shutdown_function(function(): void {
            $last = error_get_last();
            if ($last && in_array($last['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true)) {
                $this->writeLog(['type'=>'fatal','error'=>$last]);
            }
        });
    }

    public function enabled(): bool
    {
        return $this->env->bool('APP_DEBUG', false);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    private function writeLog(array $entry): void
    {
        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents(
            $dir . '/php-' . date('Y-m-d') . '.log',
            '[' . date('c') . '] ' . json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
