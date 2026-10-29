<?php
// A report's action bar (docs/decisions.md W9.10; the approved TP_Report's "Download" at the top right): its spreadsheet file, plain.
// $href: the file's address; $title: what it holds.
?>
<div class="doc-actions" role="group" aria-label="<?= $word('DOC', 'actions') ?>">
  <a class="btn ghost" href="<?= $e($href) ?>" title="<?= $e($title) ?>" download><span class="ico ico-export" aria-hidden="true"></span><span><?= $word('UI', 'export') ?></span></a>
</div>
