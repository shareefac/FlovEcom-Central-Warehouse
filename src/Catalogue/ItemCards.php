<?php

declare(strict_types=1);

namespace CW\Catalogue;

use CW\Audit;
use CW\Auth\Permissions;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Staff\StaffRoles;

/**
 * The item card (IM3; docs/decisions.md I100-I105): the legal and buying fields of an item, kept in item_card (0016, one row per
 * item, made on the first change) with every write in item_card_change (append-only) and audit_log.
 *
 *  - Who: staff holding `catalogue.edit` (mapping_lead, stock_controller, purchasing_manager; provisional, I109), never admin
 *    (403 admin_cannot_edit, I12), roles re-read inside the transaction. Everyone with catalogue.view reads.
 *  - Optimistic concurrency: every write names the card version it was drawn with (0 = no card yet); another version is 409
 *    card_changed and nothing is written. Nothing changed writes nothing (result `unchanged`).
 *  - Values come from a person only: typed on the item page (kind `change`), accepted from a proposal (`accept`: the value
 *    must still be one CardProposals offers, else 409 proposal_gone), or imported from a CSV file by a person (`import`,
 *    ItemCardCsv). A flavour from a file is `proposed`; a flavour TYPED (a new value) or accepted is `confirmed`; a form that
 *    re-sends the proposed flavour unchanged keeps it proposed (I114); confirming the card confirms it. `single_use` is a
 *    person's answer only: CardProposals never proposes it.
 *  - Confirm ("these fields are right"): needs ItemRules::missing() empty (422 card_incomplete) and, when the card breaks a
 *    rule, the person's explicit acknowledgement that the item will be blocked (422 card_breaches otherwise). It sets
 *    confirmed_*, first_confirmed_at the first time, and confirmed_breaches: the rules it acknowledged. Those BLOCK the item
 *    until the next confirmation (ItemRules::status, I113): a later change of a legal field clears confirmed_by/actor/at (the
 *    card shows "changed since it was confirmed") but keeps confirmed_breaches, so no edit lifts a block by itself; a rule an
 *    edit breaks is a warning until a person confirms the card with it.
 *  - Audit: item_card.change {kind, version, changes, detail}, item_card.confirm {version, breaches, first}; the history row
 *    holds the whole card after the write (the invariants IC1-IC3 compare it with item_card).
 */
final class ItemCards
{
    /** The card's fields in form order => what people read. */
    public const FIELDS = [
        'product_type' => 'product type',
        'liquid_ml' => 'liquid (ml)',
        'nicotine_mg' => 'nicotine (mg/ml)',
        'duty_liable' => 'duty-liable',
        'single_use' => 'single-use',
        'ecid' => 'ECID / GB-ID',
        'manufacturer' => 'manufacturer',
        'brand' => 'brand',
        'flavour' => 'flavour',
        'discontinued' => 'discontinued / do not reorder',
    ];
    /** Changing one of these clears the confirmation (discontinued is a buying flag, not something confirmed from the box). */
    public const CONFIRMED_FIELDS = ['product_type', 'liquid_ml', 'nicotine_mg', 'duty_liable', 'single_use', 'ecid', 'manufacturer', 'brand', 'flavour'];
    /** Every stored value the history snapshot keeps (item_card_change.card), compared nightly with item_card. */
    public const SNAPSHOT = ['product_type', 'liquid_ml', 'nicotine_mg', 'duty_liable', 'single_use', 'ecid', 'manufacturer', 'brand', 'flavour',
        'flavour_status', 'discontinued', 'confirmed_by', 'confirmed_at', 'confirmed_breaches', 'first_confirmed_at'];
    public const KINDS = ['change', 'accept', 'import', 'confirm'];
    public const ML_MAX_TENTHS = 50_000;      // 5000.0 ml
    public const MG_MAX_HUNDREDTHS = 10_000;  // 100.00 mg/ml
    public const TEXT_MAX = ['ecid' => 32, 'manufacturer' => 128, 'brand' => 128, 'flavour' => 255];
    public const HISTORY_LIMIT = 50;
    /**
     * What a block stops TODAY, said wherever a block is announced (I121): the reorder list, "create draft PO" and the approval of
     * a purchase order. Receiving (IM6) and the website stock (IM10) do not ask yet: the screens must not promise they do.
     */
    public const BLOCK_EFFECT = 'it is never suggested for reorder, and a purchase order with it cannot be approved. CW does not stop receiving '
        . 'or website sales yet: take it off sale on the website by hand.';

    /**
     * Spellings people type for a product type (a CSV file, the form's value) => the type. "disposable" is not one (I104), nor
     * is a bare "pod" / "pods": in UK vape retail that is as often an EMPTY refillable pod (an accessory) as a prefilled one (I123).
     */
    private const TYPE_SPELLINGS = [
        'e liquid' => 'e_liquid', 'eliquid' => 'e_liquid', 'liquid' => 'e_liquid', 'nic salt' => 'e_liquid', 'nicotine salt' => 'e_liquid',
        'short fill' => 'shortfill', 'nic shot' => 'nic_shot', 'nicotine shot' => 'nic_shot', 'booster' => 'nic_shot',
        'prefilled pod' => 'prefilled_pod', 'pre filled pod' => 'prefilled_pod', 'prefilled pods' => 'prefilled_pod', 'pre filled pods' => 'prefilled_pod',
        'device kit' => 'device_kit', 'device' => 'device_kit', 'kit' => 'device_kit', 'pod kit' => 'device_kit', 'vape kit' => 'device_kit',
        'single use' => 'single_use', 'single use vape' => 'single_use', 'coils' => 'coil', 'tanks' => 'tank', 'accessories' => 'accessory',
    ];

    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock CW's clock (updated_at, confirmed_at) */
    public function __construct(private readonly Db $db, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    /**
     * The card of an item as stored, typed for screens and rules: decimals as strings ("10.0", "20.00"), flags as 0/1/null,
     * `version` 0 and every field null (discontinued 0) when the item has no card yet; plus confirmed_by_name, updated_by_name.
     *
     * @return array<string, mixed>
     */
    public function card(int $skuId): array
    {
        $r = $this->db->one('SELECT c.*, cb.display_name AS confirmed_by_name, ub.display_name AS updated_by_name FROM item_card c '
            . 'LEFT JOIN staff_user cb ON cb.id = c.confirmed_by LEFT JOIN staff_user ub ON ub.id = c.updated_by WHERE c.sku_id = ?', [$skuId]);
        return $r === null ? self::blankCard($skuId) : self::fromRow($r);
    }

    /** @return array<string, mixed> the card of an item that has none */
    public static function blankCard(int $skuId): array
    {
        $c = ['sku_id' => $skuId, 'version' => 0];
        foreach (self::SNAPSHOT as $k) {
            $c[$k] = match ($k) {
                'discontinued' => 0,
                'confirmed_breaches' => [],
                default => null,
            };
        }
        return $c + ['confirmed_actor' => null, 'confirmed_breaches' => [], 'updated_by' => null, 'updated_actor' => null, 'updated_at' => null,
            'created_at' => null, 'confirmed_by_name' => null, 'updated_by_name' => null];
    }

    /**
     * Checks and tidies what a person typed or a file holds. Only the keys present are checked (a CSV file gives some columns;
     * the form gives all). Empty means "not known" (null), except `discontinued`, which is yes or no. Returns the values in
     * their stored form; every problem at once in 422 card_invalid (detail.errors: field => sentence).
     *
     * @param array<string, mixed> $input field => text (or an int / bool / null)
     * @return array<string, mixed>
     */
    public static function check(array $input): array
    {
        $out = [];
        $errors = [];
        foreach ($input as $k => $raw) {
            if (!isset(self::FIELDS[$k])) {
                throw new CwException('bad_field', "the item card has no field {$k}", 400, ['field' => $k]);
            }
            if ($raw !== null && !is_scalar($raw)) {
                $errors[$k] = 'The ' . self::FIELDS[$k] . ' is not a value.';
                continue;
            }
            $s = is_bool($raw) ? ($raw ? 'yes' : 'no') : trim((string) $raw);
            if (!mb_check_encoding($s, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $s) === 1) {
                $errors[$k] = 'The ' . self::FIELDS[$k] . ' is not plain text on one line.';
                continue;
            }
            $s = trim((string) preg_replace('/\s+/u', ' ', $s));
            try {
                $out[$k] = match ($k) {
                    'product_type' => self::type($s),
                    'liquid_ml' => self::ml($s),
                    'nicotine_mg' => self::mg($s),
                    'duty_liable', 'single_use' => self::yesNo($s, true),
                    'discontinued' => self::yesNo($s, false),
                    'ecid' => self::ecid($s),
                    default => self::text($s, self::TEXT_MAX[$k], self::FIELDS[$k]),
                };
            } catch (\UnexpectedValueException $e) {
                $errors[$k] = $e->getMessage();
            }
        }
        if ($errors !== []) {
            throw new CwException('card_invalid', 'Correct the item card: ' . implode(' ', array_values($errors)), 422, ['errors' => $errors]);
        }
        return $out;
    }

    /**
     * Saves the fields given (all of the form's, or a file row's) when anything changed: one write, one history row, one audit
     * row. $kind: change (typed), accept (a proposal: $detail names it) or import (a CSV row: $detail names the run).
     * 404 unknown_item · 409 merged_item / card_changed · 422 card_invalid · 403 as editor().
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $detail
     * @return array{result: string, version: int, changed: list<string>, unconfirmed: bool}
     */
    public function save(Caller $caller, int $skuId, int $expectedVersion, array $input, string $kind = 'change', array $detail = []): array
    {
        if (!in_array($kind, ['change', 'accept', 'import'], true)) {
            throw new \InvalidArgumentException("bad kind {$kind}");
        }
        $values = self::check($input);
        return $this->db->transaction(function (Db $db) use ($caller, $skuId, $expectedVersion, $values, $kind, $detail): array {
            self::editor($db, $caller);
            $cur = $this->lockCard($db, $skuId, $expectedVersion);
            return $this->apply($db, $caller, $cur, $values, $kind, $detail);
        });
    }

    /**
     * Accepts one proposal (CardProposals) for one field: the value must still be offered (409 proposal_gone otherwise), or,
     * for the flavour, be the flavour a file proposed (its status becomes confirmed). Saved as kind `accept` with the sources.
     *
     * @return array{result: string, version: int, changed: list<string>, unconfirmed: bool}
     */
    public function accept(Caller $caller, int $skuId, int $expectedVersion, string $field, string $value): array
    {
        if (!in_array($field, CardProposals::FIELDS, true)) {
            throw new CwException('bad_field', "nothing is proposed for the field {$field}", 400, ['field' => $field]);
        }
        $clean = self::check([$field => $value])[$field];
        return $this->db->transaction(function (Db $db) use ($caller, $skuId, $expectedVersion, $field, $clean): array {
            self::editor($db, $caller);
            $cur = $this->lockCard($db, $skuId, $expectedVersion);
            $sources = null;
            if ($field === 'flavour' && $cur['flavour'] === $clean && $cur['flavour_status'] === 'proposed') {
                $sources = ['the imported file'];
            } else {
                foreach ((new CardProposals($db))->of($skuId, $cur)['fields'][$field] ?? [] as $p) {
                    if ($p['value'] === $clean) {
                        $sources = $p['sources'];
                    }
                }
            }
            if ($sources === null) {
                throw new CwException('proposal_gone', 'That suggestion is no longer offered for this item (the card or its listings changed): look again.', 409);
            }
            return $this->apply($db, $caller, $cur, [$field => $clean], 'accept', ['field' => $field, 'sources' => array_slice($sources, 0, 10)]);
        });
    }

    /**
     * "These fields are right": confirms the card as it is at $expectedVersion. 422 card_incomplete (detail.missing: field
     * codes), 422 card_breaches (detail.rules) unless $acknowledgeBlock, 409 card_changed, 403 as editor(). Result `confirmed`
     * (`breaches`: the rules it breaks, which block the item from now on until the next confirmation; `lifted`: the rules the
     * last confirmation blocked that it no longer breaks) or `already` (nothing written).
     *
     * @return array{result: string, version: int, breaches: list<string>, first: bool, lifted: list<string>}
     */
    public function confirm(Caller $caller, int $skuId, int $expectedVersion, bool $acknowledgeBlock = false): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $skuId, $expectedVersion, $acknowledgeBlock): array {
            $me = self::editor($db, $caller);
            $cur = $this->lockCard($db, $skuId, $expectedVersion);
            if ($cur['version'] === 0) {
                throw new CwException('card_incomplete', 'This item has no card yet: fill in the fields first.', 422,
                    ['missing' => ItemRules::missing($cur)]);
            }
            if ($cur['confirmed_at'] !== null) {
                return ['result' => 'already', 'version' => $cur['version'], 'breaches' => ItemRules::breaches($cur), 'first' => false, 'lifted' => []];
            }
            $missing = ItemRules::missing($cur);
            if ($missing !== []) {
                throw new CwException('card_incomplete', 'Before the card can be confirmed, fill in: '
                    . implode(', ', array_map(static fn (string $f): string => ItemRules::neededLabel($f, $cur['product_type']), $missing)) . '.', 422,
                    ['missing' => $missing]);
            }
            $breaches = ItemRules::breaches($cur);
            if ($breaches !== [] && !$acknowledgeBlock) {
                throw new CwException('card_breaches', 'These fields break the law (' . ItemRules::labels($breaches) . '). Confirming them BLOCKS the item: '
                    . self::BLOCK_EFFECT . ' Correct the fields, or tick that you confirm the item is blocked.', 422, ['rules' => $breaches]);
            }
            $lifted = array_values(array_diff($cur['confirmed_breaches'], $breaches));
            $now = $this->now();
            $first = $cur['first_confirmed_at'] === null;
            $next = $cur['version'] + 1;
            $db->exec('UPDATE item_card SET version = ?, confirmed_by = ?, confirmed_actor = ?, confirmed_at = ?, confirmed_breaches = ?, '
                . "first_confirmed_at = COALESCE(first_confirmed_at, ?), flavour_status = IF(flavour IS NULL, NULL, 'confirmed'), updated_by = ?, "
                . 'updated_actor = ?, updated_at = ? WHERE sku_id = ?',
                [$next, $me['id'], $caller->actor, $now, $breaches === [] ? null : json_encode($breaches, JSON_THROW_ON_ERROR), $now, $me['id'], $caller->actor,
                    $now, $skuId]);
            $after = $this->rowFor($db, $skuId);
            $this->writeHistory($db, $caller, $skuId, $next, 'confirm', null, $after, ['breaches' => $breaches, 'first' => $first]
                + ($lifted === [] ? [] : ['lifted' => $lifted]));
            Audit::write($db, $caller, 'item_card.confirm', 'item_card', (string) $skuId, null, ['version' => $next, 'breaches' => $breaches, 'first' => $first,
                'lifted' => $lifted, 'card' => self::snapshot($after)]);
            return ['result' => 'confirmed', 'version' => $next, 'breaches' => $breaches, 'first' => $first, 'lifted' => $lifted];
        });
    }

    /**
     * The card's history, newest first: version, kind, changes (field => [before, after]), detail, who (name or actor), when.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $skuId, int $limit = self::HISTORY_LIMIT): array
    {
        $out = [];
        foreach ($this->db->all('SELECT h.version, h.kind, h.changes, h.detail, h.actor, h.created_at, u.display_name FROM item_card_change h '
            . 'LEFT JOIN staff_user u ON u.id = h.staff_user_id WHERE h.sku_id = ? ORDER BY h.version DESC LIMIT ' . max(1, min(500, $limit)), [$skuId]) as $r) {
            $out[] = ['version' => (int) $r['version'], 'kind' => (string) $r['kind'], 'changes' => self::json($r['changes']), 'detail' => self::json($r['detail']),
                'who' => $r['display_name'] !== null ? (string) $r['display_name'] : (string) $r['actor'], 'at' => (string) $r['created_at']];
        }
        return $out;
    }

    /**
     * The staff check of every card write: a staff caller (403 staff_required), active (403 staff_not_allowed), not admin
     * (403 admin_cannot_edit), holding $perm (403 role_not_allowed). Returns id and roles.
     *
     * @return array{id: int, roles: list<string>}
     */
    public static function editor(Db $db, Caller $caller, string $perm = 'catalogue.edit'): array
    {
        if ($caller->staffUserId === null) {
            throw new CwException('staff_required', 'Item cards and barcodes are changed by staff on the item pages.', 403);
        }
        $roles = StaffRoles::active($db, $caller->staffUserId);
        if (in_array('admin', $roles, true)) {
            throw new CwException('admin_cannot_edit', 'Admin manages people and roles only: the catalogue team changes item cards and barcodes.', 403);
        }
        if (!Permissions::can($roles, $perm)) {
            throw new CwException('role_not_allowed', (count($roles) === 1 ? 'Your role (' : 'Your roles (') . implode(', ', $roles)
                . ') can look at item cards and barcodes but not change them.', 403);
        }
        return ['id' => $caller->staffUserId, 'roles' => $roles];
    }

    /** The whole card as the history and the invariants keep it (SNAPSHOT keys, values as stored). @param array<string, mixed> $card @return array<string, mixed> */
    public static function snapshot(array $card): array
    {
        $out = [];
        foreach (self::SNAPSHOT as $k) {
            $v = $card[$k] ?? null;
            $out[$k] = match ($k) {
                'duty_liable', 'single_use', 'confirmed_by' => $v === null ? null : (int) $v,
                'discontinued' => (int) ($v ?? 0),
                'confirmed_breaches' => ItemRules::confirmedBreaches($v),
                default => $v === null ? null : (string) $v,
            };
        }
        return $out;
    }

    /** A yes / no / '' value as people read it. */
    public static function yesNoLabel(mixed $v): string
    {
        return $v === null || $v === '' ? '' : ((int) $v === 1 ? 'yes' : 'no');
    }

    // ------------------------------------------------------------------------------------------

    /**
     * Locks the item (FOR SHARE: not merged meanwhile) and its card (FOR UPDATE), and checks the version.
     *
     * @return array<string, mixed> the current card (typed)
     */
    private function lockCard(Db $db, int $skuId, int $expectedVersion): array
    {
        $sku = $db->one('SELECT id, code, merged_into_sku_id FROM sku WHERE id = ? FOR SHARE', [$skuId])
            ?? throw new CwException('unknown_item', 'There is no such item.', 404);
        if ($sku['merged_into_sku_id'] !== null) {
            $into = (string) ($db->value('SELECT code FROM sku WHERE id = ?', [(int) $sku['merged_into_sku_id']]) ?? 'another item');
            throw new CwException('merged_item', "{$sku['code']} was merged into {$into}: change that item's card instead.", 409, ['merged_into' => (int) $sku['merged_into_sku_id']]);
        }
        $row = $db->one('SELECT * FROM item_card WHERE sku_id = ? FOR UPDATE', [$skuId]);
        $cur = $row === null ? self::blankCard($skuId) : self::fromRow($row);
        if ($cur['version'] !== $expectedVersion) {
            throw new CwException('card_changed', 'Someone changed this item card since you opened it (version ' . $cur['version'] . ' now, you saw '
                . $expectedVersion . '): look at it again.', 409, ['version' => $cur['version']]);
        }
        return $cur;
    }

    /**
     * What writing $values (checked) onto the card $cur would do: the card after it, the changes, whether the confirmation is
     * cleared. Pure: a save, an accept and an import row run it on the locked card, the CSV import's check on the card read
     * without a lock (the same answer, without a write). 422 card_invalid when the card would not hold together: a single-use
     * product type without "single-use: yes"; 0 ml ("no tank") on anything but a device / kit (I115).
     *
     *  - The flavour (I114): kind import => proposed (the same flavour again keeps its status); kind accept => confirmed; kind
     *    change => confirmed when the value is NEW (a person typed it), unchanged status when the form only sent it back.
     *  - A change of a legal field (CONFIRMED_FIELDS, or the flavour's status) clears confirmed_by/actor/at, except confirming a
     *    proposed flavour; confirmed_breaches stays (it blocks until the next confirmation, I113).
     *
     * @param array<string, mixed> $cur
     * @param array<string, mixed> $values checked values (check())
     * @return array{new: array<string, mixed>, changes: array<string, array{before: mixed, after: mixed}>, unconfirm: bool}
     */
    public static function plan(array $cur, array $values, string $kind): array
    {
        $new = $cur;
        $changes = [];
        foreach ($values as $k => $v) {
            if ($k === 'flavour') {
                $status = match (true) {
                    $v === null => null,
                    $v === $cur['flavour'] && $kind !== 'accept' => $cur['flavour_status'], // sent back unchanged: as it was
                    $kind === 'import' => 'proposed',
                    default => 'confirmed',
                };
                if ($v !== $cur['flavour']) {
                    $changes['flavour'] = ['before' => $cur['flavour'], 'after' => $v];
                }
                if ($status !== $cur['flavour_status']) {
                    $changes['flavour_status'] = ['before' => $cur['flavour_status'], 'after' => $status];
                }
                $new['flavour'] = $v;
                $new['flavour_status'] = $status;
                continue;
            }
            if ($v !== $cur[$k]) {
                $changes[$k] = ['before' => $cur[$k], 'after' => $v];
                $new[$k] = $v;
            }
        }
        if ($changes === []) {
            return ['new' => $cur, 'changes' => [], 'unconfirm' => false];
        }
        $errors = [];
        if ($new['product_type'] === 'single_use' && $new['single_use'] !== 1) {
            $errors['single_use'] = 'A single-use product type needs "single-use: yes": a person says so, it is never assumed.';
        }
        if ($new['liquid_ml'] === '0.0' && $new['product_type'] !== 'device_kit') {
            $errors['liquid_ml'] = '0 ml means "comes without a tank": only a device / kit can say so. Give the ml, or leave it empty when nobody knows yet.';
        }
        if ($errors !== []) {
            throw new CwException('card_invalid', 'Correct the item card: ' . implode(' ', $errors), 422, ['errors' => $errors]);
        }
        $unconfirm = $cur['confirmed_at'] !== null && array_intersect(array_keys($changes), [...self::CONFIRMED_FIELDS, 'flavour_status']) !== []
            && !(array_keys($changes) === ['flavour_status'] && $new['flavour_status'] === 'confirmed');
        if ($unconfirm) {
            foreach (['confirmed_by', 'confirmed_actor', 'confirmed_at'] as $k) {
                $new[$k] = null;
            }
        }
        return ['new' => $new, 'changes' => $changes, 'unconfirm' => $unconfirm];
    }

    /**
     * Writes $values onto the locked card $cur (plan()): one row, one history row, one audit row; nothing when nothing changed.
     *
     * @param array<string, mixed> $cur
     * @param array<string, mixed> $values checked values
     * @param array<string, mixed> $detail
     * @return array{result: string, version: int, changed: list<string>, unconfirmed: bool}
     */
    private function apply(Db $db, Caller $caller, array $cur, array $values, string $kind, array $detail): array
    {
        ['new' => $new, 'changes' => $changes, 'unconfirm' => $unconfirm] = self::plan($cur, $values, $kind);
        if ($changes === []) {
            return ['result' => 'unchanged', 'version' => $cur['version'], 'changed' => [], 'unconfirmed' => false];
        }
        $next = $cur['version'] + 1;
        $now = $this->now();
        $me = (int) $caller->staffUserId;
        $cols = ['product_type', 'liquid_ml', 'nicotine_mg', 'duty_liable', 'single_use', 'ecid', 'manufacturer', 'brand', 'flavour', 'flavour_status', 'discontinued'];
        $params = array_map(static fn (string $c): mixed => $new[$c], $cols);
        if ($cur['version'] === 0) {
            try {
                $db->exec('INSERT INTO item_card (sku_id, version, ' . implode(', ', $cols) . ', updated_by, updated_actor, updated_at, created_at) VALUES (?, ?, '
                    . implode(', ', array_fill(0, count($cols), '?')) . ', ?, ?, ?, ?)', [$cur['sku_id'], $next, ...$params, $me, $caller->actor, $now, $now]);
            } catch (\PDOException $e) {
                if (Db::driverCode($e) !== 1062) {
                    throw $e;
                }
                // Two first saves of one item at the same moment (no row to lock yet): the other one won.
                throw new CwException('card_changed', 'Someone saved this item card a moment ago: look at it again.', 409, ['version' => 1]);
            }
        } else {
            $db->exec('UPDATE item_card SET version = ?, ' . implode(', ', array_map(static fn (string $c): string => "{$c} = ?", $cols))
                . ($unconfirm ? ', confirmed_by = NULL, confirmed_actor = NULL, confirmed_at = NULL' : '')
                . ', updated_by = ?, updated_actor = ?, updated_at = ? WHERE sku_id = ?', [$next, ...$params, $me, $caller->actor, $now, $cur['sku_id']]);
        }
        $after = $this->rowFor($db, (int) $cur['sku_id']);
        $this->writeHistory($db, $caller, (int) $cur['sku_id'], $next, $kind, $changes, $after, $detail);
        Audit::write($db, $caller, 'item_card.change', 'item_card', (string) $cur['sku_id'], null, ['kind' => $kind, 'version' => $next, 'changes' => $changes,
            'unconfirmed' => $unconfirm] + ($detail === [] ? [] : ['detail' => $detail]));
        return ['result' => 'saved', 'version' => $next, 'changed' => array_keys($changes), 'unconfirmed' => $unconfirm];
    }

    /** @return array<string, mixed> the card row just written, typed */
    private function rowFor(Db $db, int $skuId): array
    {
        return self::fromRow($db->one('SELECT * FROM item_card WHERE sku_id = ?', [$skuId]) ?? throw new \LogicException('the card was just written'));
    }

    /**
     * @param array<string, array{before: mixed, after: mixed}>|null $changes
     * @param array<string, mixed> $after
     * @param array<string, mixed> $detail
     */
    private function writeHistory(Db $db, Caller $caller, int $skuId, int $version, string $kind, ?array $changes, array $after, array $detail): void
    {
        $db->exec('INSERT INTO item_card_change (sku_id, version, kind, changes, card, detail, staff_user_id, actor) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            $skuId, $version, $kind, $changes === null ? null : json_encode($changes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            json_encode(self::snapshot($after), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $detail === [] ? null : json_encode($detail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $caller->staffUserId, $caller->actor,
        ]);
    }

    /** @param array<string, mixed> $r an item_card row @return array<string, mixed> */
    public static function fromRow(array $r): array
    {
        $breaches = is_string($r['confirmed_breaches'] ?? null) ? json_decode((string) $r['confirmed_breaches'], true) : null;
        return [
            'sku_id' => (int) $r['sku_id'], 'version' => (int) $r['version'],
            'product_type' => $r['product_type'] === null ? null : (string) $r['product_type'],
            'liquid_ml' => $r['liquid_ml'] === null ? null : (string) $r['liquid_ml'],
            'nicotine_mg' => $r['nicotine_mg'] === null ? null : (string) $r['nicotine_mg'],
            'duty_liable' => $r['duty_liable'] === null ? null : (int) $r['duty_liable'],
            'single_use' => $r['single_use'] === null ? null : (int) $r['single_use'],
            'ecid' => $r['ecid'] === null ? null : (string) $r['ecid'],
            'manufacturer' => $r['manufacturer'] === null ? null : (string) $r['manufacturer'],
            'brand' => $r['brand'] === null ? null : (string) $r['brand'],
            'flavour' => $r['flavour'] === null ? null : (string) $r['flavour'],
            'flavour_status' => $r['flavour_status'] === null ? null : (string) $r['flavour_status'],
            'discontinued' => (int) $r['discontinued'],
            'confirmed_by' => $r['confirmed_by'] === null ? null : (int) $r['confirmed_by'],
            'confirmed_actor' => $r['confirmed_actor'] === null ? null : (string) $r['confirmed_actor'],
            'confirmed_at' => $r['confirmed_at'] === null ? null : (string) $r['confirmed_at'],
            'confirmed_breaches' => is_array($breaches) ? array_values(array_map('strval', $breaches)) : [],
            'first_confirmed_at' => $r['first_confirmed_at'] === null ? null : (string) $r['first_confirmed_at'],
            'updated_by' => $r['updated_by'] === null ? null : (int) $r['updated_by'],
            'updated_actor' => (string) $r['updated_actor'], 'updated_at' => (string) $r['updated_at'], 'created_at' => (string) $r['created_at'],
            'confirmed_by_name' => isset($r['confirmed_by_name']) ? (string) $r['confirmed_by_name'] : null,
            'updated_by_name' => isset($r['updated_by_name']) ? (string) $r['updated_by_name'] : null,
        ];
    }

    private function now(): string
    {
        return ($this->clock)()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    /** @return array<mixed> */
    private static function json(mixed $v): array
    {
        $d = is_string($v) ? json_decode($v, true) : null;
        return is_array($d) ? $d : [];
    }

    // ----- field parsers (\UnexpectedValueException carries the sentence for people) -----

    private static function type(string $s): ?string
    {
        if ($s === '') {
            return null;
        }
        $k = trim((string) preg_replace('/[\s_\-\/]+/u', ' ', mb_strtolower($s)));
        foreach (ItemRules::TYPES as $code => $label) {
            if ($k === str_replace('_', ' ', $code) || $k === trim((string) preg_replace('/[\s_\-\/]+/u', ' ', $label))) {
                return $code;
            }
        }
        if (isset(self::TYPE_SPELLINGS[$k])) {
            return self::TYPE_SPELLINGS[$k];
        }
        throw new \UnexpectedValueException('The product type must be one of: ' . implode(', ', ItemRules::TYPES) . ' ("disposable" is not one: say '
            . 'single-use vape or device / kit).');
    }

    /** "10", "10.0", "2.5", "10ml", "2,5 ml" -> "10.0" / "2.5"; to 0.1 ml, at most 5000; "0" = no tank (a device / kit only: plan()). */
    private static function ml(string $s): ?string
    {
        if ($s === '') {
            return null;
        }
        $t = (string) preg_replace('/\s*ml$/iu', '', $s);
        if (!str_contains($t, '.') && substr_count($t, ',') === 1) {
            $t = str_replace(',', '.', $t);
        }
        if (preg_match('/^([0-9]{1,5})(?:\.([0-9]*))?$/D', $t, $m) !== 1) {
            throw new \UnexpectedValueException('The liquid is a number of ml, for example 10 or 2.5.');
        }
        $frac = rtrim($m[2] ?? '', '0');
        if (strlen($frac) > 1) {
            throw new \UnexpectedValueException('The liquid is given to 0.1 ml (for example 2.5, not 2.55).');
        }
        $tenths = (int) $m[1] * 10 + (int) ($frac === '' ? '0' : $frac);
        if ($tenths > self::ML_MAX_TENTHS) {
            throw new \UnexpectedValueException('The liquid is at most 5000 ml.');
        }
        return intdiv($tenths, 10) . '.' . ($tenths % 10);
    }

    /** "20", "20mg", "20 mg/ml", "1.7%" (= 17 mg/ml), "0" -> "20.00"; to 0.01 mg/ml, from 0 to 100. */
    private static function mg(string $s): ?string
    {
        if ($s === '') {
            return null;
        }
        if (preg_match('/^([0-9]{1,3})(?:[.,]([0-9]*))?\s*%$/Du', $s, $m) === 1) {
            $frac = rtrim($m[2] ?? '', '0');
            if (strlen($frac) > 3) {
                throw new \UnexpectedValueException('A nicotine percentage has at most 3 decimals (1.7% = 17 mg/ml).');
            }
            $hundredths = (int) $m[1] * 1000 + (int) str_pad($frac, 3, '0'); // 1% = 10 mg/ml = 1000 hundredths of a mg
        } else {
            $t = (string) preg_replace('/\s*mg(\s*\/\s*ml)?$/iu', '', $s);
            if (!str_contains($t, '.') && substr_count($t, ',') === 1) {
                $t = str_replace(',', '.', $t);
            }
            if (preg_match('/^([0-9]{1,3})(?:\.([0-9]*))?$/D', $t, $m) !== 1) {
                throw new \UnexpectedValueException('The nicotine is a number of mg/ml, for example 20, 10 or 0 (or a percentage: 2%).');
            }
            $frac = rtrim($m[2] ?? '', '0');
            if (strlen($frac) > 2) {
                throw new \UnexpectedValueException('The nicotine is given to 0.01 mg/ml.');
            }
            $hundredths = (int) $m[1] * 100 + (int) str_pad($frac, 2, '0');
        }
        if ($hundredths > self::MG_MAX_HUNDREDTHS) {
            throw new \UnexpectedValueException('The nicotine is at most 100 mg/ml.');
        }
        return intdiv($hundredths, 100) . '.' . str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function yesNo(string $s, bool $nullable): ?int
    {
        $k = mb_strtolower($s);
        if ($k === '' && $nullable) {
            return null;
        }
        return match ($k) {
            'yes', 'y', 'true', '1' => 1,
            'no', 'n', 'false', '0', '' => 0,
            default => throw new \UnexpectedValueException('Answer yes or no' . ($nullable ? ' (or leave it empty when nobody knows yet)' : '') . '.'),
        };
    }

    private static function ecid(string $s): ?string
    {
        $t = strtoupper(str_replace(' ', '', $s));
        if ($t === '') {
            return null;
        }
        if (strlen($t) < 5 || strlen($t) > self::TEXT_MAX['ecid'] || preg_match('/^[A-Z0-9]+(-[A-Z0-9]+)*$/D', $t) !== 1) {
            throw new \UnexpectedValueException('The ECID / GB-ID is 5 to 32 letters and digits with single hyphens, for example 12345-16-12345.');
        }
        return $t;
    }

    private static function text(string $s, int $max, string $label): ?string
    {
        if ($s === '') {
            return null;
        }
        if (mb_strlen($s) > $max) {
            throw new \UnexpectedValueException("The {$label} is at most {$max} characters.");
        }
        return $s;
    }
}
