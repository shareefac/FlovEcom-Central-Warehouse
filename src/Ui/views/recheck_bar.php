<?php
// To match's action bar (docs/decisions.md W9.8): "Check these again" (after a tolerance changed), plain: the board has no main action.
?>
<div class="doc-actions" role="group" aria-label="<?= $word('DOC', 'actions') ?>">
  <form class="inline" method="post" action="/ui/purchasing/invoices/to-match/recheck">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <button class="btn" type="submit" title="<?= $word('INVOICES', 'recheck_hint') ?>"><?= $word('INVOICES', 'recheck') ?></button>
  </form>
</div>
