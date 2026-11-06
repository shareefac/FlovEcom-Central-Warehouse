<?php
// A timed draft invoice's "..." in its head (docs/decisions.md KG27, KG32, KG36; pack J, PJ10): make it final now (the editor's "make final",
// the time typed left aside; it sends post_now_confirmed=1, the answer the in-page question's "Make final now" sends: a press in this menu
// is already the second), or take the time off (its own form, pi-unschedule).
?>
      <button type="submit" form="pi-form" name="<?= $e(\CW\Ui\Controller\InvoiceOnePage::CONFIRM) ?>" value="1" class="btn menu-item" data-kg-confirm-now><?= $word('DOC_GRID', 'final_now') ?></button>
      <button type="submit" form="pi-unschedule" class="btn menu-item"><?= $word('DOC_GRID', 'take_off') ?></button>
