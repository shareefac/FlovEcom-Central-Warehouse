<?php
// A return's files (docs/decisions.md W9.6): on the left under its lines, as a document's notes and files are (.doc-notes): the list, then
// for who may add one the upload in the dashed drop area. $base, $doc, $files, $canFile, $fileRoles, $maxMb.
?>
<h3 class="doc-label" id="files-h"><?= $word('STOCK_OPS', 'files') ?></h3>
<?php if ($files === []): ?>
<p class="muted"><?= $word('STOCK_OPS', 'no_files') ?></p>
<?php else: ?>
<ul class="files">
<?php foreach ($files as $file): ?>
  <li><a href="<?= $u('/ui/files/' . $file['id']) ?>"><?= $e($file['original_name']) ?></a> <span class="muted"><?= $e($file['what']) ?> · <?= $e($file['kind']) ?> · <?= $e($file['size']) ?></span></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if ($canFile): ?>
<form class="record doc-drop" method="post" enctype="multipart/form-data" action="<?= $u($base . '/' . $doc->id . '/files') ?>" data-leaves="1">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <label><?= $word('STOCK_OPS', 'file_role') ?>
    <select name="role">
<?php foreach ($fileRoles as $code => $label): ?>
      <option value="<?= $e($code) ?>"><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label><?= $word('STOCK_OPS', 'file') ?> <span class="hint"><?= $say('STOCK_OPS', 'file_hint', $maxMb) ?></span><input type="file" name="file" accept="application/pdf,image/jpeg,image/png" required></label>
  <p class="actions"><button class="btn" type="submit"><?= $word('STOCK_OPS', 'file_button') ?></button></p>
</form>
<?php endif; ?>
