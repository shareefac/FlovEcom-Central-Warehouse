<?php $v = $typed ?? ($current ?? []); ?>
<h1><?= $word('PAGE_TITLE', 'reorder_brands') ?></h1>
<?= $intro('reorder_brands') ?>
<p><?= $say('BRANDS', 'intro', $defaultSafety) ?></p>
<?php if ($lookOnly !== null): ?>
<p class="hint"><?= $say('UI', 'look_only', $lookOnly) ?></p>
<?php endif; ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($canManage && $brand !== null): ?>
<section class="card box" aria-labelledby="edit-h" id="change">
  <h2 id="edit-h"><?= $say('BRANDS', 'title_brand', $brand) ?></h2>
  <form class="record" method="post" action="/ui/purchasing/reorder/brands">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <input type="hidden" name="brand" value="<?= $e($brand) ?>">
    <fieldset>
      <legend class="visually-hidden"><?= $say('BRANDS', 'title_brand', $brand) ?></legend>
      <label><?= $word('BRANDS', 'factor') ?> <span class="hint"><?= $word('BRANDS', 'factor_hint') ?></span> <input type="text" name="demand_factor" value="<?= $e($v['demand_factor'] ?? '') ?>" maxlength="4" class="short"></label>
      <label><?= $word('BRANDS', 'safety') ?> <span class="hint"><?= $say('BRANDS', 'safety_hint', $defaultSafety) ?></span> <input type="number" name="safety_days" value="<?= $e($v['safety_days'] ?? '') ?>" min="0" max="90" class="short"></label>
      <label><?= $word('BRANDS', 'note') ?> <input type="text" name="note" value="<?= $e($v['note'] ?? '') ?>" maxlength="500"></label>
    </fieldset>
    <p class="actions"><button type="submit" class="primary"><?= $word('BRANDS', 'save') ?></button></p>
  </form>
</section>
<?php elseif ($canManage && $rows !== []): ?>
<p class="hint"><?= $word('BRANDS', 'how') ?></p>
<?php endif; ?>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::BRANDS['none'], \CW\Ui\Words::BRANDS['none_text']) ?>
<?php else: ?>
<div class="table-wrap">
<table class="stack list brands">
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
      <td class="c-next"><a class="btn secondary" href="<?= $u('/ui/purchasing/reorder/brands', ['brand' => $r['brand']]) ?>#change"><?= $word('BRANDS', 'change') ?></a></td>
<?php endif; ?>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
