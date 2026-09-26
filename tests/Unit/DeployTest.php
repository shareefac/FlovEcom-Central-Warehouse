<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Deployment files that cannot be exercised here but must not regress (R18). */
final class DeployTest extends TestCase
{
    private const DIR = __DIR__ . '/../../deploy/staging';

    /**
     * The API pool's error log grows by one line per request with a missing or unknown key,
     * before any authentication: it needs size-based rotation, run more often than daily.
     */
    public function testTheApiLogDirectoryIsRotatedBySizeHourly(): void
    {
        $conf = (string) file_get_contents(self::DIR . '/logrotate-cw-api.conf');
        self::assertMatchesRegularExpression('#^/var/log/cw-api/\*\.log \{#m', $conf);
        self::assertMatchesRegularExpression('/^\s+size \d+M$/m', $conf);
        self::assertMatchesRegularExpression('/^\s+rotate \d+$/m', $conf);
        self::assertMatchesRegularExpression('/^\s+copytruncate$/m', $conf);
        $pool = (string) file_get_contents(self::DIR . '/php-fpm-cw-api.conf');
        self::assertStringContainsString('php_admin_value[error_log] = /var/log/cw-api/', $pool, 'the rule covers the pool log');
        $install = (string) file_get_contents(self::DIR . '/install_api.sh');
        self::assertStringContainsString('deploy/staging/logrotate-cw-api.conf', $install);
        self::assertMatchesRegularExpression('#^\s*\'[0-9]+ \* \* \* \* root /usr/sbin/logrotate -s \S+ /etc/cw/logrotate-cw-api.conf\'#m', $install);
    }
}
