<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Expenses\ExpenseAccess;
use CW\Expenses\ExpenseFiles;
use CW\Expenses\ExpenseMoney;
use CW\Expenses\Expenses;
use CW\Expenses\ExpenseTypes;
use CW\Expenses\ExpenseViews;
use CW\Expenses\RegularCostMath;
use CW\Files\FileStore;
use CW\Profit\ProfitViews;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Profit > Expenses (CW-1150; docs/decisions.md EX13; the approved drawings Main and AddExpense of 24 Nov 2026): the month's expenses by
 * shop (a month, a shop, one search on the supplier or the invoice number, Show), the three shops' month totals, each shop's regular costs
 * of the month on their own line; "Add expense" (the shop picked each time with three big buttons, no default); one expense's page (its PDF,
 * change it, delete it with a reason, its history); the expenses' own suppliers. Finance and the Expenses job look (expenses.view); only the
 * Expenses job writes (expenses.edit; the services check it again). The invoice file is sent only here, to those who see the expenses.
 */
final class ExpensesController
{
    public const PATH = '/ui/profit/expenses';
    public const PERM_VIEW = ExpenseAccess::PERM_VIEW;
    public const PERM_EDIT = ExpenseAccess::PERM_EDIT;
    private const NOTICES = ['added', 'changed', 'unchanged', 'deleted', 'supplier_added', 'supplier_changed'];

    /** GET: the list of a month (?month=YYYY-MM | all, ?shop=, ?q=, ?deleted=1). */
    public function list(Context $ctx): HtmlResponse
    {
        $views = new ExpenseViews($ctx->db);
        $today = ProfitViews::today();
        $m = (string) ($ctx->req->param('month') ?? '');
        $month = $m === 'all' ? null : (ExpenseViews::isMonth($m) ? $m : substr($today, 0, 7));
        $shops = $views->shops();
        $shop = UiRequest::id($ctx->req->param('shop'));
        $shop = in_array($shop, array_column($shops, 'id'), true) ? $shop : null;
        $q = trim((string) ($ctx->req->param('q') ?? ''));
        $q = mb_check_encoding($q, 'UTF-8') && mb_strlen($q) <= 64 && preg_match('/[\x00-\x1F\x7F]/', $q) !== 1 ? $q : '';
        $deleted = $ctx->req->param('deleted') === '1';
        // Exact days (from Real profit's lines, the Codex check r1, M7): both real days, the first not after the last; else the month.
        [$from, $to] = [(string) ($ctx->req->param('from') ?? ''), (string) ($ctx->req->param('to') ?? '')];
        $range = RegularCostMath::isDay($from) && RegularCostMath::isDay($to) && $from <= $to ? [$from, $to] : null;
        $data = $range !== null ? $views->days($range[0], $range[1], $shop, $q, $deleted) : $views->month($month, $shop, $q, $deleted);
        $months = $views->months($today, $deleted);
        if ($month !== null && !in_array($month, $months, true)) {
            $months[] = $month;
            rsort($months);
        }
        $n = (string) ($ctx->req->param('notice') ?? '');
        return $ctx->page('profit_expenses', $data + [
            'months' => $months,
            'month' => $range === null ? $month : null,
            'range' => $range,
            'shops' => $shops,
            'shopId' => $shop,
            'q' => $q,
            'deleted' => $deleted,
            'canEdit' => $ctx->me()->can(self::PERM_EDIT),
            'limit' => ExpenseViews::LIST_LIMIT,
        ], 200, ['title' => Words::MENU['profit_expenses'], 'notice' => in_array($n, self::NOTICES, true) ? Words::EXPENSES['notice_' . $n] : null]);
    }

    /** GET new: the add form (empty; the shop never picked for you). */
    public function newForm(Context $ctx): HtmlResponse
    {
        $n = (string) ($ctx->req->param('notice') ?? '');
        return $this->form($ctx, 200, null, [], $n === 'added' ? Words::EXPENSES['notice_added'] : null);
    }

    /** POST new: adds the expense; "Save and add another" comes back to an empty form. */
    public function add(Context $ctx): HtmlResponse
    {
        $typed = self::typed($ctx);
        try {
            $file = self::upload($ctx, false);
            self::fileAgain($ctx, $file);
            $sameOk = $ctx->req->field('same_ok') === '1';
            $another = $ctx->req->field('then') === 'another';
            // One effect per form (FormOnce, I46; the Codex check r3, H1): the same form sent again (a lost answer, a retry), a confirmed second
            // invoice too, lands where the first did and adds nothing. What it keeps of the form: the fields and the file's fingerprint.
            $request = $typed + ['same_ok' => $sameOk ? '1' : '', 'then' => $another ? 'another' : 'done',
                'file' => $file === null ? '' : (string) hash_file('sha256', $file['path'])];
            $r = FormOnce::run($ctx, 'ui.expense.add', $request, static function (\CW\Db $db) use ($ctx, $typed, $file, $sameOk, $another): \CW\OpResult {
                $x = (new Expenses($db, static fn (): FileStore => $ctx->files()))->add($ctx->caller(), $typed, $file, $sameOk);
                return \CW\OpResult::of(303, ['redirect' => $another ? Html::url(self::PATH . '/new', ['notice' => 'added'])
                    : Html::url(self::PATH . '/' . $x['id'], ['notice' => 'added'])]);
            });
        } catch (CwException $e) {
            return $this->form($ctx, $e->httpStatus, $e, $typed, null);
        }
        return FormOnce::redirect($r);
    }

    /** GET {id}: one expense. */
    public function show(Context $ctx): HtmlResponse
    {
        $n = (string) ($ctx->req->param('notice') ?? '');
        return $this->one($ctx, $ctx->id(), 200, null, null, in_array($n, self::NOTICES, true) ? Words::EXPENSES['notice_' . $n] : null);
    }

    /** POST {id}/change. */
    public function change(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $typed = self::typed($ctx);
        try {
            $file = self::upload($ctx, false);
            self::fileAgain($ctx, $file);
            $r = (new Expenses($ctx->db, static fn (): FileStore => $ctx->files()))->change($ctx->caller(), $id, self::seen($ctx), $typed, $file,
                $ctx->req->field('same_ok') === '1');
        } catch (CwException $e) {
            return $this->refused($ctx, $id, $e, $typed);
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/' . $id, ['notice' => $r['changed'] ? 'changed' : 'unchanged']));
    }

    /** POST {id}/delete: only with its box ticked and a reason. */
    public function delete(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        try {
            if ($ctx->req->field('sure') !== '1') {
                throw new CwException('not_sure', Words::EXPENSES_ERROR['not_sure'], 422, ['field' => 'sure']);
            }
            (new Expenses($ctx->db))->delete($ctx->caller(), $id, self::seen($ctx), (string) ($ctx->req->field('reason') ?? ''));
        } catch (CwException $e) {
            return $this->refused($ctx, $id, $e, null);
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/' . $id, ['notice' => 'deleted']));
    }

    /** GET {id}/file: the invoice PDF or photo, as an attachment of its sniffed type (FilesController::download). */
    public function file(Context $ctx): HtmlResponse
    {
        $x = $ctx->db->one('SELECT e.file_id, e.invoice_no, e.invoice_date, s.name AS supplier FROM expense e JOIN expense_supplier s ON s.id = e.supplier_id '
            . 'WHERE e.id = ?', [$ctx->id()]);
        if ($x === null || $x['file_id'] === null) {
            return $ctx->error(404, 'not_found', Words::EXPENSES_ERROR['no_such_file'], [self::PATH, Words::MENU['profit_expenses']]);
        }
        // The same rules as the general file address: bytes that are also a supplier's invoice copy need invoices.view as well (the Codex check
        // r2, H8; EX24).
        $refused = FilesController::refusal($ctx, (int) $x['file_id']);
        if ($refused !== null) {
            return $refused;
        }
        $file = $ctx->files()->read((int) $x['file_id']);
        $mime = (string) $file['meta']['mime'];
        // Named from the expense ("Viking Direct 58812290.pdf"); the store keeps a neutral name (EX23).
        $name = trim((string) $x['supplier'] . ' ' . ((string) $x['invoice_no'] !== '' ? (string) $x['invoice_no'] : (string) $x['invoice_date']));
        return FilesController::download($file['bytes'], $mime, FileStore::downloadName($name . '.' . (FileStore::EXTENSIONS[$mime][0] ?? 'bin'), $mime));
    }

    /** GET suppliers: the expenses' own suppliers. */
    public function suppliers(Context $ctx): HtmlResponse
    {
        $n = (string) ($ctx->req->param('notice') ?? '');
        return $this->suppliersPage($ctx, 200, null, null, in_array($n, self::NOTICES, true) ? Words::EXPENSES['notice_' . $n] : null);
    }

    /** POST suppliers/add. */
    public function addSupplier(Context $ctx): HtmlResponse
    {
        $name = (string) ($ctx->req->field('name') ?? '');
        try {
            (new Expenses($ctx->db))->addSupplier($ctx->caller(), $name);
        } catch (CwException $e) {
            return $this->suppliersPage($ctx, $e->httpStatus, $e, $name, null);
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/suppliers', ['notice' => 'supplier_added']));
    }

    /** POST suppliers/{id}/change: do=rename (name) | off | on. */
    public function changeSupplier(Context $ctx): HtmlResponse
    {
        $do = (string) ($ctx->req->field('do') ?? '');
        try {
            if (!in_array($do, ['rename', 'off', 'on'], true)) {
                throw new CwException('bad_form', Words::ERROR['bad_form'], 400);
            }
            (new Expenses($ctx->db))->changeSupplier($ctx->caller(), $ctx->id(), self::seen($ctx), $do === 'rename' ? (string) ($ctx->req->field('name') ?? '') : null,
                $do === 'rename' ? null : $do === 'on');
        } catch (CwException $e) {
            return $this->suppliersPage($ctx, $e->httpStatus, $e, null, null);
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/suppliers', ['notice' => 'supplier_changed']));
    }

    /** A refusal of the expenses' services in words, by its code (with its numbers); another code keeps the service's message. */
    public static function plain(CwException $e): string
    {
        return match ($e->errorCode) {
            'same_invoice' => Words::say('EXPENSES_ERROR', ($e->detail['credit'] ?? false) === true ? 'same_credit' : 'same_invoice',
                is_string($e->detail['date'] ?? null) ? Html::day($e->detail['date']) : ''),
            'same_cost' => Words::say('EXPENSES_ERROR', 'same_cost', is_string($e->detail['from'] ?? null) ? Html::day($e->detail['from']) : ''),
            'file_too_large', 'too_large' => Words::say('EXPENSES_ERROR', 'file_too_large', intdiv(ExpenseFiles::MAX_BYTES, 1_048_576)),
            'changed_meanwhile' => Words::say('EXPENSES_ERROR', 'changed_meanwhile', (string) ($e->detail['by'] ?? '?')),
            default => Words::EXPENSES_ERROR[$e->errorCode] ?? Words::error($e->errorCode, $e->getMessage()),
        };
    }

    /** @return array<string, mixed> the form's fields as typed */
    private static function typed(Context $ctx): array
    {
        $f = static fn (string $k): string => (string) ($ctx->req->field($k) ?? '');
        return ['shop' => $f('shop'), 'supplier' => $f('supplier'), 'new_supplier' => $ctx->req->field('new_supplier') === '1', 'type' => $f('type'),
            'invoice_date' => $f('invoice_date'), 'invoice_no' => $f('invoice_no'), 'credit' => $ctx->req->field('credit') === '1', 'net' => $f('net'), 'vat' => $f('vat'),
            'note' => $f('note')];
    }

    /** @return array{path: string, name: string}|null the uploaded file, or null when none was chosen */
    private static function upload(Context $ctx, bool $required): ?array
    {
        $file = $ctx->req->file('file');
        if ($file === null || ($file['error'] === UPLOAD_ERR_NO_FILE && !$required)) {
            return $required ? throw new CwException('no_file', Words::EXPENSES_ERROR['no_file'], 400, ['field' => 'file']) : null;
        }
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > ExpenseFiles::MAX_BYTES) {
            throw new CwException('file_too_large', 'the file is too large', 413, ['field' => 'file']);
        }
        if ($file['error'] !== UPLOAD_ERR_OK || $file['path'] === '') {
            throw new CwException('upload_failed', Words::EXPENSES_ERROR['upload_failed'], 400, ['field' => 'file']);
        }
        return ['path' => (string) $file['path'], 'name' => (string) $file['name']];
    }

    /**
     * A form sent back after a refusal while a file had been chosen says so (file_again, a hidden field): sent again without a file it is
     * refused (422 file_again) unless "Save without a file" is ticked, so the PDF is never lost unseen (the Codex check r2, M10; EX28).
     *
     * @param array{path: string, name: string}|null $file
     */
    private static function fileAgain(Context $ctx, ?array $file): void
    {
        if ($file === null && $ctx->req->field('file_again') === '1' && $ctx->req->field('no_file_ok') !== '1') {
            throw new CwException('file_again', Words::EXPENSES_ERROR['file_again'], 422, ['field' => 'file']);
        }
    }

    /** Whether the form being answered had a file chosen, or was itself sent back asking for one (its refusal must ask again). */
    private static function fileChosen(Context $ctx): bool
    {
        return $ctx->req->file('file') !== null || ($ctx->req->field('file_again') === '1' && $ctx->req->field('no_file_ok') !== '1');
    }

    /**
     * The form's one-effect key: the one sent, when the form comes back refused (nothing was kept under it: sent again, corrected, it is the
     * same form), else a new one (I46).
     */
    public static function formKey(Context $ctx, ?CwException $error): string
    {
        $k = (string) ($ctx->req->field(FormOnce::FIELD) ?? '');
        return $error !== null && !in_array($error->errorCode, ['bad_form_key', 'idempotency_key_reused'], true) && preg_match('/^[0-9a-f]{32}$/D', $k) === 1
            ? $k : FormOnce::newKey();
    }

    private static function seen(Context $ctx): int
    {
        $s = (string) ($ctx->req->field('seen') ?? '');
        if (preg_match('/^[1-9]\d{0,8}$/D', $s) !== 1) {
            throw new CwException('bad_form', Words::ERROR['bad_form'], 400);
        }
        return (int) $s;
    }

    /**
     * The add form, empty or with what was typed and why it was refused.
     *
     * @param array<string, mixed> $typed
     */
    private function form(Context $ctx, int $status, ?CwException $error, array $typed, ?string $notice): HtmlResponse
    {
        $views = new ExpenseViews($ctx->db);
        return $ctx->page('profit_expense_new', [
            'shops' => $views->shops(),
            'types' => (new ExpenseTypes($ctx->db))->active('expense'),
            'suppliers' => array_values(array_filter($views->suppliers(), static fn (array $s): bool => $s['is_active'])),
            'typed' => $typed + ['shop' => '', 'supplier' => '', 'new_supplier' => false, 'type' => '', 'invoice_date' => '', 'invoice_no' => '', 'credit' => false,
                'net' => '', 'vat' => '', 'note' => ''],
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
            'errorField' => is_string($error?->detail['field'] ?? null) ? $error->detail['field'] : null,
            'sameAsk' => $error?->errorCode === 'same_invoice',
            'other' => is_int($error?->detail['other'] ?? null) ? self::PATH . '/' . $error->detail['other'] : null,
            'maxMb' => intdiv(ExpenseFiles::MAX_BYTES, 1_048_576),
            'fileAgain' => $error !== null && self::fileChosen($ctx),
            'formKey' => self::formKey($ctx, $error),
        ], $status, ['title' => Words::EXPENSES['new_title'], 'notice' => $notice]);
    }

    /**
     * The expense's page after a refused change or delete (the Codex check r1, H2): changed by someone else meanwhile, the page shows what is
     * saved NOW and its version (what was typed is dropped: the person makes the change again on the new figures, never sends old ones over the
     * new); refused for anything else, it keeps what was typed AND the version the form was drawn with, so sending it again after someone
     * else's change is refused too.
     *
     * @param array<string, mixed>|null $typed
     */
    private function refused(Context $ctx, int $id, CwException $e, ?array $typed): HtmlResponse
    {
        if ($e->errorCode === 'changed_meanwhile') {
            return $this->one($ctx, $id, $e->httpStatus, $e, null, null);
        }
        $s = (string) ($ctx->req->field('seen') ?? '');
        return $this->one($ctx, $id, $e->httpStatus, $e, $typed, null, preg_match('/^[1-9]\d{0,8}$/D', $s) === 1 ? (int) $s : null);
    }

    /**
     * @param array<string, mixed>|null $typed what a refused change said
     * @param int|null $seen the version the forms carry (null: the expense's own)
     */
    private function one(Context $ctx, int $id, int $status, ?CwException $error, ?array $typed, ?string $notice, ?int $seen = null): HtmlResponse
    {
        $views = new ExpenseViews($ctx->db);
        $x = $views->expense($id);
        if ($x === null) {
            return $ctx->error(404, 'not_found', Words::EXPENSES_ERROR['no_such_expense'], [self::PATH, Words::MENU['profit_expenses']]);
        }
        $types = (new ExpenseTypes($ctx->db))->active('expense');
        if (!in_array((int) $x['type_id'], array_column($types, 'id'), true)) {
            $types[] = ['id' => (int) $x['type_id'], 'name' => (string) $x['type']]; // its own type, switched off since, stays its choice
        }
        $title = Words::say('EXPENSES', 'one_title', (string) $x['supplier'], (string) $x['invoice_no'] !== '' ? (string) $x['invoice_no'] : Html::day((string) $x['invoice_date']));
        return $ctx->page('profit_expense', [
            'x' => $x,
            'shops' => $views->shops(),
            'types' => $types,
            'suppliers' => array_values(array_filter($views->suppliers(), static fn (array $s): bool => $s['is_active'] || $s['id'] === (int) $x['supplier_id'])),
            'typed' => $typed ?? ['shop' => (string) $x['channel_id'], 'supplier' => (string) $x['supplier'], 'new_supplier' => false, 'type' => (string) $x['type_id'],
                // A credit note's amounts as printed, with its tick on (EX37).
                'invoice_date' => (string) $x['invoice_date'], 'invoice_no' => (string) $x['invoice_no'], 'credit' => (int) $x['net_pence'] < 0,
                'net' => ExpenseMoney::plain(abs((int) $x['net_pence'])), 'vat' => ExpenseMoney::plain(abs((int) $x['vat_pence'])), 'note' => (string) ($x['note'] ?? '')],
            'canEdit' => $ctx->me()->can(self::PERM_EDIT) && $x['deleted_at'] === null,
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
            'errorField' => is_string($error?->detail['field'] ?? null) ? $error->detail['field'] : null,
            'sameAsk' => $error?->errorCode === 'same_invoice',
            'maxMb' => intdiv(ExpenseFiles::MAX_BYTES, 1_048_576),
            'listHref' => Html::url(self::PATH, ['month' => substr((string) $x['invoice_date'], 0, 7)]),
            // A change refused because someone else changed it shows their figures and still asks for the file chosen (the Codex check r3, M8).
            'fileAgain' => $error !== null && self::fileChosen($ctx),
            'seen' => $seen ?? (int) $x['version'],
        ], $status, ['title' => $title, 'notice' => $notice]);
    }

    private function suppliersPage(Context $ctx, int $status, ?CwException $error, ?string $typed, ?string $notice): HtmlResponse
    {
        return $ctx->page('profit_expense_suppliers', [
            'suppliers' => (new ExpenseViews($ctx->db))->suppliers(),
            'canEdit' => $ctx->me()->can(self::PERM_EDIT),
            'typed' => $typed ?? '',
            'error' => $error === null ? null : self::plain($error),
        ], $status, ['title' => Words::EXPENSES['suppliers_title'], 'notice' => $notice]);
    }
}
