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
    if (!is_array($data) || empty($data['direct_url'])) {
        return null;
    }

    $fetchedAt = (int) ($data['fetched_at'] ?? 0);
    if ($fetchedAt > 0 && (time() - $fetchedAt) > IG_CACHE_TTL) {
        return null;
    }

    return $data;
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
