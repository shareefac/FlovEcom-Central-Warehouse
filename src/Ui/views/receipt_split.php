<?php /* Pack PO (docs/decisions.md PO10, review finding 6): the second step after "this invoice number is on another delivery of the same order". */ ?>
<section class="card split-invoice" aria-labelledby="split-h">
  <h2 id="split-h"><?= $word('RECEIPT', 'split_title') ?></h2>
  <p><?= $say('RECEIPT', 'split_offer', (string) $step['po']) ?></p>
  <form class="quick" method="post" action="<?= $u('/ui/receiving/' . $doc->id . '/invoice') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <input type="hidden" name="invoice_number" value="<?= $e($step['number']) ?>">
    <input type="hidden" name="invoice_split" value="1">
    <button type="submit" class="btn primary"><?= $word('RECEIPT', 'split_button') ?></button>
  </form>
  <p class="hint"><?= $word('RECEIPT', 'split_hint') ?></p>
</section>
