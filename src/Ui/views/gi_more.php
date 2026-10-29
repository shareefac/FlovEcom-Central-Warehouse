<?php
// A delivery's other steps on its one page (docs/decisions.md GV2): under More (the one More, DS4), its keyer's only: copy the order's lines
// still to come, import the supplier's sheet (with a sample sheet), cancel the delivery (last, in red). Each is its own form after the
// page's one form leaves it (data-leaves: the page asks first when something typed is not saved); none needs the script.
?>
<?php if ($keyer): ?>
<div class="doc-actions">
<details class="more"<?php if ($importErrors !== []): ?> open<?php endif; ?>>
  <summary class="btn"><?= $word('UI', 'more') ?></summary>
  <div class="more-menu">
<?php if ($canCopy): ?>
    <form method="post" action="<?= $u('/ui/receiving/' . $doc->id . '/copy') ?>" data-leaves="1">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
      <button class="btn" type="submit"><?= $word('GI', 'more_copy') ?></button>
    </form>
<?php endif; ?>
    <details class="doc-act"<?php if ($importErrors !== []): ?> open<?php endif; ?>>
      <summary class="btn"><?= $word('GI', 'more_import') ?></summary>
      <div class="doc-act-panel">
        <form class="upload record" method="post" enctype="multipart/form-data" action="<?= $u('/ui/receiving/' . $doc->id . '/import') ?>" data-leaves="1">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
          <label><?= $say('RECEIPT', 'sheet_file', $maxMb) ?> <input type="file" name="file" required></label>
          <label><?= $word('RECEIPT', 'sheet_mode') ?>
            <select name="mode">
              <option value="append"><?= $word('RECEIPT', 'sheet_append') ?></option>
              <option value="replace"><?= $word('RECEIPT', 'sheet_replace') ?></option>
            </select>
          </label>
          <p class="actions"><button class="btn" type="submit"><?= $word('RECEIPT', 'sheet_button') ?></button></p>
        </form>
        <p class="hint"><a href="/ui/receiving/template.csv"><?= $word('RECEIVING', 'sample') ?></a></p>
      </div>
    </details>
    <details class="doc-act">
      <summary class="btn risky"><?= $word('GI', 'more_cancel') ?></summary>
      <div class="doc-act-panel">
        <form class="record" method="post" action="<?= $u('/ui/receiving/' . $doc->id . '/cancel') ?>" data-leaves="1">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
          <label><?= $word('RECEIPT', 'cancel_why') ?> <input type="text" name="reason" minlength="3" maxlength="500" required></label>
          <p class="actions"><button type="submit" class="btn risky"><?= $word('RECEIPT', 'cancel_button') ?></button></p>
        </form>
      </div>
    </details>
  </div>
</details>
</div>
<?php endif; ?>
