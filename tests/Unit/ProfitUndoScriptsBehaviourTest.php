<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The two undo scripts of profit step 1 RUN (the Codex check of the fix round, new HIGH 3; final2/FIX_BRIEF_A3.md G2): deploy/production's
 * profit_undo_b.sh (undo B, the emergency switch to the rollback release) and profit_drain_jobs.sh (undo A's step on the box). They keep the
 * box's absolute paths, as they run there (copied to /root at go-live step 1b): each test runs a COPY whose paths /opt/cw-prod-releases,
 * /opt/cw-prod-release, /opt/cw-prod, /etc/cron.d, /etc/cw and /run/lock are rewritten into a temporary tree, with a PATH of stubs first
 * (id, hostname, php, pgrep, kill, systemctl, a2query, a2ensite, apache2ctl, install, sleep, runuser, curl: tests/fixtures/deploy_stubs/bin and
 * .../profit_undo, each recording its call) and links to a short list of plain file and text tools after them, nothing else (bash's own kill
 * is turned off, so the stub is the one run). Each case checks what changed (the cron file, /opt/cw-prod, php-fpm, the public vhost, the
 * markers) and what did not. Needs Linux and bash: the test machine.
 */
final class ProfitUndoScriptsBehaviourTest extends TestCase
{
    private const DIR = __DIR__ . '/../../deploy/production';
    private const STUBS = __DIR__ . '/../fixtures/deploy_stubs/bin';
    private const OWN_STUBS = __DIR__ . '/../fixtures/deploy_stubs/profit_undo';
    private const MIGRATIONS = __DIR__ . '/../../migrations';
    /** The stubs from deploy_stubs/bin the two scripts may meet (the rest are not on their PATH: "not found"). */
    private const SHARED = ['hostname', 'systemctl', 'a2query', 'a2ensite', 'apache2ctl', 'sleep', 'runuser', 'getent'];
    /** The real commands the scripts and the stubs may run (links in $base/sys). */
    private const TOOLS = ['bash', 'env', 'cat', 'cp', 'mv', 'rm', 'mkdir', 'chmod', 'ln', 'readlink', 'dirname', 'basename', 'tr', 'grep', 'sed', 'awk', 'seq',
        'date', 'touch', 'sha256sum', 'flock', 'head', 'tail', 'wc', 'true', 'false', 'stat'];
    /** The box's paths the copies are rewritten from (a path of the box is never run here). */
    private const BOX = ['/opt/cw-prod-releases', '/opt/cw-prod-release', '/opt/cw-prod', '/etc/cron.d', '/etc/cw', '/run/lock'];
    /** What a run must never touch on this machine. */
    private const OUTSIDE = ['/opt/cw-prod', '/opt/cw-prod.next', '/opt/cw-prod-release', '/opt/cw-prod-releases', '/etc/cron.d/cw-prod', '/etc/cw',
        '/run/lock/cw-prod-install.lock', '/root/profit_undo_b.sh', '/root/profit_drain_jobs.sh'];
    /** The release running now (the profit release) and the rollback commit (c40c050's code + 0074 + 0075). */
    private const PROFIT = 'dddddddddddddddddddddddddddddddddddddddd';
    private const ROLLBACK_COMMIT = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
    private const RB = '/opt/cw-prod-releases/rollback-profit';
    private const ROLLBACK_CRON = "# the jobs of the rollback release (c40c050's: no profit job)\n"
        . "* * * * *  root  cd /opt/cw-prod && php bin/expire_reservations.php --db=cw_prod >> /var/log/cw/expire_reservations.log 2>&1\n";
    private const APPLIED = "0074_profit.sql                          applied 2026-11-21 06:00:01\n0075_profit_queue.sql                    applied 2026-11-21 06:00:02";
    /** A job of the profit release (with a few checks left) and a job of CW's own that is no profit job (it never ends while the test runs). */
    private const JOBS = "3 php bin/apply_order_snapshots.php --db=cw_prod\n100000 php bin/expire_reservations.php --db=cw_prod\n";

    private string $base = '';
    private string $root = '';
    private string $stub = '';
    /** @var array<string, bool> */
    private array $outsideBefore = [];
    /** @var resource|null */
    private $lock = null;
    /** The directory a run starts in (null: $base, which anyone can search). */
    private ?string $cwd = null;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_executable('/bin/bash')) {
            self::markTestSkipped('the scripts run in bash on Linux: the test machine runs these');
        }
        foreach ([...self::TOOLS, 'getent'] as $t) {
            if (self::real($t) === null) {
                self::markTestSkipped("{$t} is missing");
            }
        }
        if (posix_getpwnam('www-data') === false) {
            self::markTestSkipped('no user www-data');
        }
        foreach (self::OUTSIDE as $p) {
            $this->outsideBefore[$p] = file_exists($p) || is_link($p);
        }
        $this->base = (string) realpath(sys_get_temp_dir()) . '/cw_profitundo_' . bin2hex(random_bytes(5));
        $this->root = $this->base . '/root';
        $this->stub = $this->base . '/stub';
        foreach (['answers', 'state'] as $d) {
            mkdir("{$this->stub}/{$d}", 0755, true);
        }
        touch($this->stub . '/calls.log');
        foreach (['opt/cw-prod-releases', 'etc/cron.d', 'etc/cw', 'run/lock', 'etc/letsencrypt/live/warehouse.floverfy.com', 'home'] as $d) {
            mkdir("{$this->root}/{$d}", 0755, true);
        }
        mkdir($this->base . '/bin', 0755);
        foreach (self::SHARED as $s) {
            symlink((string) realpath(self::STUBS . '/' . $s), "{$this->base}/bin/{$s}");
        }
        foreach (array_diff(scandir(self::OWN_STUBS) ?: [], ['.', '..']) as $s) {
            symlink((string) realpath(self::OWN_STUBS . '/' . $s), "{$this->base}/bin/{$s}");
        }
        mkdir($this->base . '/sys', 0755);
        foreach (self::TOOLS as $t) {
            symlink((string) self::real($t), "{$this->base}/sys/{$t}");
        }
        // The real getent for the runuser stub's www-data (a directory only the stubs look in: CW_STUB_SYSPATH).
        mkdir($this->base . '/private', 0755);
        symlink((string) self::real('getent'), $this->base . '/private/getent');
        file_put_contents($this->base . '/bash_env', "enable -n kill\n");
        $this->box();
    }

    protected function tearDown(): void
    {
        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
        if ($this->base !== '' && str_starts_with($this->base, (string) realpath(sys_get_temp_dir()) . '/cw_profitundo_') && is_dir($this->base)) {
            exec('rm -rf ' . escapeshellarg($this->base));
        }
        foreach ($this->outsideBefore as $p => $was) {
            self::assertSame($was, file_exists($p) || is_link($p), "{$p}: a run reached outside its tree");
        }
        parent::tearDown();
    }

    private static function real(string $tool): ?string
    {
        foreach (['/usr/bin', '/bin', '/usr/sbin', '/sbin'] as $d) {
            if (is_executable("{$d}/{$tool}")) {
                return "{$d}/{$tool}";
            }
        }
        return null;
    }

    /**
     * The production box after go-live: the profit release running (/opt/cw-prod, its cron file with the profit jobs), the rollback release
     * prepared at step 1b (c40c050's files + the repository's own 0074 and 0075), php-fpm and the public vhost on, the installer's lock file
     * there, a profit job running; for the drain, the release copy of the rollback commit pushed.
     */
    private function box(): void
    {
        $profitCron = (string) file_get_contents(self::DIR . '/cw-prod.cron');
        self::assertStringContainsString('apply_order_snapshots', $profitCron, 'the profit release runs its jobs');
        $this->release('opt/cw-prod-releases/' . self::PROFIT, $profitCron, self::PROFIT);
        $this->release(ltrim(self::RB, '/'), self::ROLLBACK_CRON, self::ROLLBACK_COMMIT);
        foreach (['0074_profit.sql', '0075_profit_queue.sql'] as $m) {
            copy(self::MIGRATIONS . '/' . $m, "{$this->root}" . self::RB . "/migrations/{$m}");
        }
        symlink($this->root . '/opt/cw-prod-releases/' . self::PROFIT, $this->root . '/opt/cw-prod');
        file_put_contents($this->root . '/etc/cron.d/cw-prod', $profitCron);
        touch($this->root . '/run/lock/cw-prod-install.lock');
        file_put_contents($this->root . '/etc/letsencrypt/live/warehouse.floverfy.com/fullchain.pem', "certificate\n");
        file_put_contents($this->stub . '/state/fpm', "active\n");
        touch($this->stub . '/state/site_cw-https');
        // undo A's release copy (push_code.sh of the rollback commit): its REVISION and its cron file without the profit jobs.
        mkdir($this->root . '/opt/cw-prod-release/deploy/production', 0755, true);
        file_put_contents($this->root . '/opt/cw-prod-release/REVISION', self::ROLLBACK_COMMIT . "\n");
        file_put_contents($this->root . '/opt/cw-prod-release/deploy/production/cw-prod.cron', self::ROLLBACK_CRON);
        // ... and its installer (a stand-in: it records its call and whether the install lock was free when it started).
        // (answers/push_in_handoff: a push that took the lock as the drain let it go and replaced the release copy first: it installs that.)
        file_put_contents($this->root . '/opt/cw-prod-release/deploy/production/install_cron.sh', "#!/usr/bin/env bash\n"
            . 'if flock -n "$CW_DEPLOY_ROOT/run/lock/cw-prod-install.lock" true; then l=free; else l=held; fi' . "\n"
            . 'if [[ -f $CW_STUB_DIR/answers/push_in_handoff ]]; then cp "$CW_STUB_DIR/answers/push_in_handoff" "$CW_DEPLOY_ROOT/opt/cw-prod-release/REVISION"; fi' . "\n"
            . 'rev=$(tr -cd 0-9a-f < "$CW_DEPLOY_ROOT/opt/cw-prod-release/REVISION")' . "\n"
            . 'echo "install_cron.sh of $rev $* (the install lock $l)" >> "$CW_STUB_DIR/calls.log"' . "\n"
            . 'code=$(cat "$CW_STUB_DIR/answers/install_exit" 2>/dev/null || echo 0)' . "\n"
            . 'if [[ $code -eq 0 ]]; then mkdir -p "$CW_DEPLOY_ROOT/opt/cw-prod-releases/$rev"; ln -sfn "$CW_DEPLOY_ROOT/opt/cw-prod-releases/$rev" "$CW_DEPLOY_ROOT/opt/cw-prod"; fi' . "\n"
            . 'exit "$code"' . "\n");
        $this->answer('migrate_status', self::APPLIED);
        $this->answer('jobs', self::JOBS);
    }

    private function release(string $rel, string $cron, string $rev): void
    {
        $dir = $this->root . '/' . $rel;
        foreach (['bin', 'vendor', 'public', 'deploy/production', 'migrations'] as $d) {
            mkdir("{$dir}/{$d}", 0755, true);
        }
        foreach (['bin/migrate.php', 'bin/expire_reservations.php', 'vendor/autoload.php', 'public/index.php'] as $f) {
            file_put_contents("{$dir}/{$f}", "<?php // {$f} of {$rev}\n");
        }
        file_put_contents("{$dir}/deploy/production/cw-prod.cron", $cron);
        file_put_contents("{$dir}/REVISION", $rev . "\n");
    }

    private function answer(string $name, string $value): void
    {
        file_put_contents($this->stub . '/answers/' . $name, $value);
    }

    /** The script as shipped, its box paths rewritten into the tree (a script naming another path of the box is not run). */
    private function copy(string $script): string
    {
        $src = (string) file_get_contents(self::DIR . '/' . $script);
        self::assertStringStartsWith("#!/usr/bin/env bash\n", $src);
        $map = [];
        foreach (self::BOX as $p) {
            $map[$p] = $this->root . $p;
        }
        $copy = strtr($src, $map);
        foreach (explode("\n", $copy) as $n => $line) {
            if (str_starts_with(ltrim($line), '#')) {
                continue;
            }
            $rest = str_replace([$this->root, '/dev/null'], ['TREE', ''], $line);
            self::assertDoesNotMatchRegularExpression('#(^|[\s"\'=:(<>|;&])/[A-Za-z]#', $rest, "{$script} line " . ($n + 1) . " names a path outside the tree: {$line}");
        }
        $file = $this->base . '/' . $script;
        file_put_contents($file, $copy);
        return $file;
    }

    /** @return array{0: int, 1: string, 2: string} exit code, stdout, stderr */
    private function runScript(string $script, string ...$args): array
    {
        $file = $this->copy($script);
        $out = $this->base . '/stdout';
        $err = $this->base . '/stderr';
        $p = proc_open(['/bin/bash', $file, ...$args], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, $this->cwd ?? $this->base, [
            'PATH' => $this->base . '/bin:' . $this->base . '/sys',
            'HOME' => $this->root . '/home',
            'LC_ALL' => 'C',
            'BASH_ENV' => $this->base . '/bash_env',
            'CW_DEPLOY_ROOT' => $this->root,
            'CW_STUB_DIR' => $this->stub,
            'CW_STUB_SYSPATH' => $this->base . '/private',
        ]);
        self::assertIsResource($p);
        $code = proc_close($p);
        return [$code, (string) file_get_contents($out), (string) file_get_contents($err)];
    }

    private function calls(): string
    {
        return (string) file_get_contents($this->stub . '/calls.log');
    }

    /** @return array{live: string, cron: ?string, markers: list<string>, fpm: string, sites: list<string>, tree: string} what runs on the box */
    private function state(): array
    {
        clearstatcache();
        $cron = $this->root . '/etc/cron.d/cw-prod';
        $markers = [];
        foreach (scandir($this->root . '/etc/cw') ?: [] as $f) {
            if ($f[0] !== '.') {
                $markers[] = preg_replace('/^cw-prod\.cron\.replaced-\d{8}T\d{6}Z$/', 'cw-prod.cron.replaced-<time>', $f);
            }
        }
        $sites = array_map(static fn (string $f): string => substr(basename($f), 5), glob($this->stub . '/state/site_*') ?: []);
        return [
            'live' => substr((string) readlink($this->root . '/opt/cw-prod'), strlen($this->root)),
            'cron' => is_file($cron) ? (string) file_get_contents($cron) : null,
            'markers' => $markers,
            'fpm' => trim((string) @file_get_contents($this->stub . '/state/fpm')),
            'sites' => $sites,
            'tree' => self::digest($this->root),
        ];
    }

    /** Every path of the tree with its type, mode and content (or link target). */
    private static function digest(string $top): string
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($top, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $path => $info) {
            $out[substr((string) $path, strlen($top))] = match (true) {
                $info->isLink() => 'link ' . readlink((string) $path),
                $info->isDir() => sprintf('dir %o', $info->getPerms() & 07777),
                default => sprintf('file %o ', $info->getPerms() & 07777) . sha1_file((string) $path),
            };
        }
        ksort($out);
        return sha1((string) json_encode($out));
    }

    /** Nothing that runs was touched: the same tree and services, and no command that changes them was called. */
    private function assertNothingChanged(array $before, string $what): void
    {
        self::assertSame($before, $this->state(), "{$what}: nothing changed");
        foreach (['install ', 'systemctl ', 'a2ensite', 'apache2ctl', 'kill ', 'pgrep ', 'expire_reservations'] as $c) {
            self::assertStringNotContainsString($c, $this->calls(), "{$what}: {$c} never ran");
        }
    }

    /** Each step after the one before it. @param list<string> $steps */
    private static function assertInOrder(string $text, array $steps): void
    {
        $at = -1;
        foreach ($steps as $step) {
            $p = strpos($text, $step, $at + 1);
            self::assertNotFalse($p, "'{$step}' after the step before it, in:\n{$text}");
            $at = $p;
        }
    }

    private function profitRunning(): array
    {
        return ['live' => '/opt/cw-prod-releases/' . self::PROFIT, 'cron' => (string) file_get_contents(self::DIR . '/cw-prod.cron'), 'markers' => [],
            'fpm' => 'active', 'sites' => ['cw-https']];
    }

    private static function withoutTree(array $state): array
    {
        unset($state['tree']);
        return $state;
    }

    /**
     * Undo B stops, naming why, before anything that runs is changed: not root, not the box, a rollback file missing, a migration file not
     * the one rehearsed, the installer's lock held, the rollback release cannot read the schema, a migration pending.
     */
    public function testUndoBStopsBeforeAnythingThatRunsIsChanged(): void
    {
        $cases = [
            'not root' => [fn () => $this->answer('uid', '1000'), 'run as root on cw-prod-lon1-01'],
            'another box' => [fn () => $this->answer('hostname', 'cw-test'), 'run as root on cw-prod-lon1-01'],
            'a rollback file missing' => [fn () => unlink($this->root . self::RB . '/bin/expire_reservations.php'), self::RB . '/bin/expire_reservations.php is missing'],
            'its vendor missing' => [fn () => unlink($this->root . self::RB . '/vendor/autoload.php'), self::RB . '/vendor/autoload.php is missing'],
            'a migration file not rehearsed' => [fn () => file_put_contents($this->root . self::RB . '/migrations/0075_profit_queue.sql', "-- changed\n", FILE_APPEND),
                "the rollback release's 0074 / 0075 are not the files rehearsed"],
            'the installer\'s lock held' => [function (): void {
                $this->lock = fopen($this->root . '/run/lock/cw-prod-install.lock', 'c') ?: null;
                self::assertNotNull($this->lock);
                self::assertTrue(flock($this->lock, LOCK_EX | LOCK_NB));
            }, 'an install (or a push) holds /run/lock/cw-prod-install.lock'],
            'migrate --status failing' => [fn () => $this->answer('migrate_status_exit', '1'), 'the rollback release could not read the schema'],
            'a migration pending' => [fn () => $this->answer('migrate_status', "0074_profit.sql                          applied 2026-11-21 06:00:01\n"
                . '0075_profit_queue.sql                    PENDING'), 'a migration is pending: recover step 1 first'],
        ];
        foreach ($cases as $what => [$arrange, $why]) {
            $this->tearDownLock();
            exec('rm -rf ' . escapeshellarg($this->root) . ' ' . escapeshellarg($this->stub));
            foreach (['answers', 'state'] as $d) {
                mkdir("{$this->stub}/{$d}", 0755, true);
            }
            touch($this->stub . '/calls.log');
            foreach (['opt/cw-prod-releases', 'etc/cron.d', 'etc/cw', 'run/lock', 'etc/letsencrypt/live/warehouse.floverfy.com', 'home'] as $d) {
                mkdir("{$this->root}/{$d}", 0755, true);
            }
            $this->box();
            $arrange();
            $before = $this->state();
            [$code, $out, $err] = $this->runScript('profit_undo_b.sh');
            self::assertSame(1, $code, "{$what}: {$out}{$err}");
            self::assertStringContainsString("UNDO B STOPPED: {$why}", str_replace($this->root, '', $err), $what);
            self::assertStringNotContainsString('UNDO B DONE', $out, $what);
            $this->assertNothingChanged($before, $what);
            self::assertSame($this->profitRunning(), self::withoutTree($before), "{$what}: the profit release still runs as it did");
        }
    }

    private function tearDownLock(): void
    {
        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    /**
     * Undo B, the normal case: the rollback's cron file first (no profit job starts), the running profit job waited for (CW's own job, no
     * profit job, never), /opt/cw-prod switched to the rollback release, php-fpm reloaded (it runs), the vhost left on, the expiry run from
     * the rollback release, DONE.
     */
    public function testUndoBSwitchesToTheRollbackRelease(): void
    {
        [$code, $out, $err] = $this->runScript('profit_undo_b.sh');
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('UNDO B DONE', $out);
        $calls = $this->calls();
        self::assertSame(['live' => self::RB, 'cron' => self::ROLLBACK_CRON, 'markers' => [], 'fpm' => 'active', 'sites' => ['cw-https']], self::withoutTree($this->state()));
        self::assertInOrder($calls, ['id -u', 'php ' . self::RB . '/bin/migrate.php --db=cw_prod --status', 'install -m 0644 -o root -g root ' . self::RB
            . '/deploy/production/cw-prod.cron /etc/cron.d/cw-prod', 'pgrep -f', 'systemctl reload php8.3-fpm', 'a2query -s cw-https',
            'php bin/expire_reservations.php --db=cw_prod (release ' . self::RB . ')']);
        self::assertStringNotContainsString('kill ', $calls, 'the job ended within the wait: nothing TERMed');
        self::assertStringNotContainsString('a2ensite', $calls, 'the vhost was on');
        self::assertStringNotContainsString('systemctl start', $calls, 'php-fpm ran');
        self::assertStringContainsString("0 php bin/apply_order_snapshots.php --db=cw_prod\n", (string) file_get_contents($this->stub . '/answers/jobs'), 'waited for');
        self::assertFileDoesNotExist($this->root . '/opt/cw-prod.next');
        self::assertFileExists($this->root . '/opt/cw-prod-releases/' . self::PROFIT . '/bin/migrate.php', 'the profit release is kept');
    }

    /** Undo B: a profit job (any --db, or none) still running after the wait is TERMed, checked gone, then the switch. CW's own job never. */
    public function testUndoBTermsAProfitJobLeftAfterTheWait(): void
    {
        $this->answer('jobs', "100000 php bin/expire_reservations.php --db=cw_prod\n100000 php bin/refresh_item_cost.php\n");
        [$code, $out, $err] = $this->runScript('profit_undo_b.sh');
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('UNDO B DONE', $out);
        self::assertStringContainsString('4243 php bin/refresh_item_cost.php', $out, 'the job left is named');
        $calls = $this->calls();
        self::assertSame(1, substr_count($calls, 'kill '), $calls);
        self::assertInOrder($calls, ['install ', 'kill -TERM 4243', 'pgrep -af', 'systemctl reload php8.3-fpm', 'php bin/expire_reservations.php']);
        self::assertSame(self::RB, $this->state()['live']);
    }

    /** Undo B: a profit job that ends just as the wait ends (gone between the check and the listing) is no failure: the undo goes on, DONE. */
    public function testUndoBGoesOnWhenTheLastJobEndsAsTheWaitEnds(): void
    {
        $this->answer('jobs', "361 php bin/apply_order_snapshots.php --db=cw_prod\n");
        [$code, $out, $err] = $this->runScript('profit_undo_b.sh');
        self::assertSame(0, $code, "never a silent stop half way (the rollback cron in, the link not switched): {$out}{$err}");
        self::assertStringContainsString('UNDO B DONE', $out);
        self::assertSame(self::RB, $this->state()['live']);
    }

    /** Undo B: a profit job that ignores TERM is listed and the undo stops: the link NOT switched, php-fpm and the vhost untouched. */
    public function testUndoBStopsWhenAJobIgnoresTerm(): void
    {
        $this->answer('jobs', "100000 php bin/apply_order_snapshots.php --db=cw_prod\n");
        $this->answer('ignore_term', '');
        [$code, $out, $err] = $this->runScript('profit_undo_b.sh');
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString('UNDO B STOPPED: profit jobs still running after TERM', $err);
        self::assertStringContainsString('4242 php bin/apply_order_snapshots.php --db=cw_prod', $out, 'the job is named');
        self::assertSame(['live' => '/opt/cw-prod-releases/' . self::PROFIT, 'cron' => self::ROLLBACK_CRON, 'markers' => [], 'fpm' => 'active', 'sites' => ['cw-https']],
            self::withoutTree($this->state()), 'the rollback cron is in (no profit job starts), nothing else changed');
        $calls = $this->calls();
        self::assertStringContainsString('kill -TERM 4242', $calls);
        foreach (['systemctl ', 'a2ensite', 'apache2ctl', 'expire_reservations'] as $c) {
            self::assertStringNotContainsString($c, $calls, $c);
        }
    }

    /**
     * Undo B after a stopped install's markers (nothing pending: step 1 recovered as far as the schema): php-fpm started, the vhost enabled
     * (Apache's configuration checked, reloaded), the markers taken away, the paused cron file moved aside; the rollback's cron file in.
     */
    public function testUndoBBringsTheWebBackWhateverAStoppedInstallLeft(): void
    {
        rename($this->root . '/etc/cron.d/cw-prod', $this->root . '/etc/cw/cw-prod.cron.paused');
        touch($this->root . '/etc/cw/cw-https.paused');
        touch($this->root . '/etc/cw/cw-fpm.stopped');
        file_put_contents($this->stub . '/state/fpm', "inactive\n");
        unlink($this->stub . '/state/site_cw-https');
        [$code, $out, $err] = $this->runScript('profit_undo_b.sh');
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('UNDO B DONE', $out);
        self::assertSame(['live' => self::RB, 'cron' => self::ROLLBACK_CRON, 'markers' => ['cw-prod.cron.replaced-<time>'], 'fpm' => 'active', 'sites' => ['cw-https']],
            self::withoutTree($this->state()));
        self::assertInOrder($this->calls(), ['install ', 'systemctl start php8.3-fpm', 'a2ensite -q cw-https', 'apache2ctl configtest', 'systemctl reload apache2',
            'systemctl is-active --quiet php8.3-fpm', 'a2query -s cw-https', 'php bin/expire_reservations.php']);
    }

    /** The drain stops, naming why, before anything changes: not root, no full commit id, the release copy of another commit, a cron file with profit jobs, a pause marker. */
    public function testTheDrainStopsBeforeAnythingChanges(): void
    {
        $other = 'ffffffffffffffffffffffffffffffffffffffff';
        $cases = [
            'not root' => [fn () => $this->answer('uid', '1000'), self::ROLLBACK_COMMIT, 'run as root on cw-prod-lon1-01'],
            'no full commit id' => [static fn () => null, 'eeeeeee', "give the rollback commit's full id"],
            'the release copy of another commit' => [static fn () => null, $other, "/opt/cw-prod-release is not the rollback commit {$other}"],
            'a cron file with profit jobs' => [fn () => copy(self::DIR . '/cw-prod.cron', $this->root . '/opt/cw-prod-release/deploy/production/cw-prod.cron'),
                self::ROLLBACK_COMMIT, "/opt/cw-prod-release's cron file has profit jobs"],
            'the paused cron marker' => [fn () => touch($this->root . '/etc/cw/cw-prod.cron.paused'), self::ROLLBACK_COMMIT, '/etc/cw/cw-prod.cron.paused is there'],
            'the paused vhost marker' => [fn () => touch($this->root . '/etc/cw/cw-https.paused'), self::ROLLBACK_COMMIT, '/etc/cw/cw-https.paused is there'],
            'the stopped php-fpm marker' => [fn () => touch($this->root . '/etc/cw/cw-fpm.stopped'), self::ROLLBACK_COMMIT, '/etc/cw/cw-fpm.stopped is there'],
        ];
        foreach ($cases as $what => [$arrange, $commit, $why]) {
            exec('rm -rf ' . escapeshellarg($this->root) . ' ' . escapeshellarg($this->stub));
            foreach (['answers', 'state'] as $d) {
                mkdir("{$this->stub}/{$d}", 0755, true);
            }
            touch($this->stub . '/calls.log');
            foreach (['opt/cw-prod-releases', 'etc/cron.d', 'etc/cw', 'run/lock', 'etc/letsencrypt/live/warehouse.floverfy.com', 'home'] as $d) {
                mkdir("{$this->root}/{$d}", 0755, true);
            }
            $this->box();
            $arrange();
            $before = $this->state();
            [$code, $out, $err] = $this->runScript('profit_drain_jobs.sh', $commit);
            self::assertSame(1, $code, "{$what}: {$out}{$err}");
            self::assertStringContainsString("DRAIN STOPPED: {$why}", str_replace($this->root, '', $err), $what);
            self::assertStringNotContainsString('DRAINED', $out, $what);
            $this->assertNothingChanged($before, $what);
        }
    }

    /** The drain, the normal case: the rollback's cron file first, the profit job waited for, DRAINED; nothing else changes (the installer's work). */
    public function testTheDrainInstallsTheRollbackCronAndWaitsForTheJobs(): void
    {
        $before = $this->state();
        [$code, $out, $err] = $this->runScript('profit_drain_jobs.sh', self::ROLLBACK_COMMIT);
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('DRAINED', $out);
        self::assertSame(array_replace(self::withoutTree($before), ['cron' => self::ROLLBACK_CRON, 'live' => '/opt/cw-prod-releases/' . self::ROLLBACK_COMMIT]),
            self::withoutTree($this->state()), 'the cron file, and the release the installer switched to (PR154)');
        self::assertInOrder($this->calls(), ['id -u', 'install -m 0644 -o root -g root /opt/cw-prod-release/deploy/production/cw-prod.cron /etc/cron.d/cw-prod', 'pgrep -f']);
        self::assertStringNotContainsString('kill ', $this->calls());
        self::assertStringContainsString("0 php bin/apply_order_snapshots.php --db=cw_prod\n", (string) file_get_contents($this->stub . '/answers/jobs'), 'waited for');
    }

    /** The drain: the last job ending as the wait ends is no failure (DRAINED); a job that ignores TERM is named and the drain stops. */
    public function testTheDrainAtTheEndOfItsWait(): void
    {
        $this->answer('jobs', "361 php bin/recost_sales.php --db=cw_prod\n");
        [$code, $out, $err] = $this->runScript('profit_drain_jobs.sh', self::ROLLBACK_COMMIT);
        self::assertSame(0, $code, "never a silent stop: {$out}{$err}");
        self::assertStringContainsString('DRAINED', $out);
        file_put_contents($this->stub . '/calls.log', '');
        $this->answer('jobs', "100000 php bin/rebuild_profit_days.php --db=cw_prod --apply\n");
        $this->answer('ignore_term', '');
        [$code, $out, $err] = $this->runScript('profit_drain_jobs.sh', self::ROLLBACK_COMMIT);
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString('DRAIN STOPPED: profit jobs still running after TERM', $err);
        self::assertStringContainsString('4242 php bin/rebuild_profit_days.php --db=cw_prod --apply', $out);
        self::assertStringContainsString('kill -TERM 4242', $this->calls());
        self::assertSame(self::ROLLBACK_CRON, $this->state()['cron'], 'the rollback cron is in: no profit job starts');
    }

    // ---- the third final review (final2/FIX_BRIEF_A4.md H7, H8; a-HIGH4, c-M8, Fable L3; a-M, b-M6, Fable L1) -----------------------------

    private function apache(): string
    {
        return trim((string) @file_get_contents($this->stub . '/state/apache')) ?: 'active';
    }

    /**
     * H7: Apache stopped while the public vhost is enabled on disk and no marker says so (a-HIGH4, c-M8): undo B checks Apache's configuration
     * and starts it before it says DONE (it said DONE with Apache stopped: every shop's stock call still failing); then the installer's smoke
     * run, every job of it, before DONE.
     */
    public function testUndoBStartsApacheWhenItIsNotRunning(): void
    {
        file_put_contents($this->stub . '/state/apache', "inactive\n");
        [$code, $out, $err] = $this->runScript('profit_undo_b.sh');
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('UNDO B DONE', $out);
        self::assertSame('active', $this->apache(), 'Apache runs');
        $calls = $this->calls();
        self::assertInOrder($calls, ['pgrep -f', 'systemctl reload php8.3-fpm', 'apache2ctl configtest', 'systemctl is-active --quiet apache2', 'systemctl start apache2',
            'systemctl is-active --quiet apache2', 'a2query -s cw-https']);
        self::assertStringNotContainsString('a2ensite', $calls, 'the vhost was enabled on disk');
        $smoke = [];
        foreach (['expire_reservations.php', 'prune_changes.php --dry-run', 'invariants.php', 'check_arrivals.php', 'apply_store_choices.php', 'refresh_reports.php --status',
            'health_alert.php --no-send', 'housekeeping.php --dry-run'] as $job) {
            $smoke[] = 'php bin/' . $job . ' --db=cw_prod (release ' . self::RB . ')';
        }
        self::assertInOrder($calls, ['systemctl start apache2', ...$smoke]);
        self::assertStringContainsString('smoke: bin/housekeeping.php --dry-run -> exit 0', $out);
    }

    /** H7: a rollback release the web server cannot read (a file of it root's only) stops undo B BEFORE the switch: nothing that runs changes. */
    public function testUndoBStopsBeforeTheSwitchWhenTheWebServerCannotReadTheRollback(): void
    {
        chmod($this->root . self::RB . '/public/index.php', 0600);
        $before = $this->state();
        [$code, $out, $err] = $this->runScript('profit_undo_b.sh');
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString('UNDO B STOPPED: www-data cannot read ' . self::RB, str_replace($this->root, '', $err));
        self::assertStringNotContainsString('UNDO B DONE', $out);
        self::assertStringNotContainsString('the link is already switched', $err);
        $this->assertNothingChanged($before, 'www-data cannot read the rollback');
        self::assertStringContainsString('runuser -u www-data -- test -r ' . self::RB . '/public/index.php -a -r ' . self::RB . '/vendor/autoload.php', $this->calls());
    }

    /**
     * H7: a failure after the switch says so and is safe to run again: Apache's configuration that does not load (its certificate gone), Apache
     * that does not start, php-fpm that does not start (a bare tool error before: now the STOPPED line through the trap), a smoke job that fails;
     * each "UNDO B STOPPED: ... (the link is already switched; run this again)", no DONE; once the cause is put right, the same script again: DONE.
     */
    public function testUndoBAfterTheSwitchSaysTheLinkIsSwitchedAndRunsAgain(): void
    {
        $cases = [
            'the configuration does not load' => [function (): void {
                file_put_contents($this->stub . '/state/apache', "inactive\n");
                unlink($this->root . '/etc/letsencrypt/live/warehouse.floverfy.com/fullchain.pem');
            }, "UNDO B STOPPED: Apache's configuration does not load", function (): void {
                file_put_contents($this->root . '/etc/letsencrypt/live/warehouse.floverfy.com/fullchain.pem', "certificate\n");
            }],
            'Apache does not start' => [function (): void {
                file_put_contents($this->stub . '/state/apache', "inactive\n");
                $this->answer('apache_start_exit', '1');
            }, 'UNDO B STOPPED: Apache does not start', fn () => unlink($this->stub . '/answers/apache_start_exit')],
            'php-fpm does not start' => [function (): void {
                file_put_contents($this->stub . '/state/fpm', "inactive\n");
                $this->answer('fpm_start_exit', '1');
            }, 'UNDO B STOPPED: "systemctl start php8.3-fpm" failed (exit 1)', fn () => unlink($this->stub . '/answers/fpm_start_exit')],
            'a smoke job fails' => [fn () => $this->answer('invariants.php.exit', '1'), 'UNDO B STOPPED: a smoke job failed on the rollback release',
                fn () => unlink($this->stub . '/answers/invariants.php.exit')],
        ];
        foreach ($cases as $what => [$arrange, $why, $repair]) {
            exec('rm -rf ' . escapeshellarg($this->root) . ' ' . escapeshellarg($this->stub));
            foreach (['answers', 'state'] as $d) {
                mkdir("{$this->stub}/{$d}", 0755, true);
            }
            touch($this->stub . '/calls.log');
            foreach (['opt/cw-prod-releases', 'etc/cron.d', 'etc/cw', 'run/lock', 'etc/letsencrypt/live/warehouse.floverfy.com', 'home'] as $d) {
                mkdir("{$this->root}/{$d}", 0755, true);
            }
            $this->box();
            $arrange();
            [$code, $out, $err] = $this->runScript('profit_undo_b.sh');
            self::assertSame(1, $code, "{$what}: {$out}{$err}");
            self::assertStringContainsString($why, $err, $what);
            self::assertStringContainsString('(the link is already switched; run this again)', $err, $what);
            self::assertStringNotContainsString('UNDO B DONE', $out, $what);
            self::assertSame(self::RB, $this->state()['live'], "{$what}: the link is switched");
            $repair();
            [$code, $out, $err] = $this->runScript('profit_undo_b.sh');
            self::assertSame(0, $code, "{$what}, run again: {$out}{$err}");
            self::assertStringContainsString('UNDO B DONE', $out, $what);
            self::assertSame(['live' => self::RB, 'cron' => self::ROLLBACK_CRON, 'fpm' => 'active', 'sites' => ['cw-https']],
                array_intersect_key(self::withoutTree($this->state()), array_flip(['live', 'cron', 'fpm', 'sites'])), $what);
            self::assertSame('active', $this->apache(), $what);
        }
    }

    /**
     * H8: the drain holds the installer's lock for its checks and the drain, checks REVISION again at the end under it, lets it go and runs the
     * rollback release's installer itself at once (without --migrate): the installer finds the lock free and is of the commit checked.
     */
    public function testTheDrainRunsTheRollbacksInstallerAtOnceWithTheLockLetGo(): void
    {
        [$code, $out, $err] = $this->runScript('profit_drain_jobs.sh', self::ROLLBACK_COMMIT);
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('DRAINED AND INSTALLED: ' . self::ROLLBACK_COMMIT, $out);
        $calls = $this->calls();
        self::assertInOrder($calls, ['id -u', 'install -m 0644 -o root -g root /opt/cw-prod-release/deploy/production/cw-prod.cron /etc/cron.d/cw-prod', 'pgrep -f',
            'install_cron.sh of ' . self::ROLLBACK_COMMIT . '  (the install lock free)']);
        self::assertSame(1, substr_count($calls, 'install_cron.sh of '), 'run once, without --migrate');
        // The installer failing: the drain says so (its exit), never DRAINED AND INSTALLED.
        file_put_contents($this->stub . '/calls.log', '');
        $this->answer('install_exit', '3');
        [$code, $out, $err] = $this->runScript('profit_drain_jobs.sh', self::ROLLBACK_COMMIT);
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString('DRAIN STOPPED: the installer of ' . self::ROLLBACK_COMMIT . ' ended with exit 3', $err);
        self::assertStringNotContainsString('DRAINED AND INSTALLED', $out);
    }

    /** H8: a push that lands while the drain waits (the release copy now another commit): the drain stops at its check under the lock, the installer never runs. */
    public function testTheDrainStopsWhenTheReleaseCopyChangesDuringTheWait(): void
    {
        $other = 'ffffffffffffffffffffffffffffffffffffffff';
        $this->answer('push_during_wait', $other . "\n");
        [$code, $out, $err] = $this->runScript('profit_drain_jobs.sh', self::ROLLBACK_COMMIT);
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString('DRAIN STOPPED: /opt/cw-prod-release changed during the drain', str_replace($this->root, '', $err));
        self::assertStringNotContainsString('install_cron.sh of', $this->calls(), 'the installer never ran');
        self::assertStringNotContainsString('DRAINED', $out);
    }

    /** H8: the installer's lock held (an install or a push running): the drain stops before anything changes; the installer never runs. */
    public function testTheDrainStopsWhenTheInstallLockIsHeld(): void
    {
        $this->lock = fopen($this->root . '/run/lock/cw-prod-install.lock', 'c') ?: null;
        self::assertNotNull($this->lock);
        self::assertTrue(flock($this->lock, LOCK_EX | LOCK_NB));
        $before = $this->state();
        [$code, $out, $err] = $this->runScript('profit_drain_jobs.sh', self::ROLLBACK_COMMIT);
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString('DRAIN STOPPED: an install (or a push) holds /run/lock/cw-prod-install.lock', str_replace($this->root, '', $err));
        $this->assertNothingChanged($before, 'the lock held');
        self::assertStringNotContainsString('install_cron.sh of', $this->calls());
    }

    // ---- the Codex check of this fix round (r13, build/A/codex_r13_a.txt), shown failing first on 9c15538 ---------------------------------

    /**
     * r13 a (HIGH): Apache running, the vhost off, its configuration failing (no certificate): undo B enables the vhost on disk and stops; once
     * the cause is put right, the same script again checks the configuration and reloads Apache (it found the vhost enabled on disk and
     * skipped the reload: DONE with the vhost not loaded).
     */
    public function testUndoBRunAgainAfterAFailedConfigtestReloadsApache(): void
    {
        unlink($this->stub . '/state/site_cw-https');
        unlink($this->root . '/etc/letsencrypt/live/warehouse.floverfy.com/fullchain.pem');
        [$code, $out, $err] = $this->runScript('profit_undo_b.sh');
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString("UNDO B STOPPED: Apache's configuration does not load", $err);
        file_put_contents($this->root . '/etc/letsencrypt/live/warehouse.floverfy.com/fullchain.pem', "certificate\n");
        file_put_contents($this->stub . '/calls.log', '');
        [$code, $out, $err] = $this->runScript('profit_undo_b.sh');
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('UNDO B DONE', $out);
        self::assertInOrder($this->calls(), ['apache2ctl configtest', 'systemctl is-active --quiet apache2', 'systemctl reload apache2', 'php bin/expire_reservations.php']);
    }

    /** Found with r13: a smoke job that may say exit 1 (health_alert, as the installer reads it) is no stop and prints no STOPPED line. */
    public function testUndoBSmokeHealthAlertExitOneIsNoStop(): void
    {
        $this->answer('health_alert.php.exit', '1');
        [$code, $out, $err] = $this->runScript('profit_undo_b.sh');
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('UNDO B DONE', $out);
        self::assertStringContainsString('smoke: bin/health_alert.php --no-send -> exit 1', $out);
        self::assertStringNotContainsString('STOPPED', $err);
    }

    /**
     * r13 a (MEDIUM): a push waiting for the lock takes it as the drain lets it go and replaces the release copy before the installer starts:
     * the installer installs that other commit; the drain says so (it said DRAINED AND INSTALLED with the rollback's id).
     */
    public function testTheDrainChecksTheReleaseTheInstallerSwitchedTo(): void
    {
        $other = 'ffffffffffffffffffffffffffffffffffffffff';
        $this->answer('push_in_handoff', $other . "\n");
        [$code, $out, $err] = $this->runScript('profit_drain_jobs.sh', self::ROLLBACK_COMMIT);
        self::assertSame(1, $code, $out . $err);
        self::assertStringNotContainsString('DRAINED AND INSTALLED', $out);
        self::assertStringContainsString('DRAIN STOPPED: /opt/cw-prod runs /opt/cw-prod-releases/' . $other . ', not the rollback commit', str_replace($this->root, '', $err));
    }

    // ---- the final re-check of the fix round (build/A/codex_final4_astra_p.txt, p-HIGH "undo B web readiness"; FIX_BRIEF_A5.md F2), shown failing first on 5e1fe37

    /** A fresh box, as setUp() leaves it (for a test of several cases). */
    private function freshBox(): void
    {
        $this->tearDownLock();
        exec('rm -rf ' . escapeshellarg($this->root) . ' ' . escapeshellarg($this->stub));
        foreach (['answers', 'state'] as $d) {
            mkdir("{$this->stub}/{$d}", 0755, true);
        }
        touch($this->stub . '/calls.log');
        foreach (['opt/cw-prod-releases', 'etc/cron.d', 'etc/cw', 'run/lock', 'etc/letsencrypt/live/warehouse.floverfy.com', 'home'] as $d) {
            mkdir("{$this->root}/{$d}", 0755, true);
        }
        $this->box();
    }

    /** The API's and the sign-in page's probes as undo B runs them (the local listener, as enable_https.sh checks them; no curlrc, no proxy). */
    private const PROBE = 'curl -q -s -o /dev/null -w %{http_code} --noproxy * --max-time 3 --resolve warehouse.floverfy.com:443:127.0.0.1 https://warehouse.floverfy.com/v1/health';
    private const PROBE_UI = 'curl -q -s -o /dev/null -w %{http_code} --noproxy * --max-time 3 --resolve warehouse.floverfy.com:443:127.0.0.1 https://warehouse.floverfy.com/ui/login';

    /**
     * F2 (a): ANY file or directory of the rollback release that www-data cannot read (not only public/index.php and vendor/autoload.php:
     * a root-only src/Api/Kernel.php passed both checks and the root smoke run, then every shop's call was a 500) stops undo B BEFORE the
     * switch, naming the first such path: the link, the cron file, php-fpm, Apache and the vhost untouched.
     */
    public function testUndoBStopsBeforeTheSwitchWhenWwwDataCannotReadAnyPartOfTheRollback(): void
    {
        $cases = [
            'a root-only file' => [function (): void {
                mkdir($this->root . self::RB . '/src/Api', 0755, true);
                file_put_contents($this->root . self::RB . '/src/Api/Kernel.php', "<?php // the API's kernel\n");
                chmod($this->root . self::RB . '/src/Api/Kernel.php', 0600);
            }, self::RB . '/src/Api/Kernel.php'],
            'a root-only directory' => [function (): void {
                mkdir($this->root . self::RB . '/vendor/composer', 0755, true);
                file_put_contents($this->root . self::RB . '/vendor/composer/autoload_real.php', "<?php\n");
                chmod($this->root . self::RB . '/vendor/composer', 0700);
            }, self::RB . '/vendor/composer'],
            'a directory www-data cannot search' => [function (): void {
                mkdir($this->root . self::RB . '/src', 0755, true);
                file_put_contents($this->root . self::RB . '/src/Clock.php', "<?php\n");
                chmod($this->root . self::RB . '/src', 0744);
            }, ''],
        ];
        foreach ($cases as $what => [$arrange, $named]) {
            $this->freshBox();
            $arrange();
            $before = $this->state();
            [$code, $out, $err] = $this->runScript('profit_undo_b.sh');
            $err = str_replace($this->root, '', $err);
            self::assertSame(1, $code, "{$what}: {$out}{$err}");
            self::assertStringContainsString('UNDO B STOPPED: www-data cannot read ' . $named, $err, $what);
            self::assertStringNotContainsString('UNDO B DONE', $out, $what);
            self::assertStringNotContainsString('the link is already switched', $err, $what);
            $this->assertNothingChanged($before, $what);
            self::assertSame($this->profitRunning(), self::withoutTree($before), "{$what}: the profit release still runs as it did");
            self::assertStringContainsString('runuser -u www-data -- find ' . self::RB . ' ! -readable -print -quit', $this->calls(), $what);
        }
        // A rollback release www-data can read whole: the check passes and the undo goes on.
        $this->freshBox();
        mkdir($this->root . self::RB . '/src/Api', 0755, true);
        file_put_contents($this->root . self::RB . '/src/Api/Kernel.php', "<?php // the API's kernel\n");
        [$code, $out, $err] = $this->runScript('profit_undo_b.sh');
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('UNDO B DONE', $out);
        self::assertInOrder($this->calls(), ['runuser -u www-data -- find ' . self::RB . ' ! -readable -print -quit', 'install -m 0644', 'pgrep -f']);
    }

    /**
     * F2 (b): after the switch and Apache's start or reload, and BEFORE "UNDO B DONE", the API is asked through the local listener (GET
     * /v1/health with no key, as enable_https.sh checks it): 401 = the web server runs the rollback release. A few tries over about 10 s (a
     * reload settling); anything else stops undo B, "the link is already switched; run this again", no DONE; once put right, run again: DONE.
     */
    public function testUndoBAsksTheApiBeforeItSaysDone(): void
    {
        // The normal case: one probe of each, after Apache's reload and the vhost's check, before the smoke run and DONE.
        [$code, $out, $err] = $this->runScript('profit_undo_b.sh');
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('UNDO B DONE', $out);
        $calls = $this->calls();
        self::assertSame([1, 1], [substr_count($calls, '/v1/health'), substr_count($calls, '/ui/login')], $calls);
        self::assertInOrder($calls, ['systemctl reload apache2', 'a2query -s cw-https', self::PROBE, self::PROBE_UI, 'php bin/expire_reservations.php']);
        self::assertStringContainsString('GET https://warehouse.floverfy.com/v1/health (no key) -> HTTP 401 (curl exit 0); '
            . 'GET https://warehouse.floverfy.com/ui/login -> HTTP 200 (curl exit 0)', $out);
        // A reload still settling: 503, no connection, then 401: DONE after the third try.
        $this->freshBox();
        $this->answer('health_codes', "503\n000\n401\n");
        $this->answer('health_exits', "0\n7\n0\n");
        [$code, $out, $err] = $this->runScript('profit_undo_b.sh');
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('UNDO B DONE', $out);
        self::assertSame(3, substr_count($this->calls(), '/v1/health'));
        // The API answering 500 (the web server cannot run the rollback release): STOPPED after its tries, the link switched, no DONE.
        $this->freshBox();
        $this->answer('health_codes', "500\n");
        [$code, $out, $err] = $this->runScript('profit_undo_b.sh');
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString('UNDO B STOPPED: the web does not answer as it should: GET https://warehouse.floverfy.com/v1/health (no key) -> HTTP 500 '
            . '(curl exit 0), expected 401', $err);
        self::assertStringContainsString('(the link is already switched; run this again)', $err);
        self::assertStringNotContainsString('UNDO B DONE', $out);
        self::assertSame(self::RB, $this->state()['live'], 'the link is switched');
        $calls = $this->calls();
        self::assertSame(5, substr_count($calls, '/v1/health'), 'five tries (two seconds apart: the sleep stub returns at once)');
        self::assertStringNotContainsString('expire_reservations', $calls, 'no smoke run before the API answers');
        // Put right: the same script again, DONE.
        unlink($this->stub . '/answers/health_codes');
        unlink($this->stub . '/state/health_calls');
        [$code, $out, $err] = $this->runScript('profit_undo_b.sh');
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('UNDO B DONE', $out);
        // No connection at all (curl's 000, exit 7): no 401 either.
        $this->freshBox();
        $this->answer('health_codes', "000\n");
        $this->answer('health_exits', "7\n");
        [$code, $out, $err] = $this->runScript('profit_undo_b.sh');
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString('-> HTTP 000 (curl exit 7), expected 401', $err);
        self::assertStringNotContainsString('UNDO B DONE', $out);
    }

    // ---- the Codex check of this round (r14, build/A/codex_r14.txt, the default model), shown failing first on 3bdb294 ----------------

    /**
     * r14 HIGH: undo B is run from a plain root shell, whose directory is /root (0700). GNU find run as www-data from there ends with "Failed
     * to restore initial working directory" and exit 1 (find 4.9.0 on the CW test box: runs/profit_r14_find_cwd_check.log), so the readability
     * check stopped every undo. It runs from / now: started in a directory only root can enter, the undo goes on, DONE.
     */
    public function testUndoBRunsFromADirectoryOnlyRootCanEnter(): void
    {
        $home = $this->base . '/roothome';
        mkdir($home, 0700);
        chmod($home, 0700);
        $this->cwd = $home;
        [$code, $out, $err] = $this->runScript('profit_undo_b.sh');
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('UNDO B DONE', $out);
        self::assertStringNotContainsString('Failed to restore initial working directory', $err);
        self::assertStringContainsString('runuser -u www-data -- find ' . self::RB . ' ! -readable -print -quit', $this->calls());
        // ... and a root-only file is still found from there.
        $this->freshBox();
        mkdir($this->root . self::RB . '/src/Api', 0755, true);
        file_put_contents($this->root . self::RB . '/src/Api/Kernel.php', "<?php\n");
        chmod($this->root . self::RB . '/src/Api/Kernel.php', 0600);
        [$code, $out, $err] = $this->runScript('profit_undo_b.sh');
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString('UNDO B STOPPED: www-data cannot read ' . self::RB . '/src/Api/Kernel.php', str_replace($this->root, '', $err));
    }

    /**
     * r14 MEDIUM: a probe counts only when curl itself succeeded (a 401 printed and then a timeout reading the body, curl's exit 28, is no
     * answer), with no curlrc and no proxy (-q, --noproxy '*': an inherited proxy could answer for the box); and r14's F2 (b) PARTLY: the
     * API answers 401 before it opens the database, so the sign-in page is asked too (GET /ui/login: 200 only once the web server read
     * app.env, connected as cw_app and read ui_secret_key, as enable_https.sh checks it), else STOPPED.
     */
    public function testUndoBCountsAProbeOnlyWhenCurlSucceededAndTheSignInPageAnswers(): void
    {
        $this->answer('health_exits', "28\n");
        [$code, $out, $err] = $this->runScript('profit_undo_b.sh');
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString('GET https://warehouse.floverfy.com/v1/health (no key) -> HTTP 401 (curl exit 28), expected 401', $err);
        self::assertStringNotContainsString('UNDO B DONE', $out);
        self::assertStringContainsString(self::PROBE, $this->calls(), 'no curlrc, no proxy');
        // The sign-in page answering 503 (the database not reached as the web server): STOPPED, no DONE.
        $this->freshBox();
        $this->answer('login_codes', "503\n");
        [$code, $out, $err] = $this->runScript('profit_undo_b.sh');
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString('GET https://warehouse.floverfy.com/ui/login -> HTTP 503 (curl exit 0), expected 200', $err);
        self::assertStringContainsString('(the link is already switched; run this again)', $err);
        self::assertStringNotContainsString('UNDO B DONE', $out);
        self::assertSame(5, substr_count($this->calls(), '/ui/login'));
    }
}
