<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Catalogue\ItemCardCsv;
use CW\Catalogue\ItemCardList;
use CW\Catalogue\ItemCards;
use CW\Catalogue\ItemRules;
use CW\CwException;
use CW\Db;
use CW\OpResult;
use CW\Output\CsvReader;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * The item card screens (IM3; docs/decisions.md I109, I110):
 *  - Items > Item cards (/ui/items/cards, catalogue.view): every item with its card, ordered by the stock it holds (a board by card, W11.1), with
 *    filters and the CSV download (the same filters);
 *  - the card form (/ui/items/{id}/card, catalogue.edit) and its POST; accepting a suggestion and confirming the card (POSTs
 *    from the item page);
 *  - the CSV import (/ui/items/cards/import, catalogue.edit): check only (the default) or save.
 * Every POST carries the card version it was drawn with (409 redraws with what changed) and a FormOnce key (the same form sent
 * twice has one effect). CW\Catalogue\ItemCards checks the person again inside its transaction (catalogue.edit, never admin).
 */
final class ItemCardsController
{
    // ------------------------------------------------------------------------------------------
    // The list, the CSV
    // ------------------------------------------------------------------------------------------

    public function index(Context $ctx): HtmlResponse
    {
        $f = ItemCardList::filters($ctx->req->query);
        $list = new ItemCardList($ctx->db);
        $total = $list->count($f);
        $pages = max(1, (int) ceil($total / ItemCardList::PAGE));
        if ($f['page'] > $pages) {
            $f['page'] = $pages;
        }
        $rows = [];
        foreach ($list->rows($f, ItemCardList::PAGE, ($f['page'] - 1) * ItemCardList::PAGE) as $r) {
            $hasCard = $r['card_sku_id'] !== null;
            $st = $hasCard ? ItemRules::status($r) : ['level' => null, 'blocked' => [], 'warnings' => []];
            $state = !$hasCard ? 'none' : ($r['confirmed_at'] !== null ? 'confirmed' : ($r['first_confirmed_at'] !== null ? 'changed' : 'unconfirmed'));
            // The board's group (the redesign's Items artboard, docs/decisions.md RD-A3): Needs a look (a block, or a card still to confirm), No card
            // yet, Ready (confirmed); the row's status label says which (Blocked in red, Check the card in amber).
            $group = $st['level'] === 'block' ? 'blocked' : match ($state) { 'none' => 'none', 'confirmed' => 'confirmed', default => 'check' };
            [$pillTone, $pill] = match ($group) {
                'blocked' => ['blocked', Words::INV_LIST['p_blocked']],
                'check' => ['needs', Words::INV_LIST['p_check']],
                'none' => ['waiting', Words::INV_LIST['p_none']],
                default => ['done', Words::INV_LIST['p_ready']],
            };
            $rows[] = [
                'id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'], 'held' => (int) $r['held'],
                'type' => $r['product_type'] === null ? null : ucfirst(ItemRules::TYPES[(string) $r['product_type']] ?? (string) $r['product_type']),
                'ml' => Html::dec($r['liquid_ml']), 'mg' => Html::dec($r['nicotine_mg']), 'duty' => self::yesNo($r['duty_liable']),
                'single_use' => self::yesNo($r['single_use']), 'flavour' => $r['flavour'], 'flavour_proposed' => $r['flavour_status'] === 'proposed',
                'discontinued' => $hasCard && (int) $r['discontinued'] === 1,
                'state' => $state, 'group' => match ($group) { 'blocked', 'check' => 'look', 'confirmed' => 'ready', default => 'none' },
                'pill' => $pill, 'pillTone' => $pillTone,
                'stateWord' => $group === 'blocked' ? Words::CARD_STATE['blocked'] : Words::of('CARD_STATE', $state),
                'level' => $st['level'], 'blocked' => ItemRules::labels($st['blocked']), 'warnings' => ItemRules::labels($st['warnings']),
            ];
        }
        // The filters are plain inputs in the toolbar's Show row, with ONE Clear (the owner's patterns of 29 Oct 2026; the chips of 21 Oct are gone);
        // the empty state of a search keeps the filters (`searchClear`).
        $query = ItemCardList::query($f);
        $states = array_map(static fn (string $code): string => $code === 'unconfirmed' ? Words::CARDS['filter_unconfirmed'] : Words::of('CARD_STATE', $code),
            array_combine(array_keys(ItemCardList::STATES), array_keys(ItemCardList::STATES)));
        $states['all'] = Words::CARDS['any_card'];
        return $ctx->page('item_cards', [
            'rows' => $rows,
            'groups' => Html::groups($rows, ['look' => ['needs', Words::INV_LIST['g_look']], 'none' => ['waiting', Words::INV_LIST['g_none']],
                'ready' => ['done', Words::INV_LIST['g_ready']]]),
            // The ONE Show choice (RD-A2): the artboard's four, each a link (Made by hand is its own page).
            'show' => [
                ['label' => Words::INV_LIST['all_items'], 'href' => Html::url('/ui/items/cards', $f['q'] === '' ? [] : ['q' => $f['q']]),
                    'current' => !$f['stock'] && $f['state'] === 'all' && $f['type'] === '' && !$f['warnings'] && !$f['blocked'] && !$f['discontinued']],
                ['label' => Words::INV_LIST['holding'], 'href' => Html::url('/ui/items/cards', ['q' => $f['q'] === '' ? null : $f['q'], 'stock' => '1']),
                    'current' => $f['stock'] && $f['state'] === 'all' && $f['type'] === '' && !$f['warnings'] && !$f['blocked'] && !$f['discontinued']],
                ['label' => Words::INV_LIST['needs_look'], 'href' => Html::url('/ui/items/cards', ['q' => $f['q'] === '' ? null : $f['q'], 'state' => 'unconfirmed']),
                    'current' => !$f['stock'] && $f['state'] === 'unconfirmed' && $f['type'] === '' && !$f['warnings'] && !$f['blocked'] && !$f['discontinued']],
                ['label' => Words::INV_LIST['by_hand'], 'href' => Html::url(HandMadeController::PATH, ['show' => 'all']), 'current' => false],
            ],
            'searchClear' => $f['q'] === '' ? null : array_diff_key($query, ['q' => true]),
            'filters' => $f,
            'query' => $query,
            // A search keeps the filters on (the Show choice, More's filters).
            'kept' => array_diff_key($query, ['q' => true]),
            'total' => $total,
            'page' => $f['page'],
            'pages' => $pages,
            'filtered' => $f['q'] !== '' || $f['state'] !== 'all' || $f['type'] !== '' || $f['stock'] || $f['warnings'] || $f['blocked'] || $f['discontinued'],
            'canEdit' => $ctx->me()->can('catalogue.edit'),
            'bar' => self::bar($ctx, $f, $query, $states),
        ], 200, ['title' => Words::MENU['cards'], 'active' => 'cards']);
    }

    /**
     * The head's right (the redesign's Items artboard, docs/decisions.md RD-A3): More holds the filters of before (the card, the kind of product,
     * the four ticks: a GET form, open in the menu when one the Show choice does not name is on), Clear, Import (the catalogue team), the
     * download; then the ONE main button "New item" for who may make an item by hand.
     *
     * @param array{q: string, state: string, stock: bool, warnings: bool, blocked: bool, type: string, discontinued: bool, page: int} $f
     * @param array<string, string> $query
     * @param array<string, string> $states
     * @return array<string, mixed>
     */
    private static function bar(Context $ctx, array $f, array $query, array $states): array
    {
        $me = $ctx->me();
        $shown = !$f['warnings'] && !$f['blocked'] && !$f['discontinued'] && $f['type'] === ''
            && (($f['state'] === 'all') || ($f['state'] === 'unconfirmed' && !$f['stock']));
        $links = [];
        if ($f['q'] !== '' || $f['state'] !== 'all' || $f['type'] !== '' || $f['stock'] || $f['warnings'] || $f['blocked'] || $f['discontinued']) {
            $links[] = ['label' => Words::CARDS['clear_filters'], 'href' => '/ui/items/cards'];
        }
        if ($me->can('catalogue.edit')) {
            $links[] = ['label' => Words::CARDS['import'], 'href' => '/ui/items/cards/import'];
        }
        $links[] = ['label' => Words::CARDS['download'], 'href' => Html::url('/ui/items/cards.csv', $query), 'download' => true];
        $kinds = ['' => Words::CARDS['any_kind'], 'none' => Words::CARDS['not_set']] + array_map('ucfirst', ItemRules::TYPES);
        return [
            'filters' => [
                'action' => '/ui/items/cards', 'keep' => $f['q'] === '' ? [] : ['q' => $f['q']], 'open' => !$shown,
                'selects' => [
                    ['name' => 'state', 'label' => Words::CARDS['card'], 'value' => $f['state'] === 'all' ? '' : $f['state'],
                        'options' => array_combine(array_map(static fn (string $c): string => $c === 'all' ? '' : $c, array_keys($states)), array_values($states))],
                    ['name' => 'type', 'label' => Words::CARDS['kind'], 'value' => $f['type'], 'options' => $kinds],
                ],
                'ticks' => [
                    ['name' => 'stock', 'label' => Words::CARDS['stock'], 'checked' => $f['stock']],
                    ['name' => 'warnings', 'label' => Words::CARDS['warnings'], 'checked' => $f['warnings']],
                    ['name' => 'blocked', 'label' => Words::CARDS['blocked'], 'checked' => $f['blocked']],
                    ['name' => 'discontinued', 'label' => Words::CARDS['discontinued'], 'checked' => $f['discontinued']],
                ],
            ],
            'links' => $links,
            'main' => $me->can('items.create') ? ['label' => Words::INV_LIST['new_item'], 'href' => HandMadeController::PATH] : null,
        ];
    }

    /** "Yes" / "No" / "" for a card's yes-no answer (1, 0, null). */
    private static function yesNo(mixed $v): string
    {
        return match (ItemCards::yesNoLabel($v)) {
            'yes' => Words::CARD['duty_yes'],
            'no' => Words::CARD['no'],
            default => '',
        };
    }

    /**
     * A refusal of the catalogue services in the page's words, by its error code (plan rule 18: the services' messages stay the
     * API's). A code without words: the service's message, then "Nothing was saved."
     */
    public static function plain(CwException $e): string
    {
        $code = $e->errorCode;
        return match (true) {
            in_array($code, ['no_file', 'too_large', 'upload_failed', 'form_already_saved'], true) => $e->getMessage(),
            $code === 'bad_units' => Words::say('CARD_ERROR', 'bad_units', \CW\Catalogue\ItemBarcodes::UNITS_MAX),
            $code === 'card_changed' => Words::CARD_ERROR['card_changed_page'],
            $code === 'card_incomplete' && is_array($e->detail['missing'] ?? null) && $e->detail['missing'] !== []
                => Words::say('CARD_ERROR', 'card_incomplete', implode(', ', array_map('strval', $e->detail['missing']))),
            $code === 'card_incomplete' => Words::CARD_ERROR['no_card'],
            $code === 'card_breaches' => Words::say('CARD_ERROR', 'card_breaches', ItemRules::labels(array_map('strval', (array) ($e->detail['rules'] ?? [])))),
            $code === 'barcode_on_other_item' && is_string($e->detail['code'] ?? null)
                => Words::say('CARD_ERROR', 'barcode_on_other_item', $e->detail['code'], $e->detail['code']),
            $code === 'bad_file' && is_array($e->detail['columns'] ?? null) && $e->detail['columns'] !== []
                => Words::say('CARD_ERROR', 'unknown_columns', implode(', ', array_map('strval', $e->detail['columns']))),
            // The selling-mode switch on the same page (IM10, SiteModes::set).
            $code === 'bad_threshold' => Words::say('SELLING_ERROR', 'bad_threshold', \CW\SiteWriter\SiteModes::THRESHOLD_MAX),
            isset(Words::SELLING_ERROR[$code]) && !str_contains(Words::SELLING_ERROR[$code], '%s') => Words::SELLING_ERROR[$code],
            isset(Words::ERROR[$code]) && !str_contains(Words::ERROR[$code], '%') => Words::ERROR[$code],
            isset(Words::CARD_ERROR[$code]) && !str_contains(Words::CARD_ERROR[$code], '%s') => Words::CARD_ERROR[$code],
            default => Words::say('BUY_ERROR', 'other', PurchaseOrdersController::sentence($e->getMessage())),
        };
    }

    public function csv(Context $ctx): HtmlResponse
    {
        $f = ItemCardList::filters($ctx->req->query);
        $csv = ItemCardCsv::export((new ItemCardList($ctx->db))->rows($f, null));
        return FilesController::download($csv, 'text/csv; charset=utf-8', 'item-cards-' . gmdate('Ymd') . '.csv');
    }

    // ------------------------------------------------------------------------------------------
    // The card form
    // ------------------------------------------------------------------------------------------

    public function form(Context $ctx): HtmlResponse
    {
        $sku = $this->sku($ctx);
        if ($sku === null) {
            return $ctx->error(404, 'unknown_item', Words::ERROR['unknown_item'], ['/ui/search', Words::title('search')]);
        }
        $card = $ctx->itemCards()->card((int) $sku['id']);
        return $this->formPage($ctx, $sku, self::formValues($card), $card['version'], FormOnce::newKey(), 200, null);
    }

    public function save(Context $ctx): HtmlResponse
    {
        $sku = $this->sku($ctx);
        if ($sku === null) {
            return $ctx->error(404, 'unknown_item', Words::ERROR['unknown_item'], ['/ui/search', Words::title('search')]);
        }
        $id = (int) $sku['id'];
        $typed = self::posted($ctx->req);
        $version = self::version($ctx->req->field('version'));
        if ($version === null) {
            return $ctx->error(400, 'bad_version', Words::ERROR['bad_version']);
        }
        try {
            $r = FormOnce::run($ctx, 'ui.item_card.save', $typed + ['version' => (string) $version], static function (Db $db) use ($ctx, $id, $version, $typed): OpResult {
                $s = $ctx->itemCards()->save($ctx->caller(), $id, $version, $typed);
                $notice = $s['result'] === 'unchanged' ? 'card_unchanged' : ($s['unconfirmed'] ? 'card_saved_unconfirmed' : 'card_saved');
                return OpResult::of(303, ['result' => $s['result'], 'version' => $s['version'], 'redirect' => Html::url('/ui/items/' . $id, ['notice' => $notice])]);
            });
        } catch (CwException $e) {
            $key = $ctx->req->field(FormOnce::FIELD) ?? FormOnce::newKey();
            $card = $ctx->itemCards()->card($id);
            return match ($e->errorCode) {
                'card_changed' => $this->formPage($ctx, $sku, $typed, $card['version'], $key, 409, $e, self::changesSince($ctx, $id, $version)),
                'idempotency_key_reused' => $this->formPage($ctx, $sku, $typed, $card['version'], FormOnce::newKey(), 409, new CwException('form_already_saved',
                    Words::CARD_ERROR['form_already_saved'], 409)),
                'card_invalid', 'bad_form_key' => $this->formPage($ctx, $sku, $typed, $version, $e->errorCode === 'card_invalid' ? $key : FormOnce::newKey(), $e->httpStatus, $e),
                default => (new ItemController())->page($ctx, $e->httpStatus, $e),
            };
        }
        return FormOnce::redirect($r);
    }

    public function accept(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $version = self::version($ctx->req->field('version'));
        $field = $ctx->req->field('field') ?? '';
        $value = $ctx->req->field('value') ?? '';
        if ($version === null) {
            return $ctx->error(400, 'bad_version', Words::ERROR['bad_version']);
        }
        try {
            $r = FormOnce::run($ctx, 'ui.item_card.accept', ['version' => (string) $version, 'field' => $field, 'value' => $value],
                static function (Db $db) use ($ctx, $id, $version, $field, $value): OpResult {
                    $s = $ctx->itemCards()->accept($ctx->caller(), $id, $version, $field, $value);
                    return OpResult::of(303, ['result' => $s['result'], 'redirect' => Html::url('/ui/items/' . $id,
                        ['notice' => $s['result'] === 'unchanged' ? 'card_unchanged' : ($s['unconfirmed'] ? 'card_saved_unconfirmed' : 'accepted')])]);
                });
        } catch (CwException $e) {
            return (new ItemController())->page($ctx, $e->httpStatus, $e);
        }
        return FormOnce::redirect($r);
    }

    public function confirm(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $version = self::version($ctx->req->field('version'));
        $ack = $ctx->req->field('acknowledge_block') === '1';
        if ($version === null) {
            return $ctx->error(400, 'bad_version', Words::ERROR['bad_version']);
        }
        try {
            $r = FormOnce::run($ctx, 'ui.item_card.confirm', ['version' => (string) $version, 'acknowledge_block' => $ack ? '1' : ''],
                static function (Db $db) use ($ctx, $id, $version, $ack): OpResult {
                    $c = $ctx->itemCards()->confirm($ctx->caller(), $id, $version, $ack);
                    $notice = match (true) {
                        $c['result'] === 'already' => 'already_confirmed',
                        $c['breaches'] !== [] => 'confirmed_blocked',
                        $c['lifted'] !== [] => 'confirmed_lifted',
                        default => 'confirmed',
                    };
                    return OpResult::of(303, ['result' => $c['result'], 'redirect' => Html::url('/ui/items/' . $id, ['notice' => $notice])]);
                });
        } catch (CwException $e) {
            return (new ItemController())->page($ctx, $e->httpStatus, $e);
        }
        return FormOnce::redirect($r);
    }

    // ------------------------------------------------------------------------------------------
    // The CSV import
    // ------------------------------------------------------------------------------------------

    public function importForm(Context $ctx): HtmlResponse
    {
        return $this->importPage($ctx, 200, null, null);
    }

    public function import(Context $ctx): HtmlResponse
    {
        $apply = $ctx->req->field('mode') === 'apply';
        try {
            $file = $ctx->req->file('file');
            if ($file === null) {
                throw new CwException('no_file', Words::CARD_IMPORT['no_file'], 400);
            }
            if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > UiRequest::MAX_UPLOAD_BYTES) {
                throw new CwException('too_large', Words::say('CARD_IMPORT', 'too_large', intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576)), 413);
            }
            if ($file['error'] !== UPLOAD_ERR_OK || $file['path'] === '') {
                throw new CwException('upload_failed', Words::CARD_IMPORT['upload_failed'], 400);
            }
            $path = $file['path'];
            $name = $file['name'];
            $sha = (string) hash_file('sha256', $path);
            $run = static fn (): array => (new ItemCardCsv($ctx->db))->import($ctx->caller(), $path, $name, $apply, CsvReader::DEFAULT_MAX_BYTES,
                ItemCardCsv::UI_MAX_ROWS, ItemCardCsv::UI_MAX_CHANGES);
            if ($apply) {
                // One effect per form: the same form and file sent twice import once (the replay shows the first run's report).
                try {
                    $r = FormOnce::run($ctx, 'ui.item_cards.import', ['mode' => 'apply', 'sha256' => $sha], static function (Db $db) use ($run): OpResult {
                        return OpResult::of(200, ['report' => $run()]);
                    });
                } catch (CwException $e) {
                    // A file refused as a whole: FormOnce's transaction took the import's own record with it; record the run now (I120).
                    if (in_array($e->errorCode, ItemCardCsv::FILE_REFUSALS, true)) {
                        (new ItemCardCsv($ctx->db))->recordRefused($ctx->caller(), $path, $name, true, $e);
                    }
                    throw $e;
                }
                $report = (array) $r->body['report'];
            } else {
                $report = $run();
            }
        } catch (CwException $e) {
            return $this->importPage($ctx, $e->httpStatus, $e, null);
        }
        return $this->importPage($ctx, $report['errors_total'] > 0 ? 422 : 200, null, $report);
    }

    // ------------------------------------------------------------------------------------------

    /** @param array<string, mixed>|null $report */
    private function importPage(Context $ctx, int $status, ?CwException $error, ?array $report): HtmlResponse
    {
        return $ctx->page('item_cards_import', [
            'error' => $error === null ? null : self::plain($error),
            'report' => $report,
            'formKey' => FormOnce::newKey(),
            'columns' => ['code', 'card_version', ...ItemCardCsv::IMPORT_FIELDS],
            'typeCodes' => array_values(ItemRules::TYPES),
            'maxChanges' => ItemCardCsv::UI_MAX_CHANGES,
            'maxMiB' => intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576),
            'fieldWords' => array_map(static fn (string $f): string => Words::of('CARD_FIELD', $f), array_combine(ItemCardCsv::IMPORT_FIELDS, ItemCardCsv::IMPORT_FIELDS)),
        ], $status, ['title' => Words::title('card_import'), 'active' => 'cards']);
    }

    /**
     * @param array<string, mixed> $sku
     * @param array<string, string> $values
     * @param list<string> $changes
     */
    private function formPage(Context $ctx, array $sku, array $values, int $version, string $formKey, int $status, ?CwException $error, array $changes = []): HtmlResponse
    {
        $errors = $error !== null && is_array($error->detail['errors'] ?? null) ? $error->detail['errors'] : [];
        $card = $ctx->itemCards()->card((int) $sku['id']);
        return $ctx->page('item_card_form', [
            'sku' => ['id' => (int) $sku['id'], 'code' => (string) $sku['code'], 'name' => (string) $sku['name']],
            'v' => $values,
            'version' => $version,
            'formKey' => $formKey,
            'confirmed' => $card['confirmed_at'] !== null,
            'enforced' => $card['first_confirmed_at'] !== null,
            // A flavour a file proposed, still on the form unchanged: the form says so and saving it leaves it proposed (I114).
            'flavourProposed' => $card['flavour_status'] === 'proposed' && ($values['flavour'] ?? '') === (string) $card['flavour'],
            'types' => array_map('ucfirst', ItemRules::TYPES),
            'error' => $error === null ? null : ($error->errorCode === 'card_changed' ? Words::CARD_ERROR['card_changed'] : self::plain($error)),
            'errors' => $errors,
            'changes' => $changes,
            'title' => Words::say('CARD', 'form_title', (string) $sku['name']),
        ], $status, ['title' => Words::say('CARD', 'form_title', (string) $sku['name']), 'active' => 'search']);
    }

    /** @return array<string, mixed>|null the route's item */
    private function sku(Context $ctx): ?array
    {
        return $ctx->db->one('SELECT id, code, name, merged_into_sku_id FROM sku WHERE id = ?', [$ctx->id()]);
    }

    /**
     * The card as the form shows it ('' = not known; yes / no for the answers; ml and mg without trailing zeros).
     *
     * @param array<string, mixed> $card
     * @return array<string, string>
     */
    private static function formValues(array $card): array
    {
        $out = [];
        foreach (array_keys(ItemCards::FIELDS) as $k) {
            $v = $card[$k];
            $out[$k] = match ($k) {
                'liquid_ml', 'nicotine_mg' => Html::dec($v),
                'duty_liable', 'single_use', 'discontinued' => ItemCards::yesNoLabel($v),
                default => $v === null ? '' : (string) $v,
            };
        }
        return $out;
    }

    /** @return array<string, string> the form's fields as posted (a missing field is empty; discontinued: the checkbox) */
    private static function posted(UiRequest $req): array
    {
        $out = [];
        foreach (array_keys(ItemCards::FIELDS) as $k) {
            $out[$k] = $k === 'discontinued' ? ($req->field($k) === 'yes' ? 'yes' : 'no') : ($req->field($k) ?? '');
        }
        return $out;
    }

    /** @return list<string> what changed on the card after version $since ("version n by X: field: a → b") */
    private static function changesSince(Context $ctx, int $id, int $since): array
    {
        $out = [];
        foreach (array_reverse($ctx->itemCards()->history($id, 50)) as $h) {
            if ($h['version'] <= $since) {
                continue;
            }
            $parts = [];
            $value = static fn (string $f, mixed $v): string => $v === null || $v === '' ? Words::CARD['h_empty']
                : ($f === 'flavour_status' ? ($v === 'proposed' ? Words::CARD['from_file'] : Words::CARD['h_confirmed']) : (string) $v);
            foreach ($h['changes'] as $f => $c) {
                $parts[] = ($f === 'flavour_status' ? Words::CARD['flavour_status'] : mb_strtolower(Words::of('CARD_FIELD', (string) $f))) . ': '
                    . $value((string) $f, $c['before'] ?? null) . ' → ' . $value((string) $f, $c['after'] ?? null);
            }
            $out[] = Words::say('CARD', 'h_line', Html::when((string) $h['at']), (string) $h['who'], $h['kind'] === 'confirm' ? Words::CARD['h_confirmed'] : implode('; ', $parts));
        }
        return $out;
    }

    /** The version a form carries: 0 (no card yet) or a positive whole number. */
    private static function version(?string $v): ?int
    {
        return $v !== null && preg_match('/^(0|[1-9][0-9]{0,9})$/D', $v) === 1 ? (int) $v : null;
    }
}
