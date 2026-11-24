<?php
// Profit › Expenses (CW-1150; docs/decisions.md EX13; the approved drawing Main of 24 Nov 2026): a month, a shop and one search (the supplier
// or the invoice number) with Show; each shop's month (without VAT, how many, with VAT); the expenses grouped by shop, newest first: date,
// supplier, type, invoice number, shop, net, total, its PDF; each shop's regular costs of the month on their own line. A row opens the
// expense (its PDF, change, delete). On a phone each row is a card.
$X = \CW\Ui\Words::EXPENSES;
$gbp = static fn (int $p): string => \CW\Expenses\ExpenseMoney::gbp($p);
$monthName = static fn (string $m): string => (new \DateTimeImmutable($m . '-01'))->format('F Y');
$shortMonth = static fn (string $m): string => (new \DateTimeImmutable($m . '-01'))->format('M');
$cols = ['date', 'supplier', 'type', 'invoice_no', 'shop', 'net', 'total', 'pdf'];
// How many: the invoices and the credit notes counted apart (the Fable review of 70ab3d2, L4), and the total with VAT.
$tileCount = static function (array $t) use ($gbp): string {
    $X = \CW\Ui\Words::EXPENSES;
    $credits = (int) ($t['credits'] ?? 0);
    $invoices = (int) $t['count'] - $credits;
    if ($credits === 0) {
        return $invoices === 1 ? \CW\Ui\Words::say('EXPENSES', 'tile_one', $gbp($t['total'])) : \CW\Ui\Words::say('EXPENSES', 'tile_many', (string) $invoices, $gbp($t['total']));
    }
    $cn = $credits === 1 ? $X['tile_cn_one'] : \CW\Ui\Words::say('EXPENSES', 'tile_cn_many', (string) $credits);
    if ($invoices === 0) {
        return \CW\Ui\Words::say('EXPENSES', 'tile_alone', $cn, $gbp($t['total']));
    }
    return \CW\Ui\Words::say('EXPENSES', 'tile_parts', $invoices === 1 ? $X['tile_inv_one'] : \CW\Ui\Words::say('EXPENSES', 'tile_inv_many', (string) $invoices), $cn,
        $gbp($t['total']));
};
?>
<?= $head(\CW\Ui\Words::MENU['profit_expenses'], intro: 'profit_expenses', aside: $explain('expenses', \CW\Ui\Words::MENU['profit_expenses'], [$vatPct])) ?>
<form class="filters expenses-filters" method="get" action="/ui/profit/expenses" role="search">
<?php if ($range !== null): ?>
  <input type="hidden" name="from" value="<?= $e($range[0]) ?>">
  <input type="hidden" name="to" value="<?= $e($range[1]) ?>">
<?php else: ?>
  <label><?= $word('EXPENSES', 'month') ?>
    <select name="month">
<?php foreach ($months as $m): ?>
      <option value="<?= $e($m) ?>"<?php if ($month === $m): ?> selected<?php endif; ?>><?= $e($monthName($m)) ?></option>
<?php endforeach; ?>
      <option value="all"<?php if ($month === null): ?> selected<?php endif; ?>><?= $word('EXPENSES', 'all_months') ?></option>
    </select>
  </label>
<?php endif; ?>
  <label><?= $word('EXPENSES', 'shop') ?>
    <select name="shop">
      <option value=""><?= $word('EXPENSES', 'all_shops') ?></option>
<?php foreach ($shops as $s): ?>
      <option value="<?= $e((string) $s['id']) ?>"<?php if ($shopId === $s['id']): ?> selected<?php endif; ?>><?= $e($s['name']) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label><?= $word('EXPENSES', 'search_label') ?>
    <input type="search" name="q" value="<?= $e($q) ?>" placeholder="<?= $word('EXPENSES', 'search') ?>" maxlength="64">
  </label>
  <label class="choice"><input type="checkbox" name="deleted" value="1"<?php if ($deleted): ?> checked<?php endif; ?>> <?= $word('EXPENSES', 'show_deleted') ?></label>
  <button type="submit" class="btn"><?= $word('EXPENSES', 'show') ?></button>
</form>
<p class="muted"><a href="/ui/profit/expenses/suppliers"><?= $word('EXPENSES', 'suppliers') ?></a> · <a href="/ui/profit/regular"><?= $word('EXPENSES', 'regular_link') ?></a> · <a href="/ui/reference/expense-types"><?= $word('EXPENSES', 'types_link') ?></a></p>
<?php if ($cut !== null): ?>
<p class="note" role="status"><?= $e($cut) ?></p>
<?php endif; ?>
<?php if ($range !== null): ?>
<p class="note" role="status"><?= $say('EXPENSES', 'range_note', \CW\Ui\Html::day($range[0]), \CW\Ui\Html::day($range[1])) ?> <a href="<?= $u('/ui/profit/expenses', ['month' => substr($range[0], 0, 7), 'shop' => $shopId]) ?>"><?= $word('EXPENSES', 'by_month') ?></a></p>
<?php endif; ?>
<?php if ($tiles !== []): ?>
<section class="doc-progress rp-figs rp-keep expense-figs" aria-label="<?= $word('EXPENSES', 'tiles') ?>">
<?php foreach ($tiles as $t): ?>
  <div class="doc-step expense-tile"><p class="doc-step-label"><?= $say('EXPENSES', 'tile', $t['shop'], $range !== null ? \CW\Ui\Html::day($range[0]) . ' to ' . \CW\Ui\Html::day($range[1]) : $monthName((string) $month)) ?></p><p class="doc-step-big"><?= $e($gbp($t['net'])) ?></p><p class="step-sub"><?= $e($tileCount($t)) ?></p></div>
<?php endforeach; ?>
</section>
<p class="muted"><?= $word('EXPENSES', 'tile_note') ?></p>
<?php endif; ?>
<?php if ($more): ?>
<p class="note"><?= $say('EXPENSES', 'list_limit', $limit) ?></p>
<?php endif; ?>
<?php if ($groups === []): ?>
<?= $empty($X['none'], $canEdit ? $X['none_text'] : '', $canEdit ? '/ui/profit/expenses/new' : null, $canEdit ? \CW\Ui\Words::NEW['profit_expenses'] : null) ?>
<?php else: ?>
<div class="table-wrap">
<table class="stack rp-table expenses-list">
  <thead>
    <tr>
<?php foreach ($cols as $c): ?>
      <th scope="col"<?php if (in_array($c, ['net', 'total'], true)): ?> class="num"<?php endif; ?>><?= $word('EXPENSES', 'col_' . $c) ?></th>
<?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
<?php foreach ($groups as $g): ?>
    <tr class="rp-group"><th scope="rowgroup" colspan="8"><?= $e($g['shop']) ?></th></tr>
<?php foreach ($g['rows'] as $r): ?>
    <tr<?php if ($r['deleted']): ?> class="off"<?php endif; ?>>
      <td data-label="<?= $word('EXPENSES', 'col_date') ?>"><a href="<?= $u('/ui/profit/expenses/' . $r['id']) ?>"><?= $day($r['invoice_date']) ?></a><?php if ($r['net'] < 0): ?> <?= $chip('done', $X['credit_chip']) ?><?php endif; ?><?php if ($r['deleted']): ?> <?= $chip('off', $X['deleted_chip']) ?><?php endif; ?></td>
      <td data-label="<?= $word('EXPENSES', 'col_supplier') ?>"><a href="<?= $u('/ui/profit/expenses/' . $r['id']) ?>"><?= $e($r['supplier']) ?></a></td>
      <td data-label="<?= $word('EXPENSES', 'col_type') ?>"><?= $chip('info', $r['type']) ?></td>
      <td data-label="<?= $word('EXPENSES', 'col_invoice_no') ?>"><?= $e($r['invoice_no'] !== '' ? $r['invoice_no'] : '-') ?></td>
      <td data-label="<?= $word('EXPENSES', 'col_shop') ?>"><?= $e($r['shop']) ?></td>
      <td class="num" data-label="<?= $word('EXPENSES', 'col_net') ?>"><?= $e($gbp($r['net'])) ?></td>
      <td class="num" data-label="<?= $word('EXPENSES', 'col_total') ?>"><?= $e($gbp($r['total'])) ?></td>
      <td data-label="<?= $word('EXPENSES', 'col_pdf') ?>"><?php if ($r['has_file']): ?><a href="<?= $u('/ui/profit/expenses/' . $r['id'] . '/file') ?>"><?= $word('EXPENSES', 'pdf') ?></a><?php else: ?>-<?php endif; ?></td>
    </tr>
<?php endforeach; ?>
<?php if ($g['regular'] > 0 && ($month !== null || $range !== null)): ?>
    <tr class="expense-regular">
      <td data-label="<?= $word('EXPENSES', 'col_date') ?>"><?= $e($range !== null ? (new \DateTimeImmutable($range[0]))->format('j M') . ' - ' . (new \DateTimeImmutable($range[1]))->format('j M') : $shortMonth((string) $month)) ?></td>
      <td data-label="<?= $word('EXPENSES', 'col_supplier') ?>"><a href="/ui/profit/regular"><?= $word('EXPENSES', 'regular_row') ?></a></td>
      <td data-label="<?= $word('EXPENSES', 'col_type') ?>"><?= $chip('off', $X['regular_type']) ?></td>
      <td data-label="<?= $word('EXPENSES', 'col_invoice_no') ?>"><?= $word('EXPENSES', 'regular_by') ?></td>
      <td data-label="<?= $word('EXPENSES', 'col_shop') ?>"><?= $e($g['shop']) ?></td>
      <td class="num" data-label="<?= $word('EXPENSES', 'col_net') ?>"><?= $e($gbp($g['regular'])) ?></td>
      <td class="num" data-label="<?= $word('EXPENSES', 'col_total') ?>">-</td>
      <td data-label="<?= $word('EXPENSES', 'col_pdf') ?>">-</td>
    </tr>
<?php endif; ?>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<p class="muted"><?= $word('EXPENSES', 'list_note') ?></p>
