<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Config;
use CW\Ops\TestRefPurge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * deploy/production's scripts RUN (ProductionDeployTest reads their text). Each test builds a temporary tree that stands for the
 * production box (CW_DEPLOY_ROOT, holding the marker .cw-deploy-test-root the scripts ask for), puts the scripts where the box
 * keeps them, and runs them with a PATH of tests/fixtures/deploy_stubs/bin and, after it, links to a short list of plain
 * file and text tools (SAFE_TOOLS) and nothing else: systemctl, a2query, a2ensite, a2dissite, apache2ctl, runuser, flock, ssh,
 * composer, php and the rest record each call and answer from the scenario, so no service, network or database is touched
 * (rsync, git, tar and flock's locking are the real ones), and a command with no stub is not found. Then it checks what
 * changed in the tree and the order of the calls. The Codex review of the production-install branch asked for these (second
 * review, finding 5; the review of this branch, prodtest_codex_review.txt, made them stricter).
 * Needs Linux, root (the scripts chown) and bash, rsync, flock and git: the test machine. Skipped on a box with a web server or
 * CW on it; fails if a run left anything at the box's real paths.
 */
final class ProductionDeployBehaviourTest extends TestCase
{
    private const DIR = __DIR__ . '/../../deploy/production';
    private const STUBS = __DIR__ . '/../fixtures/deploy_stubs/bin';
    private const SCRIPTS = ['provision.sh', 'push_code.sh', 'install_cron.sh', 'install_file_store.sh', 'enable_https.sh', 'set_db_password.sh'];
    /** The first lines after `set -euo pipefail` (and their comment) in every script. */
    private const PREAMBLE = "R=\${CW_DEPLOY_ROOT:-/}\nR=\${R%/}\nif [[ -n \$R ]]; then\n    kernel_host=\n"
        . "    read -r kernel_host 2>/dev/null < /proc/sys/kernel/hostname || true\n"
        . "    [[ \$R =~ ^/[A-Za-z0-9/._-]+\$ && -f \$R/.cw-deploy-test-root && -n \$kernel_host ]] \\\n"
        . "        && [[ ! \$kernel_host =~ ^(cw-prod-lon1-01|vpg-ded-lon1-01|FlovEcom-Central-Warehouse)\$ ]] \\\n"
        . "        && grep -qsx '# cw-deploy-stub' \"\$(type -P systemctl)\" \\\n"
        . "        || { echo \"";
    /** The real commands a script may run in a test (links in $base/sys): files and text in the tree, nothing else. */
    private const SAFE_TOOLS = ['bash', 'sh', 'env', 'cat', 'cp', 'mv', 'rm', 'mkdir', 'install', 'chmod', 'chown', 'ln', 'readlink', 'dirname',
        'basename', 'tr', 'cut', 'sed', 'grep', 'awk', 'sort', 'uniq', 'seq', 'date', 'stat', 'mktemp', 'tar', 'git', 'nice', 'tail', 'head', 'wc',
        'touch', 'id', 'cmp', 'true', 'false'];
    /** The real commands only the stubs call (links in $base/private, never on the scripts' PATH): their stubs go first. */
    private const PRIVATE_TOOLS = ['rsync', 'flock', 'getent'];
    /** Absolute paths a script may write without $R: what it puts INTO a file for the box, or the name it matches against. */
    private const UNPREFIXED_OK = [
        "'47 * * * * root /usr/sbin/logrotate -s /var/lib/logrotate/cw-web.status /etc/cw/logrotate-cw-web.conf'",
        'store=/srv/cw-docs',
        '< /proc/sys/kernel/hostname || true',
        '/var/www*|*/public|*/public/*|/opt/*)',
    ];
    /** What a run must never leave on this machine: the box's own paths (not /srv/cw-docs: the test machine's own store is there). */
    private const OUTSIDE = ['/opt/cw-prod', '/opt/cw-prod-release', '/opt/cw-prod-releases', '/opt/cw-prod-incoming', '/etc/cron.d/cw-prod',
        '/etc/cron.d/cw-web-logrotate', '/etc/cw/cw-prod.cron.paused', '/etc/cw/cw-https.paused', '/etc/cw/cw-fpm.stopped',
        '/etc/logrotate.d/cw-prod', '/run/lock/cw-prod-install.lock', '/var/lib/cw-acme', '/var/log/cw-web',
        '/etc/php/8.3/fpm/pool.d/cw-web.conf', '/etc/apache2/sites-available/cw-https.conf', '/etc/letsencrypt/live/warehouse.floverfy.com',
        '/root/central-warehouse'];
    private const SYSPATH = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
    private const OLD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const NEW = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const MID = 'cccccccccccccccccccccccccccccccccccccccc';
    private const APP_ENV = "db_user=cw_app\ndb_password=not-a-real-password\ndb_host=private-db.example\ndb_port=25060\n";
    private const APPLIED = "0046_rollback_settings.sql               applied 2026-10-17 23:56:01";
    private const PENDING = self::APPLIED . "\n0047_next.sql                            PENDING";

    private string $base = '';
    private string $root = '';
    private string $stub = '';
    /** The running release's files before the run (installedBox). */
    private string $oldCode = '';
    /** @var resource|null */
    private $lock = null;
    /** @var resource|null */
    private $socket = null;

    protected function tearDown(): void
    {
        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }
        if ($this->base !== '' && str_starts_with($this->base, realpath(sys_get_temp_dir()) . '/cw_prodbeh_') && is_dir($this->base)) {
            @chmod($this->root . '/opt', 0755);
            exec('rm -rf ' . escapeshellarg($this->base));
        }
        parent::tearDown();
    }

    /** Every script reads CW_DEPLOY_ROOT once, first, refuses a tree without the marker, and names no path outside it. */
    public function testEveryScriptKeepsEveryPathUnderTheRoot(): void
    {
        foreach (self::SCRIPTS as $script) {
            $src = (string) file_get_contents(self::DIR . '/' . $script);
            self::assertSame(1, substr_count($src, 'CW_DEPLOY_ROOT:-/'), "{$script} reads CW_DEPLOY_ROOT once");
            self::assertMatchesRegularExpression('/^set -euo pipefail\n(?:#.*\n)*' . preg_quote(self::PREAMBLE, '/')
                . '[a-z_]+: CW_DEPLOY_ROOT=\$R is not a test tree [^\n]*" >&2; exit 2; \}\nfi\n/m', $src, "{$script}: right after set -euo pipefail, before anything runs");
            self::assertSame([], self::outsideTheTree($src), "{$script}: every absolute path is under \$R");
        }
        // The scan itself: an absolute path is caught, one under $R (or in a comment) is not.
        self::assertSame(['line 1: install -m 0644 x /etc/cron.d/cw-prod'], self::outsideTheTree("install -m 0644 x /etc/cron.d/cw-prod\n"));
        self::assertSame(['line 1: x=$(cat "/opt/cw-prod/REVISION")'], self::outsideTheTree("x=\$(cat \"/opt/cw-prod/REVISION\")\n"));
        self::assertSame([], self::outsideTheTree("install -m 0644 x \"\$R/etc/cron.d/cw-prod\" # not /etc/cron.d\n# /etc in a comment\n"));
        // A command named by its path, a PATH of the script's own, or `command -p` would pass the stubs by.
        self::assertSame(['line 1: /bin/systemctl stop php8.3-fpm', 'line 2: PATH=/usr/bin:/bin systemctl stop php8.3-fpm', 'line 3: command -p systemctl stop php8.3-fpm'],
            self::outsideTheTree("/bin/systemctl stop php8.3-fpm\nPATH=/usr/bin:/bin systemctl stop php8.3-fpm\ncommand -p systemctl stop php8.3-fpm\n"));
    }

    /** No stubbed command has a real twin on the scripts' PATH: a stub that is missing is "not found", never the real one. */
    public function testNoStubHasARealTwinOnTheScriptsPath(): void
    {
        $stubs = array_values(array_diff(scandir(self::STUBS) ?: [], ['.', '..', '_record']));
        self::assertContains('systemctl', $stubs);
        self::assertSame([], array_values(array_intersect($stubs, self::SAFE_TOOLS)));
        self::assertSame([], array_values(array_diff(self::PRIVATE_TOOLS, $stubs)), 'each private tool is reached through its stub');
        foreach ($stubs as $stub) {
            $file = (string) realpath(self::STUBS . '/' . $stub);
            self::assertStringStartsWith("#!/usr/bin/env bash\n# cw-deploy-stub\n", (string) file_get_contents($file), "{$stub} says it is a stub");
        }
    }

    /** The staging-marker cases below hold both answers, as the application gives them. */
    public function testTheMarkerCasesHoldBothAnswers(): void
    {
        $staging = 0;
        foreach (self::markers() as [$line]) {
            $staging += self::appSaysStaging(Config::fromArrays([], Config::parseEnv($line))) ? 1 : 0;
        }
        self::assertGreaterThanOrEqual(6, $staging);
        self::assertGreaterThanOrEqual(6, count(self::markers()) - $staging);
    }

    /** A CW_DEPLOY_ROOT that is not a test tree (no marker, relative, a space) is refused before anything runs. */
    public function testARootThatIsNotATestTreeIsRefused(): void
    {
        $this->needBox();
        $this->mainCheckout();
        $this->release('opt/cw-prod-release', self::NEW);
        mkdir($this->base . '/elsewhere', 0755);
        $tree = $this->tree();
        foreach ([$this->base . '/elsewhere', 'relative/tree', $this->base . '/with space'] as $root) {
            foreach (self::SCRIPTS as $script) {
                $this->clearCalls();
                [$code, $out, $err] = $this->runScript($script, ['--check'], ['CW_DEPLOY_ROOT' => $root]);
                self::assertSame(2, $code, "{$script} with CW_DEPLOY_ROOT={$root}: {$out}{$err}");
                self::assertStringContainsString("CW_DEPLOY_ROOT={$root} is not a test tree", $err, $script);
                self::assertSame('', $out, $script);
                self::assertSame('', $this->calls(), "{$script}: nothing ran");
            }
        }
        self::assertSame($tree, $this->tree());
    }

    /**
     * Each script's preamble alone, with a stand-in file for /proc/sys/kernel/hostname: a test root is taken only with its marker,
     * a hostname that was read and is not a CW server's, and the stub systemctl first on PATH.
     */
    public function testThePreambleTakesATestRootOnlyWhenEverythingHolds(): void
    {
        $this->needBox();
        $host = $this->base . '/kernel-hostname';
        $stubs = $this->base . '/bin:' . $this->base . '/sys';
        $cases = [ // hostname file (null: none), PATH, marker => accepted
            'a test machine' => ["cw-test\n", $stubs, true, true],
            'a name like a server\'s' => ["cw-prod-lon1-01x\n", $stubs, true, true],
            'the production box' => ["cw-prod-lon1-01\n", $stubs, true, false],
            'the main server' => ["vpg-ded-lon1-01\n", $stubs, true, false],
            'staging' => ["FlovEcom-Central-Warehouse\n", $stubs, true, false],
            'a hostname that cannot be read' => [null, $stubs, true, false],
            'an empty hostname' => ['', $stubs, true, false],
            'no marker' => ["cw-test\n", $stubs, false, false],
            'no stub systemctl' => ["cw-test\n", $this->base . '/sys', true, false],
        ];
        foreach (self::SCRIPTS as $script) {
            $src = (string) file_get_contents(self::DIR . '/' . $script);
            self::assertSame(1, preg_match('/^R=\$\{CW_DEPLOY_ROOT:-\/\}\n.*?\nfi\n/ms', $src, $m), $script);
            $preamble = str_replace('/proc/sys/kernel/hostname', $host, $m[0], $replaced);
            self::assertSame(1, $replaced, $script);
            foreach ($cases as $what => [$hostname, $path, $marker, $accepted]) {
                @unlink($host);
                if ($hostname !== null) {
                    file_put_contents($host, $hostname);
                }
                $marker ? touch($this->root . '/.cw-deploy-test-root') : unlink($this->root . '/.cw-deploy-test-root');
                $p = proc_open(['/bin/bash', '-c', "set -euo pipefail\n{$preamble}echo taken\n"], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes, $this->base, ['PATH' => $path, 'CW_DEPLOY_ROOT' => $this->root, 'LC_ALL' => 'C']);
                self::assertIsResource($p);
                $out = (string) stream_get_contents($pipes[1]);
                $err = (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $code = proc_close($p);
                self::assertSame($accepted ? [0, "taken\n"] : [2, ''], [$code, $out], "{$script}, {$what}: {$err}");
                if (!$accepted) {
                    self::assertStringContainsString("CW_DEPLOY_ROOT={$this->root} is not a test tree", $err, "{$script}, {$what}");
                }
            }
        }
        touch($this->root . '/.cw-deploy-test-root');
    }

    /**
     * A marked tree is still refused where the stub systemctl does not come first on PATH (the real one, or none): a test root
     * never runs with real services. (Never on a CW server either: the kernel's hostname, read from /proc; ProductionDeployTest.)
     */
    public function testAMarkedTreeIsRefusedWithoutTheStubsFirstOnPath(): void
    {
        $this->needBox();
        $this->mainCheckout();
        $this->release('opt/cw-prod-release', self::NEW);
        $tree = $this->tree();
        $paths = ['the plain tools only' => $this->base . '/sys'];
        if (is_executable('/usr/bin/systemctl') || is_executable('/bin/systemctl')) {
            $paths['the real systemctl first'] = self::SYSPATH . ':' . $this->base . '/bin';
        }
        foreach ($paths as $what => $path) {
            // push_code.sh would reach for ssh: only ever with the plain tools (no ssh at all) here.
            foreach ($what === 'the plain tools only' ? self::SCRIPTS : array_diff(self::SCRIPTS, ['push_code.sh']) as $script) {
                $this->clearCalls();
                [$code, $out, $err] = $this->runScript($script, ['--check'], [], $path);
                self::assertSame(2, $code, "{$script}, {$what}: {$out}{$err}");
                self::assertStringContainsString("CW_DEPLOY_ROOT={$this->root} is not a test tree", $err, "{$script}, {$what}");
                self::assertSame('', $this->calls(), "{$script}, {$what}: nothing ran");
            }
        }
        self::assertSame($tree, $this->tree());
    }

    /** A push sends exactly the commit's files (no tests/, tools/ or ignored file), runs composer before REVISION exists, and swaps the copy in whole. */
    public function testAPushSendsTheCommitsFilesAndWritesRevisionLast(): void
    {
        $this->needBox();
        $rev = $this->mainCheckout();
        $dest = $this->release('opt/cw-prod-release', self::OLD);
        file_put_contents("{$dest}/only-in-the-old-copy.txt", "old\n");
        [$code, $out, $err] = $this->runScript('push_code.sh');
        self::assertSame(0, $code, $out . $err);
        self::assertSame($rev, trim((string) file_get_contents("{$dest}/REVISION")));
        foreach (['public/index.php', 'src/Thing.php', 'deploy/production/install_cron.sh', 'vendor/autoload.php'] as $f) {
            self::assertFileExists("{$dest}/{$f}");
        }
        foreach (['only-in-the-old-copy.txt', 'tests', 'tools', '.env', '.git'] as $f) {
            self::assertFileDoesNotExist("{$dest}/{$f}", "{$f} is not one of the commit's files");
        }
        self::assertSame('755 root:root', $this->stat('opt/cw-prod-release'), 'the web server can enter it');
        self::assertSame([], $this->entries('opt/cw-prod-incoming'), 'the incoming copy became the release copy');
        self::assertSame([], glob($this->root . '/tmp/cw-push.*') ?: [], 'the local export is removed');
        $calls = $this->calls();
        self::assertStringContainsString("composer install --no-dev --no-progress --prefer-dist --no-plugins --no-scripts --quiet (in /opt/cw-prod-incoming/{$rev}-", $calls);
        self::assertStringContainsString('REVISION absent)', $calls, 'composer runs before REVISION is written');
        // Each stub's own line (the ssh line carries the remote script's text too).
        self::assertInOrder($calls, ['ssh root@129.212.162.243 hostname', "\nrsync -az", "\nflock -w 600 /run/lock/cw-prod-install.lock", "\ncomposer install"]);
    }

    /** A push that stops (the transfer, or composer) leaves the release copy as it was: the old whole release and its REVISION. */
    public function testAnInterruptedPushLeavesTheReleaseCopyAsItWas(): void
    {
        $this->needBox();
        $this->mainCheckout();
        $dest = $this->release('opt/cw-prod-release', self::OLD);
        file_put_contents("{$dest}/only-in-the-old-copy.txt", "old\n");
        $copy = $this->tree('opt/cw-prod-release');
        foreach (['the transfer' => ['rsync_exit', 12], 'composer' => ['composer_exit', 23]] as $what => [$answer, $exit]) {
            $this->clearCalls();
            $this->answer($answer, (string) $exit);
            [$code, $out, $err] = $this->runScript('push_code.sh');
            self::assertSame($exit, $code, "{$what}: {$out}{$err}");
            self::assertSame($copy, $this->tree('opt/cw-prod-release'), "{$what} stopped: the release copy is untouched");
            foreach ($this->entries('opt/cw-prod-incoming') as $incoming) {
                self::assertFileDoesNotExist("{$this->root}/opt/cw-prod-incoming/{$incoming}/REVISION", 'an incoming copy is never marked whole');
            }
            self::assertSame([], glob($this->root . '/tmp/cw-push.*') ?: [], 'the local export is removed');
            if ($what === 'the transfer') {
                self::assertDoesNotMatchRegularExpression('/composer|flock/', $this->calls(), 'nothing after the stopped transfer');
            } else {
                self::assertMatchesRegularExpression('/^composer install /m', $this->calls(), 'composer ran, and failed');
            }
        }
    }

    /** The first push to a new box (no composer yet) leaves code to run provision.sh from, never a release: install_cron.sh refuses it. */
    public function testTheFirstPushIsNotARelease(): void
    {
        $this->needBox();
        $this->mainCheckout();
        $this->stubBin(['composer']);
        [$code, $out, $err] = $this->runScript('push_code.sh');
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('composer is not installed there yet (no REVISION: the copy is not a release)', $out);
        $dest = $this->root . '/opt/cw-prod-release';
        self::assertFileExists("{$dest}/deploy/production/provision.sh");
        self::assertFileDoesNotExist("{$dest}/REVISION");
        self::assertSame([], $this->entries('opt/cw-prod-incoming'));
        $this->stubBin();
        $this->write('etc/cw/app.env', self::APP_ENV . "db_name=cw_prod\n", 0600);
        $this->clearCalls();
        [$code, $out, $err] = $this->runScript('install_cron.sh', ['--migrate']);
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString('is not a whole release (push_code.sh writes REVISION last, after composer): run push_code.sh', $err);
        self::assertDirectoryDoesNotExist($this->root . '/opt/cw-prod-releases', 'nothing built');
        self::assertFileDoesNotExist($this->root . '/opt/cw-prod');
        self::assertDoesNotMatchRegularExpression('/rsync|systemctl|a2dissite|migrate\.php/', $this->calls());
    }

    /** One install or push at a time: a push waits for the install lock and replaces nothing without it; an install refuses while a push holds it. */
    public function testAPushAndAnInstallExcludeEachOther(): void
    {
        $this->needBox();
        $this->installedBox();
        $rev = $this->mainCheckout();
        $copy = $this->tree('opt/cw-prod-release');
        $running = $this->running();
        $this->lock = fopen($this->root . '/run/lock/cw-prod-install.lock', 'c') ?: null;
        self::assertNotNull($this->lock);
        self::assertTrue(flock($this->lock, LOCK_EX | LOCK_NB));
        $this->answer('flock_wait', '1');
        [$code, $out, $err] = $this->runScript('push_code.sh');
        self::assertSame(1, $code, $out . $err);
        self::assertSame($copy, $this->tree('opt/cw-prod-release'), 'the release copy an install may be reading is never replaced');
        self::assertDoesNotMatchRegularExpression('/^composer /m', $this->calls(), 'nothing ran without the lock');
        [$code, $out, $err] = $this->runScript('install_cron.sh');
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString("another install (or a push) holds {$this->root}/run/lock/cw-prod-install.lock", $err);
        self::assertSame([self::OLD], $this->entries('opt/cw-prod-releases'), 'nothing built');
        self::assertSame($running, $this->running());
        // The lock was the only obstacle.
        flock($this->lock, LOCK_UN);
        [$code, $out, $err] = $this->runScript('install_cron.sh');
        self::assertSame(0, $code, $out . $err);
        self::assertSame('/opt/cw-prod-releases/' . self::NEW, $this->running()['live']);
        [$code, $out, $err] = $this->runScript('push_code.sh');
        self::assertSame(0, $code, $out . $err);
        self::assertSame($rev, trim((string) file_get_contents($this->root . '/opt/cw-prod-release/REVISION')));
    }

    /** push_code.sh sends GitHub's main only: a changed checkout, a main GitHub does not have, or another box is refused before anything is sent. */
    public function testAPushRefusesWhatIsNotGithubsMain(): void
    {
        $this->needBox();
        $this->mainCheckout();
        $repo = $this->root . '/root/central-warehouse';
        file_put_contents("{$repo}/public/index.php", "<?php // changed by hand\n");
        $this->expectRefusal('push_code.sh', 'the checkout has changes that are not committed');
        $this->git($repo, 'commit', '-q', '-am', 'not on GitHub yet');
        $this->expectRefusal('push_code.sh', "is not GitHub's main ");
        self::assertStringNotContainsString('ssh', $this->calls(), 'refused before the box is asked anything');
        $this->git($repo, 'push', '-q', 'origin', 'main');
        $this->answer('hostname', 'cw-staging');
        $this->expectRefusal('push_code.sh', 'root@129.212.162.243 is not cw-prod-lon1-01');
        self::assertDirectoryDoesNotExist($this->root . '/opt/cw-prod-release');
        self::assertDirectoryDoesNotExist($this->root . '/opt/cw-prod-incoming');
        self::assertStringNotContainsString('rsync', $this->calls());
    }

    /** A push that lands while install_cron.sh copies the release copy (REVISION changes during the copy): refused, the half-built release removed. */
    public function testAReleaseCopyThatChangesDuringTheCopyIsRefused(): void
    {
        $this->needBox();
        $this->installedBox();
        $running = $this->running();
        file_put_contents($this->stub . '/hooks/rsync.after', 'printf "%s\n" ' . self::MID . ' > "$CW_DEPLOY_ROOT/opt/cw-prod-release/REVISION"' . "\n");
        [$code, $out, $err] = $this->runScript('install_cron.sh', ['--migrate']);
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString('changed while it was copied (a push ran): run this again', $err);
        self::assertSame([self::OLD], $this->entries('opt/cw-prod-releases'), 'no half-built release left, none put in place');
        self::assertSame($running, $this->running(), 'nothing paused, nothing switched');
        self::assertDoesNotMatchRegularExpression('/systemctl|a2dissite|migrate\.php/', $this->calls());
    }

    /** A pending migration without --migrate: exit 3, and nothing that runs changes (the new release is only built beside the running one). */
    public function testAPendingMigrationWithoutMigrateChangesNothingThatRuns(): void
    {
        $this->needBox();
        $this->installedBox();
        $this->answer('migrate_status', self::PENDING);
        $running = $this->running();
        [$code, $out, $err] = $this->runScript('install_cron.sh');
        self::assertSame(3, $code, $out . $err);
        self::assertStringContainsString('cw_prod has pending migrations for the new code; re-run with --migrate (nothing running was changed)', $err);
        self::assertSame($running, $this->running(), 'the same release runs, with its jobs, its web and php-fpm');
        self::assertSame(self::NEW, trim((string) file_get_contents($this->root . '/opt/cw-prod-releases/' . self::NEW . '/REVISION')));
        $calls = $this->calls();
        self::assertSame(1, substr_count($calls, 'bin/migrate.php'), 'only the status was asked');
        self::assertStringContainsString('php /opt/cw-prod-releases/' . self::NEW . '/bin/migrate.php --db=cw_prod --status', $calls, 'with the NEW code');
        self::assertDoesNotMatchRegularExpression('/systemctl|a2dissite|a2ensite|pgrep/', $calls);
    }

    /** @return array<string, array{string}> */
    public static function statuses(): array
    {
        return ['a pending migration' => [self::PENDING], 'nothing pending (the grants still converge)' => [self::APPLIED]];
    }

    /** --migrate: the cron file aside and the jobs waited for, the vhost off, php-fpm drained and stopped; the schema; the switch; all back. */
    #[DataProvider('statuses')]
    public function testMigratePausesTheWritersDrainsThemAndBringsThemBack(string $status): void
    {
        $this->needBox();
        $this->installedBox();
        $this->answer('migrate_status', $status);
        $this->answer('fpm_drain', '2');      // php-fpm still answers two checks after SIGQUIT
        $this->answer('jobs', "2 php bin/expire_reservations.php --db=cw_prod\n1000 php bin/expire_reservations.php --db=cw_staging\n");  // a cw_prod job runs two more checks
        [$code, $out, $err] = $this->runScript('install_cron.sh', ['--migrate']);
        self::assertSame(0, $code, $out . $err);
        self::assertSame(['cron=absent paused_cron=present vhost=off fpm=inactive web_marker=present fpm_marker=present jobs=0 live=/opt/cw-prod-releases/' . self::OLD],
            $this->migrations(), 'while the schema changed: every writer paused, no cw_prod job running, the old release still in place');
        self::assertSame($this->resumed(), $this->running(), 'after: the new release with its jobs, the web back, no marker left');
        $kept = glob($this->root . '/etc/cw/cw-prod.cron.replaced-*') ?: [];
        self::assertCount(1, $kept, 'the paused cron file is kept aside, never deleted');
        self::assertSame('# the jobs of release ' . self::OLD . "\n", file_get_contents($kept[0]));
        $calls = $this->calls();
        self::assertInOrder($calls, ['a2dissite -q cw-https', 'systemctl reload apache2', 'systemctl kill -s QUIT php8.3-fpm', 'systemctl stop php8.3-fpm',
            "bin/migrate.php --db=cw_prod\n", 'systemctl start php8.3-fpm', 'a2ensite -q cw-https', 'systemctl reload apache2']);
        $quit = (int) strpos($calls, 'systemctl kill -s QUIT php8.3-fpm');
        $stop = (int) strpos($calls, 'systemctl stop php8.3-fpm');
        self::assertSame(3, substr_count(substr($calls, $quit, $stop - $quit), 'systemctl is-active --quiet php8.3-fpm'),
            'php-fpm stopped only after its requests finished (two checks busy, the third free)');
        $migrate = (int) strpos($calls, "bin/migrate.php --db=cw_prod\n");
        self::assertSame(4, substr_count(substr($calls, 0, $migrate), 'pgrep -f'), 'the running cw_prod job waited for (two busy, one free, one last check), not the other schema\'s');
    }

    /** Jobs still running after two minutes: the run stops with the writers paused and the schema untouched. */
    public function testJobsThatDoNotFinishLeaveTheWritersPausedAndTheSchemaAlone(): void
    {
        $this->needBox();
        $this->installedBox();
        $this->answer('migrate_status', self::PENDING);
        $this->answer('jobs', "1000 php bin/invariants.php --db=cw_prod\n");
        [$code, $out, $err] = $this->runScript('install_cron.sh', ['--migrate']);
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString('jobs still running after 2 minutes; the writers stay paused, run this again with --migrate', $err);
        self::assertSame([], $this->migrations(), 'no migration ran');
        self::assertSame($this->paused(), $this->running());
        self::assertSame(121, substr_count($this->calls(), 'pgrep -f'), 'two minutes of checks, then the last one');
    }

    /**
     * A failed migration leaves every writer paused; a run without --migrate then refuses and changes nothing. The failure was in
     * the grants, after the SQL went in: nothing is pending any more, and a --migrate run must still converge, failing again with
     * the writers paused, and resume only once it succeeds.
     */
    public function testAFailedMigrationKeepsTheWritersPausedUntilAMigrateRunSucceeds(): void
    {
        $this->needBox();
        $this->installedBox();
        $this->answer('migrate_status', self::PENDING);
        $this->answer('migrate_exit', '1');
        [$code, $out, $err] = $this->runScript('install_cron.sh', ['--migrate']);
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString('the migration (or the grants) FAILED; the writers stay paused', $err);
        self::assertSame($this->paused(), $this->running(), 'nothing came back onto a half-migrated schema');

        $this->clearCalls();
        $tree = $this->tree();
        [$code, $out, $err] = $this->runScript('install_cron.sh');
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString('the writers are paused from an earlier run that stopped: re-run with --migrate', $err);
        self::assertSame($tree, $this->tree(), 'the refused run changed nothing');
        self::assertSame($this->paused(), $this->running());
        self::assertDoesNotMatchRegularExpression('/rsync|systemctl|a2ensite|a2dissite|migrate\.php/', $this->calls());

        $this->answer('migrate_status', self::APPLIED);
        [$code, $out, $err] = $this->runScript('install_cron.sh', ['--migrate']);
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString('the migration (or the grants) FAILED; the writers stay paused', $err);
        self::assertSame($this->paused(), $this->running(), 'nothing pending, the grants failing again: still paused');

        unlink($this->stub . '/answers/migrate_exit');
        [$code, $out, $err] = $this->runScript('install_cron.sh', ['--migrate']);
        self::assertSame(0, $code, $out . $err);
        $during = 'cron=absent paused_cron=present vhost=off fpm=inactive web_marker=present fpm_marker=present jobs=0 live=/opt/cw-prod-releases/' . self::OLD;
        self::assertSame([$during, $during, $during], $this->migrations(), 'every convergence ran with the writers paused, nothing pending or not');
        self::assertSame($this->resumed(), $this->running());
    }

    /** Each release is readable by the web server whatever modes the release copy carried; www-data's access is checked before the switch. */
    public function testEachReleaseIsReadableByTheWebServer(): void
    {
        $this->needBox();
        $this->installedBox();
        $src = $this->root . '/opt/cw-prod-release';
        foreach (['', '/public', '/vendor', '/bin'] as $d) {
            chmod($src . $d, 0700);
        }
        foreach (['/public/index.php', '/vendor/autoload.php', '/bin/migrate.php'] as $f) {
            chmod($src . $f, 0600);
        }
        [$code, $out, $err] = $this->runScript('install_cron.sh');
        self::assertSame(0, $code, $out . $err);
        $new = $this->root . '/opt/cw-prod-releases/' . self::NEW;
        $all = [$new => new \SplFileInfo($new)] + iterator_to_array(new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($new, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST));
        foreach ($all as $path => $info) {
            $mode = $info->getPerms() & 0777;
            $rel = substr((string) $path, strlen($this->root));
            self::assertSame($info->isDir() ? 05 : 04, $mode & ($info->isDir() ? 05 : 04), sprintf('%s (%o): others may %s it', $rel, $mode, $info->isDir() ? 'enter' : 'read'));
            self::assertSame(0, $mode & 022, "{$rel}: only root writes it");
        }
        $n = '/opt/cw-prod-releases/' . self::NEW;
        self::assertStringContainsString("runuser -u www-data -- test -r {$n}/public/index.php -a -r {$n}/vendor/autoload.php", $this->calls());
        self::assertSame($n, $this->running()['live']);
    }

    /** A release the web server cannot reach (a directory above it www-data may not enter) is never switched to, and nothing is paused. */
    public function testAReleaseTheWebServerCannotReachIsNotSwitchedTo(): void
    {
        $this->needBox();
        $this->installedBox();
        $running = $this->running();
        chmod($this->root . '/opt', 0700);
        [$code, $out, $err] = $this->runScript('install_cron.sh', ['--migrate']);
        chmod($this->root . '/opt', 0755);
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString("www-data cannot read {$this->root}/opt/cw-prod-releases/" . self::NEW . ': the web server could not run it', $err);
        self::assertSame($running, $this->running(), 'still the old release, nothing paused');
        self::assertDoesNotMatchRegularExpression('/systemctl|a2dissite|migrate\.php/', $this->calls());
    }

    /** @return array<string, array{string}> app.env lines, each once */
    public static function markers(): array
    {
        $out = [];
        foreach (['environment=staging', "environment='staging'", 'environment="staging"', 'export environment=staging', 'ENVIRONMENT=staging',
            'Environment = Staging', "environment=staging \t", 'environment= STAGING', 'environment=production', 'environment=stagingx',
            '# environment=staging', 'environment_old=staging', 'environment=', 'environment="staging', 'environment=staging # old', ''] as $line) {
            $out[$line === '' ? '(no marker)' : (string) json_encode($line)] = [$line];
        }
        return $out;
    }

    /**
     * The staging marker read as the application reads it (CW\Ui\Kernel: Config::loadApp()->appFile(TestRefPurge::ENV_KEY), trimmed
     * and lower-cased): both installers refuse exactly what would make a screen say TEST SYSTEM, before anything is built or changed.
     */
    #[DataProvider('markers')]
    public function testTheStagingMarkerIsReadAsTheApplicationReadsIt(string $line): void
    {
        $this->needBox();
        $this->installedBox();
        $file = $this->write('etc/cw/app.env', self::APP_ENV . "db_name=cw_prod\n{$line}\n", 0600);
        $staging = self::appSaysStaging(Config::loadApp(['CW_APP_ENV' => $file]));
        $this->answer('migrate_status', self::PENDING); // past the guards, a run stops at the migration check (exit 3)
        [$code, $out, $err] = $this->runScript('install_cron.sh');
        self::assertSame($staging ? 1 : 3, $code, $out . $err);
        if ($staging) {
            self::assertStringContainsString('says environment=staging: never on production', $err);
            self::assertSame([self::OLD], $this->entries('opt/cw-prod-releases'), 'refused before anything is built');
        }
        $this->clearCalls();
        [$code, $out, $err] = $this->runScript('enable_https.sh', ['--check']);
        self::assertSame($staging ? 1 : 0, $code, $out . $err);
        self::assertStringContainsString($staging ? 'says environment=staging: never on production' : 'guards passed', $staging ? $err : $out);
    }

    /** @return array<string, array{string, array<string, string>}> */
    public static function otherSchemas(): array
    {
        return [
            "staging's" => ["db_name=cw_staging\n", []],
            'a copy' => ["db_name=cw_prod_copy\n", []],
            'a test schema, quoted' => ["DB_NAME=\"cw_test_prodtest\"\n", []],
            'none' => ['', []],
            'another, with CW_DB_NAME=cw_prod in the environment' => ["db_name=cw_other\n", ['CW_DB_NAME' => 'cw_prod']],
            'a line CW\Config cannot read' => ["db_name=cw_prod\nthis line is not key = value\n", []],
        ];
    }

    /** An app.env that names another schema (or cannot be read) is refused by both installers, before anything is built or changed. */
    #[DataProvider('otherSchemas')]
    public function testAnAppEnvNamingAnotherSchemaIsRefused(string $lines, array $env): void
    {
        $this->needBox();
        $this->installedBox();
        $this->write('etc/cw/app.env', self::APP_ENV . $lines, 0600);
        $running = $this->running();
        [$code, $out, $err] = $this->runScript('install_cron.sh', ['--migrate'], $env);
        self::assertSame(1, $code, $out . $err);
        self::assertMatchesRegularExpression("/names the schema '[^']*', not cw_prod|cannot read /", $err);
        self::assertSame([self::OLD], $this->entries('opt/cw-prod-releases'), 'nothing built');
        self::assertSame($running, $this->running(), 'nothing paused');
        [$code, $out, $err] = $this->runScript('enable_https.sh', ['--check'], $env);
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString('expected cw_prod (the public vhost must never serve another schema)', $err);
    }

    /** cw_prod written any way the application reads it passes both installers' guards. */
    public function testCwProdWrittenAnyWayTheApplicationReadsItPasses(): void
    {
        $this->needBox();
        $this->installedBox();
        $this->write('etc/cw/app.env', self::APP_ENV . "export DB_NAME = 'cw_prod'\nenvironment=production\n", 0600);
        $this->answer('migrate_status', self::PENDING);
        [$code, $out, $err] = $this->runScript('install_cron.sh');
        self::assertSame(3, $code, $out . $err);
        [$code, $out, $err] = $this->runScript('enable_https.sh', ['--check']);
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('guards passed (code in ' . $this->root . '/opt/cw-prod, schema cw_prod)', $out);
    }

    /** enable_https.sh: php-fpm may read app.env (0640 root:www-data), never db.env (0600 root); ui_secret_key made once, never shown, never replaced. */
    public function testEnableHttpsOpensAppEnvToPhpFpmAndMakesTheKeyOnce(): void
    {
        $this->needBox();
        $this->installedBox();
        $this->beforeHttps();
        chmod($this->root . '/etc/cw', 0755);
        chmod($this->root . '/etc/cw/db.env', 0644);
        $this->socket = stream_socket_server('unix://' . $this->root . '/run/php/php8.3-fpm-cw-web.sock') ?: null;
        self::assertNotNull($this->socket);
        [$code, $out, $err] = $this->runScript('enable_https.sh');
        self::assertSame(0, $code, $out . $err);
        self::assertSame('750 root:www-data', $this->stat('etc/cw'));
        self::assertSame('640 root:www-data', $this->stat('etc/cw/app.env'));
        self::assertSame('600 root:root', $this->stat('etc/cw/db.env'));
        $key = Config::parseEnvFile($this->root . '/etc/cw/app.env')['ui_secret_key'] ?? '';
        self::assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen((string) base64_decode($key, true)), 'a secretbox key');
        self::assertStringContainsString('app.env: ui_secret_key generated (not shown)', $out);
        self::assertStringNotContainsString($key, $out . $err . $this->calls(), 'the key is never printed');
        self::assertFileEquals(self::DIR . '/php-fpm-cw-web.conf', $this->root . '/etc/php/8.3/fpm/pool.d/cw-web.conf');
        self::assertFileEquals(self::DIR . '/apache-cw-https.conf', $this->root . '/etc/apache2/sites-available/cw-https.conf');
        self::assertStringContainsString("47 * * * * root /usr/sbin/logrotate -s /var/lib/logrotate/cw-web.status /etc/cw/logrotate-cw-web.conf\n",
            (string) file_get_contents($this->root . '/etc/cron.d/cw-web-logrotate'), "the cron line names the box's own paths");
        self::assertSame(['cw-acme', 'cw-https'], $this->running()['sites']);
        self::assertInOrder($this->calls(), ['systemctl reload php8.3-fpm', 'a2ensite -q cw-acme',
            'certbot certonly --webroot -w /var/lib/cw-acme -d warehouse.floverfy.com', 'a2ensite -q cw-https']);

        [$code, $out, $err] = $this->runScript('enable_https.sh');
        self::assertSame(0, $code, $out . $err);
        self::assertSame($key, Config::parseEnvFile($this->root . '/etc/cw/app.env')['ui_secret_key'] ?? '', 'the key is never replaced');
        self::assertStringContainsString('app.env: ui_secret_key present', $out);
        self::assertSame('640 root:www-data', $this->stat('etc/cw/app.env'));
    }

    /** @return array<string, array{string, string, string}> what is wrong (an answer, a file, a file gone), its value, the refusal */
    public static function httpsRefusals(): array
    {
        return [
            'the name does not resolve yet' => ['dns', '', 'does not resolve yet'],
            'the name points elsewhere' => ['dns', '203.0.113.9', "resolves to '203.0.113.9', expected only 129.212.162.243"],
            'the name also points elsewhere' => ['dns', '129.212.162.243 203.0.113.9', 'expected only 129.212.162.243'],
            "the reserved address is another droplet's" => ['meta_ip', '203.0.113.9', "DigitalOcean's metadata does not give this droplet"],
            'no UI in the running release' => ['gone:opt/cw-prod-releases/' . self::OLD . '/public/ui/assets/app.css', '', 'has no UI yet'],
            'the one-time passwords are still on the box' => ['file:etc/cw/initial_staff.txt', "one-time passwords\n", 'still holds one-time passwords'],
            'a placeholder account is active' => ['db_value', '2', 'placeholder staff accounts active: 2'],
        ];
    }

    /** enable_https.sh refuses, before it installs or changes anything, until it is safe to open the site. */
    #[DataProvider('httpsRefusals')]
    public function testEnableHttpsRefusesBeforeChangingAnything(string $what, string $value, string $refusal): void
    {
        $this->needBox();
        $this->installedBox();
        $this->beforeHttps();
        if (str_starts_with($what, 'file:')) {
            $this->write(substr($what, 5), $value, 0600);
        } elseif (str_starts_with($what, 'gone:')) {
            unlink($this->root . '/' . substr($what, 5));
        } else {
            $this->answer($what, $value);
        }
        $tree = $this->tree();
        [$code, $out, $err] = $this->runScript('enable_https.sh');
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString($refusal, $err);
        self::assertSame($tree, $this->tree(), 'nothing changed');
        self::assertSame('600 root:root', $this->stat('etc/cw/app.env'));
        self::assertDoesNotMatchRegularExpression('/apt-get|a2enmod|a2enconf|a2ensite|systemctl|certbot/', $this->calls());
    }

    /** provision.sh: --check lists what is missing and changes nothing; a run allows SSH (and 80, 443) before it switches the firewall on. */
    public function testProvisionAllowsSshBeforeTheFirewallAndCheckChangesNothing(): void
    {
        $this->needBox();
        $this->release('opt/cw-prod-release', self::NEW);
        $this->write('etc/os-release', "PRETTY_NAME=\"Ubuntu 24.04.3 LTS\"\nNAME=\"Ubuntu\"\nVERSION_ID=\"24.04\"\nID=ubuntu\n", 0644);
        $this->answer('dpkg_missing', "certbot\nufw\n");
        $tree = $this->tree();
        [$code, $out, $err] = $this->runScript('provision.sh', ['--check']);
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('missing packages: certbot ufw', $out);
        self::assertSame($tree, $this->tree());
        self::assertDoesNotMatchRegularExpression('/apt-get|ufw (allow|default|--force)|timedatectl set|a2enmod|systemctl/', $this->calls());

        $this->clearCalls();
        [$code, $out, $err] = $this->runScript('provision.sh');
        self::assertSame(0, $code, $out . $err);
        $calls = $this->calls();
        self::assertStringContainsString('apt-get install -y -qq -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold certbot ufw', $calls);
        self::assertInOrder($calls, ['ufw allow OpenSSH', 'ufw allow 80/tcp', 'ufw allow 443/tcp', 'ufw default deny incoming', 'ufw --force enable']);
        self::assertFileDoesNotExist($this->stub . '/state/ssh_cut', 'the firewall never came on without SSH allowed');
        self::assertSame("APT::Periodic::Update-Package-Lists \"1\";\nAPT::Periodic::Unattended-Upgrade \"1\";\n",
            file_get_contents($this->root . '/etc/apt/apt.conf.d/20auto-upgrades'));
        self::assertStringContainsString('firewall on: 6 rules', $out);
    }

    /** provision.sh and install_file_store.sh run on the production box only, from a whole release copy, and refuse before changing anything. */
    public function testProvisionAndTheFileStoreRefuseAnotherBox(): void
    {
        $this->needBox();
        $this->release('opt/cw-prod-release', self::NEW);
        $this->write('etc/cw/app.env', self::APP_ENV . "db_name=cw_prod\n", 0600);
        foreach (['Debian GNU/Linux 12 (bookworm)' => ['debian', '12'], 'Ubuntu 22.04.5 LTS' => ['ubuntu', '22.04'], 'Pop!_OS 24.04 LTS' => ['pop', '24.04']] as $pretty => [$id, $version]) {
            $this->write('etc/os-release', "PRETTY_NAME=\"{$pretty}\"\nVERSION_ID=\"{$version}\"\nID={$id}\n", 0644);
            $this->expectRefusal('provision.sh', "this is {$pretty}, not Ubuntu 24.04", ['--check']);
        }
        $this->write('etc/os-release', "PRETTY_NAME=\"Ubuntu 24.04.3 LTS\"\nVERSION_ID=\"24.04\"\nID=ubuntu\n", 0644);
        $this->answer('hostname', 'cw-staging');
        $tree = $this->tree();
        $this->expectRefusal('provision.sh', 'this box is cw-staging, not cw-prod-lon1-01 (the production server)', ['--check']);
        $this->expectRefusal('install_file_store.sh', 'this box is cw-staging, not cw-prod-lon1-01');
        self::assertSame($tree, $this->tree());
        unlink($this->stub . '/answers/hostname');
        exec('rm -rf ' . escapeshellarg($this->root . '/opt/cw-prod-release/vendor'));
        $this->expectRefusal('install_file_store.sh', 'has no vendor/ (push_code.sh installs it)');
        self::assertDirectoryDoesNotExist($this->root . '/srv/cw-docs');
        self::assertDoesNotMatchRegularExpression('/apt-get|ufw|chattr|runuser/', $this->calls());
    }

    /**
     * set_db_password.sh: two different passwords, none, or one the database refuses change nothing (db.env as it was, no
     * temporary file left, /etc/cw as it was: 0750 root:www-data); one the database takes replaces db.env, 0600, and /etc/cw stays
     * as it was. Nothing it prints holds a password typed, not even with an xtrace inherited from the shell. Another box: refused.
     */
    public function testSetDbPasswordWritesOnlyAPasswordTheDatabaseTakes(): void
    {
        $this->needBox();
        $this->release('opt/cw-prod-release', self::NEW);
        chown($this->root . '/etc/cw', 'root');
        chgrp($this->root . '/etc/cw', 'www-data');
        chmod($this->root . '/etc/cw', 0750);
        $this->write('etc/cw/app.env', self::APP_ENV . "db_name=cw_prod\n", 0640);
        chgrp($this->root . '/etc/cw/app.env', 'www-data');
        $this->write('etc/cw/db.env', "username = doadmin\npassword = the-old-one\nhost = private-db.example\nport = 25060\n", 0600);
        $before = $this->tree('etc/cw');
        $cases = [
            'two different passwords' => ["pw-first-1111\npw-other-2222\n", 0, 'did not match (or were empty): nothing changed'],
            'two empty lines' => ["\n\n", 0, 'did not match (or were empty): nothing changed'],
            'nothing typed' => ['', 0, ''],
            'a password the database refuses' => ["pw-refused-3333\npw-refused-3333\n", 1, 'the database did not accept this password: nothing changed'],
        ];
        foreach ($cases as $what => [$typed, $dbExit, $says]) {
            $this->answer('db_exit', (string) $dbExit);
            $this->clearCalls();
            [$code, $out, $err] = $this->runScript('set_db_password.sh', [], [], null, $typed);
            self::assertSame(1, $code, "{$what}: {$out}{$err}");
            self::assertStringContainsString($says, $err, $what);
            self::assertSame($before, $this->tree('etc/cw'), "{$what}: db.env, its directory and app.env as they were, no temporary file left");
            foreach (array_filter(explode("\n", $typed)) as $secret) {
                self::assertStringNotContainsString($secret, $out . $err . $this->calls(), "{$what}: never printed");
            }
        }
        self::assertStringContainsString('php -r (the database)', $this->calls(), 'the refused one was tried against the database');

        // Taken: with an xtrace inherited from the shell (SHELLOPTS), which the script switches off before anything is read.
        unlink($this->stub . '/answers/db_exit');
        $this->clearCalls();
        [$code, $out, $err] = $this->runScript('set_db_password.sh', [], ['SHELLOPTS' => 'xtrace'], null, "pw-good-4444\npw-good-4444\n");
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('+ R=', $err, 'the xtrace was on when it started');
        self::assertStringNotContainsString('pw-good-4444', $out . $err . $this->calls(), 'never printed, not even traced');
        self::assertStringContainsString('saved and checked: ' . $this->root . '/etc/cw/db.env (0600 root)', $out);
        self::assertSame("username = doadmin\npassword = pw-good-4444\nhost = private-db-mysql-lon1-37047-do-user-39758664-0.m.db.ondigitalocean.com\n"
            . "port = 25060\ndatabase = defaultdb\nsslmode = REQUIRED\n", file_get_contents($this->root . '/etc/cw/db.env'));
        self::assertSame('600 root:root', $this->stat('etc/cw/db.env'));
        self::assertSame('750 root:www-data', $this->stat('etc/cw'), '/etc/cw as it was: php-fpm still reads app.env');
        self::assertSame(['app.env', 'db.env'], $this->entries('etc/cw'), 'no temporary file left');
        self::assertSame(1, substr_count($this->calls(), 'php -r (the database)'), 'checked against the database before it was written');

        // Another box: refused before a password is read.
        $after = $this->tree('etc/cw');
        $this->answer('hostname', 'cw-staging');
        [$code, $out, $err] = $this->runScript('set_db_password.sh', [], [], null, "pw-staging-5555\npw-staging-5555\n");
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString('this box is cw-staging, not cw-prod-lon1-01', $err);
        self::assertSame($after, $this->tree('etc/cw'));
    }

    // ---- the tree, the stubs, the runs ----

    /** Skips where these must not or cannot run; builds the empty tree and the stub directory. */
    private function needBox(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('the scripts run as root on Linux (they chown): the test machine runs these');
        }
        foreach (['/var/www', '/opt/cw-staging', '/etc/cron.d/cw-staging'] as $p) {
            if (file_exists($p)) {
                self::markTestSkipped("{$p} exists: never on a box with a web server or CW on it");
            }
        }
        foreach (['bash', 'rsync', 'flock', 'git', 'tar', 'getent', 'stat'] as $c) {
            if (!is_executable("/usr/bin/{$c}")) {
                self::markTestSkipped("/usr/bin/{$c} is missing");
            }
        }
        if (posix_getpwnam('www-data') === false) {
            self::markTestSkipped('no user www-data');
        }
        foreach (self::OUTSIDE as $p) {
            self::assertFileDoesNotExist($p, 'an earlier run reached outside its tree, or this box runs CW: look before running these tests');
        }
        $this->base = realpath(sys_get_temp_dir()) . '/cw_prodbeh_' . bin2hex(random_bytes(5));
        $this->root = $this->base . '/root';
        $this->stub = $this->base . '/stub';
        foreach (['answers', 'state', 'hooks'] as $d) {
            mkdir("{$this->stub}/{$d}", 0755, true);
        }
        mkdir($this->base . '/home', 0700);
        chmod($this->base, 0755);
        touch($this->stub . '/calls.log');
        foreach (['etc/cw', 'etc/cron.d', 'etc/logrotate.d', 'etc/apt/apt.conf.d', 'etc/apache2/sites-available', 'etc/apache2/conf-available',
            'etc/php/8.3/fpm/pool.d', 'run/lock', 'run/php', 'var/log', 'var/lib', 'opt', 'srv', 'root/.ssh', 'tmp'] as $d) {
            mkdir("{$this->root}/{$d}", 0755, true);
        }
        chmod($this->root . '/tmp', 01777);
        chmod($this->root, 0755);
        touch($this->root . '/.cw-deploy-test-root');
        foreach (['sys' => self::SAFE_TOOLS, 'private' => self::PRIVATE_TOOLS] as $where => $tools) {
            mkdir("{$this->base}/{$where}", 0755);
            foreach ($tools as $tool) {
                foreach (['/usr/bin', '/bin', '/usr/sbin', '/sbin'] as $dir) {
                    if (is_executable("{$dir}/{$tool}")) {
                        symlink("{$dir}/{$tool}", "{$this->base}/{$where}/{$tool}");
                        break;
                    }
                }
            }
        }
        $this->stubBin();
    }

    /** bin/: links to every stub, but those named. */
    private function stubBin(array $without = []): void
    {
        $bin = $this->base . '/bin';
        if (is_dir($bin)) {
            exec('rm -rf ' . escapeshellarg($bin));
        }
        mkdir($bin, 0755);
        foreach (scandir(self::STUBS) ?: [] as $f) {
            if ($f[0] !== '.' && !in_array($f, $without, true)) {
                symlink((string) realpath(self::STUBS . '/' . $f), "{$bin}/{$f}");
            }
        }
    }

    /**
     * Runs deploy/production/$script from where the box keeps it (the main checkout for push_code.sh, the release copy for the
     * others) with PATH the stubs and then $base/sys (unless $path is given), and only the environment given here. A script that
     * does not keep every path under CW_DEPLOY_ROOT is not run at all: it could reach this machine's own files.
     *
     * @param list<string> $args
     * @param array<string, string> $env
     * @param ?string $typed what is typed at its prompts (stdin); null: nothing (/dev/null)
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function runScript(string $script, array $args = [], array $env = [], ?string $path = null, ?string $typed = null): array
    {
        $path ??= $this->base . '/bin:' . $this->base . '/sys';
        $src = (string) file_get_contents(self::DIR . '/' . $script);
        if (!str_contains($src, self::PREAMBLE) || self::outsideTheTree($src) !== []) {
            self::fail("{$script} not run: it does not keep every path under CW_DEPLOY_ROOT");
        }
        $file = $this->root . ($script === 'push_code.sh' ? '/root/central-warehouse' : '/opt/cw-prod-release') . '/deploy/production/' . $script;
        self::assertFileEquals(self::DIR . '/' . $script, $file, "the tree runs the repository's script");
        $out = $this->base . '/stdout';
        $err = $this->base . '/stderr';
        $own = self::machinesOwnConfig();
        $p = proc_open(['/bin/bash', $file, ...$args], [0 => $typed === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, $this->root, $env + [
            'PATH' => $path,
            'HOME' => $this->base . '/home',
            'LC_ALL' => 'C',
            'CW_DEPLOY_ROOT' => $this->root,
            'CW_STUB_DIR' => $this->stub,
            'CW_STUB_SYSPATH' => $this->base . '/private',
            'CW_STUB_PHP' => PHP_BINARY,
            'CW_STUB_AUTOLOAD' => $this->autoload(),
            'GIT_CONFIG_NOSYSTEM' => '1',
        ]);
        self::assertIsResource($p);
        if ($typed !== null) {
            fwrite($pipes[0], $typed);
            fclose($pipes[0]);
        }
        $code = proc_close($p);
        foreach (self::OUTSIDE as $o) {
            self::assertFileDoesNotExist($o, "{$script} reached outside its tree");
        }
        self::assertSame($own, self::machinesOwnConfig(), "{$script} reached this machine's own /etc/cw");
        return [$code, (string) file_get_contents($out), (string) file_get_contents($err)];
    }

    /** @return array<string, string> this machine's own /etc/cw (the test database's settings): mode, owner, group, time, content */
    private static function machinesOwnConfig(): array
    {
        clearstatcache();
        $out = [];
        foreach (['/etc/cw', '/etc/cw/app.env', '/etc/cw/db.env'] as $p) {
            $s = @stat($p);
            $out[$p] = $s === false ? 'absent' : sprintf('%o %d:%d %d %s', $s['mode'] & 07777, $s['uid'], $s['gid'], $s['mtime'], is_file($p) ? (string) @sha1_file($p) : '');
        }
        return $out;
    }

    /** @param list<string> $args */
    private function expectRefusal(string $script, string $message, array $args = []): void
    {
        $this->clearCalls();
        [$code, $out, $err] = $this->runScript($script, $args);
        self::assertSame(1, $code, "{$script}: {$out}{$err}");
        self::assertStringContainsString($message, $err, $script);
    }

    /** Code lines (not comments) that name an absolute path of the box without $R in front. @return list<string> */
    private static function outsideTheTree(string $src): array
    {
        $bad = [];
        foreach (preg_split('/\R/', $src) ?: [] as $n => $line) {
            $code = trim($line);
            if ($code === '' || $code[0] === '#') {
                continue;
            }
            $code = (string) preg_replace('/\s#\s.*$/', '', $code);
            foreach (self::UNPREFIXED_OK as $ok) {
                if (str_contains($code, $ok)) {
                    continue 2;
                }
            }
            if (preg_match('~(?<![\w$}{./-])/(?:etc|opt|run|var|srv|tmp|root|home|usr|bin|sbin|lib|boot|proc|sys|mnt|media)(?:/|\b)~', $code) === 1
                || preg_match('/(?<![\w-])PATH=|\bcommand\s+-p\b/', $code) === 1) {
                $bad[] = 'line ' . ($n + 1) . ': ' . $code;
            }
        }
        return $bad;
    }

    private static function appSaysStaging(Config $app): bool
    {
        return strtolower(trim((string) $app->appFile(TestRefPurge::ENV_KEY))) === 'staging';
    }

    private function autoload(): string
    {
        return (string) realpath(__DIR__ . '/../../vendor/autoload.php');
    }

    /** A release as push_code.sh leaves it: the commit's files (deploy/ is the repository's own; some only this one has), vendor/, and REVISION unless $rev is ''. */
    private function release(string $rel, string $rev): string
    {
        $dir = $this->root . '/' . $rel;
        foreach (['public/ui/assets', 'vendor', 'bin', 'deploy/production', 'deploy/staging', 'tests', 'tools'] as $d) {
            mkdir("{$dir}/{$d}", 0755, true);
        }
        if ($rev !== '') {
            file_put_contents("{$dir}/REVISION", $rev . "\n");
        }
        file_put_contents("{$dir}/vendor/autoload.php", "<?php\nrequire " . var_export($this->autoload(), true) . ";\n");
        file_put_contents("{$dir}/public/index.php", "<?php // the front controller of {$rev}\n");
        file_put_contents("{$dir}/public/ui/assets/app.css", "/* {$rev} */\n");
        file_put_contents("{$dir}/bin/migrate.php", "<?php // the php stub answers for it\n");
        file_put_contents("{$dir}/tests/ExampleTest.php", "<?php\n");
        file_put_contents("{$dir}/tools/tool.php", "<?php\n");
        // Files of its own (an endpoint, a directory): a copy of one release over another adds or removes them, and shows.
        mkdir("{$dir}/src/only-in-{$rev}", 0755, true);
        file_put_contents("{$dir}/src/only-in-{$rev}/Thing.php", "<?php // only in {$rev}\n");
        file_put_contents("{$dir}/public/only-in-{$rev}.php", "<?php // an endpoint only {$rev} has\n");
        foreach (glob(self::DIR . '/*') ?: [] as $f) {
            copy($f, "{$dir}/deploy/production/" . basename($f));
        }
        copy(dirname(self::DIR) . '/staging/seal_file_store.sh', "{$dir}/deploy/staging/seal_file_store.sh");
        return $dir;
    }

    /** The box after an earlier install: release OLD running (/opt/cw-prod) with its jobs, the web on (its certificate too); release NEW pushed. */
    private function installedBox(): void
    {
        $this->release('opt/cw-prod-release', self::NEW);
        $old = $this->release('opt/cw-prod-releases/' . self::OLD, self::OLD);
        // An earlier release is not the new one: each of its files one line longer (other contents, other sizes) and a day older,
        // so a copy of the new release over it shows however rsync compares (times, sizes, --size-only) and whatever part it copies.
        $day = time() - 86400;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($old, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $path => $info) {
            if ($info->isFile()) {
                file_put_contents((string) $path, "\n# the release " . self::OLD . ", as it ran\n", FILE_APPEND);
            }
            touch((string) $path, $day);
        }
        touch($old, $day);
        symlink($old, $this->root . '/opt/cw-prod');
        $this->write('etc/cw/app.env', self::APP_ENV . "db_name=cw_prod\n", 0600);
        $this->write('etc/cw/db.env', "username = doadmin\npassword = not-a-real-password\nhost = private-db.example\nport = 25060\n", 0600);
        $this->write('etc/cron.d/cw-prod', '# the jobs of release ' . self::OLD . "\n", 0644);
        file_put_contents($this->stub . '/state/fpm', "active\n");
        touch($this->stub . '/state/site_cw-https');
        $this->oldCode = $this->code('/opt/cw-prod-releases/' . self::OLD);
        mkdir($this->root . '/etc/letsencrypt/live/warehouse.floverfy.com', 0755, true);
        file_put_contents($this->root . '/etc/letsencrypt/live/warehouse.floverfy.com/fullchain.pem', "certificate\n");
    }

    /** The box before its first enable_https.sh: no HTTPS vhost, no certificate. */
    private function beforeHttps(): void
    {
        unlink($this->stub . '/state/site_cw-https');
        exec('rm -rf ' . escapeshellarg($this->root . '/etc/letsencrypt'));
    }

    /** The main server's checkout (/root/central-warehouse on main, equal to GitHub's main: here a bare repository). @return string HEAD */
    private function mainCheckout(): string
    {
        $repo = $this->root . '/root/central-warehouse';
        foreach (['deploy/production', 'public', 'src', 'tests', 'tools'] as $d) {
            mkdir("{$repo}/{$d}", 0755, true);
        }
        foreach (glob(self::DIR . '/*') ?: [] as $f) {
            copy($f, "{$repo}/deploy/production/" . basename($f));
        }
        file_put_contents("{$repo}/public/index.php", "<?php // the front controller\n");
        file_put_contents("{$repo}/src/Thing.php", "<?php\n");
        file_put_contents("{$repo}/tests/ThingTest.php", "<?php\n");
        file_put_contents("{$repo}/tools/tool.php", "<?php\n");
        file_put_contents("{$repo}/.gitignore", ".env\n/data/\n");
        $this->git($repo, 'init', '-q', '-b', 'main');
        $this->git($repo, 'add', '-A');
        $this->git($repo, 'commit', '-q', '-m', 'first');
        $this->git($this->base, 'init', '-q', '--bare', '-b', 'main', 'origin.git');
        $this->git($repo, 'remote', 'add', 'origin', $this->base . '/origin.git');
        $this->git($repo, 'push', '-q', 'origin', 'main');
        file_put_contents("{$repo}/.env", "SECRET=not-for-production\n"); // ignored by git: it must never travel
        chmod("{$repo}/.env", 0644);
        return trim($this->git($repo, 'rev-parse', 'HEAD'));
    }

    private function git(string $dir, string ...$args): string
    {
        $p = proc_open(['/usr/bin/git', ...$args], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, [
            'PATH' => self::SYSPATH, 'HOME' => $this->base . '/home', 'LC_ALL' => 'C', 'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_AUTHOR_NAME' => 'CW test', 'GIT_AUTHOR_EMAIL' => 'test@example.invalid',
            'GIT_COMMITTER_NAME' => 'CW test', 'GIT_COMMITTER_EMAIL' => 'test@example.invalid',
        ]);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($p), 'git ' . implode(' ', $args) . ': ' . $err);
        return $out;
    }

    private function write(string $rel, string $content, int $mode): string
    {
        $path = $this->root . '/' . $rel;
        file_put_contents($path, $content);
        chmod($path, $mode);
        return $path;
    }

    private function answer(string $name, string $value): void
    {
        file_put_contents($this->stub . '/answers/' . $name, $value);
    }

    private function calls(): string
    {
        return (string) file_get_contents($this->stub . '/calls.log');
    }

    private function clearCalls(): void
    {
        file_put_contents($this->stub . '/calls.log', '');
    }

    /** @return list<string> what was running at each migration (the php stub's record) */
    private function migrations(): array
    {
        $file = $this->stub . '/migrations.log';
        return is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];
    }

    /** @return list<string> the names in a directory of the tree (none when it does not exist) */
    private function entries(string $rel): array
    {
        $dir = $this->root . '/' . $rel;
        return is_dir($dir) ? array_values(array_diff(scandir($dir) ?: [], ['.', '..'])) : [];
    }

    /** '%a %U:%G' of a path in the tree, as stat(1) prints it. */
    private function stat(string $rel): string
    {
        clearstatcache();
        $path = $this->root . '/' . $rel;
        $user = posix_getpwuid((int) fileowner($path));
        $group = posix_getgrgid((int) filegroup($path));
        return sprintf('%o %s:%s', fileperms($path) & 07777, $user['name'] ?? '?', $group['name'] ?? '?');
    }

    /** @return array{live: string, code: string, cron: ?string, paused: list<string>, fpm: string, sites: list<string>} what runs on the box */
    private function running(): array
    {
        clearstatcache();
        $cron = $this->root . '/etc/cron.d/cw-prod';
        $live = substr((string) readlink($this->root . '/opt/cw-prod'), strlen($this->root));
        return [
            'live' => $live,
            'code' => $this->code($live),
            'cron' => is_file($cron) ? (string) file_get_contents($cron) : null,
            'paused' => array_values(array_filter(['cw-prod.cron.paused', 'cw-https.paused', 'cw-fpm.stopped'],
                fn (string $m): bool => file_exists($this->root . '/etc/cw/' . $m))),
            'fpm' => trim((string) @file_get_contents($this->stub . '/state/fpm')),
            'sites' => array_map(static fn (string $f): string => substr(basename($f), 5), glob($this->stub . '/state/site_*') ?: []),
        ];
    }

    /** @return array{live: string, code: string, cron: ?string, paused: list<string>, fpm: string, sites: list<string>} every writer paused, the old release in place as it was */
    private function paused(): array
    {
        return ['live' => '/opt/cw-prod-releases/' . self::OLD, 'code' => $this->oldCode, 'cron' => null,
            'paused' => ['cw-prod.cron.paused', 'cw-https.paused', 'cw-fpm.stopped'], 'fpm' => 'inactive', 'sites' => []];
    }

    /** @return array{live: string, code: string, cron: ?string, paused: list<string>, fpm: string, sites: list<string>} the new release running with its jobs, the web back */
    private function resumed(): array
    {
        return ['live' => '/opt/cw-prod-releases/' . self::NEW, 'code' => $this->code('/opt/cw-prod-releases/' . self::NEW),
            'cron' => (string) file_get_contents(self::DIR . '/cw-prod.cron'), 'paused' => [], 'fpm' => 'active', 'sites' => ['cw-https']];
    }

    /** A digest of a release: the directory itself and its files (names, modes, owners, contents). */
    private function code(string $rel): string
    {
        return $rel === '' ? '' : sha1((string) json_encode($this->tree(ltrim($rel, '/'))));
    }

    /** @return array<string, string> $rel itself ('') and every path under it => its type, mode, owner and content (or link target) */
    private function tree(string $rel = ''): array
    {
        clearstatcache();
        $top = rtrim($this->root . '/' . $rel, '/');
        $out = [];
        if (!is_dir($top)) {
            return $out;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($top, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ([$top => new \SplFileInfo($top)] + iterator_to_array($it) as $path => $info) {
            $who = $info->isLink() ? '' : sprintf(' %d:%d', $info->getOwner(), $info->getGroup());
            $out[substr((string) $path, strlen($top))] = match (true) {
                $info->isLink() => 'link ' . readlink((string) $path),
                $info->isDir() => sprintf('dir %o', $info->getPerms() & 07777) . $who,
                default => sprintf('file %o', $info->getPerms() & 07777) . $who . ' ' . sha1_file((string) $path),
            };
        }
        ksort($out);
        return $out;
    }

    /** Each step after the one before it, and (the first time a step is named) not before it either: an extra early call fails. */
    private static function assertInOrder(string $text, array $steps): void
    {
        $at = -1;
        $named = [];
        foreach ($steps as $step) {
            $p = strpos($text, $step, $at + 1);
            self::assertNotFalse($p, "'{$step}' after the step before it, in:\n{$text}");
            if (!in_array($step, $named, true)) {
                self::assertSame($p, strpos($text, $step), "'{$step}' first happens only after the step before it, in:\n{$text}");
            }
            $named[] = $step;
            $at = $p;
        }
    }
}
