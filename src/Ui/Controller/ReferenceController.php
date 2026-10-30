<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Admin\ApprovalRules;
use CW\Company\CompanyDetails;
use CW\Documents\NumberSeries;
use CW\Output\CsvWriter;
use CW\Settings;
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
    public const TYPE_ORDER = ['PO', 'GRN', 'SIN', 'SOUT', 'ADJ', 'TRF', 'REL', 'SINV', 'DN', 'SCN', 'CNT', 'WO', 'TRD', 'RPK']; // SCN: pack C; RPK: pack B (PB9)

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
     * Rules and numbers (app_setting, I38-I41; plan §6.33; the approved TP_Settings, docs/decisions.md W13.9): every setting by topic, a
     * card each, one row per setting with its plain name, what it does, whether the owner has agreed it and who last changed it (UK
     * time); for whoever changes settings (settings.manage) a simple value (a number, a date, a short text, yes or no) is changed in its
     * row, with a reason, and saved on its own through the setting's own route (SettingsController::save, the same fields as its page;
     * the version the row was drawn with, and "agreed" as it is now, so a row's Save never changes it); a list, a long text or a hidden
     * value opens its page. One search finds a setting (`q`: its name, what it does, its topic). The company details' card first, the
     * VAT codes' last. Who checks what is on the Approval rules page (one place for one thing). The screen never names a server tool.
     */
    public function settings(Context $ctx): HtmlResponse
    {
        $q = mb_substr(trim((string) $ctx->req->param('q')), 0, 100);
        $canChange = $ctx->me()->can('settings.manage');
        // Every setting with the version its row is drawn with (its form's `seen`, as the setting's page sends it): the value and its version
        // read together, in one statement (docs/decisions.md W13.21), before anything else of the page.
        $all = $ctx->settings()->allWithVersions();
        $topics = [];
        $names = [];
        foreach ($ctx->db->all("SELECT id, display_name FROM staff_user WHERE id IN (SELECT CAST(SUBSTRING(updated_actor, 7) AS UNSIGNED) FROM app_setting "
            . "WHERE updated_actor LIKE 'staff:%')") as $u) {
            $names['staff:' . $u['id']] = (string) $u['display_name'];
        }
        $values = array_column($all, 'value', 'key');
        foreach ($all as $s) {
            $key = (string) $s['key'];
            if (isset(ApprovalRules::SWITCHES[$key]) || isset(ApprovalRules::NUMBERS[$key])) {
                continue; // the approval rules have their own page (one place for one thing, Y9)
            }
            // Pack C follow-up (PC19): a setting not used while its switch is off is greyed and says so.
            $switch = Settings::UNUSED_WHEN_OFF[$key] ?? null;
            $value = $s['value'];
            $actor = (string) $s['updated_actor'];
            $type = (string) $s['type'];
            $topic = Words::settingTopic($key);
            if ($q !== '' && mb_stripos(Words::settingName($key) . ' ' . Words::settingHelp($key, (string) $s['description']) . ' ' . $topic, $q) === false) {
                continue;
            }
            // Changed in its row: a value typed in one box (W13.9); a list (ticked boxes), a long text or a hidden value on its own page.
            $inline = $canChange && in_array($type, ['int', 'decimal', 'string', 'date', 'bool'], true) && !isset(Settings::RULES[$key]['list_in'])
                && !Settings::isSecret($key);
            $topics[$topic][] = [
                'key' => $key,
                'href' => Html::url('/ui/reference/settings/setting', ['key' => $key]),
                'name' => Words::settingName($key),
                'help' => Words::settingHelp($key, (string) $s['description']),
                'type' => $type,
                'inline' => $inline,
                'raw' => is_bool($value) ? ($value ? 'true' : 'false') : ($value === null ? '' : (string) $value),
                'seen' => (int) $s['version'],
                'unit' => self::unit($key, $type),
                // Who changed it last, under what it does; a value as it was set up says nothing (its page's History has it all).
                'changed_shown' => !in_array($actor, ['system:migrate', 'system:history'], true),
                'value' => match (true) {
                    is_bool($value) => Words::SETTINGS_PAGE[$value ? 'yes' : 'no'],
                    is_string($value) && ConfigWords::listWords($key, $value) !== null => ConfigWords::listWords($key, $value),
                    $value === null, $value === '' => null,
                    default => (string) $s['display'],
                },
                'agreed' => !$s['provisional'],
                'unused' => $switch !== null && ($values[$switch] ?? null) === false ? Words::say('SETTINGS_PAGE', 'unused_while_off', Words::settingName($switch)) : null,
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
        $vat = $ctx->db->all('SELECT code, label, rate_percent, is_active FROM vat_code ORDER BY sort_order, code');
        if ($q !== '') {
            $vat = array_values(array_filter($vat, static fn (array $c): bool => mb_stripos(Words::SETTINGS_PAGE['vat'] . ' ' . $c['label'] . ' ' . $c['code'], $q) !== false));
        }
        return $ctx->page('settings', [
            'company' => ['legal_name' => $company['legal_name'], 'confirmed' => $company['confirmed'], 'version' => $company['version'],
                'missing' => CompanyDetails::missing($company), 'canEdit' => $ctx->me()->can('company.edit')],
            'topics' => $topics,
            'q' => $q,
            'vat' => $vat,
            'canChange' => $canChange,
            'lookOnly' => $ctx->me()->can('settings.manage') ? null : Words::CONFIG['look_only'],
            // 2-step verification switched off for a while (auth.totp_off_until, TF5): a warning at the top while it is.
            'codeOffUntil' => $ctx->codeOffUntil(),
        ], 200, ['title' => Words::SETTINGS_PAGE['title'], 'active' => 'settings']);
    }

    /**
     * The word after a setting's box (TP_Settings' "days", "%", "units"), read from how its key ends; '' for any other. A number's only.
     */
    public static function unit(string $key, string $type): string
    {
        if (!in_array($type, ['int', 'decimal'], true)) {
            return '';
        }
        foreach (['_days' => 'unit_days', '_minutes' => 'unit_minutes', '_hours' => 'unit_hours', '_pct' => 'unit_percent', '_percent' => 'unit_percent',
            '_units' => 'unit_units', '_rows' => 'unit_rows', '_items' => 'unit_items'] as $end => $word) {
            if (str_ends_with($key, $end)) {
                return Words::SETTINGS_PAGE[$word];
            }
        }
        return '';
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
