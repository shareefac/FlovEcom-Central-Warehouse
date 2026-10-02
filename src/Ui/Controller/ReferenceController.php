<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Documents\NumberSeries;
use CW\Output\CsvWriter;
use CW\Ui\Context;
use CW\Ui\HtmlResponse;

/**
 * Reference lists (reference.view, every role): the reason codes (I22; also as CSV for Excel) and the number series
 * with the document types and their review rules (I19, I20). Read-only: both lists are changed by a migration only
 * (the app login has SELECT on reason_code and document_type, and moves number_series.last_no only by posting).
 */
final class ReferenceController
{
    /** The order the screens list the document types in (the flow of goods, not the alphabet). */
    public const TYPE_ORDER = ['PO', 'GRN', 'SINV', 'DN', 'CNT', 'ADJ', 'WO', 'TRD'];

    public function reasons(Context $ctx): HtmlResponse
    {
        return $ctx->page('reasons', ['reasons' => self::reasonRows($ctx)], 200, ['title' => 'Reason codes', 'active' => 'reasons']);
    }

    public function reasonsCsv(Context $ctx): HtmlResponse
    {
        $csv = new CsvWriter([['code', 'text'], ['label', 'text'], ['applies_to', 'text'], ['direction', 'text'], ['needs_note', 'text'],
            ['free_gift', 'text'], ['system_only', 'text'], ['active', 'text'], ['sort_order', 'number']]);
        foreach (self::reasonRows($ctx) as $r) {
            $csv->add([$r['code'], $r['label'], $r['applies_to'], $r['direction'], $r['needs_note'], $r['is_gift'], $r['system_only'], $r['is_active'],
                $r['sort_order']]);
        }
        return FilesController::download($csv->output(), 'text/csv; charset=utf-8', 'reason-codes.csv');
    }

    public function series(Context $ctx): HtmlResponse
    {
        $docs = $ctx->documents();
        $rows = [];
        foreach ($ctx->db->all(
            'SELECT t.code, t.name, t.phase, t.review_rule, t.review_limit_units, t.approval_rule, t.approval_limit_units, t.review_due_days, '
            . 'n.prefix, n.last_no, n.pad FROM document_type t JOIN number_series n ON n.prefix = t.prefix',
        ) as $r) {
            $last = (int) $r['last_no'];
            $rows[(string) $r['code']] = [
                'code' => (string) $r['code'], 'name' => (string) $r['name'], 'prefix' => (string) $r['prefix'],
                'last' => $last > 0 ? NumberSeries::format((string) $r['prefix'], $last, (int) $r['pad']) : null,
                'next' => NumberSeries::format((string) $r['prefix'], $last + 1, (int) $r['pad']),
                'review' => match ($r['review_rule']) {
                    'all' => 'every document',
                    'over_limit' => 'above ' . (int) $r['review_limit_units'] . ' units',
                    default => 'none',
                },
                'approval' => $r['approval_rule'] === 'none' ? 'none'
                    : 'positive units without a supplier document above ' . (int) $r['approval_limit_units'],
                'due_days' => (int) $r['review_due_days'],
                'live' => $docs->handler((string) $r['code']) !== null,
                'phase' => (string) $r['phase'],
            ];
        }
        uksort($rows, static fn (string $a, string $b): int => array_search($a, self::TYPE_ORDER, true) <=> array_search($b, self::TYPE_ORDER, true));
        return $ctx->page('series', ['series' => array_values($rows)], 200, ['title' => 'Number series', 'active' => 'series']);
    }

    /** @return list<array<string, mixed>> */
    private static function reasonRows(Context $ctx): array
    {
        $yes = static fn (mixed $v): string => (int) $v === 1 ? 'yes' : 'no';
        $out = [];
        foreach ($ctx->db->all('SELECT code, label, applies_to, direction, needs_note, is_gift, system_only, is_active, sort_order FROM reason_code '
            . 'ORDER BY sort_order, code') as $r) {
            $out[] = ['code' => (string) $r['code'], 'label' => (string) $r['label'], 'applies_to' => str_replace(',', ', ', (string) $r['applies_to']),
                'direction' => (string) $r['direction'], 'needs_note' => $yes($r['needs_note']), 'is_gift' => $yes($r['is_gift']),
                'system_only' => $yes($r['system_only']), 'is_active' => $yes($r['is_active']), 'sort_order' => (int) $r['sort_order']];
        }
        return $out;
    }
}
