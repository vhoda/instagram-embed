<?php
declare(strict_types=1);

/**
 * Invocación mínima de yt-dlp para Instagram (autónomo en ig.weko.lol).
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

    return $cookiesArg . ' --user-agent ' . escapeshellarg($ua);
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

    $stderrFile = sys_get_temp_dir() . '/ig_ytdl_' . uniqid('', true) . '.err';
    $command = $ytdlp
        . ' --no-cache-dir'
        . ig_ytdlp_common_args()
        . ' --dump-json --no-playlist '
        . escapeshellarg($url)
        . ' 2>' . escapeshellarg($stderrFile);

    $output = shell_exec($command);
    $warnings = @file_get_contents($stderrFile) ?: '';
    @unlink($stderrFile);

    if (is_string($output) && $output !== '' && json_decode($output, true)) {
        return [
            'output' => $output,
            'warnings' => $warnings,
            'command' => $command,
        ];
    }

    return [
        'output' => null,
        'warnings' => $warnings !== '' ? $warnings : 'respuesta vacía de yt-dlp',
        'command' => $command,
    ];
}

/**
 * Descarga MP4 con audio: prioriza progressive (h264+aac), si no merge DASH.
 */
function ig_ytdlp_download(string $url, string $outputPath): bool
{
    $ytdlp = ig_ytdlp_path();
    if ($ytdlp === false) {
        return false;
    }

    $dir = dirname($outputPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $tmpBase = $outputPath . '.part.' . getmypid();
    $tmpOut = $tmpBase . '.mp4';

    // 1/2/3 = progressive muxed (h264+aac) en Instagram; fallback merge DASH
    $format = '1/2/3/bestvideo*+bestaudio/best';
    $stderrFile = sys_get_temp_dir() . '/ig_ytdl_dl_' . uniqid('', true) . '.err';

    $command = $ytdlp
        . ' --no-cache-dir --no-playlist'
        . ig_ytdlp_common_args()
        . ' -f ' . escapeshellarg($format)
        . ' --merge-output-format mp4'
        . ' -o ' . escapeshellarg($tmpOut)
        . ' ' . escapeshellarg($url)
        . ' 2>' . escapeshellarg($stderrFile);

    $output = [];
    $code = 1;
    exec($command, $output, $code);
    $warnings = @file_get_contents($stderrFile) ?: '';
    @unlink($stderrFile);

    // yt-dlp puede escribir exactamente $tmpOut o con extensión añadida
    $candidates = [$tmpOut, $tmpBase . '.mp4', $tmpBase];
    $found = null;
    foreach ($candidates as $cand) {
        if (is_file($cand) && filesize($cand) > 1000) {
            $found = $cand;
            break;
        }
    }

    // Buscar por glob por si el nombre varía
    if ($found === null) {
        $matches = glob($tmpBase . '*') ?: [];
        foreach ($matches as $cand) {
            if (is_file($cand) && filesize($cand) > 1000 && preg_match('/\.(mp4|mkv|webm)$/i', $cand)) {
                $found = $cand;
                break;
            }
        }
    }

    if ($code !== 0 || $found === null) {
        error_log('[ig.weko.lol] yt-dlp download falló: ' . $warnings);
        foreach (glob($tmpBase . '*') ?: [] as $junk) {
            @unlink($junk);
        }
        return false;
    }

    // Remux a mp4 h264-friendly si hace falta (ya debería ser mp4)
    if (!@rename($found, $outputPath)) {
        if (!@copy($found, $outputPath)) {
            @unlink($found);
            return false;
        }
        @unlink($found);
    }

    foreach (glob($tmpBase . '*') ?: [] as $junk) {
        @unlink($junk);
    }

    @chmod($outputPath, 0664);
    return is_file($outputPath) && filesize($outputPath) > 1000;
}
