<?php
declare(strict_types=1);

/**
 * Utilidades para convertir URLs de Instagram ↔ ig.weko.lol
 * y detectar bots de preview (Discord, etc.).
 */

function is_instagram_video_path(string $url): bool
{
    return parse_instagram_path($url) !== null;
}

/**
 * @return array{type: string, id: string, query: string, path_type: string}|null
 */
function parse_instagram_path(string $url): ?array
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }

    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }

    $parts = parse_url($url);
    if ($parts === false || empty($parts['host']) || empty($parts['path'])) {
        return null;
    }

    $host = strtolower($parts['host']);
    $allowedHosts = [
        'instagram.com',
        'www.instagram.com',
        'm.instagram.com',
        'instagr.am',
        'www.instagr.am',
        IG_SITE_HOST,
        'www.' . IG_SITE_HOST,
    ];

    if (!in_array($host, $allowedHosts, true)) {
        return null;
    }

    if (!preg_match('#^/(reels?|p|tv)/([A-Za-z0-9_-]+)/?#i', $parts['path'], $m)) {
        return null;
    }

    $type = strtolower($m[1]);
    // Normalizar plural → singular para cache / yt-dlp
    if ($type === 'reels') {
        $type = 'reel';
    }

    return [
        'type' => $type,
        'id' => $m[2],
        'query' => $parts['query'] ?? '',
        'path_type' => strtolower($m[1]), // reel|reels|p|tv como vino en la URL
    ];
}

function normalize_instagram_url(string $url): ?string
{
    $parsed = parse_instagram_path($url);
    if ($parsed === null) {
        return null;
    }

    // Instagram acepta /reel/ y /reels/; yt-dlp va bien con /reel/
    $canonical = 'https://www.instagram.com/' . $parsed['type'] . '/' . $parsed['id'] . '/';
    if ($parsed['query'] !== '') {
        $canonical .= '?' . $parsed['query'];
    }

    return $canonical;
}

function instagram_url_to_proxy(string $url, string $host = IG_SITE_HOST): ?string
{
    $parsed = parse_instagram_path($url);
    if ($parsed === null) {
        return null;
    }

    $pathType = $parsed['path_type'] ?? $parsed['type'];
    $proxy = 'https://' . $host . '/' . $pathType . '/' . $parsed['id'] . '/';
    if ($parsed['query'] !== '') {
        $proxy .= '?' . $parsed['query'];
    }

    return $proxy;
}

function proxy_url_to_instagram(string $url): ?string
{
    return normalize_instagram_url($url);
}

function proxy_request_to_instagram_url(string $path, string $query = ''): ?string
{
    $url = 'https://' . IG_SITE_HOST . '/' . ltrim($path, '/');
    if ($query !== '') {
        $url .= (str_contains($url, '?') ? '&' : '?') . ltrim($query, '?');
    }

    return normalize_instagram_url($url);
}

/**
 * @return array{type: string, id: string}|null
 */
function parse_proxy_route(?string $type, ?string $id): ?array
{
    $type = strtolower(trim((string) $type));
    $id = trim((string) $id);

    if ($type === 'reels') {
        $type = 'reel';
    }

    if (!in_array($type, ['reel', 'p', 'tv'], true)) {
        return null;
    }

    if ($id === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
        return null;
    }

    return ['type' => $type, 'id' => $id];
}

function build_proxy_page_url(string $type, string $id, string $query = '', bool $raw = false): string
{
    $url = IG_SITE_URL . '/' . $type . '/' . $id . '/';
    $params = [];

    if ($query !== '') {
        parse_str($query, $params);
    }

    if ($raw) {
        $params['raw'] = '1';
    }

    if ($params !== []) {
        $url .= '?' . http_build_query($params);
    }

    return $url;
}

/**
 * URL estática .mp4 (nginx/apache la sirve con Content-Length, como weko/files).
 * Incluye ?v=mtime para invalidar caché de Cloudflare al re-descargar.
 */
function build_proxy_video_url(string $type, string $id): string
{
    $url = IG_SITE_URL . '/media/' . $type . '_' . $id . '.mp4';
    $path = ig_local_video_path($type, $id);
    if (is_file($path)) {
        $url .= '?v=' . filemtime($path);
    }

    return $url;
}

function ig_local_video_path(string $type, string $id): string
{
    return IG_MEDIA_DIR . '/' . $type . '_' . $id . '.mp4';
}

function is_preview_bot(?string $userAgent): bool
{
    if ($userAgent === null || $userAgent === '') {
        return false;
    }

    return (bool) preg_match(
        '/Discordbot|Slackbot|TelegramBot|Twitterbot|facebookexternalhit|LinkedInBot|WhatsApp|SkypeUriPreview/i',
        $userAgent
    );
}
