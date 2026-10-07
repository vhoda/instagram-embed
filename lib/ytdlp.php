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
