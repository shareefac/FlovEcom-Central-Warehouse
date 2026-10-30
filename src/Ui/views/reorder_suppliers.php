<?php
// Purchasing › Reorder › Suppliers, a report (the owner's approved TP_Report; docs/decisions.md W9.12): the figures strip (suppliers, with
// their own spare days, the normal delivery and spare days), the supplier being changed in two columns (reorder.manage; its own route, the
// version it was drawn with), then the suppliers as a board with the coloured edge, each with its "Change". Delivery days and how often we
// order are the supplier's own page's.
$v = $typed ?? ($current ?? []);
$spare = count(array_filter($rows, static fn (array $r): bool => $r['safety_days'] !== null));
?>
<?= $head(\CW\Ui\Words::PAGE_TITLE['reorder_suppliers'], intro: 'reorder_suppliers', look: $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<section class="doc-progress rp-figs" aria-label="<?= $word('REORDER_SUPPLIERS', 'figures') ?>">
  <div class="doc-step"><p class="doc-step-label"><?= $word('REORDER_SUPPLIERS', 'fig_suppliers') ?></p><p class="doc-step-big"><?= $n(count($rows)) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('REORDER_SUPPLIERS', 'fig_spare') ?></p><p class="doc-step-big"><?= $n($spare) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('REORDER_SUPPLIERS', 'fig_lead') ?></p><p class="doc-step-big"><?= $n($defaults['lead']) ?></p><p class="step-sub"><?= $word('REORDER_SUPPLIERS', 'lead_note') ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('REORDER_SUPPLIERS', 'fig_safety') ?></p><p class="doc-step-big"><?= $n($defaults['safety']) ?></p></div>
</section>
<?php if ($canManage && $current !== null): ?>
<form class="doc-details" method="post" action="/ui/purchasing/reorder/suppliers" id="change" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="version" value="<?= $e($version) ?>">
  <input type="hidden" name="supplier_id" value="<?= $e($current['id']) ?>">
  <fieldset class="doc-grid">
    <legend><?= $say('REORDER_SUPPLIERS', 'title_supplier', (string) $current['name']) ?></legend>
    <label><?= $word('REORDER_SUPPLIERS', 'spare') ?> <span class="hint"><?= $say('REORDER_SUPPLIERS', 'spare_hint', $defaults['safety']) ?></span> <input type="number" name="safety_days" value="<?= $e($v['safety_days'] ?? '') ?>" min="0" max="90" class="short"></label>
    <label><?= $word('REORDER_SUPPLIERS', 'note') ?> <input type="text" name="note" value="<?= $e($v['note'] ?? '') ?>" maxlength="500"></label>
    <p class="actions doc-wide"><a class="btn ghost" href="/ui/purchasing/reorder/suppliers" data-leave><?= $word('DOC', 'leave') ?></a> <button type="submit" class="btn primary"><?= $word('REORDER_SUPPLIERS', 'save') ?></button></p>
  </fieldset>
</form>
<?php endif; ?>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::REORDER_SUPPLIERS['none'], \CW\Ui\Words::REORDER_SUPPLIERS['none_text']) ?>
<?php else: ?>
<div class="grp-block doc-group info rd-list">
  <h2 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-suppliers"><?= $word('REORDER_SUPPLIERS', 'group') ?></button> <span class="grp-count"><?php if (count($rows) === 1): ?><?= $word('REORDER_SUPPLIERS', 'group_one') ?><?php else: ?><?= $say('REORDER_SUPPLIERS', 'group_many', count($rows)) ?><?php endif; ?></span></h2>
  <div class="table-wrap doc-board" id="g-suppliers">
  <table class="stack list board brands">
    <thead>
      <tr>
        <th scope="col"><?= $word('REORDER_SUPPLIERS', 'supplier') ?></th>
        <th scope="col" class="c-status"><?= $word('REORDER_SUPPLIERS', 'status') ?></th>
        <th scope="col" class="num"><?= $word('REORDER_SUPPLIERS', 'products') ?></th>
        <th scope="col" class="num"><?= $word('REORDER_SUPPLIERS', 'delivery') ?></th>
        <th scope="col" class="num"><?= $word('REORDER_SUPPLIERS', 'every') ?></th>
        <th scope="col" class="num"><?= $word('REORDER_SUPPLIERS', 'spare') ?></th>
        <th scope="col"><?= $word('REORDER_SUPPLIERS', 'note') ?></th>
        <th scope="col"><?= $word('REORDER_SUPPLIERS', 'changed') ?></th>
<?php if ($canManage): ?>
        <th scope="col"><span class="visually-hidden"><?= $word('REORDER_SUPPLIERS', 'change') ?></span></th>
<?php endif; ?>
      </tr>
    </thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr>
        <th scope="row" class="c-head"><a href="<?= $u('/ui/purchasing/suppliers/' . $r['id']) ?>"><?= $e($r['name']) ?></a> <span class="o-sub"><?= $e($r['code']) ?></span></th>
        <td class="c-status doc-st <?= $e(\CW\Ui\Words::tone('SUPPLIER_STATUS', (string) $r['status'])) ?>"><span class="chip"><?= $word('SUPPLIER_STATUS', (string) $r['status']) ?></span></td>
        <td class="num" data-label="<?= $word('REORDER_SUPPLIERS', 'products') ?>"><?= $n($r['items']) ?></td>
        <td class="num" data-label="<?= $word('REORDER_SUPPLIERS', 'delivery') ?>"><?php if ($r['default_lead_days'] !== null): ?><?= $say('REORDER_SUPPLIERS', 'n_days', (int) $r['default_lead_days']) ?><?php else: ?><span class="muted"><?= $say('REORDER_SUPPLIERS', 'normal_days', $defaults['lead']) ?></span><?php endif; ?></td>
        <td class="num" data-label="<?= $word('REORDER_SUPPLIERS', 'every') ?>"><?php if ($r['review_days'] !== null): ?><?= $say('REORDER_SUPPLIERS', 'n_days', (int) $r['review_days']) ?><?php else: ?><span class="muted"><?= $say('REORDER_SUPPLIERS', 'normal_days', $defaults['review']) ?></span><?php endif; ?></td>
        <td class="num" data-label="<?= $word('REORDER_SUPPLIERS', 'spare') ?>"><?php if ($r['safety_days'] !== null): ?><?= $say('REORDER_SUPPLIERS', 'n_days', (int) $r['safety_days']) ?><?php else: ?><span class="muted"><?= $say('REORDER_SUPPLIERS', 'normal_days', $defaults['safety']) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('REORDER_SUPPLIERS', 'note') ?>"><?= $e($r['note']) ?></td>
        <td data-label="<?= $word('REORDER_SUPPLIERS', 'changed') ?>"><?php if ($r['updated_at'] !== null): ?><?= $say('REORDER_SUPPLIERS', 'changed_line', \CW\Ui\Html::when($r['updated_at']), (string) ($r['updated_by_name'] ?? \CW\Ui\Words::ANOMALIES['set_up'])) ?><?php endif; ?></td>
<?php if ($canManage): ?>
        <td class="c-next"><a class="btn sm" href="<?= $u('/ui/purchasing/reorder/suppliers', ['supplier' => (string) $r['id']]) ?>#change"><?= $word('REORDER_SUPPLIERS', 'change') ?></a></td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>
