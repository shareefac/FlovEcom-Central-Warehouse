<?php
// Purchasing › Reorder › What to buy, a report with the suggestion list first (the owner's approved TP_Report; docs/decisions.md W9.11):
// More at the head's right (the download, the sales data and its upkeep, the brands, the days left out, the sales history), what the last
// "Create draft orders" made, the figures strip (products to buy, will run out, cost, when the figures were worked out), one search box
// and the Show row; then the suggestions grouped will run out, to buy, enough for now, each group a coloured heading with its count over a
// card with its colour on the left edge, and ONE "Create draft orders" in the bar at the bottom of the list (doc.PO.post). The draft form
// carries what it did (row_count first, FormOnce, the filters).
$bar = $partial('reorder_bar', ['rows' => $rows, 'query' => $query, 'history' => $history, 'channelNames' => $channelNames, 'canManage' => $canManage,
    'figures' => $figures, 'stockCw' => $filters['stock'] === 'cw']);
$latest = null;
$stale = $figures['failed'] || $history['demand_stale'];
foreach ($history['channels'] as $c) {
    $latest = $latest === null || (string) $c['to'] > $latest ? (string) $c['to'] : $latest;
    $stale = $stale || $c['stale'];
}
?>
<div class="doc-head">
<?= $head(\CW\Ui\Words::MENU['reorder'], intro: 'reorder', look: $lookOnly, side: $bar) ?>
</div>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($created !== null): ?>
<section class="card box" aria-labelledby="created-h">
  <h2 id="created-h"><?= $word('REORDER', 'made') ?></h2>
<?php if ($created['drafts'] === []): ?>
  <p class="note"><?= $word('REORDER', 'made_none') ?></p>
<?php else: ?>
  <ul class="plain">
<?php foreach ($created['drafts'] as $d): ?>
    <li><a href="<?= $u('/ui/purchasing/orders/' . $d['id']) ?>"><?= $e($d['line']) ?></a><?php if ($d['below_minimum']): ?> <span class="tag needs"><?= $say('REORDER', 'below_minimum', \CW\Ui\Html::money($d['min_order_value'])) ?></span><?php endif; ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($created['skipped'] !== []): ?>
  <h3><?= $word('REORDER', 'not_ordered') ?></h3>
  <ul class="plain">
<?php foreach ($created['skipped'] as $s): ?>
    <li><a href="<?= $u('/ui/purchasing/reorder/items/' . $s['sku_id']) ?>"><?= $e($s['name']) ?></a> <span class="muted"><?= $e($s['code']) ?></span> – <?= $e($s['reason']) ?></li>
<?php endforeach; ?>
<?php if ($created['more'] > 0): ?>
    <li class="muted"><?= $say('REORDER', 'and_more', $created['more']) ?></li>
<?php endif; ?>
  </ul>
<?php endif; ?>
</section>
<?php endif; ?>

<section class="doc-progress rp-figs" aria-label="<?= $word('REORDER', 'figures') ?>">
  <div class="doc-step"><p class="doc-step-label"><?php if ($filters['show'] === 'need'): ?><?= $word('REORDER', 'fig_to_buy') ?><?php else: ?><?= $word('REORDER', 'fig_listed') ?><?php endif; ?></p><p class="doc-step-big"><?= $n($total) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('REORDER', 'fig_urgent') ?></p><p class="doc-step-big"><?= $n($strip['urgent']) ?></p><?php if ($pages > 1): ?><p class="step-sub"><?= $word('REORDER', 'fig_page') ?></p><?php endif; ?></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('REORDER', 'fig_value') ?></p><p class="doc-step-big"><?= $e($strip['value']) ?></p><?php if ($pages > 1): ?><p class="step-sub"><?= $word('REORDER', 'fig_page') ?></p><?php endif; ?></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('REORDER', 'fig_worked') ?></p><p class="doc-step-big"><?php if ($filters['stock'] === 'cw' && $figures['at'] !== null): ?><?= $when($figures['at']) ?><?php elseif ($history['computed_at'] !== null): ?><?= $when($history['computed_at']) ?><?php else: ?><?= $word('REORDER', 'fig_never') ?><?php endif; ?></p><?php if ($latest !== null): ?><p class="step-sub"><?= $say('REORDER', 'fig_sales_to', \CW\Ui\Html::day($latest)) ?></p><?php endif; ?><?php if ($stale): ?><p><?= $chip('needs', \CW\Ui\Words::REORDER['fig_old']) ?></p><?php endif; ?></div>
</section>

<?php if ($history['channels'] === []): ?>
<p class="note"><?= $word('REORDER', 'no_data') ?> <?= $word('REORDER', 'no_data_text') ?></p>
<?php endif; ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>" data-leaves="1">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('REORDER', 'product') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($filters['q']) ?>" maxlength="100" placeholder="<?= $word('REORDER', 'product_hint') ?>"></div>
  <div class="tb-state">
    <label for="tb-show"><?= $word('REORDER', 'show') ?></label>
    <select id="tb-show" name="show">
      <option value="need"<?php if ($filters['show'] === 'need'): ?> selected<?php endif; ?>><?= $word('REORDER', 'show_need') ?></option>
      <option value="all"<?php if ($filters['show'] === 'all'): ?> selected<?php endif; ?>><?= $word('REORDER', 'show_all') ?></option>
    </select>
    <label class="choice tb-check"><input type="checkbox" name="urgent" value="1"<?php if ($filters['urgent']): ?> checked<?php endif; ?>> <?= $word('REORDER', 'urgent') ?></label>
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
    <label class="visually-hidden" for="tb-stock"><?= $word('REORDER', 'stock') ?></label>
    <select id="tb-stock" name="stock">
      <option value="cw"<?php if ($filters['stock'] === 'cw'): ?> selected<?php endif; ?>><?= $word('REORDER', 'stock_cw') ?></option>
      <option value="site"<?php if ($filters['stock'] === 'site'): ?> selected<?php endif; ?>><?= $word('REORDER', 'stock_site') ?></option>
    </select>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
<?php if ($filtered): ?>
  <div class="tb-end"><a class="btn ghost sm" href="/ui/purchasing/reorder"><?= $word('REORDER', 'clear') ?></a></div>
<?php endif; ?>
</form>

<?php if ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::REORDER['none_filter'], \CW\Ui\Words::REORDER['none_filter_text'], '/ui/purchasing/reorder', \CW\Ui\Words::REORDER['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::REORDER['none'], $filters['show'] === 'need' ? \CW\Ui\Words::REORDER['none_text'] : '') ?>
<?php else: ?>
<?php if ($canDraft): ?>
<form class="reorder" method="post" action="/ui/purchasing/reorder/draft" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
  <input type="hidden" name="row_count" value="<?= $e(count($rows)) ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <input type="hidden" name="stock" value="<?= $e($filters['stock']) ?>">
  <input type="hidden" name="brand" value="<?= $e($filters['brand']) ?>">
  <input type="hidden" name="supplier" value="<?= $e($filters['supplier']) ?>">
  <input type="hidden" name="q" value="<?= $e($filters['q']) ?>">
  <input type="hidden" name="show" value="<?= $e($filters['show']) ?>">
  <input type="hidden" name="urgent" value="<?= $e($filters['urgent'] ? '1' : '') ?>">
  <input type="hidden" name="below" value="<?= $e($filters['below'] ? '1' : '') ?>">
  <input type="hidden" name="page" value="<?= $e($page) ?>">
<?php endif; ?>
<?php foreach ($groups as $g): ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $e($g['name']) ?></button> <span class="grp-count"><?php if (count($g['rows']) === 1): ?><?= $word('REORDER', 'group_one') ?><?php else: ?><?= $say('REORDER', 'group_many', count($g['rows'])) ?><?php endif; ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board doc-list reorder">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('REORDER', 'product') ?></th>
        <th scope="col" class="c-status"><?= $word('REORDER', 'state') ?></th>
<?php if ($canDraft): ?>
        <th scope="col"><?= $word('REORDER', 'buy') ?></th>
<?php endif; ?>
        <th scope="col" class="num"><?= $word('REORDER', 'sells_a_day') ?></th>
        <th scope="col" class="num"><?php if ($filters['stock'] === 'site'): ?><?= $word('REORDER', 'have_site') ?><?php else: ?><?= $word('REORDER', 'have') ?><?php endif; ?></th>
        <th scope="col" class="num"><?= $word('REORDER', 'suggest') ?></th>
        <th scope="col" class="num"><?= $word('REORDER', 'value_label') ?></th>
        <th scope="col"><?= $word('REORDER', 'why') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u('/ui/purchasing/reorder/items/' . $r['sku_id']) ?>"><?= $e($r['code']) ?></a><span class="o-sub"><?= $e($r['name']) ?><?php if ($r['brand'] !== null): ?> · <?= $e($r['brand']) ?><?php endif; ?></span><span class="o-sub"><?php if ($r['supplier'] !== null): ?><?= $e($r['supplier_name'] ?? $r['supplier']) ?><?php else: ?><?= $word('REORDER', 'no_supplier') ?><?php endif; ?></span>
<?php foreach ($r['flag_list'] as $fl): ?>
<?php if ($fl['word'] !== $g['name']): ?>
          <?= $chip($fl['tone'], $fl['word']) ?>
<?php endif; ?>
<?php endforeach; ?>
<?php if ($r['draft'] !== null): ?>
          <span class="o-line"><?= $say('REORDER', 'on_draft', $r['in_drafts']) ?> – <a href="<?= $u('/ui/purchasing/orders/' . $r['draft']) ?>"><?= $word('REORDER', 'open_draft') ?></a>. <?= $e($r['draft_note']) ?></span>
<?php endif; ?>
        </th>
        <td class="c-status doc-st <?= $e($g['tone']) ?>"><span class="chip"><?= $e($g['name']) ?></span></td>
<?php if ($canDraft): ?>
        <td data-label="<?= $word('REORDER', 'buy') ?>"><label class="choice"><input type="checkbox" name="pick_<?= $e($r['sku_id']) ?>" value="1"<?php if ($r['packs'] > 0 && (int) $r['in_drafts'] === 0): ?> checked<?php endif; ?>> <span class="visually-hidden"><?= $say('REORDER', 'buy_label', $r['name']) ?></span></label></td>
<?php endif; ?>
        <td class="num" data-label="<?= $word('REORDER', 'sells_a_day') ?>"><?= $e($r['per_day']) ?></td>
        <td class="num" data-label="<?php if ($filters['stock'] === 'site'): ?><?= $word('REORDER', 'have_site') ?><?php else: ?><?= $word('REORDER', 'have') ?><?php endif; ?>"><?= $say('REORDER', 'have_line', $r['available'], $r['on_order']) ?> <span class="o-sub"><?= $say('REORDER', 'days_left', $r['days_left']) ?></span><?php if ((int) ($r['safety_stock'] ?? 0) > 0): ?> <span class="o-sub"><?= $say('REORDER_ENGINE', 'safety_stock', (int) $r['safety_stock']) ?></span><?php endif; ?></td>
        <td class="num" data-label="<?= $word('REORDER', 'suggest') ?>"><?php if ($canDraft): ?><input class="packs" type="number" name="packs_<?= $e($r['sku_id']) ?>" value="<?= $e($r['packs']) ?>" min="0" max="1000000" step="1" aria-label="<?= $say('REORDER', 'packs_label', $r['name']) ?>"><input type="hidden" name="si_<?= $e($r['sku_id']) ?>" value="<?= $e($r['supplier_item_id'] ?? '') ?>"><input type="hidden" name="upp_<?= $e($r['sku_id']) ?>" value="<?= $e($r['upp']) ?>"><?php else: ?><?= $n($r['packs']) ?><?php endif; ?>
<?php if ($r['upp'] > 1): ?>
          <span class="o-sub"><?= $say('REORDER', 'pack_of', $r['upp']) ?></span>
<?php endif; ?>
        </td>
        <td class="num" data-label="<?= $word('REORDER', 'value_label') ?>"><?= $e($r['value']) ?><?php if ($r['price'] !== ''): ?> <span class="o-sub"><?= $say('REORDER', 'per_pack', $r['price']) ?></span><?php endif; ?></td>
        <td class="c-wide" data-label="">
          <details class="why">
            <summary><?= $word('REORDER', 'why') ?></summary>
            <p><?php foreach ($r['why'] as $sentence): ?><?= $e($sentence) ?> <?php endforeach; ?></p>
            <details class="why maths">
              <summary><?= $word('REORDER', 'maths') ?></summary>
              <p><?= $e($r['explain']) ?></p>
            </details>
          </details>
        </td>
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
  <a href="<?= $u('/ui/purchasing/reorder', $query + ['page' => $page - 1]) ?>" rel="prev"><?= $word('REORDER', 'previous') ?></a>
<?php endif; ?>
  <span><?= $say('REORDER', 'page', $page, $pages) ?></span>
<?php if ($page < $pages): ?>
  <a href="<?= $u('/ui/purchasing/reorder', $query + ['page' => $page + 1]) ?>" rel="next"><?= $word('REORDER', 'next') ?></a>
<?php endif; ?>
</nav>
<?php endif; ?>
<?php if ($canDraft): ?>
  <div class="doc-bar">
    <span class="doc-bar-note"><?= $word('REORDER', 'create_text') ?></span>
    <button type="submit" class="btn primary"><?= $word('REORDER', 'create') ?></button>
  </div>
</form>
<?php endif; ?>
<?php endif; ?>
