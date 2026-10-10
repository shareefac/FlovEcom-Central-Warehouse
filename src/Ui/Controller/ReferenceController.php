<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Admin\ApprovalRules;
use CW\Company\CompanyDetails;
use CW\Documents\NumberSeries;
use CW\Output\CsvWriter;
use CW\Ui\ConfigWords;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * Reference lists (reference.view, every role): the reason codes (I22; also as CSV for Excel), the number series
 * with the document types and their review rules (I19, I20), and the settings with the VAT codes (I38-I41). Since the
 * set-it-yourself pack (0019, Y4, Y12) each setting opens its own page (SettingsController) and each reason too
 * (ReasonsController), where an admin or a reviewer changes them; the approval rules have their own page (ApprovalsController).
 * The settings page starts with the company details (their own screen since 0013: CompanyController, I90).
 */
final class ReferenceController
{
    /** The order the screens list the document types in (the flow of goods, not the alphabet). */
    public const TYPE_ORDER = ['PO', 'GRN', 'SIN', 'SOUT', 'ADJ', 'TRF', 'REL', 'SINV', 'DN', 'CNT', 'WO', 'TRD'];

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
                'kind' => Words::docType((string) $r['code'], true, (string) $r['name']),
                'review' => match ($r['review_rule']) {
                    'all' => Words::SETTINGS_PAGE['every'],
                    'over_limit' => Words::say('SETTINGS_PAGE', 'over_items', (int) $r['review_limit_units']),
                    default => Words::SETTINGS_PAGE['none'],
                },
                'approval' => match ($r['approval_rule']) {
                    'none' => Words::SETTINGS_PAGE['none'],
                    'over_value' => Words::say('SETTINGS_PAGE', 'over_value', (int) $r['approval_limit_units']),
                    default => Words::say('SETTINGS_PAGE', 'over_units', (int) $r['approval_limit_units']),
                },
                'due_days' => (int) $r['review_due_days'],
                'live' => $docs->handler((string) $r['code']) !== null,
            ];
        }
        uksort($rows, static fn (string $a, string $b): int => array_search($a, self::TYPE_ORDER, true) <=> array_search($b, self::TYPE_ORDER, true));
        return $ctx->page('series', ['series' => array_values($rows)], 200, ['title' => Words::title('series'), 'active' => 'series']);
    }

    /**
     * Settings and lists (app_setting, I38-I41; plan §6.33): every setting by topic with its plain name, value, whether the
     * owner has agreed it and when it changed (UK time; "when CW was set up" for the set-up's own rows); "Who checks what" for
     * the kinds of record in use; the VAT codes. Read-only: settings change with bin/settings.php --admin on the server,
     * document rules by migration and bin/document_rules.php --admin (I57), VAT codes by migration. The screen never names
     * those tools: it says to ask the admin.
     */
    public function settings(Context $ctx): HtmlResponse
    {
        $docs = $ctx->documents();
        $rules = [];
        foreach ($ctx->db->all('SELECT * FROM document_type') as $r) {
            $code = (string) $r['code'];
            if ($docs->handler($code) === null) {
                continue; // only the kinds in use today (plan F421)
            }
            $limit = (int) $r['approval_limit_units'];
            $rules[$code] = [
                'code' => $code,
                'name' => Words::docType($code, true, (string) $r['name']),
                'review' => match ($r['review_rule']) {
                    'all' => Words::say('SETTINGS_PAGE', 'rule_all', (int) $r['review_due_days']),
                    'over_limit' => Words::say('SETTINGS_PAGE', 'rule_over', (int) $r['review_limit_units'], (int) $r['review_due_days']),
                    default => Words::SETTINGS_PAGE['rule_none'],
                },
                'approval' => match ($r['approval_rule']) {
                    'none' => null,
                    'over_value' => Words::say('SETTINGS_PAGE', 'rule_value', $limit),
                    default => Words::say('SETTINGS_PAGE', 'rule_units', $limit),
                },
                // reject_action (0010, I49): 'reverse' (I19) or 'record' (PO: the rejection is recorded, the order stands).
                'reject' => ($r['reject_action'] ?? 'reverse') === 'record' ? Words::SETTINGS_PAGE[$code === 'PO' ? 'rule_record_po' : 'rule_record']
                    : Words::SETTINGS_PAGE['rule_reverse'],
            ];
        }
        uksort($rules, static fn (string $a, string $b): int => array_search($a, self::TYPE_ORDER, true) <=> array_search($b, self::TYPE_ORDER, true));
        $topics = [];
        $names = [];
        foreach ($ctx->db->all("SELECT id, display_name FROM staff_user WHERE id IN (SELECT CAST(SUBSTRING(updated_actor, 7) AS UNSIGNED) FROM app_setting "
            . "WHERE updated_actor LIKE 'staff:%')") as $u) {
            $names['staff:' . $u['id']] = (string) $u['display_name'];
        }
        foreach ($ctx->settings()->all() as $s) {
            $key = (string) $s['key'];
            if (isset(ApprovalRules::SWITCHES[$key]) || isset(ApprovalRules::NUMBERS[$key])) {
                continue; // the approval rules have their own page (one place for one thing, Y9)
            }
            $value = $s['value'];
            $actor = (string) $s['updated_actor'];
            $topics[Words::settingTopic($key)][] = [
                'key' => $key,
                'href' => Html::url('/ui/reference/settings/setting', ['key' => $key]),
                'name' => Words::settingName($key),
                'help' => Words::settingHelp($key, (string) $s['description']),
                'value' => match (true) {
                    is_bool($value) => Words::SETTINGS_PAGE[$value ? 'yes' : 'no'],
                    is_string($value) && ConfigWords::listWords($key, $value) !== null => ConfigWords::listWords($key, $value),
                    $value === null, $value === '' => null,
                    default => (string) $s['display'],
                },
                'agreed' => !$s['provisional'],
                'changed' => match (true) {
                    str_starts_with($actor, 'staff:') => Words::say('SETTINGS_PAGE', 'by', Html::when((string) $s['updated_at']), $names[$actor] ?? $actor),
                    in_array($actor, ['system:migrate', 'system:history'], true) => Words::say('SETTINGS_PAGE', 'set_up', Html::day((string) $s['updated_at'])),
                    default => Words::say('SETTINGS_PAGE', 'on_server', Html::when((string) $s['updated_at'])),
                },
            ];
        }
        // Topics in the order of Words::SETTING_TOPIC, and the settings of a topic in the order of Words::SETTING (the
        // promotion checks together, in the order they are read), any setting without a word last.
        $topicOrder = array_values(Words::SETTING_TOPIC);
        uksort($topics, static fn (string $a, string $b): int => [array_search($a, $topicOrder, true) === false, array_search($a, $topicOrder, true)]
            <=> [array_search($b, $topicOrder, true) === false, array_search($b, $topicOrder, true)]);
        $keyOrder = array_keys(Words::SETTING);
        foreach ($topics as &$rows) {
            usort($rows, static fn (array $x, array $y): int => [array_search($x['key'], $keyOrder, true) === false, array_search($x['key'], $keyOrder, true), $x['key']]
                <=> [array_search($y['key'], $keyOrder, true) === false, array_search($y['key'], $keyOrder, true), $y['key']]);
        }
        unset($rows);
        $company = $ctx->company()->current();
        return $ctx->page('settings', [
            'company' => ['legal_name' => $company['legal_name'], 'confirmed' => $company['confirmed'], 'version' => $company['version'],
                'missing' => CompanyDetails::missing($company), 'canEdit' => $ctx->me()->can('company.edit')],
            'topics' => $topics,
            'rules' => array_values($rules),
            'vat' => $ctx->db->all('SELECT code, label, rate_percent, is_active FROM vat_code ORDER BY sort_order, code'),
            'canChange' => $ctx->me()->can('settings.manage'),
            'lookOnly' => $ctx->me()->can('settings.manage') ? null : Words::CONFIG['look_only'],
        ], 200, ['title' => Words::MENU['settings'], 'active' => 'settings']);
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
