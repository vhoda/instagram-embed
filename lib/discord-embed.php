<?php
declare(strict_types=1);

/**
 * Meta tags Open Graph / Twitter para Discord — mismo patrón que weko.lol/media.php.
 * Solo video: sin og:title, og:description, og:image ni cards summary.
 *
 * @param array{
 *   width?: int,
 *   height?: int,
 *   mime?: string
 * } $meta
 * @return list<string>
 */
function build_discord_video_og_tags(string $pageUrl, string $rawVideoUrl, array $meta = []): array
{
    $width = (int) ($meta['width'] ?? IG_DEFAULT_WIDTH);
    $height = (int) ($meta['height'] ?? IG_DEFAULT_HEIGHT);
    $mime = (string) ($meta['mime'] ?? 'video/mp4');

    if ($width <= 0) {
        $width = IG_DEFAULT_WIDTH;
    }
    if ($height <= 0) {
        $height = IG_DEFAULT_HEIGHT;
    }

    // No usar htmlspecialchars en URLs: Discord se confunde con &amp;
    return [
        '<meta property="og:type" content="video.other">',
        '<meta property="og:url" content="' . $pageUrl . '">',
        '<meta property="og:site_name" content="ig.weko.lol">',
        '<meta property="og:video" content="' . $rawVideoUrl . '">',
        '<meta property="og:video:secure_url" content="' . $rawVideoUrl . '">',
        '<meta property="og:video:type" content="' . $mime . '">',
        '<meta property="og:video:width" content="' . $width . '">',
        '<meta property="og:video:height" content="' . $height . '">',
        '<meta name="twitter:card" content="player">',
        '<meta name="twitter:player" content="' . $rawVideoUrl . '">',
        '<meta name="twitter:player:width" content="' . $width . '">',
        '<meta name="twitter:player:height" content="' . $height . '">',
    ];
}

/**
 * Meta OG para imagen — mismo patrón que weko.lol/media.php (sin título/descripción).
 *
 * @param array{width?: int, height?: int, mime?: string} $meta
 * @return list<string>
 */
function build_discord_image_og_tags(string $pageUrl, string $imageUrl, array $meta = []): array
{
    $width = (int) ($meta['width'] ?? 1080);
    $height = (int) ($meta['height'] ?? 1080);
    $mime = (string) ($meta['mime'] ?? 'image/jpeg');

    if ($width <= 0) {
        $width = 1080;
    }
    if ($height <= 0) {
        $height = 1080;
    }

    // Igual que weko imágenes: solo la imagen, sin título/descripción.
    return [
        '<meta property="og:type" content="website">',
        '<meta property="og:image" content="' . $imageUrl . '">',
        '<meta property="og:image:type" content="' . $mime . '">',
        '<meta property="og:image:width" content="' . $width . '">',
        '<meta property="og:image:height" content="' . $height . '">',
        '<meta name="twitter:card" content="summary_large_image">',
        '<meta name="twitter:image" content="' . $imageUrl . '">',
    ];
}
