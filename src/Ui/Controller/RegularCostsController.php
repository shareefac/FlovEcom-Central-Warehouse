<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Expenses\ExpenseAccess;
use CW\Expenses\ExpenseMoney;
use CW\Expenses\ExpenseTypes;
use CW\Expenses\ExpenseViews;
use CW\Expenses\RegularCostMath;
use CW\Expenses\RegularCosts;
use CW\Profit\ProfitSettings;
use CW\Profit\ProfitViews;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * Profit > Regular costs (CW-1150; docs/decisions.md EX14; the approved drawing MonthlyCosts of 24 Nov 2026): the costs with no invoice that
 * go out every week, month or year, grouped as the drawing groups them, with what is in force today ("Every week in all"); "Add regular
 * cost"; one line's page: its name, group, end and note changed, "The amount changes" (the line ends, a new one starts: the old days keep the
 * old amount), a delete with a reason for a line put in by mistake, its history. Finance and the Expenses job look; only the job writes.
 */
final class RegularCostsController
{
    public const PATH = '/ui/profit/regular';
    private const NOTICES = ['added', 'changed', 'unchanged', 'amount', 'deleted'];

    public function list(Context $ctx): HtmlResponse
    {
        $views = new ExpenseViews($ctx->db, $ctx->caller());
        $today = ProfitViews::today();
        $n = (string) ($ctx->req->param('notice') ?? '');
        // Exact days and a shop (from Real profit's lines, the Codex check r1, M7): each line's share of those days, and their total.
        [$from, $to] = [(string) ($ctx->req->param('from') ?? ''), (string) ($ctx->req->param('to') ?? '')];
        $range = RegularCostMath::isDay($from) && RegularCostMath::isDay($to) && $from <= $to ? [$from, $to] : null;
        [$range, $cut] = ExpensesController::capRange($ctx, $range);
        $shop = \CW\Ui\UiRequest::id($ctx->req->param('shop'));
        $shop = in_array($shop, array_column($views->shops(), 'id'), true) ? $shop : null;
        // The lines and their shares from ONE moment of the database (the Codex check r2, H5).
        [$list, $shares] = \CW\Profit\ProfitDays::snapshot($ctx->db, static function () use ($views, $range, $shop, $today): array {
            $shares = [];
            if ($range !== null) {
                foreach ($views->regularLines($range[0], $range[1], $shop) as $l) {
                    $shares[$l['id']] = $l['share'];
                }
            }
            return [$views->regularList($today, $range === null ? null : $shop), $shares];
        });
        return $ctx->page('profit_regular', [
            'groups' => $list['groups'],
            'today' => $list['today'],
            'todayCost' => $list['today_cost'],
            'canEdit' => $ctx->me()->can(ExpenseAccess::PERM_EDIT),
            'range' => $range,
            'shares' => $shares,
            'shopName' => $shop === null ? null : (string) $ctx->db->value('SELECT name FROM channel WHERE id = ?', [$shop]),
            'cut' => $cut,
            'vatPct' => ExpensesController::vatPct($ctx),
            'howCounts' => self::howCounts($ctx),
        ], 200, ['title' => Words::MENU['profit_regular'], 'notice' => in_array($n, self::NOTICES, true) ? Words::REGULAR['notice_' . $n] : null]);
    }

    /**
     * "How it is shared out" with the shops' VAT rate of the setting and its example worked out at that rate (Unit 1 £207.69 a week; the
     * Fable review of 70ab3d2, L3: never a fixed 20% or "/ 6").
     */
    public static function howCounts(Context $ctx): string
    {
        $rate = (new ProfitSettings($ctx->db))->vatBp();
        $vat = RegularCostMath::vat(20769, $rate);
        $cost = 20769 - $vat;
        return Words::say('REGULAR', 'how_counts', RegularCostMath::percent($rate), ExpenseMoney::gbp($vat), ExpenseMoney::gbp($cost),
            ExpenseMoney::gbp(intdiv(2 * $cost + 7, 14)), ExpenseMoney::gbp(RegularCostMath::share('week', $cost, '2026-11-01', null, '2026-11-01', '2026-11-30')));
    }

    public function newForm(Context $ctx): HtmlResponse
    {
        return $this->form($ctx, 200, null, []);
    }

    public function add(Context $ctx): HtmlResponse
    {
        $f = static fn (string $k): string => (string) ($ctx->req->field($k) ?? '');
        $typed = ['name' => $f('name'), 'shop' => $f('shop'), 'type' => $f('type'), 'vat' => $f('vat'), 'every' => $f('every'), 'amount' => $f('amount'),
            'from' => $f('from'), 'until' => $f('until'), 'note' => $f('note')];
        try {
            $same = ExpensesController::same($ctx);
            // One effect per form (FormOnce, I46; the Codex check r3, H1): the same form sent again, a confirmed second one too, adds nothing more.
            $r = \CW\Ui\FormOnce::run($ctx, 'ui.regular_cost.add', $typed + ['same_ok' => $same],
                static function (\CW\Db $db) use ($ctx, $typed, $same): \CW\OpResult {
                    $id = (new RegularCosts($db))->add($ctx->caller(), $typed, $same);
                    return \CW\OpResult::of(303, ['redirect' => Html::url(self::PATH . '/' . $id, ['notice' => 'added'])]);
                });
        } catch (CwException $e) {
            return ExpensesController::deniedWrite($e) ? ExpensesController::denied($ctx, $e) : $this->form($ctx, $e->httpStatus, $e, $typed);
        }
        return \CW\Ui\FormOnce::redirect($r);
    }

    public function show(Context $ctx): HtmlResponse
    {
        $n = (string) ($ctx->req->param('notice') ?? '');
        return $this->one($ctx, $ctx->id(), 200, null, null, in_array($n, self::NOTICES, true) ? Words::REGULAR['notice_' . $n] : null);
    }

    /** POST {id}/change: name, group, VAT choice, last day, note. */
    public function change(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $f = static fn (string $k): string => (string) ($ctx->req->field($k) ?? '');
        $typed = ['name' => $f('name'), 'type' => $f('type'), 'vat' => $f('vat'), 'until' => $f('until'), 'note' => $f('note'), 'form' => 'change'];
        try {
            $changed = (new RegularCosts($ctx->db))->change($ctx->caller(), $id, self::seen($ctx), $typed, ExpensesController::same($ctx));
        } catch (CwException $e) {
            return $this->refused($ctx, $id, $e, $typed);
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/' . $id, ['notice' => $changed ? 'changed' : 'unchanged']));
    }

    /** POST {id}/amount: the amount changes from a day (a new line). */
    public function amount(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $typed = ['amount' => (string) ($ctx->req->field('amount') ?? ''), 'from' => (string) ($ctx->req->field('from') ?? ''), 'form' => 'amount'];
        try {
            $new = (new RegularCosts($ctx->db))->newAmount($ctx->caller(), $id, self::seen($ctx), $typed['amount'], $typed['from']);
        } catch (CwException $e) {
            return $this->refused($ctx, $id, $e, $typed);
        }
        // The same amount at the same VAT: nothing changed, the same line (EX46).
        return HtmlResponse::redirect(Html::url(self::PATH . '/' . $new, ['notice' => $new === $id ? 'unchanged' : 'amount']));
    }

    /** POST {id}/delete: a line put in by mistake, with its box ticked and a reason. */
    public function delete(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        try {
            if ($ctx->req->field('sure') !== '1') {
                throw new CwException('not_sure', Words::EXPENSES_ERROR['not_sure'], 422, ['field' => 'sure']);
            }
            (new RegularCosts($ctx->db))->delete($ctx->caller(), $id, self::seen($ctx), (string) ($ctx->req->field('reason') ?? ''));
        } catch (CwException $e) {
            return $this->refused($ctx, $id, $e, ['form' => 'delete']);
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/' . $id, ['notice' => 'deleted']));
    }

    /** About a day's share today, as the page says it ("£24.72 a day"): the cost over 7, this month's days or this year's days, half up. */
    public static function perDay(string $every, int $amount, string $today): int
    {
        $den = match ($every) {
            'week' => 7,
            'month' => RegularCostMath::days(substr($today, 0, 8) . '01', ExpenseViews::monthDays(substr($today, 0, 7))[1]),
            default => RegularCostMath::days(substr($today, 0, 4) . '-01-01', substr($today, 0, 4) . '-12-31'),
        };
        return intdiv(2 * $amount + $den, 2 * $den);
    }

    private static function seen(Context $ctx): int
    {
        $s = (string) ($ctx->req->field('seen') ?? '');
        if (preg_match('/^[1-9]\d{0,8}$/D', $s) !== 1) {
            throw new CwException('bad_form', Words::ERROR['bad_form'], 400);
        }
        return (int) $s;
    }

    /** @param array<string, string> $typed */
    private function form(Context $ctx, int $status, ?CwException $error, array $typed): HtmlResponse
    {
        return $ctx->page('profit_regular_new', [
            'shops' => (new ExpenseViews($ctx->db))->shops(),
            'types' => (new ExpenseTypes($ctx->db))->active('regular'),
            // "Includes 20% VAT" picked for you (the owner, 24 Nov 2026: EX38).
            'typed' => $typed + ['name' => '', 'shop' => '', 'type' => '', 'vat' => 'inc', 'every' => 'week', 'amount' => '', 'from' => '', 'until' => '', 'note' => ''],
            'vatRate' => RegularCostMath::percent((new ProfitSettings($ctx->db))->vatBp()),
            'error' => $error === null ? null : ExpensesController::plain($error),
            'errorField' => is_string($error?->detail['field'] ?? null) ? $error->detail['field'] : null,
            'sameAsk' => $error?->errorCode === 'same_cost',
            'sameIds' => is_string($error?->detail['confirm'] ?? null) ? $error->detail['confirm'] : '',
            'sameList' => ExpensesController::sameList($error),
            'formKey' => ExpensesController::formKey($ctx, $error),
            'vatPct' => ExpensesController::vatPct($ctx),
            'howCounts' => self::howCounts($ctx),
        ], $status, ['title' => Words::REGULAR['new_title']]);
    }

    /**
     * The line's page after a refused form (the Codex check r1, H2): changed by someone else meanwhile, what is saved NOW and its version (what was
     * typed is dropped); refused for anything else, what was typed and the version the form was drawn with.
     *
     * @param array<string, string> $typed
     */
    private function refused(Context $ctx, int $id, CwException $e, array $typed): HtmlResponse
    {
        if (ExpensesController::deniedWrite($e)) {
            return ExpensesController::denied($ctx, $e);
        }
        if ($e->errorCode === 'changed_meanwhile') {
            return $this->one($ctx, $id, $e->httpStatus, $e, null, null);
        }
        $s = (string) ($ctx->req->field('seen') ?? '');
        return $this->one($ctx, $id, $e->httpStatus, $e, $typed, null, preg_match('/^[1-9]\d{0,8}$/D', $s) === 1 ? (int) $s : null);
    }

    /**
     * @param array<string, string>|null $typed what a refused form said ('form': which one)
     * @param int|null $seen the version the forms carry (null: the line's own)
     */
    private function one(Context $ctx, int $id, int $status, ?CwException $error, ?array $typed, ?string $notice, ?int $seen = null): HtmlResponse
    {
        $r = (new ExpenseViews($ctx->db, $ctx->caller()))->regular($id);
        if ($r === null) {
            return $ctx->error(404, 'not_found', Words::EXPENSES_ERROR['no_such_regular'], [self::PATH, Words::MENU['profit_regular']]);
        }
        $types = (new ExpenseTypes($ctx->db))->active('regular');
        if (!in_array((int) $r['type_id'], array_column($types, 'id'), true)) {
            $types[] = ['id' => (int) $r['type_id'], 'name' => (string) $r['grp']];
        }
        $today = ProfitViews::today();
        $month = ExpenseViews::monthDays(substr($today, 0, 7));
        $until = $r['until_day'] === null ? null : (string) $r['until_day'];
        $form = $typed['form'] ?? null;
        $rate = (int) $r['vat_rate_bp'];
        $included = (int) $r['vat_included'] === 1;
        [$vat, $cost] = ExpenseViews::vatAndCost((int) $r['amount_pence'], $rate);
        // A line continuing another (a new amount): the live line just before it by date in its chain (ExpenseViews: read with the line, in one
        // moment) ends before this one starts, and stays so when this one is deleted (the Fable review of 70ab3d2, M1; the Codex re-check of
        // 8dbee81, C5: by date across the whole chain): its page says so on the delete form and once deleted.
        $before = $r['replaces_id'] === null ? null : $r['prev'];
        return $ctx->page('profit_regular_one', [
            'r' => $r,
            'types' => $types,
            'typed' => ['name' => $form === 'change' ? $typed['name'] : (string) $r['name'], 'type' => $form === 'change' ? $typed['type'] : (string) $r['type_id'],
                'until' => $form === 'change' ? $typed['until'] : (string) ($until ?? ''), 'note' => $form === 'change' ? $typed['note'] : (string) ($r['note'] ?? ''),
                'vat' => $form === 'change' && in_array($typed['vat'], ['inc', 'none'], true) ? $typed['vat'] : ($included ? 'inc' : 'none'),
                'amount' => $form === 'amount' ? $typed['amount'] : '', 'from' => $form === 'amount' ? $typed['from'] : ''],
            'form' => $form,
            // Shared out without VAT (EX38).
            'perDay' => self::perDay((string) $r['every'], $cost, $today),
            'thisMonth' => RegularCostMath::share((string) $r['every'], $cost, (string) $r['from_day'], $until, $month[0], $month[1]),
            'monthName' => (new \DateTimeImmutable($month[0]))->format('F Y'),
            'canEdit' => $ctx->me()->can(ExpenseAccess::PERM_EDIT) && $r['deleted_at'] === null,
            'error' => $error === null ? null : ExpensesController::plain($error),
            'errorField' => is_string($error?->detail['field'] ?? null) ? $error->detail['field'] : null,
            'amountText' => ExpenseMoney::gbp((int) $r['amount_pence']),
            'vatText' => $included ? Words::say('REGULAR', 'vat_amount', ExpenseMoney::gbp($vat), RegularCostMath::percent($rate)) : Words::REGULAR['vat_none'],
            'costText' => ExpenseMoney::gbp($cost),
            // The rate "Includes ... VAT" names: the line's own, or the shops' rate of today for a line with none.
            'vatRate' => RegularCostMath::percent($included ? $rate : (new ProfitSettings($ctx->db))->vatBp()),
            'seen' => $seen ?? (int) $r['version'],
            'sameAsk' => $error?->errorCode === 'same_cost',
            'sameIds' => is_string($error?->detail['confirm'] ?? null) ? $error->detail['confirm'] : '',
            'sameList' => ExpensesController::sameList($error),
            'before' => $before === null || $before['until_day'] === null ? null
                : ['id' => (int) $before['id'], 'until' => (string) $before['until_day']],
            'vatPct' => ExpensesController::vatPct($ctx),
        ], $status, ['title' => (string) $r['name'], 'notice' => $notice]);
    }
}
