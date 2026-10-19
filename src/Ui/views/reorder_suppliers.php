<?php $v = $typed ?? ($current ?? []); ?>
<?= $head(\CW\Ui\Words::PAGE_TITLE['reorder_suppliers'], intro: 'reorder_suppliers', look: $lookOnly) ?>
<p><?= $say('REORDER_SUPPLIERS', 'intro', $defaults['safety']) ?></p>
<p class="hint"><?= $word('REORDER_SUPPLIERS', 'lead_note') ?></p>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($canManage && $current !== null): ?>
<section class="card box" aria-labelledby="edit-h" id="change">
  <h2 id="edit-h"><?= $say('REORDER_SUPPLIERS', 'title_supplier', (string) $current['name']) ?></h2>
  <form class="record" method="post" action="/ui/purchasing/reorder/suppliers">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <input type="hidden" name="supplier_id" value="<?= $e($current['id']) ?>">
    <fieldset>
      <legend class="visually-hidden"><?= $say('REORDER_SUPPLIERS', 'title_supplier', (string) $current['name']) ?></legend>
      <label><?= $word('REORDER_SUPPLIERS', 'spare') ?> <span class="hint"><?= $say('REORDER_SUPPLIERS', 'spare_hint', $defaults['safety']) ?></span> <input type="number" name="safety_days" value="<?= $e($v['safety_days'] ?? '') ?>" min="0" max="90" class="short"></label>
      <label><?= $word('REORDER_SUPPLIERS', 'note') ?> <input type="text" name="note" value="<?= $e($v['note'] ?? '') ?>" maxlength="500"></label>
    </fieldset>
    <p class="actions"><button type="submit" class="primary"><?= $word('REORDER_SUPPLIERS', 'save') ?></button></p>
  </form>
</section>
<?php elseif ($canManage && $rows !== []): ?>
<p class="hint"><?= $word('REORDER_SUPPLIERS', 'how') ?></p>
<?php endif; ?>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::REORDER_SUPPLIERS['none'], \CW\Ui\Words::REORDER_SUPPLIERS['none_text']) ?>
<?php else: ?>
<div class="table-wrap">
<table class="stack list brands">
  <thead>
    <tr>
      <th scope="col"><?= $word('REORDER_SUPPLIERS', 'supplier') ?></th>
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
      <th scope="row" class="c-head"><a href="<?= $u('/ui/purchasing/suppliers/' . $r['id']) ?>"><?= $e($r['name']) ?></a> <span class="o-sub"><?= $e($r['code']) ?></span> <?= $stateChip('SUPPLIER_STATUS', (string) $r['status']) ?></th>
      <td class="num" data-label="<?= $word('REORDER_SUPPLIERS', 'products') ?>"><?= $n($r['items']) ?></td>
      <td class="num" data-label="<?= $word('REORDER_SUPPLIERS', 'delivery') ?>"><?php if ($r['default_lead_days'] !== null): ?><?= $say('REORDER_SUPPLIERS', 'n_days', (int) $r['default_lead_days']) ?><?php else: ?><span class="muted"><?= $say('REORDER_SUPPLIERS', 'normal_days', $defaults['lead']) ?></span><?php endif; ?></td>
      <td class="num" data-label="<?= $word('REORDER_SUPPLIERS', 'every') ?>"><?php if ($r['review_days'] !== null): ?><?= $say('REORDER_SUPPLIERS', 'n_days', (int) $r['review_days']) ?><?php else: ?><span class="muted"><?= $say('REORDER_SUPPLIERS', 'normal_days', $defaults['review']) ?></span><?php endif; ?></td>
      <td class="num" data-label="<?= $word('REORDER_SUPPLIERS', 'spare') ?>"><?php if ($r['safety_days'] !== null): ?><?= $say('REORDER_SUPPLIERS', 'n_days', (int) $r['safety_days']) ?><?php else: ?><span class="muted"><?= $say('REORDER_SUPPLIERS', 'normal_days', $defaults['safety']) ?></span><?php endif; ?></td>
      <td data-label="<?= $word('REORDER_SUPPLIERS', 'note') ?>"><?= $e($r['note']) ?></td>
      <td data-label="<?= $word('REORDER_SUPPLIERS', 'changed') ?>"><?php if ($r['updated_at'] !== null): ?><?= $say('REORDER_SUPPLIERS', 'changed_line', \CW\Ui\Html::when($r['updated_at']), (string) ($r['updated_by_name'] ?? \CW\Ui\Words::ANOMALIES['set_up'])) ?><?php endif; ?></td>
<?php if ($canManage): ?>
      <td class="c-next"><a class="btn secondary" href="<?= $u('/ui/purchasing/reorder/suppliers', ['supplier' => (string) $r['id']]) ?>#change"><?= $word('REORDER_SUPPLIERS', 'change') ?></a></td>
<?php endif; ?>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
