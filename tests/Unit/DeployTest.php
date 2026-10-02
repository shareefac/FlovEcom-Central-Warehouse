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

    /** The UI's own log directory (a login form is reachable by anyone who can reach the vhost) gets the same rotation as the API's. */
    public function testTheUiAndWebLogDirectoriesAreRotatedBySizeHourly(): void
    {
        foreach (['ui' => ['/var/log/cw-ui/', 'install_ui.sh', 'install_ui'], 'web' => ['/var/log/cw-web/', 'enable_https.sh', 'enable_https']] as $name => [$dir, $script, $label]) {
            $conf = (string) file_get_contents(self::DIR . "/logrotate-cw-{$name}.conf");
            self::assertMatchesRegularExpression('#^' . preg_quote(rtrim($dir, '/'), '#') . '/\*\.log \{#m', $conf, $name);
            self::assertMatchesRegularExpression('/^\s+size \d+M$/m', $conf, $name);
            self::assertMatchesRegularExpression('/^\s+rotate \d+$/m', $conf, $name);
            self::assertMatchesRegularExpression('/^\s+copytruncate$/m', $conf, $name);
            $pool = (string) file_get_contents(self::DIR . "/php-fpm-cw-{$name}.conf");
            self::assertStringContainsString('php_admin_value[error_log] = ' . $dir, $pool, "the rule covers the {$name} pool log");
            $install = (string) file_get_contents(self::DIR . '/' . $script);
            self::assertStringContainsString("deploy/staging/logrotate-cw-{$name}.conf", $install, $label);
            self::assertMatchesRegularExpression("#^\\s*'[0-9]+ \\* \\* \\* \\* root /usr/sbin/logrotate -s \\S+ /etc/cw/logrotate-cw-{$name}.conf'#m", $install, $label);
        }
    }

    /** Files the UI needs must ship with the repo: the vhost, the pool, the two assets, the front controller. */
    public function testTheUiDeploymentFilesExistAndAgree(): void
    {
        foreach (['php-fpm-cw-ui.conf', 'apache-cw-ui.conf', 'install_ui.sh', 'logrotate-cw-ui.conf'] as $f) {
            self::assertFileExists(self::DIR . '/' . $f);
        }
        foreach (['public/index.php', 'public/ui/assets/app.css', 'public/ui/assets/app.js'] as $f) {
            self::assertFileExists(__DIR__ . '/../../' . $f);
        }
        $vhost = (string) file_get_contents(self::DIR . '/apache-cw-ui.conf');
        self::assertStringContainsString('<VirtualHost 127.0.0.1:8080>', $vhost, 'the existing local-only listener');
        self::assertDoesNotMatchRegularExpression('/^\s*Listen\b/mi', $vhost, 'a second Listen for the same address stops Apache');
        self::assertStringContainsString('Require local', $vhost, 'loopback only');
        self::assertStringContainsString('RewriteRule ^ /opt/cw-ui/public/index.php [END]', $vhost, 'one front controller, nothing else served from public/');
        self::assertStringContainsString('php8.3-fpm-cw-ui.sock', $vhost);
        $pool = (string) file_get_contents(self::DIR . '/php-fpm-cw-ui.conf');
        self::assertStringContainsString('/run/php/php8.3-fpm-cw-ui.sock', $pool);
        self::assertStringContainsString('CW_DB_NAME', $pool, 'the test schema of the slot');
        self::assertStringContainsString('cw_test_ui', $pool);
        self::assertStringNotContainsString('cw_staging', $pool, 'the slot pool never points at the real staging schema');
        self::assertStringNotContainsString('cw_staging', $vhost);
        self::assertFileExists(self::DIR . '/apache-cw-api.conf', 'the API vhost stays the default vhost of the listener');
    }

    /** The public HTTPS vhost is written but must stay OFF: only enable_https.sh, run by a person, may install it. */
    public function testThePublicHttpsVhostIsNotEnabledByAnythingAutomatic(): void
    {
        $vhost = (string) file_get_contents(self::DIR . '/apache-cw-https.conf');
        self::assertStringContainsString('NOT ENABLED', $vhost);
        self::assertStringContainsString('<VirtualHost *:443>', $vhost);
        self::assertStringContainsString('ServerName warehouse-staging.floverfy.com', $vhost);
        self::assertStringContainsString('SSLProtocol -all +TLSv1.2 +TLSv1.3', $vhost);
        self::assertStringContainsString('/opt/cw-staging/public/index.php', $vhost);
        self::assertStringNotContainsString('cw_test_', $vhost, 'the public name never serves a test schema');
        self::assertStringContainsString('Options None', $vhost);
        self::assertStringContainsString('CGIPassAuth On', $vhost, 'the API key header reaches PHP');
        self::assertDoesNotMatchRegularExpression('/mod_remoteip|RemoteIPHeader/i', preg_replace('/^\s*#.*$/m', '', $vhost) ?? '', 'REMOTE_ADDR is the only client address the app trusts');

        $hooks = ['install_ui.sh', 'install_api.sh', 'install_cron.sh', 'cw-staging.cron'];
        foreach ($hooks as $f) {
            $src = (string) file_get_contents(self::DIR . '/' . $f);
            $code = preg_replace('/^\s*#.*$/m', '', $src) ?? '';
            self::assertDoesNotMatchRegularExpression('/a2ensite\b[^\n]*(cw-https|cw-acme)/', $code, "{$f} must not enable the public vhost");
            self::assertDoesNotMatchRegularExpression('/apache-cw-(https|acme)\.conf/', $code, "{$f} must not install the public vhost");
            self::assertDoesNotMatchRegularExpression('/certbot|letsencrypt/i', $code, "{$f} must not ask for a certificate");
            self::assertDoesNotMatchRegularExpression('/enable_https/', $code, "{$f} must not run enable_https.sh");
        }
        // The ACME vhost is as inert until enable_https.sh.
        $acme = (string) file_get_contents(self::DIR . '/apache-cw-acme.conf');
        self::assertStringContainsString('NOT enabled until enable_https.sh runs', $acme);
        self::assertStringContainsString('.well-known/acme-challenge/', $acme);
    }

    /**
     * I36: new files of the document store are sealed every minute (root:www-data 0440, chattr +i) and every stored file
     * is re-hashed nightly; the installer seals what is there and checks that a sealed file cannot be rewritten in place.
     */
    public function testTheDocumentStoreIsSealedAndVerifiedOnASchedule(): void
    {
        $cron = (string) file_get_contents(self::DIR . '/cw-staging.cron');
        self::assertMatchesRegularExpression('#^\* \* \* \* \*  root  bash /opt/cw-staging/deploy/staging/seal_file_store\.sh /srv/cw-docs >> /var/log/cw/seal_file_store\.log 2>&1$#m', $cron);
        self::assertMatchesRegularExpression('#^27 4 \* \* \*  root  \[ ! -d /srv/cw-docs \] \|\| \{ cd /opt/cw-staging && php bin/verify_files\.php --db=cw_staging #m', $cron);
        $seal = (string) file_get_contents(self::DIR . '/seal_file_store.sh');
        self::assertStringStartsWith("#!/usr/bin/env bash\n", $seal);
        self::assertStringContainsString('set -euo pipefail', $seal);
        self::assertStringContainsString('chown root:www-data "$path" && chmod 0440 "$path" && chattr +i "$path"', $seal);
        self::assertStringContainsString('^[0-9a-f]{64}$', $seal, 'only stored files (sha256 names) are sealed');
        self::assertStringNotContainsString('chattr -i', $seal, 'the sweep never unseals');
        self::assertStringNotContainsString('rm ', preg_replace('/^\s*#.*$/m', '', $seal) ?? '', 'the sweep never removes');
        $install = (string) file_get_contents(self::DIR . '/install_file_store.sh');
        self::assertStringContainsString('seal_file_store.sh', $install);
        self::assertStringContainsString('in-place rewrite of a sealed file refused', $install);
    }

    /** The one script that switches it on refuses unless the name, the address and the code are right. */
    public function testEnableHttpsRefusesUntilItIsSafe(): void
    {
        $src = (string) file_get_contents(self::DIR . '/enable_https.sh');
        self::assertStringStartsWith("#!/usr/bin/env bash\n", $src);
        self::assertStringContainsString('set -euo pipefail', $src);
        self::assertStringContainsString('NAME=warehouse-staging.floverfy.com', $src);
        self::assertStringContainsString('ADDR=46.101.55.135', $src);
        self::assertStringContainsString('--check', $src, 'a dry run that changes nothing');
        self::assertMatchesRegularExpression('/getent ahostsv4 "\$NAME"/', $src, 'DNS is checked first');
        self::assertStringContainsString('does not resolve yet', $src);
        self::assertStringContainsString('expected only $ADDR', $src, 'a name that also points elsewhere is refused');
        self::assertStringContainsString('does not own $ADDR', $src, 'the challenge must land on this box');
        self::assertStringContainsString('/opt/cw-staging', $src);
        self::assertMatchesRegularExpression('/\$repo == \/opt\/cw-staging/', $src, 'it never runs from the live copy itself');
        self::assertStringContainsString('expected cw_staging', $src, 'the public vhost never serves a test schema');
        self::assertStringContainsString('a2query -s cw-ui', $src, 'the loopback UI must already work');
        // Staff secrets (review round 2, U23): the clear-text credentials file is gone and no placeholder account is active.
        self::assertMatchesRegularExpression('#\[\[ ! -e /etc/cw/initial_staff\.txt \]\]#', $src);
        self::assertStringContainsString('shred -u /etc/cw/initial_staff.txt', $src);
        self::assertStringContainsString('email LIKE ?", ["%.invalid"]', $src, 'placeholder accounts are counted');
        self::assertStringContainsString('[[ $placeholders == 0 ]]', $src, 'an unknown answer refuses too');
        // All guards come before anything is installed, the certificate before the HTTPS vhost.
        $guards = strpos($src, 'guards passed');
        self::assertLessThan($guards, (int) strpos($src, 'initial_staff.txt ]]'));
        self::assertLessThan($guards, (int) strpos($src, '[[ $placeholders == 0 ]]'));
        $check = strpos($src, 'if [[ $check == 1 ]]');
        $firstInstall = strpos($src, 'apt-get install');
        $cert = strpos($src, 'certbot certonly');
        $vhost = strpos($src, 'a2ensite -q cw-https');
        foreach (['guards passed' => $guards, 'check' => $check, 'install' => $firstInstall, 'certbot' => $cert, 'https vhost' => $vhost] as $what => $pos) {
            self::assertNotFalse($pos, $what);
        }
        self::assertLessThan($check, $guards);
        self::assertLessThan($firstInstall, $check, '--check stops before any change');
        self::assertLessThan($cert, $firstInstall);
        self::assertLessThan($vhost, $cert, 'the HTTPS vhost cannot load without the certificate files');
        self::assertMatchesRegularExpression('/--deploy-hook \'systemctl reload apache2\'/', $src, 'renewals reload Apache');
        self::assertStringContainsString('--keep-until-expiring', $src, 'a re-run does not burn the rate limit');
        // The script is syntactically valid bash.
        $lint = self::bashSyntax(self::DIR . '/enable_https.sh');
        self::assertNull($lint, (string) $lint);
        foreach (['install_ui.sh', 'install_api.sh', 'install_cron.sh'] as $f) {
            self::assertNull(self::bashSyntax(self::DIR . '/' . $f), $f);
        }
    }

    /** @return string|null bash's complaint, or null when the file parses (null when bash is unavailable) */
    private static function bashSyntax(string $file): ?string
    {
        $bash = trim((string) @shell_exec('command -v bash 2>/dev/null'));
        if ($bash === '') {
            return null;
        }
        $out = [];
        $code = 0;
        exec(escapeshellarg($bash) . ' -n ' . escapeshellarg($file) . ' 2>&1', $out, $code);
        return $code === 0 ? null : implode("\n", $out);
    }
}
