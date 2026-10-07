<?php
declare(strict_types=1);

/**
 * Proxy HTTP del CDN de Instagram con soporte Range, Content-Length y CORS.
 * Discord exige Content-Length en HEAD/GET para adjuntar el video.
 */

/**
 * @return array{status: int, headers: array<string, string>}
 */
function ig_remote_probe(string $remoteUrl): array
{
    $ch = curl_init($remoteUrl);
    if ($ch === false) {
        return ['status' => 0, 'headers' => []];
    }

    $headers = [];
    $status = 0;

    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: */*',
            'Referer: https://www.instagram.com/',
            'Range: bytes=0-0',
        ],
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers, &$status): int {
            $len = strlen($line);
            $trim = trim($line);
            if ($trim === '') {
                return $len;
            }
            if (preg_match('#^HTTP/\S+\s+(\d+)#i', $trim, $m)) {
                $status = (int) $m[1];
                return $len;
            }
            $pos = strpos($trim, ':');
            if ($pos !== false) {
                $name = strtolower(trim(substr($trim, 0, $pos)));
                $headers[$name] = trim(substr($trim, $pos + 1));
            }
            return $len;
        },
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk): int {
            return strlen($chunk);
        },
    ]);

    curl_exec($ch);
    curl_close($ch);

    return ['status' => $status, 'headers' => $headers];
}

function ig_parse_total_size(array $headers): int
{
    if (!empty($headers['content-range']) && preg_match('#/(\d+)\s*$#', $headers['content-range'], $m)) {
        return (int) $m[1];
    }
    if (!empty($headers['content-length'])) {
        return (int) $headers['content-length'];
    }
    return 0;
}

function stream_remote_video(string $remoteUrl, string $mime = 'video/mp4', int $knownSize = 0): int
{
    if ($remoteUrl === '' || !preg_match('#^https?://#i', $remoteUrl)) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'URL de video inválida';
        return 502;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $isHead = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD';
    $isOptions = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS';

    if ($isOptions) {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
        header('Access-Control-Allow-Headers: Range');
        http_response_code(204);
        return 204;
    }

    $totalSize = $knownSize > 0 ? $knownSize : 0;
    if ($totalSize <= 0) {
        $probe = ig_remote_probe($remoteUrl);
        $totalSize = ig_parse_total_size($probe['headers']);
        if (in_array($probe['status'], [401, 403, 404, 410, 0], true) && $totalSize <= 0) {
            http_response_code($probe['status'] > 0 ? $probe['status'] : 502);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Video no disponible';
            return $probe['status'] > 0 ? $probe['status'] : 502;
        }
    }

    $start = 0;
    $end = $totalSize > 0 ? $totalSize - 1 : 0;
    $isRange = false;

    if (!empty($_SERVER['HTTP_RANGE']) && $totalSize > 0
        && preg_match('/bytes=([0-9]*)-([0-9]*)/i', $_SERVER['HTTP_RANGE'], $m)
    ) {
        $isRange = true;
        if ($m[1] !== '') {
            $start = (int) $m[1];
        }
        if ($m[2] !== '') {
            $end = (int) $m[2];
        }
        if ($end >= $totalSize) {
            $end = $totalSize - 1;
        }
        if ($start > $end || $start >= $totalSize) {
            http_response_code(416);
            header('Content-Range: bytes */' . $totalSize);
            return 416;
        }
    }

    $length = $totalSize > 0 ? ($end - $start + 1) : 0;

    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
    header('Access-Control-Allow-Headers: Range');
    header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=604800, immutable');
    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Content-Disposition: inline; filename="instagram.mp4"');

    if ($isRange && $totalSize > 0) {
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $totalSize);
        header('Content-Length: ' . $length);
    } elseif ($totalSize > 0) {
        http_response_code(200);
        header('Content-Length: ' . $totalSize);
    } else {
        http_response_code(200);
    }

    if ($isHead) {
        return $isRange ? 206 : 200;
    }

    $requestHeaders = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept: */*',
        'Referer: https://www.instagram.com/',
    ];
    if ($totalSize > 0) {
        $requestHeaders[] = 'Range: bytes=' . $start . '-' . $end;
    } elseif (!empty($_SERVER['HTTP_RANGE'])) {
        $requestHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
    }

    $ch = curl_init($remoteUrl);
    if ($ch === false) {
        http_response_code(502);
        echo 'No se pudo iniciar el proxy';
        return 502;
    }

    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk): int {
            echo $chunk;
            if (function_exists('fastcgi_finish_request') === false && connection_aborted()) {
                return 0;
            }
            return strlen($chunk);
        },
    ]);

    $ok = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($ok === false) {
        error_log('[ig.weko.lol] curl proxy error: ' . $curlErr);
        return 502;
    }

    return $httpCode > 0 ? $httpCode : 200;
}

function remote_url_expired(string $remoteUrl): bool
{
    $probe = ig_remote_probe($remoteUrl);
    return in_array($probe['status'], [401, 403, 404, 410, 0], true);
}

/**
 * ¿El MP4 local tiene pista de audio?
 */
function ig_local_video_has_audio(string $localPath): bool
{
    if (!is_file($localPath) || filesize($localPath) < 1000) {
        return false;
    }

    $ffprobe = trim((string) shell_exec('command -v ffprobe 2>/dev/null'));
    if ($ffprobe === '') {
        return true;
    }

    $cmd = escapeshellarg($ffprobe)
        . ' -v error -select_streams a -show_entries stream=codec_type'
        . ' -of csv=p=0 ' . escapeshellarg($localPath) . ' 2>/dev/null';
    $out = trim((string) shell_exec($cmd));

    return $out !== '' && str_contains(strtolower($out), 'audio');
}

/**
 * Archivo listo para Discord: existe + marcador .ready (o audio verificado una vez).
 */
function ig_local_video_ready(string $localPath): bool
{
    if (!is_file($localPath) || filesize($localPath) < 1000) {
        return false;
    }

    if (is_file($localPath . '.ready')) {
        return true;
    }

    if (ig_local_video_has_audio($localPath)) {
        @file_put_contents($localPath . '.ready', (string) time());
        return true;
    }

    return false;
}

/**
 * Lock por reel para evitar dobles descargas concurrentes (Discord hace varias peticiones).
 *
 * @template T
 * @param callable(): T $fn
 * @return T
 */
function ig_with_media_lock(string $type, string $id, callable $fn): mixed
{
    $lockDir = IG_CACHE_DIR . '/locks';
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0775, true);
    }

    $lockPath = $lockDir . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $type . '_' . $id) . '.lock';
    $fp = fopen($lockPath, 'c+');
    if ($fp === false) {
        return $fn();
    }

    flock($fp, LOCK_EX);
    try {
        return $fn();
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * Materializa MP4 con audio vía yt-dlp (con lock + reintentos en ytdlp.php).
 *
 * @return array{ok: bool, meta: ?array}
 */
function ig_ensure_local_video(string $instagramUrl, string $localPath, bool $force = false): array
{
    require_once __DIR__ . '/ytdlp.php';

    if (!$force && ig_local_video_ready($localPath)) {
        return ['ok' => true, 'meta' => null];
    }

    if ($force || (is_file($localPath) && !ig_local_video_ready($localPath))) {
        @unlink($localPath);
        @unlink($localPath . '.ready');
    }

    $result = ig_ytdlp_download($instagramUrl, $localPath);

    return [
        'ok' => (bool) ($result['ok'] ?? false),
        'meta' => is_array($result['meta'] ?? null) ? $result['meta'] : null,
    ];
}

/**
 * Streaming local idéntico en espíritu a weko stream_file_with_range.
 */
function stream_local_video(string $path, string $mime = 'video/mp4'): int
{
    if (!is_file($path) || !is_readable($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'File not found';
        return 404;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $size = filesize($path);
    $start = 0;
    $end = $size - 1;
    $isHead = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD';

    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
    header('Access-Control-Allow-Headers: Range');
    header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=604800, immutable');
    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Content-Disposition: inline; filename="instagram.mp4"');

    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        return 204;
    }

    if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=([0-9]*)-([0-9]*)/i', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] !== '') {
            $start = (int) $m[1];
        }
        if ($m[2] !== '') {
            $end = (int) $m[2];
        }
        if ($end >= $size) {
            $end = $size - 1;
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            return 416;
        }

        $length = $end - $start + 1;
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        header('Content-Length: ' . $length);

        if (!$isHead) {
            $fp = fopen($path, 'rb');
            if ($fp === false) {
                return 500;
            }
            fseek($fp, $start);
            $remaining = $length;
            while ($remaining > 0 && !feof($fp)) {
                $chunk = fread($fp, (int) min(8192, $remaining));
                if ($chunk === false) {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
            }
            fclose($fp);
        }
        return 206;
    }

    http_response_code(200);
    header('Content-Length: ' . $size);

    if (!$isHead) {
        readfile($path);
    }

    return 200;
}
