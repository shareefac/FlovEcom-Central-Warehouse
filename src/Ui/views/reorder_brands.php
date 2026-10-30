<?php
// Purchasing › Reorder › Brands, a report (the owner's approved TP_Report; docs/decisions.md W9.12): the figures strip (brands, with a
// sales change, with their own spare days, the normal spare days), the brand being changed in two columns (reorder.manage; its own route,
// the version it was drawn with), then the brands as a board with the coloured edge, each with its "Change".
$v = $typed ?? ($current ?? []);
$changed = count(array_filter($rows, static fn (array $r): bool => $r['demand_factor'] !== null));
$spare = count(array_filter($rows, static fn (array $r): bool => $r['safety_days'] !== null));
?>
<?= $head(\CW\Ui\Words::PAGE_TITLE['reorder_brands'], intro: 'reorder_brands', look: $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<section class="doc-progress rp-figs" aria-label="<?= $word('BRANDS', 'figures') ?>">
  <div class="doc-step"><p class="doc-step-label"><?= $word('BRANDS', 'fig_brands') ?></p><p class="doc-step-big"><?= $n(count($rows)) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('BRANDS', 'fig_changed') ?></p><p class="doc-step-big"><?= $n($changed) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('BRANDS', 'fig_spare') ?></p><p class="doc-step-big"><?= $n($spare) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('BRANDS', 'fig_normal') ?></p><p class="doc-step-big"><?= $n($defaultSafety) ?></p></div>
</section>
<?php if ($canManage && $brand !== null): ?>
<form class="doc-details" method="post" action="/ui/purchasing/reorder/brands" id="change" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="version" value="<?= $e($version) ?>">
  <input type="hidden" name="brand" value="<?= $e($brand) ?>">
  <fieldset class="doc-grid">
    <legend><?= $say('BRANDS', 'title_brand', $brand) ?></legend>
    <label><?= $word('BRANDS', 'factor') ?> <span class="hint"><?= $word('BRANDS', 'factor_hint') ?></span> <input type="text" name="demand_factor" value="<?= $e($v['demand_factor'] ?? '') ?>" maxlength="4" class="short"></label>
    <label><?= $word('BRANDS', 'safety') ?> <span class="hint"><?= $say('BRANDS', 'safety_hint', $defaultSafety) ?></span> <input type="number" name="safety_days" value="<?= $e($v['safety_days'] ?? '') ?>" min="0" max="90" class="short"></label>
    <label class="doc-wide"><?= $word('BRANDS', 'note') ?> <input type="text" name="note" value="<?= $e($v['note'] ?? '') ?>" maxlength="500"></label>
    <p class="actions doc-wide"><a class="btn ghost" href="/ui/purchasing/reorder/brands" data-leave><?= $word('DOC', 'leave') ?></a> <button type="submit" class="btn primary"><?= $word('BRANDS', 'save') ?></button></p>
  </fieldset>
</form>
<?php endif; ?>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::BRANDS['none'], \CW\Ui\Words::BRANDS['none_text']) ?>
<?php else: ?>
<div class="grp-block doc-group info">
  <h2 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-brands"><?= $word('BRANDS', 'group') ?></button> <span class="grp-count"><?php if (count($rows) === 1): ?><?= $word('BRANDS', 'group_one') ?><?php else: ?><?= $say('BRANDS', 'group_many', count($rows)) ?><?php endif; ?></span></h2>
  <div class="table-wrap doc-board" id="g-brands">
  <table class="stack list board brands">
    <thead>
      <tr>
        <th scope="col"><?= $word('BRANDS', 'brand') ?></th>
        <th scope="col" class="num"><?= $word('BRANDS', 'products') ?></th>
        <th scope="col" class="num"><?= $word('BRANDS', 'sells') ?></th>
        <th scope="col" class="num"><?= $word('BRANDS', 'change_col') ?></th>
        <th scope="col" class="num"><?= $word('BRANDS', 'spare') ?></th>
        <th scope="col"><?= $word('BRANDS', 'note') ?></th>
        <th scope="col"><?= $word('BRANDS', 'changed') ?></th>
<?php if ($canManage): ?>
        <th scope="col"><span class="visually-hidden"><?= $word('BRANDS', 'change') ?></span></th>
<?php endif; ?>
      </tr>
    </thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr>
        <th scope="row" class="c-head"><?= $e($r['brand']) ?></th>
        <td class="num" data-label="<?= $word('BRANDS', 'products') ?>"><?= $n($r['items']) ?></td>
        <td class="num" data-label="<?= $word('BRANDS', 'sells') ?>"><?= $dec($r['rate']) ?></td>
        <td class="num" data-label="<?= $word('BRANDS', 'change_col') ?>"><?php if ($r['demand_factor'] !== null): ?><?= $dec($r['demand_factor']) ?><?php else: ?><span class="muted"><?= $word('BRANDS', 'normal') ?></span><?php endif; ?></td>
        <td class="num" data-label="<?= $word('BRANDS', 'spare') ?>"><?php if ($r['safety_days'] !== null): ?><?= $n($r['safety_days']) ?><?php else: ?><span class="muted"><?= $word('BRANDS', 'normal') ?></span><?php endif; ?></td>
        <td data-label="<?= $word('BRANDS', 'note') ?>"><?= $e($r['note']) ?></td>
        <td data-label="<?= $word('BRANDS', 'changed') ?>"><?php if ($r['updated_at'] !== null): ?><?= $say('BRANDS', 'changed_line', \CW\Ui\Html::when($r['updated_at']), (string) ($r['updated_by_name'] ?? \CW\Ui\Words::ANOMALIES['set_up'])) ?><?php endif; ?></td>
<?php if ($canManage): ?>
        <td class="c-next"><a class="btn sm" href="<?= $u('/ui/purchasing/reorder/brands', ['brand' => $r['brand']]) ?>#change"><?= $word('BRANDS', 'change') ?></a></td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>
