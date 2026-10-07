<?php
declare(strict_types=1);

require_once __DIR__ . '/ytdlp.php';

/**
 * Resuelve metadatos de video de Instagram vía yt-dlp, con cache local.
 *
 * @return array{
 *   direct_url: string,
 *   title: string,
 *   author: string,
 *   thumbnail: string,
 *   width: int,
 *   height: int,
 *   mime: string,
 *   duration: int|null
 * }|null
 */
function resolve_instagram_video(string $type, string $id, string $query = '', bool $forceRefresh = false): ?array
{
    $cacheKey = ig_cache_key($type, $id, $query);
    $cached = $forceRefresh ? null : ig_cache_read($cacheKey);

    if (is_array($cached) && !empty($cached['direct_url'])) {
        return $cached;
    }

    $instagramUrl = 'https://www.instagram.com/' . $type . '/' . $id . '/';
    if ($query !== '') {
        parse_str($query, $params);
        unset($params['raw']);
        if ($params !== []) {
            $instagramUrl .= '?' . http_build_query($params);
        }
    }

    $result = ig_ytdlp_dump_json($instagramUrl);
    if (empty($result['output'])) {
        error_log('[ig.weko.lol] dump-json falló: ' . ($result['warnings'] ?? 'sin detalle'));
        return null;
    }

    $info = json_decode($result['output'], true);
    if (!is_array($info)) {
        return null;
    }

    $picked = ig_pick_best_video_format($info);
    if ($picked === null) {
        error_log('[ig.weko.lol] sin formato de video para ' . $instagramUrl);
        return null;
    }

    $meta = [
        'direct_url' => $picked['url'],
        'title' => (string) ($info['title'] ?? ('Instagram ' . $type)),
        'author' => (string) ($info['uploader'] ?? $info['channel'] ?? $info['creator'] ?? ''),
        'thumbnail' => (string) ($info['thumbnail'] ?? ''),
        'width' => (int) ($picked['width'] ?? $info['width'] ?? IG_DEFAULT_WIDTH),
        'height' => (int) ($picked['height'] ?? $info['height'] ?? IG_DEFAULT_HEIGHT),
        'mime' => (string) ($picked['mime'] ?? 'video/mp4'),
        'duration' => isset($info['duration']) ? (int) $info['duration'] : null,
        'fetched_at' => time(),
        'instagram_url' => $instagramUrl,
    ];

    if ($meta['width'] <= 0) {
        $meta['width'] = IG_DEFAULT_WIDTH;
    }
    if ($meta['height'] <= 0) {
        $meta['height'] = IG_DEFAULT_HEIGHT;
    }

    ig_cache_write($cacheKey, $meta);

    return $meta;
}

/**
 * @param array<string, mixed> $info
 * @return array{url: string, width: int|null, height: int|null, mime: string}|null
 */
function ig_pick_best_video_format(array $info): ?array
{
    $candidates = [];

    if (!empty($info['url']) && ig_looks_like_video_url((string) $info['url'], $info['ext'] ?? null, $info['vcodec'] ?? null)) {
        $candidates[] = [
            'url' => (string) $info['url'],
            'width' => isset($info['width']) ? (int) $info['width'] : null,
            'height' => isset($info['height']) ? (int) $info['height'] : null,
            'tbr' => isset($info['tbr']) ? (float) $info['tbr'] : 0.0,
            'score' => 500000 + (isset($info['height']) ? (int) $info['height'] : 0),
            'mime' => ig_guess_mime(is_string($info['ext'] ?? null) ? (string) $info['ext'] : 'mp4'),
        ];
    }

    if (!empty($info['formats']) && is_array($info['formats'])) {
        foreach ($info['formats'] as $fmt) {
            if (!is_array($fmt) || empty($fmt['url'])) {
                continue;
            }

            $vcodec = $fmt['vcodec'] ?? null;
            $acodec = $fmt['acodec'] ?? null;
            $ext = $fmt['ext'] ?? null;
            $formatId = (string) ($fmt['format_id'] ?? '');
            // Progressive muxed (1/2/3): codecs "unknown" pero traen h264+aac
            $isProgressive = preg_match('/^[123]$/', $formatId) === 1;

            if ($vcodec === 'none' && !$isProgressive) {
                continue; // audio-only DASH
            }
            if (($vcodec === null || $vcodec === '') && !$isProgressive) {
                continue;
            }

            $hasAudio = ($acodec !== null && $acodec !== 'none') || $isProgressive;
            $hasVideo = ($vcodec !== null && $vcodec !== 'none' && $vcodec !== '') || $isProgressive;

            if (!$hasVideo && !$isProgressive) {
                continue;
            }

            // Priorizar muxed con audio; progressive h264 > DASH video-only
            $score = 0;
            if ($isProgressive) {
                $score += 3_000_000;
            }
            if ($hasAudio && $hasVideo) {
                $score += 2_000_000;
            } elseif ($hasAudio) {
                $score += 100_000;
            }
            $score += isset($fmt['height']) ? (int) $fmt['height'] : 0;

            $candidates[] = [
                'url' => (string) $fmt['url'],
                'width' => isset($fmt['width']) ? (int) $fmt['width'] : null,
                'height' => isset($fmt['height']) ? (int) $fmt['height'] : null,
                'tbr' => isset($fmt['tbr']) ? (float) $fmt['tbr'] : 0.0,
                'score' => $score,
                'has_audio' => $hasAudio || $isProgressive,
                'mime' => ig_guess_mime(is_string($ext) ? $ext : 'mp4'),
            ];
        }
    }

    if ($candidates === []) {
        return null;
    }

    usort($candidates, static function (array $a, array $b): int {
        return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
    });

    $best = $candidates[0];

    return [
        'url' => $best['url'],
        'width' => $best['width'],
        'height' => $best['height'],
        'mime' => $best['mime'],
        'has_audio' => (bool) ($best['has_audio'] ?? false),
    ];
}

function ig_looks_like_video_url(string $url, mixed $ext = null, mixed $vcodec = null): bool
{
    if (is_string($vcodec) && $vcodec !== '' && $vcodec !== 'none') {
        return true;
    }

    if (is_string($ext) && preg_match('/^(mp4|webm|mov|m4v)$/i', $ext)) {
        return true;
    }

    return (bool) preg_match('/\.(mp4|webm|mov|m4v)(\?|$)/i', $url);
}

function ig_guess_mime(string $ext): string
{
    return match (strtolower($ext)) {
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'm4v' => 'video/x-m4v',
        default => 'video/mp4',
    };
}

function ig_cache_key(string $type, string $id, string $query = ''): string
{
    parse_str($query, $params);
    unset($params['raw']);
    ksort($params);
    $suffix = $params === [] ? '' : '_' . md5(http_build_query($params));

    return $type . '_' . $id . $suffix;
}

function ig_cache_path(string $key): string
{
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $key) ?: 'invalid';
    return IG_CACHE_DIR . '/' . $safe . '.json';
}

function ig_cache_read(string $key): ?array
{
    $path = ig_cache_path($key);
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }

    $fetchedAt = (int) ($data['fetched_at'] ?? 0);
    if ($fetchedAt > 0 && (time() - $fetchedAt) > IG_CACHE_TTL) {
        return null;
    }

    return $data;
}

/**
 * Prepara embed Discord: lock + una sola descarga yt-dlp + meta.
 * Solo OK si el MP4 local está listo (con audio).
 *
 * @return array{meta: array, local_path: string, video_url: string}|null
 */
function prepare_instagram_embed(string $type, string $id, string $query = ''): ?array
{
    return ig_with_media_lock($type, $id, static function () use ($type, $id, $query): ?array {
        $localPath = ig_local_video_path($type, $id);
        $cacheKey = ig_cache_key($type, $id, $query);

        $instagramUrl = 'https://www.instagram.com/' . $type . '/' . $id . '/';
        if ($query !== '') {
            parse_str($query, $params);
            unset($params['raw']);
            if ($params !== []) {
                $instagramUrl .= '?' . http_build_query($params);
            }
        }

        // Hit caliente: archivo listo → respuesta inmediata
        if (ig_local_video_ready($localPath)) {
            $meta = ig_cache_read($cacheKey) ?? ig_meta_from_local($localPath, $instagramUrl, $type);
            return [
                'meta' => $meta,
                'local_path' => $localPath,
                'video_url' => build_proxy_video_url($type, $id),
            ];
        }

        // Una pasada: descarga + info-json (sin dump-json previo)
        $download = ig_ensure_local_video($instagramUrl, $localPath, false);
        if (!$download['ok'] || !ig_local_video_ready($localPath)) {
            error_log('[ig.weko.lol] prepare falló para ' . $instagramUrl);
            return null;
        }

        $meta = ig_meta_from_ytdlp_info($download['meta'] ?? null, $instagramUrl, $type, $localPath);
        ig_cache_write($cacheKey, $meta);

        return [
            'meta' => $meta,
            'local_path' => $localPath,
            'video_url' => build_proxy_video_url($type, $id),
        ];
    });
}

/**
 * @param array<string, mixed>|null $info
 * @return array<string, mixed>
 */
function ig_meta_from_ytdlp_info(?array $info, string $instagramUrl, string $type, string $localPath): array
{
    $width = IG_DEFAULT_WIDTH;
    $height = IG_DEFAULT_HEIGHT;
    $title = 'Instagram ' . $type;
    $author = '';
    $duration = null;
    $direct = '';

    if (is_array($info)) {
        $title = (string) ($info['title'] ?? $title);
        $author = (string) ($info['uploader'] ?? $info['channel'] ?? $info['creator'] ?? '');
        $duration = isset($info['duration']) ? (int) $info['duration'] : null;
        $width = (int) ($info['width'] ?? $width);
        $height = (int) ($info['height'] ?? $height);
        $direct = (string) ($info['url'] ?? '');

        $picked = ig_pick_best_video_format($info);
        if ($picked !== null) {
            $width = (int) ($picked['width'] ?? $width);
            $height = (int) ($picked['height'] ?? $height);
            if ($direct === '') {
                $direct = (string) $picked['url'];
            }
        }
    }

    // Fallback dimensiones desde el archivo
    if ($width <= 0 || $height <= 0 || ($width === IG_DEFAULT_WIDTH && $height === IG_DEFAULT_HEIGHT)) {
        $probed = ig_probe_video_size($localPath);
        if ($probed !== null) {
            $width = $probed['width'];
            $height = $probed['height'];
        }
    }

    if ($width <= 0) {
        $width = IG_DEFAULT_WIDTH;
    }
    if ($height <= 0) {
        $height = IG_DEFAULT_HEIGHT;
    }

    return [
        'direct_url' => $direct,
        'title' => $title,
        'author' => $author,
        'thumbnail' => is_array($info) ? (string) ($info['thumbnail'] ?? '') : '',
        'width' => $width,
        'height' => $height,
        'mime' => 'video/mp4',
        'duration' => $duration,
        'fetched_at' => time(),
        'instagram_url' => $instagramUrl,
        'local' => true,
    ];
}

/**
 * @return array<string, mixed>
 */
function ig_meta_from_local(string $localPath, string $instagramUrl, string $type): array
{
    $probed = ig_probe_video_size($localPath);

    return [
        'direct_url' => '',
        'title' => 'Instagram ' . $type,
        'author' => '',
        'thumbnail' => '',
        'width' => $probed['width'] ?? IG_DEFAULT_WIDTH,
        'height' => $probed['height'] ?? IG_DEFAULT_HEIGHT,
        'mime' => 'video/mp4',
        'duration' => null,
        'fetched_at' => time(),
        'instagram_url' => $instagramUrl,
        'local' => true,
    ];
}

/**
 * @return array{width: int, height: int}|null
 */
function ig_probe_video_size(string $localPath): ?array
{
    if (!is_file($localPath)) {
        return null;
    }

    $ffprobe = trim((string) shell_exec('command -v ffprobe 2>/dev/null'));
    if ($ffprobe === '') {
        return null;
    }

    $cmd = escapeshellarg($ffprobe)
        . ' -v error -select_streams v:0 -show_entries stream=width,height'
        . ' -of csv=p=0:s=x ' . escapeshellarg($localPath) . ' 2>/dev/null';
    $out = trim((string) shell_exec($cmd));
    if (!preg_match('/^(\d+)x(\d+)$/', $out, $m)) {
        return null;
    }

    return ['width' => (int) $m[1], 'height' => (int) $m[2]];
}

function ig_cache_write(string $key, array $data): void
{
    if (!is_dir(IG_CACHE_DIR)) {
        @mkdir(IG_CACHE_DIR, 0775, true);
    }

    $path = ig_cache_path($key);
    @file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function ig_cache_invalidate(string $type, string $id, string $query = ''): void
{
    $path = ig_cache_path(ig_cache_key($type, $id, $query));
    if (is_file($path)) {
        @unlink($path);
    }
}
