<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/instagram-url.php';
require_once __DIR__ . '/lib/instagram-redirect.php';
require_once __DIR__ . '/lib/instagram-media.php';
require_once __DIR__ . '/lib/discord-embed.php';
require_once __DIR__ . '/lib/video-stream.php';

ini_set('max_execution_time', '180');

$typeRaw = $_GET['type'] ?? null;
$id = $_GET['id'] ?? null;
$route = parse_proxy_route(is_string($typeRaw) ? $typeRaw : null, is_string($id) ? $id : null);

if ($route === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ig.weko.lol — usa /reel/{id}/, /reels/{id}/, /p/{id}/ o /tv/{id}/\n";
    exit;
}

$query = $_SERVER['QUERY_STRING'] ?? '';
parse_str($query, $queryParams);
$wantRaw = isset($queryParams['raw']) && (string) $queryParams['raw'] !== '' && (string) $queryParams['raw'] !== '0';
unset($queryParams['type'], $queryParams['id'], $queryParams['raw']);
$forwardQuery = http_build_query($queryParams);

// Tipo de path original (reel vs reels) para el redirect a Instagram
$pathType = strtolower(is_string($typeRaw) ? $typeRaw : $route['type']);
if ($pathType !== 'reels') {
    $pathType = $route['type'];
}

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isBot = is_preview_bot($userAgent);

// Humanos: redirect a Instagram (web / iOS / Android). Bots: embed Discord.
if (!$wantRaw && !$isBot) {
    ig_redirect_human_to_instagram($pathType, $route['id'], $forwardQuery);
}

$pageUrl = build_proxy_page_url($route['type'], $route['id'], $forwardQuery, false);
$videoUrl = build_proxy_video_url($route['type'], $route['id']);
$localPath = ig_local_video_path($route['type'], $route['id']);
$instagramUrl = proxy_request_to_instagram_url($route['type'] . '/' . $route['id'] . '/', $forwardQuery)
    ?? ('https://www.instagram.com/' . $route['type'] . '/' . $route['id'] . '/');

$meta = resolve_instagram_video($route['type'], $route['id'], $forwardQuery);

if ($meta === null) {
    http_response_code(422);
    header('Content-Type: text/html; charset=utf-8');
    $safeIg = htmlspecialchars($instagramUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Video no disponible</title><link rel="stylesheet" href="/style.css"></head><body class="page page--error"><main class="card"><h1>Sin video</h1><p>No se encontró un video en este post de Instagram (puede ser solo foto, privado o restringido).</p><p><a class="btn" href="' . $safeIg . '" rel="noopener noreferrer">Abrir en Instagram</a></p></main></body></html>';
    exit;
}

// Materializar MP4 con audio (yt-dlp merge) en /media/
$instagramCanonical = (string) ($meta['instagram_url'] ?? $instagramUrl);
if (!ig_ensure_local_video($instagramCanonical, $localPath)) {
    error_log('[ig.weko.lol] no se pudo materializar video con audio: ' . $instagramCanonical);
}

if ($wantRaw) {
    if (is_file($localPath) && filesize($localPath) > 1000) {
        header('Location: ' . $videoUrl, true, 302);
        exit;
    }
    stream_remote_video($meta['direct_url'], $meta['mime'] ?? 'video/mp4');
    exit;
}

$ogTags = build_discord_video_og_tags($pageUrl, $videoUrl, $meta);
$pageTitle = 'ig.weko.lol';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $pageTitle ?></title>
    <?php foreach ($ogTags as $tag): ?>
    <?= $tag . "\n" ?>
    <?php endforeach; ?>
    <link rel="stylesheet" href="/style.css">
</head>
<body></body>
</html>
