<?php
// A saved supplier invoice's or credit note's action bar (docs/decisions.md PV19, PV27: the approved design): in the page's head, at its
// right beside the title; More first (the other actions, si_action), then the next step in the accent. An action under More refused for
// its reason opens More too (PV26).
?>
<div class="doc-actions" role="group" aria-label="<?= $word('DOC', 'actions') ?>">
<?php if ($moreActions !== []): ?>
<?php // An action under More refused for its reason opens More too, so its open fold is seen (the Codex review of 9ce3515, L6; PV26).
$moreOpen = (in_array('accept', $moreActions, true) && $typedAccept !== '') || (in_array('reverse', $moreActions, true) && $typedCancel['code'] !== ''); ?>
  <details class="doc-more"<?php if ($moreOpen): ?> open<?php endif; ?>>
    <summary class="btn secondary sm"><?= $word('DOC', 'more') ?></summary>
    <div class="doc-more-menu">
<?php foreach ($moreActions as $a): ?>
<?= $partial('si_action', ['a' => $a, 'primary' => false, 'doc' => $doc, 'base' => $base, 'rec' => $rec, 'k' => $k, 'match' => $match, 'typedAccept' => $typedAccept, 'typedCancel' => $typedCancel,
    'creditKey' => $creditKey, 'reverse' => $reverse]) ?>
<?php endforeach; ?>
    </div>
  </details>
<?php endif; ?>
<?php if ($mainAction !== null): ?>
<?= $partial('si_action', ['a' => $mainAction, 'primary' => true, 'doc' => $doc, 'base' => $base, 'rec' => $rec, 'k' => $k, 'match' => $match, 'typedAccept' => $typedAccept, 'typedCancel' => $typedCancel,
    'creditKey' => $creditKey, 'reverse' => $reverse]) ?>
<?php endif; ?>
</div>
