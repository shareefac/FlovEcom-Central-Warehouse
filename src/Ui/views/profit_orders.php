<?php
// Profit › Profit by order (docs/decisions.md PR27): the paid orders of the days and shop chosen, newest first or the lowest margin first,
// or the orders with the number typed (any day); each opens its page. A re-send's cost is on its first order (shown with it). Paged.
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
      <td class="num" data-label="<?= $word('PROFIT', 'cost') ?>"><?= $money($r['cost'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'profit') ?>"><?= $money($r['profit'] / 100) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'margin') ?>"><?= $e(\CW\Profit\ProfitViews::pct($r['margin_bp'])) ?></td>
      <td class="num" data-label="<?= $word('PROFIT', 'delivery') ?>"><?= $money($r['delivery'] / 100) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="pager"><?= $say('PROFIT', 'page', $page_no, $pages) ?><?php if ($prev_link !== null): ?> <a href="<?= $e($prev_link) ?>"><?= $word('PROFIT', 'newer') ?></a><?php endif; ?><?php if ($next_link !== null): ?> <a href="<?= $e($next_link) ?>"><?= $word('PROFIT', 'older') ?></a><?php endif; ?></p>
<?php endif; ?>
