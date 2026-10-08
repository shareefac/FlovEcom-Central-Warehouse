<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Admin\AuditSearch;
use CW\Admin\Sites;
use CW\Clock;
use CW\CwException;
use CW\Output\CsvWriter;
use CW\Ops\IntegrityRuns;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * The read-only system pages of the set-it-yourself pack: the Websites page (G05, system.view; Admin\Sites), the Safety checks
 * page (G36, system.view; Ops\IntegrityRuns, written by bin/invariants.php) and the audit log with its CSV file (G07, audit.view;
 * Admin\AuditSearch). Nothing here changes anything; the websites' commands are shown only in the folded technical details.
 */
final class SystemController
{
    public function sites(Context $ctx): HtmlResponse
    {
        $rows = [];
        foreach ((new Sites($ctx->db))->all() as $s) {
            $rows[] = $s + [
                'contact' => $s['heartbeat'] === null ? Words::SITES['never'] : Words::say('SITES', 'ago', Html::when($s['heartbeat']), self::duration((int) $s['age'])),
                'no_contact' => !$s['stale'] ? null
                    : ($s['age'] === null ? Words::SITES['no_contact_ever'] : Words::say('SITES', 'no_contact', self::duration((int) $s['age']))),
                'queue_text' => $s['outbox'] === null || $s['outbox']['depth'] === null ? Words::SITES['not_reported']
                    : ((int) $s['outbox']['depth'] === 0 ? Words::SITES['nothing']
                        : Words::say('SITES', 'queue_line', (int) $s['outbox']['depth'], self::duration((int) ($s['outbox']['oldest'] ?? 0)))),
                'dead_text' => $s['outbox'] === null || $s['outbox']['dead'] === null ? Words::SITES['not_reported'] : number_format((int) $s['outbox']['dead']),
                'feed_text' => $s['feed']['behind'] === null ? Words::SITES['not_reported']
                    : Words::say('SITES', 'feed_line', (int) $s['feed']['behind'], $s['feed']['applied_at'] === null ? Words::SITES['never'] : Html::when($s['feed']['applied_at'])),
                'sells_text' => implode(', ', array_map(static fn (array $w): string => $w['name'], $s['warehouses']['selling'])),
                'writer_text' => Words::SITES[$s['writer_live'] ? 'writer_on' : ($s['writer'] ? 'writer_waiting' : 'writer_off')],
                'commands' => array_map(static fn (array $c): array => ['what' => Words::of('SITE_COMMAND', $c['what']), 'command' => $c['command']], Sites::commands($s)),
            ];
        }
        return $ctx->page('sites', ['sites' => $rows], 200, ['title' => Words::MENU['sites'], 'active' => 'sites']);
    }

    public function checks(Context $ctx): HtmlResponse
    {
        $runs = new IntegrityRuns($ctx->db);
        $recent = $runs->recent(30);
        $latest = $recent[0] ?? null;
        $hours = $runs->latest()['hours'] ?? null;
        return $ctx->page('integrity', [
            'latest' => $latest === null ? null : $latest + [
                'seconds' => (int) round(((int) ($latest['stats']['ms'] ?? 0)) / 1000),
                'cut' => $latest['problems'] > count($latest['details']) ? count($latest['details']) : null,
            ],
            'stale' => $hours !== null && $hours >= IntegrityRuns::STALE_HOURS,
            'earlier' => array_slice($recent, 1),
        ], 200, ['title' => Words::MENU['integrity'], 'active' => 'integrity']);
    }

    public function audit(Context $ctx): HtmlResponse
    {
        $search = new AuditSearch($ctx->db);
        $today = Clock::now()->format('Y-m-d');
        $error = null;
        try {
            $f = AuditSearch::filters($ctx->req->query, $today);
            $page = $search->page($f);
            $status = 200;
        } catch (CwException $e) {
            $f = AuditSearch::filters([], $today);
            $page = ['rows' => [], 'next' => null];
            $error = Words::say('AUDIT', 'bad_filter', $e->getMessage());
            $status = $e->httpStatus;
        }
        $query = array_filter(['from' => $f['from'], 'to' => $f['to'], 'who' => $f['who'], 'record' => $f['record'], 'id' => $f['id'], 'action' => $f['action']],
            static fn (?string $v): bool => $v !== null);
        $rows = array_map(static fn (array $r): array => $r + self::described($r), $page['rows']);
        return $ctx->page('audit', [
            'f' => $f,
            'rows' => $rows,
            'next' => $page['next'] === null ? null : Html::url('/ui/system/audit', $query + ['before' => (string) $page['next']]),
            'csv' => Html::url('/ui/system/audit.csv', $query),
            'whoOptions' => array_map(static fn (array $o): array => $o + ['selected' => $o['value'] === (string) $f['who']], [
                ['value' => '', 'name' => Words::AUDIT['everyone']],
                ...array_map(static fn (array $p): array => ['value' => 'staff:' . $p['id'], 'name' => $p['name']], $search->people()),
                ['value' => 'system', 'name' => Words::AUDIT['system']],
                ['value' => 'site', 'name' => Words::AUDIT['sites']],
            ]),
            'records' => Words::AUDIT_RECORD,
            'families' => Words::AUDIT_FAMILY,
            'error' => $error,
            'filtered' => $f['who'] !== null || $f['record'] !== null || $f['action'] !== null || $f['id'] !== null,
        ], $status, ['title' => Words::MENU['audit'], 'active' => 'audit']);
    }

    /**
     * The search as a CSV file for Excel (formula-safe, I25): every entry of the search, newest first, at most AuditSearch::CSV_MAX
     * (the last line says so when there were more). Times in UTC, as the database keeps them (the column name says so).
     */
    public function auditCsv(Context $ctx): HtmlResponse
    {
        try {
            $f = AuditSearch::filters($ctx->req->query, Clock::now()->format('Y-m-d'));
        } catch (CwException $e) {
            return $ctx->error(400, 'bad_filter', Words::say('AUDIT', 'bad_filter', $e->getMessage()), ['/ui/system/audit', Words::MENU['audit']]);
        }
        $out = (new AuditSearch($ctx->db))->export($f);
        $csv = new CsvWriter([['id', 'number'], ['when_utc', 'text'], ['actor', 'text'], ['person', 'text'], ['website', 'text'], ['action', 'text'],
            ['what', 'text'], ['record_type', 'text'], ['record_id', 'text'], ['ip', 'text'], ['detail', 'text']]);
        foreach ($out['rows'] as $r) {
            $d = self::described($r);
            $csv->add([$r['id'], substr($r['created_at'], 0, 19), $r['actor'], $r['person'], $r['site'], $r['action'], $d['what'], $r['entity_type'], $r['entity_id'],
                $r['ip'], $r['detail']]);
        }
        if ($out['more']) {
            $csv->add([null, null, null, null, null, null, sprintf('only the newest %d entries: narrow the search for the rest', AuditSearch::CSV_MAX), null, null, null, null]);
        }
        return FilesController::download($csv->output(), 'text/csv; charset=utf-8', 'audit-log-' . $f['from'] . '-to-' . $f['to'] . '.csv');
    }

    /**
     * An entry in words: who (a name, "set up by CW", a website), what was done (AUDIT_ACTION, else the kind AUDIT_FAMILY), the
     * kind of record (AUDIT_RECORD).
     *
     * @param array<string, mixed> $r
     * @return array{who: string, what: string, record_words: string}
     */
    public static function described(array $r): array
    {
        $action = (string) $r['action'];
        $family = strstr($action, '.', true);
        $who = match (true) {
            $r['person'] !== null => (string) $r['person'],
            $r['site'] !== null => (string) $r['site'],
            default => Words::AUDIT['cw'],
        };
        return [
            'who' => $who,
            'what' => Words::AUDIT_ACTION[$action] ?? Words::AUDIT_FAMILY[$family === false ? $action : $family] ?? Words::of('AUDIT_ACTION', str_replace('.', '_', $action)),
            'record_words' => $r['entity_type'] === null ? '' : Words::of('AUDIT_RECORD', (string) $r['entity_type']),
        ];
    }

    /** "45 seconds", "3 minutes", "2 hours", "4 days". */
    public static function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        return match (true) {
            $seconds < 120 => Words::say('SITES', 'seconds', $seconds),
            $seconds < 7200 => Words::say('SITES', 'minutes', intdiv($seconds, 60)),
            $seconds < 172800 => Words::say('SITES', 'hours', intdiv($seconds, 3600)),
            default => Words::say('SITES', 'days', intdiv($seconds, 86400)),
        };
    }
}
