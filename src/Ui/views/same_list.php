<?php
// The records a "same one" question met (CW-1150; the fix round 2 of 8dbee81, Codex C1 and the Fable re-check F-B; docs/decisions.md EX58):
// every one, each with its link and why, on the add and change forms of an expense and of a regular cost, so "it is another one" is ticked
// only after seeing all of them. $items: list of href + text; $group: the words' group (EXPENSES or REGULAR).
?>
<?php if ($items !== []): ?>
<div class="note same-list" role="status">
  <p><?= $word($group, 'same_list') ?></p>
  <ul>
<?php foreach ($items as $m): ?>
    <li><a href="<?= $u($m['href']) ?>"><?= $e($m['text']) ?></a></li>
<?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
