<?php
// Product matching's two tabs (the owner asked for them; the approved ByItem artboard of 30 Oct 2026; docs/decisions.md RD-C7): By item and New
// on the stores with how many new store products wait for a match. Plain links (no script); the tab shown has no link (CV13).
?>
<nav class="tabs bi-tabs" aria-label="<?= $word('RD_MATCH', 'tabs') ?>">
  <a class="tab"<?php if ($tab === 'items'): ?> aria-current="page"<?php else: ?> href="/ui/review/products"<?php endif; ?>><?= $word('RD_MATCH', 'tab_items') ?></a>
  <a class="tab"<?php if ($tab === 'new'): ?> aria-current="page"<?php else: ?> href="<?= $u('/ui/review/products', ['tab' => 'new']) ?>"<?php endif; ?>><?= $word('RD_MATCH', 'tab_new') ?> <span class="count"><?= $n($count) ?></span></a>
</nav>
