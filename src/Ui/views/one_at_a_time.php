<?php
// A row no bulk action takes (docs/decisions.md M63, U139; CW\Mapping\OneAtATime): the line under its name that says what it is and
// what to do. Held back: when and by whom, and that its own page says why (the note itself is not printed on a list). A match of a
// spot check: its name and the way to it. The row has no tick box.
?>
<span class="o-sub no-bulk <?= $e($why['code'] === 'held' ? 'held' : 'spot') ?>"><?= $e($why['text']) ?><?php if ($why['link'] !== null): ?> <a href="<?= $e($why['link'][0]) ?>"><?= $e($why['link'][1]) ?></a><?php endif; ?></span>
