<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Profit\PaypalFees;
use PHPUnit\Framework\TestCase;

/**
 * CW-1172 round 6 (the Codex astra final of 9b46fe0; the lead's decision 5; docs/decisions.md PPF70-): the go-live kit holds the nightly PayPal
 * job off between its backfill and the gate's verdict with a file beside the key (<key file>.hold: "until=<unix time>"): the nightly does
 * nothing while it holds; a hold that has run out, one further ahead than the kit ever writes (a hold forgotten or not the kit's), or one not in
 * its form holds nothing. MADE-UP files only.
 */
final class PaypalReadR6Test extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cw_pp_hold_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function hold(string $text): string
    {
        $key = $this->dir . '/paypal_vapeandgo.json';
        file_put_contents($key . '.hold', $text);
        return $key;
    }

    public function testTheNightlyIsHeldOnlyWhileTheKitsHoldRuns(): void
    {
        $now = new \DateTimeImmutable('2026-11-25T22:30:00Z');
        $t = $now->getTimestamp();
        // no key file named, or no hold beside it: nothing held
        self::assertNull(PaypalFees::nightlyHold(null, $now));
        self::assertNull(PaypalFees::nightlyHold($this->dir . '/paypal_vapeandgo.json', $now));
        // the kit's hold, an hour to run: held, and the message says until when
        $key = $this->hold('until=' . ($t + 3600) . "\n");
        $why = PaypalFees::nightlyHold($key, $now);
        self::assertNotNull($why);
        self::assertStringContainsString('2026-11-25 23:30', (string) $why);
        // at most PaypalFees::HOLD_MAX_SEC ahead (the kit writes two hours): still held at the limit, nothing beyond it
        self::assertNotNull(PaypalFees::nightlyHold($this->hold('until=' . ($t + PaypalFees::HOLD_MAX_SEC)), $now));
        self::assertNull(PaypalFees::nightlyHold($this->hold('until=' . ($t + PaypalFees::HOLD_MAX_SEC + 1)), $now), 'never a hold of days: not the kit\'s');
        // run out: nothing held
        self::assertNull(PaypalFees::nightlyHold($this->hold('until=' . $t), $now));
        self::assertNull(PaypalFees::nightlyHold($this->hold('until=' . ($t - 60)), $now));
        // not in its form: nothing held
        foreach (['', 'until=', 'until=soon', 'until=' . ($t + 60) . ' and more', 'hold until=' . ($t + 60), str_repeat('9', 200)] as $bad) {
            self::assertNull(PaypalFees::nightlyHold($this->hold($bad), $now), "'{$bad}' holds nothing");
        }
    }
}
