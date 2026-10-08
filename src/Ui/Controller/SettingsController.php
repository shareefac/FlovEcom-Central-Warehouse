<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Admin\ApprovalRules;
use CW\Admin\ConfigHistory;
use CW\CwException;
use CW\Settings;
use CW\Ui\ConfigWords;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * One setting (G01, docs/decisions.md Y4): what it does, its value now, whether the owner agreed it, and every change (who, when,
 * from what to what, why). Everyone looks (reference.view); an admin or a reviewer (settings.manage) changes it with a typed field
 * (checked by Settings::parse and Settings::RULES), a reason and the "agreed" tick, through Settings::change (which re-checks the
 * permission, the version the form was drawn with and the value). The approval switches are changed on the Approval rules page
 * (one place for one thing); the company details have their own page. Refusals are shown in words by their code, the typed value
 * kept.
 */
final class SettingsController
{
    /** notice key => text: only these can be shown (a notice never comes from the URL as text). */
    public const NOTICES = Words::SETTING_NOTICE;

    public function show(Context $ctx): HtmlResponse
    {
        $key = (string) $ctx->req->param('key');
        return $this->page($ctx, $key, 200, null, null, self::NOTICES[$ctx->req->param('notice') ?? ''] ?? null);
    }

    public function save(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $key = (string) ($req->field('key') ?? '');
        $seen = $req->field('seen');
        if ($seen === null || preg_match('/^\d{1,9}$/D', $seen) !== 1) {
            return $this->page($ctx, $key, 400, new CwException('bad_version', Words::ERROR['bad_version'], 400), null);
        }
        $typed = ['value' => (string) ($req->field('value') ?? ''), 'agreed' => $req->field('agreed') === '1', 'reason' => (string) ($req->field('reason') ?? '')];
        if (str_starts_with($key, 'company.') || isset(ApprovalRules::SWITCHES[$key]) || isset(ApprovalRules::NUMBERS[$key])) {
            return $this->page($ctx, $key, 409, new CwException('elsewhere', Words::SETTING_EDIT[str_starts_with($key, 'company.') ? 'company' : 'approvals'], 409), $typed);
        }
        try {
            $r = $ctx->settings()->change($ctx->caller(), $key, $typed['value'], $typed['reason'], $typed['agreed'], (int) $seen);
        } catch (CwException $e) {
            return $this->page($ctx, $key, $e->httpStatus, $e, $typed);
        }
        $notice = 'unchanged';
        if ($r['changed']) {
            $notice = $r['before'] !== $r['after'] ? 'saved' : ($typed['agreed'] ? 'agreed' : 'unagreed');
        }
        return HtmlResponse::redirect(Html::url('/ui/reference/settings/setting', ['key' => $key, 'notice' => $notice]));
    }

    /**
     * The allowed values of a setting in words (its type, then its rule: Settings::RULES, the `_days` default).
     */
    public static function hint(string $key, string $type): string
    {
        $parts = [Words::SETTING_EDIT['type_' . $type] ?? ''];
        $rule = Settings::RULES[$key] ?? (str_ends_with($key, '_days') ? Settings::DAYS_RULE : []);
        if (isset($rule['min'], $rule['max'])) {
            $parts[] = sprintf(Words::SETTING_EDIT['range'], is_int($rule['min']) ? number_format($rule['min']) : $rule['min'],
                is_int($rule['max']) ? number_format($rule['max']) : $rule['max']);
        }
        if (($rule['vat_code'] ?? false) === true) {
            $parts[] = Words::SETTING_EDIT['vat'];
        }
        if (isset($rule['in'])) {
            $parts[] = sprintf(Words::SETTING_EDIT['one_of'], implode(', ', $rule['in']));
        }
        if (isset($rule['pattern'])) {
            $parts[] = Words::SETTING_EDIT['sites'];
        }
        if (in_array($type, ['int', 'decimal', 'date', 'string'], true) && !isset($rule['min'])) {
            $parts[] = Words::SETTING_EDIT['empty'];
        }
        return trim(implode(' ', array_filter($parts)));
    }

    /**
     * A refusal of Settings::change in the page's words, by its code; another code keeps the service's message (the API's).
     */
    public static function plain(CwException $e, string $hint): string
    {
        return match ($e->errorCode) {
            'bad_value' => Words::CONFIG_ERROR['bad_value'] . ' ' . $hint,
            'changed_meanwhile' => Words::say('CONFIG_ERROR', 'changed_meanwhile', ConfigWords::who((string) ($e->detail['by'] ?? ''), null),
                Html::when(is_string($e->detail['at'] ?? null) ? $e->detail['at'] : null)),
            'bad_reason', 'role_not_allowed', 'staff_not_allowed' => Words::CONFIG_ERROR[$e->errorCode],
            'elsewhere' => $e->getMessage(),
            default => Words::error($e->errorCode, $e->getMessage()),
        };
    }

    /** @param array{value: string, agreed: bool, reason: string}|null $typed what a refused form sent (kept) */
    private function page(Context $ctx, string $key, int $status, ?CwException $error, ?array $typed, ?string $notice = null): HtmlResponse
    {
        $settings = $ctx->settings();
        $row = null;
        foreach ($settings->all() as $s) {
            if ($s['key'] === $key) {
                $row = $s;
                break;
            }
        }
        if ($row === null || str_starts_with($key, 'company.')) {
            return $ctx->error(404, 'no_setting', 'there is no such setting', ['/ui/reference/settings', Words::MENU['settings']]);
        }
        $type = (string) $row['type'];
        $value = $row['value'];
        $approval = isset(ApprovalRules::SWITCHES[$key]) || isset(ApprovalRules::NUMBERS[$key]);
        $canEdit = $ctx->me()->can('settings.manage') && !$approval;
        $latest = ConfigHistory::latest($ctx->db, 'setting', $key);
        $hint = self::hint($key, $type);
        return $ctx->page('setting', [
            'key' => $key,
            'name' => Words::settingName($key),
            'help' => Words::settingHelp($key, (string) $row['description']),
            'type' => $type,
            'shown' => match (true) {
                is_bool($value) => Words::CONFIG[$value ? 'yes' : 'no'],
                $value === null, $value === '' => null,
                default => (string) $row['display'],
            },
            // What the form's field holds: what was typed on a refused form, else the value now (as the CLI takes it).
            'raw' => $typed['value'] ?? (is_bool($value) ? ($value ? 'true' : 'false') : ($value === null ? '' : (string) $value)),
            'agreed' => !$row['provisional'],
            'agreedTick' => $typed['agreed'] ?? !$row['provisional'],
            'reason' => $typed['reason'] ?? '',
            'changed' => $latest === null || in_array($latest['actor'], ['system:migrate', 'system:history'], true)
                ? Words::say('SETTINGS_PAGE', 'set_up', Html::day((string) $row['updated_at']))
                : Words::say('SETTINGS_PAGE', 'by', Html::when($latest['created_at']), ConfigWords::who($latest['actor'], self::name($ctx, $latest['actor']))),
            'hint' => $hint,
            'canEdit' => $canEdit,
            'approval' => $approval,
            'seen' => $latest['version'] ?? 0,
            'history' => ConfigWords::history($ctx->db, 'setting', $key, $type),
            'error' => $error === null ? null : self::plain($error, $hint),
            'errorCode' => $error?->errorCode,
            'lookOnly' => $ctx->me()->can('settings.manage') ? null : Words::SETTING_EDIT['look_only'],
        ], $status, ['title' => Words::settingName($key), 'active' => 'setting', 'notice' => $notice]);
    }

    /** The display name of a staff actor ('staff:12'), or null. */
    private static function name(Context $ctx, string $actor): ?string
    {
        if (!str_starts_with($actor, 'staff:')) {
            return null;
        }
        $n = $ctx->db->value('SELECT display_name FROM staff_user WHERE id = ?', [(int) substr($actor, 6)]);
        return $n === null ? null : (string) $n;
    }
}
