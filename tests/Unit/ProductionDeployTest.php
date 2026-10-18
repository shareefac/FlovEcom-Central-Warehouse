<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The production server's deployment files (deploy/production; docs/ops.md "The production server"), which cannot be run here:
 * they must do what staging's do, under the production names, and refuse what must never happen on production.
 */
final class ProductionDeployTest extends TestCase
{
    private const DIR = __DIR__ . '/../../deploy/production';
    private const STAGING = __DIR__ . '/../../deploy/staging';
    /** Staging's names => production's. */
    private const NAMES = ['warehouse-staging.floverfy.com' => 'warehouse.floverfy.com', '46.101.55.135' => '129.212.162.243',
        '/opt/cw-staging' => '/opt/cw-prod', 'cw_staging' => 'cw_prod', '/etc/cron.d/cw-staging' => '/etc/cron.d/cw-prod'];

    /** Every setting line (not the comments) is staging's, with the production names: the two servers run the same things. */
    public function testTheJobsAndTheWebServerAreStagingsUnderTheProductionNames(): void
    {
        foreach (['cw-staging.cron' => 'cw-prod.cron', 'logrotate-cw.conf' => 'logrotate-cw.conf', 'apache-cw-https.conf' => 'apache-cw-https.conf',
            'apache-cw-acme.conf' => 'apache-cw-acme.conf', 'apache-cw-hardening.conf' => 'apache-cw-hardening.conf',
            'php-fpm-cw-web.conf' => 'php-fpm-cw-web.conf', 'logrotate-cw-web.conf' => 'logrotate-cw-web.conf'] as $staging => $prod) {
            $want = self::settings(strtr((string) file_get_contents(self::STAGING . '/' . $staging), self::NAMES));
            self::assertNotSame([], $want, $staging);
            self::assertSame($want, self::settings((string) file_get_contents(self::DIR . '/' . $prod)), "{$prod} is {$staging} under the production names");
        }
    }

    /** No production file names staging's host, address, schema or code copy, nor a test schema; nothing runs from a test slot. */
    public function testNothingInProductionNamesStaging(): void
    {
        foreach (glob(self::DIR . '/*') ?: [] as $file) {
            // The one SSH key that reaches both boxes keeps its name (the owner put its public half on the droplet).
            $text = str_replace('/root/.ssh/cw_staging', '', implode("\n", self::settings((string) file_get_contents($file))));
            foreach (['warehouse-staging', '46.101.55.135', '/opt/cw-staging', 'cw_staging', 'cw_test_', 'scripts/remote.sh', '--mark-staging'] as $bad) {
                self::assertStringNotContainsString($bad, $text, basename($file) . " names {$bad}");
            }
            // The one staging file production uses: the seal sweep, which takes the store's root as its argument.
            preg_match_all('#deploy/staging/([a-z_\-.]+)#', $text, $m);
            self::assertSame([], array_values(array_diff(array_unique($m[1]), ['seal_file_store.sh'])), basename($file));
        }
    }

    /** Nothing but enable_https.sh installs or enables the public vhosts, or asks for a certificate. */
    public function testThePublicVhostIsSwitchedOnByEnableHttpsAlone(): void
    {
        foreach (['provision.sh', 'push_code.sh', 'install_cron.sh', 'install_file_store.sh', 'cw-prod.cron'] as $f) {
            $code = implode("\n", self::settings((string) file_get_contents(self::DIR . '/' . $f)));
            if ($f !== 'install_cron.sh') { // it switches back on, only, the vhost it paused for a migration (the next test)
                self::assertDoesNotMatchRegularExpression('/a2ensite\b[^\n]*(cw-https|cw-acme)/', $code, "{$f} must not enable the public vhost");
            }
            self::assertDoesNotMatchRegularExpression('/apache-cw-(https|acme)\.conf/', $code, "{$f} must not install the public vhost");
            self::assertDoesNotMatchRegularExpression('/certbot\s+(certonly|run|renew)|letsencrypt/i', $code, "{$f} must not ask for a certificate");
            self::assertDoesNotMatchRegularExpression('/enable_https/', $code, "{$f} must not run enable_https.sh");
        }
    }

    /** enable_https.sh refuses until it is safe, as staging's does, with the production guards. */
    public function testEnableHttpsRefusesUntilItIsSafeOnProduction(): void
    {
        $src = (string) file_get_contents(self::DIR . '/enable_https.sh');
        self::assertStringContainsString("NAME=warehouse.floverfy.com\nADDR=129.212.162.243", $src);
        self::assertStringContainsString('$repo != /opt/cw-prod-release', $src, 'it runs from the release copy only');
        self::assertStringContainsString('hostname) == cw-prod-lon1-01', $src);
        self::assertStringContainsString('metadata/v1/reserved_ip/ipv4', $src, 'a reserved address is not on the interface: the metadata says whose it is');
        self::assertStringContainsString('expected cw_prod', $src);
        self::assertStringContainsString('says environment=staging: never on production', $src);
        self::assertStringContainsString('initial_staff.txt', $src);
        self::assertStringContainsString('[[ $placeholders == 0 ]]', $src);
        self::assertStringNotContainsString('a2query -s cw-ui', $src, 'production has no loopback UI');
        $guards = strpos($src, 'guards passed');
        foreach (['metadata/v1/reserved_ip', 'expected cw_prod', 'environment=staging', '[[ $placeholders == 0 ]]'] as $g) {
            self::assertLessThan($guards, (int) strpos($src, $g), "{$g} is checked before anything is installed");
        }
        self::assertLessThan((int) strpos($src, 'a2ensite -q cw-https'), (int) strpos($src, 'certbot certonly'), 'the certificate before the HTTPS vhost');
    }

    /**
     * install_cron.sh (the review of 0df58e2): every guard before anything is built; the new release built whole beside the
     * running one; migrations checked with the NEW code, and a pending one stops the run (nothing running changed) unless
     * --migrate, which pauses the writers first; then one rename switches /opt/cw-prod.
     */
    public function testInstallCronBuildsBesideTheRunningCodeAndSwitchesAfterTheSchema(): void
    {
        $src = (string) file_get_contents(self::DIR . '/install_cron.sh');
        self::assertStringContainsString("src=/opt/cw-prod-release\nreleases=/opt/cw-prod-releases\nlive=/opt/cw-prod\nschema=cw_prod", $src);
        $pos = static fn (string $s): int => (int) strpos($src, $s);
        // The marker and the schema read as the application reads app.env.
        self::assertStringContainsString('CW\Config::parseEnvFile("/etc/cw/app.env")', $src);
        self::assertStringContainsString('strtolower(trim((string) ($app["environment"] ?? "")))', $src);
        self::assertStringContainsString('says environment=staging: never on production', $src);
        self::assertStringContainsString('names the schema', $src);
        $build = $pos('# 1. The new release');
        foreach (['environment=staging: never', 'names the schema', 'is not a commit id', 'is a directory, not the symlink'] as $g) {
            self::assertLessThan($build, $pos($g), "{$g}: refused before anything is built");
        }
        $status = $pos('migrate.php" --db="$schema" --status');
        $pause = $pos('mv /etc/cron.d/cw-prod "$paused_cron"');
        $web = $pos('a2dissite -q cw-https');
        $migrate = $pos('php "$new/bin/migrate.php" --db="$schema" |');
        $switch = $pos('mv -T "$live.next" "$live"');
        foreach (['status' => $status, 'pause' => $pause, 'web' => $web, 'migrate' => $migrate, 'switch' => $switch] as $what => $at) {
            self::assertGreaterThan(0, $at, $what);
        }
        self::assertLessThan($status, $build);
        self::assertLessThan($pause, $status, 'the schema is checked with the new code before anything that runs changes');
        self::assertLessThan($migrate, $pause, 'the writers are paused before the schema changes');
        self::assertLessThan($migrate, $web);
        self::assertLessThan($switch, $migrate, 'the code switches after the schema');
        self::assertStringContainsString('re-run with --migrate (nothing running was changed)', $src);
        self::assertStringContainsString('the writers stay paused', $src, 'a failure leaves them paused, and says so');
        // The public vhost: switched off and back on around a migration only when it was on (the marker), never installed here.
        self::assertStringContainsString('if a2query -s cw-https >/dev/null 2>&1; then', $src);
        self::assertStringContainsString('if [[ -f $paused_web ]]; then', $src);
        self::assertLessThan($pos('a2ensite -q cw-https'), $pos('if [[ -f $paused_web ]]; then'), 'it is switched back on only when this installer paused it');
        // The second review: one install at a time; a fresh build directory, its REVISION and the source's checked after the copy;
        // the web server able to read the release before it runs it; the web drained (php-fpm stopped gracefully) before the
        // schema changes; a run after a stopped one must converge again (--migrate) before it resumes.
        self::assertLessThan($build, $pos('flock -n 9 ||'));
        self::assertStringContainsString('build=$(mktemp -d "$releases/.build-$rev.XXXXXX")', $src);
        self::assertLessThan($pos('mv "$build" "$new"'), $pos('changed while it was copied'));
        self::assertLessThan($switch, $pos('runuser -u www-data -- test -r "$new/public/index.php"'));
        self::assertLessThan($migrate, $pos('systemctl kill -s QUIT php8.3-fpm'));
        self::assertLessThan($build, $pos('re-run with --migrate (it converges the schema and the grants, then resumes)'));
        self::assertStringContainsString("if [[ \$migrate -eq 1 ]]; then\n    # Pause every writer", $src, 'every --migrate run pauses the writers first (grants change too)');
        self::assertStringContainsString('if [[ $migrate -eq 1 ]]; then', $src, 'with --migrate the migration (and so the grants) always runs');
        self::assertStringContainsString('install -m 0644 -o root -g root deploy/production/cw-prod.cron /etc/cron.d/cw-prod', $src);
        $cron = (string) file_get_contents(self::DIR . '/cw-prod.cron');
        preg_match_all('/--db=(\w+)/', $cron, $m);
        self::assertSame(['cw_prod'], array_values(array_unique($m[1])), 'every job runs against cw_prod');
    }

    /** enable_https.sh gives php-fpm read access to app.env and makes the UI key before the pool starts (staging's install_api/ui). */
    public function testEnableHttpsPreparesTheWebsConfigurationBeforeThePool(): void
    {
        $src = (string) file_get_contents(self::DIR . '/enable_https.sh');
        $pos = static fn (string $s): int => (int) strpos($src, $s);
        foreach (['chown root:www-data /etc/cw/app.env', 'chmod 0640 /etc/cw/app.env', 'chmod 0600 /etc/cw/db.env',
            'SecretBox::newKeyBase64()', '"db_host", "db_port"'] as $s) {
            self::assertGreaterThan(0, $pos($s), $s);
            self::assertLessThan($pos('/etc/php/8.3/fpm/pool.d/cw-web.conf'), $pos($s), "{$s} before the pool");
            self::assertGreaterThan($pos('if [[ $check == 1 ]]'), $pos($s), "{$s} after --check");
        }
        self::assertStringContainsString('CW\Config::parseEnvFile("/etc/cw/app.env")', $src, 'the marker as the application reads it');
    }

    /** push_code.sh sends GitHub's main only: the main checkout, branch main, a clean tree, equal to origin/main. */
    public function testPushCodeSendsOnlyGithubsMain(): void
    {
        $src = (string) file_get_contents(self::DIR . '/push_code.sh');
        foreach (['git archive --format=tar "$rev"', 'incoming=/opt/cw-prod-incoming/$rev-$$', 'flock -w 600 /run/lock/cw-prod-install.lock bash -euo pipefail',
            'mv $incoming $DEST', '$repo == /root/central-warehouse',
            'rev-parse --abbrev-ref HEAD) == main', 'git status --porcelain', 'git fetch -q origin main', '$rev == "$(git rev-parse origin/main)"',
            'HOST=root@129.212.162.243', '--exclude=/tests/', '--exclude=/tools/', 'composer install --no-dev'] as $s) {
            self::assertStringContainsString($s, $src);
        }
        $first = strpos($src, 'nice -n 19 rsync ');
        self::assertNotFalse($first);
        self::assertLessThan($first, (int) strpos($src, 'origin/main)"'), 'checked before anything is sent');
        // The third review: each push to its own directory; composer, REVISION and the rename under the install lock, in that order.
        $locked = (int) strpos($src, 'flock -w 600 /run/lock/cw-prod-install.lock bash -euo pipefail');
        self::assertLessThan($locked, $first, 'the files go to the incoming directory before the lock is taken');
        self::assertStringContainsString('"$tmp/" "$HOST:$incoming/"', $src, 'never straight into the release copy');
        self::assertLessThan((int) strpos($src, '> REVISION', $locked), (int) strpos($src, 'composer install --no-dev', $locked), 'REVISION after composer');
        self::assertLessThan((int) strpos($src, 'mv $incoming $DEST', $locked), (int) strpos($src, '> REVISION', $locked), 'the rename after REVISION');
        self::assertStringNotContainsString('"$repo/" "$HOST', $src, 'the working tree itself is never sent: only the commit\'s files');
        self::assertLessThan($first, (int) strpos($src, 'chmod 0755 "$tmp"'), 'the release is readable by the web server');
        self::assertStringNotContainsString('"$HOST:$DEST/"', $src, 'nothing is sent into the release copy an install may be reading');
    }

    /** provision.sh opens SSH before it switches the firewall on, and runs on the production box only. */
    public function testProvisionKeepsSshOpenAndRunsOnTheProductionBoxOnly(): void
    {
        $src = (string) file_get_contents(self::DIR . '/provision.sh');
        self::assertStringContainsString('$(hostname) == cw-prod-lon1-01', $src);
        self::assertLessThan((int) strpos($src, 'ufw --force enable'), (int) strpos($src, 'ufw allow OpenSSH'));
        foreach (['ufw allow 80/tcp', 'ufw allow 443/tcp', 'ufw default deny incoming', 'Unattended-Upgrade "1"', 'php8.3-fpm', 'e2fsprogs'] as $s) {
            self::assertStringContainsString($s, $src);
        }
    }

    /** Every script is valid bash and stops on the first error. */
    public function testTheScriptsAreValidBash(): void
    {
        foreach (glob(self::DIR . '/*.sh') ?: [] as $f) {
            $src = (string) file_get_contents($f);
            self::assertStringStartsWith("#!/usr/bin/env bash\n", $src, basename($f));
            self::assertStringContainsString('set -euo pipefail', $src, basename($f));
            $bash = trim((string) @shell_exec('command -v bash 2>/dev/null'));
            if ($bash !== '') {
                $out = [];
                $code = 0;
                exec(escapeshellarg($bash) . ' -n ' . escapeshellarg($f) . ' 2>&1', $out, $code);
                self::assertSame(0, $code, basename($f) . ': ' . implode("\n", $out));
            }
        }
    }

    /** @return list<string> the lines that are not comments or blank, trimmed at the right */
    private static function settings(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $t = trim($line);
            if ($t === '' || str_starts_with($t, '#') || str_starts_with($t, ';')) {
                continue;
            }
            $out[] = rtrim($line);
        }
        return $out;
    }
}
