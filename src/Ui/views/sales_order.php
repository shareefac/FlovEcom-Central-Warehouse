<?php
// One order of a store (pack D, docs/decisions.md PD4), a record page (the approved design of 29 Oct 2026, pattern 2, PO_View;
// docs/decisions.md W10): read only. Its reference with the status block, the stock kept for it at the head's right (sales_order_bar); the
// strip of what became of its sold units; the paper card: the store beside the facts, then its sold units (a page of them) as the card's
// lines, each with its warehouse item, store variant, warehouse and a filled status cell; then the history: each unit's stock changes from
// the ledger, a group a unit, each change with a link to it among the item's movements.
$bar = $partial('sales_order_bar', ['kept' => $kept]);
?>
<p class="crumbs"><a href="<?= $e($back) ?>"><?= $word('MENU', 'sales_orders') ?></a></p>
<div class="doc-head">
<?= $head($title, intro: 'sales_order', chips: $chip($tone, $chipWord), side: $bar) ?>
</div>

<?php if ($strip !== []): ?>
<section class="doc-progress rp-figs" aria-label="<?= $word('SALE_ORDER', 'figures') ?>">
<?php foreach ($strip as $s): ?>
  <div class="doc-step"><p class="doc-step-label"><?= $e($s['label']) ?></p><p class="doc-step-big"><?= $n($s['n']) ?></p></div>
<?php endforeach; ?>
</section>
<?php endif; ?>

<article class="doc-view" aria-labelledby="units-h">
<?= $partial('doc_party', ['party' => $party, 'facts' => $docFacts]) ?>
  <h2 id="units-h" class="doc-label"><?= $word('SALE_ORDER', 'units') ?></h2>
<?php if ($units === []): ?>
  <p class="muted"><?= $word('SALE_ORDER', 'no_units') ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack list board doc-lines sales-units">
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
      <tr>
        <th scope="row" class="c-head c-item"><?php if ($l['sku_id'] !== null): ?><a class="o-name" href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['name']) ?></a><?php else: ?><?= $e($l['name']) ?><?php endif; ?><span class="o-sub"><?= $e($l['sub']) ?></span></th>
        <td data-label="<?= $word('SALE_ORDER', 'u_unit') ?>"><?= $e($l['unit']) ?></td>
        <td data-label="<?= $word('SALE_ORDER', 'u_variant') ?>"><?= $e($l['variant']) ?></td>
        <td data-label="<?= $word('SALE_ORDER', 'u_warehouse') ?>"><?= $e($l['warehouse']) ?></td>
        <td class="c-status doc-st <?= $e($l['tone']) ?>"><?= $e($l['state']) ?></td>
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
</article>

<?php if ($history !== []): ?>
<section class="doc-related" aria-labelledby="steps-h">
  <div class="board-head"><h2 id="steps-h" class="board-sub"><?= $word('SALE_ORDER', 'steps') ?></h2></div>
<?php if ($capped !== null): ?>
  <p class="note"><?= $e($capped) ?></p>
<?php endif; ?>
<?php foreach ($history as $i => $h): ?>
  <div class="grp-block doc-group info">
    <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="u-<?= $e($i) ?>"><?= $e($h['title']) ?></button> <span class="grp-count"><?= $e($h['sub']) ?></span></h3>
<?php if ($h['empty'] !== null): ?>
    <div class="table-wrap doc-board" id="u-<?= $e($i) ?>"><p class="muted"><?= $e($h['empty']) ?></p></div>
<?php else: ?>
    <div class="table-wrap doc-board" id="u-<?= $e($i) ?>">
    <table class="stack list board doc-list ledger sales-steps">
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
