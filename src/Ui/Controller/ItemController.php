<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Catalogue\BarcodeReviews;
use CW\Catalogue\CardProposals;
use CW\Catalogue\ItemCards;
use CW\Catalogue\ItemRules;
use CW\Clock;
use CW\Counts\CountSheets;
use CW\CwException;
use CW\Documents\Documents;
use CW\Mapping\SiteState;
use CW\Reorder\DemandMath;
use CW\StockOps\StockOps;
use CW\Ui\ByProductContext;
use CW\Ui\Context;
use CW\Ui\Duplicates;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * /ui/items/{id}: who an item is, its item card (IM3: the legal and buying fields, the rules they break, suggestions to accept,
 * the confirmation, the history), its barcodes (with the forms to add, remove and set units per scan for catalogue.edit),
 * every listing linked to it (now or before), the selling mode CW writes on each website with the switch (IM10, SellingModeController),
 * its stock and (suppliers.view) who supplies it. ItemCardsController's actions
 * redraw this page with their error (page()). One page in sections (the approved redesign of 30 Oct 2026, artboard Item; docs/decisions.md
 * RD-A4; stock first since IV1-IV12): the head with the status label, the fact line and the actions this person may use (top()), the stock
 * card, Details (facts()), the recent stock history, the side cards (storeRows(), the barcodes, the suppliers), and the closed folds of
 * what the tabs of before held (PARTS; which one opens: openPart()).
 */
final class ItemController
{
    /** The notices named in a redirect (their words: Words::CARD_NOTICE). */
    public const NOTICES = Words::CARD_NOTICE;

    /**
     * The page is one page in sections (the approved redesign of 30 Oct 2026, artboard Item; docs/decisions.md RD-A4): no tab row. What the
     * tabs of before held that the artboard does not draw is a closed fold under the history: these parts, which a refused form, a redirect's
     * notice or an old `?tab=` link opens (openPart()). `barcodes` is the Barcodes card's add form (open after a refused one).
     */
    public const PARTS = ['barcodes', 'warehouses', 'selling', 'groups', 'history'];

    /** The part a redirect's notice opens: where the change was made. Any other notice opens none. */
    public const NOTICE_PARTS = ['selling_mode_set' => 'selling', 'selling_mode_unchanged' => 'selling', 'family_set' => 'groups', 'family_out' => 'groups',
        'unchanged' => 'groups'];

    /** An old link's tab (the tab row of before, IV6) => the part it opens now; Details and Barcodes are always shown. */
    private const OLD_TABS = ['details' => null, 'barcodes' => null, 'packs' => 'groups', 'stores' => 'selling', 'history' => 'history'];

    /** The weeks after which "lasts about N weeks" says "over a year" (a year's weeks: a fact of the calendar, not a setting). */
    private const YEAR_WEEKS = 52;

    public function show(Context $ctx): HtmlResponse
    {
        $notice = $ctx->req->param('notice') ?? '';
        return $this->page($ctx, 200, null, self::NOTICES[$notice] ?? SellingModeController::NOTICES[$notice] ?? Words::ITEMS_NOTICE[$notice] ?? null);
    }

    /**
     * The item page, also after a refused card or barcode action ($error shown on top, answered under $status; $typed: what
     * the barcode forms had, so a refused add keeps it).
     *
     * @param array<string, mixed> $typed ('selling_mode': what a refused selling-mode form had)
     */
    public function page(Context $ctx, int $status, ?CwException $error, ?string $notice = null, array $typed = []): HtmlResponse
    {
        $q = $ctx->queries();
        $id = $ctx->id();
        $sku = $q->sku($id);
        if ($sku === null) {
            return $ctx->error(404, 'unknown_item', 'no such item', ['/ui/search', Words::title('search')]);
        }
        $listings = $q->listingsOfSku($id);
        $names = [];
        foreach ($q->channels() as $c) {
            $names[(string) $c['code']] = (string) $c['name'];
        }
        $history = $q->historyOf(array_map(static fn (array $r): int => (int) $r['id'], $listings));
        $rows = [];
        foreach ($listings as $l) {
            $lid = (int) $l['id'];
            $periods = [];
            foreach ($history[$lid] ?? [] as $h) {
                $periods[] = [
                    'sku_id' => (int) $h['sku_id'], 'sku_code' => self::s($h['sku_code']), 'units' => $h['units_per_item'],
                    'from' => self::s($h['valid_from']), 'to' => self::s($h['valid_to']), 'action' => self::s($h['action']),
                    'decider' => self::s($h['decider']), 'decision_id' => (int) $h['decision_id'], 'this_item' => (int) $h['sku_id'] === $id,
                ];
            }
            $rows[] = [
                'id' => $lid, 'channel' => $names[(string) $l['channel_code']] ?? self::s($l['channel_code']), 'variant' => self::s($l['external_variant_id']),
                'title' => self::s($l['product_title']), 'variant_title' => self::s($l['variant_title']), 'status' => self::s($l['status']),
                'units_per_item' => (int) $l['units_per_item'], 'units_30d' => $l['units_30d'], 'units_365d' => $l['units_365d'],
                'linked' => $l['sku_id'] !== null && (int) $l['sku_id'] === $id, 'elsewhere' => $l['sku_id'] !== null && (int) $l['sku_id'] !== $id,
                'periods' => $periods,
            ];
        }
        $stock = [];
        $totals = ['on_hand' => 0, 'incoming' => 0, 'allocated' => 0, 'held' => 0, 'available' => 0];
        foreach ($q->stockOf($id) as $b) {
            $stock[] = [
                'code' => self::s($b['code']), 'name' => self::s($b['name']), 'sellable' => (bool) $b['is_sellable'], 'on_hand' => $b['on_hand'],
                'allocated' => $b['allocated'], 'held' => $b['held'], 'incoming' => $b['incoming'], 'available' => $b['available'], 'counted_at' => self::s($b['counted_at']),
            ];
            if ($b['is_sellable']) {
                foreach (array_keys($totals) as $k) {
                    $totals[$k] += (int) $b[$k];
                }
            }
        }
        $ledgerRows = $q->ledgerOf($id);
        // Who made a stock change, by name (plan F253): staff:<id> -> the person, system:… -> set up by CW, channel:<code> -> the website.
        $staffIds = [];
        foreach ($ledgerRows as $r) {
            if (preg_match('/^staff:(\d{1,10})$/D', (string) $r['actor'], $m) === 1) {
                $staffIds[(int) $m[1]] = true;
            }
        }
        $people = [];
        if ($staffIds !== []) {
            $ids = array_keys($staffIds);
            foreach ($ctx->db->all('SELECT id, display_name FROM staff_user WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $p) {
                $people[(int) $p['id']] = (string) $p['display_name'];
            }
        }
        $who = static function (?string $actor) use ($people, $names): ?string {
            if ($actor === null) {
                return null;
            }
            return match (true) {
                preg_match('/^staff:(\d{1,10})$/D', $actor, $m) === 1 => $people[(int) $m[1]] ?? $actor,
                str_starts_with($actor, 'system:') => Words::ITEM['by_cw'],
                str_starts_with($actor, 'channel:') => $names[substr($actor, 8)] ?? Words::ITEM['by_site'],
                default => $actor,
            };
        };
        $ledger = [];
        foreach ($ledgerRows as $r) {
            $note = self::s($r['note']);
            $ledger[] = [
                'id' => (int) $r['id'], 'warehouse' => self::s($r['warehouse']), 'bucket' => self::s($r['bucket']), 'delta' => $r['qty_delta'],
                'after' => $r['balance_after'], 'type' => self::s($r['movement_type']), 'ref' => self::s($r['order_ref'] ?? $r['doc_ref']),
                'actor' => $who(self::s($r['actor'])), 'note' => $note !== null && preg_match('/^#\d+$/D', $note) === 1 ? null : $note, 'at' => self::s($r['effective_at']),
                // The solid cell by kind (IV4) and the change with its sign ("+20", "−4").
                'kind' => Words::of('MOVE_KIND', self::s($r['movement_type'])), 'tone' => Words::tone('MOVE_KIND', self::s($r['movement_type'])),
                'signed' => self::signed((int) $r['qty_delta']), 'dir' => (int) $r['qty_delta'] > 0 ? 'up' : 'down',
            ];
            // A row a stock record booked (pack A1): the record, its reason and who it was given to, and a link to it.
            $kind = ($r['doc_type'] ?? null) === null ? null : \CW\StockOps\StockOps::kindOf((string) $r['doc_type']);
            $ledger[count($ledger) - 1] += $kind === null ? ['record' => null] : ['record' => ['what' => Words::STOCK_KIND[$kind]['one'],
                'why' => self::s($r['reason_label'] ?? null), 'given' => ($r['given_to'] ?? null) === null ? null : Words::say('STOCK_OPS', 'given', (string) $r['given_to']),
                'href' => \CW\StockOps\StockOps::PATHS[$kind] . '/' . (int) $r['document_id']]];
            if ($kind !== null) {
                $ledger[count($ledger) - 1]['note'] = null;
            }
            // Pack B (PB13): a repack's rows name their record (its own page is the record page).
            if ($kind === null && ($r['doc_type'] ?? null) === \CW\Items\RepackHandler::TYPE) {
                $ledger[count($ledger) - 1]['record'] = ['what' => Words::DOC_TYPE['RPK'], 'why' => null, 'given' => null, 'href' => '/ui/documents/' . (int) $r['document_id']];
                $ledger[count($ledger) - 1]['note'] = null;
            }
        }
        $mergedInto = $sku['merged_into_sku_id'] === null ? null : $q->sku((int) $sku['merged_into_sku_id']);
        $canEdit = $ctx->me()->can('catalogue.edit') && $sku['merged_into_sku_id'] === null;
        $barcodes = array_map(static fn (array $b): array => [
            'barcode' => self::s($b['barcode']), 'usable' => (bool) $b['is_usable'], 'units' => (int) $b['units_per_scan'],
            'source' => Words::of('BARCODE_SOURCE', (string) ($b['source'] ?? 'manual')),
            'note' => ($b['note'] ?? null) === BarcodeReviews::IN_REVIEW_NOTE ? Words::BARCODE_SOURCE['in_review'] : self::s($b['note'] ?? null),
            'stored' => true, 'removeKey' => $canEdit ? FormOnce::newKey() : null, 'unitsKey' => $canEdit ? FormOnce::newKey() : null,
        ], $ctx->db->all('SELECT barcode, is_usable, units_per_scan, source, note FROM sku_barcode WHERE sku_id = ? ORDER BY units_per_scan, barcode', [$id]));
        if ($barcodes === []) {
            // Not seeded into sku_barcode yet: the usable barcodes of the listing it was minted from.
            foreach ($q->barcodesOfMany([$id])[$id] ?? [] as $code) {
                $barcodes[] = ['barcode' => $code, 'usable' => true, 'units' => 1, 'source' => ucfirst(Words::ITEM['minted_from']), 'note' => null, 'stored' => false,
                    'removeKey' => null, 'unitsKey' => null];
            }
        }
        $reviews = array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'barcode' => (string) $r['barcode'],
            'reason' => Words::of('BARCODE_REASON', (string) $r['reason'])],
            $ctx->db->all("SELECT id, barcode, reason FROM barcode_review WHERE status = 'open' AND (claimant_sku_id = ? OR holder_sku_id = ?) ORDER BY id LIMIT 50", [$id, $id]));
        $cwp = $sku['origin'] === 'vpg_mint' ? ($q->skus([$id])[$id]['vpg_variant_id'] ?? null) : null;
        // What it was made from, by name (plan F249): "Vape and Go product "Elux Legend …" (option 700001)".
        $madeFrom = null;
        if ($sku['origin_listing_id'] !== null) {
            $o = $q->listing((int) $sku['origin_listing_id']);
            if ($o !== null) {
                $madeFrom = ['id' => (int) $o['id'], 'text' => Words::say('ITEM', 'made_from_line', (string) $o['channel_name'],
                    trim(((string) $o['product_title']) . ' ' . ((string) $o['variant_title'])), (string) $o['external_variant_id'])];
            }
        }
        // Who supplies the item (IM4, I-2): shown to the people who may see suppliers.
        $suppliers = null;
        if ($ctx->me()->can('suppliers.view')) {
            $suppliers = array_map(static fn (array $r): array => [
                'id' => (int) $r['id'], 'supplier_id' => (int) $r['supplier_id'], 'supplier' => (string) $r['supplier_code'], 'name' => (string) $r['supplier_name'],
                'status' => (string) $r['status'], 'code' => self::s($r['supplier_code_item']),
                'pack' => PurchaseOrdersController::pack((string) $r['purchase_unit'], (int) $r['units_per_pack']),
                'preferred' => (int) $r['is_preferred'] === 1 && (int) $r['is_active'] === 1, 'active' => (int) $r['is_active'] === 1,
                'price' => $r['last_pack_price'] === null ? null : Html::money($r['last_pack_price']), 'price_on' => self::s($r['last_price_on']),
            ], $ctx->db->all(
                'SELECT i.id, i.supplier_id, s.code AS supplier_code, s.name AS supplier_name, s.status, i.supplier_code AS supplier_code_item, i.purchase_unit, '
                . 'i.units_per_pack, i.is_preferred, i.is_active, i.last_pack_price, i.last_price_on FROM supplier_item i JOIN supplier s ON s.id = i.supplier_id '
                . 'WHERE i.sku_id = ? ORDER BY i.is_active DESC, i.is_preferred DESC, s.name, i.units_per_pack',
                [$id],
            ));
        }
        // The duplicate groups of its listings (now or before a merge, M44): the group page holds the decision and its undo.
        $dupGroups = [];
        if ($ctx->me()->can('linking.view')) {
            // Its listings, and the listings named by the merges and splits that moved stock to or from it (a merged item's page).
            $lids = [...array_map(static fn (array $r): int => $r['id'], $rows), ...array_map('intval', array_column($ctx->db->all(
                "(SELECT id, listing_id FROM match_decision WHERE sku_id = ? AND action IN ('merge_skus', 'split') AND state = 'applied') UNION "
                . "(SELECT id, listing_id FROM match_decision WHERE merge_from_sku_id = ? AND action = 'merge_skus' AND state = 'applied') ORDER BY id DESC LIMIT 50",
                [$id, $id]), 'listing_id'))];
            foreach ((new Duplicates($ctx->db))->groupNumbersOfListings($lids) as $refs) {
                foreach ($refs as $ref) {
                    $dupGroups[$ref['id']] = $ref;
                }
            }
            ksort($dupGroups);
        }
        $cardVars = self::cardVars($ctx, $id, $canEdit);
        $top = self::top($ctx, $id, $sku, $cardVars, $totals, $ledger);
        $activeSupplies = $suppliers === null ? null : count(array_filter($suppliers, static fn (array $r): bool => $r['active']));
        $groupVars = self::groupVars($ctx, $id, $sku);
        return $ctx->page('item', $cardVars + $top + [
            'open' => self::openPart($ctx),
            // The redesign's sections (RD-A4): the Details groups, the side cards' lines.
            'facts' => self::facts($ctx, $id, $sku, $cardVars, $suppliers, $stock, $madeFrom, $cwp === null ? null : 'CWP-' . $cwp),
            'storeRows' => self::storeRows($ctx, $id, $sku, $top['sells'], $rows),
            'barcodeLines' => array_map(static fn (array $b): array => $b + ['units_words' => $b['units'] === 1 ? Words::ITEM_PAGE['single']
                : Words::say('ITEM_PAGE', 'box_of', $b['units'])], $barcodes),
            'supplierRows' => $suppliers === null ? null : array_values(array_filter($suppliers, static fn (array $r): bool => $r['active'])),
            'supplierRowsOff' => $suppliers === null ? [] : array_values(array_filter($suppliers, static fn (array $r): bool => !$r['active'])),
            // Pack B: the family and pack box, and its refusals in its words.
            'groups' => $groupVars,
            'error' => $error === null ? null : (isset(Words::ITEMS_ERROR[$error->errorCode]) ? FamiliesController::plain($error) : ItemCardsController::plain($error)),
            'errorCode' => $error?->errorCode,
            'errorDetail' => $error?->detail ?? [],
            'canEditBarcodes' => $canEdit,
            'barcodeAddKey' => $canEdit ? ($typed['form_key'] ?? FormOnce::newKey()) : null,
            'typedBarcode' => $typed['barcode'] ?? '',
            'typedUnits' => $typed['units'] ?? '1',
            'barcodeReviews' => $reviews,
            'canSeeReviews' => $ctx->me()->can('catalogue.edit'),
            'dup_groups' => array_values($dupGroups),
            'made_from' => $madeFrom,
            'sku' => [
                'id' => $id, 'code' => self::s($sku['code']), 'name' => self::s($sku['name']), 'brand' => self::s($sku['brand']),
                'policy' => self::s($sku['sell_policy']), 'strength_mg' => Html::dec($sku['strength_mg']), 'nic_type' => self::s($sku['nic_type']),
                'line' => self::s($sku['line']), 'form' => self::s($sku['form']), 'flavour' => self::s($sku['flavour']),
                'volume_ml' => Html::dec($sku['volume_ml']), 'puffs' => self::s($sku['puffs']), 'pack_units' => self::s($sku['pack_units']),
                'origin' => self::s($sku['origin']), 'origin_listing_id' => $sku['origin_listing_id'] === null ? null : (int) $sku['origin_listing_id'],
                // "Stock last counted": the latest count of its stock at any warehouse (stock_balance.counted_at, which a posted count
                // sets). The count gate's own sku.counted_at (plan §7.4) is another thing and is not what this line says (pack A2a, CT11).
                'created_at' => self::s($sku['created_at']), 'counted_at' => self::latest(array_column($stock, 'counted_at')),
                'cwp' => $cwp === null ? null : 'CWP-' . $cwp,
            ],
            'merged_into' => $mergedInto === null ? null : ['id' => (int) $mergedInto['id'], 'code' => self::s($mergedInto['code']), 'name' => self::s($mergedInto['name'])],
            'merged_from' => array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'code' => (string) $r['code']],
                $ctx->db->all('SELECT id, code FROM sku WHERE merged_into_sku_id = ? ORDER BY id LIMIT 50', [$id])),
            'barcodes' => $barcodes,
            'listings' => $rows,
            // Products › By Item, narrowed to this product: each store's column for it (U120).
            'coverage_link' => $ctx->me()->can('linking.view') && $sku['merged_into_sku_id'] === null
                ? Html::url(ByProductContext::LIST_PATH, ['q' => (string) $sku['code']]) : null,
            'stock' => $stock,
            'totals' => $totals,
            'ledger' => $ledger,
            'suppliers' => $suppliers,
            // IM10 (I158): what CW writes on each website, and the selling-mode switch.
            'sellingMode' => SellingModeController::vars($ctx, $id, $sku, is_array($typed['selling_mode'] ?? null) ? $typed['selling_mode'] : []),
        ], $status, ['title' => trim(((string) $sku['name']) . ' (' . ((string) $sku['code']) . ')'), 'active' => 'search', 'notice' => $notice]);
    }

    /**
     * Pack B (docs/decisions.md PB4-PB8, PB12): the item page's family and pack box: its family (and the form that puts it in one, moves it
     * or takes it out, for families.manage), the pack item it is or the pack items made of it, and who made it when it was made by hand.
     *
     * @param array<string, mixed> $sku Queries::sku()
     * @return array<string, mixed>
     */
    public static function groupVars(Context $ctx, int $id, array $sku): array
    {
        $family = \CW\Items\Families::ofItems($ctx->db, [$id])[$id] ?? null;
        $canFamily = $ctx->me()->can('families.manage') && $sku['merged_into_sku_id'] === null;
        $packs = (new \CW\Items\Packs($ctx->db))->ofItem($id);
        $own = $packs['pack'];
        $byHand = $ctx->db->one('SELECT h.check_state, h.created_at, u.display_name FROM item_by_hand h LEFT JOIN staff_user u ON u.id = h.created_by WHERE h.sku_id = ?', [$id]);
        return [
            'skuId' => $id,
            'family' => $family,
            'canFamily' => $canFamily,
            'familyNames' => $canFamily ? (new \CW\Items\Families($ctx->db))->activeNames() : [],
            'pack' => $own === null ? null : ['units' => (int) $own['units'], 'single' => ['id' => (int) $own['single_sku_id'], 'code' => (string) $own['single_code'],
                'name' => (string) $own['single_name']], 'flags' => array_values(array_map(static fn (string $f): array => ['tone' => Words::tone('PACK_FLAG', $f),
                'text' => Words::of('PACK_FLAG', $f)], array_filter($own['flags'], static fn (string $f): bool => $f !== 'suggest')))],
            'packsOf' => array_map(static fn (array $p): array => ['id' => (int) $p['pack_sku_id'], 'code' => (string) $p['pack_code'], 'name' => (string) $p['pack_name'],
                'units' => (int) $p['units']], $packs['packs']),
            'byHand' => $byHand === null ? null : ['state' => (string) $byHand['check_state'],
                'line' => Words::say('ITEM_NEW', 'on_item', (string) ($byHand['display_name'] ?? ''), Html::when((string) $byHand['created_at']))],
        ];
    }

    /**
     * The item card part of the page: the values as people read them, the state, the rules (breaches with their level, what a
     * confirmation still needs, advice), the suggestions with their accept forms (catalogue.edit), the confirm form and the
     * history.
     *
     * @return array<string, mixed>
     */
    public static function cardVars(Context $ctx, int $id, bool $canEdit): array
    {
        $svc = $ctx->itemCards();
        $card = $svc->card($id);
        $exists = $card['version'] > 0;
        $state = !$exists ? 'none' : ($card['confirmed_at'] !== null ? 'confirmed' : ($card['first_confirmed_at'] !== null ? 'changed' : 'unconfirmed'));
        $values = [];
        foreach (array_keys(ItemCards::FIELDS) as $k) {
            $v = $card[$k];
            $values[] = ['field' => $k, 'label' => Words::of('CARD_FIELD', $k), 'shown' => $v === null ? null : CardProposals::shown($k, $v)
                . ($k === 'flavour' && $card['flavour_status'] === 'proposed' ? ' (' . Words::CARD['from_file'] . ')' : '')];
        }
        $channels = [];
        foreach ($ctx->queries()->channels() as $c) {
            $channels[(string) $c['code']] = (string) $c['name'];
        }
        $st = ItemRules::status($card);
        $now = ItemRules::breaches($card);
        $proposals = null;
        $disposable = [];
        $disagree = [];
        $fileFlavour = null;
        if ($canEdit) {
            $p = (new CardProposals($ctx->db))->of($id, $card);
            $proposals = [];
            foreach ($p['fields'] as $field => $list) {
                foreach ($list as $one) {
                    $proposals[] = ['field' => $field, 'label' => Words::of('CARD_FIELD', $field), 'value' => self::formValue($one['value']), 'shown' => $one['shown'],
                        'sources' => array_map(static fn (string $src): string => self::source($src, $channels), $one['sources']), 'formKey' => FormOnce::newKey()];
                }
            }
            $disposable = array_map(static fn (string $src): string => self::source($src, $channels), $p['disposable_sources']);
            $disagree = array_map(static fn (string $f): string => mb_strtolower(Words::of('CARD_FIELD', $f)), $p['disagree']);
            if ($card['flavour'] !== null && $card['flavour_status'] === 'proposed') {
                $fileFlavour = ['value' => $card['flavour'], 'formKey' => FormOnce::newKey()];
            }
        }
        return [
            'card' => [
                'exists' => $exists, 'version' => $card['version'], 'state' => $state, 'values' => $values, 'discontinued' => (int) ($card['discontinued'] ?? 0) === 1,
                'confirmed_by' => $card['confirmed_by_name'] ?? ($card['confirmed_actor'] ?? null), 'confirmed_at' => $card['confirmed_at'],
                'first_confirmed_at' => $card['first_confirmed_at'], 'updated_by' => $card['updated_by_name'] ?? $card['updated_actor'], 'updated_at' => $card['updated_at'],
            ],
            'rules' => [
                'level' => $st['level'],
                // A blocked rule the fields no longer break (corrected, emptied or retyped since the last confirmation) still blocks
                // until a person confirms the card again (I113): `still` says which.
                'blocked' => array_map(static fn (string $r): array => ['code' => $r, 'label' => ItemRules::RULES[$r], 'why' => ItemRules::WHY[$r],
                    'still' => in_array($r, $now, true)], $st['blocked']),
                'warnings' => array_map(static fn (string $r): array => ['code' => $r, 'label' => ItemRules::RULES[$r], 'why' => ItemRules::WHY[$r]], $st['warnings']),
                'blockEffect' => Words::CARD['blocked'],
                'missing' => array_map(static fn (string $f): string => ItemRules::neededLabel($f, $card['product_type']), ItemRules::missing($card)),
                'advice' => ItemRules::advice($card),
            ],
            'canEditCard' => $canEdit,
            'proposals' => $proposals,
            'fileFlavour' => $fileFlavour,
            'disposableSources' => $disposable,
            'disagree' => $disagree,
            'confirmKey' => $canEdit && $exists && $state !== 'confirmed' ? FormOnce::newKey() : null,
            'cardHistory' => array_map(static function (array $h): array {
                $parts = [];
                foreach ($h['changes'] as $f => $c) {
                    $label = $f === 'flavour_status' ? Words::CARD['flavour_status'] : mb_strtolower(Words::of('CARD_FIELD', (string) $f));
                    $parts[] = $label . ': ' . self::historyValue($f, $c['before'] ?? null) . ' → ' . self::historyValue($f, $c['after'] ?? null);
                }
                $what = match ($h['kind']) {
                    'confirm' => match (true) {
                        ($h['detail']['breaches'] ?? []) !== [] => Words::say('CARD', 'h_confirmed_blocked', ItemRules::labels($h['detail']['breaches'])),
                        ($h['detail']['lifted'] ?? []) !== [] => Words::say('CARD', 'h_confirmed_lifted', ItemRules::labels($h['detail']['lifted'])),
                        default => Words::CARD['h_confirmed'],
                    },
                    'accept' => Words::say('CARD', 'h_used', implode('; ', $parts)),
                    'import' => Words::say('CARD', 'h_imported', implode('; ', $parts)),
                    default => implode('; ', $parts),
                };
                return ['version' => $h['version'], 'what' => $what, 'who' => $h['who'], 'at' => $h['at']];
            }, $svc->history($id, 20)),
        ];
    }

    /**
     * The page's top (docs/decisions.md IV1-IV5): the status block and the identity line of the head, the actions this person may use, the
     * stock strip, the recent movements' warehouse column, where it sells.
     *
     * @param array<string, mixed> $sku Queries::sku()
     * @param array<string, mixed> $cardVars cardVars()
     * @param array<string, int|string> $totals the sellable warehouses' sums
     * @param list<array<string, mixed>> $ledger the recent movements as the page draws them
     * @return array<string, mixed>
     */
    private static function top(Context $ctx, int $id, array $sku, array $cardVars, array $totals, array $ledger): array
    {
        $me = $ctx->me();
        $merged = $sku['merged_into_sku_id'] !== null;
        $supply = self::mainSupply($ctx, $id);
        $sells = self::sells($ctx, $id);
        $selling = array_filter($sells, static fn (array $s): bool => in_array($s['state'], ['on_sale', 'matched'], true)) !== [];
        [$tone, $status] = match (true) {
            $merged => ['off', Words::ITEM['st_merged']],
            $cardVars['card']['discontinued'] => ['off', Words::CARD_FIELD['discontinued']],
            $sku['sell_policy'] === 'stopped' => [Words::tone('POLICY', 'stopped'), Words::POLICY['stopped']],
            $cardVars['rules']['level'] === 'block' => ['blocked', Words::CARD_STATE['blocked']],
            $selling => ['done', Words::ITEM['st_selling']],
            default => ['waiting', Words::ITEM['st_not_selling']],
        };
        // The fact line under the name (the redesign's artboard Item, RD-A4): "CW-003167 · Elux Legend · 10 ml · 10 mg nic salt" (what is known).
        $identity = [(string) $sku['code']];
        $ml = Html::dec($sku['volume_ml']);
        $mg = Html::dec($sku['strength_mg']);
        $nic = $sku['nic_type'] === null || $sku['nic_type'] === '' ? null : mb_strtolower(Words::of('NIC_TYPE', (string) $sku['nic_type']));
        foreach ([self::s($sku['brand']), $ml === '' ? null : Words::say('ITEM_PAGE', 'ml', $ml),
            $mg === '' ? $nic : trim(Words::say('ITEM_PAGE', 'mg', $mg) . ' ' . ($nic ?? ''))] as $part) {
            if ($part !== null && $part !== '') {
                $identity[] = $part;
            }
        }
        // The actions (IV2): only what this person may use; none on an item joined into another (its stock and orders moved there).
        $roles = $me->roles;
        $actions = ['count' => null, 'move' => null, 'order' => null, 'more' => []];
        if (!$merged) {
            $warehouse = self::countWarehouse($ctx);
            if ($warehouse !== null && Documents::mayPost($roles, CountSheets::TYPE)) {
                $actions['count'] = ['warehouse' => $warehouse, 'key' => FormOnce::newKey()];
            }
            if (Documents::mayPost($roles, StockOps::KINDS['transfer'])) {
                $actions['move'] = Html::url(StockOps::PATHS['transfer'], ['item' => $id]) . '#new';
            }
            if (Documents::mayPost($roles, 'PO')) {
                // The pick alone (IV14): the new order's page names the box by its own words (a long name's words were cut in the address).
                $actions['order'] = $supply !== null
                    ? Html::url('/ui/purchasing/orders/new', ['supplier' => $supply['supplier_id'], 'pick_si' => $supply['id']])
                    : Html::url('/ui/purchasing/orders/new', ['pick_sku' => $id]);
            }
            foreach (['in' => 'more_in', 'out' => 'more_out', 'adjust' => 'more_adjust'] as $kind => $word) {
                if (Documents::mayPost($roles, StockOps::KINDS[$kind])) {
                    $actions['more'][] = ['label' => Words::ITEM[$word], 'href' => Html::url(StockOps::PATHS[$kind], ['item' => $id]) . '#new'];
                }
            }
            if ($me->can('reorder.view')) {
                $actions['more'][] = ['label' => Words::ITEM['more_reorder'], 'href' => '/ui/purchasing/reorder/items/' . $id];
            }
        }
        // ONE main action (DS5): Order more, else Count it, else Move stock.
        $actions['main'] = $actions['order'] !== null ? 'order' : ($actions['count'] !== null ? 'count' : ($actions['move'] !== null ? 'move' : null));
        $coming = $ctx->queries()->comingIn($id);
        $rate = $ctx->db->value('SELECT rate FROM reorder_demand WHERE sku_id = ?', [$id]);
        return [
            'top' => ['status' => $status, 'tone' => $tone, 'identity' => implode(' · ', $identity)],
            'actions' => $actions,
            'strip' => [
                'shelf' => (int) $totals['on_hand'], 'allocated' => (int) $totals['allocated'], 'held' => (int) $totals['held'], 'free' => (int) $totals['available'],
                'incoming' => (int) $totals['incoming'], 'coming' => array_sum(array_column($coming, 'units')), 'first' => $coming[0] ?? null,
                'canOpenPo' => $me->can('purchasing.view'), 'weekly' => $rate === null ? null : self::weekly((string) $rate, (int) $totals['available']),
            ],
            'moveWarehouses' => count(array_unique(array_column($ledger, 'warehouse'))) > 1,
            'allMoves' => Html::url('/ui/stock/movements', ['q' => (string) $sku['code'], 'figure' => 'all']),
            'sells' => $sells,
        ];
    }

    /**
     * "Sells a week" and how long the stock free to sell lasts at that rate (docs/decisions.md IV3), from the reorder engine's sales rate
     * (reorder_demand.rate: central units a day, its nightly job's): the week's units rounded half up ("under 1" when some sell but fewer
     * than half a unit a week), and "lasts about N weeks" in whole weeks (under a week, about 1 week, about N weeks, over a year), left
     * out when nothing sells or nothing is free. Integer arithmetic in ten-thousandths, as the engine's own (DemandMath).
     *
     * @return array{n: string, lasts: string}
     */
    public static function weekly(string $perDay, int $free): array
    {
        $weekE4 = DemandMath::toE4($perDay) * 7;
        $n = $weekE4 <= 0 ? 0 : DemandMath::halfUpDiv($weekE4, 10_000);
        $shown = $weekE4 > 0 && $n === 0 ? Words::ITEM['weekly_less'] : Html::int($n);
        if ($weekE4 <= 0 || $free <= 0) {
            return ['n' => $shown, 'lasts' => ''];
        }
        $weeks = intdiv($free * 10_000, $weekE4);
        return ['n' => $shown, 'lasts' => match (true) {
            $weeks < 1 => Words::ITEM['lasts_less'],
            $weeks === 1 => Words::ITEM['lasts_one'],
            $weeks >= self::YEAR_WEEKS => Words::ITEM['lasts_year'],
            default => Words::say('ITEM', 'lasts_many', $weeks),
        }];
    }

    /**
     * The item's main supply (docs/decisions.md IV1, IV2): its preferred supplier item that is in use, from a supplier not switched off (the
     * new order's supplier box offers every other), for the identity line and "Order more". Null: none.
     *
     * @return array{id: int, supplier_id: int, supplier_name: string, supplier_code: ?string, purchase_unit: string, units_per_pack: int}|null
     */
    private static function mainSupply(Context $ctx, int $id): ?array
    {
        $r = $ctx->db->one('SELECT i.id, i.supplier_id, s.name, i.supplier_code, i.purchase_unit, i.units_per_pack FROM supplier_item i JOIN supplier s ON s.id = i.supplier_id '
            . "WHERE i.sku_id = ? AND i.is_preferred = 1 AND i.is_active = 1 AND s.status <> 'inactive' ORDER BY i.id LIMIT 1", [$id]);
        return $r === null ? null : ['id' => (int) $r['id'], 'supplier_id' => (int) $r['supplier_id'], 'supplier_name' => (string) $r['name'],
            'supplier_code' => self::s($r['supplier_code']), 'purchase_unit' => (string) $r['purchase_unit'], 'units_per_pack' => (int) $r['units_per_pack']];
    }

    /** The warehouse "Count it" counts in: the count board's own first choice (the first sellable warehouse in use, else the first in use). */
    private static function countWarehouse(Context $ctx): ?int
    {
        $ws = $ctx->db->all('SELECT id, is_sellable FROM warehouse WHERE is_active = 1 ORDER BY sort_order, id');
        $sellable = array_values(array_filter($ws, static fn (array $w): bool => (int) $w['is_sellable'] === 1));
        return $sellable !== [] ? (int) $sellable[0]['id'] : ($ws === [] ? null : (int) $ws[0]['id']);
    }

    /**
     * Where it sells (docs/decisions.md IV5): one state a store, every store in the owner's order (SimpleMatchController::stores): matched there
     * and on sale (its website state as CW\Mapping\SiteState reads it, not too old), matched but not on sale (the store's word for it), matched
     * with its state not known, on hold (every matched variant there quarantined), waiting for a second OK, suggested, not linked. By Item's
     * own read of an item's cells (Queries::productCells).
     *
     * @return list<array{store: string, state: string, text: string, tone: string}>
     */
    private static function sells(Context $ctx, int $id): array
    {
        $q = $ctx->queries();
        $cells = $q->productCells([$id]);
        $now = Clock::now();
        $days = null;
        $out = [];
        foreach (SimpleMatchController::stores($q->everyStore()) as $store) {
            $cid = (int) $store['id'];
            $mine = static fn (array $rows): array => array_values(array_filter($rows, static fn (array $r): bool => (int) $r['channel_id'] === $cid));
            $matched = $mine($cells['matched']);
            $arg = null;
            if ($matched !== []) {
                $live = array_values(array_filter($matched, static fn (array $r): bool => $r['status'] !== 'quarantined'));
                $state = $live === [] ? 'on_hold' : 'matched';
                foreach ($live as $r) {
                    $s = SiteState::read($r, ['variant_status' => $r['features_status'] ?? null], ['variant_status' => $r['check_status'] ?? null]);
                    if ($s['source'] === null || ($s['source'] === SiteState::STORED && SiteState::tooOld($s['as_of'], $days ??= SiteState::maxDays($ctx->db), $now))) {
                        continue;
                    }
                    $not = SiteState::notOnSale($s);
                    if ($not === null && SiteState::text($s['variant_status']) !== null) {
                        $state = 'on_sale';
                        $arg = null;
                        break;
                    }
                    if ($not !== null && $state === 'matched') {
                        [$state, $arg] = ['not_on_sale', $not];
                    }
                }
            } else {
                $state = $mine($cells['waiting']) !== [] ? 'waiting' : ($mine($cells['suggested']) !== [] ? 'suggested' : 'not_linked');
            }
            $out[] = ['store' => (string) $store['name'], 'state' => $state, 'tone' => Words::tone('ITEM_SELLS', $state),
                'text' => Words::say('ITEM', 'sells_chip', (string) $store['name'], $arg === null ? Words::of('ITEM_SELLS', $state) : Words::say('ITEM_SELLS', $state, $arg))];
        }
        return $out;
    }

    /**
     * The part the page opens (docs/decisions.md RD-A4, IV7 of before): after a refused form, the form's own part (the page is drawn again
     * for its POST: its address says which form); else the address's `part` (or an old link's `tab`); else the part of the change a
     * redirect's notice names; else none (Details, the history and the side cards are always shown).
     */
    private static function openPart(Context $ctx): ?string
    {
        $req = $ctx->req;
        if ($req->method === 'POST') {
            $rest = (string) preg_replace('#^/ui/items/[0-9]+#', '', $req->path);
            return match (true) {
                str_starts_with($rest, '/barcodes') => 'barcodes',
                $rest === '/selling-mode' => 'selling',
                $rest === '/family' => 'groups',
                default => null,
            };
        }
        $part = $req->param('part');
        if ($part !== null && in_array($part, self::PARTS, true)) {
            return $part;
        }
        $tab = (string) $req->param('tab');
        if (array_key_exists($tab, self::OLD_TABS)) {
            return self::OLD_TABS[$tab];
        }
        return self::NOTICE_PARTS[(string) $req->param('notice')] ?? null;
    }

    /**
     * The Details section's groups (the redesign's artboard Item, docs/decisions.md RD-A4): PRODUCT (what the item is, as the matching knows it,
     * with its stock rule, what it was made from, when it was added and last counted), BUYING (who may see suppliers: the main supplier, the
     * box it is bought in, the average cost so far) and LEGAL (the product card's every field and the card's state). A fact with no value is
     * left out of PRODUCT and BUYING; the card's are all shown ("not known").
     *
     * @param array<string, mixed> $sku Queries::sku()
     * @param array<string, mixed> $cardVars cardVars()
     * @param list<array<string, mixed>>|null $suppliers
     * @param list<array<string, mixed>> $stock
     * @param array{id: int, text: string}|null $madeFrom the store product it was made from (its page), else its origin is said in words
     * @return list<array{key: string, title: string, facts: list<array{label: string, value: ?string, href?: ?string}>}>
     */
    private static function facts(Context $ctx, int $id, array $sku, array $cardVars, ?array $suppliers, array $stock, ?array $madeFrom, ?string $cwp): array
    {
        $v = static fn (mixed $x): ?string => self::s($x);
        $product = [];
        $add = static function (array &$list, string $label, ?string $value, ?string $href = null): void {
            if ($value !== null && $value !== '') {
                $list[] = ['label' => $label, 'value' => $value, 'href' => $href];
            }
        };
        $add($product, Words::FIELD['brand'], $v($sku['brand']));
        $add($product, Words::FIELD['line'], $v($sku['line']));
        $add($product, Words::FIELD['flavour'], $v($sku['flavour']));
        $mg = Html::dec($sku['strength_mg']);
        $add($product, Words::ITEM_PAGE['strength'], $mg === '' ? null : Words::say('ITEM_PAGE', 'mg', $mg));
        $ml = Html::dec($sku['volume_ml']);
        $add($product, Words::ITEM_PAGE['size'], $ml === '' ? null : Words::say('ITEM_PAGE', 'ml', $ml));
        $form = $v($sku['form']);
        $add($product, Words::ITEM_PAGE['kind'], $form === null ? null : (Words::has('FORM_VALUE', $form) ? Words::of('FORM_VALUE', $form) : $form));
        $add($product, Words::FIELD['puffs'], $v($sku['puffs']));
        $add($product, Words::FIELD['pack_units'], $v($sku['pack_units']));
        $nic = $v($sku['nic_type']);
        $add($product, Words::FIELD['nic_type'], $nic === null ? null : (Words::has('NIC_TYPE', $nic) ? Words::of('NIC_TYPE', $nic) : $nic));
        $add($product, Words::ITEM['rule'], Words::of('POLICY', (string) $sku['sell_policy']));
        // What it was made from, by name (plan F249): the store product's page, else the origin in words.
        $origin = $v($sku['origin']);
        $add($product, Words::ITEM['made_from'], $madeFrom['text'] ?? ($origin === null ? Words::LISTING['not_stated'] : Words::of('ORIGIN', $origin)),
            $madeFrom === null ? null : '/ui/review/listing/' . $madeFrom['id']);
        $add($product, Words::LISTING['tech_cwp'], $cwp);
        $add($product, Words::ITEM_PAGE['added'], Html::when((string) $sku['created_at']));
        $counted = self::latest(array_column($stock, 'counted_at'));
        $product[] = ['label' => Words::ITEM['last_counted'], 'value' => $counted === null ? Words::ITEM['never'] : Html::when($counted), 'href' => null];
        $out = [['key' => 'product', 'title' => Words::ITEM_PAGE['g_product'], 'facts' => $product]];
        if ($suppliers !== null) {
            $main = null;
            foreach ($suppliers as $r) {
                if ($r['preferred'] && $r['active']) {
                    $main = $r;
                    break;
                }
            }
            $buying = [];
            $add($buying, Words::ITEM_PAGE['main_supplier'], $main['name'] ?? null, $main === null ? null : '/ui/purchasing/supplier-items/' . $main['id']);
            $add($buying, Words::ITEM_PAGE['buys_in'], $main['pack'] ?? null);
            $avg = (new \CW\StockOps\CostHints($ctx->db))->average([$id])[$id] ?? null;
            $add($buying, Words::ITEM_PAGE['avg_cost'], $avg === null ? null : Html::money($avg));
            if ($buying === []) {
                $buying[] = ['label' => Words::ITEM_PAGE['main_supplier'], 'value' => Words::ITEM_PAGE['none_yet'], 'href' => null];
            }
            $out[] = ['key' => 'buying', 'title' => Words::ITEM_PAGE['g_buying'], 'facts' => $buying];
        }
        $legal = array_map(static fn (array $f): array => ['label' => (string) $f['label'], 'value' => $f['shown'], 'href' => null], $cardVars['card']['values']);
        $legal[] = ['label' => Words::ITEM_PAGE['card'], 'value' => Words::of('CARD_STATE', (string) $cardVars['card']['state']), 'href' => null];
        $out[] = ['key' => 'legal', 'title' => Words::ITEM_PAGE['g_legal'], 'facts' => $legal];
        return $out;
    }

    /**
     * The Store listings card (the redesign's artboard Item, RD-A4): each store in the owner's order with its state as a small label (where it
     * sells, IV5), the store products matched to the item there (their page: Change, for who may look at matching) and, where none is, Match…
     * (the store's picker for who may decide).
     *
     * @param array<string, mixed> $sku Queries::sku()
     * @param list<array{store: string, state: string, text: string, tone: string}> $sells
     * @param list<array<string, mixed>> $listings the page's listing rows
     * @return list<array<string, mixed>>
     */
    private static function storeRows(Context $ctx, int $id, array $sku, array $sells, array $listings): array
    {
        $me = $ctx->me();
        $look = $me->can('linking.view');
        $decide = $me->can(\CW\Ui\Route::DECIDE) && $sku['merged_into_sku_id'] === null;
        $codes = [];
        foreach ($ctx->queries()->everyStore() as $st) {
            $codes[(string) $st['name']] = (string) $st['code'];
        }
        $out = [];
        foreach ($sells as $st) {
            $mine = array_values(array_filter($listings, static fn (array $l): bool => $l['linked'] && $l['channel'] === $st['store']));
            $out[] = [
                'store' => $st['store'], 'tone' => $st['tone'], 'state' => ucfirst(Words::of('ITEM_SELLS', $st['state'] === 'not_on_sale' ? 'matched' : $st['state'])),
                'title' => $st['text'],
                'listings' => array_map(static fn (array $l): array => [
                    'line' => Words::say('ITEM_PAGE', 'listing_line', trim(((string) ($l['title'] ?? Words::LISTING['no_title'])) . ' ' . ((string) ($l['variant_title'] ?? ''))),
                        (string) $l['variant'], Words::saleUses($l['units_per_item'])),
                    'href' => $look ? '/ui/review/listing/' . $l['id'] : null,
                ], $mine),
                'match' => $mine === [] && $decide && isset($codes[$st['store']])
                    ? Html::url('/ui/review/products/' . $id . '/map', ['channel' => $codes[$st['store']]]) : null,
            ];
        }
        return $out;
    }

    /** A change with its sign: "+20", "−4" (a true minus), "0". */
    private static function signed(int $v): string
    {
        return $v > 0 ? '+' . Html::int($v) : ($v < 0 ? '−' . Html::int(-$v) : '0');
    }

    /** A card value as the accept form sends it (ItemCards::check reads it back to the same value). */
    private static function formValue(mixed $v): string
    {
        return is_int($v) ? (string) $v : (string) $v;
    }

    /**
     * Where a suggestion came from, in words (CardProposals names "the item's identity card …" and "listing <channel> <variant>
     * "<title>""): what the matching read, or the website product by its title, website and option number.
     *
     * @param array<string, string> $channels channel code => name
     */
    public static function source(string $src, array $channels): string
    {
        $m = [];
        if (str_starts_with($src, 'the item\'s identity card')) {
            return Words::CARD['src_identity'];
        }
        if (preg_match('/^listing (\S+) (\S+)(?: "(.*)")?$/sD', $src, $m) === 1) {
            $site = $channels[$m[1]] ?? $m[1];
            return isset($m[3]) && $m[3] !== '' ? Words::say('CARD', 'src_listing', $m[3], $site, $m[2]) : Words::say('CARD', 'src_listing_untitled', $site, $m[2]);
        }
        return $src;
    }

    private static function historyValue(string $field, mixed $v): string
    {
        if ($v === null || $v === '') {
            return Words::CARD['h_empty'];
        }
        if ($field === 'flavour_status') {
            return $v === 'proposed' ? Words::CARD['from_file'] : Words::CARD['h_confirmed'];
        }
        return CardProposals::shown($field, $v);
    }

    /** The latest of some times (DATETIME strings; nulls left out), or null when there is none. @param list<?string> $times */
    private static function latest(array $times): ?string
    {
        $times = array_values(array_filter($times, static fn (?string $t): bool => $t !== null));
        return $times === [] ? null : max($times);
    }

    private static function s(mixed $v): ?string
    {
        return is_string($v) || is_int($v) || is_float($v) ? ($v === '' ? null : (string) $v) : null;
    }
}
