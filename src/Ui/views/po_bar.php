<?php
// A saved purchase order's action bar (docs/decisions.md PV19, PV27, RD-B4: the approved PurchaseOrder.dc.html): in the page's head, at its
// right beside the title; More first (the other actions, po_action, then the lines' downloads and the record), then the next step in the
// accent; the paper-copy button before More (DG6). An action under More refused for its reason opens More too (PV26).
// On a draft or a new order drawn as the paper (one look, docs/decisions.md LP3; $editor: the editor form's id) the same bar for that form:
// its unseen Add first (Enter is Add, PX7), "Leave without saving", the paper copy, Keys (?), More (a draft's other steps: its PDF, Copy, Cancel
// draft), then Save (Ctrl+S, the save without leaving the page) and the main button Confirm, both sending the typed form. A new order has
// nothing saved yet: no paper copy and no More.
$editor = $editor ?? null;
?>
<div class="doc-actions<?php if ($editor !== null): ?> po-edit-bar<?php endif; ?>" role="group" aria-label="<?= $word('DOC', 'actions') ?>">
<?php if ($editor !== null): ?>
  <button type="submit" form="<?= $e($editor) ?>" name="action" value="add" class="po-default" tabindex="-1" aria-hidden="true"><?= $word('ORDER', 'add_button') ?></button>
  <a class="btn ghost" href="<?= $u('/ui/purchasing/orders') ?>" data-leave><?= $word('DOC', 'leave') ?></a>
<?php endif; ?>
<?php if ($doc !== null): ?>
<?php // The paper-copy button first, as the board draws it (docs/decisions.md DG6): hidden until app.js shows it. ?>
  <button type="button" class="btn" data-print hidden><?= $word('ORDER', 'paper_copy') ?></button>
<?php endif; ?>
<?php if ($editor !== null): ?>
  <button type="button" class="btn" data-kb-open data-kg-jsonly aria-controls="kb-panel" aria-keyshortcuts="Shift+/"><?= $word('DOC_GRID', 'keys_button') ?></button>
<?php endif; ?>
<?php if ($moreActions !== []): ?>
<?php // An action under More refused for its reason opens More too, so its open fold is seen (the Codex review of 9ce3515, L6; PV26).
$moreOpen = (in_array('amend', $moreActions, true) && $reasonError === 'amend') || (in_array('cancel', $moreActions, true) && $reasonError === 'cancel')
    || (in_array('reopen', $moreActions, true) && $errorCode === 'reopen_reason_required'); ?>
  <details class="more"<?php if ($moreOpen): ?> open<?php endif; ?>>
    <summary class="btn"><?= $word('DOC', 'more') ?></summary>
    <div class="more-menu">
<?php foreach ($moreActions as $a): ?>
<?= $partial('po_action', ['a' => $a, 'primary' => false, 'doc' => $doc, 'state' => $state, 'overLimit' => $overLimit, 'supplier' => $supplier, 'sendVia' => $sendVia,
    'sendWarnings' => $sendWarnings, 'sendCompany' => $sendCompany, 'companyNote' => $companyNote, 'formKey' => $formKey, 'cancelReasons' => $cancelReasons,
    'amendReasons' => $amendReasons, 'reasonError' => $reasonError, 'errorCode' => $errorCode, 'leaves' => $editor !== null]) ?>
<?php endforeach; ?>
<?php // The lines' downloads and the record (RD-B4: they stood under the lines; the approved PurchaseOrder.dc.html keeps them under More); on the
// paper a download, so a typed page is never left (W9.15). ?>
      <a class="menu-item" href="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines.xlsx') ?>"<?php if ($editor !== null): ?> download<?php endif; ?>><?= $word('ORDER', 'xlsx') ?></a>
      <a class="menu-item" href="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines.csv') ?>"<?php if ($editor !== null): ?> download<?php endif; ?>><?= $word('ORDER', 'csv') ?></a>
      <a class="menu-item" href="<?= $u('/ui/documents/' . $doc->id) ?>"><?= $word('ORDER', 'record') ?></a>
    </div>
  </details>
<?php endif; ?>
<?php if ($editor !== null): ?>
  <button type="submit" form="<?= $e($editor) ?>" name="action" value="save" class="btn" aria-keyshortcuts="Control+S"><?= $word('PO_ONE', 'save') ?></button>
  <button type="submit" form="<?= $e($editor) ?>" name="action" value="approve" class="btn primary"><?php if ($overLimit): ?><?= $word('PO', 'confirm_over_limit') ?><?php else: ?><?= $word('PO_ONE', 'confirm') ?><?php endif; ?></button>
<?php elseif ($mainAction === 'send' && ($sendBelow ?? false)): ?>
<?php // Confirmed, not sent (LP11): the next step's button leads to its form, a card under the head (open in the bar, it pushed the title down). ?>
  <a class="btn primary" href="#po-send"><?= $word('ORDER', 'send') ?></a>
<?php elseif ($mainAction !== null): ?>
<?= $partial('po_action', ['a' => $mainAction, 'primary' => true, 'doc' => $doc, 'state' => $state, 'overLimit' => $overLimit, 'supplier' => $supplier, 'sendVia' => $sendVia,
    'sendWarnings' => $sendWarnings, 'sendCompany' => $sendCompany, 'companyNote' => $companyNote, 'formKey' => $formKey, 'cancelReasons' => $cancelReasons,
    'amendReasons' => $amendReasons, 'reasonError' => $reasonError, 'errorCode' => $errorCode]) ?>
<?php endif; ?>
</div>
