<?php
// Purchasing › Reorder › Low-stock warnings, a report (the owner's approved TP_Report; docs/decisions.md W9.12): "Work out again" under More
// at the head's right (reorder.manage), what the last change did, the figures strip (products, below the safety stock and set by CW on this
// page, when the figures were worked out and which websites take them), one search box and the Show row; then the products grouped below
// the safety stock and every other, each group a coloured heading with its count over a card with its colour on the left edge, and the bar
// at the bottom of the list: "Let each website keep its own figure", then "Use the safety stock as the warning" (FormOnce, the filters).
$bar = $canManage ? $partial('low_stock_bar', ['rows' => $rows]) : '';
$below = count(array_filter($rows, static fn (array $r): bool => !empty($r['below_safety'])));
$set = count(array_filter($rows, static fn (array $r): bool => ($r['low_stock_level'] ?? null) !== null));
?>
<div class="doc-head">
<?= $head(\CW\Ui\Words::PAGE_TITLE['reorder_low_stock'], intro: 'reorder_low_stock', look: $lookOnly, side: $bar) ?>
</div>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($done !== null): ?>
<section class="card box" aria-labelledby="done-h">
  <h2 id="done-h"><?= $say('REORDER_LOW', 'done_changed', $done['changed']) ?></h2>
<?php if ($done['skipped'] !== []): ?>
  <h3><?= $word('REORDER_LOW', 'left_out') ?></h3>
  <ul class="plain">
<?php foreach ($done['skipped'] as $s): ?>
    <li><a href="<?= $u('/ui/purchasing/reorder/items/' . $s['sku_id']) ?>"><?= $e($s['name']) ?></a> <span class="muted"><?= $e($s['code']) ?></span> – <?= $e($s['reason']) ?></li>
<?php endforeach; ?>
<?php if ($done['more'] > 0): ?>
    <li class="muted"><?= $say('REORDER_LOW', 'and_more', $done['more']) ?></li>
<?php endif; ?>
  </ul>
<?php endif; ?>
</section>
<?php endif; ?>

<section class="doc-progress rp-figs" aria-label="<?= $word('REORDER_LOW', 'figures') ?>">
  <div class="doc-step"><p class="doc-step-label"><?= $word('REORDER_LOW', 'fig_products') ?></p><p class="doc-step-big"><?= $n($total) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('REORDER_LOW', 'fig_below') ?></p><p class="doc-step-big"><?= $n($below) ?></p><?php if ($pages > 1): ?><p class="step-sub"><?= $word('REORDER_LOW', 'fig_page') ?></p><?php endif; ?></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('REORDER_LOW', 'fig_set') ?></p><p class="doc-step-big"><?= $n($set) ?></p><?php if ($pages > 1): ?><p class="step-sub"><?= $word('REORDER_LOW', 'fig_page') ?></p><?php endif; ?></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('REORDER_LOW', 'fig_worked') ?></p><p class="doc-step-big"><?php if ($figures['at'] === null): ?><?= $word('REORDER', 'fig_never') ?><?php else: ?><?= $when($figures['at']) ?><?php endif; ?></p><p class="step-sub"><?php if ($writers === []): ?><?= $word('REORDER_LOW', 'writers_none') ?><?php else: ?><?= $say('REORDER_LOW', 'writers_on', \CW\Ui\Words::andList(array_map('strval', array_column($writers, 'name')))) ?><?php endif; ?></p><?php if ($figures['failed']): ?><p><?= $chip('needs', \CW\Ui\Words::REORDER_ENGINE['figures_failed']) ?></p><?php endif; ?></div>
</section>

<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>" data-leaves="1">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('REORDER_LOW', 'product') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($filters['q']) ?>" maxlength="100" placeholder="<?= $word('REORDER', 'product_hint') ?>"></div>
  <div class="tb-state">
    <label for="tb-low"><?= $word('REORDER_LOW', 'filter_low') ?></label>
    <select id="tb-low" name="low">
      <option value="any"<?php if ($filters['low'] === 'any'): ?> selected<?php endif; ?>><?= $word('REORDER_LOW', 'low_any') ?></option>
      <option value="set"<?php if ($filters['low'] === 'set'): ?> selected<?php endif; ?>><?= $word('REORDER_LOW', 'low_set') ?></option>
      <option value="own"<?php if ($filters['low'] === 'own'): ?> selected<?php endif; ?>><?= $word('REORDER_LOW', 'low_own') ?></option>
    </select>
    <label class="choice tb-check"><input type="checkbox" name="below" value="1"<?php if ($filters['below']): ?> checked<?php endif; ?>> <?= $word('REORDER_ENGINE', 'below') ?></label>
  </div>
  <div class="tb-state">
    <label class="visually-hidden" for="tb-brand"><?= $word('REORDER', 'brand') ?></label>
    <select id="tb-brand" name="brand">
      <option value=""><?= $word('REORDER', 'any_brand') ?></option>
<?php foreach ($brands as $b): ?>
      <option value="<?= $e($b) ?>"<?php if ($filters['brand'] !== null && mb_strtolower($filters['brand']) === mb_strtolower($b)): ?> selected<?php endif; ?>><?= $e($b) ?></option>
<?php endforeach; ?>
    </select>
    <label class="visually-hidden" for="tb-supplier"><?= $word('REORDER', 'supplier') ?></label>
    <select id="tb-supplier" name="supplier">
      <option value=""><?= $word('REORDER', 'any_supplier') ?></option>
<?php foreach ($suppliers as $s): ?>
      <option value="<?= $e($s['id']) ?>"<?php if ($filters['supplier'] === $s['id']): ?> selected<?php endif; ?>><?= $e($s['name']) ?></option>
<?php endforeach; ?>
    </select>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
<?php if ($filtered): ?>
  <div class="tb-end"><a class="btn ghost sm" href="/ui/purchasing/reorder/low-stock"><?= $word('REORDER_LOW', 'clear') ?></a></div>
<?php endif; ?>
</form>

<?php if ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::REORDER_LOW['none_filter'], \CW\Ui\Words::REORDER_LOW['none_filter_text'], '/ui/purchasing/reorder/low-stock', \CW\Ui\Words::REORDER_LOW['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::REORDER_LOW['none'], \CW\Ui\Words::REORDER_LOW['none_text']) ?>
<?php else: ?>
<?php if ($canManage): ?>
<form class="reorder" method="post" action="/ui/purchasing/reorder/low-stock" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <input type="hidden" name="brand" value="<?= $e($filters['brand']) ?>">
  <input type="hidden" name="supplier" value="<?= $e($filters['supplier']) ?>">
  <input type="hidden" name="q" value="<?= $e($filters['q']) ?>">
  <input type="hidden" name="below" value="<?= $e($filters['below'] ? '1' : '') ?>">
  <input type="hidden" name="low" value="<?= $e($filters['low']) ?>">
  <input type="hidden" name="page" value="<?= $e($page) ?>">
<?php endif; ?>
<?php foreach ($groups as $g): ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?>">
  <h2 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $e($g['name']) ?></button> <span class="grp-count"><?php if (count($g['rows']) === 1): ?><?= $word('REORDER_LOW', 'group_one') ?><?php else: ?><?= $say('REORDER_LOW', 'group_many', count($g['rows'])) ?><?php endif; ?><?php if ($pages > 1): ?> · <?= $say('REORDER', 'page', $page, $pages) ?><?php endif; ?></span></h2>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board reorder low-stock">
    <thead>
      <tr>
        <th scope="col"><?= $word('REORDER_LOW', 'product') ?></th>
<?php if ($canManage): ?>
        <th scope="col"><?= $word('REORDER_LOW', 'tick') ?></th>
<?php endif; ?>
        <th scope="col" class="num"><?= $word('REORDER_LOW', 'sells') ?></th>
        <th scope="col" class="num"><?= $word('REORDER_LOW', 'safety') ?></th>
        <th scope="col" class="num"><?= $word('REORDER_LOW', 'have') ?></th>
        <th scope="col" class="num"><?= $word('REORDER_LOW', 'figure') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head"><a class="o-name" href="<?= $u('/ui/purchasing/reorder/items/' . $r['sku_id']) ?>"><?= $e($r['code']) ?></a><span class="o-sub"><?= $e($r['name']) ?><?php if ($r['brand'] !== null): ?> · <?= $e($r['brand']) ?><?php endif; ?></span></th>
<?php if ($canManage): ?>
        <td data-label="<?= $word('REORDER_LOW', 'tick') ?>"><label class="choice"><input type="checkbox" name="pick_<?= $e($r['sku_id']) ?>" value="1"> <span class="visually-hidden"><?= $say('REORDER_LOW', 'tick_label', $r['name']) ?></span></label></td>
<?php endif; ?>
        <td class="num" data-label="<?= $word('REORDER_LOW', 'sells') ?>"><?= $e($r['per_day']) ?></td>
        <td class="num" data-label="<?= $word('REORDER_LOW', 'safety') ?>"><?= $n($r['safety_stock']) ?> <span class="o-sub"><?= $e($r['safety_line']) ?></span></td>
        <td class="num" data-label="<?= $word('REORDER_LOW', 'have') ?>"><?= $n($r['available']) ?></td>
        <td class="num" data-label="<?= $word('REORDER_LOW', 'figure') ?>"><?php if (($r['low_stock_level'] ?? null) === null): ?><span class="muted"><?= $word('REORDER_LOW', 'own') ?></span><?php else: ?><?= $n($r['low_stock_level']) ?><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="<?= $say('REORDER', 'page', $page, $pages) ?>">
<?php if ($page > 1): ?>
  <a href="<?= $u('/ui/purchasing/reorder/low-stock', $query + ['page' => $page - 1]) ?>" rel="prev"><?= $word('REORDER', 'previous') ?></a>
<?php endif; ?>
  <span><?= $say('REORDER', 'page', $page, $pages) ?></span>
<?php if ($page < $pages): ?>
  <a href="<?= $u('/ui/purchasing/reorder/low-stock', $query + ['page' => $page + 1]) ?>" rel="next"><?= $word('REORDER', 'next') ?></a>
<?php endif; ?>
</nav>
<?php endif; ?>
<?php if ($canManage): ?>
  <div class="doc-bar">
    <span class="doc-bar-note"><?= $say('REORDER_LOW', 'buttons_hint', $bulkMax) ?></span>
    <button class="btn" type="submit" name="action" value="keep"><?= $word('REORDER_LOW', 'keep_own') ?></button>
    <button type="submit" class="btn primary" name="action" value="safety"><?= $word('REORDER_LOW', 'use_safety') ?></button>
  </div>
</form>
<?php endif; ?>
<?php endif; ?>
