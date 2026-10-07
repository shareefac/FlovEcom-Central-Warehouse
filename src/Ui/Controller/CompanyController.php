<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Company\CompanyDetails;
use CW\CwException;
use CW\Db;
use CW\OpResult;
use CW\PurchaseOrders\PurchaseOrderPdf;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Auth\Permissions;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * The Company details screen (Settings > Company details; linked from Settings and lists and from a PO whose PDF says "do not send";
 * docs/decisions.md I90-I99): the details every purchase order prints, whether they are confirmed, what is still missing,
 * the checks of a confirmation, the orders that carry a rejected change, every version with what changed, and a sample PDF.
 *
 * Everyone with reference.view reads. A person with company.edit opens the form (one form, every field) and saves; a person
 * with company.confirm confirms ("These details are correct") and decides another reviewer's check. Both are reviewer today
 * (provisional), never admin: the routes refuse 403 and CW\Company\CompanyDetails checks again inside its transaction. The
 * edit and confirm forms carry the version they were drawn with and a FormOnce key (the same form sent twice has one
 * effect); a stale version is 409 company_changed, nothing written: the form comes back with what the person typed, the
 * new version and what changed meanwhile, so saving again is a deliberate overwrite. A form sent again with other values
 * after it was saved (the back button) comes back the same way, so the next Save works.
 */
final class CompanyController
{
    /** notice key => text (Ui\Words). Only these can be shown: a notice never comes from the URL as text. */
    public const NOTICES = Words::COMPANY_NOTICE;
    /** The form's fields (CompanyDetails::FIELDS with vat_registered as yes / no / ''), and the reason. */
    public const FORM_FIELDS = ['legal_name', 'trading_name', 'company_number', 'address', 'vat_registered', 'vat_number', 'phone', 'email', 'delivery_address'];
    /** How the history names the set-up's actors (people never see system:*). */
    private const ACTORS = ['system:migrate' => Words::COMPANY['set_up'], 'system:settings' => Words::COMPANY['old_settings']];

    public function show(Context $ctx): HtmlResponse
    {
        return $this->page($ctx, 200, null, self::NOTICES[$ctx->req->param('notice') ?? ''] ?? null);
    }

    public function editForm(Context $ctx): HtmlResponse
    {
        $p = $ctx->company()->current();
        return $this->form($ctx, self::formValues($p), $p['version'], FormOnce::newKey(), '', 200, null);
    }

    public function save(Context $ctx): HtmlResponse
    {
        $typed = self::posted($ctx);
        $reason = $ctx->req->field('reason') ?? '';
        $version = self::version($ctx->req->field('version'));
        if ($version === null) {
            return $ctx->error(400, 'bad_version', 'this form has no version: reload the page');
        }
        try {
            $r = FormOnce::run($ctx, 'ui.company.save', $typed + ['reason' => $reason, 'version' => (string) $version],
                static function (Db $db) use ($ctx, $typed, $reason, $version): OpResult {
                    $s = $ctx->company()->save($ctx->caller(), $version, $typed, $reason);
                    return OpResult::of(303, ['result' => $s['result'], 'version' => $s['version'],
                        'redirect' => Html::url('/ui/reference/company', ['notice' => $s['result'] === 'unchanged' ? 'unchanged' : 'saved'])]);
                });
        } catch (CwException $e) {
            $key = $ctx->req->field(FormOnce::FIELD) ?? FormOnce::newKey();
            if ($e->errorCode === 'company_changed') {
                // What the person typed stays; the form now carries the current version and says what changed meanwhile.
                $cur = $ctx->company()->current();
                return $this->form($ctx, $typed, $cur['version'], $key, $reason, 409, self::plain($ctx, $e, 'changed_saved'), $ctx->company()->changesSince($version));
            }
            if ($e->errorCode === 'idempotency_key_reused') {
                // This form was saved already and is sent again with other values (the back button): keep them, draw a fresh
                // form at the current version, so pressing Save again works.
                $cur = $ctx->company()->current();
                return $this->form($ctx, $typed, $cur['version'], FormOnce::newKey(), $reason, 409, new CwException('form_already_saved',
                    Words::COMPANY['saved_twice'], 409));
            }
            if (in_array($e->errorCode, ['company_invalid', 'bad_form_key'], true)) {
                return $this->form($ctx, $typed, $version, $e->errorCode === 'company_invalid' ? $key : FormOnce::newKey(), $reason, $e->httpStatus, $e);
            }
            return $this->page($ctx, $e->httpStatus, self::plain($ctx, $e, 'changed_saved'));
        }
        return FormOnce::redirect($r);
    }

    public function confirm(Context $ctx): HtmlResponse
    {
        $version = self::version($ctx->req->field('version'));
        if ($version === null) {
            return $ctx->error(400, 'bad_version', 'this form has no version: reload the page');
        }
        try {
            $r = FormOnce::run($ctx, 'ui.company.confirm', ['version' => (string) $version], static function (Db $db) use ($ctx, $version): OpResult {
                $c = $ctx->company()->confirm($ctx->caller(), $version);
                $notice = match (true) {
                    $c['result'] === 'already' => 'already',
                    $c['review_task'] !== null => 'confirmed_review',
                    default => 'confirmed',
                };
                return OpResult::of(303, ['result' => $c['result'], 'version' => $c['version'], 'redirect' => Html::url('/ui/reference/company', ['notice' => $notice])]);
            });
        } catch (CwException $e) {
            if ($e->errorCode === 'company_changed') {
                return $this->page($ctx, 409, new CwException('company_changed', self::plain($ctx, $e, 'changed_confirmed')->getMessage() . ' '
                    . Words::COMPANY['now_details'], 409));
            }
            return $this->page($ctx, $e->httpStatus, self::plain($ctx, $e, 'changed_confirmed'));
        }
        return FormOnce::redirect($r);
    }

    public function approveReview(Context $ctx): HtmlResponse
    {
        return $this->decide($ctx, true);
    }

    public function rejectReview(Context $ctx): HtmlResponse
    {
        return $this->decide($ctx, false);
    }

    /** "See how a purchase order will look": a made-up order with the details in use, marked SAMPLE, no PO number (I96). */
    public function samplePdf(Context $ctx): HtmlResponse
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('Y-m-d');
        $bytes = (new PurchaseOrderPdf())->render(PurchaseOrderPdf::sampleData($ctx->company()->company(), $today, (string) $ctx->settings()->get('po.terms')));
        return FilesController::download($bytes, 'application/pdf', 'purchase-order-sample.pdf');
    }

    // ------------------------------------------------------------------------------------------

    private function decide(Context $ctx, bool $approve): HtmlResponse
    {
        try {
            $d = $ctx->company()->decideReview($ctx->caller(), $ctx->id(), $approve, $ctx->req->field('note'));
        } catch (CwException $e) {
            return $this->page($ctx, $e->httpStatus, self::plain($ctx, $e, 'changed_saved'));
        }
        $notice = $approve ? 'review_approved' : ($d['unconfirmed_version'] !== null ? 'review_rejected' : 'review_rejected_kept');
        return HtmlResponse::redirect(Html::url('/ui/reference/company', ['notice' => $notice]));
    }

    /** The details page, also after a refused confirm or check decision (the error shown, answered under its status). */
    public function page(Context $ctx, int $status, ?CwException $error, ?string $notice = null): HtmlResponse
    {
        $svc = $ctx->company();
        $me = $ctx->me();
        $p = $svc->current();
        $now = gmdate('Y-m-d H:i:s');
        $reviews = [];
        foreach ($svc->reviews() as $t) {
            $t['open'] = $t['state'] === 'open';
            // While nobody else could decide it (the owner is the only reviewer), an open check is not "overdue": it waits.
            $t['alone'] = $t['open'] && $t['others'] === 0;
            $t['overdue'] = $t['open'] && !$t['alone'] && (string) $t['due_at'] < $now;
            $t['refusal'] = null;
            $t['may_decide'] = false;
            if ($t['open']) {
                $no = $svc->refusal($me->id, $me->roles, $t);
                $t['refusal'] = Words::refusal($no);
                $t['may_decide'] = $no === null;
            }
            $t['who'] = (string) ($t['opened_by_name'] ?? self::actor(null, $t['opened_actor'] ?? null));
            $reviews[] = $t;
        }
        $missing = CompanyDetails::missing($p);
        $problems = CompanyDetails::problems($p);
        $unsaved = $p['version'] > 0 && CompanyDetails::unsaved($p);
        $canConfirm = $me->can('company.confirm');
        $canEdit = $me->can('company.edit');
        $purchasing = $me->can('purchasing.view');
        // Approved orders not sent yet that carry unconfirmed details: their PDF keeps saying "do not send" (they keep the details
        // they were approved with); the buyer amends them once the details are confirmed (I96).
        // Each such order is named, with a link to it (plan F140).
        $stale = $purchasing ? $ctx->db->all(
            "SELECT p.document_id, d.number FROM purchase_order p JOIN document d ON d.id = p.document_id WHERE p.state = 'approved' "
            . "AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p.company_snapshot, '$.confirmed')), 'false') <> 'true' ORDER BY d.number",
        ) : [];
        $history = [];
        foreach ($svc->history() as $h) {
            $h['saved_label'] = self::actor($h['saved_by_name'], $h['saved_actor']);
            $h['confirmed_label'] = self::actor($h['confirmed_by_name'], $h['confirmed_actor']);
            // One sentence per version, no version numbers (plan F142): "Sam confirmed them (another reviewer still has to check this change)".
            $h['what'] = match ($h['kind']) {
                'seed' => Words::COMPANY['h_seed'],
                'confirm' => Words::say('COMPANY', 'h_confirm', ucfirst($h['confirmed_label'])),
                'unconfirm' => Words::say('COMPANY', 'h_unconfirm', ucfirst($h['saved_label'])),
                default => Words::say('COMPANY', 'h_change', ucfirst($h['saved_label'])),
            };
            $h['check'] = $h['review'] === null ? null : Words::COMPANY[match ($h['review']['state']) {
                'open' => 'h_check_open',
                'approved' => 'h_check_ok',
                'rejected' => 'h_check_wrong',
                default => 'h_check_closed',
            }];
            $history[] = $h;
        }
        // Who may change them: a reviewer; for an account whose Reviewer job Admin switches off, say so (plan F034, F141).
        $lookOnly = $canEdit ? null : Words::COMPANY[Permissions::blockedByAdmin($me->roles, 'company.edit') ? 'look_admin' : 'look_reviewer'];
        return $ctx->page('company', [
            'p' => $p,
            'show' => self::shownValues($p),
            'savedLabel' => self::actor($p['saved_by_name'], $p['saved_actor']),
            'confirmedLabel' => self::actor($p['confirmed_by_name'], $p['confirmed_actor']),
            'missing' => $missing,
            'problems' => array_values($problems),
            'unsaved' => $unsaved,
            'vatWarning' => $p['vat_number'] !== '' && preg_match('/^(GB|XI)\d/', $p['vat_number']) === 1 && !CompanyDetails::vatChecksumOk($p['vat_number']),
            'canEdit' => $canEdit,
            'canConfirm' => $canConfirm,
            'mayConfirm' => $canConfirm && !$p['confirmed'] && $missing === [] && $problems === [] && !$unsaved,
            'confirmKey' => $canConfirm && !$p['confirmed'] ? FormOnce::newKey() : null,
            'reviews' => $reviews,
            'history' => $history,
            'staleOrders' => $stale,
            'rejectedOrders' => $purchasing ? $svc->ordersWithRejectedDetails() : [],
            'lookOnly' => $lookOnly,
            'error' => $error === null ? null : (isset(Words::REFUSAL[$error->errorCode]) && $error->errorCode !== 'role_not_allowed'
                ? Words::REFUSAL[$error->errorCode] : Words::error($error->errorCode, $error->getMessage())),
            'errorMissing' => $error !== null && is_array($error->detail['missing'] ?? null) ? $error->detail['missing'] : null,
        ], $status, ['title' => Words::MENU['company'], 'active' => 'company', 'notice' => $notice]);
    }

    /**
     * The form. $values: what the fields show (vat_registered as 'yes' | 'no' | ''); $version: what it carries; $changes:
     * after a stale save, what the other person changed (field, label, before, after).
     *
     * @param array<string, string> $values
     * @param list<array<string, string>> $changes
     */
    private function form(Context $ctx, array $values, int $version, string $formKey, string $reason, int $status, ?CwException $error,
        array $changes = []): HtmlResponse
    {
        $errors = $error !== null && is_array($error->detail['errors'] ?? null) ? $error->detail['errors'] : [];
        // A VAT choice error is shown with the VAT number.
        if (isset($errors['vat_registered']) && !isset($errors['vat_number'])) {
            $errors['vat_number'] = $errors['vat_registered'];
        }
        $p = $ctx->company()->current();
        return $ctx->page('company_form', [
            'v' => $values,
            'reason' => $reason,
            'version' => $version,
            'formKey' => $formKey,
            'confirmed' => $p['confirmed'],
            'error' => $error === null ? null : Words::error($error->errorCode, $error->getMessage()),
            'errorCode' => $error?->errorCode,
            'errors' => $errors,
            'changes' => $changes,
            'limits' => ['name' => CompanyDetails::NAME_MAX, 'phone' => CompanyDetails::PHONE_MAX, 'email' => CompanyDetails::EMAIL_MAX,
                'lines' => CompanyDetails::ADDRESS_LINES, 'line' => CompanyDetails::ADDRESS_LINE_MAX, 'reason' => CompanyDetails::REASON_MAX,
                'address' => CompanyDetails::ADDRESS_LINES * (CompanyDetails::ADDRESS_LINE_MAX + 1)],
            'help' => ['company_number' => CompanyDetails::COMPANY_NUMBER_HELP, 'vat' => CompanyDetails::VAT_HELP, 'phone' => CompanyDetails::PHONE_HELP],
        ], $status, ['title' => Words::title('company_edit'), 'active' => 'company']);
    }

    /** @return array<string, string> the form's fields as posted (a missing field is empty; vat_registered yes | no | '') */
    private static function posted(Context $ctx): array
    {
        $out = [];
        foreach (self::FORM_FIELDS as $k) {
            $out[$k] = $ctx->req->field($k) ?? '';
        }
        return $out;
    }

    /**
     * A profile as the form shows it: tidied as a save would store it (a seed copied from the old settings with extra spaces
     * shows its tidy form, so saving the form untouched changes nothing a person can see).
     *
     * @param array<string, mixed> $p
     * @return array<string, string>
     */
    private static function formValues(array $p): array
    {
        $tidy = CompanyDetails::tidy($p);
        $out = [];
        foreach (self::FORM_FIELDS as $k) {
            $out[$k] = $k === 'vat_registered' ? match ($tidy[$k]) {
                true => 'yes',
                false => 'no',
                default => '',
            } : ($k === 'vat_number' ? CompanyDetails::formatVat((string) $tidy[$k]) : (string) $tidy[$k]);
        }
        return $out;
    }

    /** @param array<string, mixed> $p @return array<string, string> the details as the page shows them ('' = not given) */
    private static function shownValues(array $p): array
    {
        return [
            'legal_name' => $p['legal_name'], 'trading_name' => $p['trading_name'], 'company_number' => $p['company_number'],
            'vat' => match (true) {
                $p['vat_registered'] === false => 'Not VAT registered',
                $p['vat_number'] !== '' => CompanyDetails::formatVat($p['vat_number']),
                default => '',
            },
            'address' => $p['address'], 'phone' => $p['phone'], 'email' => $p['email'], 'delivery_address' => $p['delivery_address'],
        ];
    }

    /** A person's name, or what people read for a set-up actor (never "system:..."). */
    private static function actor(?string $name, ?string $actor): string
    {
        return $name ?? self::ACTORS[(string) $actor] ?? (str_starts_with((string) $actor, 'system:') ? Words::COMPANY['set_up_short'] : (string) $actor);
    }

    /**
     * A refusal of the service in the page's words, by its code (the service's message is the API's and stays as it is): a
     * stale form names who saved the details and when (UK time); a refusal for a job says who may.
     */
    private static function plain(Context $ctx, CwException $e, string $changedKey): CwException
    {
        $text = match ($e->errorCode) {
            'company_changed' => (static function () use ($ctx, $e, $changedKey): string {
                $cur = $ctx->company()->current();
                if (!isset($e->detail['version'])) {
                    return Words::COMPANY['changed_same_time'];
                }
                return Words::say('COMPANY', $changedKey, self::actor($cur['saved_by_name'], $cur['saved_actor']), Html::when((string) $cur['saved_at']));
            })(),
            'role_not_allowed', 'admin_cannot_edit' => Words::COMPANY['not_reviewer'],
            default => null,
        };
        return $text === null ? $e : new CwException($e->errorCode, $text, $e->httpStatus, $e->detail);
    }

    /** The version a form carries: 0 (no details yet) or a positive whole number. */
    private static function version(?string $v): ?int
    {
        return $v !== null && preg_match('/^(0|[1-9][0-9]{0,9})$/D', $v) === 1 ? (int) $v : null;
    }
}
