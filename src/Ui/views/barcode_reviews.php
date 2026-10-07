<h1><?= $word('MENU', 'barcodes') ?></h1>
<?= $intro('barcodes') ?>
<?php if ($error !== null && $errorId === null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<nav class="tabs" aria-label="<?= $word('MENU', 'barcodes') ?>">
  <a href="/ui/items/barcodes"<?php if ($show === 'open'): ?> aria-current="page"<?php endif; ?>><?= $say('BARCODE', 'open', $open) ?></a>
  <a href="<?= $u('/ui/items/barcodes', ['show' => 'decided']) ?>"<?php if ($show === 'decided'): ?> aria-current="page"<?php endif; ?>><?= $word('BARCODE', 'decided') ?></a>
</nav>
<form class="filters" method="get" action="/ui/items/barcodes">
<?php if ($show === 'decided'): ?>
  <input type="hidden" name="show" value="decided">
<?php endif; ?>
  <label><?= $word('BARCODE', 'find') ?> <input type="search" name="barcode" value="<?= $e($barcode) ?>" inputmode="numeric" maxlength="40"></label>
  <button type="submit"><?= $word('BARCODE', 'find_button') ?></button>
</form>
<?php if ($rows === []): ?>
<?php if ($show === 'open'): ?>
<?= $empty(\CW\Ui\Words::BARCODE['nothing'], \CW\Ui\Words::BARCODE['nothing_text']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::BARCODE['nothing_decided']) ?>
<?php endif; ?>
<?php endif; ?>
<?php foreach ($rows as $r): ?>
<article class="card barcode-review" id="review-<?= $e($r['id']) ?>" aria-labelledby="br-<?= $e($r['id']) ?>">
  <h2 id="br-<?= $e($r['id']) ?>"><?= $e($r['barcode']) ?> <?= $chip($r['reason'] === 'on_another_item' ? 'blocked' : ($r['reason'] === 'multipack_listing' ? 'needs' : 'info'), (string) $r['reasonLabel']) ?></h2>
<?php if ($errorId === (int) $r['id'] && $error !== null): ?>
  <p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
  <dl>
<?php if ($r['holder_sku_id'] !== null && $r['reason'] !== 'removed'): ?>
    <dt><?= $word('BARCODE', 'on_product') ?></dt>
    <dd><a href="<?= $u('/ui/items/' . $r['holder_sku_id']) ?>"><?= $e($r['holder_code']) ?></a> <?= $e($r['holder_name']) ?> <span class="muted"><?= $say('BARCODE', 'in_stock', (int) $r['holder_stock']) ?></span><?php if ($r['holder_merged'] !== null): ?> <span class="tag warn"><?= $say('BARCODE', 'joined', (string) $r['holder_merged_code']) ?></span><?php endif; ?></dd>
<?php if ($r['holder_merged_into_claimant'] && $r['status'] === 'open'): ?>
    <dd class="note"><?= $word('BARCODE', 'joined_note') ?></dd>
<?php endif; ?>
<?php endif; ?>
    <dt><?php if ($r['reason'] === 'removed'): ?><?= $word('BARCODE', 'removed_from') ?><?php else: ?><?= $word('BARCODE', 'carried_by') ?><?php endif; ?></dt>
    <dd><a href="<?= $u('/ui/items/' . $r['claimant_sku_id']) ?>"><?= $e($r['claimant_code']) ?></a> <?= $e($r['claimant_name']) ?> <span class="muted"><?= $say('BARCODE', 'in_stock', (int) $r['claimant_stock']) ?></span></dd>
<?php if ($r['listing_id'] !== null): ?>
    <dt><?= $word('BARCODE', 'website_product') ?></dt>
    <dd><?= $e($r['variant_title'] ?? $r['product_title']) ?> <span class="muted"><?= $say('BARCODE', 'listing_line', (string) $r['site'], \CW\Ui\Words::say('SALES', 'option', (string) $r['external_variant_id']), (string) $r['units_per_item'], (string) $r['listing_state']) ?></span></dd>
<?php endif; ?>
    <dt><?= $word('BARCODE', 'now') ?></dt>
    <dd><?php if ($r['now_sku_id'] === null): ?><?= $word('BARCODE', 'now_none') ?><?php else: ?><?= $say('BARCODE', 'now_line', (string) $r['now_code'], (string) $r['now_units'], (int) $r['now_usable'] === 1 ? \CW\Ui\Words::BARCODE['can_use'] : \CW\Ui\Words::BARCODE['cannot_use']) ?><?php endif; ?></dd>
    <dt><?= $word('BARCODE', 'found') ?></dt>
    <dd><?php if ($r['opened_by_name'] === null): ?><?= $say('BARCODE', 'found_cw', \CW\Ui\Html::when((string) $r['created_at'])) ?><?php else: ?><?= $say('BARCODE', 'found_line', \CW\Ui\Html::when((string) $r['created_at']), (string) $r['opened_by_name']) ?><?php endif; ?></dd>
<?php if ($r['status'] === 'decided'): ?>
    <dt><?= $word('BARCODE', 'decision') ?></dt>
    <dd><?= $e($r['decisionLabel']) ?><?php if ($r['decided_units'] !== null): ?> (<?= $say('BARCODE', 'per_scan', (string) $r['decided_units']) ?>)<?php endif; ?> · <?= $when($r['decided_at']) ?> · <?= $e($r['decided_by_name'] ?? \CW\Ui\Words::BARCODE['set_up']) ?><?php if ($r['note'] !== null): ?> · <?= $e($r['note']) ?><?php endif; ?></dd>
<?php endif; ?>
  </dl>
<?php if ($r['status'] === 'open'): ?>
  <form class="record barcode-decide" method="post" action="<?= $u('/ui/items/barcodes/' . $r['id'] . '/decide') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($r['formKey']) ?>">
    <fieldset>
      <legend><?= $word('BARCODE', 'decide') ?></legend>
<?php foreach ($r['choices'] as $code => $label): ?>
      <label class="choice"><input type="radio" name="decision" value="<?= $e($code) ?>"<?php if ($code === 'move' && $r['holder_merged_into_claimant']): ?> checked<?php endif; ?>> <?= $e($label) ?></label>
<?php endforeach; ?>
<?php if ($r['reason'] === 'multipack_listing' || $r['reason'] === 'on_another_item'): ?>
      <label for="units-<?= $e($r['id']) ?>"><?= $word('BARCODE', 'units_label') ?> <span class="hint"><?php if ($r['reason'] === 'multipack_listing'): ?><?= $say('BARCODE', 'units_hint_listing', (string) $r['units_per_item']) ?><?php else: ?><?= $word('BARCODE', 'units_hint_now') ?><?php endif; ?></span></label>
      <input id="units-<?= $e($r['id']) ?>" class="units" type="number" name="units" min="1" max="<?= $e($maxUnits) ?>" step="1" inputmode="numeric">
<?php endif; ?>
      <label for="note-<?= $e($r['id']) ?>"><?= $word('BARCODE', 'note') ?></label>
      <input id="note-<?= $e($r['id']) ?>" type="text" name="note" maxlength="500">
    </fieldset>
    <p class="actions"><button type="submit" class="primary"><?= $word('BARCODE', 'save_decision') ?></button></p>
  </form>
<?php endif; ?>
</article>
<?php endforeach; ?>
<?php if (count($rows) >= $limit): ?>
<p class="muted"><?= $say('BARCODE', 'limit', $limit) ?></p>
<?php endif; ?>
