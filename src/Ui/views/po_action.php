<?php
// One action of a saved purchase order (docs/decisions.md PV19, the approved design): in the document's action bar as its next step
// ($primary: the accent; a step that needs a reason or a choice opens under the bar), or as one of the others under More. $a: approve,
// withdraw, send, pdf, receive, invoice, copy, amend, close, reopen, cancel; the forms, their fields and their routes are the order page's.
?>
<?php if ($a === 'approve'): ?>
  <form class="inline" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/approve') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <button type="submit" class="btn<?php if ($primary): ?> primary<?php endif; ?>"><?php if ($overLimit): ?><?= $word('PO', 'confirm_over_limit') ?><?php else: ?><?= $word('ORDER', 'confirm_draft') ?><?php endif; ?></button>
  </form>
<?php endif; ?>
<?php if ($a === 'withdraw'): ?>
  <form class="inline" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/withdraw') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <button type="submit" class="btn<?php if ($primary): ?> primary<?php endif; ?>"><?= $word('ORDER', 'withdraw') ?></button>
  </form>
<?php endif; ?>
<?php if ($a === 'send'): ?>
  <details class="doc-act"<?php if ($primary && $state === 'approved'): ?> open<?php endif; ?>>
    <summary class="btn<?php if ($primary): ?> primary<?php endif; ?>"><?php if ($state === 'sent'): ?><?= $word('ORDER', 'send_again_open') ?><?php else: ?><?= $word('ORDER', 'send') ?><?php endif; ?></summary>
    <div class="doc-act-panel">
      <p class="hint"><?= $word('PO', 'no_email') ?></p>
      <form class="record" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/send') ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
        <label><?= $word('PO', 'send_how') ?>
          <select name="via">
<?php foreach ($sendVia as $code => $label): ?>
            <option value="<?= $e($code) ?>"><?= $e($label) ?></option>
<?php endforeach; ?>
          </select>
        </label>
        <label><?= $word('ORDER', 'send_to') ?> <input type="text" name="to" maxlength="191" value="<?= $e($supplier['email'] ?? '') ?>"></label>
<?php if ($sendWarnings !== []): ?>
        <div class="note">
          <p><strong><?= $word('ORDER', 'send_stop') ?></strong></p>
          <p><span class="warnings"><?php foreach ($sendWarnings as $w): ?><?= $e($w) ?> <?php endforeach; ?><?php if ($sendCompany): ?><a href="/ui/reference/company"><?= $word('ORDER', 'company_see') ?></a><?php endif; ?></span></p>
<?php if ($sendCompany): ?>
          <p><?= $word('ORDER', 'send_better') ?></p>
<?php endif; ?>
        </div>
        <label class="choice"><input type="checkbox" name="send_anyway" value="1" required> <?= $word('ORDER', 'send_anyway') ?></label>
<?php endif; ?>
        <p class="actions"><button type="submit" class="btn primary"><?php if ($state === 'sent'): ?><?= $word('PO', 'send_again') ?><?php else: ?><?= $word('PO', 'sent') ?><?php endif; ?></button></p>
      </form>
    </div>
  </details>
<?php endif; ?>
<?php if ($a === 'pdf'): ?>
  <a class="btn<?php if ($primary): ?> primary<?php endif; ?>" href="<?= $u('/ui/purchasing/orders/' . $doc->id . '/pdf') ?>"><?php if ($doc->status === 'posted' && $companyNote !== null): ?><?= $word('ORDER', 'pdf_hold') ?><?php elseif ($doc->status === 'posted'): ?><?= $word('ORDER', 'pdf') ?><?php else: ?><?= $word('ORDER', 'pdf_draft') ?><?php endif; ?></a>
<?php endif; ?>
<?php if ($a === 'receive'): ?>
  <a class="btn<?php if ($primary): ?> primary<?php endif; ?>" href="<?= $u('/ui/receiving/new', ['po' => $doc->id]) ?>"><?= $word('ORDER', 'receive') ?></a>
<?php endif; ?>
<?php if ($a === 'invoice'): ?>
  <a class="btn<?php if ($primary): ?> primary<?php endif; ?>" href="<?= $u('/ui/purchasing/invoices/new', ['supplier' => (int) ($supplier['id'] ?? 0), 'from' => 'po:' . $doc->id]) ?>"><?= $word('ORDER', 'record_invoice') ?></a>
<?php endif; ?>
<?php if ($a === 'copy'): ?>
  <form class="inline" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/copy') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
    <button type="submit" class="btn<?php if ($primary): ?> primary<?php endif; ?>"><?= $word('ORDER', 'copy') ?></button>
  </form>
<?php endif; ?>
<?php if ($a === 'amend'): ?>
  <details class="doc-act"<?php if ($reasonError === 'amend'): ?> open<?php endif; ?>>
    <summary class="btn"><?= $word('ORDER', 'correct') ?></summary>
    <div class="doc-act-panel">
      <p class="hint"><?= $word('ORDER', 'correct_does') ?></p>
      <form class="record" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/amend') ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
        <label><?= $word('ORDER', 'correct_why') ?>
          <select name="reason_code" required<?php if (($reasonError ?? null) === 'amend'): ?> aria-invalid="true"<?php endif; ?>>
            <option value=""><?= $word('ORDER', 'choose_reason') ?></option>
<?php foreach ($amendReasons as $r): ?>
            <option value="<?= $e($r['code']) ?>"><?= $e($r['label']) ?></option>
<?php endforeach; ?>
          </select>
        </label>
        <label><?= $word('ORDER', 'cancel_note') ?> <input type="text" name="note" maxlength="400"></label>
        <p class="actions"><button class="btn" type="submit"><?= $word('ORDER', 'correct_button') ?></button></p>
      </form>
    </div>
  </details>
<?php endif; ?>
<?php if ($a === 'close'): ?>
  <details class="doc-act">
    <summary class="btn"><?= $word('ORDER', 'close') ?></summary>
    <div class="doc-act-panel">
      <p class="hint"><?= $word('ORDER', 'close_does') ?></p>
      <form class="record" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/close') ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
        <label><?= $word('ORDER', 'close_why') ?> <input type="text" name="reason" minlength="3" maxlength="500" required></label>
        <p class="actions"><button class="btn" type="submit"><?= $word('ORDER', 'close_button') ?></button></p>
      </form>
    </div>
  </details>
<?php endif; ?>
<?php if ($a === 'reopen'): ?>
  <details class="doc-act"<?php if ($errorCode === 'reopen_reason_required'): ?> open<?php endif; ?>>
    <summary class="btn"><?= $word('ORDER', 'reopen') ?></summary>
    <div class="doc-act-panel">
      <p class="hint"><?= $word('ORDER', 'reopen_does') ?></p>
      <form class="record" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/reopen') ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
        <label><?= $word('ORDER', 'reopen_why') ?> <input type="text" name="reason" minlength="3" maxlength="500" required></label>
        <p class="actions"><button class="btn" type="submit"><?= $word('ORDER', 'reopen_button') ?></button></p>
      </form>
    </div>
  </details>
<?php endif; ?>
<?php if ($a === 'cancel'): ?>
  <details class="doc-act"<?php if ($reasonError === 'cancel'): ?> open<?php endif; ?>>
    <summary class="btn risky"><?= $word('ORDER', 'cancel') ?></summary>
    <div class="doc-act-panel">
      <p class="hint"><?php if ($doc->status === 'draft'): ?><?= $word('ORDER', 'cancel_draft_does') ?><?php elseif ($doc->status === 'awaiting_approval'): ?><?= $word('ORDER', 'cancel_request_does') ?><?php else: ?><?= $word('ORDER', 'cancel_does') ?><?php endif; ?></p>
      <form class="record" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/cancel') ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
        <label><?= $word('ORDER', 'cancel_why') ?>
          <select name="reason_code" required<?php if (($reasonError ?? null) === 'cancel'): ?> aria-invalid="true"<?php endif; ?>>
            <option value=""><?= $word('ORDER', 'choose_reason') ?></option>
<?php foreach ($cancelReasons as $r): ?>
            <option value="<?= $e($r['code']) ?>"><?= $e($r['label']) ?></option>
<?php endforeach; ?>
          </select>
        </label>
        <label><?= $word('ORDER', 'cancel_note') ?> <input type="text" name="note" maxlength="400"></label>
        <p class="actions"><button type="submit" class="btn risky"><?= $word('ORDER', 'cancel_button') ?></button></p>
      </form>
    </div>
  </details>
<?php endif; ?>
