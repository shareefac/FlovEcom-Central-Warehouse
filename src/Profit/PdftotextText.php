<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\CwException;

/**
 * P2gPdfText by poppler's pdftotext (`-layout`, the PDF on its standard input, the text on its standard output: nothing written to disk),
 * docs/decisions.md PGL7 (D5). Run as `nobody` when CW runs as root (runuser): a PDF from the internet is never read by a root process; at
 * most TIMEOUT_SEC and MAX_TEXT bytes. WAITS FOR THE PROBE: poppler-utils is on this server but not on the CW test box; the go-live kit's
 * check step says whether production has it (installing it is a server change the owner says yes to).
 */
final class PdftotextText implements P2gPdfText
{
    public const BIN = '/usr/bin/pdftotext';
    public const RUNUSER = '/usr/sbin/runuser';
    public const TIMEOUT_SEC = 10;
    public const MAX_TEXT = 1_000_000;

    public static function available(): bool
    {
        return is_file(self::BIN) && is_executable(self::BIN);
    }

    public function text(string $pdf): string
    {
        if (!self::available()) {
            throw new CwException('p2g_pdf_text', 'this server has no PDF reader for Parcel2Go\'s invoices (poppler-utils): tell the CW team', 503);
        }
        $cmd = [self::BIN, '-layout', '-enc', 'UTF-8', '-q', '-', '-'];
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            if (!is_executable(self::RUNUSER)) {
                throw new CwException('p2g_pdf_text', 'the PDF reader is never run as root and runuser is missing: tell the CW team', 503);
            }
            $cmd = [self::RUNUSER, '-u', 'nobody', '--', ...$cmd];
        }
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/', []);
        if (!is_resource($p)) {
            throw new CwException('p2g_pdf_text', 'the PDF reader could not start', 503);
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $in = $pdf;
        $until = microtime(true) + self::TIMEOUT_SEC;
        $why = null;
        while (true) {
            if ($in !== '' && is_resource($pipes[0])) {
                $n = @fwrite($pipes[0], substr($in, 0, 65536));
                $in = $n === false ? '' : substr($in, $n);
                if ($in === '' || $n === false) {
                    fclose($pipes[0]);
                }
            }
            $chunk = stream_get_contents($pipes[1]);
            if (is_string($chunk)) {
                $out .= $chunk;
            }
            stream_get_contents($pipes[2]); // its messages are never kept (they may quote the PDF)
            if (strlen($out) > self::MAX_TEXT) {
                $why = 'too much text';
                break;
            }
            $st = proc_get_status($p);
            if (!$st['running']) {
                $chunk = stream_get_contents($pipes[1]);
                $out .= is_string($chunk) ? $chunk : '';
                if ($st['exitcode'] !== 0) {
                    $why = 'it could not read the PDF (exit ' . $st['exitcode'] . ')';
                }
                break;
            }
            if (microtime(true) >= $until) {
                $why = 'it took more than ' . self::TIMEOUT_SEC . ' s';
                break;
            }
            usleep(20_000);
        }
        foreach ($pipes as $h) {
            if (is_resource($h)) {
                fclose($h);
            }
        }
        if ($why !== null) {
            proc_terminate($p, 9);
        }
        proc_close($p);
        if ($why !== null || strlen($out) > self::MAX_TEXT) {
            throw new CwException('p2g_pdf_text', 'the invoice\'s text could not be read: ' . ($why ?? 'too much text'), 422);
        }
        return $out;
    }
}
