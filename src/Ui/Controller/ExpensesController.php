<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Ai\AiSettings;
use CW\Ai\DocumentReader;
use CW\Ai\ExpenseInvoiceKind;
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
use CW\Ui\AiReadWords;
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
    /** The form's VAT choice (EX65): includes the shops' rate, no VAT, another VAT typed. */
    private const VAT_WAYS = ['inc', 'none', 'other'];

    public const PATH = '/ui/profit/expenses';
    public const PERM_VIEW = ExpenseAccess::PERM_VIEW;
    public const PERM_EDIT = ExpenseAccess::PERM_EDIT;
    private const NOTICES = ['added', 'changed', 'unchanged', 'deleted', 'supplier_added', 'supplier_changed'];

    /** GET: the list of a month (?month=YYYY-MM | all, ?shop=, ?q=, ?deleted=1). */
    public function list(Context $ctx): HtmlResponse
    {
        $views = new ExpenseViews($ctx->db, $ctx->caller());
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
        [$range, $cut] = self::capRange($ctx, $range);
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
            'cut' => $cut,
            'vatPct' => self::vatPct($ctx),
        ], 200, ['title' => Words::MENU['profit_expenses'], 'notice' => in_array($n, self::NOTICES, true) ? Words::EXPENSES['notice_' . $n] : null]);
    }

    /**
     * Exact days asked for (from Real profit's lines) taken at most reports.sales_max_days at once, as Real profit takes them (the Fable review
     * of 70ab3d2, L2; docs/decisions.md EX51): a longer span keeps its last day and is cut at the front, and the page says so.
     *
     * @param array{0: string, 1: string}|null $range
     * @return array{0: array{0: string, 1: string}|null, 1: ?string} the days, and the words saying they were cut
     */
    public static function capRange(Context $ctx, ?array $range): array
    {
        if ($range === null) {
            return [null, null];
        }
        $max = \CW\Settings::number($ctx->db, 'reports.sales_max_days', 366);
        if (RegularCostMath::days($range[0], $range[1]) <= $max) {
            return [$range, null];
        }
        $first = RegularCostMath::addDays($range[1], -($max - 1));
        return [[$first, $range[1]], Words::say('EXPENSES', 'range_cut', $max, Html::day($first))];
    }

    /** The shops' VAT rate of now as the help texts say it ("20%"; the Fable review of 70ab3d2, L3). */
    public static function vatPct(Context $ctx): string
    {
        return RegularCostMath::percent((new \CW\Profit\ProfitSettings($ctx->db))->vatBp());
    }

    /**
     * Whether a write was refused for the person's jobs (taken away between the route's check and the service's: role_not_allowed,
     * admin_cannot_post, staff_required): the answer is a plain refusal, never the expense or its history (the final review of 70ab3d2, astra
     * MEDIUM; docs/decisions.md EX48).
     */
    public static function deniedWrite(CwException $e): bool
    {
        return $e->httpStatus === 403 && in_array($e->errorCode, ['role_not_allowed', 'admin_cannot_post', 'staff_required'], true);
    }

    /** The plain refusal deniedWrite() answers with. */
    public static function denied(Context $ctx, CwException $e): HtmlResponse
    {
        return $ctx->error(403, $e->errorCode, Words::error($e->errorCode, $e->getMessage()));
    }

    /**
     * The confirmation a form carries (same_ids: the digest of the 409 it answers, of the record and every one it met: Expenses::confirmation),
     * when "it is another one" is ticked; else none (EX43, EX58).
     */
    public static function same(Context $ctx): string
    {
        $digest = (string) ($ctx->req->field('same_ids') ?? '');
        return $ctx->req->field('same_ok') === '1' && preg_match('/^[0-9a-f]{64}$/D', $digest) === 1 ? $digest : '';
    }

    /**
     * Every record a "same one" question met, as the forms list them (the Codex re-check of 8dbee81, C1; the Fable re-check F-B): its link and its
     * words (an expense: its number or "no invoice number", its date, its total and why; a regular cost: its name and days).
     *
     * @return list<array{href: string, text: string}>
     */
    public static function sameList(?CwException $error): array
    {
        $out = [];
        foreach (is_array($error?->detail['matches'] ?? null) ? $error->detail['matches'] : [] as $m) {
            if (!is_array($m) || !is_int($m['id'] ?? null)) {
                continue;
            }
            $out[] = $error->errorCode === 'same_cost'
                ? ['href' => RegularCostsController::PATH . '/' . $m['id'], 'text' => is_string($m['until'] ?? null)
                    ? Words::say('REGULAR', 'same_row_until', (string) ($m['name'] ?? ''), Html::day((string) ($m['from'] ?? '')), Html::day($m['until']))
                    : Words::say('REGULAR', 'same_row', (string) ($m['name'] ?? ''), Html::day((string) ($m['from'] ?? '')))]
                : ['href' => self::PATH . '/' . $m['id'], 'text' => Words::say('EXPENSES', ($m['why'] ?? '') === 'number' ? 'same_row_number' : 'same_row_day',
                    (string) ($m['invoice_no'] ?? '') !== '' ? (string) $m['invoice_no'] : Words::EXPENSES['same_no_number'], Html::day((string) ($m['date'] ?? '')),
                    ExpenseMoney::gbp((int) ($m['total'] ?? 0)))];
        }
        return $out;
    }

    /** GET new: the add form (empty; the shop never picked for you). */
    public function newForm(Context $ctx): HtmlResponse
    {
        $n = (string) ($ctx->req->param('notice') ?? '');
        return $this->form($ctx, 200, null, [], $n === 'added' ? Words::EXPENSES['notice_added'] : null);
    }

    /**
     * POST new: adds the expense; "Save and add another" comes back to an empty form. No file chosen, and the form carries the invoice Claude
     * read for it (ai_file; AI15): that file is the expense's, attached in the expense's own transaction as if it had been chosen (its person,
     * once, checked again: DocumentReader::useFile), then deleted from the spool.
     */
    public function add(Context $ctx): HtmlResponse
    {
        $typed = self::typed($ctx);
        $kept = null;
        try {
            $file = self::upload($ctx, false);
            $kept = $file === null ? self::aiFile($ctx) : null;
            if ($kept === null) {
                self::fileAgain($ctx, $file);
            }
            $same = self::same($ctx);
            $another = $ctx->req->field('then') === 'another';
            // One effect per form (FormOnce, I46; the Codex check r3, H1): the same form sent again (a lost answer, a retry), a confirmed second
            // invoice too, lands where the first did and adds nothing. What it keeps of the form: the fields and the file's fingerprint (the
            // invoice Claude read: its reading, so a form sent again after its file was attached and deleted is still the same form).
            $request = self::fingerprint($typed) + ['same_ok' => $same, 'then' => $another ? 'another' : 'done',
                'file' => $file !== null ? (string) hash_file('sha256', $file['path']) : ($kept !== null ? 'claude:' . $kept : '')];
            $r = FormOnce::run($ctx, 'ui.expense.add', $request, static function (\CW\Db $db) use ($ctx, $typed, $file, $kept, $same, $another): \CW\OpResult {
                $use = $file ?? ($kept === null ? null : (new DocumentReader($db))->useFile($ctx->caller(), $kept, 'expense_new', null));
                $x = (new Expenses($db, static fn (): FileStore => $ctx->files()))->add($ctx->caller(), $typed, $use, $same);
                return \CW\OpResult::of(303, ['redirect' => $another ? Html::url(self::PATH . '/new', ['notice' => 'added'])
                    : Html::url(self::PATH . '/' . $x['id'], ['notice' => 'added'])]);
            });
        } catch (CwException $e) {
            return self::deniedWrite($e) ? self::denied($ctx, $e) : $this->form($ctx, $e->httpStatus, $e, $typed, null);
        }
        self::dropUsed($ctx, $kept);
        return FormOnce::redirect($r);
    }

    /**
     * POST read: the add form sent with "Fill from the file with Claude" (CW-1182; docs/decisions.md AI5): its file read by Claude in the
     * background (DocumentReader), what was typed kept with it; the page that waits. Refused (no file, switched off, the limit, the file's
     * type): the form back as it was typed, with why.
     */
    public function read(Context $ctx): HtmlResponse
    {
        $typed = self::typed($ctx);
        try {
            $id = self::startReading($ctx, 'expense_new', $typed);
        } catch (CwException $e) {
            return self::deniedWrite($e) ? self::denied($ctx, $e) : $this->form($ctx, $e->httpStatus, $e, $typed, null);
        }
        return HtmlResponse::redirect(self::PATH . '/read/' . $id);
    }

    /** POST {id}/read: an expense's Change form sent with "Fill from the file with Claude" (AI5): as read(), the expense and its version kept. */
    public function readChange(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $typed = self::typed($ctx);
        try {
            $seen = self::seen($ctx);
            if ((new ExpenseViews($ctx->db, $ctx->caller()))->expense($id) === null) {
                return $ctx->error(404, 'not_found', Words::EXPENSES_ERROR['no_such_expense'], [self::PATH, Words::MENU['profit_expenses']]);
            }
            $job = self::startReading($ctx, 'expense_change', $typed + ['expense' => $id, 'seen' => $seen]);
        } catch (CwException $e) {
            return $this->refused($ctx, $id, $e, $typed, 'change');
        }
        return HtmlResponse::redirect(self::PATH . '/read/' . $job);
    }

    /**
     * GET read/{id}: the invoice Claude is reading for this person (AI5). While it waits or is read: the waiting page. Read: its answer taken
     * once and the form filled with it over what was typed (ExpenseInvoiceKind::fill: only what holds together), a note saying which boxes
     * Claude filled and what is left to staff; the invoice Claude read is kept for the form's Save (AI15: ai_file, never asked for again; an
     * hour). Failed: the form as typed, with why (the invoice kept even so). Another person's reading: 404.
     */
    public function reading(Context $ctx): HtmlResponse
    {
        $reader = new DocumentReader($ctx->db);
        $id = $ctx->id();
        try {
            $job = $reader->view($ctx->caller(), $id);
        } catch (CwException) {
            return $ctx->error(404, 'not_found', 'there is no such reading', [self::PATH, Words::MENU['profit_expenses']]);
        }
        $c = $job['context'];
        $expense = is_int($c['expense'] ?? null) ? $c['expense'] : null;
        $typed = self::typedFrom($c);
        $back = function (int $status, ?CwException $e, array $typed, array $ai) use ($ctx, $expense, $c): HtmlResponse {
            if ($expense === null) {
                return $this->form($ctx, $status, $e, $typed, null, $ai);
            }
            return $this->one($ctx, $expense, $status, $e, $typed, null, is_int($c['seen'] ?? null) ? $c['seen'] : null, 'change', $ai);
        };
        if (in_array($job['state'], ['waiting', 'reading'], true)) {
            return DeliveryBillsController::waitPage($ctx, $job, self::PATH . '/read/' . $id, $expense !== null ? self::PATH . '/' . $expense : self::PATH . '/new');
        }
        // the file the person sent: kept for the form's Save while it may be (AI15), else Save asks for it again unless "Save without a file" is ticked
        $file = static fn (): array => $reader->kept($ctx->caller(), $id) ? ['kept' => $id] : ['fileAgain' => true];
        if ($job['state'] === 'taken') {
            return $back(409, new CwException('ai_read', Words::AIREAD['taken'], 409), $typed, $file());
        }
        if ($job['state'] !== 'done') {
            return $back(422, new CwException('ai_read', AiReadWords::failure($job['kind'], $job['reason'], $job['detail']), 422, ['field' => 'file']), $typed,
                $file());
        }
        try {
            $answer = $reader->take($ctx->caller(), $id);
        } catch (CwException $e) {
            return $back($e->httpStatus, new CwException('ai_read', $e->errorCode === 'ai_taken' ? Words::AIREAD['taken'] : $e->getMessage(), $e->httpStatus), $typed,
                $file());
        }
        $views = new ExpenseViews($ctx->db, $ctx->caller());
        $suppliers = array_values(array_filter($views->suppliers(), static fn (array $s): bool => (bool) $s['is_active']));
        $f = ExpenseInvoiceKind::fill($answer, array_map(static fn (array $s): array => ['id' => (int) $s['id'], 'name' => (string) $s['name']], $suppliers),
            array_map(static fn (array $s): array => ['id' => (int) $s['id'], 'name' => (string) $s['name']], $views->shops()),
            (new \CW\Profit\ProfitSettings($ctx->db))->vatBp(), $typed);
        $reader->checked($id, true);
        $fields = array_map(static fn (string $k): string => Words::AIREAD['field_' . $k], $f['filled']);
        $notes = array_map(static fn (array $n): string => Words::say('AIREAD', $n[0], ...$n[1]), $f['notes']);
        return $back(200, null, $f['typed'], ['filled' => $fields === [] ? Words::AIREAD['filled_none'] : Words::say('AIREAD', 'filled', implode(', ', $fields)),
            'notes' => $notes] + $file());
    }

    /**
     * A reading started with the form's file (AI5): refused in words when the reader refuses (no file, switched off, the limit, the type).
     *
     * @param array<string, mixed> $context
     */
    private static function startReading(Context $ctx, string $place, array $context): int
    {
        $file = self::upload($ctx, false);
        try {
            if ($file === null) {
                throw new CwException('no_file', 'no file', 400, ['field' => 'file']);
            }
            return (new DocumentReader($ctx->db))->start($ctx->caller(), $place, $file['path'], $context);
        } catch (CwException $e) {
            $words = AiReadWords::refusal($e);
            if ($words === null) {
                throw $e;
            }
            throw new CwException('ai_read', $words, $e->httpStatus, ['field' => 'file']);
        }
    }

    /**
     * The form's fields as a reading kept them (DocumentReader's context): each a string or a tick, as typed() gives them.
     *
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private static function typedFrom(array $c): array
    {
        $out = [];
        foreach (['shop', 'supplier', 'supplier_id', 'supplier_was', 'type', 'invoice_date', 'invoice_no', 'net', 'vat', 'total', 'vat_way', 'hand', 'note'] as $k) {
            $out[$k] = is_string($c[$k] ?? null) ? $c[$k] : '';
        }
        foreach (['new_supplier', 'credit', 'anchor'] as $k) {
            $out[$k] = ($c[$k] ?? false) === true;
        }
        $out['vat_way'] = self::vatWay($out['vat_way']);
        $out['hand'] = self::hand($out['hand']);
        return $out;
    }

    /** GET {id}: one expense. */
    public function show(Context $ctx): HtmlResponse
    {
        $n = (string) ($ctx->req->param('notice') ?? '');
        return $this->one($ctx, $ctx->id(), 200, null, null, in_array($n, self::NOTICES, true) ? Words::EXPENSES['notice_' . $n] : null);
    }

    /** POST {id}/change. The invoice Claude read for this expense's Change (ai_file, AI15) is attached as add() attaches it. */
    public function change(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $typed = self::typed($ctx);
        $kept = null;
        try {
            $file = self::upload($ctx, false);
            $kept = $file === null ? self::aiFile($ctx) : null;
            if ($kept === null) {
                self::fileAgain($ctx, $file);
            }
            $seen = self::seen($ctx);
            $same = self::same($ctx);
            $r = $ctx->db->transaction(static function (\CW\Db $db) use ($ctx, $id, $seen, $typed, $file, $kept, $same): array {
                $use = $file ?? ($kept === null ? null : (new DocumentReader($db))->useFile($ctx->caller(), $kept, 'expense_change', $id));
                return (new Expenses($db, static fn (): FileStore => $ctx->files()))->change($ctx->caller(), $id, $seen, $typed, $use, $same);
            });
        } catch (CwException $e) {
            return $this->refused($ctx, $id, $e, $typed, 'change');
        }
        self::dropUsed($ctx, $kept);
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
            return $this->refused($ctx, $id, $e, null, 'delete');
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/' . $id, ['notice' => 'deleted']));
    }

    /** GET {id}/file: the invoice PDF or photo, as an attachment of its sniffed type (FilesController::download). */
    public function file(Context $ctx): HtmlResponse
    {
        try {
            ExpenseAccess::mayView($ctx->db, $ctx->caller()); // the jobs re-read now (EX48)
        } catch (CwException $e) {
            return self::denied($ctx, $e);
        }
        $x = $ctx->db->one('SELECT e.file_id, e.invoice_no, e.invoice_date, s.name AS supplier FROM expense e JOIN expense_supplier s ON s.id = e.supplier_id '
            . 'WHERE e.id = ?', [$ctx->id()]);
        if ($x === null || $x['file_id'] === null) {
            return $ctx->error(404, 'not_found', Words::EXPENSES_ERROR['no_such_file'], [self::PATH, Words::MENU['profit_expenses']]);
        }
        // The same rules as the general file address: bytes that are also a supplier's invoice copy need invoices.view as well (the Codex check
        // r2, H8; EX24).
        $refused = FilesController::refusal($ctx, (int) $x['file_id'], self::PERM_VIEW);
        if ($refused !== null) {
            return $refused;
        }
        $file = $ctx->files()->read((int) $x['file_id']);
        $mime = (string) $file['meta']['mime'];
        // Named from the expense ("Viking Direct 58812290.pdf"); the store keeps a neutral name (EX23).
        return FilesController::download($file['bytes'], $mime, self::downloadName((string) $x['supplier'],
            (string) $x['invoice_no'] !== '' ? (string) $x['invoice_no'] : (string) $x['invoice_date'], $mime));
    }

    /**
     * The download's name: the supplier and the invoice number (or date), cleaned and cut to leave room for the sniffed type's extension, so the
     * name is at most 255 bytes WITH it and every later clean keeps it whole (the final review of 70ab3d2, astra MEDIUM: a long multibyte name
     * lost its ".pdf"; docs/decisions.md EX52).
     */
    public static function downloadName(string $supplier, string $noOrDate, string $mime): string
    {
        $ext = FileStore::EXTENSIONS[$mime][0] ?? 'bin';
        $base = FileStore::cleanName(trim($supplier . ' ' . $noOrDate));
        $base = rtrim(mb_strcut($base, 0, 255 - strlen($ext) - 1, 'UTF-8'), ' ');
        return ($base === '' ? 'expense' : $base) . '.' . $ext;
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
            return self::deniedWrite($e) ? self::denied($ctx, $e) : $this->suppliersPage($ctx, $e->httpStatus, $e, $name, null);
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
            return self::deniedWrite($e) ? self::denied($ctx, $e) : $this->suppliersPage($ctx, $e->httpStatus, $e, null, null);
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/suppliers', ['notice' => 'supplier_changed']));
    }

    /** A refusal of the expenses' services in words, by its code (with its numbers); another code keeps the service's message. */
    public static function plain(CwException $e): string
    {
        return match ($e->errorCode) {
            // In its own words when a bill of no number is why (the same supplier, shop, date and total: the Fable re-check F-B).
            'same_invoice' => Words::say('EXPENSES_ERROR', (($e->detail['credit'] ?? false) === true ? 'same_credit' : 'same_invoice')
                . (($e->detail['why'] ?? '') === 'day_total' ? '_bill' : ''), is_string($e->detail['date'] ?? null) ? Html::day($e->detail['date']) : ''),
            'same_cost' => Words::say('EXPENSES_ERROR', 'same_cost', is_string($e->detail['from'] ?? null) ? Html::day($e->detail['from']) : ''),
            'file_too_large', 'too_large' => Words::say('EXPENSES_ERROR', 'file_too_large', intdiv(ExpenseFiles::MAX_BYTES, 1_048_576)),
            'changed_meanwhile' => Words::say('EXPENSES_ERROR', 'changed_meanwhile', (string) ($e->detail['by'] ?? '?')),
            'total_mismatch' => Words::say('EXPENSES_ERROR', 'total_mismatch', ExpenseMoney::gbp((int) ($e->detail['sum'] ?? 0)), ExpenseMoney::gbp((int) ($e->detail['total'] ?? 0))),
            'ai_file_gone' => Words::AIREAD['file_gone'], // AI15
            default => Words::EXPENSES_ERROR[$e->errorCode] ?? Words::error($e->errorCode, $e->getMessage()),
        };
    }

    /** @return array<string, mixed> the form's fields as typed */
    private static function typed(Context $ctx): array
    {
        $f = static fn (string $k): string => (string) ($ctx->req->field($k) ?? '');
        // supplier_id / supplier_was: the supplier the change form showed, by its id and the name it showed (EX45).
        return ['shop' => $f('shop'), 'supplier' => $f('supplier'), 'supplier_id' => $f('supplier_id'), 'supplier_was' => $f('supplier_was'),
            'new_supplier' => $ctx->req->field('new_supplier') === '1', 'type' => $f('type'),
            'invoice_date' => $f('invoice_date'), 'invoice_no' => $f('invoice_no'), 'credit' => $ctx->req->field('credit') === '1', 'net' => $f('net'), 'vat' => $f('vat'),
            // The total with VAT as typed (checked against net + VAT on save) and the VAT choice that steers the page's script (EX65; never saved).
            // hand: which boxes the person typed by hand (net, vat) and whether the total box holds the sum the script wrote (sum), kept by the
            // script and given back with a refusal so it carries on as it was (Codex's final review of 1f18763, H1; Fable's M1); anchor: the
            // total leads the script's sums when the page comes back, so a total the person typed (not a sum the script wrote) leads again.
            'total' => $f('total'), 'vat_way' => self::vatWay($f('vat_way')), 'hand' => self::hand($f('hand')),
            'anchor' => !ExpenseMoney::blank($f('total')) && !str_contains(self::hand($f('hand')), 'sum'), 'note' => $f('note')];
    }

    /**
     * The script's record of the boxes (EX65): typed by hand (net, vat), the total box holding the sum the script wrote (sum), the boxes the
     * script filled from the total (fnet, fvat: CW-1176, Codex's final of 332aba7) - only those words, once each, in one order; never saved,
     * never part of the one-effect key.
     */
    private static function hand(string $typed): string
    {
        $words = preg_split('/\s+/', $typed, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode(' ', array_values(array_filter(['net', 'vat', 'sum', 'fnet', 'fvat'], static fn (string $w): bool => in_array($w, $words, true))));
    }

    /**
     * What a form's one-effect key is checked against (FormOnce): the fields that make the expense, and the total when one was typed (it is
     * checked against them); never the VAT choice or the record of hand typing (they change nothing saved). A form sent from the page live
     * before EX65 (no total) keeps exactly the fingerprint that page's Add stored, so its lost answer is replayed after the go-live, never
     * refused as "sent with other values" (Codex's final review of 1f18763, M3).
     *
     * @param array<string, mixed> $typed
     * @return array<string, mixed>
     */
    private static function fingerprint(array $typed): array
    {
        $fp = array_diff_key($typed, array_flip(['total', 'vat_way', 'hand', 'anchor']));
        if (!ExpenseMoney::blank((string) ($typed['total'] ?? ''))) {
            $fp['total'] = $typed['total'];
        }
        return $fp;
    }

    /** The VAT choice of the form (EX65): one it offers, else the one picked for you ("Includes ... VAT"). */
    private static function vatWay(string $way): string
    {
        return in_array($way, self::VAT_WAYS, true) ? $way : 'inc';
    }

    /**
     * The VAT choice a saved expense's figures match (EX65), for its Change form: no VAT; the amount without VAT the shops' rate gives for
     * its total (ExpenseMoney::netOfTotal, the script's own rule); or another VAT (typed). Only which button is picked: nothing is worked out
     * for saving.
     */
    private static function wayOf(int $net, int $vat, int $rateBp): string
    {
        return $vat === 0 ? 'none' : ($net === ExpenseMoney::netOfTotal($net + $vat, $rateBp) ? 'inc' : 'other');
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

    /** The reading whose invoice the form carries for its Save (ai_file, AI15), or null. Whose and whether it is still kept: useFile() decides. */
    private static function aiFile(Context $ctx): ?int
    {
        return UiRequest::id($ctx->req->field('ai_file'));
    }

    /**
     * The invoice a Save attached, deleted from the spool once the expense is stored (AI15); a failure here changes nothing for the person (the
     * runner's clean-up deletes it within a minute).
     */
    private static function dropUsed(Context $ctx, ?int $kept): void
    {
        if ($kept === null) {
            return;
        }
        try {
            (new DocumentReader($ctx->db))->dropUsed($kept);
        } catch (\Throwable) {
            // the clean-up's turn
        }
    }

    /**
     * What a page sent back says of the invoice Claude read (AI15): the one a reading gives it, else the one a refused Save carried (kept for
     * the next Save; never when a file was chosen instead, or when it was the file that is no longer kept).
     *
     * @param array<string, mixed> $ai
     */
    private static function aiFileOf(Context $ctx, ?CwException $error, array $ai): ?int
    {
        if (is_int($ai['kept'] ?? null)) {
            return $ai['kept'];
        }
        return $error !== null && $error->errorCode !== 'ai_file_gone' && $ctx->req->file('file') === null ? self::aiFile($ctx) : null;
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
    private function form(Context $ctx, int $status, ?CwException $error, array $typed, ?string $notice, array $ai = []): HtmlResponse
    {
        $views = new ExpenseViews($ctx->db, $ctx->caller());
        $bp = (new \CW\Profit\ProfitSettings($ctx->db))->vatBp();
        return $ctx->page('profit_expense_new', [
            'shops' => $views->shops(),
            'types' => (new ExpenseTypes($ctx->db))->active('expense'),
            'suppliers' => array_values(array_filter($views->suppliers(), static fn (array $s): bool => $s['is_active'])),
            'typed' => $typed + ['shop' => '', 'supplier' => '', 'new_supplier' => false, 'type' => '', 'invoice_date' => '', 'invoice_no' => '', 'credit' => false,
                'total' => '', 'vat_way' => 'inc', 'hand' => '', 'anchor' => false, 'net' => '', 'vat' => '', 'note' => ''],
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
            'errorField' => is_string($error?->detail['field'] ?? null) ? $error->detail['field'] : null,
            'sameAsk' => $error?->errorCode === 'same_invoice',
            // What "it is another one" confirms: exactly the expenses it was asked about (EX43).
            'sameIds' => is_string($error?->detail['confirm'] ?? null) ? $error->detail['confirm'] : '',
            'sameList' => self::sameList($error),
            'maxMb' => intdiv(ExpenseFiles::MAX_BYTES, 1_048_576),
            'fileAgain' => ($error !== null && self::fileChosen($ctx)) || $error?->errorCode === 'ai_file_gone' || ($ai['fileAgain'] ?? false) === true,
            'aiFile' => self::aiFileOf($ctx, $error, $ai),
            'formKey' => self::formKey($ctx, $error),
            // Claude reads the invoice (CW-1182, AI5): the button while reading is on, and what a reading filled
            'aiOn' => AiSettings::read($ctx->db)->on(),
            'aiFilled' => $ai['filled'] ?? null,
            'aiNotes' => $ai['notes'] ?? [],
            // The shops' rate read ONCE for the page: the label and the script's sums from the same reading (Codex's final review, L2).
            'vatPct' => RegularCostMath::percent($bp),
            'vatBp' => $bp,
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
    private function refused(Context $ctx, int $id, CwException $e, ?array $typed, string $form): HtmlResponse
    {
        if (self::deniedWrite($e)) {
            return self::denied($ctx, $e);
        }
        if ($e->errorCode === 'changed_meanwhile') {
            return $this->one($ctx, $id, $e->httpStatus, $e, null, null, null, $form);
        }
        $s = (string) ($ctx->req->field('seen') ?? '');
        return $this->one($ctx, $id, $e->httpStatus, $e, $typed, null, preg_match('/^[1-9]\d{0,8}$/D', $s) === 1 ? (int) $s : null, $form);
    }

    /**
     * @param array<string, mixed>|null $typed what a refused change said
     * @param int|null $seen the version the forms carry (null: the expense's own)
     * @param string|null $form the form whose sending this page answers (change, delete; null: a plain look), so the page opens that fold and
     *     says its refusal there (as a regular cost's page: Fable's Low a on f044e2f)
     */
    private function one(Context $ctx, int $id, int $status, ?CwException $error, ?array $typed, ?string $notice, ?int $seen = null, ?string $form = null,
        array $ai = []): HtmlResponse
    {
        $views = new ExpenseViews($ctx->db, $ctx->caller());
        $x = $views->expense($id);
        if ($x === null) {
            return $ctx->error(404, 'not_found', Words::EXPENSES_ERROR['no_such_expense'], [self::PATH, Words::MENU['profit_expenses']]);
        }
        $types = (new ExpenseTypes($ctx->db))->active('expense');
        if (!in_array((int) $x['type_id'], array_column($types, 'id'), true)) {
            $types[] = ['id' => (int) $x['type_id'], 'name' => (string) $x['type']]; // its own type, switched off since, stays its choice
        }
        $title = Words::say('EXPENSES', 'one_title', (string) $x['supplier'], (string) $x['invoice_no'] !== '' ? (string) $x['invoice_no'] : Html::day((string) $x['invoice_date']));
        $bp = (new \CW\Profit\ProfitSettings($ctx->db))->vatBp();
        return $ctx->page('profit_expense', [
            'x' => $x,
            'shops' => $views->shops(),
            'types' => $types,
            'suppliers' => array_values(array_filter($views->suppliers(), static fn (array $s): bool => $s['is_active'] || $s['id'] === (int) $x['supplier_id'])),
            'typed' => $typed ?? ['shop' => (string) $x['channel_id'], 'supplier' => (string) $x['supplier'], 'supplier_id' => (string) $x['supplier_id'],
                'supplier_was' => (string) $x['supplier'], 'new_supplier' => false, 'type' => (string) $x['type_id'],
                // A credit note's amounts as printed, with its tick on (EX37).
                'invoice_date' => (string) $x['invoice_date'], 'invoice_no' => (string) $x['invoice_no'], 'credit' => (int) $x['net_pence'] < 0,
                'net' => ExpenseMoney::plain(abs((int) $x['net_pence'])), 'vat' => ExpenseMoney::plain(abs((int) $x['vat_pence'])), 'note' => (string) ($x['note'] ?? ''),
                // The VAT choice its figures match (EX65); the total box left empty in the page (its script shows net + VAT there), so a
                // change of the amounts sent without the script never meets a stale total.
                'total' => '', 'vat_way' => self::wayOf(abs((int) $x['net_pence']), abs((int) $x['vat_pence']), $bp), 'hand' => '', 'anchor' => false],
            'form' => $form,
            'canEdit' => $ctx->me()->can(self::PERM_EDIT) && $x['deleted_at'] === null,
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
            'errorField' => is_string($error?->detail['field'] ?? null) ? $error->detail['field'] : null,
            'sameAsk' => $error?->errorCode === 'same_invoice',
            'sameIds' => is_string($error?->detail['confirm'] ?? null) ? $error->detail['confirm'] : '',
            'sameList' => self::sameList($error),
            'maxMb' => intdiv(ExpenseFiles::MAX_BYTES, 1_048_576),
            // The shops' rate read ONCE for the page (L2).
            'vatPct' => RegularCostMath::percent($bp),
            'vatBp' => $bp,
            'listHref' => Html::url(self::PATH, ['month' => substr((string) $x['invoice_date'], 0, 7)]),
            // A change refused because someone else changed it shows their figures and still asks for the file chosen (the Codex check r3, M8).
            'fileAgain' => ($error !== null && self::fileChosen($ctx)) || $error?->errorCode === 'ai_file_gone' || ($ai['fileAgain'] ?? false) === true,
            'aiFile' => self::aiFileOf($ctx, $error, $ai),
            'seen' => $seen ?? (int) $x['version'],
            'aiOn' => AiSettings::read($ctx->db)->on(),
            'aiFilled' => $ai['filled'] ?? null,
            'aiNotes' => $ai['notes'] ?? [],
        ], $status, ['title' => $title, 'notice' => $notice]);
    }

    private function suppliersPage(Context $ctx, int $status, ?CwException $error, ?string $typed, ?string $notice): HtmlResponse
    {
        return $ctx->page('profit_expense_suppliers', [
            'suppliers' => (new ExpenseViews($ctx->db, $ctx->caller()))->suppliers(),
            'canEdit' => $ctx->me()->can(self::PERM_EDIT),
            'typed' => $typed ?? '',
            'error' => $error === null ? null : self::plain($error),
        ], $status, ['title' => Words::EXPENSES['suppliers_title'], 'notice' => $notice]);
    }
}
