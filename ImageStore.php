<?php
declare(strict_types=1);

namespace Kova\Core;

/**
 * Réception sécurisée des images (F-07 de l'audit).
 *
 * Contrôles côté serveur, dans cet ordre :
 *  1. le fichier vient réellement d'un envoi HTTP (is_uploaded_file) ;
 *  2. taille plafonnée ; type RÉEL déterminé par le contenu (getimagesize), jamais par le nom ni
 *     le type annoncé par le navigateur ;
 *  3. dimensions plafonnées (protection contre les « bombes de décompression ») ;
 *  4. RÉ-ENCODAGE complet via GD : les métadonnées (EXIF/GPS), les données ajoutées en fin de
 *     fichier et les « polyglottes » (image + script) sont détruits ; l'orientation EXIF est
 *     appliquée avant suppression pour que les photos de téléphone ne soient pas tournées ;
 *  4b. sans GD : refus de tout fichier contenant des balises de script/PHP ;
 *  5. nom aléatoire généré par le serveur (le nom d'origine n'est jamais utilisé).
 *
 * Stockage : Cloudinary si configuré et joignable ; sinon dossier local uploads/img/ (servi sans
 * exécution de script, voir uploads/.htaccess). Si l'hébergeur bloque les connexions sortantes,
 * un « disjoncteur » de 10 min évite d'attendre l'échec à chaque envoi.
 * IMAGE_STORAGE dans .env : auto (défaut) | local | cloudinary.
 */
final class ImageStore
{
    private const MAX_PIXELS = 25_000_000;
    private const MAX_SIDE   = 2560;
    private const BREAKER_SECONDS = 600;

    public function __construct(private Env $env, private Cloudinary $cloud) {}

    private function root(): string { return dirname(__DIR__, 2); }
    private function uploadDir(): string { return $this->root() . '/uploads/img'; }
    private function breakerFile(): string { return $this->root() . '/storage/cloud_down.flag'; }

    /** @return array{url:?string,error:?string,status:int} */
    public function saveRemote(string $url, string $folderSuffix, array $allowedHosts, int $maxBytes = 8388608, bool $allowGif = true): array
    {
        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || !in_array($host, array_map('strtolower', $allowedHosts), true)) return $this->err('Source image non autorisée.', 422);
        if (!extension_loaded('curl')) return $this->err('Téléchargement d’image indisponible.', 502);
        $dir = $this->uploadDir(); if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $tmp = tempnam(is_writable($dir) ? $dir : sys_get_temp_dir(), '.kova_remote_');
        if ($tmp === false) return $this->err('Fichier temporaire indisponible.', 502);
        $fp = @fopen($tmp, 'wb');
        if ($fp === false) { @unlink($tmp); return $this->err('Fichier temporaire indisponible.', 502); }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_FILE=>$fp, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>20, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_USERAGENT=>'KOVA Image Importer/1.0']);
        $ok = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $length = (int)curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD); curl_close($ch); fclose($fp);
        if ($ok === false || $status < 200 || $status >= 300 || $length > $maxBytes) { @unlink($tmp); return $this->err('Téléchargement de l’image impossible ou trop lourd.', 422); }
        $info = @getimagesize($tmp); $size = (int)@filesize($tmp);
        if (!is_array($info) || $size < 100 || $size > $maxBytes) { @unlink($tmp); return $this->err('Le fichier distant n’est pas une image valide.', 422); }
        [$w, $h, $type] = $info; $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png']; if (defined('IMAGETYPE_WEBP')) $allowed[IMAGETYPE_WEBP] = 'webp'; if ($allowGif) $allowed[IMAGETYPE_GIF] = 'gif';
        if (!isset($allowed[$type]) || $w < 16 || $h < 16 || $w * $h > self::MAX_PIXELS) { @unlink($tmp); return $this->err('Dimensions ou format d’image non autorisés.', 422); }
        $clean = $this->sanitize($tmp, (int)$type, (int)$w, (int)$h); @unlink($tmp);
        if ($clean === null) return $this->err('Nettoyage ou compression de l’image impossible.', 422);
        $stored = $this->store($clean['data'], $clean['ext'], $folderSuffix);
        return $stored !== null ? ['url'=>$stored,'error'=>null,'status'=>200] : $this->err('Échec du stockage cloud de l’image.', 502);
    }

    /** Import legacy contrôlé : HTTPS uniquement, hôte public, puis mêmes contrôles que l’import gateway. */
    public function saveLegacyRemote(string $url, string $folderSuffix, int $maxBytes = 8388608, bool $allowGif = true): array
    {
        $parts = parse_url($url); $host = strtolower((string)($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) return $this->err('URL legacy non autorisée.', 422);
        $ip = gethostbyname($host);
        if ($ip === $host || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return $this->err('Hôte image non public.', 422);
        return $this->saveRemote($url, $folderSuffix, [$host], $maxBytes, $allowGif);
    }

    /** Réception d’octets transmis par une passerelle de confiance, puis même validation/compression que pour un upload. */
    public function saveBytes(string $bytes, string $folderSuffix, int $maxBytes = 8388608, bool $allowGif = true): array
    {
        if ($bytes === '' || strlen($bytes) > $maxBytes) return $this->err('Image vide ou trop lourde.', 422);
        $dir = $this->uploadDir(); if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $tmp = tempnam(is_writable($dir) ? $dir : sys_get_temp_dir(), '.kova_bytes_');
        if ($tmp === false || @file_put_contents($tmp, $bytes) === false) { if ($tmp) @unlink($tmp); return $this->err('Fichier temporaire indisponible.', 502); }
        $info = @getimagesize($tmp); $size = (int)@filesize($tmp);
        if (!is_array($info) || $size < 100 || $size > $maxBytes) { @unlink($tmp); return $this->err('Le contenu reçu n’est pas une image valide.', 422); }
        [$w, $h, $type] = $info; $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png']; if (defined('IMAGETYPE_WEBP')) $allowed[IMAGETYPE_WEBP] = 'webp'; if ($allowGif) $allowed[IMAGETYPE_GIF] = 'gif';
        if (!isset($allowed[$type]) || $w < 16 || $h < 16 || $w * $h > self::MAX_PIXELS) { @unlink($tmp); return $this->err('Dimensions ou format d’image non autorisés.', 422); }
        $clean = $this->sanitize($tmp, (int)$type, (int)$w, (int)$h); @unlink($tmp);
        if ($clean === null) return $this->err('Nettoyage ou compression de l’image impossible.', 422);
        $stored = $this->store($clean['data'], $clean['ext'], $folderSuffix);
        return $stored !== null ? ['url'=>$stored,'error'=>null,'status'=>200] : $this->err('Échec du stockage cloud de l’image.', 502);
    }

    /** @return array{url:?string,error:?string,status:int} */
    public function save(string $tmpPath, string $folderSuffix, int $maxBytes = 8388608, bool $allowGif = true): array
    {
        if (!is_file($tmpPath) || !is_uploaded_file($tmpPath)) return $this->err('Fichier invalide.');
        $size = (int)filesize($tmpPath);
        if ($size < 100) return $this->err('Fichier vide ou corrompu.');
        if ($size > $maxBytes) return $this->err('Image trop lourde (' . (int)round($maxBytes / 1048576) . ' Mo maximum).');

        $info = @getimagesize($tmpPath);
        if (!is_array($info)) return $this->err('Ce fichier n’est pas une image valide.');
        [$w, $h, $type] = $info;
        $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'];
        if (defined('IMAGETYPE_WEBP')) $allowed[IMAGETYPE_WEBP] = 'webp';
        if ($allowGif) $allowed[IMAGETYPE_GIF] = 'gif';
        if (!isset($allowed[$type])) return $this->err('Format accepté : JPEG, PNG, WEBP' . ($allowGif ? ' ou GIF' : '') . '.');
        if ($w < 16 || $h < 16) return $this->err('Image trop petite.');
        if ($w * $h > self::MAX_PIXELS) return $this->err('Image trop grande (dimensions). Réduisez-la avant l’envoi.');

        $clean = $this->sanitize($tmpPath, (int)$type, (int)$w, (int)$h);
        if ($clean === null) {
            error_log('[KOVA ImageStore] ré-encodage impossible : ' . $this->why);
            return $this->err($this->why === 'memoire'
                ? 'Image trop grande pour le serveur : réduisez sa taille (ou prenez-la en résolution plus basse) puis réessayez.'
                : 'Image illisible ou non conforme.');
        }
        $url = $this->store($clean['data'], $clean['ext'], $folderSuffix);
        return $url !== null ? ['url' => $url, 'error' => null, 'status' => 200] : $this->err('Échec de l’enregistrement de l’image. Réessayez.', 502);
    }

    private string $why = '';

    private function err(string $m, int $status = 422): array { return ['url' => null, 'error' => $m, 'status' => $status]; }

    private function memoryLimitBytes(): int
    {
        $v = trim((string)ini_get('memory_limit'));
        if ($v === '' || $v === '-1') return PHP_INT_MAX;
        $n = (int)$v;
        return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
    }

    /**
     * Ré-encode l'image ENTIÈREMENT EN MÉMOIRE (aucun fichier temporaire : sur un hébergement mutualisé,
     * tempnam()/open_basedir font souvent échouer un traitement par fichier).
     * @return array{data:string,ext:string}|null
     */
    private function sanitize(string $tmp, int $type, int $w, int $h): ?array
    {
        $this->why = '';
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'png'][$type] ?? 'webp';
        $raw = @file_get_contents($tmp);
        if ($raw === false || $raw === '') { $this->why = 'lecture'; return null; }

        if (!function_exists('imagecreatefromstring')) {
            // Sans GD : contrôle par signature — refuse tout contenu exécutable/scriptable.
            if (preg_match('/<\?(php|=)|<script|<html|__HALT_COMPILER/i', $raw)) { $this->why = 'contenu scriptable'; return null; }
            return ['data' => $raw, 'ext' => $type === IMAGETYPE_GIF ? 'gif' : $ext];
        }

        // La décompression d'une image prend ~5 octets par pixel : on vérifie avant, pour un message clair.
        @ini_set('memory_limit', '256M');
        $need = $w * $h * 5 + strlen($raw) + 8 * 1048576;
        if ($need > $this->memoryLimitBytes() - memory_get_usage(true)) { $this->why = 'memoire'; return null; }

        $img = @imagecreatefromstring($raw);
        unset($raw);
        if ($img === false) { $this->why = 'décodage GD refusé'; return null; }

        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($tmp);
            $angle = [3 => 180, 6 => -90, 8 => 90][(int)($exif['Orientation'] ?? 1)] ?? 0;
            if ($angle !== 0 && function_exists('imagerotate')) {
                $r = @imagerotate($img, $angle, 0);
                if ($r !== false) { imagedestroy($img); $img = $r; }
            }
        }
        $nw = imagesx($img);
        $nh = imagesy($img);
        if (max($nw, $nh) > self::MAX_SIDE) {
            $scale = self::MAX_SIDE / max($nw, $nh);
            $resized = @imagescale($img, max(1, (int)round($nw * $scale)), max(1, (int)round($nh * $scale)));
            if ($resized !== false) { imagedestroy($img); $img = $resized; }
        }

        ob_start();                                   // encodage vers la mémoire, pas vers un fichier
        $ok = false;
        if ($ext === 'jpg') {
            $ok = @imagejpeg($img, null, 86);
        } elseif ($ext === 'webp' && function_exists('imagewebp')) {
            $ok = @imagewebp($img, null, 86);
        } else {
            imagealphablending($img, false);
            imagesavealpha($img, true);
            $ok = @imagepng($img, null, 6);
            $ext = 'png';
        }
        $data = (string)ob_get_clean();
        imagedestroy($img);
        if (!$ok || strlen($data) < 100) { $this->why = 'encodage'; return null; }
        return ['data' => $data, 'ext' => $ext];
    }

    private function breakerOpen(): bool
    {
        $f = $this->breakerFile();
        return is_file($f) && (int)@file_get_contents($f) > time();
    }

    private function trip(): void
    {
        $f = $this->breakerFile();
        @mkdir(dirname($f), 0700, true);
        @file_put_contents($f, (string)(time() + self::BREAKER_SECONDS), LOCK_EX);
    }

    /** Écrit les octets dans un fichier temporaire (dossier inscriptible trouvé automatiquement), l'utilise, le supprime. */
    private function withTempFile(string $data, string $ext, callable $fn): mixed
    {
        foreach ([sys_get_temp_dir(), $this->uploadDir(), $this->root() . '/storage'] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) continue;
            if (!is_writable($dir)) continue;
            $f = rtrim($dir, '/') . '/.kova_' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (@file_put_contents($f, $data) === false) continue;
            try { return $fn($f); } finally { @unlink($f); }
        }
        return null;
    }

    private function store(string $data, string $ext, string $folderSuffix): ?string
    {
        $mode = strtolower((string)$this->env->get('IMAGE_STORAGE', 'auto'));
        $cloudOk = $mode !== 'local' && $this->cloud->configured() && extension_loaded('curl');
        if ($cloudOk && $mode === 'auto' && $this->breakerOpen()) $cloudOk = false;

        if ($cloudOk) {
            $folder = trim((string)$this->env->get('CLOUDINARY_FOLDER', 'kova'), '/') . '/' . $folderSuffix;
            $url = $this->withTempFile($data, $ext, fn(string $f) => $this->cloud->uploadImage($f, bin2hex(random_bytes(8)) . '.' . $ext, $folder));
            if (is_string($url) && $url !== '') return $url;
            if ($mode === 'cloudinary') return null;
            $this->trip();        // hébergeur qui bloque le sortant : on bascule en local un moment
        }
        return $this->storeLocal($data, $ext);
    }

    private function storeLocal(string $data, string $ext): ?string
    {
        $sub = date('Y') . '/' . date('m');
        $dir = $this->uploadDir() . '/' . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { error_log('[KOVA ImageStore] dossier non inscriptible : ' . $dir); return null; }
        $name = bin2hex(random_bytes(12)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        if (@file_put_contents($dest, $data) === false) { error_log('[KOVA ImageStore] écriture impossible : ' . $dest); return null; }
        @chmod($dest, 0644);
        return '/uploads/img/' . $sub . '/' . $name;
    }

    /** Supprime une image que KOVA a stockée (locale ou Cloudinary). Toute autre URL est ignorée. */
    public function delete(string $url): bool
    {
        if ($url === '') return false;
        if (str_starts_with($url, '/uploads/img/')) {
            $base = realpath($this->uploadDir());
            $path = realpath($this->root() . $url);
            if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) return false;
            return is_file($path) && @unlink($path);
        }
        return $this->cloud->deleteImageUrl($url);
    }
}
