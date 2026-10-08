<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Admin\ApprovalRules;
use CW\Admin\ConfigHistory;
use CW\Admin\DocumentRules;
use CW\Auth\Permissions;
use CW\CwException;
use CW\Staff\StaffRoles;
use CW\Ui\ConfigWords;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * The Approval rules page (G03, docs/decisions.md Y9-Y11; the owner's Q8 answer "leave it for now": the existing two-person rules
 * stay on, the new staff rule is off, every one switchable). Every rule in one list, with what it does now and its history:
 *
 *  - each kind of record (Admin\DocumentRules): the reviewer check after it is final (every one / over a limit / none) and its
 *    days, the blocking OK first (purchase orders over a net value; stock put back without a supplier document, which also covers
 *    a cancellation) and what Not OK does;
 *  - the switches and numbers of Admin\ApprovalRules (suppliers, matching, the spot check, the company details, staff access).
 *
 * Everyone looks (reference.view); an admin or a reviewer (settings.manage) changes a rule with a reason, through DocumentRules::set
 * or Settings::change (each re-checks the permission and the version the form was drawn with). Switching a rule off or making it
 * looser needs a Reviewer (review finding I1, Y45: the admin a rule restrains cannot lift it); the page says so to an admin. A
 * setting's card also carries "the owner has agreed this rule" (M5). Switching a rule off never releases work already waiting.
 * Not OK on a purchase order only records it (M7). One form per rule; a refusal comes back on that rule's card, in words, with
 * what was typed kept.
 */
final class ApprovalsController
{
    public function index(Context $ctx): HtmlResponse
    {
        $notice = $ctx->req->param('notice');
        return $this->page($ctx, 200, null, null, null, in_array($notice, ['saved', 'unchanged'], true) ? Words::APPROVALS[$notice] : null);
    }

    public function save(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $kind = (string) ($req->field('kind') ?? '');
        $key = (string) ($req->field('key') ?? '');
        $seen = $req->field('seen');
        $reason = (string) ($req->field('reason') ?? '');
        $typed = [];
        foreach (['review_rule', 'review_limit', 'review_due_days', 'approval', 'approval_limit', 'reject_action', 'value'] as $f) {
            $typed[$f] = $req->field($f);
        }
        $typed['reason'] = $reason;
        $typed['agreed'] = $req->field('agreed') === '1' ? '1' : '0';
        if ($seen === null || preg_match('/^\d{1,9}$/D', $seen) !== 1 || !in_array($kind, ['document', 'switch', 'number'], true)) {
            return $this->page($ctx, 400, $key, new CwException('bad_form', Words::ERROR['bad_form'], 400), $typed);
        }
        try {
            if ($kind === 'document') {
                if (!in_array(['document', $key], ApprovalRules::PAGE['records'], true)) {
                    return $ctx->error(404, 'not_found', 'no such rule', ['/ui/reference/approvals', Words::MENU['approvals']]);
                }
                $change = ['review_rule' => (string) $typed['review_rule'], 'review_due_days' => (string) $typed['review_due_days'],
                    'reject_action' => (string) $typed['reject_action']];
                if ($typed['review_rule'] === 'over_limit') {
                    $change['review_limit_units'] = (string) $typed['review_limit'];
                }
                if (isset(DocumentRules::APPROVAL_KIND[$key])) {
                    $change['approval'] = $typed['approval'] === '1';
                    $change['approval_limit_units'] = (string) $typed['approval_limit'];
                }
                $r = (new DocumentRules($ctx->db))->set($ctx->caller(), $key, $change, $reason, (int) $seen);
            } else {
                if (!isset(ApprovalRules::SWITCHES[$key]) && !isset(ApprovalRules::NUMBERS[$key])
                    || ($kind === 'switch') !== isset(ApprovalRules::SWITCHES[$key])) {
                    return $ctx->error(404, 'not_found', 'no such rule', ['/ui/reference/approvals', Words::MENU['approvals']]);
                }
                $value = $kind === 'switch' ? ($typed['value'] === 'true' ? 'true' : 'false') : (string) $typed['value'];
                $r = $ctx->settings()->change($ctx->caller(), $key, $value, $reason, $typed['agreed'] === '1', (int) $seen);
            }
        } catch (CwException $e) {
            return $this->page($ctx, $e->httpStatus, $key, $e, $typed);
        }
        return HtmlResponse::redirect(Html::url('/ui/reference/approvals', ['notice' => $r['changed'] ? 'saved' : 'unchanged']) . '#' . self::anchor($key));
    }

    /** The id of a rule's card ("rule-PO", "rule-approvals-staff-grant"). */
    public static function anchor(string $key): string
    {
        return 'rule-' . preg_replace('/[^A-Za-z0-9]+/', '-', $key);
    }

    /**
     * A refusal in words, by its code (Words::CONFIG_ERROR); another code keeps the service's message.
     */
    public static function plain(CwException $e): string
    {
        if ($e->errorCode === 'changed_meanwhile') {
            return Words::say('CONFIG_ERROR', 'changed_meanwhile', ConfigWords::who((string) ($e->detail['by'] ?? ''), null),
                Html::when(is_string($e->detail['at'] ?? null) ? $e->detail['at'] : null));
        }
        if ($e->errorCode === 'bad_value') {
            return Words::CONFIG_ERROR['bad_value'];
        }
        return Words::CONFIG_ERROR[$e->errorCode] ?? Words::error($e->errorCode, $e->getMessage());
    }

    /**
     * @param array<string, ?string>|null $typed what a refused form sent (kept on its card)
     */
    private function page(Context $ctx, int $status, ?string $errorKey, ?CwException $error, ?array $typed, ?string $notice = null): HtmlResponse
    {
        $db = $ctx->db;
        $docs = $ctx->documents();
        $canEdit = $ctx->me()->can('settings.manage');
        $rules = [];
        foreach ((new DocumentRules($db))->all() as $r) {
            $rules[$r['code']] = $r;
        }
        $sections = [];
        foreach (ApprovalRules::PAGE as $section => $items) {
            $cards = [];
            foreach ($items as [$kind, $key]) {
                $mine = $errorKey === $key;
                if ($kind === 'document') {
                    $r = $rules[$key] ?? null;
                    if ($r === null) {
                        continue;
                    }
                    $cards[] = self::documentCard($ctx, $r, $docs->handler($key) !== null, $mine ? $typed : null) + ['kind' => 'document', 'key' => $key,
                        'anchor' => self::anchor($key), 'error' => $mine && $error !== null ? self::plain($error) : null, 'errorCode' => $mine ? $error?->errorCode : null,
                        'history' => ConfigWords::history($db, 'document_rule', $key)];
                    continue;
                }
                $settings = $ctx->settings();
                if (!$settings->has($key)) {
                    continue;
                }
                $value = $settings->get($key);
                $provisional = true;
                foreach ($settings->all() as $row) {
                    if ($row['key'] === $key) {
                        $provisional = $row['provisional'];
                    }
                }
                $words = Words::RULE[$key];
                $card = ['kind' => $kind, 'key' => $key, 'anchor' => self::anchor($key), 'title' => $words['title'],
                    'seen' => ConfigHistory::version($db, 'setting', $key), 'history' => ConfigWords::history($db, 'setting', $key, $kind === 'switch' ? 'bool' : 'int'),
                    'error' => $mine && $error !== null ? self::plain($error) : null, 'errorCode' => $mine ? $error?->errorCode : null,
                    'reason' => $mine ? (string) ($typed['reason'] ?? '') : '', 'agreed' => !$provisional,
                    'agreedTick' => $mine && isset($typed['agreed']) ? $typed['agreed'] === '1' : !$provisional,
                    'note' => $words['note'] ?? null];
                if ($kind === 'switch') {
                    $on = $value === true;
                    $card += ['on' => $on, 'text' => $words[$on ? 'on' : 'off'],
                        'tick' => $mine && ($typed['value'] ?? null) !== null ? $typed['value'] === 'true' : $on];
                } else {
                    $card += ['value' => (int) $value, 'text' => sprintf($words['now'], number_format((int) $value)), 'label' => $words['label'],
                        'typed' => $mine ? (string) ($typed['value'] ?? '') : (string) (int) $value];
                }
                $cards[] = $card;
            }
            $sections[] = ['key' => $section, 'title' => Words::APPROVALS[$section], 'cards' => $cards];
        }
        $requests = (int) $db->value("SELECT COUNT(*) FROM staff_role_request WHERE state = 'open'");
        $reviewers = 0;
        if (ApprovalRules::on($db, 'approvals.staff_grant') || ApprovalRules::on($db, 'approvals.staff_reset')) {
            foreach (StaffRoles::people($db) as $p) {
                $reviewers += $p['is_active'] && Permissions::can($p['roles'], 'staff.approve') ? 1 : 0;
            }
        }
        return $ctx->page('approvals', [
            'sections' => $sections,
            'canEdit' => $canEdit,
            'lookOnly' => $canEdit ? null : Words::CONFIG['look_only'],
            'requests' => $requests,
            'canDecideRequests' => $ctx->me()->can('staff.approve'),
            'nobodyCan' => (ApprovalRules::on($db, 'approvals.staff_grant') || ApprovalRules::on($db, 'approvals.staff_reset')) && $reviewers === 0,
            // Switching a rule off or making it looser needs a Reviewer (I1): an admin is told before they try.
            'loosenNote' => $canEdit && !$ctx->me()->can(ApprovalRules::LOOSEN_PERMISSION) ? Words::APPROVALS['loosen_admin'] : null,
        ], $status, ['title' => Words::MENU['approvals'], 'active' => 'approvals', 'notice' => $notice]);
    }

    /**
     * One kind of record's card: its name, whether it is in use, the rule in sentences, and the form's values (what a refused form
     * sent, else the rule now).
     *
     * @param array<string, mixed> $r DocumentRules::all() row
     * @param array<string, ?string>|null $typed
     * @return array<string, mixed>
     */
    private static function documentCard(Context $ctx, array $r, bool $live, ?array $typed): array
    {
        $code = (string) $r['code'];
        $days = (int) $r['review_due_days'];
        $review = match ($r['review_rule']) {
            'all' => Words::say('SETTINGS_PAGE', 'rule_all', $days),
            'over_limit' => Words::say('SETTINGS_PAGE', 'rule_over', (int) $r['review_limit_units'], $days),
            default => Words::SETTINGS_PAGE['rule_none'],
        };
        $limit = (int) ($r['approval_limit_units'] ?? 0);
        $approval = match ($r['approval_rule']) {
            'none' => $r['approval_kind'] === null ? null : Words::APPROVALS['no_ok'],
            'over_value' => Words::say('SETTINGS_PAGE', 'rule_value', $limit),
            default => Words::say('APPROVALS', 'units_cancel', $limit),
        };
        $reject = $r['reject_action'] === 'record' ? Words::SETTINGS_PAGE[$code === 'PO' ? 'rule_record_po' : 'rule_record'] : Words::SETTINGS_PAGE['rule_reverse'];
        $t = $typed ?? [];
        return [
            'title' => Words::docType($code, true, (string) $r['name']),
            'live' => $live,
            'sentences' => array_values(array_filter([ucfirst($review), $approval, $reject])),
            'seen' => (int) $r['version'],
            'hasApproval' => $r['approval_kind'] !== null,
            'recordOnly' => in_array($code, DocumentRules::REJECT_RECORD_ONLY, true),
            'okLabel' => Words::APPROVALS['ok_limit_' . $code] ?? Words::APPROVALS['number'],
            'form' => [
                'review_rule' => (string) ($t['review_rule'] ?? $r['review_rule']),
                'review_limit' => (string) ($t['review_limit'] ?? ($r['review_limit_units'] === null ? '' : (string) $r['review_limit_units'])),
                'review_due_days' => (string) ($t['review_due_days'] ?? (string) $days),
                'approval' => $t === [] ? $r['approval_rule'] !== 'none' : ($t['approval'] ?? '0') === '1',
                'approval_limit' => (string) ($t['approval_limit'] ?? ($r['approval_limit_units'] === null ? '' : (string) $r['approval_limit_units'])),
                'reject_action' => (string) ($t['reject_action'] ?? $r['reject_action']),
                'reason' => (string) ($t['reason'] ?? ''),
            ],
        ];
    }
}
