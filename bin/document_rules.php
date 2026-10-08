<?php

declare(strict_types=1);

/**
 * Changes a document type's review and approval rules (document_type; docs/decisions.md I57, Y10, docs/ops.md "Document rules"):
 * the same change the Approval rules page makes (CW\Admin\DocumentRules), on the server, as the break-glass.
 *
 *   php bin/document_rules.php --type=<CODE> [--approval-limit=N] [--approval=on|off] [--review-rule=all|over_limit|none]
 *       [--review-limit=N] [--review-due-days=N] [--reject=reverse|record] --reason="<3..500 characters>" --admin [--db=<schema>]
 *
 * On the server a change needs the admin login (--admin; refused before connecting without it, exit 1). The rules are checked
 * first, with a usage error (exit 2): an --approval-limit or --approval only on a type that has a blocking approval (PO: whole GBP
 * of the net total; ADJ: units), a --review-limit only on a type whose review rule is (or becomes) over_limit, and over_limit
 * needs a limit; limits 0..2,000,000,000; review due days 1..120. A change is written in one transaction (the row FOR UPDATE),
 * recorded as the type's next history version (config_change) and audited document_type.change {type, before, after, reason,
 * version}; the same values again write nothing ("unchanged"). Running screens and jobs read the rules per request: the next
 * posting uses them.
 *
 * Exit codes: 0 done (also "unchanged") · 1 refused (no --admin) · 2 usage, unknown type or a value the rules refuse ·
 * 3 cannot run (database, schema).
 */

use CW\Admin\DocumentRules;
use CW\Caller;
use CW\CwException;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

$usage = 'usage: php bin/document_rules.php --type=<CODE> [--approval-limit=N] [--approval=on|off] [--review-rule=all|over_limit|none] [--review-limit=N] '
    . '[--review-due-days=N] [--reject=reverse|record] --reason="..." --admin';

// Refused before connecting: without --admin the tool would connect as the app login, which may only read document_type.
$pre = getopt('', ['type:', 'admin', 'help']);
if (is_array($pre) && !isset($pre['help']) && !array_key_exists('admin', $pre)) {
    fwrite(STDERR, gmdate('Y-m-d\TH:i:s\Z') . " document_rules ERROR a document rule change needs --admin (on the server; staff change rules on the Approval rules page)\n");
    exit(Cli::PROBLEM);
}

exit(Cli::main('document_rules', ['type:', 'approval-limit:', 'approval:', 'review-rule:', 'review-limit:', 'review-due-days:', 'reject:', 'reason:'], $usage,
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
        if ($rule !== null && !in_array($rule, DocumentRules::REVIEW_RULES, true)) {
            throw new InvalidArgumentException('--review-rule is all, over_limit or none');
        }
        $approval = $one('approval');
        if ($approval !== null && !in_array($approval, ['on', 'off'], true)) {
            throw new InvalidArgumentException('--approval is on or off');
        }
        $reject = $one('reject');
        if ($reject !== null && !in_array($reject, DocumentRules::REJECT_ACTIONS, true)) {
            throw new InvalidArgumentException('--reject is reverse or record');
        }
        $change = array_filter(['review_rule' => $rule, 'review_limit_units' => $reviewLimit, 'review_due_days' => $dueDays,
            'approval' => $approval === null ? null : $approval === 'on', 'approval_limit_units' => $approvalLimit, 'reject_action' => $reject],
            static fn (mixed $v): bool => $v !== null);
        if ($change === []) {
            throw new InvalidArgumentException('give at least one of --approval-limit, --approval, --review-rule, --review-limit, --review-due-days, --reject');
        }
        $reason = $one('reason');
        if ($reason === null || mb_strlen($reason) < 3 || mb_strlen($reason) > 500 || !mb_check_encoding($reason, 'UTF-8')) {
            throw new InvalidArgumentException('give --reason="..." of 3 to 500 characters (it is audited)');
        }
        try {
            $result = (new DocumentRules($cli->db))->set(Caller::system('document_rules'), $type, $change, $reason);
        } catch (CwException $e) {
            // An unknown type, a limit or rule the table refuses: usage errors (exit 2), nothing written.
            throw new InvalidArgumentException("{$e->errorCode}: {$e->getMessage()}");
        }
        if (!$result['changed']) {
            $cli->log("{$type} unchanged");
            return Cli::OK;
        }
        $diff = [];
        foreach (DocumentRules::FIELDS as $f) {
            if ($result['before'][$f] !== $result['after'][$f]) {
                $diff[] = "{$f} " . ($result['before'][$f] ?? 'NULL') . ' -> ' . ($result['after'][$f] ?? 'NULL');
            }
        }
        $cli->log("{$type} changed: " . implode(', ', $diff) . " (version {$result['version']})");
        return Cli::OK;
    }, false));
