<?php
// The preview of Store Products' "Create new warehouse products for these" (docs/decisions.md NF7): for each ticked website
// product, the new warehouse product it would get (the details "Create a new product" takes from the website, 1 sale = 1) and what
// will happen, before anything is saved. The form holds the same rows for the apply (`row_<listing>`: the suggestion and version
// shown, whether the line is one of "these N", the fingerprint of the details shown) and the form's key; its one button saves.
?>
<p class="crumbs"><a href="<?= $e($back) ?>"><?= $word('MENU', 'store_products') ?></a></p>
<?= $head(\CW\Ui\Words::STORE_NEW['title'], intro: 'store_new_preview') ?>
<div class="alert <?php if ($go === 0): ?>waiting<?php else: ?>info<?php endif; ?> preview-facts" role="status">
  <p class="alert-title"><?= $e($summary) ?></p>
  <p><?= $e($store['name']) ?></p>
  <p><?= $word('STORE_NEW', 'text') ?></p>
  <p><?= $word('STORE_NEW', 'skips') ?></p>
<?php if ($go > 0): ?>
  <p><?= $word('STORE_NEW', 'again') ?></p>
<?php else: ?>
  <p><?= $word('STORE_NEW', 'none_to_do') ?></p>
<?php endif; ?>
</div>
<form class="store-new-apply" method="post" action="/ui/review/store/new-items/apply">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="form_key" value="<?= $e($form_key) ?>">
<?php foreach ($keep as $key => $value): ?>
  <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
<?php endforeach; ?>
  <h2><?= $word('STORE_NEW', 'lines') ?></h2>
  <div class="table-wrap">
  <table class="stack list board row-strips store-new-preview">
    <thead><tr><th scope="col" class="c-item"><?= $word('STORE_NEW', 'col_product') ?></th><th scope="col"><?= $word('STORE_NEW', 'col_outcome') ?></th><th scope="col"><?= $word('STORE_NEW', 'col_new') ?></th></tr></thead>
    <tbody>
<?php foreach ($lines as $l): ?>
      <tr class="<?= $e($l['tone']) ?>" data-outcome="<?= $e($l['outcome']) ?>">
        <th scope="row" class="c-head"><a class="o-name" href="<?= $u('/ui/review/listing/' . $l['listing_id']) ?>"><?= $e($l['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?></a><?php if ($l['variant_title'] !== null): ?><span class="o-sub"><?= $e($l['variant_title']) ?></span><?php endif; ?><span class="o-sub"><?php if ($l['brand'] !== null): ?><?= $e($l['brand']) ?> · <?php endif; ?><?= $say('QUEUE', 'option', (string) $l['variant']) ?></span><input type="hidden" name="row_<?= $e($l['listing_id']) ?>" value="<?= $e($l['field']) ?>"></th>
        <td data-label="<?= $word('STORE_NEW', 'col_outcome') ?>"><?= $chip($l['tone'], $l['label']) ?><?php foreach ($l['needs'] as $need): ?> <span class="tag"><?= $e($need) ?></span><?php endforeach; ?><?php if ($l['why'] !== null): ?><span class="why"><?= $e($l['why']) ?></span><?php endif; ?></td>
        <td class="c-wide" data-label="<?= $word('STORE_NEW', 'col_new') ?>">
<?php if ($l['card'] === null): ?>
          <span class="muted"><?= $word('STORE_NEW', 'no_card') ?></span>
<?php else: ?>
          <dl class="new-card<?php if ($l['outcome'] === 'skipped'): ?> muted<?php endif; ?>">
<?php foreach ($l['card'] as [$label, $value]): ?>
            <dt><?= $e($label) ?></dt><dd><?= $e($value) ?></dd>
<?php endforeach; ?>
          </dl>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="actions sticky"><?php if ($go > 0): ?><button type="submit" class="btn primary"><?= $e($button) ?></button> <?php endif; ?><a href="<?= $e($back) ?>"><?= $word('STORE_NEW', 'back') ?></a></p>
</form>
