<?php
// Profit › Profit by order (docs/decisions.md PR27): the paid orders of the days and shop chosen, newest first or the lowest margin first,
// or the orders with the number typed (any day); each opens its page. Goods, cost and profit are each order's own, so the rows add up to the
// day summaries (a re-send's cost is on the re-send's row); a first order also shows its re-sends' cost and its profit with them, and its
// margin is the one with them (PR10, PR102). A profit not known (a unit with no price) says so, never a number (PR102). Paged.
?>
<?= $head(\CW\Ui\Words::MENU['profit_orders'], intro: 'profit_orders', aside: $explain('profit', \CW\Ui\Words::PROFIT['profit'])) ?>
<form class="toolbar" method="get" aria-label="<?= $word('PROFIT', 'find') ?>">
  <input type="hidden" name="period" value="<?= $e($period) ?>">
<?php if ($period === 'choose'): ?>
  <input type="hidden" name="from" value="<?= $e($from) ?>">
  <input type="hidden" name="to" value="<?= $e($to) ?>">
<?php endif; ?>
<?php if ($storeId !== null): ?>
  <input type="hidden" name="store" value="<?= $e($storeId) ?>">
<?php endif; ?>
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="pf-q"><?= $word('PROFIT', 'search') ?></label><input id="pf-q" type="search" name="q" value="<?= $e($q) ?>" maxlength="64" placeholder="<?= $word('PROFIT', 'search') ?>" enterkeyhint="search"></div>
  <button type="submit" class="btn sm"><?= $word('PROFIT', 'find') ?></button>
</form>
<?= $partial('profit_options', ['period' => $period, 'periods' => $periods, 'from' => $from, 'to' => $to, 'stores' => $stores, 'storeId' => $storeId, 'warn' => $warn,
    'extra' => ['low' => [\CW\Ui\Words::PROFIT['sort'], ['' => \CW\Ui\Words::PROFIT['paid'], '1' => \CW\Ui\Words::PROFIT['lowest_first']], $low ? '1' : '']]]) ?>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::PROFIT['no_orders'], \CW\Ui\Words::PROFIT['no_orders_text']) ?>
<?php else: ?>
<p class="muted"><?php if ($total !== null): ?><?= $say('PROFIT', 'orders_found', $total) ?> <?php endif; ?><?php if ($low): ?><?= $word('PROFIT', 'no_margin') ?><?php endif; ?></p>
<div class="table-wrap">
<table class="stack rp-table profit-orders">
  <thead>
    <tr>
      <th scope="col"><?= $word('PROFIT', 'order') ?></th>
      <th scope="col"><?= $word('PROFIT', 'store') ?></th>
      <th scope="col"><?= $word('PROFIT', 'paid') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'units') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'goods') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'cost') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'profit') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'margin') ?></th>
      <th scope="col" class="num"><?= $word('PROFIT', 'delivery') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row"><a href="<?= $e($r['href']) ?>"><?= $e($r['no'] !== '' ? $r['no'] : $r['ref']) ?></a><?php if ($r['parent'] !== null): ?> <span class="muted"><?= $say('PROFIT', 'resend_of', $r['parent']) ?></span><?php endif; ?><?php if ($r['no_cost'] > 0): ?> <span class="muted"><?= $word('PROFIT', 'no_cost_short') ?></span><?php endif; ?></th>
      <td data-label="<?= $word('PROFIT', 'store') ?>"><?= $e($r['store']) ?></td>
      <td data-label="<?= $word('PROFIT', 'paid') ?>"><?= $when($r['paid_at']) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'units') ?>"><?= $n($r['units']) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'goods') ?>"><?= $money($r['sale'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'cost') ?>"><?= $money($r['cost'] / 100) ?><?php if ($r['resends'] > 0): ?><br><span class="muted"><?= $word('PROFIT', 'resend_cost') ?>: <?= $money($r['resend_cost'] / 100) ?></span><?php endif; ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'profit') ?>"><?php if ($r['unpaid'] ?? false): ?><?= $word('PROFIT', 'unpaid_profit') ?><?php elseif ($r['profit'] === null): ?><?= $word('PROFIT', 'unknown') ?><?php else: ?><?= $money($r['profit'] / 100) ?><?php endif; ?><?php if ($r['resends'] > 0): ?><br><span class="muted"><?= $word('PROFIT', 'with_resends') ?>: <?php if ($r['with_resends'] === null): ?><?= $word('PROFIT', 'unknown') ?><?php else: ?><?= $money($r['with_resends'] / 100) ?><?php endif; ?></span><?php endif; ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'margin') ?>"><?= $e(\CW\Profit\ProfitViews::pct($r['margin_bp'])) ?><?php if ($r['incomplete']): ?> <span class="muted" title="<?= $word('PROFIT', 'incomplete_note') ?>"><?= $word('PROFIT', 'incomplete') ?></span><?php endif; ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'delivery') ?>"><?= $money($r['delivery'] / 100) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($first_link !== null || $next_link !== null): ?>
<p class="pager"><?php if ($first_link !== null): ?><a href="<?= $e($first_link) ?>"><?= $word('PROFIT', 'first_page') ?></a><?php endif; ?><?php if ($next_link !== null): ?> <a href="<?= $e($next_link) ?>"><?= $word('PROFIT', 'next_page') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php endif; ?>
