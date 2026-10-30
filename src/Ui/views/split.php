<?php
// The right of a list's statement (the redesign of 30 Oct 2026; docs/decisions.md RD3, RD6): the page's create button, the main action in
// the accent, then ONE More: the page's other "New …" (DS4) and the section's specialist pages ($pages: Store listings' other matching pages,
// each with its line, Words::MENU_HELP), then $menu: the page's own items (list_more.php: its filters, Clear, the download). $extra
// (['href', 'label']): a plain button before them (Stock counts' "Open sheets"). A <details>: no script needed. $main false (outside a
// section, DS5): the create button is plain.
$pages = $pages ?? [];
$menu = $menu ?? null;
$extra = $extra ?? null;
// A filter of the list's own that is on (its fold opens in the menu: `open`) is said on the closed More, with how many (the fix round,
// docs/decisions.md RD-F5): a select not on its first choice, a ticked box.
$on = 0;
if (($menu['filters']['open'] ?? false) === true) {
    foreach ($menu['filters']['selects'] as $s) {
        $on += (string) $s['value'] !== (string) array_key_first($s['options']) ? 1 : 0;
    }
    foreach ($menu['filters']['ticks'] ?? [] as $t) {
        $on += $t['checked'] ? 1 : 0;
    }
    $on = max(1, $on);
}
?>
<?php if ($extra !== null): ?>
<a class="btn" href="<?= $e($extra['href']) ?>"><?= $e($extra['label']) ?></a>
<?php endif; ?>
<?php if ($actions !== []): ?>
<a class="btn<?php if ($main ?? true): ?> primary<?php endif; ?>" href="<?= $e($actions[0]['href']) ?>"><span class="ico ico-plus" aria-hidden="true"></span><span><?= $e($actions[0]['label']) ?></span></a>
<?php endif; ?>
<?php if (count($actions) > 1 || $pages !== [] || $menu !== null): ?>
<details class="more">
  <summary class="btn" aria-label="<?php if ($pages !== [] || $menu !== null): ?><?= $word('UI', 'more_pages') ?><?php else: ?><?= $word('UI', 'more_new') ?><?php endif; ?><?php if ($on > 0): ?> (<?= $n($on) ?> <?= $word('CARDS', 'filters_on') ?>)<?php endif; ?>"><?= $word('UI', 'more') ?><?php if ($on > 0): ?> <span class="count" title="<?= $n($on) ?> <?= $word('CARDS', 'filters_on') ?>"><?= $n($on) ?><span class="visually-hidden"> <?= $word('CARDS', 'filters_on') ?></span></span><?php endif; ?></summary>
  <div class="more-menu">
<?php foreach (array_slice($actions, 1) as $action): ?>
    <a class="menu-item" href="<?= $e($action['href']) ?>"><?= $e($action['label']) ?></a>
<?php endforeach; ?>
<?php foreach ($pages as $p): ?>
    <a class="menu-item" href="<?= $u((string) $p['href'], $p['query']) ?>"><span class="menu-name"><?= $e($p['label']) ?><?php if ($p['count'] > 0): ?><span class="count" title="<?= $n($p['count']) ?> <?= $e($p['countWords']) ?>"><?= $n($p['count']) ?><span class="visually-hidden"> <?= $e($p['countWords']) ?></span></span><?php endif; ?></span><span class="menu-sub"><?= $e($p['help']) ?></span></a>
<?php endforeach; ?>
<?php if ($menu !== null): ?>
<?= $partial('list_more', $menu) ?>
<?php endif; ?>
  </div>
</details>
<?php endif; ?>
