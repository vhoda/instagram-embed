<?php
declare(strict_types=1);

/**
 * Redirección a Instagram: escritorio → web; iOS/Android → app (con fallback web).
 */

function ig_is_mobile_browser(?string $ua = null): bool
{
    $ua = $ua ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
    return (bool) preg_match('/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i', $ua);
}

function ig_is_ios(?string $ua = null): bool
{
    $ua = $ua ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
    return (bool) preg_match('/iPhone|iPad|iPod/i', $ua);
}

function ig_is_android(?string $ua = null): bool
{
    $ua = $ua ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
    return (bool) preg_match('/Android/i', $ua);
}

/**
 * @return array{web: string, ios: string, android: string}
 */
function ig_instagram_open_urls(string $type, string $id, string $query = ''): array
{
    $type = strtolower($type);
    if ($type === 'reels') {
        $pathType = 'reels';
        $normType = 'reel';
    } elseif (in_array($type, ['reel', 'p', 'tv'], true)) {
        $pathType = $type;
        $normType = $type;
    } else {
        $pathType = 'reel';
        $normType = 'reel';
    }

    $web = 'https://www.instagram.com/' . $pathType . '/' . $id . '/';
    if ($query !== '') {
        parse_str($query, $params);
        unset($params['raw'], $params['type'], $params['id']);
        if ($params !== []) {
            $web .= '?' . http_build_query($params);
        }
    }

    // iOS: universal link HTTPS abre la app si está instalada
    $ios = $web;

    // Android: Intent hacia la app, fallback al navegador
    $fallback = rawurlencode($web);
    $hostPath = 'www.instagram.com/' . $pathType . '/' . $id . '/';
    if (str_contains($web, '?')) {
        $q = substr($web, strpos($web, '?') + 1);
        $android = 'intent://' . $hostPath . '?' . $q
            . '#Intent;package=com.instagram.android;scheme=https;S.browser_fallback_url=' . $fallback . ';end';
    } else {
        $android = 'intent://' . $hostPath
            . '#Intent;package=com.instagram.android;scheme=https;S.browser_fallback_url=' . $fallback . ';end';
    }

    unset($normType);

    return [
        'web' => $web,
        'ios' => $ios,
        'android' => $android,
    ];
}

/**
 * Redirige al visitante humano a Instagram (no usar para Discordbot).
 */
function ig_redirect_human_to_instagram(string $type, string $id, string $query = ''): void
{
    $urls = ig_instagram_open_urls($type, $id, $query);
    $web = $urls['web'];
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if (!ig_is_mobile_browser($ua) || (!ig_is_ios($ua) && !ig_is_android($ua))) {
        header('Location: ' . $web, true, 302);
        exit;
    }

    $appUrl = ig_is_ios($ua) ? $urls['ios'] : $urls['android'];

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $webJson = json_encode($web, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_SLASHES);
    $appJson = json_encode($appUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_SLASHES);
    $webEsc = htmlspecialchars($web, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Abriendo Instagram…</title>';
    echo '<link rel="stylesheet" href="/style.css">';
    echo '</head><body class="page page--error"><main class="card">';
    echo '<p class="badge">ig.weko.lol</p>';
    echo '<h1>Abriendo Instagram…</h1>';
    echo '<p class="meta">Si no se abre, usa el botón.</p>';
    echo '<div class="actions"><a class="btn btn--primary" href="' . $webEsc . '">Abrir en Instagram</a></div>';
    echo '</main>';
    echo '<script>(function(){var app=' . $appJson . ';var web=' . $webJson . ';var left=false;';
    echo 'document.addEventListener("visibilitychange",function(){if(document.hidden)left=true;});';
    echo 'window.addEventListener("pagehide",function(){left=true;});';
    echo 'window.location.href=app;';
    echo 'setTimeout(function(){if(!left)window.location.replace(web);},2200);})();</script>';
    echo '</body></html>';
    exit;
}
