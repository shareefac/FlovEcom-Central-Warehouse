<?php
// A family's actions (docs/decisions.md W11.5; the approved record page, PO_View): in the head, at its right, for whoever keeps the families.
// In use, the main one is "Add items" (its form opens under the bar, open at first while the family is empty or after a refused list); under
// More, rename it and switch it off. Switched off, switching it on again is the main one and renaming is under More. Every form posts where it
// did before.
?>
<?php if ($canManage): ?>
<div class="doc-actions" role="group" aria-label="<?= $word('DOC', 'actions') ?>">
  <details class="more">
    <summary class="btn"><?= $word('DOC', 'more') ?></summary>
    <div class="more-menu">
      <details class="doc-act family-rename">
        <summary class="btn"><?= $word('FAMILY', 'rename') ?></summary>
        <div class="doc-act-panel">
          <form class="record" method="post" action="<?= $u('/ui/items/families/' . $f['id'] . '/rename') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="version" value="<?= $e($f['version']) ?>">
            <label for="fr-name"><?= $word('FAMILY', 'rename_label') ?></label>
            <input id="fr-name" type="text" name="name" maxlength="255" required value="<?= $e($typed['name']) ?>">
            <p class="actions"><button type="submit" class="btn"><?= $word('FAMILY', 'rename_button') ?></button></p>
          </form>
        </div>
      </details>
<?php if ($f['active']): ?>
      <form class="inline family-switch" method="post" action="<?= $u('/ui/items/families/' . $f['id'] . '/switch') ?>" data-leaves="1">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="version" value="<?= $e($f['version']) ?>">
        <input type="hidden" name="on" value="0">
        <p class="muted"><?= $word('FAMILY', 'switch_off_does') ?></p>
        <button type="submit" class="btn risky"><?= $word('FAMILY', 'switch_off') ?></button>
      </form>
<?php endif; ?>
    </div>
  </details>
<?php if ($f['active']): ?>
  <details class="doc-act family-add"<?php if ($f['items'] === 0 || $errorCode === 'empty_list' || $errorCode === 'too_many_rows'): ?> open<?php endif; ?>>
    <summary class="btn primary"><?= $word('FAMILY', 'bulk') ?></summary>
    <div class="doc-act-panel">
      <form class="record" method="post" action="<?= $u('/ui/items/families/' . $f['id'] . '/add') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="form_key" value="<?= $e($addKey) ?>">
        <label for="fa-list"><?= $word('FAMILY', 'bulk_label') ?> <span class="hint"><?= $word('FAMILY', 'bulk_hint') ?></span></label>
        <textarea id="fa-list" name="list" rows="6" maxlength="20000" required><?= $e($typed['list']) ?></textarea>
        <p class="actions"><button type="submit" class="btn primary"><?= $word('FAMILY', 'bulk_button') ?></button></p>
      </form>
    </div>
  </details>
<?php else: ?>
  <form class="inline family-switch" method="post" action="<?= $u('/ui/items/families/' . $f['id'] . '/switch') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($f['version']) ?>">
    <input type="hidden" name="on" value="1">
    <button type="submit" class="btn primary"><?= $word('FAMILY', 'switch_on') ?></button>
  </form>
<?php endif; ?>
</div>
<?php endif; ?>
