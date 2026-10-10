<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Admin\ConfigHistory;
use CW\Admin\ReasonCodes;
use CW\CwException;
use CW\Ui\ConfigWords;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * Reasons for stock changes (G02, docs/decisions.md I22, Y12): the list (everyone, reference.view; the CSV stays
 * ReferenceController's), and for an admin or a reviewer (settings.manage) adding a reason, and on a reason's own page renaming it,
 * saying where it is offered, its stock-out rules ("given to", below zero: pack A1) and switching it off or on again, each with a reason, through Admin\ReasonCodes (which re-checks the permission, the version and
 * the locked reasons CW sets itself). Nothing is ever deleted. Refusals come back in words by their code, with what was typed kept.
 */
final class ReasonsController
{
    public const NOTICES = Words::REASON_NOTICE;

    public function index(Context $ctx): HtmlResponse
    {
        return $this->list($ctx, 200, null, null, self::NOTICES[$ctx->req->param('notice') ?? ''] ?? null);
    }

    public function add(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $uses = [];
        foreach (ReasonCodes::USES as $use) {
            if ($req->field('use_' . $use) === '1') {
                $uses[] = $use;
            }
        }
        $typed = ['code' => (string) ($req->field('code') ?? ''), 'label' => (string) ($req->field('label') ?? ''), 'uses' => $uses,
            'direction' => (string) ($req->field('direction') ?? ''), 'needs_note' => $req->field('needs_note') === '1', 'is_gift' => $req->field('is_gift') === '1',
            'needs_given_to' => $req->field('needs_given_to') === '1', 'below_zero' => $req->field('below_zero') === '1', 'reason' => (string) ($req->field('reason') ?? '')];
        try {
            $r = (new ReasonCodes($ctx->db))->add($ctx->caller(), $typed['code'], $typed['label'], $uses, $typed['direction'], $typed['needs_note'], $typed['is_gift'],
                $typed['reason'], $typed['needs_given_to'], $typed['below_zero']);
        } catch (CwException $e) {
            return $this->list($ctx, $e->httpStatus, $e, $typed);
        }
        return HtmlResponse::redirect(Html::url('/ui/reference/reasons/reason', ['code' => $r['code'], 'notice' => 'added']));
    }

    public function show(Context $ctx): HtmlResponse
    {
        return $this->reasonPage($ctx, (string) $ctx->req->param('code'), 200, null, null, self::NOTICES[$ctx->req->param('notice') ?? ''] ?? null);
    }

    public function change(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $code = (string) ($req->field('code') ?? '');
        $do = (string) ($req->field('do') ?? '');
        $seen = $req->field('seen');
        $uses = [];
        foreach (ReasonCodes::USES as $use) {
            if ($req->field('use_' . $use) === '1') {
                $uses[] = $use;
            }
        }
        $typed = ['label' => (string) ($req->field('label') ?? ''), 'reason' => (string) ($req->field('reason') ?? ''), 'do' => $do, 'uses' => $uses,
            'needs_given_to' => $req->field('needs_given_to') === '1', 'below_zero' => $req->field('below_zero') === '1'];
        if ($seen === null || preg_match('/^\d{1,9}$/D', $seen) !== 1 || !in_array($do, ['rename', 'uses', 'rules', 'off', 'on'], true)) {
            return $this->reasonPage($ctx, $code, 400, new CwException('bad_form', Words::ERROR['bad_form'], 400), $typed);
        }
        $service = new ReasonCodes($ctx->db);
        try {
            $r = match ($do) {
                'rename' => $service->rename($ctx->caller(), $code, $typed['label'], $typed['reason'], (int) $seen),
                'uses' => $service->setUses($ctx->caller(), $code, $uses, $typed['reason'], (int) $seen),
                'rules' => $service->setRules($ctx->caller(), $code, $typed['needs_given_to'], $typed['below_zero'], $typed['reason'], (int) $seen),
                default => $service->setActive($ctx->caller(), $code, $do === 'on', $typed['reason'], (int) $seen),
            };
        } catch (CwException $e) {
            return $this->reasonPage($ctx, $code, $e->httpStatus, $e, $typed);
        }
        $notice = !$r['changed'] ? 'unchanged' : match ($do) { 'rename' => 'renamed', 'uses' => 'uses_saved', 'rules' => 'rules_saved', default => $do };
        return HtmlResponse::redirect(Html::url('/ui/reference/reasons/reason', ['code' => $code, 'notice' => $notice]));
    }

    /** A refusal of ReasonCodes in words, by its code; another code keeps the service's message. */
    public static function plain(CwException $e): string
    {
        return match ($e->errorCode) {
            'bad_code' => Words::CONFIG_ERROR['bad_code_reason'],
            'changed_meanwhile' => Words::say('CONFIG_ERROR', 'changed_meanwhile', ConfigWords::who((string) ($e->detail['by'] ?? ''), null),
                Html::when(is_string($e->detail['at'] ?? null) ? $e->detail['at'] : null)),
            default => Words::CONFIG_ERROR[$e->errorCode] ?? Words::error($e->errorCode, $e->getMessage()),
        };
    }

    /**
     * The list with the add form (a refused add comes back here, typed values kept).
     *
     * @param array<string, mixed>|null $typed
     */
    private function list(Context $ctx, int $status, ?CwException $error, ?array $typed, ?string $notice = null): HtmlResponse
    {
        $rows = [];
        foreach ((new ReasonCodes($ctx->db))->all() as $r) {
            $rows[] = $r + [
                'href' => Html::url('/ui/reference/reasons/reason', ['code' => $r['code']]),
                'used_for' => ucfirst(implode(', ', array_map(static fn (string $u): string => Words::of('REASON_USE', $u), $r['uses']))),
                'way' => Words::of('REASON_USE', $r['direction']),
            ];
        }
        $canEdit = $ctx->me()->can('settings.manage');
        return $ctx->page('reasons', [
            'reasons' => $rows,
            'canEdit' => $canEdit,
            'lookOnly' => $canEdit ? null : Words::CONFIG['look_only'],
            'uses' => array_map(static fn (string $u): array => ['code' => $u, 'name' => ucfirst(Words::of('REASON_USE', $u)),
                'checked' => in_array($u, $typed['uses'] ?? [], true)], ReasonCodes::USES),
            'directions' => array_map(static fn (string $d): array => ['code' => $d, 'name' => Words::of('REASON_USE', $d)], ReasonCodes::DIRECTIONS),
            'typed' => $typed ?? ['code' => '', 'label' => '', 'direction' => 'decrease', 'needs_note' => false, 'is_gift' => false, 'needs_given_to' => false,
                'below_zero' => false, 'reason' => ''],
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
        ], $status, ['title' => Words::title('reasons'), 'active' => 'reasons', 'notice' => $notice]);
    }

    /** @param array<string, mixed>|null $typed */
    private function reasonPage(Context $ctx, string $code, int $status, ?CwException $error, ?array $typed, ?string $notice = null): HtmlResponse
    {
        $r = (new ReasonCodes($ctx->db))->get($code);
        if ($r === null) {
            return $ctx->error(404, 'no_reason', 'there is no such reason', ['/ui/reference/reasons', Words::title('reasons')]);
        }
        $canEdit = $ctx->me()->can('settings.manage') && !$r['system_only'];
        return $ctx->page('reason', [
            'r' => $r + [
                'used_for' => ucfirst(implode(', ', array_map(static fn (string $u): string => Words::of('REASON_USE', $u), $r['uses']))),
                'way' => Words::of('REASON_USE', $r['direction']),
            ],
            'canEdit' => $canEdit,
            'lookOnly' => $ctx->me()->can('settings.manage') ? null : Words::REASONS_EDIT['look_only'],
            'seen' => ConfigHistory::version($ctx->db, 'reason', $code),
            'typed' => ['label' => $typed['label'] ?? $r['label'], 'reason' => $typed['reason'] ?? '', 'do' => $typed['do'] ?? '',
                'needs_given_to' => ($typed['do'] ?? '') === 'rules' ? (bool) $typed['needs_given_to'] : $r['needs_given_to'],
                'below_zero' => ($typed['do'] ?? '') === 'rules' ? (bool) $typed['below_zero'] : $r['below_zero']],
            'uses' => array_map(static fn (string $u): array => ['code' => $u, 'name' => ucfirst(Words::of('REASON_USE', $u)),
                'checked' => in_array($u, ($typed['do'] ?? '') === 'uses' ? (array) ($typed['uses'] ?? []) : $r['uses'], true)], ReasonCodes::USES),
            'history' => ConfigWords::history($ctx->db, 'reason', $code),
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
        ], $status, ['title' => $r['label'], 'active' => 'reasons', 'notice' => $notice]);
    }
}
