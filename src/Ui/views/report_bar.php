<?php
// A report's bar at the head's right (the approved TP_Report of 29 Oct 2026: "Download (Excel)", a plain button; docs/decisions.md W13.2):
// the list as a spreadsheet file (the CSV, which opens in Excel). $href: the file's address.
?>
<div class="doc-actions" role="group" aria-label="<?= $word('DOC', 'actions') ?>">
  <a class="btn" href="<?= $e($href) ?>" title="<?= $word('REPORTS', 'download_title') ?>" download><?= $word('REPORTS', 'download') ?></a>
</div>
