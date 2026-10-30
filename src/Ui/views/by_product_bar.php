<?php
// Product matching's actions beside its title (the approved ByItem artboard of 30 Oct 2026; docs/decisions.md RD-C7, RD-C8): the page's ONE main button
// (`main`: "Match all new" on the New on the stores tab, for whoever may decide; none on By item) and More: every other matching page, then
// the list's own file and how many rows a page shows (By item only). A details fold: no script needed.
$main = $main ?? null; $export = $export ?? null; $sizes = $sizes ?? [];
?>
<div class="bi-actions">
<details class="more">
  <summary class="btn"><?= $word('UI', 'more') ?></summary>
  <div class="more-menu">
<?php foreach ($pages as $p): ?>
    <a class="menu-item" href="<?= $e($p['href']) ?>"><?= $e($p['label']) ?></a>
<?php endforeach; ?>
<?php if ($export !== null): ?>
    <a class="menu-item" href="<?= $e($export) ?>" title="<?= $word('BY_PRODUCT', 'download') ?>"><?= $word('RD_MATCH', 'export') ?></a>
<?php endif; ?>
<?php foreach ($sizes as $size): ?>
<?php if ($size['current']): ?>
    <span class="menu-item" aria-current="true"><?= $say('RD_MATCH', 'rows', $size['size']) ?></span>
<?php else: ?>
    <a class="menu-item" href="<?= $e($size['href']) ?>"><?= $say('RD_MATCH', 'rows', $size['size']) ?></a>
<?php endif; ?>
<?php endforeach; ?>
  </div>
</details>
<?php if ($main !== null): ?>
<a class="btn primary" href="<?= $e($main['href']) ?>"><?= $e($main['label']) ?></a>
<?php endif; ?>
</div>
