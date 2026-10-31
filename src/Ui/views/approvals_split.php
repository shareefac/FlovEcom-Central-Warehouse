<?php
// The board's "The list is split in two" (ConfirmAll.dc.html; docs/decisions.md CA1): on Approvals with ERPNext corrections shown, at the right of
// the list until a row is opened. Ready to confirm in the green ink, Needs a look in the amber ink, then the rules of Needs a look in their order
// (Corrections::NOT_READY, the big-change limit from its setting) and how to answer one by one.
?>
<article class="card ap-split" aria-label="<?= $word('CONFIRM_ALL', 'how') ?>">
  <h2><?= $word('CONFIRM_ALL', 'split') ?></h2>
  <p><strong class="ap-split-ready"><?= $word('CONFIRM_ALL', 'ready') ?></strong>: <?= $word('CONFIRM_ALL', 'ready_is') ?></p>
  <p><strong class="ap-split-look"><?= $word('CONFIRM_ALL', 'look') ?></strong>: <?= $word('CONFIRM_ALL', 'look_is') ?></p>
  <ul class="ap-split-rules">
<?php foreach ($rules as $rule): ?>
    <li><?= $e($rule) ?></li>
<?php endforeach; ?>
  </ul>
  <p class="ap-split-note"><?= $word('CONFIRM_ALL', 'one_by_one') ?></p>
</article>
