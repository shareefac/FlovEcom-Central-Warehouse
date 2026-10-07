<h1><?= $word('MENU', 'reorder') ?></h1>
<?= $intro('reorder', $lookOnly) ?>
<?php if ($lookOnly !== null): ?>
<p class="hint"><?= $word('REORDER', 'look') ?></p>
<?php endif; ?>
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
    <li><a href="<?= $u('/ui/purchasing/orders/' . $d['id']) ?>"><?= $e($d['line']) ?></a><?php if ($d['below_minimum']): ?> <span class="tag warn"><?= $say('REORDER', 'below_minimum', \CW\Ui\Html::money($d['min_order_value'])) ?></span><?php endif; ?></li>
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

<section class="card box sales-data" aria-labelledby="history-h">
  <h2 id="history-h"><?= $word('REORDER', 'data') ?></h2>
<?php if ($history['channels'] === []): ?>
  <?= $empty(\CW\Ui\Words::REORDER['no_data'], \CW\Ui\Words::REORDER['no_data_text']) ?>
<?php else: ?>
  <ul class="plain">
<?php foreach ($history['channels'] as $c): ?>
    <li><?= $say('REORDER', 'data_from', $channelNames[$c['code']] ?? $c['code'], \CW\Ui\Html::day($c['from']), \CW\Ui\Html::day($c['to'])) ?><?php if ($c['stale']): ?> <?= $chip('needs', \CW\Ui\Words::say('REORDER', 'data_old', $c['days_ago'])) ?><?php endif; ?></li>
<?php endforeach; ?>
    <li><?php if ($history['computed_at'] === null): ?><?= $word('REORDER', 'not_worked_out') ?><?php else: ?><?= $say('REORDER', 'worked_out', \CW\Ui\Html::when($history['computed_at'])) ?><?php endif; ?><?php if ($history['demand_stale']): ?> <?= $chip('needs', \CW\Ui\Words::REORDER['worked_out_old']) ?><?php endif; ?></li>
  </ul>
  <p class="hint"><?= $word('REORDER', 'demand') ?></p>
<?php endif; ?>
<?php if ($canManage): ?>
  <form class="inline" method="post" action="/ui/purchasing/reorder/recalculate">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <button type="submit"><?= $word('REORDER', 'recalculate') ?></button>
    <span class="hint"><?= $word('REORDER', 'recalculate_hint') ?></span>
  </form>
<?php endif; ?>
  <p class="see-also">
    <a href="/ui/purchasing/reorder/brands"><?= $word('REORDER', 'brands') ?></a>
    <a href="/ui/purchasing/reorder/anomalies"><?= $word('REORDER', 'anomalies') ?></a>
    <a href="/ui/purchasing/sales-history"><?= $word('REORDER', 'sales') ?></a>
  </p>
</section>

<form class="filters" method="get" action="/ui/purchasing/reorder">
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
  <label><?= $word('REORDER', 'product') ?> <input type="search" name="q" value="<?= $e($filters['q']) ?>" maxlength="100" placeholder="<?= $word('REORDER', 'product_hint') ?>"></label>
  <label><?= $word('REORDER', 'show') ?>
    <select name="show">
      <option value="need"<?php if ($filters['show'] === 'need'): ?> selected<?php endif; ?>><?= $word('REORDER', 'show_need') ?></option>
      <option value="all"<?php if ($filters['show'] === 'all'): ?> selected<?php endif; ?>><?= $word('REORDER', 'show_all') ?></option>
    </select>
  </label>
  <label><?= $word('REORDER', 'stock') ?>
    <select name="stock">
      <option value="cw"<?php if ($filters['stock'] === 'cw'): ?> selected<?php endif; ?>><?= $word('REORDER', 'stock_cw') ?></option>
      <option value="site"<?php if ($filters['stock'] === 'site'): ?> selected<?php endif; ?>><?= $word('REORDER', 'stock_site') ?></option>
    </select>
  </label>
  <label class="choice"><input type="checkbox" name="urgent" value="1"<?php if ($filters['urgent']): ?> checked<?php endif; ?>> <?= $word('REORDER', 'urgent') ?></label>
  <button type="submit"><?= $word('REORDER', 'filter') ?></button>
</form>

<?php if ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::REORDER['none_filter'], \CW\Ui\Words::REORDER['none_filter_text'], '/ui/purchasing/reorder', \CW\Ui\Words::REORDER['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::REORDER['none'], $filters['show'] === 'need' ? \CW\Ui\Words::REORDER['none_text'] : '') ?>
<?php else: ?>
<div class="crumbs">
  <p class="muted"><?php if ($filters['show'] === 'need'): ?><?php if ($total === 1): ?><?= $word('REORDER', 'total_one') ?><?php else: ?><?= $say('REORDER', 'total_many', $total) ?><?php endif; ?><?php else: ?><?php if ($total === 1): ?><?= $word('REORDER', 'total_all_one') ?><?php else: ?><?= $say('REORDER', 'total_all_many', $total) ?><?php endif; ?><?php endif; ?>
    <?= $word('REORDER', 'units') ?><?php if ($pages > 1): ?> <?= $say('REORDER', 'page', $page, $pages) ?><?php endif; ?></p>
  <p class="actions"><a href="<?= $u('/ui/purchasing/reorder.csv', $query) ?>"><?= $word('REORDER', 'download') ?></a></p>
</div>
<?php if ($canDraft): ?>
<form class="reorder" method="post" action="/ui/purchasing/reorder/draft">
  <input type="hidden" name="row_count" value="<?= $e(count($rows)) ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <input type="hidden" name="stock" value="<?= $e($filters['stock']) ?>">
  <input type="hidden" name="brand" value="<?= $e($filters['brand']) ?>">
  <input type="hidden" name="supplier" value="<?= $e($filters['supplier']) ?>">
  <input type="hidden" name="q" value="<?= $e($filters['q']) ?>">
  <input type="hidden" name="show" value="<?= $e($filters['show']) ?>">
  <input type="hidden" name="urgent" value="<?= $e($filters['urgent'] ? '1' : '') ?>">
  <input type="hidden" name="page" value="<?= $e($page) ?>">
<?php endif; ?>
<div class="table-wrap">
<table class="stack list reorder">
  <thead>
    <tr>
      <th scope="col"><?= $word('REORDER', 'product') ?></th>
<?php if ($canDraft): ?>
      <th scope="col"><?= $word('REORDER', 'buy') ?></th>
<?php endif; ?>
      <th scope="col" class="num"><?= $word('REORDER', 'sells_a_day') ?></th>
      <th scope="col" class="num"><?php if ($filters['stock'] === 'site'): ?><?= $word('REORDER', 'have_site') ?><?php else: ?><?= $word('REORDER', 'have') ?><?php endif; ?></th>
      <th scope="col" class="num"><?= $word('REORDER', 'suggest') ?></th>
      <th scope="col" class="num"><?= $word('REORDER', 'value') ?></th>
      <th scope="col"><?= $word('REORDER', 'why') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr class="<?php if ($r['urgent']): ?>urgent<?php else: ?>info<?php endif; ?>">
      <th scope="row" class="c-head"><a class="o-name" href="<?= $u('/ui/purchasing/reorder/items/' . $r['sku_id']) ?>"><?= $e($r['code']) ?></a><span class="o-sub"><?= $e($r['name']) ?><?php if ($r['brand'] !== null): ?> · <?= $e($r['brand']) ?><?php endif; ?></span><span class="o-sub"><?php if ($r['supplier'] !== null): ?><?= $e($r['supplier_name'] ?? $r['supplier']) ?><?php else: ?><?= $word('REORDER', 'no_supplier') ?><?php endif; ?></span>
<?php foreach ($r['flag_list'] as $fl): ?>
        <?= $chip($fl['tone'], $fl['word']) ?>
<?php endforeach; ?>
<?php if ($r['draft'] !== null): ?>
        <span class="o-line"><?= $say('REORDER', 'on_draft', $r['in_drafts']) ?> – <a href="<?= $u('/ui/purchasing/orders/' . $r['draft']) ?>"><?= $word('REORDER', 'open_draft') ?></a>. <?= $e($r['draft_note']) ?></span>
<?php endif; ?>
      </th>
<?php if ($canDraft): ?>
      <td data-label="<?= $word('REORDER', 'buy') ?>"><label class="choice"><input type="checkbox" name="pick_<?= $e($r['sku_id']) ?>" value="1"<?php if ($r['packs'] > 0 && (int) $r['in_drafts'] === 0): ?> checked<?php endif; ?>> <span class="visually-hidden"><?= $say('REORDER', 'buy_label', $r['name']) ?></span></label></td>
<?php endif; ?>
      <td class="num" data-label="<?= $word('REORDER', 'sells_a_day') ?>"><?= $e($r['per_day']) ?></td>
      <td class="num" data-label="<?php if ($filters['stock'] === 'site'): ?><?= $word('REORDER', 'have_site') ?><?php else: ?><?= $word('REORDER', 'have') ?><?php endif; ?>"><?= $say('REORDER', 'have_line', $r['available'], $r['on_order']) ?> <span class="o-sub"><?= $say('REORDER', 'days_left', $r['days_left']) ?></span></td>
      <td class="num" data-label="<?= $word('REORDER', 'suggest') ?>"><?php if ($canDraft): ?><input class="packs" type="number" name="packs_<?= $e($r['sku_id']) ?>" value="<?= $e($r['packs']) ?>" min="0" max="1000000" step="1" aria-label="<?= $say('REORDER', 'packs_label', $r['name']) ?>"><?php else: ?><?= $n($r['packs']) ?><?php endif; ?>
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
<?php if ($canDraft): ?>
  <p class="actions">
    <button type="submit" class="primary"><?= $word('REORDER', 'create') ?></button>
    <span class="hint"><?= $word('REORDER', 'create_text') ?></span>
  </p>
</form>
<?php endif; ?>
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
<?php endif; ?>
