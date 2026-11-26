<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\CwException;
use CW\Profit\PdftotextText;
use PHPUnit\Framework\TestCase;

/**
 * CW-1160 M15 / F12 (the final review): the PDF reader's process never holds the job: a converter that stops reading its input (a PDF larger
 * than the pipe) or never ends is stopped at the timeout, its whole process group killed; it runs under a memory cap. A stand-in converter
 * (a shell script) plays pdftotext.
 */
final class PdftotextTextTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cw_pdft_' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function script(string $body): string
    {
        $f = $this->dir . '/conv.sh';
        file_put_contents($f, "#!/bin/sh\n{$body}\n");
        chmod($f, 0755);
        return $f;
    }

    public function testAConverterThatStopsReadingIsKilledWithItsGroupAtTheTimeout(): void
    {
        $bin = $this->script("sleep 4041 & wait\n");
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function (): void {
            throw new \RuntimeException('the reader blocked past its timeout');
        });
        pcntl_alarm(12);
        $t = microtime(true);
        try {
            (new PdftotextText($bin, 2))->text('%PDF-1.4 ' . str_repeat('x', 1_500_000));
            self::fail('a converter that never ends is refused');
        } catch (CwException $e) {
            self::assertSame('p2g_pdf_text', $e->errorCode);
        } finally {
            pcntl_alarm(0);
        }
        self::assertLessThan(8, microtime(true) - $t, 'stopped at its timeout, never blocked on its input');
        // (the pattern's [1] keeps pgrep from finding the shell that runs it)
        for ($i = 0, $left = ['?']; $i < 20 && $left !== []; $i++) {
            usleep(100_000);
            $left = [];
            exec("pgrep -f 'sleep 404[1]'", $left);
        }
        self::assertSame([], $left, 'its whole process group is gone (the sleep too)');
    }

    public function testALargeInputIsReadWhole(): void
    {
        $bin = $this->script('wc -c');
        self::assertSame((string) (9 + 1_500_000), trim((new PdftotextText($bin, 10))->text('%PDF-1.4 ' . str_repeat('x', 1_500_000))));
    }
}
