<h1>Barcode review</h1>
<p class="muted">What the barcode sync could not decide alone: a barcode a site listing carries that is already another item's (it is unusable until
  decided), or a new barcode of a multipack listing (is it the pack's or the single unit's?). Nothing moves until you decide.</p>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<div class="crumbs">
  <p class="actions">
    <a href="/ui/items/barcodes"<?php if ($show === 'open'): ?> aria-current="page"<?php endif; ?>>Open (<?= $n($open) ?>)</a>
    <a href="<?= $u('/ui/items/barcodes', ['show' => 'decided']) ?>"<?php if ($show === 'decided'): ?> aria-current="page"<?php endif; ?>>Decided</a>
  </p>
  <form class="filters" method="get" action="/ui/items/barcodes">
<?php if ($show === 'decided'): ?>
    <input type="hidden" name="show" value="decided">
<?php endif; ?>
    <label>Barcode <input type="search" name="barcode" value="<?= $e($barcode) ?>" inputmode="numeric" maxlength="40"></label>
    <button type="submit">Find</button>
  </form>
</div>
<?php if ($rows === []): ?>
<p class="note"><?php if ($show === 'open'): ?>Nothing to decide.<?php else: ?>Nothing decided yet.<?php endif; ?></p>
<?php endif; ?>
<?php foreach ($rows as $r): ?>
<article class="card barcode-review" id="review-<?= $e($r['id']) ?>" aria-labelledby="br-<?= $e($r['id']) ?>">
  <h2 id="br-<?= $e($r['id']) ?>"><?= $e($r['barcode']) ?> <span class="tag<?php if ($r['reason'] === 'on_another_item'): ?> bad<?php elseif ($r['reason'] === 'multipack_listing'): ?> warn<?php endif; ?>"><?= $e($r['reasonLabel']) ?></span></h2>
<?php if ($errorId === (int) $r['id'] && $error !== null): ?>
  <p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
  <dl>
<?php if ($r['holder_sku_id'] !== null && $r['reason'] !== 'removed'): ?>
    <dt>On item</dt>
    <dd><a href="<?= $u('/ui/items/' . $r['holder_sku_id']) ?>"><?= $e($r['holder_code']) ?></a> <?= $e($r['holder_name']) ?> <span class="muted">(<?= $n($r['holder_stock']) ?> in stock)</span><?php if ($r['holder_merged'] !== null): ?> <span class="tag warn">merged into <?= $e($r['holder_merged_code']) ?></span><?php endif; ?></dd>
<?php if ($r['holder_merged_into_claimant'] && $r['status'] === 'open'): ?>
    <dd class="note">This item was merged into the listing's item, and the barcode came with it: moving it there is the usual answer (chosen below).</dd>
<?php endif; ?>
<?php endif; ?>
    <dt><?php if ($r['reason'] === 'removed'): ?>Removed from<?php else: ?>Carried by a listing of<?php endif; ?></dt>
    <dd><a href="<?= $u('/ui/items/' . $r['claimant_sku_id']) ?>"><?= $e($r['claimant_code']) ?></a> <?= $e($r['claimant_name']) ?> <span class="muted">(<?= $n($r['claimant_stock']) ?> in stock)</span></dd>
<?php if ($r['listing_id'] !== null): ?>
    <dt>Listing</dt>
    <dd><?= $e($r['channel']) ?> <?= $e($r['external_variant_id']) ?> <?= $e($r['variant_title'] ?? $r['product_title']) ?> <span class="muted">(<?= $e($r['units_per_item']) ?> per item when found; <?= $e($r['listing_status']) ?> now)</span></dd>
<?php endif; ?>
    <dt>Now</dt>
    <dd><?php if ($r['now_sku_id'] === null): ?>no item has it<?php else: ?>on <a href="<?= $u('/ui/items/' . $r['now_sku_id']) ?>"><?= $e($r['now_code']) ?></a>, <?= $e($r['now_units']) ?> per scan, <?php if ((int) $r['now_usable'] === 1): ?>usable<?php else: ?>unusable<?php endif; ?><?php endif; ?></dd>
    <dt>Found</dt>
    <dd><?= $dt($r['created_at']) ?> <span class="muted">by <?= $e($r['opened_by_name'] ?? $r['opened_actor']) ?></span></dd>
<?php if ($r['status'] === 'decided'): ?>
    <dt>Decided</dt>
    <dd><?= $e($r['decisionLabel']) ?><?php if ($r['decided_units'] !== null): ?> (<?= $e($r['decided_units']) ?> per scan)<?php endif; ?> · <?= $dt($r['decided_at']) ?> · <?= $e($r['decided_by_name'] ?? $r['decided_actor']) ?><?php if ($r['note'] !== null): ?> · <?= $e($r['note']) ?><?php endif; ?></dd>
<?php endif; ?>
  </dl>
<?php if ($r['status'] === 'open'): ?>
  <form class="record barcode-decide" method="post" action="<?= $u('/ui/items/barcodes/' . $r['id'] . '/decide') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($r['formKey']) ?>">
    <fieldset>
      <legend>Decide</legend>
<?php foreach ($r['decisions'] as $code => $label): ?>
      <label class="choice"><input type="radio" name="decision" value="<?= $e($code) ?>"<?php if ($code === 'move' && $r['holder_merged_into_claimant']): ?> checked<?php endif; ?>> <?= $e($label) ?></label>
<?php endforeach; ?>
<?php if ($r['reason'] === 'multipack_listing' || $r['reason'] === 'on_another_item'): ?>
      <label for="units-<?= $e($r['id']) ?>">Units per scan <span class="muted">(when it is added or moved; empty: <?php if ($r['reason'] === 'multipack_listing'): ?>the listing's <?= $e($r['units_per_item']) ?><?php else: ?>as it is now<?php endif; ?>)</span></label>
      <input id="units-<?= $e($r['id']) ?>" class="units" type="number" name="units" min="1" max="<?= $e($maxUnits) ?>" step="1" inputmode="numeric">
<?php endif; ?>
      <label for="note-<?= $e($r['id']) ?>">Note <span class="muted">(optional)</span></label>
      <input id="note-<?= $e($r['id']) ?>" type="text" name="note" maxlength="500">
    </fieldset>
    <p class="actions"><button type="submit">Save the decision</button></p>
  </form>
<?php endif; ?>
</article>
<?php endforeach; ?>
<?php if (count($rows) >= $limit): ?>
<p class="muted">The first <?= $n($limit) ?> are shown.</p>
<?php endif; ?>
