<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Company\CompanyDetails;
use CW\Documents\NumberSeries;
use CW\Output\CsvWriter;
use CW\Ui\Context;
use CW\Ui\HtmlResponse;

/**
 * Reference lists (reference.view, every role): the reason codes (I22; also as CSV for Excel), the number series
 * with the document types and their review rules (I19, I20), and the settings with the VAT codes (I38-I41). Read-only:
 * these lists are changed by a migration or an admin tool on the server only (the app login has SELECT on reason_code,
 * document_type, app_setting and vat_code, and moves number_series.last_no only by posting). The settings page starts with
 * the company details (their own screen since 0013: CompanyController, I90).
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
                'approval' => match ($r['approval_rule']) {
                    'none' => 'none',
                    'over_value' => 'net value above £' . number_format((int) $r['approval_limit_units']),
                    default => 'positive units without a supplier document above ' . (int) $r['approval_limit_units'],
                },
                'due_days' => (int) $r['review_due_days'],
                'live' => $docs->handler((string) $r['code']) !== null,
                'phase' => (string) $r['phase'],
            ];
        }
        uksort($rows, static fn (string $a, string $b): int => array_search($a, self::TYPE_ORDER, true) <=> array_search($b, self::TYPE_ORDER, true));
        return $ctx->page('series', ['series' => array_values($rows)], 200, ['title' => 'Number series', 'active' => 'series']);
    }

    /**
     * The settings (app_setting, I38-I41): every setting with its value, whether the owner has confirmed it (provisional),
     * the owner decision it implements and when it changed; the document types' review and approval rules; the VAT codes.
     * Read-only: settings change with bin/settings.php --admin on the server, document rules by migration and
     * bin/document_rules.php --admin (I57), VAT codes by migration.
     */
    public function settings(Context $ctx): HtmlResponse
    {
        $rules = [];
        foreach ($ctx->db->all('SELECT * FROM document_type') as $r) {
            $rules[(string) $r['code']] = [
                'code' => (string) $r['code'], 'name' => (string) $r['name'],
                'review' => match ($r['review_rule']) {
                    'all' => 'every document',
                    'over_limit' => 'above ' . (int) $r['review_limit_units'] . ' units',
                    default => 'none',
                },
                'due_days' => (int) $r['review_due_days'],
                'approval' => match ($r['approval_rule']) {
                    'none' => 'none',
                    'positive_without_supplier_doc' => 'positive units without a supplier document above ' . (int) $r['approval_limit_units'],
                    'over_value' => 'net value above £' . number_format((int) $r['approval_limit_units']),
                    default => str_replace('_', ' ', (string) $r['approval_rule']) . ' above ' . (int) $r['approval_limit_units'],
                },
                // reject_action (0010, I49): 'reverse' (I19) or 'record' (PO: the rejection is recorded, the order stands).
                'reject' => ($r['reject_action'] ?? 'reverse') === 'record' ? 'recorded only (the document stands)' : 'the document is reversed',
            ];
        }
        uksort($rules, static fn (string $a, string $b): int => array_search($a, self::TYPE_ORDER, true) <=> array_search($b, self::TYPE_ORDER, true));
        $company = $ctx->company()->current();
        return $ctx->page('settings', [
            'company' => ['legal_name' => $company['legal_name'], 'confirmed' => $company['confirmed'], 'version' => $company['version'],
                'missing' => CompanyDetails::missing($company), 'canEdit' => $ctx->me()->can('company.edit')],
            'settings' => $ctx->settings()->all(),
            'rules' => array_values($rules),
            'vat' => $ctx->db->all('SELECT code, label, rate_percent, is_active FROM vat_code ORDER BY sort_order, code'),
        ], 200, ['title' => 'Settings', 'active' => 'settings']);
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
