<?php

declare(strict_types=1);

/**
 * Dev-only profiler of the staff screens: renders the GET pages of CW\Ui\Kernel in-process as chosen staff and runs the common
 * actions, timing each request and every SQL statement it sends (time, rows, the slowest, the repeated ones).
 *
 * It REFUSES unless --db names a cw_test_* schema: run it on a copy of cw_staging, never on cw_staging itself (docs/dev.md,
 * "Profiling the staff screens"). It writes to that copy: the grants of the app login, one staff_session per profile (no web
 * sign-in: the session row is written straight into the copy), the profile accounts it lacks (perf-<name>@test.example) and,
 * with --actions, whatever the actions do (most run inside a transaction that is rolled back; the creating forms, which own
 * their transaction, run for real).
 *
 *   php -d opcache.enable_cli=1 tests/perf/profile_pages.php --db=cw_test_perf [--repeat=5] [--profiles=owner,lead]
 *       [--only=REGEX] [--pages] [--actions] [--fixed] [--json=FILE] [--explain=N]
 *
 * --pages (default when neither --pages nor --actions is given: both) every GET page each profile may open, with realistic ids
 *         taken from the lists (the first row a person would click);
 * --actions the common actions (POST, then the GET the 303 leads to);
 * --fixed the per-request fixed costs (connect + TLS, the session lookup, settings, CSRF);
 * --explain=N EXPLAIN ANALYZE of the N slowest distinct SELECTs seen;
 * --queries every statement of each row's median run (time, rows, SQL), to see what a page asks.
 * Each request runs once to warm up, then --repeat times; the table shows the median and the worst.
 */

use CW\Auth\Csrf;
use CW\Auth\Permissions;
use CW\Auth\Sessions;
use CW\Config;
use CW\Db;
use CW\Schema\Grants;
use CW\Ui\HtmlResponse;
use CW\Ui\Kernel;
use CW\Ui\Route;
use CW\Ui\UiRequest;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
date_default_timezone_set('UTC');

/** The statements of the request being measured. */
final class Prof
{
    public static bool $on = false;
    /** @var list<array{sql: string, ms: float, rows: int, params: array<int|string, array{0: mixed, 1: int}>}> */
    public static array $q = [];

    /** @param array<int|string, array{0: mixed, 1: int}> $params */
    public static function add(string $sql, float $ms, int $rows, array $params): void
    {
        if (self::$on) {
            self::$q[] = ['sql' => $sql, 'ms' => $ms, 'rows' => $rows, 'params' => $params];
        }
    }
}

/** A statement class that times execute() (buffered: the rows have arrived when it returns). */
final class ProfStatement extends \PDOStatement
{
    /** @var array<int|string, array{0: mixed, 1: int}> */
    private array $bound = [];

    protected function __construct()
    {
    }

    public function bindValue(int|string $param, mixed $value, int $type = \PDO::PARAM_STR): bool
    {
        $this->bound[$param] = [$value, $type];
        return parent::bindValue($param, $value, $type);
    }

    public function execute(?array $params = null): bool
    {
        $t = hrtime(true);
        try {
            return parent::execute($params);
        } finally {
            $ms = (hrtime(true) - $t) / 1e6;
            try {
                $rows = $this->rowCount();
            } catch (\Throwable) {
                $rows = -1;
            }
            Prof::add($this->queryString, $ms, $rows, $this->bound);
        }
    }
}

/** One profile's browser: the session cookie, the CSRF token, requests straight into the kernel. */
final class PerfBrowser
{
    public function __construct(private readonly Kernel $kernel, public readonly string $token, public readonly string $csrf, public readonly string $label)
    {
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $post
     * @return array{res: HtmlResponse, ms: float, queries: list<array<string, mixed>>}
     */
    public function send(string $method, string $path, array $query = [], array $post = []): array
    {
        $headers = ['host' => 'cw-perf.invalid'];
        if ($method === 'POST') {
            $headers['content-type'] = 'application/x-www-form-urlencoded';
            $post += ['csrf' => $this->csrf];
        }
        $req = new UiRequest($method, $path, $query, $post, [Kernel::SESSION_COOKIE => $this->token], $headers, '198.51.100.77', false, []);
        Prof::$q = [];
        Prof::$on = true;
        $t = hrtime(true);
        $res = $this->kernel->handle($req);
        $ms = (hrtime(true) - $t) / 1e6;
        Prof::$on = false;
        return ['res' => $res, 'ms' => $ms, 'queries' => Prof::$q];
    }

    /** @return array{0: string, 1: array<string, string>}|null path and query of the first link whose path matches $regex */
    public static function firstLink(string $html, string $regex): ?array
    {
        if (preg_match_all('#href="([^"]+)"#', $html, $m) < 1) {
            return null;
        }
        foreach ($m[1] as $href) {
            $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5);
            $path = (string) parse_url($href, PHP_URL_PATH);
            if (preg_match($regex, $path) === 1) {
                parse_str((string) parse_url($href, PHP_URL_QUERY), $q);
                /** @var array<string, string> $q */
                return [$path, $q];
            }
        }
        return null;
    }

    /**
     * The fields of the forms whose action contains $action, in page order (inputs, checked boxes, selects, textareas).
     *
     * @return list<array<string, string>>
     */
    public static function forms(string $html, string $action): array
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $out = [];
        foreach ($doc->getElementsByTagName('form') as $form) {
            if (!str_contains($form->getAttribute('action'), $action)) {
                continue;
            }
            $f = [];
            foreach ($form->getElementsByTagName('input') as $in) {
                $name = $in->getAttribute('name');
                if ($name === '' || $in->hasAttribute('disabled')) {
                    continue;
                }
                $type = strtolower($in->getAttribute('type') ?: 'text');
                if (in_array($type, ['radio', 'checkbox'], true)) {
                    if ($in->hasAttribute('checked')) {
                        $f[$name] = $in->getAttribute('value') ?: 'on';
                    }
                    continue;
                }
                if (in_array($type, ['submit', 'button', 'reset', 'file'], true)) {
                    continue;
                }
                $f[$name] = $in->getAttribute('value');
            }
            foreach ($form->getElementsByTagName('select') as $sel) {
                $chosen = null;
                foreach ($sel->getElementsByTagName('option') as $o) {
                    if ($chosen === null || $o->hasAttribute('selected')) {
                        $chosen = $o->getAttribute('value');
                    }
                }
                $f[$sel->getAttribute('name')] = (string) $chosen;
            }
            foreach ($form->getElementsByTagName('textarea') as $ta) {
                if ($ta->getAttribute('name') !== '') {
                    $f[$ta->getAttribute('name')] = (string) preg_replace('/^\r?\n/', '', (string) $ta->textContent);
                }
            }
            $out[] = $f;
        }
        return $out;
    }
}

// ---------------------------------------------------------------------------------------------------------------------------
// Options and the guard
// ---------------------------------------------------------------------------------------------------------------------------

$opts = getopt('', ['db:', 'repeat:', 'profiles:', 'only:', 'pages', 'actions', 'fixed', 'json:', 'explain:', 'queries', 'help']);
if (isset($opts['help']) || !is_string($opts['db'] ?? null)) {
    fwrite(STDERR, "usage: php -d opcache.enable_cli=1 tests/perf/profile_pages.php --db=cw_test_<name> [--repeat=5] [--profiles=a,b] [--only=REGEX] [--pages] [--actions] [--fixed] [--json=FILE] [--explain=N]\n");
    exit(2);
}
$schema = $opts['db'];
$config = Config::load();
if (preg_match('/^cw_test_[a-z0-9_]{1,40}$/D', $schema) !== 1 || $schema === $config->appFile('db_name')) {
    fwrite(STDERR, "profile_pages: REFUSING: --db must name a cw_test_* copy, never {$schema}\n");
    exit(2);
}
$repeat = max(1, (int) ($opts['repeat'] ?? 5));
$only = is_string($opts['only'] ?? null) ? '#' . str_replace('#', '\#', $opts['only']) . '#i' : null;
$doPages = isset($opts['pages']) || !isset($opts['actions']);
$doActions = isset($opts['actions']) || !isset($opts['pages']);
$doFixed = isset($opts['fixed']);
if (!function_exists('opcache_get_status') || !(bool) ini_get('opcache.enable_cli')) {
    fwrite(STDERR, "profile_pages: note: opcache is off in this CLI (templates are compiled on every include, unlike php-fpm): add -d opcache.enable_cli=1\n");
}

$admin = Db::connect($config->dbAdmin()->withDatabase($schema));
if ($admin->value('SELECT DATABASE()') !== $schema || $admin->value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?', [$schema, 'staff_user']) !== 1) {
    fwrite(STDERR, "profile_pages: {$schema} is not a CW schema (no staff_user)\n");
    exit(2);
}
$appUser = $config->appDbUser() ?? throw new RuntimeException('app.env has no db_user');
Grants::apply($admin, $schema, $appUser);

// ---------------------------------------------------------------------------------------------------------------------------
// Fixed costs of every request
// ---------------------------------------------------------------------------------------------------------------------------

/** @param list<float> $v */
function median(array $v): float
{
    sort($v);
    $n = count($v);
    return $n === 0 ? 0.0 : ($n % 2 === 1 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2);
}

if ($doFixed) {
    $appSettings = $config->dbApp()->withDatabase($schema);
    $tcp = $full = $pdoOnly = [];
    for ($i = 0; $i < 15; $i++) {
        $t = hrtime(true);
        $fp = @fsockopen($appSettings->host, $appSettings->port, $errno, $errstr, 5);
        $tcp[] = (hrtime(true) - $t) / 1e6;
        if (is_resource($fp)) {
            fclose($fp);
        }
        $t = hrtime(true);
        $c = Db::connect($appSettings);
        $full[] = (hrtime(true) - $t) / 1e6;
        unset($c);
        $t = hrtime(true);
        $p = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $appSettings->host, $appSettings->port, $schema), $appSettings->user,
            $appSettings->password(), [PDO::MYSQL_ATTR_SSL_CA => Db::SYSTEM_CA_BUNDLE, PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false]);
        $pdoOnly[] = (hrtime(true) - $t) / 1e6;
        unset($p);
    }
    $c = Db::connect($appSettings);
    $ping = $prep = [];
    for ($i = 0; $i < 30; $i++) {
        $t = hrtime(true);
        $c->value('SELECT 1');
        $ping[] = (hrtime(true) - $t) / 1e6;
        $t = hrtime(true);
        $c->pdo()->prepare('SELECT ? + ' . $i); // native prepares: every statement of a page is a prepare round trip, then an execute
        $prep[] = (hrtime(true) - $t) / 1e6;
    }
    $cipher = $cipherPs = [];
    for ($i = 0; $i < 15; $i++) {
        $t = hrtime(true);
        $c->pdo()->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetchAll();
        $cipher[] = (hrtime(true) - $t) / 1e6;
        $t = hrtime(true);
        try {
            $c->pdo()->query("SELECT VARIABLE_VALUE FROM performance_schema.session_status WHERE VARIABLE_NAME = 'Ssl_cipher'")->fetchAll();
        } catch (\Throwable) {
        }
        $cipherPs[] = (hrtime(true) - $t) / 1e6;
    }
    $t = hrtime(true);
    for ($i = 0; $i < 200; $i++) {
        Config::loadApp();
    }
    $cfg = (hrtime(true) - $t) / 1e6 / 200;
    $csrf = Csrf::fromSecretKey(base64_encode(random_bytes(32)));
    $t = hrtime(true);
    for ($i = 0; $i < 1000; $i++) {
        $csrf->forSession(hash('sha256', (string) $i));
    }
    $hm = (hrtime(true) - $t) / 1e6 / 1000;
    printf("FIXED  tcp connect to the cluster: median %.1f ms (worst %.1f)\n", median($tcp), max($tcp));
    printf("FIXED  PDO connect (TCP + TLS + auth): median %.1f ms (worst %.1f)\n", median($pdoOnly), max($pdoOnly));
    printf("FIXED  Db::connect (PDO + SET SESSION + the TLS check): median %.1f ms (worst %.1f)\n", median($full), max($full));
    printf("FIXED  one statement (SELECT 1: prepare + execute): median %.2f ms (worst %.2f); the prepare alone: median %.2f ms\n", median($ping), max($ping), median($prep));
    printf("FIXED  Db::connect's TLS check (SHOW SESSION STATUS LIKE 'Ssl_cipher'): median %.2f ms (worst %.2f); the same from performance_schema.session_status: %.2f ms\n",
        median($cipher), max($cipher), median($cipherPs));
    $p1 = Db::connect($appSettings, true); // a persistent link, then its reuse as the next request of a php-fpm worker would
    unset($p1);
    $reuse = [];
    for ($i = 0; $i < 15; $i++) {
        $t = hrtime(true);
        $p1 = Db::connect($appSettings, true);
        $reuse[] = (hrtime(true) - $t) / 1e6;
        unset($p1);
    }
    printf("FIXED  Db::connect of a reused persistent link (ping, rollback, locks, schema, session, TLS check): median %.1f ms (worst %.1f)\n", median($reuse), max($reuse));
    printf("FIXED  Config::loadApp (parses app.env): %.3f ms; CSRF token: %.4f ms\n", $cfg, $hm);
}

// ---------------------------------------------------------------------------------------------------------------------------
// The kernel, the profiles and their sessions
// ---------------------------------------------------------------------------------------------------------------------------

$app = Db::connect($config->dbApp()->withDatabase($schema));
$app->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ProfStatement::class, []]);
$key = base64_encode(random_bytes(32)); // a key of this run only: no secret of the box is read
$log = [];
$kernel = new Kernel(static fn (): Db => $app, static fn (): string => $key, static function (string $m) use (&$log): void {
    $log[] = $m;
}, null, static fn (): bool => true);
$csrfMaker = Csrf::fromSecretKey($key);

/** name => roles; `owner` and `admin` are the accounts of the copy that hold exactly these roles, when there is one. */
$profileRoles = [
    'owner' => ['mapping_lead', 'reviewer'],
    'admin' => ['admin'],
    'buyer' => ['buyer'],
    'lead' => ['mapping_lead'],
    'goods_in' => ['goods_in'],
];
if (is_string($opts['profiles'] ?? null)) {
    $profileRoles = array_intersect_key($profileRoles, array_flip(explode(',', $opts['profiles'])));
}
$browsers = [];
$sessions = new Sessions($admin);
foreach ($profileRoles as $name => $roles) {
    sort($roles);
    $id = null;
    if (in_array($name, ['owner', 'admin'], true)) {
        foreach ($admin->all("SELECT u.id, (SELECT GROUP_CONCAT(r.role ORDER BY r.role) FROM staff_role r WHERE r.staff_user_id = u.id AND r.revoked_at IS NULL) roles
                FROM staff_user u WHERE u.is_active = 1 AND u.email NOT LIKE '%.invalid' ORDER BY u.id") as $u) {
            if ((string) $u['roles'] === implode(',', $roles)) {
                $id = (int) $u['id'];
                break;
            }
        }
    }
    if ($id === null) {
        $email = "perf-{$name}@test.example";
        $id = $admin->value('SELECT id FROM staff_user WHERE email = ?', [$email]);
        if ($id === null) {
            $id = $admin->insert('INSERT INTO staff_user (username, display_name, email, password_hash, is_active) VALUES (?, ?, ?, ?, 1)',
                [$email, 'Perf ' . $name, $email, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
            foreach ($roles as $role) {
                $admin->exec('INSERT INTO staff_role (staff_user_id, role) VALUES (?, ?)', [$id, $role]);
            }
        }
        $id = (int) $id;
    }
    $admin->exec('UPDATE staff_user SET password_must_change = 0 WHERE id = ?', [$id]);
    $s = $sessions->create($id, '198.51.100.77', 'cw-perf');
    $browsers[$name] = ['b' => new PerfBrowser($kernel, $s['token'], $csrfMaker->forSession($s['id']), $name), 'roles' => $roles, 'id' => $id];
    fwrite(STDERR, "profile {$name}: staff {$id} (" . implode(',', $roles) . ")\n");
}

// ---------------------------------------------------------------------------------------------------------------------------
// Measuring
// ---------------------------------------------------------------------------------------------------------------------------

/** @var list<array<string, mixed>> $results */
$results = [];
/** @var array<string, array{sql: string, ms: float, params: array<int|string, array{0: mixed, 1: int}>}> $slowest */
$slowest = [];

function normSql(string $sql): string
{
    return trim((string) preg_replace('/\s+/', ' ', $sql));
}

/**
 * Runs $fn (one measured request) once to warm up and $repeat times, and records the median and the worst.
 *
 * @param \Closure(): array{res: HtmlResponse, ms: float, queries: list<array<string, mixed>>} $fn
 */
function measure(string $label, string $profile, int $repeat, \Closure $fn, bool $warm = true): ?array
{
    global $results, $slowest;
    if ($warm) {
        $fn();
    }
    $runs = [];
    for ($i = 0; $i < $repeat; $i++) {
        $runs[] = $fn();
    }
    $ms = array_map(static fn (array $r): float => $r['ms'], $runs);
    $med = median($ms);
    // The run closest to the median gives the query detail.
    usort($runs, static fn (array $a, array $b): int => abs($a['ms'] - $med) <=> abs($b['ms'] - $med));
    $r = $runs[0];
    $qs = $r['queries'];
    $dbMs = array_sum(array_column($qs, 'ms'));
    usort($qs, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);
    $top = [];
    foreach (array_slice($qs, 0, 3) as $q) {
        $top[] = sprintf('%.1f ms %d rows: %s', $q['ms'], $q['rows'], mb_substr(normSql($q['sql']), 0, 160));
    }
    foreach ($qs as $q) {
        $n = normSql($q['sql']);
        if (!isset($slowest[$n]) || $q['ms'] > $slowest[$n]['ms']) {
            $slowest[$n] = ['sql' => $q['sql'], 'ms' => $q['ms'], 'params' => $q['params'], 'where' => "{$label} ({$profile})"];
        }
    }
    $counts = array_count_values(array_map(static fn (array $q): string => normSql($q['sql']), $r['queries']));
    arsort($counts);
    $repeats = [];
    foreach ($counts as $sql => $n) {
        if ($n >= 3) {
            $repeats[] = "{$n}x " . mb_substr($sql, 0, 120);
        }
    }
    $row = ['label' => $label, 'profile' => $profile, 'status' => $r['res']->status, 'median_ms' => round($med, 1), 'worst_ms' => round(max($ms), 1),
        'queries' => count($r['queries']), 'db_ms' => round($dbMs, 1), 'bytes' => strlen($r['res']->body), 'top' => $top, 'repeats' => array_slice($repeats, 0, 4),
        'all' => array_map(static fn (array $q): string => sprintf('%.1f ms %d rows: %s', $q['ms'], $q['rows'], mb_substr(normSql($q['sql']), 0, 200)), $r['queries'])];
    $results[] = $row;
    printf("%-58s %-8s %3d %8.1f %8.1f %4d q %7.1f db %7d B\n", mb_substr($label, 0, 58), $profile, $row['status'], $row['median_ms'], $row['worst_ms'],
        $row['queries'], $row['db_ms'], $row['bytes']);
    return $row;
}

/**
 * The pages: [label, path, query] or [label, [source path, source query, link path regex], extra query]. A derived page is the first
 * link on the source page whose path matches (what a person clicks first).
 */
$pages = [
    ['Home', '/ui/', []],
    ['Password', '/ui/password', []],
    ['Matches: Strong (Key) list', '/ui/review', ['queue' => 'Key']],
    ['Matches: Check list', '/ui/review', ['queue' => 'Check']],
    ['Matches: New item list', '/ui/review', ['queue' => 'New item']],
    ["Matches: Can't tell list", '/ui/review', ['queue' => "Can't tell"]],
    ['Matches: Conflict list', '/ui/review', ['queue' => 'Conflict']],
    ['Matches: Manual list', '/ui/review', ['queue' => 'Manual']],
    ['Matches: Key list, Vape and Go, page 2', '/ui/review', ['queue' => 'Key', 'channel' => 'vapeandgo', 'page' => '2']],
    ['Matches: Check list, find "elf"', '/ui/review', ['queue' => 'Check', 'q' => 'elf']],
    ['Matches: waiting for a second OK', '/ui/review', ['queue' => 'pending']],
    ['Match: first of Key list', ['/ui/review', ['queue' => 'Key'], '#^/ui/review/listing/\d+$#'], []],
    ['Match: first of Check list', ['/ui/review', ['queue' => 'Check'], '#^/ui/review/listing/\d+$#'], []],
    ["Match: first of Can't tell list", ['/ui/review', ['queue' => "Can't tell"], '#^/ui/review/listing/\d+$#'], []],
    ['Match: first of Manual list', ['/ui/review', ['queue' => 'Manual'], '#^/ui/review/listing/\d+$#'], []],
    ['Spot checks', '/ui/review/samples', []],
    ['Spot check: first', ['/ui/review/samples', [], '#^/ui/review/samples/\d+$#'], []],
    ['Spot check: first member', [null, [], '#^/ui/review/listing/\d+$#', 'Spot check: first'], []],
    ['Duplicates list', '/ui/review/duplicates', []],
    ['Duplicates list, page 2', '/ui/review/duplicates', ['page' => '2']],
    ['Duplicate group: first', ['/ui/review/duplicates', [], '#^/ui/review/duplicates/\d+$#'], []],
    ['Find "elf bar"', '/ui/search', ['q' => 'elf bar']],
    ['Find a barcode', '/ui/search', ['q' => '5056168']],
    ['Item: first found', [null, [], '#^/ui/items/\d+$#', 'Find "elf bar"'], []],
    ['Item: from the duplicate group', [null, [], '#^/ui/items/\d+$#', 'Duplicate group: first'], []],
    ['Item cards list', '/ui/items/cards', []],
    ['Item cards CSV (download)', '/ui/items/cards.csv', []],
    ['Item cards import form', '/ui/items/cards/import', []],
    ['Item card form', [null, [], '#^/ui/items/\d+/card$#', 'Item: first found'], []],
    ['Barcode review queue', '/ui/items/barcodes', []],
    ['People', '/ui/people', []],
    ['Person: first', ['/ui/people', [], '#^/ui/people/\d+$#'], []],
    ['People CSV (download)', '/ui/people.csv', []],
    ['Staff requests', '/ui/staff-requests', []],
    ['Documents', '/ui/documents', []],
    ['Review queue (documents)', '/ui/documents/reviews', []],
    ['Document: first', ['/ui/documents', [], '#^/ui/documents/\d+$#'], []],
    ['Reasons', '/ui/reference/reasons', []],
    ['Reason: first', ['/ui/reference/reasons', [], '#^/ui/reference/reasons/reason$#'], []],
    ['Reasons CSV (download)', '/ui/reference/reasons.csv', []],
    ['Number series', '/ui/reference/series', []],
    ['Settings', '/ui/reference/settings', []],
    ['Setting: first', ['/ui/reference/settings', [], '#^/ui/reference/settings/setting$#'], []],
    ['Approval rules', '/ui/reference/approvals', []],
    ['Warehouses', '/ui/reference/warehouses', []],
    ['Warehouse: first', ['/ui/reference/warehouses', [], '#^/ui/reference/warehouses/\d+$#'], []],
    ['Who can do what', '/ui/reference/access', []],
    ['Company details', '/ui/reference/company', []],
    ['Company details form', '/ui/reference/company/edit', []],
    ['Company sample PDF (download)', '/ui/reference/company/sample.pdf', []],
    ['Websites', '/ui/system/sites', []],
    ['Safety checks', '/ui/system/checks', []],
    ['Audit log', '/ui/system/audit', []],
    ['Audit log CSV (download)', '/ui/system/audit.csv', []],
    ['Suppliers', '/ui/purchasing/suppliers', []],
    ['Suppliers CSV (download)', '/ui/purchasing/suppliers.csv', []],
    ['New supplier form', '/ui/purchasing/suppliers/new', []],
    ['Supplier: first', ['/ui/purchasing/suppliers', [], '#^/ui/purchasing/suppliers/\d+$#'], []],
    ['Purchase orders', '/ui/purchasing/orders', []],
    ['Purchase orders CSV (download)', '/ui/purchasing/orders.csv', []],
    ['Purchase order: first', ['/ui/purchasing/orders', [], '#^/ui/purchasing/orders/\d+$#'], []],
    ['What to buy (reorder list)', '/ui/purchasing/reorder', []],
    ['What to buy, page 2', '/ui/purchasing/reorder', ['page' => '2']],
    ['What to buy CSV (download)', '/ui/purchasing/reorder.csv', []],
    ['What to buy: first item', ['/ui/purchasing/reorder', [], '#^/ui/purchasing/reorder/items/\d+$#'], []],
    ['What to buy: brands', '/ui/purchasing/reorder/brands', []],
    ['What to buy: unusual days', '/ui/purchasing/reorder/anomalies', []],
    ['Sales history', '/ui/purchasing/sales-history', []],
    ['Sales history unlinked CSV (download)', '/ui/purchasing/sales-history/unlinked.csv', []],
    ['Deliveries (receive + invoice)', '/ui/receiving', []],
    ['Deliveries sheet template (download)', '/ui/receiving/template.csv', []],
    ['Goods-in bench list', '/ui/receiving/bench', []],
    ['Incidents', '/ui/receiving/incidents', []],
    ['Delivery: first', ['/ui/receiving', [], '#^/ui/receiving/\d+$#'], []],
];

/** @return array{0: Route, 1: array<string, string>}|null */
function routeOf(Kernel $kernel, string $path): ?array
{
    try {
        return $kernel->router()->match('GET', $path);
    } catch (\Throwable) {
        return null;
    }
}

if ($doPages) {
    printf("\n%-58s %-8s %3s %8s %8s %6s %10s %9s\n", 'PAGE', 'profile', 'st', 'median', 'worst', 'q', 'db ms', 'bytes');
    foreach ($browsers as $name => $p) {
        /** @var PerfBrowser $b */
        $b = $p['b'];
        $resolved = []; // label => [path, query, html]
        foreach ($pages as [$label, $target, $extra]) {
            if (is_array($target)) {
                [$srcPath, $srcQuery, $regex] = $target;
                $srcHtml = $srcPath === null ? ($resolved[$target[3]][2] ?? null) : null;
                if ($srcPath !== null) {
                    $src = $b->send('GET', $srcPath, $srcQuery);
                    $srcHtml = $src['res']->status === 200 ? $src['res']->body : null;
                }
                $link = $srcHtml === null ? null : PerfBrowser::firstLink($srcHtml, $regex);
                if ($link === null) {
                    continue;
                }
                [$path, $query] = $link;
                $query += $extra;
            } else {
                [$path, $query] = [$target, $extra];
            }
            $route = routeOf($kernel, $path);
            if ($route === null) {
                fwrite(STDERR, "no route for {$path}\n");
                continue;
            }
            $access = $route[0]->access;
            if ($access !== Route::ANY && $access !== Route::PUBLIC && !Permissions::can($p['roles'], $access)) {
                continue;
            }
            $first = $b->send('GET', $path, $query);
            $resolved[$label] = [$path, $query, $first['res']->body];
            if ($only !== null && preg_match($only, $label) !== 1) {
                continue;
            }
            measure($label, $name, $repeat, static fn (): array => $b->send('GET', $path, $query));
        }
    }
}

// ---------------------------------------------------------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------------------------------------------------------

/**
 * Runs one action $repeat (+1 warm-up) times inside a transaction that is rolled back each time, timing the POST and then the
 * GET its 303 leads to. $prepare runs inside the transaction before the clock starts and returns [path, form] (or null to skip).
 *
 * @param \Closure(PerfBrowser): ?array{0: string, 1: array<string, string>} $prepare
 */
function action(string $label, string $profile, PerfBrowser $b, Db $app, int $repeat, \Closure $prepare, bool $rollback = true): void
{
    $post = [];
    $follow = [];
    $statuses = [];
    for ($i = 0; $i <= $repeat; $i++) {
        if ($rollback) {
            $app->pdo()->beginTransaction();
        }
        try {
            $spec = $prepare($b);
            if ($spec === null) {
                fwrite(STDERR, "action {$label}: nothing to act on\n");
                return;
            }
            [$path, $form] = $spec;
            $r = $b->send('POST', $path, [], $form);
            $statuses[] = $r['res']->status;
            $f = null;
            $loc = $r['res']->header('Location');
            if ($r['res']->status === 303 && $loc !== null) {
                parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
                /** @var array<string, string> $q */
                $f = $b->send('GET', (string) parse_url($loc, PHP_URL_PATH), $q);
            } elseif ($i === 0) {
                fwrite(STDERR, "action {$label}: POST answered {$r['res']->status} " . mb_substr(trim(strip_tags($r['res']->body)), 0, 300) . "\n");
            }
            if ($i > 0) {
                $post[] = $r;
                if ($f !== null) {
                    $follow[] = $f;
                }
            }
        } finally {
            if ($rollback && $app->pdo()->inTransaction()) {
                $app->pdo()->rollBack();
            }
        }
    }
    $k = 0;
    measure("ACTION {$label}: POST", $profile, $repeat, static function () use (&$k, $post): array {
        return $post[$k++ % count($post)];
    }, false);
    if ($follow !== []) {
        $k2 = 0;
        measure("ACTION {$label}: the page after", $profile, count($follow), static function () use (&$k2, $follow): array {
            return $follow[$k2++ % count($follow)];
        }, false);
    }
}

if ($doActions) {
    printf("\n%-58s %-8s %3s %8s %8s %6s %10s %9s\n", 'ACTION', 'profile', 'st', 'median', 'worst', 'q', 'db ms', 'bytes');
    $want = static fn (string $label): bool => $only === null || preg_match($only, $label) === 1;

    // The owner's spot check: the next member's "Yes, it is a match" and "Not a match" (owner only: the sample is theirs).
    if (isset($browsers['owner'])) {
        $b = $browsers['owner']['b'];
        $member = static function (PerfBrowser $b): ?array {
            $s = $b->send('GET', '/ui/review/samples');
            $one = PerfBrowser::firstLink($s['res']->body, '#^/ui/review/samples/\d+$#');
            if ($one === null) {
                return null;
            }
            $page = $b->send('GET', $one[0], $one[1]);
            foreach ([1, 2] as $_) {
                $l = PerfBrowser::firstLink($page['res']->body, '#^/ui/review/listing/\d+$#');
                if ($l === null) {
                    return null;
                }
                return $l;
            }
            return null;
        };
        if ($want('spot check: yes')) {
            action('spot check: yes, it is a match', 'owner', $b, $app, $repeat, static function (PerfBrowser $b) use ($member): ?array {
                $l = $member($b);
                if ($l === null) {
                    return null;
                }
                $page = $b->send('GET', $l[0], $l[1]);
                $forms = PerfBrowser::forms($page['res']->body, '/decide');
                return $forms === [] ? null : [$l[0] . '/decide', $forms[0]];
            });
        }
        if ($want('spot check: not a match')) {
            action('spot check: not a match (second step)', 'owner', $b, $app, $repeat, static function (PerfBrowser $b) use ($member): ?array {
                $l = $member($b);
                if ($l === null) {
                    return null;
                }
                $page = $b->send('GET', $l[0], $l[1]);
                $forms = PerfBrowser::forms($page['res']->body, '/decide');
                if (count($forms) < 2) {
                    return null;
                }
                return [$l[0] . '/decide', ['action' => 'reject'] + $forms[1]];
            });
        }
    }

    // A matching lead's answers on the Check list: confirm the suggestion, and "No, wrong product".
    foreach (['lead', 'owner'] as $who) {
        if (!isset($browsers[$who])) {
            continue;
        }
        $b = $browsers[$who]['b'];
        $first = static function (PerfBrowser $b, string $band): ?array {
            $list = $b->send('GET', '/ui/review', ['queue' => $band]);
            return PerfBrowser::firstLink($list['res']->body, '#^/ui/review/listing/\d+$#');
        };
        if ($want('match: confirm')) {
            action('match: confirm (Check list, save and next)', $who, $b, $app, $repeat, static function (PerfBrowser $b) use ($first): ?array {
                $l = $first($b, 'Check');
                if ($l === null) {
                    return null;
                }
                $page = $b->send('GET', $l[0], $l[1]);
                $forms = PerfBrowser::forms($page['res']->body, '/decide');
                return $forms === [] ? null : [$l[0] . '/decide', ['action' => 'link'] + $forms[count($forms) - 1]];
            });
        }
        if ($want('match: not a match')) {
            action('match: not a match (Check list)', $who, $b, $app, $repeat, static function (PerfBrowser $b) use ($first): ?array {
                $l = $first($b, 'Check');
                if ($l === null) {
                    return null;
                }
                $page = $b->send('GET', $l[0], $l[1]);
                $forms = PerfBrowser::forms($page['res']->body, '/decide');
                return $forms === [] ? null : [$l[0] . '/decide', ['action' => 'reject'] + $forms[count($forms) - 1]];
            });
        }
        if ($want('duplicate')) {
            action('duplicate group: keep separate', $who, $b, $app, $repeat, static function (PerfBrowser $b): ?array {
                $list = $b->send('GET', '/ui/review/duplicates');
                $g = PerfBrowser::firstLink($list['res']->body, '#^/ui/review/duplicates/\d+$#');
                if ($g === null) {
                    return null;
                }
                $page = $b->send('GET', $g[0], $g[1]);
                $forms = PerfBrowser::forms($page['res']->body, '/decide');
                if ($forms === []) {
                    return null;
                }
                // A creating form (form_key, FormOnce owns its transaction): it runs for real on the copy, on the next group each time.
                return [$g[0] . '/decide', ['do' => 'separate_all'] + $forms[0]];
            }, false);
        }
        break; // the lead's answers once (the owner's spot check is above)
    }

    // A setting changed with a reason (settings.manage: the owner as reviewer).
    if (isset($browsers['owner']) && $want('setting')) {
        $b = $browsers['owner']['b'];
        action('setting: save with a reason', 'owner', $b, $app, $repeat, static function (PerfBrowser $b): ?array {
            $list = $b->send('GET', '/ui/reference/settings');
            $s = PerfBrowser::firstLink($list['res']->body, '#^/ui/reference/settings/setting$#');
            if ($s === null) {
                return null;
            }
            $page = $b->send('GET', $s[0], $s[1]);
            $forms = PerfBrowser::forms($page['res']->body, '/ui/reference/settings/setting');
            if ($forms === []) {
                return null;
            }
            $f = $forms[0];
            $f['reason'] = 'perf test change';
            if (preg_match('/^[0-9]+$/D', $f['value'] ?? '') === 1) {
                $f['value'] = (string) ((int) $f['value'] + 1);
            }
            return ['/ui/reference/settings/setting', $f];
        });
    }
}

// Purchasing and receiving: cw_staging has no supplier, order or delivery yet. Seed a small set in the copy through the services
// the screens call (one active supplier, 10 of its items with a price, a draft order of 10 lines, an approved order of 10 lines and
// a delivery against it), then time the screens' actions on them.
if ($doActions && isset($browsers['buyer'], $browsers['owner'], $browsers['goods_in'])) {
    $want = static fn (string $label): bool => $only === null || preg_match($only, $label) === 1;
    $buyer = \CW\Caller::staff($browsers['buyer']['id'], '198.51.100.77');
    $reviewer = \CW\Caller::staff($browsers['owner']['id'], '198.51.100.77');
    $keyer = \CW\Caller::staff($browsers['goods_in']['id'], '198.51.100.77');
    $settings = new \CW\Settings($app);
    $sup = new \CW\Suppliers\Suppliers($app, $settings);
    $docs = new \CW\Documents\Documents($app, \CW\Documents\DocumentHandlers::all($app));
    $pos = new \CW\PurchaseOrders\PurchaseOrders($app, $docs, $settings);
    $grns = new \CW\Receiving\GoodsReceipts($app, $docs, $settings);
    $tag = bin2hex(random_bytes(3));
    $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')));
    $s = $sup->create($buyer, ['name' => 'Perf Supplies ' . $tag, 'legal_name' => 'Perf Supplies ' . $tag . ' Ltd', 'company_number' => '0' . random_int(1000000, 9999999),
        'vat_number' => 'GB' . random_int(100000000, 999999999), 'address_line1' => '1 Trading Estate', 'city' => 'Leeds', 'postcode' => 'LS1 1AA', 'country' => 'GB',
        'email' => 'orders@perf.example', 'phone' => '0113 000 0000', 'payment_terms' => '30 days', 'payment_terms_days' => '30',
        'dd_checked_on' => $today->modify('-10 days')->format('Y-m-d'), 'dd_checked_by' => (string) $buyer->staffUserId,
        'dd_evidence' => 'Companies House active (perf copy)', 'dd_next_review_on' => $today->modify('+1 year')->format('Y-m-d')]);
    $s = $sup->requestActivation($buyer, (int) $s['id'], (int) $s['version']);
    $task = $app->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND kind = 'approval' AND state = 'open'", [(int) $s['id']]);
    if ($task !== null) {
        $s = $sup->approve($reviewer, (int) $task, null);
    }
    $supplierId = (int) $s['id'];
    $items = new \CW\Suppliers\SupplierItems($app);
    $si = [];
    $firstCode = null;
    foreach ($app->column('SELECT d.sku_id FROM reorder_demand d JOIN sku k ON k.id = d.sku_id WHERE k.merged_into_sku_id IS NULL '
        . 'AND NOT EXISTS (SELECT 1 FROM supplier_item x WHERE x.sku_id = d.sku_id) ORDER BY d.rate DESC LIMIT 10') as $sku) {
        $row = $items->create($buyer, $supplierId, (int) $sku, ['units_per_pack' => '6', 'supplier_code' => 'PERF-' . $tag . '-' . $sku], ['pack_price' => '12.0000']);
        $si[] = (int) $row['id'];
        $firstCode ??= 'PERF-' . $tag . '-' . $sku;
    }
    $lines = array_map(static fn (int $id): array => ['supplier_item_id' => $id, 'packs' => 2], $si);
    $draft = $pos->createDraft($buyer, $supplierId, []);
    $draft = $pos->saveDraft($buyer, $draft->id, $draft->version, [], $lines);
    $approved = $pos->createDraft($buyer, $supplierId, []);
    $approved = $pos->saveDraft($buyer, $approved->id, $approved->version, [], $lines);
    $approved = $pos->approve($buyer, $approved->id, $approved->version);
    $grn = $grns->createDraft($keyer, $supplierId, ['invoice_number' => 'PERF-' . $tag], $approved->id, true);
    fwrite(STDERR, "seeded supplier {$supplierId}, 10 items, draft PO {$draft->id}, approved PO {$approved->id}, delivery {$grn->id}\n");

    $b = $browsers['buyer']['b'];
    $editor = static function (PerfBrowser $b, int $id): array {
        $page = $b->send('GET', "/ui/purchasing/orders/{$id}");
        return PerfBrowser::forms($page['res']->body, "/ui/purchasing/orders/{$id}/lines")[0] ?? [];
    };
    if ($want('PO: start')) {
        action('PO: start a new draft', 'buyer', $b, $app, $repeat, static function (PerfBrowser $b) use ($supplierId): ?array {
            $list = $b->send('GET', '/ui/purchasing/orders');
            $f = PerfBrowser::forms($list['res']->body, '/ui/purchasing/orders')[0] ?? null;
            return $f === null ? null : ['/ui/purchasing/orders', ['supplier_id' => (string) $supplierId] + $f];
        }, false);
    }
    if ($want('PO: add')) {
        action('PO: add a line by its supplier code', 'buyer', $b, $app, $repeat, static function (PerfBrowser $b) use ($editor, $draft, $firstCode): ?array {
            $f = $editor($b, $draft->id);
            return $f === [] ? null : ["/ui/purchasing/orders/{$draft->id}/lines", ['action' => 'add', 'q' => (string) $firstCode] + $f];
        });
    }
    if ($want('PO: save')) {
        action('PO: save the 10-line draft', 'buyer', $b, $app, $repeat, static function (PerfBrowser $b) use ($editor, $draft): ?array {
            $f = $editor($b, $draft->id);
            return $f === [] ? null : ["/ui/purchasing/orders/{$draft->id}/lines", ['action' => 'save'] + $f];
        });
    }
    if ($want('PO: confirm')) {
        action('PO: confirm the 10-line draft', 'buyer', $b, $app, $repeat, static function (PerfBrowser $b) use ($editor, $draft): ?array {
            $f = $editor($b, $draft->id);
            return $f === [] ? null : ["/ui/purchasing/orders/{$draft->id}/lines", ['action' => 'approve'] + $f];
        });
    }
    $g = $browsers['goods_in']['b'];
    if ($want('delivery: start')) {
        action('delivery: start one from the order (copy its lines)', 'goods_in', $g, $app, $repeat, static function (PerfBrowser $b) use ($approved): ?array {
            $list = $b->send('GET', '/ui/receiving');
            $f = PerfBrowser::forms($list['res']->body, '/ui/receiving')[0] ?? null;
            return $f === null ? null : ['/ui/receiving', ['po_id' => (string) $approved->id, 'copy' => '1', 'invoice_number' => 'PERF-' . bin2hex(random_bytes(4))] + $f];
        }, false);
    }
    if ($want('delivery: save')) {
        action('delivery: save the 10-line delivery', 'goods_in', $g, $app, $repeat, static function (PerfBrowser $b) use ($grn): ?array {
            $page = $b->send('GET', "/ui/receiving/{$grn->id}");
            $f = PerfBrowser::forms($page['res']->body, "/ui/receiving/{$grn->id}/lines")[0] ?? null;
            return $f === null ? null : ["/ui/receiving/{$grn->id}/lines", ['action' => 'save'] + $f];
        });
    }
    if ($want('bench')) {
        action('bench check: every line counted', 'goods_in', $g, $app, $repeat, static function (PerfBrowser $b) use ($grn): ?array {
            $page = $b->send('GET', "/ui/receiving/{$grn->id}/bench");
            $f = PerfBrowser::forms($page['res']->body, "/ui/receiving/{$grn->id}/bench")[0] ?? null;
            if ($f === null) {
                return null;
            }
            foreach (array_keys($f) as $k) {
                if (preg_match('/^b_(\d+)_present$/', (string) $k, $m) === 1) {
                    $f["b_{$m[1]}_ok"] = '1';
                }
            }
            return ["/ui/receiving/{$grn->id}/bench", ['next' => '0', 'paperwork_ok' => '1'] + $f];
        });
    }
}

// ---------------------------------------------------------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------------------------------------------------------

if (isset($opts['explain'])) {
    uasort($slowest, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);
    $n = 0;
    foreach ($slowest as $norm => $s) {
        if (!preg_match('/^\s*(SELECT|WITH)\b/i', $s['sql'])) {
            continue;
        }
        if ($n++ >= (int) $opts['explain']) {
            break;
        }
        echo "\n=== EXPLAIN ANALYZE ({$s['ms']} ms in {$s['where']}): " . mb_substr($norm, 0, 400) . "\n";
        try {
            $admin->exec('SET SESSION MAX_EXECUTION_TIME = 30000');
            $st = $admin->pdo()->prepare('EXPLAIN ANALYZE ' . $s['sql']);
            foreach ($s['params'] as $k => [$v, $t]) {
                $st->bindValue($k, $v, $t);
            }
            $st->execute();
            foreach ($st->fetchAll(PDO::FETCH_NUM) as $row) {
                echo $row[0], "\n";
            }
        } catch (\Throwable $e) {
            echo 'explain failed: ', $e->getMessage(), "\n";
        }
    }
}

if (is_string($opts['json'] ?? null)) {
    file_put_contents($opts['json'], json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
echo "\nSLOWEST 3 QUERIES AND REPEATS PER ROW\n";
foreach ($results as $r) {
    echo "- {$r['label']} [{$r['profile']}] {$r['median_ms']} ms, {$r['queries']} q\n";
    foreach ($r['top'] as $t) {
        echo "    {$t}\n";
    }
    foreach ($r['repeats'] as $t) {
        echo "    REPEAT {$t}\n";
    }
    if (isset($opts['queries'])) {
        foreach ($r['all'] as $t) {
            echo "      . {$t}\n";
        }
    }
}
foreach ($log as $m) {
    fwrite(STDERR, "kernel log: {$m}\n");
}
// The sessions of this run end with it.
$admin->exec("UPDATE staff_session SET revoked = 1, revoked_at = NOW(6) WHERE user_agent_hash = ? AND revoked = 0", [hash('sha256', 'cw-perf')]);
