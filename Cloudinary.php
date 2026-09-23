<?php
declare(strict_types=1);

namespace Kova\Core;

/**
 * Upload d'images vers Cloudinary via l'API HTTP signée, sans dépendance au
 * SDK officiel (juste cURL, déjà présent sur la quasi-totalité des
 * hébergements PHP, y compris les hébergements gratuits).
 * Doc : https://cloudinary.com/documentation/upload_images#authenticated_requests
 */
final class Cloudinary
{
    public function __construct(private Env $env) {}

    public function configured(): bool
    {
        return $this->env->get('CLOUDINARY_CLOUD_NAME', '') !== ''
            && $this->env->get('CLOUDINARY_API_KEY', '') !== ''
            && $this->env->get('CLOUDINARY_API_SECRET', '') !== '';
    }

    /**
     * Envoie un fichier temporaire (issu de $_FILES) vers Cloudinary et
     * renvoie l'URL sécurisée (https) de l'image hébergée, ou null en cas
     * d'échec (voir error_log pour le détail).
     */
    public function uploadImage(string $tmpPath, string $originalName, ?string $targetFolder = null): ?string
    {
        if (!$this->configured() || !is_file($tmpPath) || !extension_loaded('curl')) {
            return null;
        }

        $cloudName = (string)$this->env->get('CLOUDINARY_CLOUD_NAME', '');
        $apiKey    = (string)$this->env->get('CLOUDINARY_API_KEY', '');
        $apiSecret = (string)$this->env->get('CLOUDINARY_API_SECRET', '');
        $folder    = trim((string)($targetFolder ?? $this->env->get('CLOUDINARY_FOLDER', 'kova')), '/');

        $timestamp = (string)time();
        $params    = ['folder' => $folder, 'timestamp' => $timestamp];
        // La signature Cloudinary porte sur les paramètres triés, hors
        // file/api_key/signature/resource_type, au format clé=valeur.
        ksort($params);
        $toSign = '';
        foreach ($params as $k => $v) {
            $toSign .= ($toSign === '' ? '' : '&') . $k . '=' . $v;
        }
        $signature = sha1($toSign . $apiSecret);

        $mime = @mime_content_type($tmpPath) ?: 'application/octet-stream';
        $file = new \CURLFile($tmpPath, $mime, $originalName);

        $ch = curl_init("https://api.cloudinary.com/v1_1/{$cloudName}/image/upload");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 5,      // hébergeur qui bloque le sortant : échec rapide, pas 20 s d'attente
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_POSTFIELDS     => [
                'file'      => $file,
                'api_key'   => $apiKey,
                'timestamp' => $timestamp,
                'signature' => $signature,
                'folder'    => $folder,
            ],
        ]);
        $response = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $curlErr !== '') {
            error_log('[KOVA Cloudinary] Erreur cURL : ' . $curlErr);
            return null;
        }
        $data = json_decode((string)$response, true);
        if ($status !== 200 || !is_array($data) || empty($data['secure_url'])) {
            error_log('[KOVA Cloudinary] Échec upload (HTTP ' . $status . ') : ' . (string)$response);
            return null;
        }
        // Livraison optimisée côté CDN : format moderne et compression perceptuellement
        // sans dégrader inutilement les photos. L'original reste conservé chez Cloudinary.
        $url = (string)$data['secure_url'];
        $url = str_replace('/upload/', '/upload/q_auto:good,f_auto/', $url);
        return $url;
    }
    /**
     * Supprime une image KOVA appartenant au compte depuis Cloudinary.
     * Les URLs externes ne sont jamais envoyées à l'API de suppression.
     */
    public function deleteImageUrl(string $url): bool
    {
        if (!$this->configured() || !extension_loaded('curl')) return false;
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['host'] ?? '') === '') return false;
        if (!str_ends_with(strtolower((string)$parts['host']), '.cloudinary.com')) return false;

        $path = (string)($parts['path'] ?? '');
        $marker = '/image/upload/';
        $pos = strpos($path, $marker);
        if ($pos === false) return false;
        $public = substr($path, $pos + strlen($marker));
        $segments = array_values(array_filter(explode('/', $public), fn($v) => $v !== ''));
        if (!$segments) return false;

        // Retire les transformations éventuelles q_auto/f_auto.
        while ($segments && (str_contains($segments[0], ':') || preg_match('/^[a-z_]+_[^/]+$/', $segments[0]))) {
            array_shift($segments);
        }
        if (!$segments) return false;
        $last = array_pop($segments);
        $last = preg_replace('/\.[A-Za-z0-9]{2,8}$/', '', $last) ?? $last;
        if ($last === '') return false;
        $publicId = implode('/', array_merge($segments, [$last]));

        $timestamp = (string)time();
        $params = ['public_id' => $publicId, 'timestamp' => $timestamp];
        ksort($params);
        $toSign = 'public_id=' . $publicId . '&timestamp=' . $timestamp;
        $signature = sha1($toSign . (string)$this->env->get('CLOUDINARY_API_SECRET', ''));

        $endpoint = sprintf(
            'https://api.cloudinary.com/v1_1/%s/image/destroy',
            rawurlencode((string)$this->env->get('CLOUDINARY_CLOUD_NAME', ''))
        );
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_POSTFIELDS => [
                'public_id' => $publicId,
                'timestamp' => $timestamp,
                'api_key' => (string)$this->env->get('CLOUDINARY_API_KEY', ''),
                'signature' => $signature,
                'invalidate' => 'true',
            ],
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $status < 200 || $status >= 300) return false;
        $data = json_decode((string)$response, true);
        return is_array($data) && in_array(($data['result'] ?? ''), ['ok', 'not found'], true);
    }

}
