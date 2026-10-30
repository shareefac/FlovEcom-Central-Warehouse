<?php
// A list's ONE Show choice (the approved redesign of 30 Oct 2026: "ONE search + ONE Show select"; docs/decisions.md RD-A2): a menu of
// links that looks like a select, so it works without the script (a link per choice: another page of the group or the same page with
// its filter); the choice shown is not a link. The engine is the More menu's (details.more: Esc or a click elsewhere closes it).
// TEMPORARY until the shared grouped-pages Show helper of the redesign's step 1 is on main: then the pages switch to it and this goes.
// $choices: list of ['label' => string, 'href' => string, 'current' => bool]; $label: what a screen reader hears before the choice; $other
// (optional): what it says when the page shows none of the choices (filters chosen under More), shown first and not a link.
$other = $other ?? null;
$shown = null;
foreach ($choices as $c) {
    if ($c['current']) {
        $shown = $c;
        break;
    }
}
if ($shown === null && $other !== null) {
    $shown = ['label' => $other, 'href' => '', 'current' => true];
    $choices = [$shown, ...$choices];
}
$shown ??= $choices[0];
?>
<details class="more show-pick">
  <summary class="btn show-select"><span class="visually-hidden"><?= $e($label) ?>: </span><?= $e($shown['label']) ?></summary>
  <div class="more-menu">
<?php foreach ($choices as $c): ?>
<?php if ($c['current']): ?>
    <span class="menu-item is-current" aria-current="true"><?= $e($c['label']) ?></span>
<?php else: ?>
    <a class="menu-item" href="<?= $e($c['href']) ?>"><?= $e($c['label']) ?></a>
<?php endif; ?>
<?php endforeach; ?>
  </div>
</details>
