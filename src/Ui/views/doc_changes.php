<?php
// A record's history of changes (docs/decisions.md DA2): who started it and each person who changed it while it was a draft, with the time
// (DocumentsController::changes). Anyone allowed may change a draft (DA1), so the page says who did what. Nothing when CW knows no change.
?>
<?php if ($changes !== null && $changes['rows'] !== []): ?>
<section class="doc-changes" aria-labelledby="changes-h">
  <h2 id="changes-h"><?= $word('DRAFT', 'changes') ?></h2>
  <ol class="versions">
<?php foreach ($changes['rows'] as $c): ?>
    <li><?= $e($c) ?></li>
<?php endforeach; ?>
  </ol>
<?php if ($changes['earlier'] !== null): ?>
  <p class="muted"><?= $e($changes['earlier']) ?></p>
<?php endif; ?>
</section>
<?php endif; ?>
