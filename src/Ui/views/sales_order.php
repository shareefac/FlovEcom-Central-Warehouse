<?php
// One order of a store (pack D, docs/decisions.md PD4): read only. Its facts, its sold units (a page of them) with the warehouse item and
// the store variant of each, then each unit's stock changes from the ledger, each with a link to it among the item's movements.
?>
<p class="crumbs"><a href="<?= $e($back) ?>"><?= $word('MENU', 'sales_orders') ?></a></p>
<h1><?= $e($title) ?> <?= $chip($tone, $chipWord) ?></h1>
<?= $intro('sales_order') ?>

<section aria-labelledby="facts-h">
  <h2 id="facts-h" class="visually-hidden"><?= $word('SALE_ORDER', 'facts') ?></h2>
  <dl class="wide">
<?php foreach ($facts as $fact): ?>
    <dt><?= $e($fact[0]) ?></dt><dd><?= $e($fact[1]) ?></dd>
<?php endforeach; ?>
    <dt><?= $word('SALE_ORDER', 'f_kept') ?></dt><dd><a href="<?= $u($kept) ?>"><?= $word('SALE_ORDER', 'kept_link') ?></a></dd>
  </dl>
</section>

<section aria-labelledby="units-h">
  <h2 id="units-h"><?= $word('SALE_ORDER', 'units') ?></h2>
<?php if ($units === []): ?>
  <p class="muted"><?= $word('SALE_ORDER', 'no_units') ?></p>
<?php else: ?>
  <p class="muted"><?= $word('SALE_ORDER', 'units_text') ?></p>
  <div class="table-wrap">
  <table class="stack list board row-strips sales-units">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('SALE_ORDER', 'u_item') ?></th>
        <th scope="col"><?= $word('SALE_ORDER', 'u_unit') ?></th>
        <th scope="col"><?= $word('SALE_ORDER', 'u_variant') ?></th>
        <th scope="col"><?= $word('SALE_ORDER', 'u_warehouse') ?></th>
        <th scope="col" class="c-status"><?= $word('SALE_ORDER', 'u_state') ?></th>
        <th scope="col"><?= $word('SALE_ORDER', 'u_sent') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($units as $l): ?>
      <tr class="<?= $e($l['tone']) ?>">
        <th scope="row" class="c-head c-item"><?php if ($l['sku_id'] !== null): ?><a class="o-name" href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['name']) ?></a><?php else: ?><?= $e($l['name']) ?><?php endif; ?><span class="o-sub"><?= $e($l['sub']) ?></span></th>
        <td data-label="<?= $word('SALE_ORDER', 'u_unit') ?>"><?= $e($l['unit']) ?></td>
        <td data-label="<?= $word('SALE_ORDER', 'u_variant') ?>"><?= $e($l['variant']) ?></td>
        <td data-label="<?= $word('SALE_ORDER', 'u_warehouse') ?>"><?= $e($l['warehouse']) ?></td>
        <td class="c-status"><?= $chip($l['tone'], $l['state']) ?></td>
        <td data-label="<?= $word('SALE_ORDER', 'u_sent') ?>"><?= $when($l['sent']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
<?php if ($more_units !== null || $first_units !== null): ?>
  <p class="pager"><?php if ($first_units !== null): ?><a href="<?= $e($first_units) ?>"><?= $word('SALE_ORDER', 'first_units') ?></a><?php endif; ?><?php if ($more_units !== null): ?><a href="<?= $e($more_units) ?>"><?= $word('SALE_ORDER', 'more_units') ?></a><?php endif; ?></p>
<?php endif; ?>
</section>

<?php if ($history !== []): ?>
<section aria-labelledby="steps-h">
  <h2 id="steps-h"><?= $word('SALE_ORDER', 'steps') ?></h2>
  <p class="muted"><?= $word('SALE_ORDER', 'steps_text') ?></p>
<?php if ($capped !== null): ?>
  <p class="note"><?= $e($capped) ?></p>
<?php endif; ?>
<?php foreach ($history as $h): ?>
  <div class="grp-block">
    <h3 class="grp-title info"><?= $e($h['title']) ?> <span class="grp-count"><?= $e($h['sub']) ?></span></h3>
<?php if ($h['empty'] !== null): ?>
    <p class="muted"><?= $e($h['empty']) ?></p>
<?php else: ?>
    <div class="table-wrap">
    <table class="stack list ledger sales-steps">
      <thead>
        <tr>
          <th scope="col"><?= $word('SALE_ORDER', 's_what') ?></th>
          <th scope="col"><?= $word('SALE_ORDER', 's_when') ?></th>
          <th scope="col"><?= $word('SALE_ORDER', 's_where') ?></th>
          <th scope="col"><?= $word('SALE_ORDER', 's_figures') ?></th>
          <th scope="col"><?= $word('SALE_ORDER', 's_who') ?></th>
          <th scope="col"><?= $word('SALE_ORDER', 's_open') ?></th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($h['steps'] as $s): ?>
        <tr>
          <th scope="row" class="c-head"><?= $e($s['what']) ?></th>
          <td data-label="<?= $word('SALE_ORDER', 's_when') ?>"><?= $when($s['at']) ?></td>
          <td data-label="<?= $word('SALE_ORDER', 's_where') ?>"><?= $e($s['where']) ?></td>
          <td data-label="<?= $word('SALE_ORDER', 's_figures') ?>"><?= $e($s['figures']) ?></td>
          <td data-label="<?= $word('SALE_ORDER', 's_who') ?>"><?= $e($s['who']) ?></td>
          <td data-label="<?= $word('SALE_ORDER', 's_open') ?>"><a href="<?= $e($s['href']) ?>"><?= $word('SALE_ORDER', 's_open_link') ?></a></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
<?php endif; ?>
  </div>
<?php endforeach; ?>
</section>
<?php endif; ?>
