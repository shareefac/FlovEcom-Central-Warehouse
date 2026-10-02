<?php

declare(strict_types=1);

/**
 * Changes a document type's review and approval rules (document_type; docs/decisions.md I57, spec §2.3, docs/ops.md
 * "Document rules"): how owner decision 11 moves the PO approval limit (provisional: £10,000 net) or a review rule.
 *
 *   php bin/document_rules.php --type=<CODE> [--approval-limit=N] [--review-rule=all|over_limit|none] [--review-limit=N]
 *       [--review-due-days=N] --reason="<3..500 characters>" --admin [--db=<schema>]
 *
 * document_type is read-only for the app login (Grants::READ_ONLY), so a change needs the admin login (--admin; refused
 * before connecting without it, exit 1). The table's CHECKs are enforced first, with a usage error (exit 2): an
 * --approval-limit only on a type that has an approval rule (PO: whole GBP of the net total; ADJ: units), a --review-limit
 * only on a type whose review rule is (or becomes) over_limit, and over_limit needs a limit; limits 0..2,000,000,000;
 * review due days 1..120. A change is written in one transaction (the row FOR UPDATE) and audited document_type.change
 * {type, before, after, reason}; the same values again write nothing ("unchanged"). Running screens and jobs read the
 * rules per request: the next posting uses them.
 *
 * Exit codes: 0 done (also "unchanged") · 1 refused (no --admin) · 2 usage, unknown type or a value the rules refuse ·
 * 3 cannot run (database, schema).
 */

use CW\Audit;
use CW\Caller;
use CW\Db;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

$usage = 'usage: php bin/document_rules.php --type=<CODE> [--approval-limit=N] [--review-rule=all|over_limit|none] [--review-limit=N] '
    . '[--review-due-days=N] --reason="..." --admin';

// Refused before connecting: without --admin the tool would connect as the app login, which may only read document_type.
$pre = getopt('', ['type:', 'admin', 'help']);
if (is_array($pre) && !isset($pre['help']) && !array_key_exists('admin', $pre)) {
    fwrite(STDERR, gmdate('Y-m-d\TH:i:s\Z') . " document_rules ERROR a document rule change needs --admin (cw_app has SELECT only on document_type)\n");
    exit(Cli::PROBLEM);
}

exit(Cli::main('document_rules', ['type:', 'approval-limit:', 'review-rule:', 'review-limit:', 'review-due-days:', 'reason:'], $usage,
    static function (Cli $cli, array $opts): int {
        $one = static function (string $k) use ($opts): ?string {
            $v = $opts[$k] ?? null;
            if (is_array($v)) {
                throw new InvalidArgumentException("give --{$k} once");
            }
            return is_string($v) ? trim($v) : null;
        };
        $int = static function (?string $v, string $k, int $min, int $max): ?int {
            if ($v === null) {
                return null;
            }
            if (preg_match('/^\d{1,10}$/D', $v) !== 1 || (int) $v < $min || (int) $v > $max) {
                throw new InvalidArgumentException("--{$k} must be a whole number from {$min} to " . number_format($max));
            }
            return (int) $v;
        };
        $type = $one('type');
        if ($type === null || preg_match('/^[A-Z]{2,8}$/D', $type) !== 1) {
            throw new InvalidArgumentException('give --type=<document type code> (PO, GRN, ADJ, ...)');
        }
        $approvalLimit = $int($one('approval-limit'), 'approval-limit', 0, 2_000_000_000);
        $reviewLimit = $int($one('review-limit'), 'review-limit', 0, 2_000_000_000);
        $dueDays = $int($one('review-due-days'), 'review-due-days', 1, 120);
        $rule = $one('review-rule');
        if ($rule !== null && !in_array($rule, ['all', 'over_limit', 'none'], true)) {
            throw new InvalidArgumentException('--review-rule is all, over_limit or none');
        }
        if ($approvalLimit === null && $reviewLimit === null && $dueDays === null && $rule === null) {
            throw new InvalidArgumentException('give at least one of --approval-limit, --review-rule, --review-limit, --review-due-days');
        }
        $reason = $one('reason');
        if ($reason === null || mb_strlen($reason) < 3 || mb_strlen($reason) > 500 || !mb_check_encoding($reason, 'UTF-8')) {
            throw new InvalidArgumentException('give --reason="..." of 3 to 500 characters (it is audited)');
        }
        $fields = ['review_rule', 'review_limit_units', 'review_due_days', 'approval_rule', 'approval_limit_units', 'reject_action'];
        $result = $cli->db->transaction(static function (Db $db) use ($type, $approvalLimit, $reviewLimit, $dueDays, $rule, $reason, $fields): array {
            $row = $db->one('SELECT * FROM document_type WHERE code = ? FOR UPDATE', [$type])
                ?? throw new InvalidArgumentException("there is no document type {$type}");
            $before = [];
            foreach ($fields as $f) {
                $before[$f] = $row[$f];
            }
            $after = $before;
            if ($approvalLimit !== null) {
                if ($row['approval_rule'] === 'none') {
                    throw new InvalidArgumentException("{$type} has no approval rule: --approval-limit does not apply to it");
                }
                $after['approval_limit_units'] = $approvalLimit;
            }
            if ($rule !== null) {
                $after['review_rule'] = $rule;
            }
            if ($reviewLimit !== null) {
                if ($after['review_rule'] !== 'over_limit') {
                    throw new InvalidArgumentException("{$type}'s review rule is {$after['review_rule']}: --review-limit applies only to over_limit");
                }
                $after['review_limit_units'] = $reviewLimit;
            }
            if ($after['review_rule'] === 'over_limit' && $after['review_limit_units'] === null) {
                throw new InvalidArgumentException('the over_limit review rule needs --review-limit');
            }
            if ($dueDays !== null) {
                $after['review_due_days'] = $dueDays;
            }
            if ($after === $before) {
                return ['changed' => false, 'before' => $before, 'after' => $after];
            }
            $db->exec('UPDATE document_type SET review_rule = ?, review_limit_units = ?, review_due_days = ?, approval_limit_units = ? WHERE code = ?',
                [$after['review_rule'], $after['review_limit_units'], $after['review_due_days'], $after['approval_limit_units'], $type]);
            Audit::write($db, Caller::system('document_rules'), 'document_type.change', 'document_type', $type, null,
                ['type' => $type, 'before' => $before, 'after' => $after, 'reason' => $reason]);
            return ['changed' => true, 'before' => $before, 'after' => $after];
        });
        if (!$result['changed']) {
            $cli->log("{$type} unchanged");
            return Cli::OK;
        }
        $diff = [];
        foreach ($fields as $f) {
            if ($result['before'][$f] !== $result['after'][$f]) {
                $diff[] = "{$f} " . ($result['before'][$f] ?? 'NULL') . ' -> ' . ($result['after'][$f] ?? 'NULL');
            }
        }
        $cli->log("{$type} changed: " . implode(', ', $diff));
        return Cli::OK;
    }, false));
