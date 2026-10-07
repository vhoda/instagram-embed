<?php
declare(strict_types=1);

define('IG_SITE_HOST', 'ig.weko.lol');
define('IG_SITE_URL', 'https://ig.weko.lol');
define('IG_CACHE_DIR', __DIR__ . '/cache');
// Archivos estáticos servidos por el webserver (como weko.lol/files/)
define('IG_MEDIA_DIR', __DIR__ . '/media');
define('IG_CACHE_TTL', 12 * 3600);
define('IG_DEFAULT_WIDTH', 720);
define('IG_DEFAULT_HEIGHT', 1280);
define('IG_MAX_VIDEO_BYTES', 50 * 1024 * 1024);

/**
 * Nota: .user.ini tiene open_basedir limitado a ig.weko.lol + /tmp
 * (archivo immutable; no se pudo ampliar a weko.lol). Por eso yt-dlp
 * vive en ig.weko.lol/bin/ y no se requiere WEKO_ROOT.
 */
