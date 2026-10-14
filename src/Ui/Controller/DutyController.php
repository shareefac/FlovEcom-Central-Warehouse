<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Duty\DutyRegister;
use CW\Output\CsvWriter;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Router;
use CW\Ui\Words;

/**
 * Purchasing › Goods in › Duty register (pack H, IM13; docs/decisions.md PH15-PH18): the vaping duty register built from the receipts,
 * with filters (the arrival days, the supplier, the stamp state, still in force or reversed, words), a page of duty.page_rows lines,
 * the download (CSV, at most duty.export_max_rows lines, the same filters), and the unstamped stock with where it is (an alarm from
 * duty.unstamped_zero_from). Read only: duty.view.
 */
final class DutyController
{
    public const PATH = '/ui/receiving/duty';

    public static function routes(Router $r): void
    {
        $c = new self();
        $r->add('GET', self::PATH, 'duty.view', $c->index(...));
        $r->add('GET', self::PATH . '.csv', 'duty.view', $c->csv(...));
    }

    public function index(Context $ctx): HtmlResponse
    {
        $reg = new DutyRegister($ctx->db, $ctx->settings());
        $f = DutyRegister::filters(self::query($ctx));
        $size = (int) ($ctx->settings()->has('duty.page_rows') ? $ctx->settings()->get('duty.page_rows') : 200);
        $size = max(1, $size);
        $total = $reg->count($f);
        $pages = max(1, (int) ceil($total / $size));
        $page = min($pages, max(1, (int) ($ctx->req->param('page') ?? 1)));
        $link = static fn (int $p): string => Html::url(self::PATH, array_filter(['from' => $f['from'], 'to' => $f['to'], 'supplier' => $f['supplier'],
            'stamp' => $f['stamp'], 'state' => $f['state'], 'q' => $f['q'] === '' ? null : $f['q'], 'page' => $p > 1 ? $p : null], static fn (mixed $v): bool => $v !== null));
        return $ctx->page('duty_register', [
            'rows' => $reg->rows($f, $size, ($page - 1) * $size),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'prev' => $page > 1 ? $link($page - 1) : null,
            'next' => $page < $pages ? $link($page + 1) : null,
            'f' => $f,
            'filtered' => $f['from'] !== null || $f['to'] !== null || $f['supplier'] !== null || $f['stamp'] !== null || $f['state'] !== null || $f['q'] !== '',
            'suppliers' => $reg->suppliers(),
            'stampStates' => DutyRegister::STAMP_STATES,
            'allLines' => $reg->lines() === 'all',
            'unstamped' => $reg->unstamped(),
            'csv' => Html::url(self::PATH . '.csv', array_filter(['from' => $f['from'], 'to' => $f['to'], 'supplier' => $f['supplier'], 'stamp' => $f['stamp'],
                'state' => $f['state'], 'q' => $f['q'] === '' ? null : $f['q']], static fn (mixed $v): bool => $v !== null)),
            'max' => (int) ($ctx->settings()->has('duty.export_max_rows') ? $ctx->settings()->get('duty.export_max_rows') : 20000),
        ], 200, ['title' => Words::SEGMENT['duty']]);
    }

    /** The register as CSV: the same filters, at most duty.export_max_rows lines (newest first), times in UTC as the other files are. */
    public function csv(Context $ctx): HtmlResponse
    {
        $reg = new DutyRegister($ctx->db, $ctx->settings());
        $f = DutyRegister::filters(self::query($ctx));
        $max = max(1, (int) ($ctx->settings()->has('duty.export_max_rows') ? $ctx->settings()->get('duty.export_max_rows') : 20000));
        $cols = [];
        foreach (['received_at_utc', 'receipt', 'receipt_state', 'supplier', 'supplier_invoice', 'delivery_note', 'cw_number', 'item', 'barcode', 'units_on_paperwork',
            'units_accepted', 'units_quarantined', 'units_refused', 'units_short', 'stamp_needed', 'stamp_state', 'stamp_on_outer_pack', 'stamp_type', 'stamp_code',
            'units_stamped', 'units_unstamped', 'unstamped_action', 'supplier_declaration', 'declaration_file', 'ml_per_unit', 'expected_duty_gbp', 'checked_by',
            'checked_at_utc', 'incidents'] as $c) {
            $cols[] = [$c, in_array($c, ['units_on_paperwork', 'units_accepted', 'units_quarantined', 'units_refused', 'units_short', 'units_stamped', 'units_unstamped',
                'ml_per_unit', 'expected_duty_gbp', 'incidents'], true) ? 'number' : 'text'];
        }
        $out = new CsvWriter($cols);
        $yes = static fn (?bool $b): string => $b === null ? '' : ($b ? 'yes' : 'no');
        $done = 0;
        $chunk = 1000;
        while ($done < $max) {
            $rows = $reg->rows($f, min($chunk, $max - $done), $done);
            foreach ($rows as $r) {
                $out->add([(string) $r['received_at'], (string) $r['number'], (string) $r['status'], (string) $r['supplier'], (string) ($r['supplier_invoice'] ?? ''),
                    (string) ($r['delivery_note'] ?? ''), (string) ($r['sku_code'] ?? ''), (string) ($r['sku_name'] ?? ''), (string) ($r['barcode'] ?? ''), $r['units'],
                    $r['accepted_units'], $r['quarantine_units'], $r['refused_units'], $r['short_units'], $yes($r['stamp_required']), (string) $r['stamp_state'],
                    $yes($r['stamp_on_pack']), (string) ($r['stamp_type'] ?? ''), (string) ($r['stamp_code'] ?? ''), $r['stamped_units'], $r['unstamped_total'],
                    (string) ($r['unstamped_action'] ?? ''), (string) ($r['pre_october_evidence'] ?? ''), $yes($r['evidence_file']),
                    $r['duty_ml'] === null ? null : (string) $r['duty_ml'], $r['expected_duty'] === null ? null : (string) $r['expected_duty'],
                    (string) ($r['checked_by'] ?? ''), (string) ($r['checked_at'] ?? ''), $r['incidents']]);
            }
            $done += count($rows);
            if (count($rows) < $chunk) {
                break;
            }
        }
        return FilesController::download($out->output(), 'text/csv; charset=utf-8', 'duty-register.csv');
    }

    /** @return array<string, mixed> the page's query values */
    private static function query(Context $ctx): array
    {
        $out = [];
        foreach (['from', 'to', 'supplier', 'stamp', 'state', 'q'] as $k) {
            $out[$k] = $ctx->req->param($k);
        }
        return $out;
    }
}
