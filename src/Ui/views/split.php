<?php
// The page's create button (the section's "New …", Ui\Sections): the main action in the accent; the section's other "New …" under More
// (docs/decisions.md DS4: the one buttons component; the split button with its accent arrow is gone). A <details>: no script needed.
// $main false (the Dashboard: its main action is the first job, DS5): the create button is plain.
?>
<?php if ($actions !== []): ?>
<a class="btn<?php if ($main ?? true): ?> primary<?php endif; ?>" href="<?= $e($actions[0]['href']) ?>"><span class="ico ico-plus" aria-hidden="true"></span><span><?= $e($actions[0]['label']) ?></span></a>
<?php if (count($actions) > 1): ?>
<details class="more">
  <summary class="btn" aria-label="<?= $word('UI', 'more_new') ?>"><?= $word('UI', 'more') ?></summary>
  <div class="more-menu">
<?php foreach (array_slice($actions, 1) as $action): ?>
    <a class="menu-item" href="<?= $e($action['href']) ?>"><?= $e($action['label']) ?></a>
<?php endforeach; ?>
  </div>
</details>
<?php endif; ?>
<?php endif; ?>
