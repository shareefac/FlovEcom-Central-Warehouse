<?php

declare(strict_types=1);

/**
 * Finds the landing pages among a store's website products and marks them (docs/decisions.md LP5; CW\Mapping\LandingPages). A
 * landing page is the page of a product where the customer picks an option; the shops keep it as one more option, but it is not a
 * product. The owner runs it once per store after the deploy of 0050 (a dry run first), and again whenever wanted: a second run
 * marks nothing twice.
 *
 *   php bin/landing_pages.php --channel=<code> [--dry-run | --apply] [--include-ignored] [--list] [--db=<schema>] [--admin]
 *   php bin/landing_pages.php --recent-hours=<1-720> [--dry-run | --apply] [--list]       (every store: the hourly cron line)
 *
 *  - --recent-hours: the website products of EVERY store first seen in the last hours, and the other products of their pages, as on
 *    arrival (the website's flag; the rule while mapping.landing_rule_on_arrival is on; ignored ones never): a mark a push could not
 *    make is made here (LP4; Codex review R5). Older products are the owner's dry run and --apply, store by store. No --channel.
 *  - Dry run unless --apply (--dry-run says so out loud): what it would mark and why (the website's own flag, or the rule and its
 *    version), and what it leaves and why (linked to an item, a person said real, decided one at a time, waiting for a second OK,
 *    ignored, a landing page no longer found). Nothing is written, nothing is locked.
 *  - --apply: each mark is a decision of its own (DecisionService `landing`, actor system:landing_pages, with why), and one audit
 *    row for the run (`landing.run`). A product that changed since the plan was read is refused under its lock and counted.
 *  - --include-ignored: website products staff ignored are marked too (otherwise they are listed and left).
 *  - --list: one line per website product it marks or leaves.
 *  The system never unmarks and never marks what a person called a real product (LP3).
 * Exit codes: 0 ok · 1 a mark was refused · 2 usage · 3 cannot run.
 */

use CW\Caller;
use CW\Mapping\LandingPages;
use CW\Mapping\LandingRule;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

ini_set('memory_limit', '1G');

exit(Cli::main('landing_pages', ['channel:', 'dry-run', 'apply', 'include-ignored', 'list', 'recent-hours:'],
    'usage: php bin/landing_pages.php --channel=<code> [--dry-run | --apply] [--include-ignored] [--list]' . "\n"
    . '       php bin/landing_pages.php --recent-hours=<1-720> [--dry-run | --apply] [--list]',
    static function (Cli $cli, array $opts): int {
        if (array_key_exists('apply', $opts) && array_key_exists('dry-run', $opts)) {
            throw new InvalidArgumentException('--dry-run and --apply: choose one');
        }
        $apply = array_key_exists('apply', $opts);
        if (array_key_exists('recent-hours', $opts)) {
            if (array_key_exists('channel', $opts) || array_key_exists('include-ignored', $opts)) {
                throw new InvalidArgumentException('--recent-hours looks at every store as on arrival: no --channel, no --include-ignored');
            }
            $hours = Cli::intOpt($opts, 'recent-hours', 48, 1, 720);
            $svc = new LandingPages($cli->db);
            $rule = \CW\Settings::flag($cli->db, LandingPages::SETTING, true);
            $refused = [];
            foreach ($cli->db->all('SELECT id, code FROM channel ORDER BY id') as $ch) {
                $ids = $svc->recentIds((int) $ch['id'], $hours);
                $plan = $svc->plan((int) $ch['id'], $ids, false, $rule);
                $r = $apply && $plan['counts']['mark'] > 0 ? $svc->apply(\CW\Caller::system('landing_pages'), $plan, 'recent') : null;
                foreach ($r['refused'] ?? [] as $why => $n) {
                    $refused[$why] = ($refused[$why] ?? 0) + $n;
                }
                if ($ids !== [] || array_key_exists('list', $opts)) {
                    $cli->log(sprintf('%schannel=%s recent_hours=%d new=%d would_mark=%d%s', $apply ? '' : 'DRY RUN (nothing written) ', (string) $ch['code'], $hours, count($ids),
                        $plan['counts']['mark'], $r === null ? '' : sprintf(' marked=%d refused=%d', $r['marked'], array_sum($r['refused']))));
                }
            }
            return $refused === [] ? Cli::OK : Cli::PROBLEM;
        }
        $code = $opts['channel'] ?? null;
        if (!is_string($code) || $code === '') {
            throw new InvalidArgumentException('--channel=<code> is required');
        }
        $channelId = $cli->db->value('SELECT id FROM channel WHERE code = ?', [$code]);
        if ($channelId === null) {
            throw new InvalidArgumentException("no channel {$code}");
        }
        $channelId = (int) $channelId;
        $svc = new LandingPages($cli->db);
        $t0 = hrtime(true);
        $plan = $svc->plan($channelId, null, array_key_exists('include-ignored', $opts));
        if (array_key_exists('list', $opts)) {
            foreach ($plan['rows'] as $r) {
                fwrite(STDOUT, sprintf("%s listing %d variant %s (%s): %s | %s - %s%s\n", $r['outcome'], $r['listing_id'], $r['variant'], $r['status'],
                    (string) $r['product_title'], (string) $r['variant_title'], $r['reason'] ?? implode('+', $r['not']),
                    $r['reason'] === LandingRule::SAME_PAGE ? ' (' . LandingRule::VERSION . ', ' . ($r['evidence']['options_count'] ?? 0) . ' options, page by '
                        . ($r['evidence']['page'] ?? '?') . ')' : ''));
            }
        }
        $kv = static fn (array $a): string => $a === [] ? '-' : implode(',', array_map(static fn (string $k, int $v): string => "{$k}:{$v}", array_keys($a), array_values($a)));
        $summary = ['channel' => $code, 'rule' => LandingRule::VERSION, 'apply' => $apply, 'include_ignored' => array_key_exists('include-ignored', $opts),
            'counts' => $plan['counts'], 'reasons' => $plan['reasons'], 'not' => $plan['not']];
        $result = null;
        if ($apply) {
            $caller = Caller::system('landing_pages');
            $result = $svc->apply($caller, $plan, 'tool');
            $svc->audit($caller, $channelId, $summary + ['marked' => $result['marked'], 'refused' => $result['refused']]);
        }
        $cli->log(sprintf('%schannel=%s rule=%s would_mark=%d (%s) already=%d left: linked=%d said_real=%d one_at_a_time=%d waiting=%d ignored=%d no_longer=%d; '
            . 'not_landing=%d (%s)%s ms=%d',
            $apply ? '' : 'DRY RUN (nothing written) ', $code, LandingRule::VERSION, $plan['counts']['mark'], $kv($plan['reasons']), $plan['counts']['already'],
            $plan['counts']['linked'], $plan['counts']['said_real'], $plan['counts']['one_at_a_time'], $plan['counts']['waiting'], $plan['counts']['ignored'],
            $plan['counts']['no_longer'], $plan['counts']['not_landing'], $kv($plan['not']),
            $result === null ? '' : sprintf(' marked=%d refused=%s', $result['marked'], $kv($result['refused'])), intdiv(hrtime(true) - $t0, 1_000_000)));
        return $result !== null && $result['refused'] !== [] ? Cli::PROBLEM : Cli::OK;
    }, true, true));
