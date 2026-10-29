<?php
// Under a page's statement (View::head, design v5): the current section's tabs (a tab not built yet without a link; the tab of the page
// shown, `here`, without one too, so a click never reloads the page and loses what was typed: docs/decisions.md CV13), the section's
// set-up, import and specialist pages under the row's More (a <details>: no script needed; docs/decisions.md HV3), the buying flow
// on Purchasing, the current tab's segments (a second row of the same tabs: docs/decisions.md DS2), and the strip that says which jobs
// Admin switches off. The layout draws the same for a page whose template draws no statement.
$pagebar = $pagebar ?? null; $flow = $flow ?? null; $segments = $segments ?? []; $switchedOff = $switchedOff ?? null;
?>
<?php if ($pagebar !== null): ?>
<?php $more = $pagebar['more'] ?? []; ?>
<?php if ($more !== []): ?>
<div class="tabs-row">
<?php endif; ?>
<nav class="tabs" aria-label="<?= $e($pagebar['label']) ?>">
<?php foreach ($pagebar['tabs'] as $tab): ?>
<?php if ($tab['soon']): ?>
  <span class="tab soon" aria-disabled="true" title="<?= $word('UI', 'soon_title') ?>"><span><?= $e($tab['label']) ?></span><span class="count soon"><?= $word('UI', 'soon') ?></span></span>
<?php else: ?>
  <a class="tab"<?php if ($tab['current']): ?> aria-current="page"<?php endif; ?><?php if (!$tab['here']): ?> href="<?= $u($tab['href'], $tab['query']) ?>"<?php endif; ?>><span><?= $e($tab['label']) ?></span><?php if ($tab['count'] > 0): ?><span class="count" title="<?= $n($tab['count']) ?> <?= $e($tab['countWords']) ?>"><?= $n($tab['count']) ?><span class="visually-hidden"> <?= $e($tab['countWords']) ?></span></span><?php endif; ?></a>
<?php endif; ?>
<?php endforeach; ?>
</nav>
<?php if ($more !== []): ?>
<details class="more tabs-more">
  <summary class="tab-more"><span><?= $word('UI', 'more') ?></span><span class="visually-hidden"> <?= $say('UI', 'more_tabs', $pagebar['label']) ?></span><?php if ($pagebar['moreCount'] > 0): ?><span class="count"><?= $n($pagebar['moreCount']) ?><span class="visually-hidden"> <?= $word('BADGE', 'section') ?></span></span><?php endif; ?></summary>
  <div class="more-menu">
<?php foreach ($more as $tab): ?>
<?php if ($tab['soon']): ?>
    <span class="menu-item soon" aria-disabled="true" title="<?= $word('UI', 'soon_title') ?>"><span class="menu-name"><?= $e($tab['label']) ?> <span class="count soon"><?= $word('UI', 'soon') ?></span></span></span>
<?php else: ?>
    <a class="menu-item" href="<?= $u($tab['href'], $tab['query']) ?>"><span class="menu-name"><?= $e($tab['label']) ?><?php if ($tab['count'] > 0): ?> <span class="count" title="<?= $n($tab['count']) ?> <?= $e($tab['countWords']) ?>"><?= $n($tab['count']) ?><span class="visually-hidden"> <?= $e($tab['countWords']) ?></span></span><?php endif; ?></span><span class="menu-sub"><?= $word('MENU_HELP', $tab['key']) ?></span></a>
<?php endif; ?>
<?php endforeach; ?>
  </div>
</details>
</div>
<?php endif; ?>
<?php endif; ?>
<?php if ($flow !== null): ?>
<ol class="flow" aria-label="<?= $word('FLOW', 'label') ?>">
<?php foreach ($flow as $step): ?>
  <li><?php if ($step['href'] !== null): ?><a href="<?= $u($step['href'], $step['query']) ?>"<?php if ($step['current']): ?> aria-current="step"<?php endif; ?>><?php else: ?><span class="flow-off"><?php endif; ?><span class="flow-n" aria-hidden="true"><?= $n($step['n']) ?></span><span class="flow-txt"><span class="flow-t"><?= $e($step['label']) ?></span><?php if ($step['text'] !== ''): ?><span class="flow-c"><?= $e($step['text']) ?></span><?php endif; ?></span><?php if ($step['href'] !== null): ?></a><?php else: ?></span><?php endif; ?></li>
<?php endforeach; ?>
</ol>
<?php endif; ?>
<?php if ($segments !== [] && $pagebar !== null): ?>
<nav class="tabs seg" aria-label="<?= $e($pagebar['tab']) ?>">
<?php foreach ($segments as $seg): ?>
  <a class="tab"<?php if ($seg['current']): ?> aria-current="page"<?php endif; ?><?php if (!$seg['here']): ?> href="<?= $u($seg['href'], $seg['query']) ?>"<?php endif; ?>><span><?= $e($seg['label']) ?></span><?php if ($seg['count'] > 0): ?><span class="count" title="<?= $n($seg['count']) ?> <?= $e($seg['countWords']) ?>"><?= $n($seg['count']) ?><span class="visually-hidden"> <?= $e($seg['countWords']) ?></span></span><?php endif; ?></a>
<?php endforeach; ?>
</nav>
<?php endif; ?>
<?php if ($switchedOff !== null): ?>
<div class="alert needs admin-off" role="note">
  <p class="alert-title"><?= $e($switchedOff) ?></p>
  <?= $explain('admin_off', 'switched off') ?>
</div>
<?php endif; ?>
