<?php
// What to buy's action bar (docs/decisions.md W9.11): in the head at its right, everything but the one main action ("Create draft orders",
// in the bar at the bottom of the list) under More: the download, the sales data and its upkeep (what is loaded, "Work out again", "Work
// out sales again"; reorder.manage), the brands, the days left out and the sales history pages. The forms post where they did.
?>
<div class="doc-actions" role="group" aria-label="<?= $word('DOC', 'actions') ?>">
  <details class="more">
    <summary class="btn"><?= $word('DOC', 'more') ?></summary>
    <div class="more-menu">
<?php if ($rows !== []): ?>
      <a class="menu-item" href="<?= $u('/ui/purchasing/reorder.csv', $query) ?>" download><?= $word('REORDER', 'download') ?></a>
<?php endif; ?>
      <details class="doc-act">
        <summary class="btn"><?= $word('REORDER', 'sales_upkeep') ?></summary>
        <div class="doc-act-panel sales-data">
<?php if ($history['channels'] === []): ?>
          <p class="note"><?= $word('REORDER', 'no_data') ?> <?= $word('REORDER', 'no_data_text') ?></p>
<?php else: ?>
          <ul class="plain">
<?php foreach ($history['channels'] as $c): ?>
            <li><?= $say('REORDER', 'data_from', $channelNames[$c['code']] ?? $c['code'], \CW\Ui\Html::day($c['from']), \CW\Ui\Html::day($c['to'])) ?><?php if ($c['stale']): ?> <?= $chip('needs', \CW\Ui\Words::say('REORDER', 'data_old', $c['days_ago'])) ?><?php endif; ?></li>
<?php endforeach; ?>
            <li><?php if ($history['computed_at'] === null): ?><?= $word('REORDER', 'not_worked_out') ?><?php else: ?><?= $say('REORDER', 'worked_out', \CW\Ui\Html::when($history['computed_at'])) ?><?php endif; ?><?php if ($history['demand_stale']): ?> <?= $chip('needs', \CW\Ui\Words::REORDER['worked_out_old']) ?><?php endif; ?></li>
<?php foreach ($history['own'] as $o): ?>
            <li><?php if ($o['to'] === null): ?><?= $say('REORDER_ENGINE', 'own_from', $channelNames[$o['code']] ?? $o['code'], \CW\Ui\Html::day($o['from'])) ?><?php else: ?><?= $say('REORDER_ENGINE', 'own_to', $channelNames[$o['code']] ?? $o['code'], \CW\Ui\Html::day($o['from']), \CW\Ui\Html::day($o['to'])) ?><?php endif; ?></li>
<?php endforeach; ?>
          </ul>
<?php endif; ?>
<?php if ($stockCw): ?>
          <p class="muted"><?php if ($figures['at'] === null): ?><?= $word('REORDER_ENGINE', 'figures_none') ?><?php else: ?><?= $say('REORDER_ENGINE', 'figures_at', \CW\Ui\Html::when($figures['at'])) ?> <?= $say('REORDER_ENGINE', 'figures_every', $figures['minutes']) ?><?php endif; ?><?php if ($figures['failed']): ?> <?= $chip('needs', \CW\Ui\Words::REORDER_ENGINE['figures_failed']) ?><?php endif; ?></p>
<?php endif; ?>
<?php if ($canManage): ?>
          <form class="inline" method="post" action="/ui/purchasing/reorder/refresh" data-leaves="1">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="items" value="<?= $e(implode(',', array_column($rows, 'sku_id'))) ?>">
            <button class="btn" type="submit" title="<?= $word('REORDER_ENGINE', 'refresh_hint') ?>"><?= $word('REORDER_ENGINE', 'refresh') ?></button>
          </form>
          <form class="inline" method="post" action="/ui/purchasing/reorder/recalculate" data-leaves="1">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <button class="btn" type="submit" title="<?= $word('REORDER', 'recalculate_hint') ?>"><?= $word('REORDER', 'recalculate') ?></button>
          </form>
<?php endif; ?>
        </div>
      </details>
      <a class="menu-item" href="/ui/purchasing/reorder/brands"><?= $word('REORDER', 'brands') ?></a>
      <a class="menu-item" href="/ui/purchasing/reorder/anomalies"><?= $word('REORDER', 'anomalies') ?></a>
      <a class="menu-item" href="/ui/purchasing/sales-history"><?= $word('REORDER', 'sales') ?></a>
    </div>
  </details>
</div>
