<?php

declare(strict_types=1);

/**
 * One parallel sign-in attempt for tests/Integration/Auth/LoginLimiterRaceTest: its own app-login
 * connection (like one php-fpm worker), waits for the common start time, runs CW\Auth\Login::attempt
 * once and prints {"status": ok|invalid|locked, "ms": n}. Job on stdin: {start, email, password, code, ip}.
 */

use CW\Auth\Login;
use CW\Auth\LoginLimiter;
use CW\Auth\Sessions;
use CW\Config;
use CW\Db;
use CW\Staff\SecretBox;
use CW\Tests\Support\TestDb;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
date_default_timezone_set('UTC');

$job = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$config = Config::load();
$db = Db::connect($config->dbApp()->withDatabase(TestDb::name()));
$login = new Login($db, new Sessions($db), new LoginLimiter($db), SecretBox::fromBase64((string) $config->get('ui_secret_key')));
@time_sleep_until((float) $job['start']);
$t = microtime(true);
$r = $login->attempt((string) $job['email'], (string) $job['password'], (string) $job['code'], (string) $job['ip'], 'race-worker', null);
echo json_encode(['status' => $r['status'], 'ms' => (int) round(1000 * (microtime(true) - $t))]), "\n";
