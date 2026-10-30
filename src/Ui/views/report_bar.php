<?php
// A report's actions beside its title (the approved Reports artboard of 30 Oct 2026; docs/decisions.md RD-C11): Export, the report as the
// spreadsheet file CW makes today (a CSV, which opens in Excel). The artboard's other button is left out (RD-C12): it would need a script, and
// the page must work without one. $href: the file's address.
?>
<div class="doc-actions" role="group" aria-label="<?= $word('DOC', 'actions') ?>">
  <a class="btn" href="<?= $e($href) ?>" title="<?= $word('RD_REPORTS', 'export_title') ?>" download><?= $word('RD_REPORTS', 'export') ?></a>
</div>
