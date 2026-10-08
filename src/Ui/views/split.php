<?php if ($actions !== []): ?>
<?php if (count($actions) === 1): ?>
<a class="btn primary sm" href="<?= $e($actions[0]['href']) ?>"><span class="ico ico-plus" aria-hidden="true"></span><span><?= $e($actions[0]['label']) ?></span></a>
<?php else: ?>
<div class="split">
  <a class="btn primary sm split-main" href="<?= $e($actions[0]['href']) ?>"><span class="ico ico-plus" aria-hidden="true"></span><span><?= $e($actions[0]['label']) ?></span></a>
  <details class="split-more">
    <summary class="btn primary sm"><span class="visually-hidden"><?= $word('UI', 'more_new') ?></span></summary>
    <ul class="pop">
<?php foreach (array_slice($actions, 1) as $action): ?>
      <li><a class="menu-item" href="<?= $e($action['href']) ?>"><?= $e($action['label']) ?></a></li>
<?php endforeach; ?>
    </ul>
  </details>
</div>
<?php endif; ?>
<?php endif; ?>
