<?php
// A supplier's action bar (docs/decisions.md W9.3; the approved record page, as po_bar): in the page's head at its right. The next step in
// the accent: "Ask a reviewer to approve" (or "Make it usable" while the second person is off) for a draft or stopped supplier whose details
// are complete, "Start a new order" for one we order from; the rest under More: change the details, start an order, add a proof document,
// stop ordering (each a fold under the bar when it needs a reason or a file). The forms, their fields and their routes are as before.
$main = $canRequest && $missing === [] ? 'request' : ($canOrder && $s['status'] === 'active' ? 'order' : null);
$moreOpen = $errorCode !== null && in_array($errorCode, ['no_file', 'too_large', 'upload_failed', 'bad_kind'], true);
?>
<div class="doc-actions" role="group" aria-label="<?= $word('DOC', 'actions') ?>">
<?php if ($canEdit || ($canOrder && $main !== 'order') || $canDeactivate): ?>
  <details class="more"<?php if ($moreOpen): ?> open<?php endif; ?>>
    <summary class="btn"><?= $word('DOC', 'more') ?></summary>
    <div class="more-menu">
<?php if ($canEdit): ?>
      <a class="menu-item" href="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/edit') ?>"><?= $word('SUPPLIER', 'change') ?></a>
<?php endif; ?>
<?php if ($canOrder && $main !== 'order'): ?>
      <a class="menu-item" href="<?= $u('/ui/purchasing/orders/new', ['supplier' => $s['id']]) ?>"><?= $word('SUPPLIER', 'new_order') ?></a>
<?php endif; ?>
<?php if ($canEdit): ?>
      <details class="doc-act"<?php if ($moreOpen): ?> open<?php endif; ?>>
        <summary class="btn"><?= $word('SUPPLIER', 'upload') ?></summary>
        <div class="doc-act-panel">
          <form class="upload" method="post" enctype="multipart/form-data" action="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/evidence') ?>" data-leaves="1">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="version" value="<?= $e($s['version']) ?>">
            <label><?= $word('SUPPLIER', 'upload_of') ?>
              <select name="kind">
                <option value="dd"><?= $word('SUPPLIER', 'upload_dd') ?></option>
                <option value="import_route"><?= $word('SUPPLIER', 'upload_route') ?></option>
              </select>
            </label>
            <label class="doc-drop"><?= $say('SUPPLIER', 'upload_file', $maxMb) ?> <input type="file" name="file" required></label>
            <p class="actions"><button class="btn" type="submit"><?= $word('SUPPLIER', 'upload_button') ?></button></p>
          </form>
        </div>
      </details>
<?php endif; ?>
<?php if ($canDeactivate): ?>
      <details class="doc-act">
        <summary class="btn risky"><?= $word('SUPPLIER', 'stop') ?></summary>
        <div class="doc-act-panel">
          <p class="hint"><?= $word('SUPPLIER', $secondPerson ? 'stop_does' : 'stop_does_alone') ?></p>
          <form class="record" method="post" action="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/deactivate') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="version" value="<?= $e($s['version']) ?>">
            <label><?= $word('SUPPLIER', 'stop_why') ?> <input type="text" name="reason" minlength="3" maxlength="500" required></label>
            <p class="actions"><button type="submit" class="btn risky"><?= $word('SUPPLIER', 'stop_button') ?></button></p>
          </form>
        </div>
      </details>
<?php endif; ?>
    </div>
  </details>
<?php endif; ?>
<?php if ($main === 'request'): ?>
  <form class="inline" method="post" action="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/request-activation') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($s['version']) ?>">
    <button type="submit" class="btn primary"><span class="btn-title"><?php if (!$secondPerson): ?><?= $word('SUPPLIER', 'ask_alone') ?><?php elseif ($s['approved_at'] === null): ?><?= $word('SUPPLIER', 'ask') ?><?php else: ?><?= $word('SUPPLIER', 'ask_again') ?><?php endif; ?></span></button>
  </form>
<?php elseif ($main === 'order'): ?>
  <a class="btn primary" href="<?= $u('/ui/purchasing/orders/new', ['supplier' => $s['id']]) ?>"><?= $word('SUPPLIER', 'new_order') ?></a>
<?php endif; ?>
</div>
