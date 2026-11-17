<?php
// Items, ONE plain list of every item (the owner, 17 Nov 2026: "items pages is to see all products in cw properly"; docs/decisions.md IL1-IL4;
// the approved redesign of 30 Oct 2026 before, RD-A3): the title, and at its right More and ONE main button "+ New item" (the page head's create
// button, its More with the list's items: list_more); one search and ONE Show choice (All items, Holding stock, Made by hand); then every item,
// most stock first, in one card with no groups and no status column, five columns: the item (its name opens it, its CW number under it, and
// "Not sold any more" when its card says so), one small column per store (Alt, VPG, VBig: an icon only, a tick in a green disc where the item
// is connected to that website, an empty grey ring where it is not; the words are the icon's label and hover title), what it holds.
?>
<?= $head(\CW\Ui\Words::INV_LIST['items'], intro: 'cards', look: $canEdit ? null : \CW\Ui\Words::whoCan('catalogue.edit'), aside: $explain('card_rules', \CW\Ui\Words::CARDS['rules_label']),
    menu: $bar) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('CARDS', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($filters['q']) ?>" maxlength="100" placeholder="<?= $word('INV_LIST', 'items_search') ?>" enterkeyhint="search"></div>
<?php foreach ($kept as $k => $v): ?>
  <input type="hidden" name="<?= $e($k) ?>" value="<?= $e($v) ?>">
<?php endforeach; ?>
  <?= $showMine($show, \CW\Ui\Words::INV_LIST['filtered']) ?>
</form>
<?php if ($rows === [] && $filters['q'] !== ''): ?>
<?= $empty(\CW\Ui\Words::say('CARDS', 'none_search', $filters['q']), \CW\Ui\Words::CARDS['none_search_text'], \CW\Ui\Html::url('/ui/items/cards', $searchClear ?? []), \CW\Ui\Words::CARDS['clear_search']) ?>
<?php elseif ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::CARDS['none'], \CW\Ui\Words::CARDS['none_text'], '/ui/items/cards', \CW\Ui\Words::CARDS['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::CARDS['none_all']) ?>
<?php else: ?>
<div class="table-wrap doc-board">
<table class="stack list board doc-list item-cards">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('INV_LIST', 'col_item') ?></th>
<?php foreach ($stores as $s): ?>
      <th scope="col" class="c-link" title="<?= $e($s['name']) ?>"><?= $e($s['label']) ?></th>
<?php endforeach; ?>
      <th scope="col" class="num"><?= $word('INV_LIST', 'col_on_hand') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u('/ui/items/' . $r['id']) ?>"><?= $e($r['name']) ?></a><span class="o-sub"><?= $e($r['code']) ?></span><?php if ($r['discontinued']): ?><span class="o-sub"><span class="tag off"><?= $word('CARDS', 'discontinued') ?></span></span><?php endif; ?></th>
<?php foreach ($r['stores'] as $i => $c): ?>
      <td class="c-link" data-label="<?= $e($stores[$i]['label']) ?>"><span class="link-ico <?= $e($c['on'] ? 'on' : 'off') ?>" role="img" aria-label="<?= $e($c['label']) ?>" title="<?= $e($c['label']) ?>"><?php if ($c['on']): ?><span class="ico ico-check" aria-hidden="true"></span><?php endif; ?></span></td>
<?php endforeach; ?>
      <td class="num" data-label="<?= $word('INV_LIST', 'col_on_hand') ?>"><?= $n($r['held']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="<?= $say('CARDS', 'page', $page, $pages) ?>">
<?php if ($page > 1): ?>
  <a href="<?= $u('/ui/items/cards', $query + ['page' => $page - 1]) ?>" rel="prev"><?= $word('CARDS', 'previous') ?></a>
<?php endif; ?>
  <span><?= $say('CARDS', 'page', $page, $pages) ?></span>
<?php if ($page < $pages): ?>
  <a href="<?= $u('/ui/items/cards', $query + ['page' => $page + 1]) ?>" rel="next"><?= $word('CARDS', 'next') ?></a>
<?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
