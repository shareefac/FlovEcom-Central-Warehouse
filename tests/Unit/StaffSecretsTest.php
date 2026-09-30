<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\ConfigException;
use CW\Staff\SecretBox;
use CW\Staff\Totp;
use PHPUnit\Framework\TestCase;

/** TOTP (RFC 6238 vectors, 6 digits) and the at-rest encryption of staff secrets. */
final class StaffSecretsTest extends TestCase
{
    /** RFC 6238 appendix B, SHA-1 seed "12345678901234567890", last 6 of the 8-digit values. */
    public function testRfc6238Vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        self::assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037', 20000000000 => '353130'] as $t => $code) {
            self::assertSame($code, Totp::code($secret, intdiv($t, 30)), "T={$t}");
        }
        self::assertSame('12345678901234567890', Totp::base32Decode($secret));
    }

    public function testVerifyAcceptsTheWindowOnceAndRefusesGarbage(): void
    {
        $secret = Totp::newSecret();
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $now = 1_790_000_000;
        $step = intdiv($now, 30);
        self::assertSame($step, Totp::verify($secret, Totp::code($secret, $step), null, 1, $now));
        self::assertSame($step - 1, Totp::verify($secret, Totp::code($secret, $step - 1), null, 1, $now));
        self::assertNull(Totp::verify($secret, Totp::code($secret, $step - 2), null, 1, $now), 'outside the window');
        self::assertNull(Totp::verify($secret, Totp::code($secret, $step), $step, 1, $now), 'a code is used once (last step)');
        self::assertNull(Totp::verify($secret, '12345', null, 1, $now));
        self::assertNull(Totp::verify($secret, 'abcdef', null, 1, $now));
        self::assertStringStartsWith('otpauth://totp/CW%20Warehouse:a%40b.test?secret=' . $secret . '&issuer=CW%20Warehouse', Totp::uri($secret, 'a@b.test'));
    }

    /** The sign-in accepts the current 30-second step and one either side (clock drift), nothing else, and a step only once. */
    public function testTheWindowEdgesAndReplay(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $step = 666666666; // T = 20000000000 .. 20000000029
        $start = $step * 30;
        foreach ([$start, $start + 29] as $now) {
            self::assertSame($step, Totp::verify($secret, '353130', null, 1, $now), "both ends of the step, now={$now}");
        }
        $prev = Totp::code($secret, $step - 1);
        $next = Totp::code($secret, $step + 1);
        self::assertSame($step - 1, Totp::verify($secret, $prev, null, 1, $start), 'one step late');
        self::assertSame($step + 1, Totp::verify($secret, $next, null, 1, $start), 'one step early');
        self::assertNull(Totp::verify($secret, Totp::code($secret, $step - 2), null, 1, $start), 'two steps late');
        self::assertNull(Totp::verify($secret, Totp::code($secret, $step + 2), null, 1, $start), 'two steps early');
        self::assertNull(Totp::verify($secret, $prev, null, 0, $start), 'window 0 means this step only');
        // One use per step: the step a code was accepted for, and every earlier one, is spent.
        self::assertNull(Totp::verify($secret, '353130', $step, 1, $start), 'replay of the same code');
        self::assertNull(Totp::verify($secret, $prev, $step, 1, $start), 'an older code after a newer one');
        self::assertSame($step + 1, Totp::verify($secret, $next, $step, 1, $start), 'the next step is still fresh');
        self::assertSame($step, Totp::verify($secret, '353130', $step - 1, 1, $start), 'only later steps than the last one count');
        // What is not exactly six ASCII digits is not a code.
        foreach (['', '35313', '3531300', ' 353130', '353130 ', "353130\n", '35313o', '+53130', '-53130', '3.5313', "\u{0663}\u{0665}\u{0663}\u{0661}\u{0663}\u{0660}"] as $bad) {
            self::assertNull(Totp::verify($secret, $bad, null, 1, $start), json_encode($bad));
        }
        self::assertSame(6, strlen(Totp::code($secret, 4)), 'leading zeros are kept (T=1234567890 gives 005924)');
        self::assertSame('005924', Totp::code($secret, intdiv(1234567890, 30)));
    }

    public function testSecretBoxRoundTripsAndOnlyOpensWithItsKey(): void
    {
        $box = SecretBox::fromBase64(SecretBox::newKeyBase64());
        $enc = $box->encrypt('JBSWY3DPEHPK3PXP');
        self::assertNotSame($enc, $box->encrypt('JBSWY3DPEHPK3PXP'), 'a fresh nonce each time');
        self::assertStringNotContainsString('JBSWY3DPEHPK3PXP', $enc);
        self::assertLessThanOrEqual(255, strlen($enc), 'fits staff_user.totp_secret_enc');
        self::assertSame('JBSWY3DPEHPK3PXP', $box->decrypt($enc));
        $other = SecretBox::fromBase64(SecretBox::newKeyBase64());
        $this->expectException(\RuntimeException::class);
        $other->decrypt($enc);
    }

    public function testAKeyOfTheWrongSizeIsRefused(): void
    {
        $this->expectException(ConfigException::class);
        SecretBox::fromBase64(base64_encode('short'));
    }
}
