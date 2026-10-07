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
