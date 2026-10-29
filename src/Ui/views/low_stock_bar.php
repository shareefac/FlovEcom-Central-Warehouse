<?php
// Low-stock warnings' action bar (docs/decisions.md W9.12): "Work out again" (the shown products' figures now) under More; the page's main
// action is in the bar at the bottom of its list. $rows: the products shown.
?>
<div class="doc-actions" role="group" aria-label="<?= $word('DOC', 'actions') ?>">
  <details class="more">
    <summary class="btn"><?= $word('DOC', 'more') ?></summary>
    <div class="more-menu">
      <form class="inline" method="post" action="/ui/purchasing/reorder/refresh" data-leaves="1">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="items" value="<?= $e(implode(',', array_column($rows, 'sku_id'))) ?>">
        <input type="hidden" name="back" value="low-stock">
        <button class="btn" type="submit" title="<?= $word('REORDER_ENGINE', 'refresh_hint') ?>"><?= $word('REORDER_ENGINE', 'refresh') ?></button>
      </form>
    </div>
  </details>
</div>
