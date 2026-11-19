<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\CwException;
use CW\Db;
use CW\Live\LiveChanges;
use CW\PurchaseOrders\PoMath;
use CW\Ui\Controller\PurchaseOrdersController;

/**
 * The words of a draft order saved box by box (the live drafts plan, step 4, 4.5 and section 5; docs/decisions.md LS7, LS9, LS12), made on the
 * server so the page's script shows only what the page gives it (dev.md rule 17): a refusal on its line with its choices (live_conflict), a
 * change said to the other open pages (po_live's events), the History card's grouped lines and their fold, Confirm's refusal.
 */
final class LiveSaveWords
{
    /** The fields a line's refusal names, in the page's words (a box the person sent: packs, price, items per pack, the product). */
    private const SENT = ['packs', 'pack_price', 'units_per_pack', 'product'];

    /** "8 × box of 10" (one item a pack: "50"). */
    public static function packs(int $packs, string $unit, int $upp): string
    {
        return $upp === 1 && $unit === 'each' ? Html::int($packs) : Words::say('LIVE_SAVE', 'packs', Html::int($packs), PurchaseOrdersController::pack($unit, $upp));
    }

    /**
     * A line's values in words, only those named ($only: packs, pack_price, units_per_pack, product; null: packs and price): "8 × box of 10 at
     * £12.50", "12 items per pack", the product's name.
     *
     * @param array<string, mixed> $v a line as PurchaseOrders names it (packs, pack_price, units_per_pack, purchase_unit, name)
     * @param list<string>|null $only
     */
    public static function values(array $v, ?array $only = null): string
    {
        $only ??= ['packs', 'pack_price'];
        $parts = [];
        if (in_array('product', $only, true) && ($v['name'] ?? '') !== '') {
            $parts[] = (string) $v['name'];
        }
        $packs = in_array('packs', $only, true) && isset($v['packs'])
            ? self::packs((int) $v['packs'], (string) ($v['purchase_unit'] ?? 'each'), (int) ($v['units_per_pack'] ?? 1)) : null;
        $price = in_array('pack_price', $only, true) && isset($v['pack_price']) ? '£' . PoMath::price((string) $v['pack_price']) : null;
        if ($packs !== null && $price !== null) {
            $parts[] = Words::say('LIVE_SAVE', 'at_price', $packs, $price);
        } elseif ($packs !== null || $price !== null) {
            $parts[] = (string) ($packs ?? $price);
        }
        if (in_array('units_per_pack', $only, true) && isset($v['units_per_pack'])) {
            $parts[] = Words::say('LIVE_SAVE', 'per_pack', Html::int((int) $v['units_per_pack']));
        }
        return implode(', ', $parts);
    }

    /**
     * A refusal of a box saved by itself, for the view live_conflict (on its line, or its head box): the code, the line or box, the words and
     * the choices in the plan's order (each a value of data-live-choice, its words, and whether it is the safe one: Esc, "keep theirs"). $sent: what the page sent (the boxes, in
     * PurchaseOrders' names: packs, pack_price, units_per_pack, supplier_item_id / sku_id; the head's value). Null for a refusal the page
     * cannot show on a line (it says the error's words where it can).
     *
     * @param array<string, mixed> $sent
     * @return array<string, mixed>
     */
    public static function refusal(CwException $e, array $sent, ?int $uid = null, ?string $field = null): array
    {
        $d = $e->detail;
        $who = static fn (): string => is_string($d['by'] ?? null) && $d['by'] !== '' ? $d['by'] : Words::LIVE_SAVE['someone'];
        $at = is_string($d['at'] ?? null) ? Html::clock($d['at']) : '';
        $out = ['code' => $e->errorCode, 'uid' => is_int($d['uid'] ?? null) ? $d['uid'] : $uid, 'rev' => is_int($d['rev'] ?? null) ? $d['rev'] : null, 'field' => $field,
            'at' => $at, 'words' => [], 'choices' => [], 'theirs' => null, 'status' => null, 'number' => null, 'by' => null];
        switch ($e->errorCode) {
            case 'line_conflict':
                $theirs = is_array($d['theirs'] ?? null) ? $d['theirs'] : [];
                if (($sent['remove'] ?? false) === true) {
                    $out['words'] = [Words::say('LIVE_SAVE', 'remove_conflict', $who(), self::values($theirs))];
                    $out['choices'] = [['remove', Words::LIVE_SAVE['remove_anyway'], false], ['keep', Words::LIVE_SAVE['keep_it'], true]];
                    break;
                }
                $mine = $theirs;
                $named = [];
                foreach (['packs', 'pack_price', 'units_per_pack'] as $k) {
                    if (array_key_exists($k, $sent)) {
                        $mine[$k] = $sent[$k];
                        if ((string) ($theirs[$k] ?? '') !== (string) $sent[$k] && !($k === 'pack_price' && self::sameMoney($theirs[$k] ?? null, $sent[$k]))) {
                            $named[] = $k;
                        }
                    }
                }
                if (isset($sent['supplier_item_id']) || isset($sent['sku_id'])) {
                    $mine['name'] = (string) ($sent['name'] ?? '');
                    $named[] = 'product';
                }
                $named = $named === [] ? ['packs', 'pack_price'] : $named;
                $out['words'] = [Words::say('LIVE_SAVE', 'conflict_line', $who(), $at),
                    Words::say('LIVE_SAVE', 'theirs', $who(), self::values($theirs, $named)) . ' ' . Words::say('LIVE_SAVE', 'yours', self::values($mine, $named))];
                $out['choices'] = [['theirs', Words::say('LIVE_SAVE', 'keep', $who()), true], ['mine', Words::LIVE_SAVE['use_mine'], false]];
                break;
            case 'line_gone':
                $out['words'] = [Words::say('LIVE_SAVE', 'gone', $who(), (string) ($d['name'] ?? ''))];
                $out['choices'] = [['put_back', Words::LIVE_SAVE['put_back'], false], ['leave', Words::LIVE_SAVE['leave_removed'], true]];
                break;
            case 'line_exists':
                $pack = is_array($d['pack'] ?? null) ? $d['pack'] : [];
                $adding = (int) ($d['adding'] ?? 0);
                $out['words'] = [Words::say('LIVE_SAVE', 'exists', $who(), self::packs((int) ($d['packs'] ?? 0), (string) ($pack['purchase_unit'] ?? 'each'),
                    (int) ($pack['units_per_pack'] ?? 1)), Html::int($adding))];
                $out['choices'] = [['more', Words::say('LIVE_SAVE', 'add_more', Html::int($adding)), false], ['leave', Words::LIVE_SAVE['leave_it'], true]];
                break;
            case 'field_conflict':
                $theirs = is_string($d['theirs'] ?? null) ? $d['theirs'] : null;
                $mine = is_string($sent['value'] ?? null) && trim($sent['value']) !== '' ? trim($sent['value']) : null;
                $show = static fn (?string $v): string => $v === null ? Words::LIVE_SAVE['empty']
                    : (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) === 1 ? Html::day($v) : $v);
                $out['field'] = is_string($d['field'] ?? null) ? $d['field'] : $field;
                $out['theirs'] = $theirs ?? '';
                $out['words'] = [Words::say('LIVE_SAVE', 'field_conflict', $who(), $at),
                    Words::say('LIVE_SAVE', 'theirs', $who(), $show($theirs)) . ' ' . Words::say('LIVE_SAVE', 'yours', $show($mine))];
                $out['choices'] = [['theirs', Words::say('LIVE_SAVE', 'keep', $who()), true], ['mine', Words::LIVE_SAVE['use_mine'], false]];
                break;
            case 'not_draft':
                $by = is_string($d['by'] ?? null) && $d['by'] !== '' ? $d['by'] : Words::LIVE['someone'];
                $out['status'] = (string) ($d['status'] ?? '');
                $out['number'] = is_string($d['number'] ?? null) ? $d['number'] : null;
                $out['by'] = $by;
                $out['words'] = [match ($out['status']) {
                    'posted' => Words::say('LIVE', 'confirmed', $by, (string) $out['number']),
                    'awaiting_approval' => Words::say('LIVE', 'sent_ok', $by),
                    'cancelled' => Words::say('LIVE', 'cancelled', $by),
                    default => PurchaseOrdersController::plain($e),
                }];
                break;
            case 'nothing_to_restore':
                $out['words'] = [Words::LIVE_SAVE['nothing_to_restore']];
                break;
            case 'live_off':
                $out['words'] = [Words::LIVE_SAVE['live_off']];
                break;
            default:
                $out['field'] = is_string($d['field'] ?? null) ? $d['field'] : $field;
                $out['words'] = [PurchaseOrdersController::plain($e)];
        }
        return $out;
    }

    /**
     * A change in words for the other open pages ("Fazil changed line 3 (Elf Bar 600 Blue Razz)."). $lineNos: line id => its number now.
     *
     * @param array<string, mixed> $c LiveChanges::since()'s row
     * @param array<int, int> $lineNos
     */
    public static function event(array $c, array $lineNos): string
    {
        $after = $c['after'] ?? [];
        $before = $c['before'] ?? [];
        $name = (string) (($after['name'] ?? '') !== '' ? $after['name'] : ($before['name'] ?? ''));
        $no = $c['line_uid'] !== null && isset($lineNos[$c['line_uid']]) ? (string) $lineNos[$c['line_uid']] : (string) ($after['line'] ?? $before['line'] ?? '');
        return match ($c['op']) {
            'set' => Words::say('LIVE_SAVE', 'ev_set', $c['name'], $no, $name),
            'add' => ($before['packs'] ?? null) !== null ? Words::say('LIVE_SAVE', 'ev_set', $c['name'], $no, $name) : Words::say('LIVE_SAVE', 'ev_add', $c['name'], $no, $name),
            'remove' => Words::say('LIVE_SAVE', 'ev_remove', $c['name'], (string) ($before['line'] ?? ''), $name),
            'restore' => Words::say('LIVE_SAVE', 'ev_restore', $c['name'], $no, $name),
            'header' => Words::say('LIVE_SAVE', 'ev_head', $c['name'], mb_strtolower(self::fieldName((string) $c['field']))),
            'supplier' => Words::say('LIVE_SAVE', 'ev_supplier', $c['name'], (string) ($after['name'] ?? '')),
            default => Words::say('LIVE_SAVE', 'ev_replace', $c['name']),
        };
    }

    /**
     * The History card's lines of a draft's line-by-line changes (LiveChanges::group): "18 Nov 2026, 14:02 · Fazil changed 3 lines" with each
     * change in its fold, as po_cards' events (at, when, text, fold).
     *
     * @param list<array<string, mixed>> $groups LiveChanges::group()
     * @return list<array{at: string, when: string, text: string, fold: list<string>}>
     */
    public static function history(array $groups): array
    {
        $out = [];
        foreach ($groups as $g) {
            $n = (int) $g['lines'];
            $text = match (true) {
                $n === 0 => Words::say('LIVE_SAVE', 'ev_details', $g['name']),
                $n === 1 => Words::say('LIVE_SAVE', $g['head'] ? 'ev_line_details' : 'ev_line', $g['name']),
                default => Words::say('LIVE_SAVE', $g['head'] ? 'ev_lines_details' : 'ev_lines', $g['name'], Html::int($n)),
            };
            $fold = [];
            foreach ($g['rows'] as $c) {
                array_push($fold, ...self::foldOf($c));
            }
            $out[] = ['at' => (string) $g['at'], 'when' => Html::when((string) $g['at']), 'text' => $text, 'fold' => $fold];
        }
        return $out;
    }

    /**
     * Confirm refused because the draft changed after the version the page shows (LS9): who changed which line ("Fazil changed line 3 a
     * moment ago. Look, then confirm again."), from the newest line-by-line change after that version; else who saved it last.
     */
    public static function confirmChanged(Db $db, int $id, int $shown): string
    {
        $c = $db->one("SELECT c.op, c.line_uid, u.display_name FROM draft_change c LEFT JOIN staff_user u ON u.id = c.staff_user_id WHERE c.kind = 'po' AND c.record_id = ? "
            . "AND c.doc_version > ? AND c.op <> 'conflict' ORDER BY c.id DESC LIMIT 1", [$id, $shown]);
        if ($c !== null) {
            $who = (string) ($c['display_name'] ?? Words::LIVE_SAVE['someone']);
            $no = $c['line_uid'] === null || $c['op'] === 'remove' ? null : $db->value('SELECT line_no FROM document_line WHERE document_id = ? AND uid = ?', [$id, (int) $c['line_uid']]);
            return $no === null ? Words::say('LIVE_SAVE', 'confirm_changed', $who) : Words::say('LIVE_SAVE', 'confirm_changed_line', $who, (string) $no);
        }
        $last = \CW\Documents\DraftChanges::last($db, $id);
        return Words::say('LIVE_SAVE', 'confirm_changed', $last['name'] ?? Words::LIVE_SAVE['someone']);
    }

    /** A head box's name in the page's words (Expected, Their reference, Note to supplier). */
    public static function fieldName(string $field): string
    {
        return match ($field) {
            'expected_date' => Words::ORDER['expected_short'],
            'external_ref' => Words::ORDER['their_ref'],
            default => Words::ORDER['note_title'],
        };
    }

    /**
     * @param array<string, mixed> $c
     * @return list<string>
     */
    private static function foldOf(array $c): array
    {
        $b = $c['before'] ?? [];
        $a = $c['after'] ?? [];
        $name = (string) (($a['name'] ?? '') !== '' ? $a['name'] : ($b['name'] ?? ''));
        switch ($c['op']) {
            case 'set':
            case 'add':
            case 'restore':
                if ($c['op'] === 'add' && ($b['packs'] ?? null) === null) {
                    return [Words::say('LIVE_SAVE', 'fold_add', $name, self::values($a, ['packs']))];
                }
                if ($c['op'] === 'restore' && ($b['packs'] ?? null) === null) {
                    return [Words::say('LIVE_SAVE', 'fold_restore', $name)];
                }
                $out = [];
                if (($b['name'] ?? '') !== ($a['name'] ?? '') && ($b['name'] ?? '') !== '') {
                    $out[] = Words::say('LIVE_SAVE', 'fold_product', (string) $b['name'], (string) ($a['name'] ?? ''));
                }
                if (($b['packs'] ?? null) !== ($a['packs'] ?? null)) {
                    $out[] = Words::say('LIVE_SAVE', 'fold_packs', Html::int((int) ($b['packs'] ?? 0)), Html::int((int) ($a['packs'] ?? 0)), $name);
                }
                if (!self::sameMoney($b['pack_price'] ?? null, $a['pack_price'] ?? null)) {
                    $out[] = Words::say('LIVE_SAVE', 'fold_price', '£' . PoMath::price((string) ($b['pack_price'] ?? '0')), '£' . PoMath::price((string) ($a['pack_price'] ?? '0')), $name);
                }
                if (($b['units_per_pack'] ?? null) !== ($a['units_per_pack'] ?? null)) {
                    $out[] = Words::say('LIVE_SAVE', 'fold_upp', Html::int((int) ($b['units_per_pack'] ?? 1)), Html::int((int) ($a['units_per_pack'] ?? 1)), $name);
                }
                return $out;
            case 'remove':
                return [Words::say('LIVE_SAVE', 'fold_remove', $name)];
            case 'header':
                $show = static fn (mixed $v): string => $v === null || $v === '' ? Words::LIVE_SAVE['empty']
                    : (preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) $v) === 1 ? Html::day((string) $v) : (string) $v);
                return [Words::say('LIVE_SAVE', 'fold_head', self::fieldName((string) $c['field']), $show($b['value'] ?? null), $show($a['value'] ?? null))];
            case 'supplier':
                return [Words::say('LIVE_SAVE', 'fold_supplier', (string) ($b['name'] ?? ''), (string) ($a['name'] ?? ''))];
            default:
                return [Words::say('LIVE_SAVE', 'fold_replace', Html::int((int) ($a['lines'] ?? 0)))];
        }
    }

    private static function sameMoney(mixed $a, mixed $b): bool
    {
        try {
            return $a !== null && $b !== null && PoMath::e4((string) $a) === PoMath::e4(str_replace(['£', ',', ' '], '', (string) $b));
        } catch (\Throwable) {
            return false;
        }
    }

    /** The newest change a page shows (LiveChanges::newest), for the page's data-live-e. */
    public static function newest(Db $db, int $id): int
    {
        try {
            return LiveChanges::newest($db, 'po', $id);
        } catch (\PDOException) {
            return 0;
        }
    }
}
