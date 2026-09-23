<?php
declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| HEADERS DE SÉCURITÉ
|--------------------------------------------------------------------------
*/

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');


/*
|--------------------------------------------------------------------------
| IDENTIFIANTS CLOUDINARY
|--------------------------------------------------------------------------
| Valeurs extraites de votre fichier .env
|--------------------------------------------------------------------------
*/

$cloudName = 'dkmraqc7r';
$apiKey    = '533676377143925';
$apiSecret = 'Nlu19tj2tPmbF-14Z_v-_XaEkQA';

if (
    $cloudName === '' ||
    $apiKey === '' ||
    $apiSecret === ''
) {
    http_response_code(500);
    exit(
        'Variables Cloudinary manquantes.'
    );
}


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

function csrf(): string
{
    if (
        empty($_SESSION['kova_csrf']) ||
        !is_string($_SESSION['kova_csrf'])
    ) {
        $_SESSION['kova_csrf'] = bin2hex(
            random_bytes(32)
        );
    }

    return $_SESSION['kova_csrf'];
}


function verifyCsrf(): void
{
    $token = $_POST['csrf'] ?? '';

    if (
        !is_string($token) ||
        empty($_SESSION['kova_csrf']) ||
        !is_string($_SESSION['kova_csrf']) ||
        !hash_equals($_SESSION['kova_csrf'], $token)
    ) {
        http_response_code(403);
        exit('Requête invalide : token CSRF incorrect.');
    }
}


/*
|--------------------------------------------------------------------------
| SIGNATURE CLOUDINARY
|--------------------------------------------------------------------------
*/

function cloudinarySignature(
    array $params,
    string $apiSecret
): string {

    /*
     * Les paramètres vides ne doivent pas entrer
     * dans la signature.
     */
    $filtered = [];

    foreach ($params as $key => $value) {

        if (
            $value === '' ||
            $value === null
        ) {
            continue;
        }

        if (is_array($value)) {
            continue;
        }

        $filtered[$key] = (string)$value;
    }

    ksort($filtered);

    $parts = [];

    foreach ($filtered as $key => $value) {
        $parts[] = $key . '=' . $value;
    }

    $stringToSign =
        implode('&', $parts) .
        $apiSecret;

    return sha1($stringToSign);
}


/*
|--------------------------------------------------------------------------
| REQUÊTE ADMIN API CLOUDINARY
|--------------------------------------------------------------------------
|
| L'API Admin /resources nécessite l'authentification
| API Key + API Secret.
|
|--------------------------------------------------------------------------
*/

function cloudinaryAdminRequest(
    string $method,
    string $url,
    string $apiKey,
    string $apiSecret,
    ?array $postFields = null
): array {

    $ch = curl_init($url);

    if ($ch === false) {
        return [
            'success' => false,
            'http'    => 0,
            'data'    => null,
            'error'   => 'Impossible d\'initialiser cURL.',
        ];
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 60,

        /*
         * Authentification HTTP Basic :
         * API_KEY:API_SECRET
         */
        CURLOPT_USERPWD => $apiKey . ':' . $apiSecret,

        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,

        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
        ],
    ];

    if ($postFields !== null) {
        $options[CURLOPT_POSTFIELDS] = $postFields;
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);

    if ($response === false) {

        $error = curl_error($ch);

        curl_close($ch);

        return [
            'success' => false,
            'http'    => 0,
            'data'    => null,
            'error'   => $error,
        ];
    }

    $httpCode = (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    $data = json_decode(
        $response,
        true
    );

    $success =
        $httpCode >= 200 &&
        $httpCode < 300;

    if (!$success) {

        $errorMessage =
            $data['error']['message']
            ?? $data['message']
            ?? $response
            ?? 'Erreur Cloudinary.';

        return [
            'success' => false,
            'http'    => $httpCode,
            'data'    => $data,
            'error'   => $errorMessage,
        ];
    }

    return [
        'success' => true,
        'http'    => $httpCode,
        'data'    => is_array($data)
            ? $data
            : [],
        'error'   => null,
    ];
}


/*
|--------------------------------------------------------------------------
| URL RESOURCE CLOUDINARY
|--------------------------------------------------------------------------
*/

function cloudinaryResourceUrl(
    string $cloudName,
    string $resourceType,
    string $publicId,
    string $format = ''
): string {

    /*
     * Pour afficher / ouvrir une ressource,
     * secure_url fournie par Cloudinary reste préférable.
     *
     * Cette fonction sert de fallback.
     */

    $extension = '';

    if (
        $resourceType === 'raw' &&
        $format !== ''
    ) {
        $extension = '.' . ltrim($format, '.');
    }

    return
        'https://res.cloudinary.com/' .
        rawurlencode($cloudName) .
        '/' .
        $resourceType .
        '/upload/' .
        rawurlencode($publicId) .
        $extension;
}


/*
|--------------------------------------------------------------------------
| FORMAT TAILLE
|--------------------------------------------------------------------------
*/

function formatBytes(
    ?int $bytes
): string {

    if (
        $bytes === null ||
        $bytes <= 0
    ) {
        return '-';
    }

    $units = [
        'B',
        'KB',
        'MB',
        'GB',
        'TB',
    ];

    $size = (float)$bytes;
    $index = 0;

    while (
        $size >= 1024 &&
        $index < count($units) - 1
    ) {
        $size /= 1024;
        $index++;
    }

    $decimals =
        $index === 0
        ? 0
        : 2;

    return number_format(
        $size,
        $decimals,
        ',',
        ' '
    ) . ' ' . $units[$index];
}


/*
|--------------------------------------------------------------------------
| LABEL TYPE
|--------------------------------------------------------------------------
*/

function getResourceLabel(
    string $resourceType,
    string $format
): string {

    $format = strtolower($format);

    if ($resourceType === 'image') {
        return 'Image';
    }

    if ($resourceType === 'video') {
        return 'Vidéo';
    }

    if (
        $resourceType === 'raw' &&
        $format === 'pdf'
    ) {
        return 'PDF';
    }

    return 'Fichier';
}


/*
|--------------------------------------------------------------------------
| CLASSE TYPE
|--------------------------------------------------------------------------
*/

function getResourceClass(
    string $resourceType,
    string $format
): string {

    $format = strtolower($format);

    if ($resourceType === 'image') {
        return 'image';
    }

    if ($resourceType === 'video') {
        return 'video';
    }

    if (
        $resourceType === 'raw' &&
        $format === 'pdf'
    ) {
        return 'pdf';
    }

    return 'other';
}


/*
|--------------------------------------------------------------------------
| VARIABLES
|--------------------------------------------------------------------------
*/

$error = '';
$message = '';

$resources = [];


/*
|--------------------------------------------------------------------------
| ACTION SUPPRESSION
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {

    verifyCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {

        $publicId = trim(
            (string)($_POST['public_id'] ?? '')
        );

        $resourceType = trim(
            (string)($_POST['resource_type'] ?? '')
        );

        /*
         * Sécurité : uniquement ces trois types.
         */
        if (
            !in_array(
                $resourceType,
                [
                    'image',
                    'video',
                    'raw',
                ],
                true
            )
        ) {

            $error =
                'Type de ressource Cloudinary invalide.';

        } elseif ($publicId === '') {

            $error =
                'Public ID manquant.';

        } else {

            $timestamp = time();

            /*
             * Signature Cloudinary.
             */
            $params = [
                'public_id' => $publicId,
                'timestamp' => $timestamp,
            ];

            $signature = cloudinarySignature(
                $params,
                $apiSecret
            );

            /*
             * IMPORTANT :
             *
             * image -> image/destroy
             * video -> video/destroy
             * raw   -> raw/destroy
             */
            $endpoint =
                'https://api.cloudinary.com/v1_1/' .
                rawurlencode($cloudName) .
                '/' .
                $resourceType .
                '/destroy';

            $postFields = [
                'public_id' => $publicId,
                'timestamp' => $timestamp,
                'api_key'   => $apiKey,
                'signature' => $signature,
                'invalidate' => 'true',
            ];

            $result = cloudinaryAdminRequest(
                'POST',
                $endpoint,
                $apiKey,
                $apiSecret,
                $postFields
            );

            if ($result['success']) {

                $message =
                    'Le fichier "' .
                    $publicId .
                    '" a été supprimé.';

            } else {

                $error =
                    'Erreur lors de la suppression : ' .
                    (
                        $result['error']
                        ?? 'Erreur inconnue.'
                    );
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| RÉCUPÉRATION DES RESSOURCES
|--------------------------------------------------------------------------
|
| On interroge :
|
| image/upload
| video/upload
| raw/upload
|
|--------------------------------------------------------------------------
*/

$resourceTypes = [
    'image',
    'video',
    'raw',
];


if ($error === '') {

    foreach ($resourceTypes as $resourceType) {

        $nextCursor = null;

        do {

            $query = [
                'max_results' => 500,
            ];

            if (
                $nextCursor !== null &&
                $nextCursor !== ''
            ) {
                $query['next_cursor'] =
                    $nextCursor;
            }

            $endpoint =
                'https://api.cloudinary.com/v1_1/' .
                rawurlencode($cloudName) .
                '/resources/' .
                $resourceType .
                '/upload?' .
                http_build_query(
                    $query,
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                );

            $result = cloudinaryAdminRequest(
                'GET',
                $endpoint,
                $apiKey,
                $apiSecret
            );

            if (!$result['success']) {

                $error =
                    'Impossible de récupérer les ' .
                    $resourceType .
                    ' Cloudinary : ' .
                    (
                        $result['error']
                        ?? 'Erreur inconnue.'
                    );

                break 2;
            }

            $data = $result['data'] ?? [];

            foreach (
                ($data['resources'] ?? [])
                as $resource
            ) {

                if (!is_array($resource)) {
                    continue;
                }

                /*
                 * On conserve le type original.
                 */
                $resource['resource_type'] =
                    $resourceType;

                $resources[] =
                    $resource;
            }

            $nextCursor =
                $data['next_cursor']
                ?? null;

        } while (
            $nextCursor !== null &&
            $nextCursor !== ''
        );
    }


    /*
    |--------------------------------------------------------------------------
    | TRI
    |--------------------------------------------------------------------------
    */

    usort(
        $resources,
        static function (
            array $a,
            array $b
        ): int {

            return strcmp(
                (string)(
                    $b['created_at'] ?? ''
                ),
                (string)(
                    $a['created_at'] ?? ''
                )
            );
        }
    );
}


/*
|--------------------------------------------------------------------------
| STATISTIQUES
|--------------------------------------------------------------------------
*/

$totalImages = 0;
$totalVideos = 0;
$totalPdfs   = 0;
$totalOthers = 0;

foreach ($resources as $resource) {

    $resourceType =
        (string)(
            $resource['resource_type']
            ?? ''
        );

    $format =
        strtolower(
            (string)(
                $resource['format']
                ?? ''
            )
        );

    if ($resourceType === 'image') {

        $totalImages++;

    } elseif ($resourceType === 'video') {

        $totalVideos++;

    } elseif ($resourceType === 'raw') {

        if ($format === 'pdf') {
            $totalPdfs++;
        } else {
            $totalOthers++;
        }
    }
}


/*
|--------------------------------------------------------------------------
| TOTAL
|--------------------------------------------------------------------------
*/

$totalFiles = count($resources);

?>
<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>KOVA — Cloudinary Manager</title>

<style>

* {
    box-sizing: border-box;
}

html {
    background: #f5f6f8;
}

body {
    margin: 0;
    background: #f5f6f8;
    color: #18181b;
    font-family:
        Arial,
        Helvetica,
        sans-serif;
}

.container {
    width: min(1550px, 96%);
    margin: 30px auto 60px;
}

h1 {
    margin: 0;
    font-size: 32px;
}

h2 {
    margin-top: 0;
}

.subtitle {
    margin-top: 8px;
    color: #666;
}

.card {
    background: #fff;
    border-radius: 16px;
    padding: 22px;
    margin-bottom: 20px;
    box-shadow:
        0 5px 25px rgba(0, 0, 0, .06);
}

.connection {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
}

.cloud-name {
    font-weight: bold;
}

.alert {
    padding: 14px 16px;
    border-radius: 10px;
    margin-bottom: 20px;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
}

.alert-success {
    background: #dcfce7;
    color: #166534;
}

.stats {
    display: grid;
    grid-template-columns:
        repeat(5, minmax(0, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.stat {
    background: #fff;
    border-radius: 14px;
    padding: 18px;
    box-shadow:
        0 5px 20px rgba(0, 0, 0, .05);
}

.stat-label {
    color: #666;
    font-size: 14px;
}

.stat strong {
    display: block;
    margin-top: 7px;
    font-size: 28px;
}

.table-wrapper {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    min-width: 1100px;
    border-collapse: collapse;
}

th,
td {
    padding: 13px;
    border-bottom: 1px solid #eee;
    text-align: left;
    vertical-align: middle;
}

th {
    background: #fafafa;
    font-size: 13px;
}

.preview {
    width: 140px;
    height: 95px;
    display: block;
    object-fit: cover;
    border-radius: 9px;
    background: #eee;
}

.pdf-preview {
    width: 140px;
    height: 95px;
    display: block;
    border: 1px solid #ddd;
    border-radius: 9px;
    background: #fff;
}

.file-icon {
    width: 140px;
    height: 95px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 9px;
    background: #f0f0f0;
    font-size: 38px;
}

.badge {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
}

.badge.image {
    background: #dbeafe;
    color: #1d4ed8;
}

.badge.video {
    background: #ede9fe;
    color: #6d28d9;
}

.badge.pdf {
    background: #fee2e2;
    color: #b91c1c;
}

.badge.other {
    background: #e5e7eb;
    color: #374151;
}

.public-id {
    max-width: 300px;
    overflow-wrap: anywhere;
    word-break: break-word;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
}

.btn {
    display: inline-block;
    border: 0;
    border-radius: 8px;
    padding: 9px 12px;
    cursor: pointer;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
}

.btn-open {
    background: #2563eb;
    color: #fff;
}

.btn-download {
    background: #059669;
    color: #fff;
}

.btn-danger {
    background: #dc2626;
    color: #fff;
}

.btn:hover {
    opacity: .88;
}

.empty {
    text-align: center;
    padding: 40px 20px;
    color: #666;
}

code,
pre {
    font-family:
        Consolas,
        Monaco,
        monospace;
}

pre {
    background: #18181b;
    color: #fff;
    padding: 15px;
    border-radius: 10px;
    overflow-x: auto;
}

@media (max-width: 1100px) {

    .stats {
        grid-template-columns:
            repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 700px) {

    .container {
        width: 94%;
        margin-top: 20px;
    }

    .stats {
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }

    .connection {
        align-items: flex-start;
        flex-direction: column;
    }
}

</style>

</head>

<body>

<div class="container">


    <div class="card">

        <h1>KOVA</h1>

        <p class="subtitle">
            Gestionnaire Cloudinary — Images, PDF,
            vidéos et autres fichiers
        </p>

        <div class="connection">

            <div>
                Cloud connecté :
                <span class="cloud-name">
                    <?= e($cloudName) ?>
                </span>
            </div>

            <div>
                API Cloudinary active
            </div>

        </div>

    </div>


    <?php if ($error !== ''): ?>

        <div class="alert alert-error">
            <?= e($error) ?>
        </div>

    <?php endif; ?>


    <?php if ($message !== ''): ?>

        <div class="alert alert-success">
            <?= e($message) ?>
        </div>

    <?php endif; ?>


    <div class="stats">

        <div class="stat">

            <div class="stat-label">
                Total
            </div>

            <strong>
                <?= $totalFiles ?>
            </strong>

        </div>


        <div class="stat">

            <div class="stat-label">
                Images
            </div>

                        <strong>
                <?= $totalImages ?>
            </strong>

        </div>


        <div class="stat">

            <div class="stat-label">
                PDF
            </div>

            <strong>
                <?= $totalPdfs ?>
            </strong>

        </div>


        <div class="stat">

            <div class="stat-label">
                Vidéos
            </div>

            <strong>
                <?= $totalVideos ?>
            </strong>

        </div>


        <div class="stat">

            <div class="stat-label">
                Autres fichiers
            </div>

            <strong>
                <?= $totalOthers ?>
            </strong>

        </div>

    </div>


    <div class="card">

        <h2>
            Fichiers Cloudinary
        </h2>


        <?php if (empty($resources)): ?>

            <div class="empty">

                Aucun fichier trouvé dans Cloudinary.

            </div>

        <?php else: ?>


            <div class="table-wrapper">

                <table>

                    <thead>

                        <tr>

                            <th>
                                Aperçu
                            </th>

                            <th>
                                Type
                            </th>

                            <th>
                                Public ID
                            </th>

                            <th>
                                Format
                            </th>

                            <th>
                                Taille
                            </th>

                            <th>
                                Date
                            </th>

                            <th>
                                Actions
                            </th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php foreach ($resources as $resource): ?>


                        <?php

                        $publicId =
                            (string)(
                                $resource['public_id']
                                ?? ''
                            );

                        $secureUrl =
                            (string)(
                                $resource['secure_url']
                                ?? ''
                            );

                        $format =
                            strtolower(
                                (string)(
                                    $resource['format']
                                    ?? ''
                                )
                            );

                        $bytes =
                            isset($resource['bytes'])
                            ? (int)$resource['bytes']
                            : 0;

                        $createdAt =
                            (string)(
                                $resource['created_at']
                                ?? ''
                            );

                        $resourceType =
                            (string)(
                                $resource['resource_type']
                                ?? ''
                            );

                        $label =
                            getResourceLabel(
                                $resourceType,
                                $format
                            );

                        $class =
                            getResourceClass(
                                $resourceType,
                                $format
                            );


                        /*
                         * Fallback si Cloudinary ne fournit
                         * pas directement secure_url.
                         */

                        if (
                            $secureUrl === '' &&
                            $publicId !== ''
                        ) {

                            $secureUrl =
                                cloudinaryResourceUrl(
                                    $cloudName,
                                    $resourceType,
                                    $publicId,
                                    $format
                                );
                        }

                        ?>


                        <tr>


                            <!-- APERÇU -->

                            <td>

                                <?php if (
                                    $resourceType === 'image'
                                ): ?>

                                    <img
                                        src="<?= e($secureUrl) ?>"
                                        class="preview"
                                        loading="lazy"
                                        alt="<?= e($publicId) ?>"
                                    >

                                <?php elseif (
                                    $resourceType === 'video'
                                ): ?>

                                    <video
                                        class="preview"
                                        controls
                                        preload="metadata"
                                    >

                                        <source
                                            src="<?= e($secureUrl) ?>"
                                        >

                                        Votre navigateur
                                        ne supporte pas la vidéo.

                                    </video>

                                <?php elseif (
                                    $resourceType === 'raw' &&
                                    $format === 'pdf'
                                ): ?>

                                    <iframe
                                        src="<?= e($secureUrl) ?>"
                                        class="pdf-preview"
                                        loading="lazy"
                                        title="<?= e($publicId) ?>"
                                    ></iframe>

                                <?php else: ?>

                                    <div class="file-icon">
                                        📄
                                    </div>

                                <?php endif; ?>

                            </td>


                            <!-- TYPE -->

                            <td>

                                <span
                                    class="badge <?= e($class) ?>"
                                >
                                    <?= e($label) ?>
                                </span>

                            </td>


                            <!-- PUBLIC ID -->

                            <td class="public-id">

                                <?= e($publicId) ?>

                            </td>


                            <!-- FORMAT -->

                            <td>

                                <?= e(
                                    $format !== ''
                                        ? $format
                                        : '-'
                                ) ?>

                            </td>


                            <!-- TAILLE -->

                            <td>

                                <?= e(
                                    formatBytes($bytes)
                                ) ?>

                            </td>


                            <!-- DATE -->

                            <td>

                                <?= e(
                                    $createdAt !== ''
                                        ? $createdAt
                                        : '-'
                                ) ?>

                            </td>


                            <!-- ACTIONS -->

                            <td>

                                <div class="actions">


                                    <?php if (
                                        $secureUrl !== ''
                                    ): ?>


                                        <!-- OUVRIR -->

                                        <a
                                            href="<?= e($secureUrl) ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="btn btn-open"
                                        >
                                            Ouvrir
                                        </a>


                                        <!-- TÉLÉCHARGER -->

                                        <a
                                            href="<?= e($secureUrl) ?>"
                                            download
                                            class="btn btn-download"
                                        >
                                            Télécharger
                                        </a>


                                    <?php endif; ?>


                                    <!-- SUPPRIMER -->

                                    <form
                                        method="post"
                                        onsubmit="return confirm(
                                            'Supprimer définitivement ce fichier de Cloudinary ?'
                                        );"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf"
                                            value="<?= e(csrf()) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="delete"
                                        >

                                        <input
                                            type="hidden"
                                            name="public_id"
                                            value="<?= e($publicId) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="resource_type"
                                            value="<?= e($resourceType) ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="btn btn-danger"
                                        >
                                            Supprimer
                                        </button>

                                    </form>


                                </div>

                            </td>


                        </tr>


                    <?php endforeach; ?>


                    </tbody>

                </table>

            </div>


        <?php endif; ?>


    </div>


</div>

</body>

</html>