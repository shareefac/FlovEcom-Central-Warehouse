<?php
// Product matching's three tabs (the owner asked for them; the approved ByItem artboard of 30 Oct 2026; docs/decisions.md RD-C7, RD-S1): By
// item, Simple matching (the owner's page of 25 Oct, /ui/match) and New on the stores with how many new store products wait for a match, counted
// only when that tab is open ($count null on the other two: the fix round, docs/decisions.md RD-F9). Plain links (no script); the tab shown has
// no link (CV13).
?>
<nav class="tabs bi-tabs" aria-label="<?= $word('RD_MATCH', 'tabs') ?>">
  <a class="tab"<?php if ($tab === 'items'): ?> aria-current="page"<?php else: ?> href="/ui/review/products"<?php endif; ?>><?= $word('RD_MATCH', 'tab_items') ?></a>
  <a class="tab"<?php if ($tab === 'simple'): ?> aria-current="page"<?php else: ?> href="/ui/match"<?php endif; ?>><?= $word('RD_MATCH', 'tab_simple') ?></a>
  <a class="tab"<?php if ($tab === 'new'): ?> aria-current="page"<?php else: ?> href="<?= $u('/ui/review/products', ['tab' => 'new']) ?>"<?php endif; ?>><?= $word('RD_MATCH', 'tab_new') ?><?php if ($count !== null): ?> <span class="count"><?= $n($count) ?></span><?php endif; ?></a>
</nav>
