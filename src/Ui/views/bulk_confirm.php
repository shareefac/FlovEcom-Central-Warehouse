<p class="crumbs"><a href="<?= $e($back[0]) ?>"><?= $e($back[1]) ?></a></p>
<h1><?= $e($title) ?></h1>
<?= $intro('bulk_confirm') ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<div class="alert blocked confirm-step" role="note">
  <p class="alert-title"><?= $e($title) ?></p>
  <p><?= $e($text) ?></p>
  <p><?= $word('BULK_CONFIRM', 'skips') ?></p>
</div>
<form class="record bulk-confirm" method="post" action="/ui/review/bulk">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="source" value="<?= $e($source) ?>">
  <input type="hidden" name="action" value="<?= $e($action) ?>">
  <input type="hidden" name="confirm" value="1">
<?php foreach ($keep as $key => $value): ?>
  <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
<?php endforeach; ?>
<?php foreach ($rows as $r): ?>
  <input type="hidden" name="pick_<?= $e($r['listing_id']) ?>" value="1"><input type="hidden" name="ver_<?= $e($r['listing_id']) ?>" value="<?= $e($r['map_version']) ?>"><input type="hidden" name="prop_<?= $e($r['listing_id']) ?>" value="<?= $e($r['proposal_id']) ?>">
<?php endforeach; ?>
<?php if ($needs_reason): ?>
  <label for="bulk-reason"><span><?= $word('BULK_CONFIRM', 'reason') ?> <span class="hint"><?= $word('BULK_CONFIRM', 'reason_hint') ?></span></span></label>
  <input type="text" id="bulk-reason" name="reason" maxlength="<?= $e($max_reason) ?>" value="<?= $e($reason) ?>" required<?php if ($error !== null): ?> aria-invalid="true"<?php endif; ?>>
<?php endif; ?>
  <p class="actions"><button type="submit" class="danger"><?= $e($button) ?></button> <a href="<?= $e($back[0]) ?>"><?= $word('BULK_CONFIRM', 'cancel') ?></a></p>
</form>
<h2><?= $say('BULK_CONFIRM', 'rows', $count) ?></h2>
<div class="table-wrap">
<table class="stack list bulk-rows">
  <thead><tr><th scope="col"><?= $word('QUEUE', 'product') ?></th><th scope="col"><?= $word('QUEUE', 'website') ?></th></tr></thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row" class="c-head"><a class="o-name" href="/ui/review/listing/<?= $e($r['listing_id']) ?>"><?= $e($r['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?></a></th>
      <td data-label="<?= $word('QUEUE', 'website') ?>"><?= $e($r['store'] ?? '') ?><?php if ($r['variant'] !== null): ?> <span class="muted small"><?= $say('QUEUE', 'option', (string) $r['variant']) ?></span><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
