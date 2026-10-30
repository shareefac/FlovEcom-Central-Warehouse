<?php
// A saved purchase order's action bar (docs/decisions.md PV19, PV27, RD-B4: the approved PurchaseOrder.dc.html): in the page's head, at its
// right beside the title; More first (the other actions, po_action, then the lines' downloads and the record), then the next step in the
// accent. An action under More refused for its reason opens More too (PV26).
?>
<div class="doc-actions" role="group" aria-label="<?= $word('DOC', 'actions') ?>">
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
    'amendReasons' => $amendReasons, 'reasonError' => $reasonError, 'errorCode' => $errorCode]) ?>
<?php endforeach; ?>
<?php // The lines' downloads and the record (RD-B4: they stood under the lines; the approved PurchaseOrder.dc.html keeps them under More). ?>
      <a class="menu-item" href="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines.xlsx') ?>"><?= $word('ORDER', 'xlsx') ?></a>
      <a class="menu-item" href="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines.csv') ?>"><?= $word('ORDER', 'csv') ?></a>
      <a class="menu-item" href="<?= $u('/ui/documents/' . $doc->id) ?>"><?= $word('ORDER', 'record') ?></a>
    </div>
  </details>
<?php endif; ?>
<?php if ($mainAction !== null): ?>
<?= $partial('po_action', ['a' => $mainAction, 'primary' => true, 'doc' => $doc, 'state' => $state, 'overLimit' => $overLimit, 'supplier' => $supplier, 'sendVia' => $sendVia,
    'sendWarnings' => $sendWarnings, 'sendCompany' => $sendCompany, 'companyNote' => $companyNote, 'formKey' => $formKey, 'cancelReasons' => $cancelReasons,
    'amendReasons' => $amendReasons, 'reasonError' => $reasonError, 'errorCode' => $errorCode]) ?>
<?php endif; ?>
</div>
