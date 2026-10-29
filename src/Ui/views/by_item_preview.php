<?php
// The preview of a bulk action of Products › By Item (docs/decisions.md U125-U126): what would happen to every suggested variant of
// the ticked items, one line each, before anything is saved. The form holds the same rows for the apply (`row_<variant>`: the item,
// the suggestion and the version shown, and whether the line is one of "these N"); its one button is the only thing that saves.
// For "Not a match…" this page is the second step (U110): the red button.
?>
<p class="crumbs"><a href="<?= $e($back) ?>"><?= $word('MENU', 'by_product') ?></a></p>
<?= $head($title, intro: 'by_item_preview') ?>
<div class="alert <?php if ($go === 0): ?>waiting<?php elseif ($danger): ?>blocked confirm-step<?php else: ?>info<?php endif; ?> preview-facts" role="status">
  <p class="alert-title"><?= $e($summary) ?></p>
  <p><?= $e($scope) ?></p>
<?php if ($idle !== null): ?>
  <p><?= $e($idle) ?></p>
<?php endif; ?>
  <p><?= $e($text) ?></p>
<?php if ($go > 0): ?>
  <p><?= $word('BY_ITEM_BULK', 'again') ?></p>
<?php else: ?>
  <p><?= $word('BY_ITEM_BULK', 'none_to_do') ?></p>
<?php endif; ?>
</div>
<form class="by-item-apply" method="post" action="/ui/review/products/bulk/apply">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="action" value="<?= $e($action) ?>">
<?php foreach ($keep as $key => $value): ?>
  <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
<?php endforeach; ?>
  <h2><?= $word('BY_ITEM_BULK', 'lines') ?></h2>
  <div class="table-wrap">
  <table class="stack list board row-strips by-item-preview">
    <thead><tr><th scope="col" class="c-item"><?= $word('BY_ITEM_BULK', 'col_item') ?></th><th scope="col"><?= $word('BY_ITEM_BULK', 'col_store') ?></th><th scope="col"><?= $word('BY_ITEM_BULK', 'col_variant') ?></th><th scope="col"><?= $word('BY_ITEM_BULK', 'col_outcome') ?></th></tr></thead>
    <tbody>
<?php foreach ($lines as $l): ?>
      <tr class="<?= $e($l['tone']) ?>" data-outcome="<?= $e($l['outcome']) ?>">
        <th scope="row" class="c-head"><a class="o-name" href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['item']) ?></a><?php if ($l['item_code'] !== null): ?><span class="o-sub"><?= $e($l['item_code']) ?></span><?php endif; ?><input type="hidden" name="row_<?= $e($l['listing_id']) ?>" value="<?= $e($l['field']) ?>"></th>
        <td data-label="<?= $word('BY_ITEM_BULK', 'col_store') ?>"><?= $e($l['store']) ?></td>
        <td data-label="<?= $word('BY_ITEM_BULK', 'col_variant') ?>"><a href="<?= $u('/ui/review/listing/' . $l['listing_id']) ?>"><?= $e($l['title']) ?></a><?php if ($l['variant'] !== null): ?> <span class="muted small"><?= $say('BY_ITEM_BULK', 'option', (string) $l['variant']) ?></span><?php endif; ?></td>
        <td class="c-wide" data-label="<?= $word('BY_ITEM_BULK', 'col_outcome') ?>"><?= $chip($l['tone'], $l['label']) ?><?php foreach ($l['needs'] as $need): ?> <span class="tag"><?= $e($need) ?></span><?php endforeach; ?><?php if ($l['why'] !== null): ?><span class="why"><?= $e($l['why']) ?></span><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="actions sticky"><?php if ($go > 0): ?><button type="submit" class="btn <?php if ($danger): ?>risky<?php else: ?>primary<?php endif; ?>"><?= $e($button) ?></button> <?php endif; ?><a href="<?= $e($back) ?>"><?= $word('BY_ITEM_BULK', 'back') ?></a></p>
</form>
