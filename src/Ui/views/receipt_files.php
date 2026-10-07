<section class="card receipt-files" aria-labelledby="files-h">
  <h2 id="files-h"><?= $word('RECEIPT', 'files') ?></h2>
<?php if ($files === []): ?>
  <p class="hint"><?= $word('RECEIPT', 'files_none') ?></p>
<?php else: ?>
  <ul class="plain files">
<?php foreach ($files as $f): ?>
    <li><a href="<?= $u('/ui/files/' . $f['id']) ?>"><?= $e($f['original_name']) ?></a> <?= $chip('info', (string) $f['role_word']) ?>
      <span class="o-sub"><?= $say('RECEIPT', 'file_line', (string) $f['kind'], (string) $f['size'], \CW\Ui\Html::when((string) $f['attached_at'])) ?></span></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($canAttach): ?>
  <form class="upload" method="post" enctype="multipart/form-data" action="<?= $u('/ui/receiving/' . $doc->id . '/files') ?>" data-leaves="1" data-busy-text="<?= $word('RECEIPT', 'busy') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="back" value="<?= $e($back) ?>">
    <label><?= $word('RECEIPT', 'file_what') ?>
      <select name="role" required>
<?php foreach ($fileRoles as $role => $label): ?>
        <option value="<?= $e($role) ?>"<?php if ($back === 'bench' && $role === 'photo'): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label><?= $say('RECEIPT', 'file_label', $maxMb) ?> <input type="file" name="file" accept="application/pdf,image/jpeg,image/png"<?php if ($back === 'bench'): ?> capture="environment"<?php endif; ?> data-max-bytes="2097152" data-too-big="<?= $say('RECEIPT', 'too_big', $maxMb) ?>" required></label>
    <label><?= $word('RECEIPT', 'file_note') ?> <input type="text" name="note" maxlength="255"></label>
    <p class="actions"><button type="submit"><?= $word('RECEIPT', 'attach') ?></button></p>
  </form>
  <p class="hint"><?= $word('RECEIPT', 'files_kept') ?></p>
<?php endif; ?>
</section>
