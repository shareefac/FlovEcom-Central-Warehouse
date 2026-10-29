<?= $head(\CW\Ui\Words::PAGE_TITLE['reorder_low_stock'], intro: 'reorder_low_stock', look: $lookOnly) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('REORDER_LOW', 'product') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($filters['q']) ?>" maxlength="100" placeholder="<?= $word('UI', 'search') ?>"></div>
  <details class="tb-pop">
    <summary class="btn ghost sm"><span class="ico ico-filter" aria-hidden="true"></span><span><?= $word('UI', 'filter') ?></span><?php if ($filtered): ?> <span class="count">&#10003;</span><?php endif; ?></summary>
    <div class="pop pop-form">
      <label><?= $word('REORDER', 'brand') ?>
        <select name="brand">
          <option value=""><?= $word('REORDER', 'any_brand') ?></option>
<?php foreach ($brands as $b): ?>
          <option value="<?= $e($b) ?>"<?php if ($filters['brand'] !== null && mb_strtolower($filters['brand']) === mb_strtolower($b)): ?> selected<?php endif; ?>><?= $e($b) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label><?= $word('REORDER', 'supplier') ?>
        <select name="supplier">
          <option value=""><?= $word('REORDER', 'any_supplier') ?></option>
<?php foreach ($suppliers as $s): ?>
          <option value="<?= $e($s['id']) ?>"<?php if ($filters['supplier'] === $s['id']): ?> selected<?php endif; ?>><?= $e($s['name']) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label><?= $word('REORDER_LOW', 'filter_low') ?>
        <select name="low">
          <option value="any"<?php if ($filters['low'] === 'any'): ?> selected<?php endif; ?>><?= $word('REORDER_LOW', 'low_any') ?></option>
          <option value="set"<?php if ($filters['low'] === 'set'): ?> selected<?php endif; ?>><?= $word('REORDER_LOW', 'low_set') ?></option>
          <option value="own"<?php if ($filters['low'] === 'own'): ?> selected<?php endif; ?>><?= $word('REORDER_LOW', 'low_own') ?></option>
        </select>
      </label>
      <label class="choice"><input type="checkbox" name="below" value="1"<?php if ($filters['below']): ?> checked<?php endif; ?>> <?= $word('REORDER_ENGINE', 'below') ?></label>
      <div class="pop-actions"><?php if ($filtered): ?><a class="btn ghost sm" href="/ui/purchasing/reorder/low-stock"><?= $word('REORDER_LOW', 'clear') ?></a><?php endif; ?><button type="submit" class="btn sm"><?= $word('UI', 'apply') ?></button></div>
    </div>
  </details>
</form>
<p><?= $word('REORDER_LOW', 'intro') ?></p>
<p class="hint"><?= $word('REORDER_LOW', 'how') ?> <?= $word('REORDER_LOW', 'site_wins') ?></p>
<p class="hint"><?php if ($writers === []): ?><?= $word('REORDER_LOW', 'writers_none') ?><?php else: ?><?= $say('REORDER_LOW', 'writers_on', \CW\Ui\Words::andList(array_map('strval', array_column($writers, 'name')))) ?><?php endif; ?></p>
<p class="hint"><?php if ($figures['at'] === null): ?><?= $word('REORDER_ENGINE', 'figures_none') ?><?php else: ?><?= $say('REORDER_ENGINE', 'figures_at', \CW\Ui\Html::when($figures['at'])) ?><?php endif; ?><?php if ($figures['failed']): ?> <?= $chip('needs', \CW\Ui\Words::REORDER_ENGINE['figures_failed']) ?><?php endif; ?></p>
<?php if ($canManage): ?>
<form class="inline" method="post" action="/ui/purchasing/reorder/refresh">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="items" value="<?= $e(implode(',', array_column($rows, 'sku_id'))) ?>">
  <input type="hidden" name="back" value="low-stock">
  <button class="btn" type="submit"><?= $word('REORDER_ENGINE', 'refresh') ?></button>
</form>
<?php endif; ?>
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

<?php if ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::REORDER_LOW['none_filter'], \CW\Ui\Words::REORDER_LOW['none_filter_text'], '/ui/purchasing/reorder/low-stock', \CW\Ui\Words::REORDER_LOW['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::REORDER_LOW['none'], \CW\Ui\Words::REORDER_LOW['none_text']) ?>
<?php else: ?>
<div class="board-head"><h2 class="board-title"><?= $word('PAGE_TITLE', 'reorder_low_stock') ?></h2><p class="board-note"><?php if ($total === 1): ?><?= $word('REORDER_LOW', 'total_one') ?><?php else: ?><?= $say('REORDER_LOW', 'total_many', $total) ?><?php endif; ?><?php if ($pages > 1): ?> <?= $say('REORDER', 'page', $page, $pages) ?><?php endif; ?></p></div>
<?php if ($canManage): ?>
<form class="reorder" method="post" action="/ui/purchasing/reorder/low-stock">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <input type="hidden" name="brand" value="<?= $e($filters['brand']) ?>">
  <input type="hidden" name="supplier" value="<?= $e($filters['supplier']) ?>">
  <input type="hidden" name="q" value="<?= $e($filters['q']) ?>">
  <input type="hidden" name="below" value="<?= $e($filters['below'] ? '1' : '') ?>">
  <input type="hidden" name="low" value="<?= $e($filters['low']) ?>">
  <input type="hidden" name="page" value="<?= $e($page) ?>">
<?php endif; ?>
<div class="table-wrap">
<table class="stack list reorder low-stock">
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
<?php foreach ($rows as $r): ?>
    <tr class="<?php if (!empty($r['below_safety'])): ?>urgent<?php else: ?>info<?php endif; ?>">
      <th scope="row" class="c-head"><a class="o-name" href="<?= $u('/ui/purchasing/reorder/items/' . $r['sku_id']) ?>"><?= $e($r['code']) ?></a><span class="o-sub"><?= $e($r['name']) ?><?php if ($r['brand'] !== null): ?> · <?= $e($r['brand']) ?><?php endif; ?></span><?php if (!empty($r['below_safety'])): ?> <?= $chip('needs', \CW\Ui\Words::say('REORDER_ENGINE', 'below_chip', (int) $r['safety_stock'])) ?><?php endif; ?></th>
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
<?php if ($canManage): ?>
  <p class="actions">
    <button type="submit" class="btn primary" name="action" value="safety"><?= $word('REORDER_LOW', 'use_safety') ?></button>
    <button class="btn" type="submit" name="action" value="keep"><?= $word('REORDER_LOW', 'keep_own') ?></button>
    <span class="hint"><?= $say('REORDER_LOW', 'buttons_hint', $bulkMax) ?></span>
  </p>
</form>
<?php endif; ?>
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
<?php endif; ?>
