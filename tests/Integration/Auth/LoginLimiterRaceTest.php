<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Auth;

use CW\Auth\LoginLimiter;
use CW\Tests\Support\KernelUiTestCase;

/**
 * The sign-in limiter holds under concurrency (plan §11, U1: 10 failures -> 15 minutes' pause). The
 * count and the charge are one step under the account's and the address's named locks
 * (LoginLimiter::exclusive), so attempts that arrive together are counted one after another.
 * Regression test of the review finding (security lens): 10 simultaneous wrong passwords against an
 * account with 9 failures all had their password checked (19 failures in the window).
 */
final class LoginLimiterRaceTest extends KernelUiTestCase
{
    private const WORKERS = 10;

    public function testParallelAttemptsPastTheLimitAreRefusedUnchecked(): void
    {
        $u = $this->uiUser('mapping_lead');
        $login = $this->loginService();
        for ($i = 1; $i <= LoginLimiter::ACCOUNT_MAX - 1; $i++) {
            self::assertSame('invalid', $login->attempt($u['email'], "wrong-password-{$i}", '000000', '198.51.100.30', 'seq', null)['status']);
        }
        $limiter = new LoginLimiter(self::$db);
        self::assertSame(LoginLimiter::ACCOUNT_MAX - 1, $limiter->accountFailures($u['email']));

        // Ten more wrong passwords at the same moment, from ten addresses, each on its own connection (= one php-fpm worker).
        $statuses = $this->burst(array_map(static fn (int $w): array => ['email' => $u['email'], 'password' => "burst-{$w}", 'code' => '000000',
            'ip' => '198.51.100.' . (40 + $w)], range(0, self::WORKERS - 1)));
        $checked = count(array_filter($statuses, static fn (string $s): bool => $s === 'invalid'));
        self::assertSame(1, $checked, 'exactly one attempt of the burst was checked (the 10th failure); statuses: ' . implode(',', $statuses));
        self::assertSame(self::WORKERS - 1, count(array_filter($statuses, static fn (string $s): bool => $s === 'locked')));
        self::assertSame(LoginLimiter::ACCOUNT_MAX, $limiter->accountFailures($u['email']), 'refused attempts are not recorded (U1)');
        self::assertSame('locked', $login->attempt($u['email'], $u['password'], self::code($u['secret']), '198.51.100.31', 'seq', null)['status']);
    }

    public function testTheAddressLimitHoldsForParallelAttemptsOnManyAccounts(): void
    {
        $ip = '198.51.100.77';
        $login = $this->loginService();
        for ($i = 1; $i <= LoginLimiter::IP_MAX - 2; $i++) {
            self::assertSame('invalid', $login->attempt("nobody{$i}@test.invalid", 'wrong password here', '000000', $ip, 'seq', null)['status']);
        }
        // Six unknown accounts from the same address at once: two more failures reach the limit, the rest are refused.
        $statuses = $this->burst(array_map(static fn (int $w): array => ['email' => "burst{$w}@test.invalid", 'password' => 'wrong password here',
            'code' => '000000', 'ip' => $ip], range(0, 5)));
        self::assertSame(2, count(array_filter($statuses, static fn (string $s): bool => $s === 'invalid')), implode(',', $statuses));
        self::assertSame(LoginLimiter::IP_MAX, (new LoginLimiter(self::$db))->ipFailures($ip));
    }

    /**
     * Runs one sign-in attempt per job in parallel processes that start at the same instant.
     *
     * @param list<array{email: string, password: string, code: string, ip: string}> $jobs
     * @return list<string> statuses
     */
    private function burst(array $jobs): array
    {
        $start = microtime(true) + 3.0;
        $procs = [];
        foreach ($jobs as $job) {
            $pipes = [];
            $p = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Support/login_race_worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($p);
            fwrite($pipes[0], json_encode(['start' => $start] + $job, JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $procs[] = [$p, $pipes];
        }
        $statuses = [];
        foreach ($procs as $i => [$p, $pipes]) {
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($p);
            $line = json_decode(trim($out), true);
            self::assertSame(0, $code, "worker {$i}: " . substr($err . $out, 0, 500));
            self::assertIsArray($line, "worker {$i}: " . substr($err . $out, 0, 500));
            $statuses[] = (string) $line['status'];
        }
        return $statuses;
    }
}
