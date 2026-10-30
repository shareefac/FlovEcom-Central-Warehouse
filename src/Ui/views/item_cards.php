<?php
// Items, a simple list (the approved redesign of 30 Oct 2026, artboard Items; docs/decisions.md RD-A3): the title, and at its right More and
// ONE main button "+ New item" (the page head's create button, its More with the list's items: list_more); one search and ONE Show choice (All items, Holding stock, Needs a look, Made by hand); then the
// items grouped Needs a look (a block or a card still to confirm), No card yet, Ready, most stock first in each, three columns: the item (its
// name opens it, its CW number under it), its status label, what it holds. A block's rules and a warning stay a small line under the name
// (the "?" beside the heading says what they mean).
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
<?php foreach ($groups as $g): ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $e($g['name']) ?></button> <span class="grp-count"><?= $n(count($g['rows'])) ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board doc-list item-cards">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('INV_LIST', 'col_item') ?></th>
        <th scope="col" class="c-status"><?= $word('INV_LIST', 'col_status') ?></th>
        <th scope="col" class="num"><?= $word('INV_LIST', 'col_on_hand') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u('/ui/items/' . $r['id']) ?>"><?= $e($r['name']) ?></a><span class="o-sub"><?= $e($r['code']) ?></span><?php if ($r['blocked'] !== '' && $r['pillTone'] === 'blocked'): ?><span class="o-sub"><?= $e($r['blocked']) ?></span><?php endif; ?><?php if ($r['warnings'] !== '' || $r['discontinued']): ?><span class="o-sub"><?php if ($r['warnings'] !== ''): ?><span class="tag needs"><?= $say('CARDS', 'warning_line', $r['warnings']) ?></span><?php endif; ?><?php if ($r['discontinued']): ?> <span class="tag off"><?= $word('CARDS', 'discontinued') ?></span><?php endif; ?></span><?php endif; ?></th>
        <td class="c-status doc-st <?= $e($r['pillTone']) ?>"><span class="chip"><?= $e($r['pill']) ?></span></td>
        <td class="num" data-label="<?= $word('INV_LIST', 'col_on_hand') ?>"><?= $n($r['held']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
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
