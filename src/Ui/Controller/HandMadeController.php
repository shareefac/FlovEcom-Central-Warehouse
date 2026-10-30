<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Admin\ApprovalRules;
use CW\Catalogue\ItemCards;
use CW\Catalogue\ItemRules;
use CW\CwException;
use CW\Db;
use CW\Items\Families;
use CW\Items\HandMade;
use CW\Matching\Form;
use CW\OpResult;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Products › Add Item (pack B; owner answer Q10; docs/decisions.md PB1-PB3): the form that makes an item by hand (CW\Items\HandMade:
 * its identity, its product card, a barcode, a family) and the items made by hand, newest first, with the second person's check while
 * approvals.item_by_hand_check is on. Who: items.create (the routes; checked again by the service, never admin). The form carries a
 * form key (one item however often it is sent). The same name and identity is answered with the other items and a tick box "it is a
 * different item"; every other refusal comes back on the form in words, with what was typed kept.
 */
final class HandMadeController
{
    public const PATH = '/ui/items/new';
    /** The identity fields of the form, in form order (DecisionService::CARD_FIELDS). */
    public const IDENTITY = ['name', 'brand', 'line', 'flavour', 'strength_mg', 'nic_type', 'form', 'volume_ml', 'puffs', 'pack_units'];
    /** The product card's fields on this form: `c_<field>` (the card's brand and flavour are the box's, apart from the identity's). */
    public const CARD = ['product_type', 'liquid_ml', 'nicotine_mg', 'duty_liable', 'single_use', 'ecid', 'manufacturer', 'brand', 'flavour', 'discontinued'];
    public const NIC_TYPES = ['salt', 'freebase', 'zero'];
    /** The form's yes / no answers (radios: yes, no, or "not known", which sends nothing). */
    private const ANSWERS = ['c_duty_liable', 'c_single_use'];

    public function form(Context $ctx): HtmlResponse
    {
        $notice = $ctx->req->param('notice');
        // A name brought by a link (Lines to place's "+ Add new item", docs/decisions.md LQ5) fills in the name; nothing is made until it is sent.
        $name = trim((string) ($ctx->req->param('name') ?? ''));
        return $this->page($ctx, 200, null, $name === '' ? [] : ['name' => mb_substr($name, 0, 120)], null, FormOnce::newKey(),
            $notice !== null ? (Words::ITEMS_NOTICE[$notice] ?? null) : null);
    }

    public function create(Context $ctx): HtmlResponse
    {
        $typed = self::posted($ctx->req);
        $key = $ctx->req->field(FormOnce::FIELD) ?? '';
        try {
            $familyId = null;
            if ($typed['family'] !== '') {
                $fam = (new Families($ctx->db))->activeByName($typed['family'])
                    ?? throw new CwException('unknown_family', 'no family in use has that name', 422, ['field' => 'family']);
                $familyId = $fam['id'];
            }
            $in = self::input($typed, $familyId);
            $r = FormOnce::run($ctx, 'ui.item.create', $typed, static function (Db $db) use ($ctx, $in): OpResult {
                $made = (new HandMade($db, $ctx->decisions()))->create($ctx->caller(), $in);
                return OpResult::of(303, ['sku_id' => $made['sku_id'], 'redirect' => Html::url('/ui/items/' . $made['sku_id'],
                    ['notice' => $made['check_state'] === 'waiting' ? 'item_made_waiting' : 'item_made'])]);
            });
        } catch (CwException $e) {
            $same = $e->errorCode === 'same_item_exists' && is_array($e->detail['items'] ?? null) ? $e->detail['items'] : null;
            return $this->page($ctx, $e->httpStatus, $same === null ? $e : null, $typed, $same,
                in_array($e->errorCode, ['bad_form_key', 'idempotency_key_reused'], true) || preg_match('/^[0-9a-f]{32}$/D', $key) !== 1 ? FormOnce::newKey() : $key);
        }
        return FormOnce::redirect($r);
    }

    /** A second person's check of an item made by hand (approvals.item_by_hand_check). */
    public function check(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        try {
            (new HandMade($ctx->db, $ctx->decisions()))->check($ctx->caller(), $id, $ctx->req->field('note'));
        } catch (CwException $e) {
            return $this->page($ctx, $e->httpStatus, $e, [], null, FormOnce::newKey(), null, true);
        }
        return HtmlResponse::redirect(Html::url(self::PATH, ['notice' => 'checked', 'show' => 'waiting']));
    }

    /**
     * The page: the one-page form (wave w11, docs/decisions.md W11.2), or the items made by hand as a list of their own (`?show=all` or
     * `?show=waiting`, and a refused check: $list; W11.3).
     *
     * @param array<string, string> $typed
     * @param list<array<string, mixed>>|null $same the items of the same name and identity (the warning), or null
     */
    private function page(Context $ctx, int $status, ?CwException $error, array $typed, ?array $same, string $formKey, ?string $notice = null, bool $list = false): HtmlResponse
    {
        $show = $ctx->req->param('show');
        if ($list || $show === 'all' || $show === 'waiting') {
            return $this->listPage($ctx, $status, $error, $show === 'waiting', $notice);
        }
        $errors = $error !== null && is_array($error->detail['errors'] ?? null) ? array_map('strval', array_values($error->detail['errors'])) : [];
        $field = is_string($error?->detail['field'] ?? null) ? (string) $error->detail['field'] : null;
        $v = $typed + self::blank();
        // A fold opens when what it holds was typed or refused (a refused form comes back with every value where it was). Typed text is typed
        // whatever it says ("on" too, W11.15); a yes / no answer is given only by its yes or its no (its "not known" sends nothing).
        $typedIn = static fn (array $names): bool => array_filter($names, static fn (string $n): bool => in_array($n, self::ANSWERS, true)
            ? in_array($v[$n], ['yes', 'no'], true) : $v[$n] !== '') !== [];
        $legal = array_map(static fn (string $f): string => 'c_' . $f, array_values(array_diff(self::CARD, ['discontinued'])));
        $buying = ['pack_units', 'family', 'c_discontinued'];
        return $ctx->page('item_new', [
            'v' => $v,
            'formKey' => $formKey,
            'same' => $same === null ? null : array_map(static fn (array $s): array => ['id' => (int) $s['id'], 'code' => (string) $s['code'], 'name' => (string) $s['name']], $same),
            'error' => $error === null ? null : ($errors !== [] ? Words::ITEM_NEW['card_errors'] : FamiliesController::plain($error)),
            'errorCode' => $error?->errorCode,
            'errorField' => $field,
            'errorItem' => $error?->errorCode === 'barcode_on_other_item' && isset($error->detail['sku_id']) ? ['id' => (int) $error->detail['sku_id'],
                'code' => (string) ($error->detail['code'] ?? '')] : null,
            'cardErrors' => $errors,
            'legalOpen' => $errors !== [] || in_array($field, $legal, true) || $typedIn($legal),
            'buyingOpen' => in_array($field, $buying, true) || $typedIn($buying),
            'types' => array_map('ucfirst', ItemRules::TYPES),
            'forms' => array_combine(Form::ALL, array_map(static fn (string $f): string => Words::of('FORM_VALUE', $f), Form::ALL)),
            'nicTypes' => array_combine(self::NIC_TYPES, array_map(static fn (string $t): string => Words::of('NIC_TYPE', $t), self::NIC_TYPES)),
            'families' => (new Families($ctx->db))->activeNames(),
        ], $status, ['title' => Words::title('item_new'), 'notice' => $notice]);
    }

    /** The items made by hand, newest first, grouped by their check (a board; docs/decisions.md W11.3), with the second person's check in each row. */
    private function listPage(Context $ctx, int $status, ?CwException $error, bool $waiting, ?string $notice): HtmlResponse
    {
        $page = max(1, min(10_000, UiRequest::id($ctx->req->param('page')) ?? 1));
        $list = (new HandMade($ctx->db, $ctx->decisions()))->recent($waiting, $page);
        $me = $ctx->me();
        $rows = array_map(static fn (array $r): array => [
            'id' => (int) $r['sku_id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'], 'brand' => $r['brand'] === null ? null : (string) $r['brand'],
            'who' => (string) ($r['created_by_name'] ?? ''), 'at' => (string) $r['created_at'], 'state' => (string) $r['check_state'], 'group' => (string) $r['check_state'],
            'checked' => $r['checked_at'] === null ? null : Words::say('ITEM_NEW', 'checked_by', (string) ($r['checked_by_name'] ?? ''), Html::when((string) $r['checked_at'])),
            'note' => $r['check_note'] === null ? null : (string) $r['check_note'],
            'canCheck' => $r['check_state'] === 'waiting' && (int) $r['created_by'] !== $me->id,
            'own' => $r['check_state'] === 'waiting' && (int) $r['created_by'] === $me->id,
        ], $list['rows']);
        $show = $waiting ? 'waiting' : 'all';
        return $ctx->page('items_by_hand', [
            'rows' => $rows,
            'groups' => Html::groups($rows, array_map(static fn (string $state): array => [Words::tone('BY_HAND_STATE', $state), Words::of('BY_HAND_STATE', $state)],
                array_combine(['waiting', 'checked', 'not_asked'], ['waiting', 'checked', 'not_asked']))),
            'show' => $show,
            // Items' ONE Show choice (the redesign, docs/decisions.md RD-A3): this page is its Made by hand; waiting for a check is under More.
            'views' => [
                ['label' => Words::INV_LIST['all_items'], 'href' => '/ui/items/cards', 'current' => false],
                ['label' => Words::INV_LIST['holding'], 'href' => '/ui/items/cards?stock=1', 'current' => false],
                ['label' => Words::INV_LIST['needs_look'], 'href' => '/ui/items/cards?state=unconfirmed', 'current' => false],
                ['label' => Words::INV_LIST['by_hand'], 'href' => Html::url(self::PATH, ['show' => 'all']), 'current' => true],
            ],
            'bar' => ['links' => [$waiting ? ['label' => Words::ITEM_NEW['show_all'], 'href' => Html::url(self::PATH, ['show' => 'all'])]
                : ['label' => Words::ITEM_NEW['show_waiting'], 'href' => Html::url(self::PATH, ['show' => 'waiting'])]],
                'main' => $me->can('items.create') ? ['label' => Words::INV_LIST['new_item'], 'href' => self::PATH] : null],
            'checkOn' => ApprovalRules::on($ctx->db, HandMade::CHECK_SWITCH),
            'error' => $error === null ? null : FamiliesController::plain($error),
            'errorCode' => $error?->errorCode,
            'page_no' => $list['page'],
            'pages' => $list['pages'],
            'prev_link' => $list['page'] > 1 ? Html::url(self::PATH, ['show' => $show, 'page' => $list['page'] - 1]) : null,
            'next_link' => $list['page'] < $list['pages'] ? Html::url(self::PATH, ['show' => $show, 'page' => $list['page'] + 1]) : null,
        ], $status, ['title' => Words::ITEM_NEW['list_title'], 'notice' => $notice]);
    }

    /** @return array<string, string> every field of the form, empty */
    private static function blank(): array
    {
        $out = [];
        foreach (self::IDENTITY as $f) {
            $out[$f] = '';
        }
        foreach (self::CARD as $f) {
            $out['c_' . $f] = '';
        }
        return $out + ['barcode' => '', 'barcode_units' => '1', 'family' => '', 'anyway' => ''];
    }

    /** @return array<string, string> what the form sent (a missing field is empty) */
    private static function posted(UiRequest $req): array
    {
        $out = [];
        foreach (array_keys(self::blank()) as $f) {
            $out[$f] = trim($req->field($f) ?? '');
        }
        $out['c_discontinued'] = $out['c_discontinued'] === 'yes' ? 'yes' : '';
        $out['anyway'] = $out['anyway'] === '1' ? '1' : '';
        if ($out['barcode_units'] === '') {
            $out['barcode_units'] = '1';
        }
        return $out;
    }

    /**
     * The service's input from the form.
     *
     * @param array<string, string> $typed
     * @return array<string, mixed>
     */
    private static function input(array $typed, ?int $familyId): array
    {
        $card = [];
        foreach (self::IDENTITY as $f) {
            $card[$f] = $typed[$f] === '' ? null : $typed[$f];
        }
        $item = [];
        foreach (self::CARD as $f) {
            $v = $typed['c_' . $f];
            if ($f === 'discontinued') {
                $v = $v === 'yes' ? 'yes' : 'no';
            }
            $item[$f] = $v;
        }
        if (preg_match('/^[0-9]{1,5}$/D', $typed['barcode_units']) !== 1) {
            throw new CwException('bad_units', 'units per scan is a whole number', 422, ['field' => 'barcode_units']);
        }
        return ['card' => $card, 'item_card' => array_intersect_key($item, ItemCards::FIELDS), 'barcode' => $typed['barcode'], 'barcode_units' => (int) $typed['barcode_units'],
            'family_id' => $familyId, 'anyway' => $typed['anyway'] === '1'];
    }
}
