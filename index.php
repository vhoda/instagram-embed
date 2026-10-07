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

$pathType = strtolower(is_string($typeRaw) ? $typeRaw : $route['type']);
if ($pathType !== 'reels') {
    $pathType = $route['type'];
}

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isBot = is_preview_bot($userAgent);

// Humanos: redirect a Instagram. Bots / ?raw=1: embed o stream.
if (!$wantRaw && !$isBot) {
    ig_redirect_human_to_instagram($pathType, $route['id'], $forwardQuery);
}

$pageUrl = build_proxy_page_url($route['type'], $route['id'], $forwardQuery, false);
$prepared = prepare_instagram_embed($route['type'], $route['id'], $forwardQuery);

if ($prepared === null) {
    // No mentir a Discord con og:video roto (evita cachear fallos)
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $ig = htmlspecialchars(
        'https://www.instagram.com/' . $route['type'] . '/' . $route['id'] . '/',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>ig.weko.lol</title></head><body>';
    echo '<p>Video temporalmente no disponible. <a href="' . $ig . '">Abrir en Instagram</a></p>';
    echo '</body></html>';
    exit;
}

$meta = $prepared['meta'];
$videoUrl = $prepared['video_url'];
$localPath = $prepared['local_path'];

if ($wantRaw) {
    // 302 al estático (Content-Length correcto vía webserver/CF)
    header('Location: ' . $videoUrl, true, 302);
    exit;
}

$ogTags = build_discord_video_og_tags($pageUrl, $videoUrl, $meta);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=120');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ig.weko.lol</title>
    <?php foreach ($ogTags as $tag): ?>
    <?= $tag . "\n" ?>
    <?php endforeach; ?>
</head>
<body></body>
</html>
<?php
// Tras responder al bot, no hay más trabajo; el MP4 ya está en disco.
