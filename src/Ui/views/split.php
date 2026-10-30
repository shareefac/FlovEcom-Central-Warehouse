<?php
// The right of a list's statement (the redesign of 30 Oct 2026; docs/decisions.md RD3, RD6): the page's create button, the main action in
// the accent, then ONE More: the page's other "New …" (DS4) and the section's specialist pages ($pages: Store listings' other matching pages,
// each with its line, Words::MENU_HELP). A <details>: no script needed. $main false (outside a section, DS5): the create button is plain.
$pages = $pages ?? [];
?>
<?php if ($actions !== []): ?>
<a class="btn<?php if ($main ?? true): ?> primary<?php endif; ?>" href="<?= $e($actions[0]['href']) ?>"><span class="ico ico-plus" aria-hidden="true"></span><span><?= $e($actions[0]['label']) ?></span></a>
<?php endif; ?>
<?php if (count($actions) > 1 || $pages !== []): ?>
<details class="more">
  <summary class="btn" aria-label="<?php if ($pages !== []): ?><?= $word('UI', 'more_pages') ?><?php else: ?><?= $word('UI', 'more_new') ?><?php endif; ?>"><?= $word('UI', 'more') ?></summary>
  <div class="more-menu">
<?php foreach (array_slice($actions, 1) as $action): ?>
    <a class="menu-item" href="<?= $e($action['href']) ?>"><?= $e($action['label']) ?></a>
<?php endforeach; ?>
<?php foreach ($pages as $p): ?>
    <a class="menu-item" href="<?= $u((string) $p['href'], $p['query']) ?>"><span class="menu-name"><?= $e($p['label']) ?><?php if ($p['count'] > 0): ?><span class="count" title="<?= $n($p['count']) ?> <?= $e($p['countWords']) ?>"><?= $n($p['count']) ?><span class="visually-hidden"> <?= $e($p['countWords']) ?></span></span><?php endif; ?></span><span class="menu-sub"><?= $e($p['help']) ?></span></a>
<?php endforeach; ?>
  </div>
</details>
<?php endif; ?>
