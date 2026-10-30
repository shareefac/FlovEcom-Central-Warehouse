<?php
// Product matching's actions beside its title (the approved ByItem artboard of 30 Oct 2026; docs/decisions.md RD-C7, RD-C8): the page's ONE main button
// (`main`: "Match all new" on the New on the stores tab, for whoever may decide; none on By item) and ONE More: Store listings' other matching
// pages as the header's More lists them (the section's specialist pages, docs/decisions.md RD6, with their counts and lines), then the list's
// own file and how many rows a page shows (By item only). A details fold: no script needed.
$main = $main ?? null; $export = $export ?? null; $sizes = $sizes ?? [];
$pages = $sectionMore();
?>
<div class="bi-actions">
<details class="more">
  <summary class="btn" aria-label="<?= $word('UI', 'more_pages') ?>"><?= $word('UI', 'more') ?></summary>
  <div class="more-menu">
<?php foreach ($pages as $p): ?>
    <a class="menu-item" href="<?= $u((string) $p['href'], $p['query']) ?>"><span class="menu-name"><?= $e($p['label']) ?><?php if ($p['count'] > 0): ?><span class="count" title="<?= $n($p['count']) ?> <?= $e($p['countWords']) ?>"><?= $n($p['count']) ?><span class="visually-hidden"> <?= $e($p['countWords']) ?></span></span><?php endif; ?></span><span class="menu-sub"><?= $e($p['help']) ?></span></a>
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
