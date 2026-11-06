<?php
// A draft order's "..." in its head (docs/decisions.md KG26): the lines file to download (the order's own route). Cancel draft stays its
// one step under the totals (PX11); the import is under the lines when the order is too long for the page (PX10).
?>
      <a class="menu-item" href="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines.xlsx') ?>" download><?= $word('ORDER', 'xlsx') ?></a>
