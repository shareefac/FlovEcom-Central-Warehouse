<?php
// A row no bulk action takes (docs/decisions.md M63, U139, U144; CW\Mapping\OneAtATime): the line under its name that says what it
// is and what to do. Held back: when and by whom, and that its own page says why (the note itself is not printed on a list). A match
// of a spot check: its name and the way to it. Found by the check on arrival: that no AI has looked at it. The row has no tick box.
?>
<span class="o-sub no-bulk <?= $e(['held' => 'held', 'on_arrival' => 'arrival'][$why['code']] ?? 'spot') ?>"><?= $e($why['text']) ?><?php if ($why['link'] !== null): ?> <a href="<?= $e($why['link'][0]) ?>"><?= $e($why['link'][1]) ?></a><?php endif; ?></span>
