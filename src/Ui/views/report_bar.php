<?php
// A report's actions beside its title (the approved Reports artboard of 30 Oct 2026; docs/decisions.md RD-C11, DG7): the paper-copy button
// (hidden until app.js shows it: the browser's own paper copy of the page, without the menus), then Export: with
// $xlsx, a menu of the two files (Excel, CSV) with the same rows; without it, the CSV alone as before (the audit log, the duty register).
// $href: the CSV's address; $xlsx: the Excel file's, or null.
?>
<div class="doc-actions" role="group" aria-label="<?= $word('DOC', 'actions') ?>">
  <button type="button" class="btn" data-print hidden><?= $word('ORDER', 'paper_copy') ?></button>
<?php if ($xlsx !== null): ?>
  <details class="more rp-export">
    <summary class="btn"><?= $word('RD_REPORTS', 'export') ?></summary>
    <div class="more-menu">
      <a class="menu-item" href="<?= $e($xlsx) ?>" download><?= $word('RD_REPORTS', 'export_xlsx') ?></a>
      <a class="menu-item" href="<?= $e($href) ?>" title="<?= $word('RD_REPORTS', 'export_title') ?>" download><?= $word('RD_REPORTS', 'export_csv') ?></a>
    </div>
  </details>
<?php else: ?>
  <a class="btn" href="<?= $e($href) ?>" title="<?= $word('RD_REPORTS', 'export_title') ?>" download><?= $word('RD_REPORTS', 'export') ?></a>
<?php endif; ?>
</div>
