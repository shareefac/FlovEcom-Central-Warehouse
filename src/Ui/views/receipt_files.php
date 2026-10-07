<section aria-labelledby="files-h">
  <h2 id="files-h">Files</h2>
<?php if ($files === []): ?>
  <p class="muted">No file yet. The supplier's invoice (a PDF, or a photo of a paper invoice) must be attached before the receipt is posted.</p>
<?php else: ?>
  <ul class="plain files">
<?php foreach ($files as $f): ?>
    <li><a href="<?= $u('/ui/files/' . $f['id']) ?>"><?= $e($f['original_name']) ?></a> <span class="tag"><?= $e(str_replace('_', ' ', $f['role'])) ?></span>
      <span class="muted"><?= $e($f['mime']) ?>, <?= $n($f['size_bytes']) ?> bytes, <?= $uk($f['attached_at']) ?></span></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($canAttach): ?>
  <form class="upload" method="post" enctype="multipart/form-data" action="<?= $u('/ui/receiving/' . $doc->id . '/files') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="back" value="<?= $e($back) ?>">
    <label>What it is
      <select name="role" required>
<?php foreach ($fileRoles as $role => $label): ?>
        <option value="<?= $e($role) ?>"<?php if ($back === 'bench' && $role === 'photo'): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label>File (PDF, JPEG or PNG; at most 2 MiB: a camera photo is made smaller first) <input type="file" name="file" accept="application/pdf,image/jpeg,image/png"<?php if ($back === 'bench'): ?> capture="environment"<?php endif; ?> data-max-bytes="2097152" required></label>
    <label>Note <input type="text" name="note" maxlength="255"></label>
    <button type="submit">Attach</button>
  </form>
  <p class="muted">Files are kept at least 7 years and never removed.</p>
<?php endif; ?>
</section>
