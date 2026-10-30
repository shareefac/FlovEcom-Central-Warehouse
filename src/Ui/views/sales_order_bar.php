<?php
// An order's bar (docs/decisions.md W10; the approved record page): in the head, at its right. The order is read only (the stores change
// it), so the bar holds what can be opened from it: the stock CW keeps for it (its reservation). Plain: a page that only looks has no main
// action.
?>
<div class="doc-actions" role="group" aria-label="<?= $word('DOC', 'actions') ?>">
  <a class="btn" href="<?= $u($kept) ?>"><?= $word('SALE_ORDER', 'kept_link') ?></a>
</div>
