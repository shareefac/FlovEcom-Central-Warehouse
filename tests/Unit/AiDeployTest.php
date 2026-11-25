<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * CW-1182 (docs/decisions.md AI2, AI3, AI14): what the release needs on a box: Anthropic's PHP SDK and a PSR-18 client (Guzzle) as production
 * packages (composer --no-dev installs them), no Composer plugin run; ONE cron line each on production and staging for the reader, every
 * minute, with its own memory limit; the Click & Drop report's upload never offered to Claude.
 */
final class AiDeployTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testThePackagesAreProductionOnes(): void
    {
        $c = json_decode((string) file_get_contents(self::ROOT . '/composer.json'), true, 16, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('anthropic-ai/sdk', $c['require']);
        self::assertArrayHasKey('guzzlehttp/guzzle', $c['require']);
        self::assertFalse($c['config']['allow-plugins']['php-http/discovery'], 'no plugin runs (push_code.sh installs with --no-plugins anyway)');
        $lock = json_decode((string) file_get_contents(self::ROOT . '/composer.lock'), true, 64, JSON_THROW_ON_ERROR);
        $names = array_column($lock['packages'], 'version', 'name');
        self::assertArrayHasKey('anthropic-ai/sdk', $names, 'in the production packages, not the dev ones');
        self::assertArrayHasKey('guzzlehttp/guzzle', $names);
        foreach ($lock['packages'] as $p) {
            $php = (string) ($p['require']['php'] ?? '');
            self::assertDoesNotMatchRegularExpression('/^(>=|\^|~)8\.[4-9]/', $php, $p['name'] . ' runs on PHP 8.3 (production: 8.3.6)');
        }
    }

    public function testOneCronLineEachEveryMinute(): void
    {
        foreach (['production/cw-prod.cron' => ['/opt/cw-prod', 'cw_prod'], 'staging/cw-staging.cron' => ['/opt/cw-staging', 'cw_staging']] as $file => [$dir, $db]) {
            $cron = (string) file_get_contents(self::ROOT . '/deploy/' . $file);
            self::assertSame(1, preg_match_all('#^[^\#\n]*bin/ai_read\.php#m', $cron), "{$file}: one line");
            // H1 (Codex final of 86aebbd): the reader runs as www-data (the spool's owner), never root; from its own folder (www-data's cwd), its log
            // written by cron's root shell
            self::assertMatchesRegularExpression('#^\* \* \* \* \*  root  cd ' . preg_quote($dir, '#') . ' && runuser -u www-data -- php -d memory_limit=512M '
                . 'bin/ai_read\.php --db=' . $db . ' >> /var/log/cw/ai_read\.log 2>&1$#m', $cron);
        }
        $install = (string) file_get_contents(self::ROOT . '/deploy/production/install_cron.sh');
        self::assertStringNotContainsString('ai_read.php', $install, 'never run as an installer\'s smoke check (it reads documents)');
        // High 2 (the re-check of ca8987b): the release's own migrate step (run by the installer with the writers paused) types the key's row back
        self::assertStringContainsString('AiSettings::restoreKeyRow($db)', (string) file_get_contents(self::ROOT . '/bin/migrate.php'));
        // H9 (Codex final of 86aebbd): the go-live kit names the commit it checked; the installer checks it again under its own lock (as PayPal's)
        foreach (['--expect-revision=*) expect_rev=', '--expect-active=*) expect_active=', 'not the expected $expect_rev (a push replaced it)'] as $part) {
            self::assertStringContainsString($part, $install);
        }
    }

    public function testTheLabelReportIsNeverOfferedToClaude(): void
    {
        $view = (string) file_get_contents(self::ROOT . '/src/Ui/views/profit_delivery.php');
        $labels = substr($view, (int) strpos($view, 'delivery-labels'));
        self::assertStringContainsString('accept=".xls,application/vnd.ms-excel"', $labels, 'the Click & Drop report stays an .xls (it holds addresses)');
        self::assertStringNotContainsString('pdf', substr($labels, 0, (int) strpos($labels, '</form>')));
        $ctl = (string) file_get_contents(self::ROOT . '/src/Ui/Controller/DeliveryBillsController.php');
        $fn = substr($ctl, (int) strpos($ctl, 'public function labels('), 2000);
        self::assertStringNotContainsString('DocumentReader', substr($fn, 0, (int) strpos($fn, "\n    }\n")));
    }
}
