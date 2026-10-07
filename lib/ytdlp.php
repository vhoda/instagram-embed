<?php
declare(strict_types=1);

/**
 * Invocación robusta de yt-dlp para Instagram (reintentos + formatos).
 */

function ig_ytdlp_path(): string|false
{
    $localPath = __DIR__ . '/../bin/yt-dlp';
    if (@is_file($localPath) && @is_executable($localPath)) {
        return $localPath;
    }

    return false;
}

function ig_ytdlp_common_args(): string
{
    $cookiesArg = '';
    $cookiesPath = __DIR__ . '/../cookies/instagram.txt';
    if (@is_file($cookiesPath)) {
        $cookiesArg = ' --cookies ' . escapeshellarg($cookiesPath);
    }

    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    return $cookiesArg
        . ' --user-agent ' . escapeshellarg($ua)
        . ' --socket-timeout 20'
        . ' --retries 3'
        . ' --fragment-retries 3';
}

/**
 * @return array{output: ?string, warnings: string, command: string}
 */
function ig_ytdlp_dump_json(string $url): array
{
    $ytdlp = ig_ytdlp_path();
    if ($ytdlp === false) {
        return [
            'output' => null,
            'warnings' => 'yt-dlp no encontrado en ig.weko.lol/bin',
            'command' => '',
        ];
    }

    $lastWarnings = '';
    $lastCommand = '';

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        if ($attempt > 1) {
            usleep(400000 * $attempt);
        }

        $stderrFile = sys_get_temp_dir() . '/ig_ytdl_' . uniqid('', true) . '.err';
        $command = $ytdlp
            . ' --no-cache-dir --no-playlist'
            . ig_ytdlp_common_args()
            . ' --dump-json '
            . escapeshellarg($url)
            . ' 2>' . escapeshellarg($stderrFile);

        $output = shell_exec($command);
        $warnings = @file_get_contents($stderrFile) ?: '';
        @unlink($stderrFile);
        $lastWarnings = $warnings;
        $lastCommand = $command;

        if (is_string($output) && $output !== '' && json_decode($output, true)) {
            return [
                'output' => $output,
                'warnings' => $warnings,
                'command' => $command,
            ];
        }

        // Rate-limit / login: reintentar
        if (!preg_match('/rate.?limit|login required|403|429|unable to extract|challenge/i', $warnings)) {
            break;
        }
    }

    return [
        'output' => null,
        'warnings' => $lastWarnings !== '' ? $lastWarnings : 'respuesta vacía de yt-dlp',
        'command' => $lastCommand,
    ];
}

/**
 * Estrategias de formato: rápido progressive → merge 720p → merge best.
 *
 * @return list<string>
 */
function ig_ytdlp_format_strategies(): array
{
    // Discord: calidad irrelevante → priorizar ~512p (rápido, poco peso, alto success).
    // Fallbacks si Instagram no ofrece ese rango.
    return [
        'bestvideo*[height<=512]+bestaudio/best[height<=512]/best',
        'bestvideo*[height<=540]+bestaudio/best[height<=540]/best',
        'bestvideo*[height<=720]+bestaudio/best[height<=720]/best',
        '1/2/3',
        'bestvideo*+bestaudio/best',
    ];
}

/**
 * Descarga MP4 con audio y opcionalmente lee info-json.
 * Una sola pasada (sin dump-json previo).
 *
 * @return array{ok: bool, meta: ?array, warnings: string}
 */
function ig_ytdlp_download(string $url, string $outputPath): array
{
    $ytdlp = ig_ytdlp_path();
    if ($ytdlp === false) {
        return ['ok' => false, 'meta' => null, 'warnings' => 'yt-dlp no encontrado'];
    }

    $dir = dirname($outputPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $lastWarnings = '';

    foreach (ig_ytdlp_format_strategies() as $format) {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            if ($attempt > 1) {
                usleep(350000 * $attempt);
            }

            $tmpBase = $outputPath . '.part.' . getmypid() . '.' . substr(uniqid('', true), -6);
            $tmpOut = $tmpBase . '.mp4';
            $infoJson = $tmpBase . '.info.json';
            $stderrFile = sys_get_temp_dir() . '/ig_ytdl_dl_' . uniqid('', true) . '.err';

            $command = $ytdlp
                . ' --no-cache-dir --no-playlist'
                . ig_ytdlp_common_args()
                . ' -f ' . escapeshellarg($format)
                . ' --merge-output-format mp4'
                . ' --write-info-json --no-write-playlist-metafiles'
                . ' -o ' . escapeshellarg($tmpOut)
                . ' ' . escapeshellarg($url)
                . ' 2>' . escapeshellarg($stderrFile);

            $output = [];
            $code = 1;
            exec($command, $output, $code);
            $warnings = @file_get_contents($stderrFile) ?: '';
            @unlink($stderrFile);
            $lastWarnings = $warnings;

            $found = ig_ytdlp_find_output($tmpBase, $tmpOut);
            $meta = null;

            // info-json puede quedar como *.info.json junto al mp4
            $infoCandidates = array_merge(
                [$infoJson],
                glob($tmpBase . '*.info.json') ?: [],
                $found ? [preg_replace('/\.(mp4|mkv|webm)$/i', '.info.json', $found) ?: ''] : []
            );
            foreach ($infoCandidates as $infoPath) {
                if ($infoPath !== '' && is_file($infoPath)) {
                    $decoded = json_decode((string) file_get_contents($infoPath), true);
                    if (is_array($decoded)) {
                        $meta = $decoded;
                    }
                    @unlink($infoPath);
                    break;
                }
            }

            if ($code === 0 && $found !== null) {
                if (!@rename($found, $outputPath)) {
                    if (!@copy($found, $outputPath)) {
                        @unlink($found);
                        ig_ytdlp_cleanup_tmp($tmpBase);
                        continue;
                    }
                    @unlink($found);
                }
                ig_ytdlp_cleanup_tmp($tmpBase);
                @chmod($outputPath, 0664);

                if (is_file($outputPath) && filesize($outputPath) > 1000) {
                    @file_put_contents($outputPath . '.ready', (string) time());
                    return [
                        'ok' => true,
                        'meta' => $meta,
                        'warnings' => $warnings,
                    ];
                }
            }

            ig_ytdlp_cleanup_tmp($tmpBase);

            // Si el formato no existe, pasar a la siguiente estrategia
            if (preg_match('/Requested format is not available|Only images are available/i', $warnings)) {
                break;
            }
        }
    }

    error_log('[ig.weko.lol] yt-dlp download falló: ' . $lastWarnings);

    return ['ok' => false, 'meta' => null, 'warnings' => $lastWarnings];
}

function ig_ytdlp_find_output(string $tmpBase, string $tmpOut): ?string
{
    $candidates = [$tmpOut, $tmpBase . '.mp4', $tmpBase];
    foreach ($candidates as $cand) {
        if (is_file($cand) && filesize($cand) > 1000) {
            return $cand;
        }
    }

    foreach (glob($tmpBase . '*') ?: [] as $cand) {
        if (is_file($cand) && filesize($cand) > 1000 && preg_match('/\.(mp4|mkv|webm)$/i', $cand)) {
            return $cand;
        }
    }

    return null;
}

function ig_ytdlp_cleanup_tmp(string $tmpBase): void
{
    foreach (glob($tmpBase . '*') ?: [] as $junk) {
        @unlink($junk);
    }
}

/**
 * ¿El dump-json parece post solo-imagen (o carrusel sin video)?
 */
function ig_ytdlp_info_has_video(array $info): bool
{
    if (!empty($info['duration']) && (float) $info['duration'] > 0) {
        return true;
    }

    $vcodec = $info['vcodec'] ?? null;
    if (is_string($vcodec) && $vcodec !== '' && $vcodec !== 'none') {
        return true;
    }

    // Reutilizar picker de formatos de video si está cargado
    if (function_exists('ig_pick_best_video_format')) {
        return ig_pick_best_video_format($info) !== null;
    }

    if (!empty($info['formats']) && is_array($info['formats'])) {
        foreach ($info['formats'] as $fmt) {
            if (!is_array($fmt)) {
                continue;
            }
            $v = $fmt['vcodec'] ?? null;
            if (is_string($v) && $v !== '' && $v !== 'none') {
                return true;
            }
        }
    }

    return false;
}

/**
 * Descarga imagen (jpg/png/webp) de un /p/ u otro post sin video.
 * $outputBase sin extensión → escribe p_ID.jpg (u otra ext).
 *
 * @return array{ok: bool, path: ?string, meta: ?array, warnings: string}
 */
function ig_ytdlp_download_image(string $url, string $outputBase): array
{
    $ytdlp = ig_ytdlp_path();
    if ($ytdlp === false) {
        return ['ok' => false, 'path' => null, 'meta' => null, 'warnings' => 'yt-dlp no encontrado'];
    }

    $dir = dirname($outputBase);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $lastWarnings = '';

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        if ($attempt > 1) {
            usleep(400000 * $attempt);
        }

        $tmpBase = $outputBase . '.part.' . getmypid() . '.' . substr(uniqid('', true), -6);
        $tmpOut = $tmpBase . '.%(ext)s';
        $stderrFile = sys_get_temp_dir() . '/ig_ytdl_img_' . uniqid('', true) . '.err';

        // Sin -f restrictivo: yt-dlp elige la imagen del post
        $command = $ytdlp
            . ' --no-cache-dir --no-playlist'
            . ig_ytdlp_common_args()
            . ' --write-info-json --no-write-playlist-metafiles'
            . ' -o ' . escapeshellarg($tmpOut)
            . ' ' . escapeshellarg($url)
            . ' 2>' . escapeshellarg($stderrFile);

        $output = [];
        $code = 1;
        exec($command, $output, $code);
        $warnings = @file_get_contents($stderrFile) ?: '';
        @unlink($stderrFile);
        $lastWarnings = $warnings;

        $found = null;
        foreach (glob($tmpBase . '*') ?: [] as $cand) {
            if (!is_file($cand) || filesize($cand) < 200) {
                continue;
            }
            if (preg_match('/\.(jpg|jpeg|png|webp)$/i', $cand)) {
                $found = $cand;
                break;
            }
        }

        $meta = null;
        foreach (glob($tmpBase . '*.info.json') ?: [] as $infoPath) {
            $decoded = json_decode((string) file_get_contents($infoPath), true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
            @unlink($infoPath);
        }

        if ($code === 0 && $found !== null) {
            $ext = strtolower(pathinfo($found, PATHINFO_EXTENSION) ?: 'jpg');
            if ($ext === 'jpeg') {
                $ext = 'jpg';
            }
            $finalPath = $outputBase . '.' . $ext;

            // Limpiar otras extensiones previas del mismo post
            foreach (['jpg', 'jpeg', 'png', 'webp'] as $oldExt) {
                $old = $outputBase . '.' . $oldExt;
                if ($old !== $finalPath && is_file($old)) {
                    @unlink($old);
                    @unlink($old . '.ready');
                }
            }

            if (!@rename($found, $finalPath)) {
                if (!@copy($found, $finalPath)) {
                    ig_ytdlp_cleanup_tmp($tmpBase);
                    continue;
                }
                @unlink($found);
            }
            ig_ytdlp_cleanup_tmp($tmpBase);
            @chmod($finalPath, 0664);
            @file_put_contents($finalPath . '.ready', (string) time());

            return [
                'ok' => true,
                'path' => $finalPath,
                'meta' => $meta,
                'warnings' => $warnings,
            ];
        }

        ig_ytdlp_cleanup_tmp($tmpBase);

        if (!preg_match('/rate.?limit|login required|403|429|empty media|unable to extract/i', $warnings)) {
            break;
        }
    }

    error_log('[ig.weko.lol] yt-dlp image download falló: ' . $lastWarnings);

    return ['ok' => false, 'path' => null, 'meta' => null, 'warnings' => $lastWarnings];
}

/**
 * Fallback: Instagram /p/{id}/media/?size=l → JPEG (yt-dlp no soporta posts solo-foto).
 *
 * @return array{ok: bool, path: ?string, meta: ?array, warnings: string}
 */
function ig_fetch_instagram_image_direct(string $shortcode, string $outputBase, string $query = ''): array
{
    $dir = dirname($outputBase);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    parse_str($query, $params);
    unset($params['raw'], $params['type'], $params['id']);

    foreach (['l', 'm'] as $size) {
        $url = 'https://www.instagram.com/p/' . rawurlencode($shortcode) . '/media/?size=' . $size;
        if ($params !== []) {
            $url .= '&' . http_build_query($params);
        }

        $tmp = $outputBase . '.part.' . getmypid() . '.jpg';
        $ch = curl_init($url);
        if ($ch === false) {
            continue;
        }

        $fh = fopen($tmp, 'wb');
        if ($fh === false) {
            curl_close($ch);
            continue;
        }

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_FILE => $fh,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                'Referer: https://www.instagram.com/',
            ],
        ]);

        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        fclose($fh);

        if ($ok === false || $code >= 400 || !is_file($tmp) || filesize($tmp) < 500) {
            @unlink($tmp);
            continue;
        }

        // Validar que sea imagen
        if (!preg_match('#^image/#i', $contentType)) {
            $probe = @getimagesize($tmp);
            if ($probe === false) {
                @unlink($tmp);
                continue;
            }
        }

        $finalPath = $outputBase . '.jpg';
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $oldExt) {
            $old = $outputBase . '.' . $oldExt;
            if ($old !== $finalPath && is_file($old)) {
                @unlink($old);
                @unlink($old . '.ready');
            }
        }

        if (!@rename($tmp, $finalPath)) {
            if (!@copy($tmp, $finalPath)) {
                @unlink($tmp);
                continue;
            }
            @unlink($tmp);
        }

        @chmod($finalPath, 0664);
        @file_put_contents($finalPath . '.ready', (string) time());

        $w = 1080;
        $h = 1080;
        $sizeInfo = @getimagesize($finalPath);
        if (is_array($sizeInfo)) {
            $w = (int) $sizeInfo[0];
            $h = (int) $sizeInfo[1];
        }

        return [
            'ok' => true,
            'path' => $finalPath,
            'meta' => [
                'width' => $w,
                'height' => $h,
                'ext' => 'jpg',
                'title' => 'Instagram p',
            ],
            'warnings' => '',
        ];
    }

    return [
        'ok' => false,
        'path' => null,
        'meta' => null,
        'warnings' => 'media/?size=l falló',
    ];
}
