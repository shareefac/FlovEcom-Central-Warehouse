<?php
// Purchases › Supplier invoices › From ERPNext (CW-917; docs/decisions.md EC14), a simple list in the shared look of To match and Timed: one
// Show menu (waiting, copied, cancelled in ERPNext, not copied), the switch and the last run in one line; then the ERPNext purchase invoices in
// one group over a white table card; five columns: the ERPNext invoice (its supplier's number, or the invoice a debit note returns against,
// under it), the supplier, the state as a small pill (why it waits under it), the date, the total (its copy's link under it). Look only: a
// waiting one is put right where its cause is (the supplier, the product's link), and the next run copies it. Never in Approvals.
$words = \CW\Ui\Words::ERP_COPY;
$label = ['waiting' => $words['show_waiting'], 'copied' => $words['show_copied'], 'reversed' => $words['show_reversed'], 'skipped' => $words['show_skipped']];
$tone = ['waiting' => 'needs', 'copied' => 'done', 'reversed' => 'off', 'skipped' => 'off'];
?>
<?= $head(\CW\Ui\Words::SEGMENT['from_erpnext'], intro: 'from_erpnext') ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <?= $showStart() ?>
  <div class="tb-state">
    <label for="tb-show"><?= $word('ORDERS', 'show') ?></label>
    <select id="tb-show" name="show">
<?php foreach ($label as $value => $text): ?>
      <option value="<?= $e($value) ?>"<?php if ($show === $value): ?> selected<?php endif; ?>><?= $e($text) ?></option>
<?php endforeach; ?>
    </select>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
  <?= $showEnd() ?>
</form>
<p class="muted erp-copy-run"><?= $e($on ? $words['on'] : $words['off']) ?> <?= $e($runLine) ?></p>

<?php if ($rows === []): ?>
<?php if ($show === 'waiting'): ?>
<?= $empty($words['none'], $words['none_text']) ?>
<?php else: ?>
<?= $empty($words['none_other'], $words['none_other_text']) ?>
<?php endif; ?>
<?php else: ?>
<div class="grp-block doc-group <?= $e($tone[$show]) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-erp"><?= $e($label[$show]) ?></button> <span class="grp-count"><?php if (count($rows) === 1): ?><?= $e($words['group_one']) ?><?php else: ?><?= $e(sprintf($words['group_many'], count($rows))) ?><?php endif; ?></span></h3>
  <div class="table-wrap doc-board" id="g-erp">
  <table class="stack list board doc-list">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $e($words['col_invoice']) ?></th>
        <th scope="col"><?= $e($words['col_supplier']) ?></th>
        <th scope="col" class="c-status"><?= $e($words['col_state']) ?></th>
        <th scope="col"><?= $e($words['col_date']) ?></th>
        <th scope="col" class="num"><?= $e($words['col_total']) ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><?= $e($r['name']) ?><?php if ($r['sub'] !== ''): ?><span class="o-sub"><?= $e($r['sub']) ?></span><?php endif; ?></th>
        <td data-label="<?= $e($words['col_supplier']) ?>"><?= $e($r['supplier']) ?></td>
        <td class="c-status doc-st <?= $e($r['tone']) ?>"><span class="chip"><?= $e($r['state_word']) ?></span><?php if ($r['why'] !== null): ?><span class="o-sub"><?= $e($r['why']) ?></span><?php endif; ?></td>
        <td data-label="<?= $e($words['col_date']) ?>"><?= $e($r['date']) ?></td>
        <td class="num" data-label="<?= $e($words['col_total']) ?>"><?= $money($r['total']) ?><?php if ($r['copy'] !== null): ?><span class="o-sub"><a href="<?= $u($r['copy']['href']) ?>"><?= $e($r['copy']['text']) ?></a></span><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php if (count($rows) >= $limit): ?>
<p class="muted"><?= $say('STOCK_OPS', 'limit', $limit) ?></p>
<?php endif; ?>
<?php endif; ?>
