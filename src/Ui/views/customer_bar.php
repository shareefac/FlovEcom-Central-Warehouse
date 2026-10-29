<?php
// A customer's actions (docs/decisions.md W8; the approved record page): in the head, at its right, for whoever keeps the customer list.
// The main one is "Change the details" (its form opens under the bar, and stays open with what was typed when it was refused); under More,
// switch the customer off (or on again). Both post where they did before.
?>
<?php if ($canManage): ?>
<div class="doc-actions" role="group" aria-label="<?= $word('DOC', 'actions') ?>">
  <details class="more">
    <summary class="btn"><?= $word('DOC', 'more') ?></summary>
    <div class="more-menu">
      <form class="inline" method="post" action="<?= $u('/ui/stock/handovers/customers/' . $c['id'] . '/active') ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="version" value="<?= $e($version) ?>">
<?php if ((int) $c['is_active'] === 1): ?>
        <input type="hidden" name="on" value="0">
        <p class="muted"><?= $word('CUSTOMER', 'switch_off_text') ?></p>
        <button type="submit" class="btn risky"><?= $word('CUSTOMER', 'switch_off') ?></button>
<?php else: ?>
        <input type="hidden" name="on" value="1">
        <button class="btn" type="submit"><?= $word('CUSTOMER', 'switch_on') ?></button>
<?php endif; ?>
      </form>
    </div>
  </details>
  <details class="doc-act"<?php if ($error !== null): ?> open<?php endif; ?>>
    <summary class="btn primary"><?= $word('CUSTOMER', 'change') ?></summary>
    <div class="doc-act-panel">
      <form class="record customer" method="post" action="<?= $u('/ui/stock/handovers/customers/' . $c['id']) ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="version" value="<?= $e($version) ?>">
        <fieldset class="doc-grid">
          <legend class="visually-hidden"><?= $word('CUSTOMER', 'change') ?></legend>
<?= $partial('handover_customer_fields', ['v' => $v, 'errors' => $errors, 'kinds' => $kinds, 'vatCodes' => $vatCodes]) ?>
        </fieldset>
        <p class="actions"><button type="submit" class="btn primary"><?= $word('CUSTOMER', 'save') ?></button></p>
      </form>
    </div>
  </details>
</div>
<?php endif; ?>
