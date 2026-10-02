<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The matching engine's plain runner (tests/matching/run.php: Normalizer, Veto, Band, JudgeCard, the golden trap and true
 * pairs from the real catalogue exports, and the run3 follow-ups of docs/decisions.md M29) as part of the suite, so a change
 * to src/Matching cannot pass the suite while a golden case fails. The runner stays runnable on its own (no Composer).
 */
final class MatchingGoldenTest extends TestCase
{
    public function testTheGoldenRunnerPasses(): void
    {
        $runner = dirname(__DIR__) . '/matching/run.php';
        $p = proc_open([PHP_BINARY, '-d', 'memory_limit=512M', $runner], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        $res = json_decode(trim($out), true);
        self::assertIsArray($res, "runner output: {$out}{$err}");
        self::assertSame(0, $res['failed'] ?? null, $err);
        self::assertGreaterThanOrEqual(59, $res['passed'] ?? 0, 'golden cases run');
        self::assertSame(0, $code, $err);
    }
}
