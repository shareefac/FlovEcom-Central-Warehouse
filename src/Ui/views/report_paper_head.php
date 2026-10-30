<?php
// A report's own head, centred (the approved Reports artboard of 30 Oct 2026; docs/decisions.md RD-C11): the company's name with Central
// Warehouse, the report's name, then its line (the date, the options it was run with) and any muted line under it.
$more = $more ?? [];
?>
<div class="rp-paper-head">
  <p class="rp-co"><?= $e($company) ?></p>
  <p class="rp-name"><?= $e($name) ?></p>
  <p class="rp-line"><?= $e($line) ?></p>
<?php foreach ($more as $m): ?>
  <p class="rp-line muted"><?= $e($m) ?></p>
<?php endforeach; ?>
</div>
