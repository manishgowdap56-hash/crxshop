<?php
declare(strict_types=1);

/*
 * MP4 "Fast Start" helpers (pure PHP - no ffmpeg / exec needed, safe on shared hosting).
 *
 * Many encoders (phones, screen recorders, ffmpeg default) write the "moov" index at the END of the file.
 * A browser then cannot start playing until it has fetched the end of the file, which shows up as long
 * stalls / repeated buffering on mobile networks. This moves "moov" in front of "mdat" WITHOUT re-encoding
 * (lossless, same bytes, same size) and shifts the chunk offsets (stco / co64) accordingly.
 *
 * Streaming copy only: memory use is bounded by the size of the moov index (capped), never the video size.
 * On ANY doubt the functions return a 'skipped:*' result and the caller keeps the original upload untouched.
 */

const MP4_MOOV_MAX_BYTES = 33554432;   // 32 MB: far above any normal moov; refuse anything stranger

/** Top-level atoms: [['type'=>..,'pos'=>..,'size'=>..,'hdr'=>..], ...] or null if the structure is not sane. */
function mp4_top_atoms($fh, int $fileSize): ?array
{
    $atoms = [];
    $pos = 0;
    while ($pos + 8 <= $fileSize && count($atoms) < 256) {
        if (fseek($fh, $pos) !== 0) return null;
        $h = fread($fh, 16);
        if ($h === false || strlen($h) < 8) return null;
        $u = unpack('Nsize/a4type', $h);
        $size = (int)$u['size'];
        $hdr = 8;
        if ($size === 1) {
            if (strlen($h) < 16) return null;
            $size = (int)unpack('J', substr($h, 8, 8))[1];
            $hdr = 16;
        } elseif ($size === 0) {
            $size = $fileSize - $pos;          // "extends to end of file"
        }
        if ($size < $hdr || $pos + $size > $fileSize) return null;
        $atoms[] = ['type' => (string)$u['type'], 'pos' => $pos, 'size' => $size, 'hdr' => $hdr];
        $pos += $size;
    }
    return $atoms;
}

/**
 * Add $delta to every chunk offset (stco / co64) that is smaller than $threshold, in place inside the moov bytes.
 * Offsets >= $threshold point behind the old moov, whose absolute position does not change, so they are left alone.
 * $tables counts how many offset tables were patched (0 => unknown/compressed structure => caller must skip).
 */
function mp4_patch_offsets(string &$buf, int $start, int $end, int $delta, int $threshold, int &$tables): bool
{
    $containers = ['moov' => 1, 'trak' => 1, 'mdia' => 1, 'minf' => 1, 'stbl' => 1];
    $pos = $start;
    while ($pos + 8 <= $end) {
        $size = (int)unpack('N', substr($buf, $pos, 4))[1];
        $type = substr($buf, $pos + 4, 4);
        $hdr = 8;
        if ($size === 1) {
            if ($pos + 16 > $end) return false;
            $size = (int)unpack('J', substr($buf, $pos + 8, 8))[1];
            $hdr = 16;
        } elseif ($size === 0) {
            $size = $end - $pos;
        }
        if ($size < $hdr || $pos + $size > $end) return false;
        $bodyStart = $pos + $hdr;
        $bodyEnd = $pos + $size;

        if (isset($containers[$type]) && $type !== 'moov') {
            if (!mp4_patch_offsets($buf, $bodyStart, $bodyEnd, $delta, $threshold, $tables)) return false;
        } elseif ($type === 'stco' || $type === 'co64') {
            if ($bodyStart + 8 > $bodyEnd) return false;
            $count = (int)unpack('N', substr($buf, $bodyStart + 4, 4))[1];
            $w = ($type === 'stco') ? 4 : 8;
            if ($bodyStart + 8 + $count * $w > $bodyEnd) return false;
            $p = $bodyStart + 8;
            $left = $count;
            while ($left > 0) {                         // chunked: bounded memory even for huge tables
                $n = min($left, 4096);
                $raw = substr($buf, $p, $n * $w);
                $vals = unpack(($w === 4 ? 'N' : 'J') . '*', $raw);
                $new = '';
                foreach ($vals as $v) {
                    $v = (int)$v;
                    if ($v < $threshold) $v += $delta;
                    if ($w === 4 && $v > 0xFFFFFFFF) return false;   // would need co64: do not guess, skip
                    $new .= ($w === 4) ? pack('N', $v) : pack('J', $v);
                }
                $buf = substr_replace($buf, $new, $p, $n * $w);
                $p += $n * $w;
                $left -= $n;
            }
            $tables++;
        }
        $pos += $size;
    }
    return true;
}

/**
 * Writes a fast-start copy of $src to $dst.
 * Returns: 'optimized'      - $dst written (moov now before mdat, same size, same media bytes)
 *          'already'        - moov already in front (or fragmented MP4): nothing written, use $src as it is
 *          'skipped:<why>'  - could not / should not optimize: nothing left at $dst, use $src as it is
 */
function mp4_faststart_file(string $src, string $dst): string
{
    if (PHP_INT_SIZE < 8) return 'skipped:32bit-php';
    clearstatcache(true, $src);
    $size = @filesize($src);
    if (!is_int($size) || $size < 32) return 'skipped:size';

    $in = @fopen($src, 'rb');
    if (!$in) return 'skipped:open';
    $out = null;
    try {
        $atoms = mp4_top_atoms($in, $size);
        if ($atoms === null || !$atoms) return 'skipped:structure';
        if ($atoms[0]['type'] !== 'ftyp') return 'skipped:no-ftyp-first';

        $moov = null;
        $mdatPos = null;
        foreach ($atoms as $a) {
            if ($a['type'] === 'moof') return 'already';             // fragmented MP4 streams natively
            if ($a['type'] === 'moov' && $moov === null) $moov = $a;
            if ($a['type'] === 'mdat' && $mdatPos === null) $mdatPos = $a['pos'];
        }
        if ($moov === null || $mdatPos === null) return 'skipped:no-moov-or-mdat';
        if ($moov['pos'] < $mdatPos) return 'already';
        if ($moov['size'] > MP4_MOOV_MAX_BYTES) return 'skipped:moov-too-large';

        $ftypEnd = $atoms[0]['pos'] + $atoms[0]['size'];
        if ($ftypEnd > $moov['pos']) return 'skipped:layout';

        if (fseek($in, $moov['pos']) !== 0) return 'skipped:seek';
        $buf = (string)fread($in, $moov['size']);
        if (strlen($buf) !== $moov['size']) return 'skipped:moov-read';

        $tables = 0;
        // delta = size of the moov that is being inserted in front of the media data
        if (!mp4_patch_offsets($buf, $moov['hdr'], $moov['size'], $moov['size'], $moov['pos'], $tables) || $tables === 0) {
            return 'skipped:offset-tables';
        }

        $out = @fopen($dst, 'wb');
        if (!$out) return 'skipped:write-open';

        $ok = true;
        // 1) ftyp
        $ok = $ok && fseek($in, 0) === 0 && stream_copy_to_stream($in, $out, $ftypEnd) === $ftypEnd;
        // 2) patched moov
        $ok = $ok && fwrite($out, $buf) === strlen($buf);
        // 3) everything between ftyp and the old moov (free / mdat / ...)
        $mid = $moov['pos'] - $ftypEnd;
        $ok = $ok && fseek($in, $ftypEnd) === 0 && stream_copy_to_stream($in, $out, $mid) === $mid;
        // 4) anything after the old moov
        $tailStart = $moov['pos'] + $moov['size'];
        $tail = $size - $tailStart;
        if ($ok && $tail > 0) {
            $ok = fseek($in, $tailStart) === 0 && stream_copy_to_stream($in, $out, $tail) === $tail;
        }
        $ok = $ok && fflush($out);
        fclose($out);
        $out = null;

        clearstatcache(true, $dst);
        if (!$ok || @filesize($dst) !== $size) {
            @unlink($dst);
            return 'skipped:write-failed';
        }
        return 'optimized';
    } catch (Throwable $e) {
        if (is_resource($out)) fclose($out);
        $out = null;
        if (is_file($dst)) @unlink($dst);
        return 'skipped:exception';
    } finally {
        if (is_resource($in)) fclose($in);
        if (is_resource($out)) fclose($out);
    }
}

/**
 * Read-only description of a stored video file (for the admin report).
 * faststart: true / false / null (unknown or not an MP4). avg_mbps: average bitrate from the file size and duration.
 */
function mp4_inspect(string $path): array
{
    $info = ['bytes' => 0, 'mb' => 0.0, 'kind' => 'unknown', 'faststart' => null, 'fragmented' => false,
             'duration_s' => null, 'avg_mbps' => null];
    clearstatcache(true, $path);
    $size = @filesize($path);
    if (!is_int($size) || $size <= 0) return $info;
    $info['bytes'] = $size;
    $info['mb'] = round($size / 1048576, 2);

    $fh = @fopen($path, 'rb');
    if (!$fh) return $info;
    try {
        $head = (string)fread($fh, 4);
        if ($head === "\x1A\x45\xDF\xA3") { $info['kind'] = 'webm'; return $info; }

        $atoms = mp4_top_atoms($fh, $size);
        if (!$atoms || $atoms[0]['type'] !== 'ftyp') return $info;
        $info['kind'] = 'mp4';

        $moov = null;
        $mdatPos = null;
        foreach ($atoms as $a) {
            if ($a['type'] === 'moof') $info['fragmented'] = true;
            if ($a['type'] === 'moov' && $moov === null) $moov = $a;
            if ($a['type'] === 'mdat' && $mdatPos === null) $mdatPos = $a['pos'];
        }
        if ($moov !== null && $mdatPos !== null) {
            $info['faststart'] = $info['fragmented'] ? true : ($moov['pos'] < $mdatPos);
        }
        if ($moov !== null && $moov['size'] <= MP4_MOOV_MAX_BYTES && fseek($fh, $moov['pos']) === 0) {
            $buf = (string)fread($fh, $moov['size']);
            $p = $moov['hdr'];
            while ($p + 8 <= strlen($buf)) {
                $s = (int)unpack('N', substr($buf, $p, 4))[1];
                $t = substr($buf, $p + 4, 4);
                if ($s < 8 || $p + $s > strlen($buf)) break;
                if ($t === 'mvhd') {
                    $b = $p + 8;
                    if (ord($buf[$b]) === 1) {
                        $ts = (int)unpack('N', substr($buf, $b + 20, 4))[1];
                        $du = (int)unpack('J', substr($buf, $b + 24, 8))[1];
                    } else {
                        $ts = (int)unpack('N', substr($buf, $b + 12, 4))[1];
                        $du = (int)unpack('N', substr($buf, $b + 16, 4))[1];
                    }
                    if ($ts > 0 && $du > 0) {
                        $info['duration_s'] = round($du / $ts, 1);
                        $info['avg_mbps'] = round(($size * 8 / ($du / $ts)) / 1000000, 2);
                    }
                    break;
                }
                $p += $s;
            }
        }
    } finally {
        fclose($fh);
    }
    return $info;
}
