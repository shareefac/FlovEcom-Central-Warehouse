<p class="crumbs"><a href="/ui/receiving/bench">Goods-in bench</a> <a href="<?= $u('/ui/receiving/' . $doc->id) ?>">The receipt</a></p>
<h1>Bench check <span class="muted"><?= $e($supplier['code'] ?? '') ?> <?= $e($supplier['name'] ?? '') ?></span></h1>
<p class="muted">Invoice <?= $e($doc->externalRef ?? 'not keyed yet') ?> (receipt #<?= $n($doc->id) ?>). <?= $n($count) ?> lines<?php if ($count > $pageSize): ?>, shown <?= $n($pageSize) ?> at a time<?php endif; ?>.
<?php if ($checkedBy !== null): ?> Last checked by <?= $e($checkedBy) ?>.<?php endif; ?></p>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<p class="note">This delivery counts as received on <strong><?= $e($receivedLabel) ?></strong> (UK time): the duty-stamp rule goes by this date.
<?php if ($refusal): ?>
  It arrived on or after <?= $e($cutoffDay ?? 'the refusal date') ?>, so every unstamped duty-liable unit is refused at the door or quarantined: it is never accepted.
<?php else: ?>
  Delivered up to <?= $e($lastDay) ?>: unstamped duty-liable stock is accepted only with the supplier's evidence that it was made or imported before
  1 Oct 2026 (and must be sold, returned or destroyed by 31 Mar 2027); anything made or imported later that arrives unstamped is refused or quarantined.
  From <?= $e($cutoffDay) ?>: every unstamped delivery is refused at the door or quarantined.
<?php endif; ?></p>

<form class="bench" method="post" action="<?= $u('/ui/receiving/' . $doc->id . '/bench') ?>" data-unsaved="the bench check">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
  <input type="hidden" name="from" value="<?= $e($from) ?>">
  <input type="hidden" name="bench_was" value="<?= $e($stamps['checklist']) ?>">
  <fieldset class="bench-header">
    <legend>The delivery</legend>
    <label>Supplier and paperwork credible (the invoice and delivery note match the goods and the supplier)
      <select name="paperwork_ok">
        <option value="">Not checked yet</option>
        <option value="1"<?php if ((string) ($typed['paperwork_ok'] ?? ($gr['paperwork_ok'] ?? '')) === '1'): ?> selected<?php endif; ?>>Yes, credible</option>
        <option value="0"<?php if ((string) ($typed['paperwork_ok'] ?? ($gr['paperwork_ok'] ?? '')) === '0'): ?> selected<?php endif; ?>>No: do not post (refuse the delivery)</option>
      </select>
    </label>
    <label>Note <input type="text" name="bench_note" maxlength="500" value="<?= $e($typed['bench_note'] ?? ($gr['bench_note'] ?? '')) ?>"></label>
<?php if (!$receivedToday): ?>
    <label class="choice"><input type="checkbox" name="arrived_now" value="1"<?php if (($typed['arrived_now'] ?? '') === '1'): ?> checked<?php endif; ?>> The goods arrived now, not on <?= $e($receivedDay) ?> (the desk keyed the receipt before they came)</label>
<?php endif; ?>
  </fieldset>

  <fieldset class="bench-tools">
    <legend>Find a line</legend>
    <label>Scan or type a barcode, this supplier's code or a CW code <input type="search" id="bench-find" maxlength="64" autocomplete="off" inputmode="numeric" data-find-in=".bench-line"></label>
    <span class="muted" id="bench-find-result" role="status"></span>
    <p class="actions"><button type="button" data-fill-stamps="1">Every duty line here: stamp on the pack, digital</button>
      <button type="button" data-tick-all="1">Tick every line on this page as counted</button></p>
    <p class="muted">The buttons only fill the form: nothing is saved until "Save the check". A line counts as checked once it is ticked or one of its
      answers changed.</p>
  </fieldset>

<?php foreach ($lines as $no => $l): ?>
<?php $over = (int) ($typed['b_' . $no . '_over'] ?? $l['over_units']); ?>
  <article class="bench-line card<?php if ($l['checked_at'] === null): ?> unchecked<?php endif; ?>" id="line-<?= $e($no) ?>" data-codes="<?= $e(implode(' ', array_filter([$l['sku_code'], $l['supplier_code'], ...($barcodes[(int) $l['sku_id']]['codes'] ?? [])]))) ?>">
    <input type="hidden" name="b_<?= $e($no) ?>_present" value="<?= $e($stamps['identity'][$no] ?? '') ?>">
    <input type="hidden" name="b_<?= $e($no) ?>_was" value="<?= $e($stamps['lines'][$no] ?? '') ?>">
    <h2>Line <?= $n($no) ?>: <?= $e($l['sku_code']) ?> <span class="muted"><?= $e($l['sku_name']) ?></span></h2>
    <p class="expect"><strong><?= $e($l['packs_text']) ?> = <?= $n($l['units']) ?> units</strong> on the paperwork<?php if ($l['supplier_code'] !== null): ?> &middot; supplier code <?= $e($l['supplier_code']) ?><?php endif; ?>
<?php if (($barcodes[(int) $l['sku_id']]['labels'] ?? []) !== []): ?> &middot; barcode <?= $e(implode(', ', $barcodes[(int) $l['sku_id']]['labels'])) ?><?php endif; ?>
      <?php if ($l['stamp_req']): ?><span class="tag">duty stamp required</span><?php else: ?><span class="tag ok">no duty stamp</span><?php endif; ?><?php if ($l['checked_at'] !== null): ?> <span class="tag ok">counted</span><?php endif; ?></p>
<?php foreach ($l['problems'] as $p): ?>
    <p class="field-error"><?= $e($p) ?></p>
<?php endforeach; ?>
    <label class="choice counted"><input type="checkbox" name="b_<?= $e($no) ?>_ok" value="1"<?php if (isset($typed['b_' . $no . '_present']) ? ($typed['b_' . $no . '_ok'] ?? '') === '1' : $l['checked_at'] !== null): ?> checked<?php endif; ?>> Counted: <?= $n($l['units']) ?> units as on the paperwork, or the differences below</label>
<?php if ($l['stamp_req']): ?>
    <fieldset class="stamp">
      <legend>Duty stamp</legend>
      <label>On the outer retail pack, sealing it
        <select name="b_<?= $e($no) ?>_stamp" data-stamp="1">
          <option value="">Not checked yet</option>
          <option value="1"<?php if ((string) ($typed['b_' . $no . '_stamp'] ?? ($l['stamp_on_pack'] ?? '')) === '1'): ?> selected<?php endif; ?>>Yes (count any unstamped units below)</option>
          <option value="0"<?php if ((string) ($typed['b_' . $no . '_stamp'] ?? ($l['stamp_on_pack'] ?? '')) === '0'): ?> selected<?php endif; ?>>No: none of them is stamped</option>
        </select>
      </label>
      <label>Stamp type
        <select name="b_<?= $e($no) ?>_type" data-stamp-type="1">
          <option value="">Choose</option>
<?php foreach ($stampTypes as $code => $label): ?>
          <option value="<?= $e($code) ?>"<?php if (($typed['b_' . $no . '_type'] ?? ($l['stamp_type'] ?? '')) === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label>Scanned stamp code (optional) <input type="text" name="b_<?= $e($no) ?>_code" maxlength="128" autocomplete="off" data-enter="next" value="<?= $e($typed['b_' . $no . '_code'] ?? ($l['stamp_code'] ?? '')) ?>"></label>
    </fieldset>
<?php endif; ?>
    <fieldset class="exceptions">
      <legend>What arrived differently (units)</legend>
      <label>Short <input type="number" name="b_<?= $e($no) ?>_short" min="0" inputmode="numeric" value="<?= $e($typed['b_' . $no . '_short'] ?? $l['short_units']) ?>"></label>
      <label>Over <input type="number" name="b_<?= $e($no) ?>_over" min="0" inputmode="numeric" value="<?= $e($typed['b_' . $no . '_over'] ?? $l['over_units']) ?>"></label>
<?php if ($over > $l['units']): ?>
      <label class="choice"><input type="checkbox" name="b_<?= $e($no) ?>_overok" value="1"<?php if (($typed['b_' . $no . '_overok'] ?? ($over === (int) $l['over_units'] ? '1' : '')) === '1'): ?> checked<?php endif; ?>> The over count is right (more than the paperwork itself)</label>
<?php endif; ?>
      <label>Damaged <input type="number" name="b_<?= $e($no) ?>_damaged" min="0" inputmode="numeric" value="<?= $e($typed['b_' . $no . '_damaged'] ?? $l['damaged_units']) ?>"></label>
      <label>Wrong item <input type="number" name="b_<?= $e($no) ?>_wrong" min="0" inputmode="numeric" value="<?= $e($typed['b_' . $no . '_wrong'] ?? $l['wrong_item_units']) ?>"> <span class="muted">(name what came instead in the note above, and photograph it)</span></label>
<?php if ($l['stamp_req']): ?>
      <label>Unstamped <input type="number" name="b_<?= $e($no) ?>_unstamped" min="0" inputmode="numeric" data-unstamped="1" value="<?= $e($typed['b_' . $no . '_unstamped'] ?? $l['unstamped_units']) ?>"></label>
      <div class="unstamped-only" data-unstamped-only="1">
      <label>The unstamped units
        <select name="b_<?= $e($no) ?>_action">
          <option value="">Choose (when some are unstamped)</option>
<?php foreach ($actions as $code => $label): ?>
<?php if ($code !== 'accept_pre_october' || !$refusal): ?>
          <option value="<?= $e($code) ?>"<?php if (($typed['b_' . $no . '_action'] ?? ($l['unstamped_action'] ?? '')) === $code): ?> selected<?php endif; ?>><?= $e($label) ?><?php if ($code === 'accept_pre_october'): ?> (sold, returned or destroyed by 31 Mar 2027)<?php endif; ?></option>
<?php endif; ?>
<?php endforeach; ?>
        </select>
      </label>
<?php if (!$refusal): ?>
      <label class="wide">The supplier's evidence of manufacture before 1 Oct 2026 (to accept unstamped units)
        <textarea name="b_<?= $e($no) ?>_evidence" maxlength="500" rows="2"><?= $e($typed['b_' . $no . '_evidence'] ?? ($l['pre_october_evidence'] ?? '')) ?></textarea></label>
<?php endif; ?>
      <p class="muted">With unstamped units refused or quarantined (or no stamp on the pack), the line's damaged and over units go with them: they are
        unstamped too.</p>
      </div>
<?php endif; ?>
    </fieldset>
  </article>
<?php endforeach; ?>
  <p class="actions sticky">
    <button type="submit" name="next" value="0" class="primary">Save the check</button>
<?php if ($from + $pageSize <= $count): ?>
    <button type="submit" name="next" value="1">Save and show the next <?= $n($pageSize) ?> lines</button>
<?php endif; ?>
  </p>
</form>
<?php if ($count > $pageSize): ?>
<p class="pager"><?php if ($from > 1): ?><a href="<?= $u('/ui/receiving/' . $doc->id . '/bench', ['from' => max(1, $from - $pageSize)]) ?>">Previous lines</a><?php endif; ?>
<?php if ($from + $pageSize <= $count): ?><a href="<?= $u('/ui/receiving/' . $doc->id . '/bench', ['from' => $from + $pageSize]) ?>">Next lines</a><?php endif; ?></p>
<?php endif; ?>

<section aria-labelledby="photos-h">
  <h2 id="photos-h">Photos and evidence</h2>
<?php if ($photos === []): ?>
  <p class="muted">None yet. Photograph damage, a missing or misplaced stamp, a wrong item, and the supplier's certificate for pre-October stock. Save the
    check first: attaching a photo leaves this page.</p>
<?php else: ?>
  <ul class="plain">
<?php foreach ($photos as $ph): ?>
    <li><a href="<?= $u('/ui/files/' . $ph['id']) ?>"><?= $e($ph['original_name']) ?></a> <span class="tag"><?= $e(str_replace('_', ' ', $ph['role'])) ?></span></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
  <form class="upload" method="post" enctype="multipart/form-data" action="<?= $u('/ui/receiving/' . $doc->id . '/files') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="back" value="bench">
    <label>What it is
      <select name="role">
        <option value="photo">Photo</option>
        <option value="evidence">Duty evidence (made before 1 Oct 2026)</option>
        <option value="delivery_note">Delivery note</option>
      </select>
    </label>
    <label>Photo or file (JPEG, PNG or PDF; at most 2 MiB: a camera photo is made smaller first) <input type="file" name="file" accept="image/jpeg,image/png,application/pdf" capture="environment" data-max-bytes="2097152" required></label>
    <label>Note <input type="text" name="note" maxlength="255"></label>
    <button type="submit">Attach</button>
  </form>
</section>
