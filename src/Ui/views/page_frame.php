<?php
// Under a page's statement (View::head): only the strip that says which jobs Admin switches off. No tab row and no segment row (the owner,
// 30 Oct 2026: "dont need tab view use sidebar and sub section"; docs/decisions.md DC28): the sidebar lists the current section's pages and
// the page's segments; no buying-flow strip (DC5). The layout draws the same for a page whose template draws no statement.
$switchedOff = $switchedOff ?? null;
?>
<?php if ($switchedOff !== null): ?>
<div class="alert needs admin-off" role="note">
  <p class="alert-title"><?= $e($switchedOff) ?></p>
  <?= $explain('admin_off', 'switched off') ?>
</div>
<?php endif; ?>
