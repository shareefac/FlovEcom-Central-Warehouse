<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\CwException;

/**
 * P2gPdfText by poppler's pdftotext (`-layout`, the PDF on its standard input, the text on its standard output: nothing written to disk),
 * docs/decisions.md PGL7 (D5). Run as `nobody` when CW runs as root (runuser): a PDF from the internet is never read by a root process. The
 * converter never holds the job (the final review, M15 / F12): it runs in its own process group (setsid) under a memory cap (prlimit
 * MAX_MEMORY), its input written and its output read without blocking (stream_select), and at the timeout or past MAX_TEXT bytes of text the
 * WHOLE group is killed (runuser and pdftotext, or anything they started), and pdftotext runs under `timeout --foreground -s KILL` as well
 * (G9). The go-live kit's prepare step installs poppler-utils on production.
 */
final class PdftotextText implements P2gPdfText
{
    public const BIN = '/usr/bin/pdftotext';
    public const RUNUSER = '/usr/sbin/runuser';
    public const SETSID = '/usr/bin/setsid';
    public const PRLIMIT = '/usr/bin/prlimit';
    public const TIMEOUT = '/usr/bin/timeout';
    public const TIMEOUT_SEC = 10;
    public const MAX_TEXT = 1_000_000;
    /** The converter's address-space cap (bytes). */
    public const MAX_MEMORY = 536_870_912;

    /** @param string $bin the converter (pdftotext; a test's stand-in) */
    public function __construct(private readonly string $bin = self::BIN, private readonly int $timeoutSec = self::TIMEOUT_SEC)
    {
    }

    public static function available(): bool
    {
        return is_file(self::BIN) && is_executable(self::BIN);
    }

    public function text(string $pdf): string
    {
        if (!is_file($this->bin) || !is_executable($this->bin)) {
            throw new CwException('p2g_pdf_text', 'this server has no PDF reader for Parcel2Go\'s invoices (poppler-utils): tell the CW team', 503);
        }
        foreach ([self::SETSID, self::PRLIMIT, self::TIMEOUT] as $tool) {
            if (!is_executable($tool)) {
                throw new CwException('p2g_pdf_text', 'the PDF reader\'s guard (' . basename($tool) . ') is missing on this server: tell the CW team', 503);
            }
        }
        // the converter's own clock as well (G9: should runuser ever give it a session of its own, the group kill would miss it): killed 2 s
        // after this one's timeout by its direct parent; --foreground keeps it in the group below
        $cmd = [self::TIMEOUT, '--foreground', '-s', 'KILL', (string) ($this->timeoutSec + 2), $this->bin, '-layout', '-enc', 'UTF-8', '-q', '-', '-'];
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            if (!is_executable(self::RUNUSER)) {
                throw new CwException('p2g_pdf_text', 'the PDF reader is never run as root and runuser is missing: tell the CW team', 503);
            }
            $cmd = [self::RUNUSER, '-u', 'nobody', '--', ...$cmd];
        }
        // its own session and process group (the group's id is its pid: killed whole), under the memory and CPU caps
        $cmd = [self::SETSID, self::PRLIMIT, '--as=' . self::MAX_MEMORY, '--cpu=' . ($this->timeoutSec + 5), '--', ...$cmd];
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/', []);
        if (!is_resource($p)) {
            throw new CwException('p2g_pdf_text', 'the PDF reader could not start', 503);
        }
        $pid = (int) (proc_get_status($p)['pid'] ?? 0);
        foreach ($pipes as $h) {
            stream_set_blocking($h, false);
        }
        $out = '';
        $in = $pdf;
        $until = microtime(true) + $this->timeoutSec;
        $why = null;
        $open = [1 => true, 2 => true];
        while (true) {
            $left = $until - microtime(true);
            if ($left <= 0) {
                $why = 'it took more than ' . $this->timeoutSec . ' s';
                break;
            }
            $read = [];
            foreach ($open as $k => $_) {
                $read[] = $pipes[$k];
            }
            $write = is_resource($pipes[0]) ? [$pipes[0]] : [];
            $except = null;
            if ($read === [] && $write === []) {
                break; // its output ended and its input is closed
            }
            $n = @stream_select($read, $write, $except, (int) floor($left), (int) (fmod($left, 1.0) * 1_000_000));
            if ($n === false) {
                $why = 'its pipes failed';
                break;
            }
            foreach ($write as $h) {
                $w = @fwrite($h, substr($in, 0, 65536));
                $in = $w === false ? '' : substr($in, $w);
                if ($in === '' || $w === false) {
                    fclose($pipes[0]);
                }
            }
            foreach ($read as $h) {
                $k = $h === $pipes[1] ? 1 : 2;
                $chunk = fread($h, 65536);
                if ($k === 1 && is_string($chunk)) {
                    $out .= $chunk; // its messages (2) are never kept: they may quote the PDF
                }
                if (feof($h)) {
                    unset($open[$k]);
                }
            }
            if (strlen($out) > self::MAX_TEXT) {
                $why = 'too much text';
                break;
            }
        }
        $code = null;
        while ($why === null) {
            // its output ended: its exit awaited until the same timeout (a converter that closed its output and hangs is killed too)
            $st = proc_get_status($p);
            if (!$st['running']) {
                $code = (int) $st['exitcode'];
                break;
            }
            if (microtime(true) >= $until) {
                $why = 'it took more than ' . $this->timeoutSec . ' s';
                break;
            }
            usleep(10_000);
        }
        if ($why !== null && $pid > 0 && function_exists('posix_kill')) {
            @posix_kill(-$pid, 9); // the whole group
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
        if ($why === null && $code !== 0) {
            $why = 'it could not read the PDF (exit ' . $code . ')';
        }
        if ($why !== null) {
            throw new CwException('p2g_pdf_text', 'the invoice\'s text could not be read: ' . $why, 422);
        }
        return $out;
    }
}
