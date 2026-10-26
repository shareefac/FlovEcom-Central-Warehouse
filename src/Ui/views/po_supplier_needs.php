<?php /* Pack PO (docs/decisions.md PO3), one line since PO on one page (PX9): a supplier not approved yet: the order is saved, not confirmed. */ ?>
<p class="note supplier-needs"><?= $e($needs['text']) ?> <a href="<?= $u($needs['link']) ?>"><?= $word('PO_ADD', 'needs_link') ?></a></p>
