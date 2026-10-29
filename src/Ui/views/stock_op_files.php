<?php
// A stock record's files (pack A1; docs/decisions.md W8): the list, then the form that adds one (multipart, its own route as before), for
// whoever keeps records of its kind while it is not stopped. On the record page it is the paper card's left column; on a draft it follows
// the lines. $files, $canFile, $fileRoles, $maxMb, $action (the files route).
?>
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
  <form class="record doc-drop" method="post" enctype="multipart/form-data" data-leaves="1" action="<?= $u($action) ?>">
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
