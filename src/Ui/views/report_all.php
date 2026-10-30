<?php
// "All reports" under every report (the approved Reports artboard of 30 Oct 2026; docs/decisions.md RD-C11): the reports CW has, in their
// groups, as this person may open them; the report shown is not a link (CV13). The head's "‹ All reports" leads here (#all-reports).
?>
<section class="card rp-all" id="all-reports" aria-labelledby="all-reports-h">
  <h2 id="all-reports-h"><?= $word('RD_REPORTS', 'all') ?></h2>
  <div class="rp-all-groups">
<?php foreach ($groups as $g): ?>
    <div class="rp-all-group">
      <p class="rp-all-label"><?= $e($g['label']) ?></p>
<?php foreach ($g['links'] as $l): ?>
<?php if ($l['current']): ?>
      <a class="rp-all-link" aria-current="page"><?= $e($l['label']) ?></a>
<?php else: ?>
      <a class="rp-all-link" href="<?= $e($l['href']) ?>"><?= $e($l['label']) ?></a>
<?php endif; ?>
<?php endforeach; ?>
    </div>
<?php endforeach; ?>
  </div>
</section>
