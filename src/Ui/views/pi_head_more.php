<?php
// A timed draft invoice's "..." in its head (docs/decisions.md KG27, KG32; pack J, PJ10): make it final now (the editor's "make final", the time
// typed left aside: post_now), or
// take the time off (its own form, pi-unschedule).
?>
      <button type="submit" form="pi-form" name="action" value="post_now" class="btn menu-item"><?= $word('DOC_GRID', 'final_now') ?></button>
      <button type="submit" form="pi-unschedule" class="btn menu-item"><?= $word('DOC_GRID', 'take_off') ?></button>
