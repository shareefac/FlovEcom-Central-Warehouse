<?php
// The Show choice of a page (the redesign's lists, 30 Oct 2026; docs/decisions.md RD2): the pages of one sidebar entry (and a page's own
// filter choices) as ONE menu that looks like the artboards' select: a <details>, so it opens without a script, of plain links (GET
// navigation, no form and no new route); the current choice is not a link (CV13). View::show() draws it ($showMenu); $choices: label,
// href, query (optional), current, count and countWords (optional).
$now = null;
foreach ($choices as $c) {
    if (($c['current'] ?? false) === true) {
        $now = $c;
        break;
    }
}
?>
<details class="show-menu">
  <summary class="show-select"><span class="visually-hidden"><?= $word('UI', 'show') ?>: </span><span class="show-now"><?= $e($now['label'] ?? \CW\Ui\Words::UI['show']) ?></span></summary>
  <ul class="show-list" aria-label="<?= $word('UI', 'show') ?>">
<?php foreach ($choices as $c): ?>
<?php $count = (int) ($c['count'] ?? 0); ?>
<?php if (($c['current'] ?? false) === true): ?>
    <li><span class="show-item" aria-current="page"><span class="show-name"><?= $e($c['label']) ?></span><?php if ($count > 0): ?><span class="count" title="<?= $n($count) ?> <?= $e($c['countWords'] ?? '') ?>"><?= $n($count) ?><span class="visually-hidden"> <?= $e($c['countWords'] ?? '') ?></span></span><?php endif; ?></span></li>
<?php else: ?>
    <li><a class="show-item" href="<?= $u((string) $c['href'], $c['query'] ?? []) ?>"><span class="show-name"><?= $e($c['label']) ?></span><?php if ($count > 0): ?><span class="count" title="<?= $n($count) ?> <?= $e($c['countWords'] ?? '') ?>"><?= $n($count) ?><span class="visually-hidden"> <?= $e($c['countWords'] ?? '') ?></span></span><?php endif; ?></a></li>
<?php endif; ?>
<?php endforeach; ?>
  </ul>
</details>
