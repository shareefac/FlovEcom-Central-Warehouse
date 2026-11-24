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
?>
<?= $head(\CW\Ui\Words::MENU['profit_expenses'], intro: 'profit_expenses', aside: $explain('expenses', \CW\Ui\Words::MENU['profit_expenses'])) ?>
<form class="filters expenses-filters" method="get" action="/ui/profit/expenses" role="search">
  <label><?= $word('EXPENSES', 'month') ?>
    <select name="month">
<?php foreach ($months as $m): ?>
      <option value="<?= $e($m) ?>"<?php if ($month === $m): ?> selected<?php endif; ?>><?= $e($monthName($m)) ?></option>
<?php endforeach; ?>
      <option value="all"<?php if ($month === null): ?> selected<?php endif; ?>><?= $word('EXPENSES', 'all_months') ?></option>
    </select>
  </label>
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
<?php if ($tiles !== []): ?>
<section class="doc-progress rp-figs rp-keep expense-figs" aria-label="<?= $word('EXPENSES', 'tiles') ?>">
<?php foreach ($tiles as $t): ?>
  <div class="doc-step expense-tile"><p class="doc-step-label"><?= $say('EXPENSES', 'tile', $t['shop'], $monthName((string) $month)) ?></p><p class="doc-step-big"><?= $e($gbp($t['net'])) ?></p><p class="step-sub"><?php if ($t['count'] === 1): ?><?= $say('EXPENSES', 'tile_one', $gbp($t['total'])) ?><?php else: ?><?= $say('EXPENSES', 'tile_many', (string) $t['count'], $gbp($t['total'])) ?><?php endif; ?></p></div>
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
      <td data-label="<?= $word('EXPENSES', 'col_date') ?>"><a href="<?= $u('/ui/profit/expenses/' . $r['id']) ?>"><?= $day($r['invoice_date']) ?></a><?php if ($r['deleted']): ?> <?= $chip('off', $X['deleted_chip']) ?><?php endif; ?></td>
      <td data-label="<?= $word('EXPENSES', 'col_supplier') ?>"><a href="<?= $u('/ui/profit/expenses/' . $r['id']) ?>"><?= $e($r['supplier']) ?></a></td>
      <td data-label="<?= $word('EXPENSES', 'col_type') ?>"><?= $chip('info', $r['type']) ?></td>
      <td data-label="<?= $word('EXPENSES', 'col_invoice_no') ?>"><?= $e($r['invoice_no'] !== '' ? $r['invoice_no'] : '-') ?></td>
      <td data-label="<?= $word('EXPENSES', 'col_shop') ?>"><?= $e($r['shop']) ?></td>
      <td class="num" data-label="<?= $word('EXPENSES', 'col_net') ?>"><?= $e($gbp($r['net'])) ?></td>
      <td class="num" data-label="<?= $word('EXPENSES', 'col_total') ?>"><?= $e($gbp($r['total'])) ?></td>
      <td data-label="<?= $word('EXPENSES', 'col_pdf') ?>"><?php if ($r['has_file']): ?><a href="<?= $u('/ui/profit/expenses/' . $r['id'] . '/file') ?>"><?= $word('EXPENSES', 'pdf') ?></a><?php else: ?>-<?php endif; ?></td>
    </tr>
<?php endforeach; ?>
<?php if ($g['regular'] > 0 && $month !== null): ?>
    <tr class="expense-regular">
      <td data-label="<?= $word('EXPENSES', 'col_date') ?>"><?= $e($shortMonth($month)) ?></td>
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
